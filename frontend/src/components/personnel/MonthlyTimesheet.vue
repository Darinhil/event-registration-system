<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import TimesheetCalendar from './timesheet/TimesheetCalendar.vue'
import TimesheetDayPanel from './timesheet/TimesheetDayPanel.vue'
import LeaveBalancePanel from './timesheet/LeaveBalancePanel.vue'
import { useAuthStore } from '../../stores/auth'
import { MONTHS, analyse, dayLabel, formatHours } from '../../utils/timesheet'
import { decideTimesheet, downloadTimesheetAttachment, getTimesheet, getTimesheetApprovals, getTimesheetForReview, processTimesheet, recallTimesheet, saveTimesheet, submitTimesheet, uploadTimesheetAttachment } from '../../services/timesheetService'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isApprover = computed(() => ['admin', 'manager'].includes(auth.user?.role)) // Timesheet::APPROVER_ROLES
const describe = (error, fallback) => (!error.response ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500 ? 'Something went wrong on our side. Please try again in a moment.'
  : [error.response.data?.errors && Object.values(error.response.data.errors).flat()[0]].find(Boolean) || error.response.data?.message || fallback)
const STATUS = {
  draft: { label: 'Draft', icon: 'pen', tone: 'muted' },
  submitted: { label: 'Submitted', icon: 'send', tone: 'info' },
  returned: { label: 'Returned for correction', icon: 'undo', tone: 'warning' },
  approved: { label: 'Approved', icon: 'check-circle', tone: 'success' },
}
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '')

/* ===================== Period ===================== */
const now = new Date()
const parsePeriod = (value) => { const match = /^(\d{4})-(\d{2})$/.exec(value || ''); return match ? { year: Number(match[1]), month: Number(match[2]) } : { year: now.getFullYear(), month: now.getMonth() + 1 } }
const period = reactive(parsePeriod(route.query.period))
const periodLabel = computed(() => `${MONTHS[period.month - 1]} ${period.year}`)
const yearOptions = computed(() => [now.getFullYear() - 1, now.getFullYear(), now.getFullYear() + 1])

/* ===================== My timesheet ===================== */
const tab = ref('mine')
const data = ref(null)
const entries = ref({})
const approverId = ref('')
const comment = ref('')
const loading = ref(true)
const loadError = ref('')
const step = ref('enter') // enter | review
const flash = ref('')
const editable = computed(() => Boolean(data.value?.editable))
const snapshot = ref('')
const currentSnapshot = () => JSON.stringify({ entries: entries.value, approver: approverId.value || null, comment: comment.value || '' })
const dirty = computed(() => editable.value && snapshot.value !== currentSnapshot())

const adopt = (payload, { keepLocal = false } = {}) => {
  data.value = payload
  if (!keepLocal) {
    entries.value = JSON.parse(JSON.stringify(payload.entries || {}))
    approverId.value = payload.approver?.id ?? ''
    comment.value = payload.comment || ''
  }
  snapshot.value = currentSnapshot()
}
const load = async () => {
  loading.value = true
  loadError.value = ''
  step.value = 'enter'
  selected.value = []
  try { adopt((await getTimesheet(period.year, period.month)).data.data) } catch (error) { loadError.value = describe(error, 'Your timesheet couldn’t be loaded.') } finally { loading.value = false }
}

const analysis = computed(() => (data.value ? analyse({ year: period.year, month: period.month, days: data.value.days, entries: entries.value, balances: data.value.balances, rules: data.value.rules }) : null))

/* ---------- Saving (manual + automatic) ---------- */
const saveState = ref('idle') // idle | saving | saved | error | blocked
const saveError = ref('')
const savedAt = ref(null)
const tick = ref(Date.now())
let ticker
const savedAgo = computed(() => {
  if (!savedAt.value) return ''
  const minutes = Math.floor((tick.value - savedAt.value) / 60000)
  return minutes < 1 ? 'just now' : minutes === 1 ? '1 min ago' : minutes < 60 ? `${minutes} min ago` : new Date(savedAt.value).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
})
const payloadOut = () => ({ entries: entries.value, approver_id: approverId.value || null, comment: comment.value || null })
const save = async () => {
  if (!editable.value) return true
  if (analysis.value?.saveBlocked) { saveState.value = 'blocked'; return false }
  const sending = currentSnapshot()
  saveState.value = 'saving'
  saveError.value = ''
  try {
    const { data: response } = await saveTimesheet(period.year, period.month, payloadOut())
    const typedMeanwhile = currentSnapshot() !== sending
    data.value = response.data
    if (!typedMeanwhile) entries.value = JSON.parse(JSON.stringify(response.data.entries || {}))
    snapshot.value = typedMeanwhile ? sending : currentSnapshot()
    savedAt.value = Date.now()
    saveState.value = 'saved'
    return true
  } catch (error) {
    saveState.value = 'error'
    saveError.value = describe(error, 'Your changes weren’t saved.')
    return false
  }
}
let autosaveTimer
watch(() => currentSnapshot(), () => {
  if (!dirty.value) return
  if (saveState.value === 'blocked' && !analysis.value?.saveBlocked) saveState.value = 'idle'
  clearTimeout(autosaveTimer)
  autosaveTimer = setTimeout(save, 2000)
})

/* ---------- Month navigation ---------- */
const goToPeriod = async (year, month) => {
  if (dirty.value && !(await save()) && !window.confirm('Your latest changes couldn’t be saved. Leave this month anyway?')) return
  clearTimeout(autosaveTimer)
  Object.assign(period, { year, month })
  router.replace({ query: { ...route.query, period: `${year}-${String(month).padStart(2, '0')}` } })
  savedAt.value = null
  saveState.value = 'idle'
  flash.value = ''
  load()
}
const shiftMonth = (delta) => { const date = new Date(period.year, period.month - 1 + delta, 1); goToPeriod(date.getFullYear(), date.getMonth() + 1) }

/* ---------- Editing days ---------- */
const openDay = ref(null)
const uploading = ref(false)
const uploadError = ref('')
const cleanEntry = (entry) => (entry.hours == null || entry.hours === '') && !entry.leave_type && !entry.note && !entry.attachment
const patchDay = (day, patch) => {
  const next = { hours: null, leave_type: null, leave_part: null, note: '', attachment: null, ...(entries.value[day] || {}), ...patch }
  if (!next.leave_type) next.leave_part = null
  const copy = { ...entries.value }
  if (cleanEntry(next)) delete copy[day]
  else copy[day] = next
  entries.value = copy
}
const setHours = (day, hours) => patchDay(day, { hours })
const workingDays = computed(() => (data.value?.days || []).filter((info) => info.working).map((info) => info.day))
const fillStandard = () => {
  const copy = { ...entries.value }
  for (const day of workingDays.value) {
    const entry = copy[day] || {}
    if (!entry.leave_type && (entry.hours == null || entry.hours === '')) copy[day] = { hours: data.value.rules.standard_hours, leave_type: null, leave_part: null, note: entry.note || '', attachment: entry.attachment || null }
  }
  entries.value = copy
  flash.value = 'Standard hours filled in for every empty working day.'
}
const uploadFor = async (day, file) => {
  uploading.value = true
  uploadError.value = ''
  try {
    const { data: response } = await uploadTimesheetAttachment(period.year, period.month, file)
    patchDay(day, { attachment: response.data })
  } catch (error) { uploadError.value = describe(error, 'The file couldn’t be uploaded.') } finally { uploading.value = false }
}
const download = async (timesheetId, day, entry) => {
  try {
    const response = await downloadTimesheetAttachment(timesheetId, day)
    const url = URL.createObjectURL(response.data)
    Object.assign(document.createElement('a'), { href: url, download: entry.attachment?.name || 'attachment' }).click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch { flash.value = 'The attachment couldn’t be downloaded.' }
}

/* ---------- Selecting several days ---------- */
const selected = ref([])
let anchor = null
const toggleSelect = (day, range) => {
  if (range && anchor) {
    const [from, to] = [Math.min(anchor, day), Math.max(anchor, day)]
    selected.value = workingDays.value.filter((item) => item >= from && item <= to)
  } else {
    selected.value = selected.value.includes(day) ? selected.value.filter((item) => item !== day) : [...selected.value, day].sort((a, b) => a - b)
    anchor = day
  }
  if (!selected.value.length) anchor = null
}
const bulkLeave = ref('annual')
const applyToSelection = (kind) => {
  const copy = { ...entries.value }
  for (const day of selected.value.filter((item) => workingDays.value.includes(item))) {
    const entry = copy[day] || {}
    copy[day] = kind === 'leave'
      ? { ...entry, hours: null, leave_type: bulkLeave.value, leave_part: 'full' }
      : kind === 'hours' ? { ...entry, hours: data.value.rules.standard_hours, leave_type: null, leave_part: null } : { hours: null, leave_type: null, leave_part: null, note: '', attachment: null }
    if (cleanEntry(copy[day])) delete copy[day]
  }
  entries.value = copy
  flash.value = `${selected.value.length} day${selected.value.length === 1 ? '' : 's'} updated.`
  selected.value = []
  anchor = null
}

/* ---------- Confirm dialogs (discard / clear all) ---------- */
const confirmDialog = ref(null)
const confirmState = reactive({ title: '', body: '', action: '', run: null })
const ask = async (title, body, action, run) => { Object.assign(confirmState, { title, body, action, run }); await nextTick(); confirmDialog.value?.showModal() }
const confirmRun = () => { confirmDialog.value?.close(); confirmState.run?.() }
const discard = () => ask('Discard your changes?', 'Everything since the last save will be lost.', 'Discard changes', () => { const saved = JSON.parse(snapshot.value); entries.value = saved.entries; approverId.value = saved.approver ?? ''; comment.value = saved.comment; clearTimeout(autosaveTimer); flash.value = 'Changes discarded.' })
const clearAll = () => ask('Clear the whole month?', 'All hours, leave, notes and attachments for this month will be removed from the draft.', 'Clear all', () => { entries.value = {}; flash.value = 'All days cleared.' })

/* ---------- Review & submit ---------- */
const certify = ref(false)
const submitting = ref(false)
const submitError = ref('')
const reviewHeading = ref(null)
const toReview = async () => {
  if (dirty.value) await save()
  certify.value = false
  submitError.value = ''
  step.value = 'review'
  await nextTick()
  reviewHeading.value?.focus()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
const jumpTo = async (day) => { step.value = 'enter'; await nextTick(); openDay.value = day }
const leaveTaken = computed(() => (data.value?.days || []).filter((info) => entries.value[info.day]?.leave_type).map((info) => ({ day: info.day, type: data.value.rules.leave_types[entries.value[info.day].leave_type], part: entries.value[info.day].leave_part })))
const canSubmit = computed(() => analysis.value && !analysis.value.errors.length && certify.value && approverId.value)
const submit = async () => {
  submitting.value = true
  submitError.value = ''
  try {
    const { data: response } = await submitTimesheet(period.year, period.month, { ...payloadOut(), certify: certify.value })
    adopt(response.data)
    step.value = 'enter'
    flash.value = `Timesheet submitted to ${response.data.approver?.name}.`
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (error) { submitError.value = describe(error, 'Your timesheet wasn’t submitted.') } finally { submitting.value = false }
}
const recalling = ref(false)
const recall = async () => {
  recalling.value = true
  try { adopt((await recallTimesheet(period.year, period.month)).data.data); flash.value = 'Timesheet recalled — it’s a draft again.' } catch (error) { flash.value = describe(error, 'The timesheet couldn’t be recalled.') } finally { recalling.value = false }
}

/* ---------- Status timeline ---------- */
const lastAction = (action) => [...(data.value?.history || [])].reverse().find((item) => item.action === action)
const timeline = (sheet) => {
  const history = sheet?.history || []
  const last = (action) => [...history].reverse().find((item) => item.action === action)
  return [
    { key: 'submitted', label: 'Submitted', event: ['submitted', 'approved', 'returned'].includes(sheet.status) || sheet.status === 'approved' ? last('submitted') : null },
    { key: 'approved', label: 'Supervisor approved', event: sheet.status === 'approved' ? last('approved') : null, waiting: sheet.status === 'submitted' ? sheet.approver?.name : null },
    { key: 'processed', label: 'HR processed', event: sheet.processed ? last('processed') : null },
  ]
}

/* ===================== Approvals (supervisors) ===================== */
const approvals = ref([])
const approvalsLoading = ref(false)
const approvalsError = ref('')
const reviewing = ref(null) // payload of the timesheet being reviewed
const reviewComments = ref({})
const decisionComment = ref('')
const decisionError = ref('')
const deciding = ref(false)
const loadApprovals = async () => {
  approvalsLoading.value = true
  approvalsError.value = ''
  try { approvals.value = (await getTimesheetApprovals()).data.data } catch (error) { approvalsError.value = describe(error, 'Approvals couldn’t be loaded.') } finally { approvalsLoading.value = false }
}
watch(tab, (value) => { flash.value = ''; reviewing.value = null; if (value === 'approvals') loadApprovals() })
const openReview = async (id) => {
  approvalsError.value = ''
  try {
    reviewing.value = (await getTimesheetForReview(id)).data.data
    reviewComments.value = { ...reviewing.value.day_comments }
    decisionComment.value = ''
    decisionError.value = ''
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (error) { approvalsError.value = describe(error, 'The timesheet couldn’t be opened.') }
}
const reviewAnalysis = computed(() => (reviewing.value ? analyse({ year: reviewing.value.year, month: reviewing.value.month, days: reviewing.value.days, entries: reviewing.value.entries, balances: reviewing.value.balances, rules: reviewing.value.rules }) : null))
const decide = async (decision) => {
  decisionError.value = ''
  if (decision === 'returned' && !decisionComment.value.trim()) { decisionError.value = 'Tell the staff member what needs correcting.'; document.getElementById('mt-decision-comment')?.focus(); return }
  deciding.value = true
  try {
    reviewing.value = (await decideTimesheet(reviewing.value.id, { decision, comment: decisionComment.value.trim() || null, day_comments: decision === 'returned' ? reviewComments.value : {} })).data.data
    flash.value = decision === 'approved' ? `${reviewing.value.employee.name}’s timesheet approved.` : `Timesheet returned to ${reviewing.value.employee.name} for correction.`
    loadApprovals()
  } catch (error) { decisionError.value = describe(error, 'Your decision wasn’t saved.') } finally { deciding.value = false }
}
const markProcessed = async () => {
  deciding.value = true
  try { reviewing.value = (await processTimesheet(reviewing.value.id)).data.data; flash.value = 'Marked as processed by HR.'; loadApprovals() } catch (error) { decisionError.value = describe(error, 'Couldn’t mark it as processed.') } finally { deciding.value = false }
}

/* ===================== Shared day panel ===================== */
const panelSource = computed(() => (tab.value === 'approvals' && reviewing.value ? { sheet: reviewing.value, entries: reviewing.value.entries, analysis: reviewAnalysis.value, mode: reviewing.value.can_decide ? 'review' : 'view' } : data.value ? { sheet: data.value, entries: entries.value, analysis: analysis.value, mode: editable.value && step.value === 'enter' ? 'edit' : 'view' } : null))
const panelInfo = computed(() => panelSource.value?.sheet.days.find((info) => info.day === openDay.value) || null)
const onDayComment = (day, text) => { const copy = { ...reviewComments.value }; if (text?.trim()) copy[day] = text.trim(); else delete copy[day]; reviewComments.value = copy }

const onBeforeUnload = (event) => { if (dirty.value) { save(); event.preventDefault(); event.returnValue = '' } }
onMounted(() => { load(); ticker = setInterval(() => { tick.value = Date.now() }, 30000); window.addEventListener('beforeunload', onBeforeUnload) })
onBeforeUnmount(() => { clearInterval(ticker); clearTimeout(autosaveTimer); if (dirty.value) save(); window.removeEventListener('beforeunload', onBeforeUnload) })
</script>

<template>
  <div class="mt">
    <!-- ===== Header bar (sticky) ===== -->
    <header class="mt-bar">
      <div class="mt-bar__title">
        <h1>Monthly Timesheet</h1>
        <span v-if="tab === 'mine' && data" class="mt-badge" :class="`is-${STATUS[data.status].tone}`"><AppIcon :name="STATUS[data.status].icon" :size="13" />{{ STATUS[data.status].label }}{{ data.processed ? ' · HR processed' : '' }}</span>
      </div>
      <div v-if="tab === 'mine'" class="mt-month" role="group" aria-label="Month">
        <button type="button" class="mt-icon-btn" aria-label="Previous month" @click="shiftMonth(-1)"><AppIcon name="chevron-left" :size="18" /></button>
        <label class="sr-only" for="mt-month-select">Month</label>
        <select id="mt-month-select" :value="period.month" @change="goToPeriod(period.year, Number($event.target.value))"><option v-for="(name, index) in MONTHS" :key="name" :value="index + 1">{{ name }}</option></select>
        <label class="sr-only" for="mt-year-select">Year</label>
        <select id="mt-year-select" :value="period.year" @change="goToPeriod(Number($event.target.value), period.month)"><option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option></select>
        <button type="button" class="mt-icon-btn" aria-label="Next month" @click="shiftMonth(1)"><AppIcon name="chevron-right" :size="18" /></button>
      </div>
      <div v-if="tab === 'mine' && data" class="mt-bar__actions">
        <span v-if="editable" class="mt-save" :class="`is-${saveState}`" aria-live="polite">
          <template v-if="saveState === 'saving'"><AppIcon name="refresh" :size="14" class="mt-spin" />Saving…</template>
          <template v-else-if="saveState === 'error'"><AppIcon name="alert-circle" :size="14" />Not saved</template>
          <template v-else-if="saveState === 'blocked'"><AppIcon name="alert-circle" :size="14" />Fix highlighted days to save</template>
          <template v-else-if="dirty">Unsaved changes</template>
          <template v-else-if="savedAt"><AppIcon name="check" :size="14" />Saved {{ savedAgo }}</template>
          <template v-else-if="data.saved_at">Saved {{ fmtDateTime(data.saved_at) }}</template>
        </span>
        <template v-if="editable && step === 'enter'">
          <button v-if="dirty" type="button" class="mt-btn mt-btn--text" @click="discard">Discard changes</button>
          <button type="button" class="mt-btn mt-btn--ghost" :disabled="!dirty || saveState === 'saving'" @click="save"><AppIcon name="save" :size="16" />Save draft</button>
          <button type="button" class="mt-btn mt-btn--primary" @click="toReview">Review &amp; submit<AppIcon name="arrow-right" :size="16" /></button>
        </template>
        <button v-else-if="data.status === 'submitted'" type="button" class="mt-btn mt-btn--ghost" :disabled="recalling" @click="recall"><AppIcon name="undo" :size="16" />{{ recalling ? 'Recalling…' : 'Recall' }}</button>
      </div>
    </header>

    <div v-if="isApprover" class="mt-tabs" role="tablist" aria-label="Timesheets">
      <button type="button" role="tab" :aria-selected="tab === 'mine'" :class="{ 'is-active': tab === 'mine' }" @click="tab = 'mine'">My timesheet</button>
      <button type="button" role="tab" :aria-selected="tab === 'approvals'" :class="{ 'is-active': tab === 'approvals' }" @click="tab = 'approvals'">Approvals<span v-if="approvals.filter((item) => item.status === 'submitted').length" class="mt-count">{{ approvals.filter((item) => item.status === 'submitted').length }}</span></button>
    </div>

    <p v-if="flash" class="mt-flash" role="status"><AppIcon name="check-circle" :size="18" /><span>{{ flash }}</span><button type="button" class="mt-icon-btn" aria-label="Dismiss" @click="flash = ''"><AppIcon name="x" :size="16" /></button></p>
    <p v-if="saveState === 'error'" class="mt-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ saveError }}</span><button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="save">Try again</button></p>

    <!-- ===================== My timesheet ===================== -->
    <template v-if="tab === 'mine'">
      <div v-if="loading" class="mt-skeleton" aria-busy="true" aria-label="Loading timesheet"><span></span><span></span><span class="is-tall"></span></div>
      <section v-else-if="loadError" class="mt-state" role="alert"><AppIcon name="alert-circle" :size="28" /><h2>Timesheet didn’t load</h2><p>{{ loadError }}</p><button type="button" class="mt-btn mt-btn--primary" @click="load"><AppIcon name="refresh" :size="16" />Try again</button></section>

      <template v-else-if="data">
        <ol class="mt-stepper" aria-label="Progress">
          <li v-for="(item, index) in [{ key: 'enter', label: 'Enter' }, { key: 'review', label: 'Review' }, { key: 'done', label: 'Submitted' }]" :key="item.key" :class="{ 'is-current': (editable ? step : 'done') === item.key, 'is-done': (!editable && item.key !== 'done') || (editable && step === 'review' && item.key === 'enter') }" :aria-current="(editable ? step : 'done') === item.key ? 'step' : undefined">
            <span class="mt-stepper__dot"><AppIcon v-if="(!editable && item.key !== 'done') || (editable && step === 'review' && item.key === 'enter')" name="check" :size="13" /><template v-else>{{ index + 1 }}</template></span>{{ item.label }}
          </li>
        </ol>

        <div v-if="data.status === 'returned'" class="mt-callout is-returned" role="alert">
          <AppIcon name="undo" :size="20" />
          <div><strong>Returned for correction by {{ lastAction('returned')?.by }}</strong><p>{{ data.return_comment }}</p><small v-if="Object.keys(data.day_comments).length">Days with comments are marked with <AppIcon name="info" :size="12" /> in the calendar.</small></div>
        </div>

        <!-- Employee summary -->
        <dl class="mt-employee">
          <div><dt>Employee ID</dt><dd>{{ data.employee.staff_id }}</dd></div>
          <div><dt>Name</dt><dd>{{ data.employee.name }}</dd></div>
          <div><dt>Position</dt><dd>{{ data.employee.position || 'Not on record' }}</dd></div>
          <div><dt>Department</dt><dd>{{ data.employee.department || 'Not on record' }}</dd></div>
          <div><dt>Approver</dt><dd>{{ data.approver?.name || (approverId && data.approvers.find((person) => person.id === Number(approverId))?.name) || 'Chosen when you submit' }}</dd></div>
          <div><dt>Period</dt><dd>01 {{ MONTHS[period.month - 1].slice(0, 3) }} {{ period.year }} – {{ data.days.length }} {{ MONTHS[period.month - 1].slice(0, 3) }} {{ period.year }}</dd></div>
        </dl>

        <!-- KPIs -->
        <section class="mt-kpis" aria-label="Month summary">
          <article><small>Expected hours</small><strong>{{ formatHours(analysis.totals.expected) }}</strong><span>{{ analysis.totals.workingDays }} working days × {{ data.rules.standard_hours }} h</span></article>
          <article><small>Hours entered</small><strong>{{ formatHours(analysis.totals.entered) }}</strong><span>+ {{ formatHours(analysis.totals.leaveDays * data.rules.standard_hours) }} covered by leave</span></article>
          <article :class="analysis.totals.difference === 0 ? 'is-good' : analysis.totals.difference < 0 ? 'is-under' : 'is-over'">
            <small>Difference</small>
            <strong><AppIcon :name="analysis.totals.difference === 0 ? 'check-circle' : 'alert-circle'" :size="18" />{{ analysis.totals.difference > 0 ? '+' : '' }}{{ formatHours(analysis.totals.difference) }}</strong>
            <span>{{ analysis.totals.difference === 0 ? 'Matches the expected hours' : analysis.totals.difference < 0 ? 'Under the expected hours' : 'Over the expected hours' }}</span>
          </article>
          <article><small>Leave this month</small><strong>{{ analysis.totals.leaveDays }} day{{ analysis.totals.leaveDays === 1 ? '' : 's' }}</strong><span>{{ leaveTaken.length ? [...new Set(leaveTaken.map((item) => item.type))].join(', ') : 'No leave marked' }}</span></article>
        </section>

        <!-- ===== Step 1: enter ===== -->
        <div v-if="step === 'enter'" class="mt-layout">
          <section class="mt-card mt-main" aria-labelledby="mt-cal-title">
            <div class="mt-main__head">
              <h2 id="mt-cal-title" class="mt-card__title">{{ periodLabel }}</h2>
              <ul class="mt-legend" aria-label="Legend">
                <li><span class="mt-tag is-weekend"><AppIcon name="circle-dot" :size="12" />Weekend</span></li>
                <li><span class="mt-tag is-holiday"><AppIcon name="calendar" :size="12" />Public holiday</span></li>
                <li><span class="mt-tag is-leave"><AppIcon name="calendar-check" :size="12" />Leave</span></li>
                <li><span class="mt-tag is-error"><AppIcon name="alert-circle" :size="12" />Needs attention</span></li>
              </ul>
            </div>
            <div v-if="editable" class="mt-tools">
              <template v-if="selected.length">
                <span class="mt-tools__count">{{ selected.length }} day{{ selected.length === 1 ? '' : 's' }} selected</span>
                <label class="sr-only" for="mt-bulk-leave">Leave type for selected days</label>
                <select id="mt-bulk-leave" v-model="bulkLeave"><option v-for="(name, key) in data.rules.leave_types" :key="key" :value="key">{{ name }}</option></select>
                <button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="applyToSelection('leave')">Apply full-day leave</button>
                <button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="applyToSelection('hours')">Set standard hours</button>
                <button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="applyToSelection('clear')">Clear days</button>
                <button type="button" class="mt-btn mt-btn--text mt-btn--sm" @click="selected = []; anchor = null">Cancel selection</button>
              </template>
              <template v-else>
                <button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="fillStandard"><AppIcon name="check" :size="15" />Fill {{ data.rules.standard_hours }} h on empty working days</button>
                <button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" :disabled="!Object.keys(entries).length" @click="clearAll"><AppIcon name="trash" :size="15" />Clear all</button>
                <span class="mt-hint">Tip: Shift + click two days to select a range (or press Space on a day).</span>
              </template>
            </div>
            <p v-else class="mt-callout"><AppIcon name="lock" :size="16" /><span>This timesheet is {{ STATUS[data.status].label.toLowerCase() }} and read-only.{{ data.status === 'submitted' ? ' Recall it to make changes.' : '' }}</span></p>
            <TimesheetCalendar :year="period.year" :month="period.month" :days="data.days" :entries="entries" :analysis="analysis" :leave-types="data.rules.leave_types" :readonly="!editable" :selected="selected" :day-comments="data.day_comments" :active-day="openDay" @open="openDay = $event" @select="toggleSelect" @hours="setHours" />
          </section>

          <div class="mt-side">
            <section v-if="!editable" class="mt-card mt-status" aria-labelledby="mt-status-title">
              <h2 id="mt-status-title" class="mt-card__title">Approval</h2>
              <dl class="mt-status__facts">
                <div><dt>Approver</dt><dd>{{ data.approver?.name || '—' }}</dd></div>
                <div><dt>Submitted</dt><dd>{{ fmtDateTime(data.submitted_at) || '—' }}</dd></div>
              </dl>
              <ol class="mt-timeline">
                <li v-for="item in timeline(data)" :key="item.key" :class="{ 'is-done': item.event, 'is-waiting': !item.event && item.waiting }">
                  <span class="mt-timeline__dot"><AppIcon :name="item.event ? 'check' : 'clock'" :size="12" /></span>
                  <div><strong>{{ item.label }}</strong><small v-if="item.event">{{ item.event.by }} · {{ fmtDateTime(item.event.at) }}</small><small v-else-if="item.waiting">Waiting for {{ item.waiting }}</small><small v-else>Not yet</small></div>
                </li>
              </ol>
              <button v-if="data.status === 'submitted'" type="button" class="mt-btn mt-btn--ghost mt-btn--full" :disabled="recalling" @click="recall"><AppIcon name="undo" :size="16" />Recall to make changes</button>
            </section>
            <LeaveBalancePanel :balances="analysis.balances" />
          </div>
        </div>

        <!-- ===== Step 2: review ===== -->
        <section v-else class="mt-card mt-review" aria-labelledby="mt-review-title">
          <h2 id="mt-review-title" ref="reviewHeading" tabindex="-1" class="mt-card__title">Review {{ periodLabel }}</h2>
          <div class="mt-review__grid">
            <div>
              <h3>Totals</h3>
              <dl class="mt-review__totals">
                <div><dt>Expected</dt><dd>{{ formatHours(analysis.totals.expected) }}</dd></div>
                <div><dt>Worked</dt><dd>{{ formatHours(analysis.totals.entered) }}</dd></div>
                <div><dt>Covered by leave</dt><dd>{{ formatHours(analysis.totals.leaveDays * data.rules.standard_hours) }}</dd></div>
                <div class="is-total" :class="analysis.totals.difference === 0 ? 'is-good' : 'is-off'"><dt>Difference</dt><dd>{{ analysis.totals.difference > 0 ? '+' : '' }}{{ formatHours(analysis.totals.difference) }}</dd></div>
              </dl>
              <h3>Leave taken</h3>
              <p v-if="!leaveTaken.length" class="mt-muted">No leave this month.</p>
              <ul v-else class="mt-review__leave"><li v-for="item in leaveTaken" :key="item.day"><span>{{ dayLabel(period.year, period.month, item.day) }}</span><span>{{ item.type }} · {{ item.part === 'full' ? 'Full day' : item.part === 'am' ? 'Morning' : 'Afternoon' }}</span></li></ul>
            </div>
            <div>
              <h3>Checks</h3>
              <p v-if="!analysis.issues.length" class="mt-callout is-good"><AppIcon name="check-circle" :size="16" /><span>Everything looks complete.</span></p>
              <ul v-else class="mt-issues">
                <li v-for="(issue, index) in analysis.issues" :key="index" :class="`is-${issue.level}`">
                  <button type="button" @click="jumpTo(issue.day)"><AppIcon name="alert-circle" :size="15" /><span><strong>{{ dayLabel(period.year, period.month, issue.day) }}</strong> — {{ issue.message }}</span><AppIcon name="chevron-right" :size="15" /></button>
                </li>
              </ul>
              <p v-if="analysis.errors.length" class="mt-hint">Fix the {{ analysis.errors.length }} error{{ analysis.errors.length === 1 ? '' : 's' }} before submitting. Warnings don’t block submission.</p>

              <div class="mt-field">
                <label for="mt-approver" class="mt-label">Approver <span class="mt-req" aria-hidden="true">*</span></label>
                <select id="mt-approver" v-model="approverId" :aria-invalid="!approverId && submitError ? 'true' : undefined">
                  <option value="" disabled>{{ data.approvers.length ? 'Choose your supervisor…' : 'No approvers available' }}</option>
                  <option v-for="person in data.approvers" :key="person.id" :value="person.id">{{ person.name }}{{ person.role === 'admin' ? ' (Master Admin)' : ' (Manager)' }}</option>
                </select>
              </div>
              <div class="mt-field">
                <label for="mt-comment" class="mt-label">Comment to your approver <span class="mt-optional">Optional</span></label>
                <textarea id="mt-comment" v-model="comment" rows="3" maxlength="2000" placeholder="Anything they should know about this month"></textarea>
              </div>
              <label class="mt-certify"><input v-model="certify" type="checkbox" /><span>I certify that these hours are accurate.</span></label>
              <p v-if="submitError" class="mt-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ submitError }}</span></p>
            </div>
          </div>
          <footer class="mt-review__foot">
            <button type="button" class="mt-btn mt-btn--ghost" @click="step = 'enter'"><AppIcon name="arrow-left" :size="16" />Back to editing</button>
            <span class="mt-hint">{{ !approverId ? 'Choose an approver.' : !certify ? 'Tick the certification to submit.' : analysis.errors.length ? 'Fix the errors to submit.' : '' }}</span>
            <button type="button" class="mt-btn mt-btn--primary mt-btn--lg" :disabled="!canSubmit || submitting" @click="submit"><AppIcon :name="submitting ? 'refresh' : 'send'" :size="17" :class="{ 'mt-spin': submitting }" />{{ submitting ? 'Submitting…' : 'Submit timesheet' }}</button>
          </footer>
        </section>
      </template>
    </template>

    <!-- ===================== Approvals ===================== -->
    <template v-else>
      <section v-if="!reviewing" class="mt-card mt-approvals" aria-labelledby="mt-approvals-title">
        <h2 id="mt-approvals-title" class="mt-card__title">Timesheets sent to you</h2>
        <div v-if="approvalsLoading" class="mt-skeleton" aria-busy="true"><span></span></div>
        <p v-else-if="approvalsError" class="mt-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ approvalsError }}</span><button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="loadApprovals">Try again</button></p>
        <p v-else-if="!approvals.length" class="mt-empty"><AppIcon name="inbox" :size="22" />No timesheets have been sent to you yet.</p>
        <ul v-else class="mt-approvals__list">
          <li v-for="item in approvals" :key="item.id">
            <div><strong>{{ item.staff }}</strong><span>{{ MONTHS[item.month - 1] }} {{ item.year }} · {{ formatHours(item.hours) }} · {{ item.leave_days }} leave day{{ item.leave_days === 1 ? '' : 's' }}</span><small>Submitted {{ fmtDateTime(item.submitted_at) }}</small></div>
            <span class="mt-badge" :class="`is-${STATUS[item.status].tone}`"><AppIcon :name="STATUS[item.status].icon" :size="13" />{{ STATUS[item.status].label }}{{ item.processed ? ' · HR processed' : '' }}</span>
            <button type="button" class="mt-btn mt-btn--sm" :class="item.status === 'submitted' ? 'mt-btn--primary' : 'mt-btn--ghost'" @click="openReview(item.id)">{{ item.status === 'submitted' ? 'Review' : 'View' }}</button>
          </li>
        </ul>
      </section>

      <template v-else>
        <button type="button" class="mt-back" @click="reviewing = null"><AppIcon name="arrow-left" :size="16" />All timesheets</button>
        <dl class="mt-employee">
          <div><dt>Employee</dt><dd>{{ reviewing.employee.name }} · {{ reviewing.employee.staff_id }}</dd></div>
          <div><dt>Position</dt><dd>{{ reviewing.employee.position || 'Not on record' }}</dd></div>
          <div><dt>Month</dt><dd>{{ MONTHS[reviewing.month - 1] }} {{ reviewing.year }}</dd></div>
          <div><dt>Status</dt><dd><span class="mt-badge" :class="`is-${STATUS[reviewing.status].tone}`">{{ STATUS[reviewing.status].label }}{{ reviewing.processed ? ' · HR processed' : '' }}</span></dd></div>
        </dl>
        <section class="mt-kpis" aria-label="Month summary">
          <article><small>Expected</small><strong>{{ formatHours(reviewAnalysis.totals.expected) }}</strong></article>
          <article><small>Worked</small><strong>{{ formatHours(reviewAnalysis.totals.entered) }}</strong></article>
          <article :class="reviewAnalysis.totals.difference === 0 ? 'is-good' : reviewAnalysis.totals.difference < 0 ? 'is-under' : 'is-over'"><small>Difference</small><strong>{{ reviewAnalysis.totals.difference > 0 ? '+' : '' }}{{ formatHours(reviewAnalysis.totals.difference) }}</strong></article>
          <article><small>Leave</small><strong>{{ reviewAnalysis.totals.leaveDays }} days</strong></article>
        </section>
        <div class="mt-layout">
          <section class="mt-card mt-main" aria-label="Timesheet">
            <p v-if="reviewing.comment" class="mt-callout"><AppIcon name="info" :size="16" /><span><strong>{{ reviewing.employee.name }} wrote:</strong> {{ reviewing.comment }}</span></p>
            <p v-if="reviewing.can_decide" class="mt-hint">Open a day to add a comment for {{ reviewing.employee.name }}. Comments are sent if you return the timesheet.</p>
            <TimesheetCalendar :year="reviewing.year" :month="reviewing.month" :days="reviewing.days" :entries="reviewing.entries" :analysis="reviewAnalysis" :leave-types="reviewing.rules.leave_types" readonly :day-comments="reviewComments" :active-day="openDay" @open="openDay = $event" />
          </section>
          <div class="mt-side">
            <section class="mt-card mt-status" aria-labelledby="mt-decision-title">
              <h2 id="mt-decision-title" class="mt-card__title">{{ reviewing.can_decide ? 'Your decision' : 'Approval' }}</h2>
              <template v-if="reviewing.can_decide">
                <div class="mt-field">
                  <label for="mt-decision-comment" class="mt-label">Comment <span class="mt-optional">Required to return</span></label>
                  <textarea id="mt-decision-comment" v-model="decisionComment" rows="3" maxlength="2000" :aria-invalid="decisionError ? 'true' : undefined" placeholder="What needs correcting?" @input="decisionError = ''"></textarea>
                </div>
                <p v-if="Object.keys(reviewComments).length" class="mt-hint">{{ Object.keys(reviewComments).length }} day comment{{ Object.keys(reviewComments).length === 1 ? '' : 's' }} will be included.</p>
                <p v-if="decisionError" class="mt-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ decisionError }}</p>
                <div class="mt-decision">
                  <button type="button" class="mt-btn mt-btn--ghost" :disabled="deciding" @click="decide('returned')"><AppIcon name="undo" :size="16" />Return for correction</button>
                  <button type="button" class="mt-btn mt-btn--primary" :disabled="deciding" @click="decide('approved')"><AppIcon name="check" :size="16" />Approve</button>
                </div>
              </template>
              <ol class="mt-timeline">
                <li v-for="item in timeline(reviewing)" :key="item.key" :class="{ 'is-done': item.event }">
                  <span class="mt-timeline__dot"><AppIcon :name="item.event ? 'check' : 'clock'" :size="12" /></span>
                  <div><strong>{{ item.label }}</strong><small>{{ item.event ? `${item.event.by} · ${fmtDateTime(item.event.at)}` : 'Not yet' }}</small></div>
                </li>
              </ol>
              <button v-if="reviewing.can_process" type="button" class="mt-btn mt-btn--primary mt-btn--full" :disabled="deciding" @click="markProcessed"><AppIcon name="check-circle" :size="16" />Mark as processed by HR</button>
            </section>
            <LeaveBalancePanel :balances="reviewAnalysis.balances" />
          </div>
        </div>
      </template>
    </template>

    <!-- Day panel -->
    <TimesheetDayPanel
      v-if="panelSource"
      :day="openDay"
      :info="panelInfo"
      :entry="openDay ? panelSource.entries[openDay] || {} : {}"
      :state="openDay ? panelSource.analysis?.stateOf[openDay] || {} : {}"
      :label="openDay ? dayLabel(panelSource.sheet.year, panelSource.sheet.month, openDay) : ''"
      :leave-types="panelSource.sheet.rules.leave_types"
      :balances="panelSource.analysis?.balances || []"
      :rules="panelSource.sheet.rules"
      :mode="panelSource.mode"
      :approver-comment="openDay ? (tab === 'approvals' ? reviewComments[openDay] : panelSource.sheet.day_comments[openDay]) || '' : ''"
      :uploading="uploading"
      :upload-error="uploadError"
      :can-prev="openDay > 1"
      :can-next="openDay < panelSource.sheet.days.length"
      @close="openDay = null; uploadError = ''"
      @prev="openDay -= 1"
      @next="openDay += 1"
      @change="patchDay"
      @upload="uploadFor"
      @download="(day) => download(panelSource.sheet.id, day, panelSource.entries[day])"
      @comment="onDayComment"
    />

    <!-- Confirmation -->
    <dialog ref="confirmDialog" class="mt-confirm" aria-labelledby="mt-confirm-title">
      <h2 id="mt-confirm-title">{{ confirmState.title }}</h2>
      <p>{{ confirmState.body }}</p>
      <div><button type="button" class="mt-btn mt-btn--ghost" autofocus @click="confirmDialog.close()">Keep editing</button><button type="button" class="mt-btn mt-btn--danger" @click="confirmRun">{{ confirmState.action }}</button></div>
    </dialog>

    <!-- Mobile: actions stay reachable at the bottom -->
    <div v-if="tab === 'mine' && editable && step === 'enter'" class="mt-mobilebar">
      <button type="button" class="mt-btn mt-btn--ghost" :disabled="!dirty" @click="save">Save draft</button>
      <button type="button" class="mt-btn mt-btn--primary" @click="toReview">Review &amp; submit</button>
    </div>
  </div>
</template>

<style>
.mt { --mt-ink: #17365f; --mt-text: #3d5470; --mt-muted: #61768f; --mt-line: #dfe7ef; --mt-soft: #f6f9fc; --mt-brand: #0b7f62; --mt-brand-dark: #086a51; --mt-brand-soft: #e8f5f0; --mt-success: #14804a; --mt-success-soft: #e7f6ee; --mt-warning: #8a5300; --mt-warning-soft: #fff4dc; --mt-info: #2b5ea8; --mt-info-soft: #e9f1fd; --mt-danger: #b42d20; --mt-danger-soft: #fdf1f0; --mt-holiday: #fff6e0; width: min(100%, 1240px); margin: 0 auto; padding: 0 0 48px; color: var(--mt-text); font-size: 14px; line-height: 1.5; }
.mt button { margin: 0; } /* older global styles give every button a top margin and large padding */
.mt h1, .mt h2, .mt h3 { color: var(--mt-ink); letter-spacing: -.01em; }
.mt-eyebrow { margin: 0 0 2px; color: var(--mt-brand); font-size: .74rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.mt-muted { margin: 0; color: var(--mt-muted); }
.mt-hint { margin: 0; color: var(--mt-muted); font-size: .84rem; }
.mt-req { color: var(--mt-danger); }
.mt-optional { margin-left: 6px; padding: 1px 8px; border-radius: 99px; background: #eef2f6; color: var(--mt-muted); font-size: .72rem; font-weight: 600; }

/* Header bar */
.mt-bar { position: sticky; top: 0; z-index: 20; display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; margin: 0 0 16px; padding: 16px 0 12px; border-bottom: 1px solid var(--mt-line); background: rgba(245, 248, 252, .96); backdrop-filter: blur(6px); }
.mt-bar__title { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-right: auto; }
.mt-bar__title h1 { margin: 0; font-size: 1.5rem; font-weight: 700; }
.mt-bar__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.mt-month { display: flex; align-items: center; gap: 4px; padding: 4px; border: 1px solid var(--mt-line); border-radius: 12px; background: #fff; }
.mt-month select { height: 34px; padding: 0 8px; border: 0; background: transparent; color: var(--mt-ink); font: inherit; font-weight: 600; cursor: pointer; }
.mt-month select:focus-visible { outline: 2px solid var(--mt-brand); border-radius: 6px; }
.mt-save { display: inline-flex; align-items: center; gap: 5px; color: var(--mt-muted); font-size: .82rem; }
.mt-save.is-error, .mt-save.is-blocked { color: var(--mt-danger); font-weight: 600; }

.mt-tabs { display: inline-flex; gap: 4px; margin-bottom: 16px; padding: 4px; border: 1px solid var(--mt-line); border-radius: 12px; background: #fff; }
.mt-tabs button { display: inline-flex; align-items: center; gap: 8px; min-height: 38px; padding: 0 16px; border: 0; border-radius: 9px; background: transparent; color: var(--mt-muted); font: inherit; font-weight: 600; cursor: pointer; }
.mt-tabs button.is-active { background: var(--mt-brand-soft); color: var(--mt-brand-dark); }
.mt-count { padding: 1px 8px; border-radius: 99px; background: var(--mt-warning-soft); color: var(--mt-warning); font-size: .75rem; font-weight: 700; }

.mt-flash, .mt-alert { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 10px 10px 10px 14px; border: 1px solid; border-radius: 10px; font-size: .9rem; font-weight: 600; }
.mt-flash { border-color: #bfe3d6; background: var(--mt-brand-soft); color: var(--mt-brand-dark); }
.mt-alert { border-color: #f2c4bf; background: var(--mt-danger-soft); color: #8f2a20; }
.mt-flash span, .mt-alert span { flex: 1; }
.mt-flash svg, .mt-alert svg { flex: none; }
.mt-callout { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 12px; padding: 10px 14px; border-radius: 10px; background: var(--mt-soft); color: var(--mt-text); font-size: .88rem; }
.mt-callout svg { flex: none; margin-top: 2px; }
.mt-callout a { color: var(--mt-brand-dark); font-weight: 600; }
.mt-callout.is-good { background: var(--mt-success-soft); color: #145c38; }
.mt-callout.is-comment { background: var(--mt-info-soft); color: #1f4a85; }
.mt-callout.is-returned { margin-bottom: 16px; padding: 14px 16px; border: 1px solid #f1d39a; background: var(--mt-warning-soft); color: #6b4300; }
.mt-callout.is-returned p { margin: 2px 0; color: #4f3300; }
.mt-callout.is-returned small { display: inline-flex; align-items: center; gap: 4px; }

/* Stepper */
.mt-stepper { display: flex; gap: 8px; margin: 0 0 16px; padding: 0; list-style: none; }
.mt-stepper li { display: flex; align-items: center; gap: 8px; color: var(--mt-muted); font-size: .88rem; font-weight: 600; }
.mt-stepper li + li::before { width: 32px; height: 1px; margin-right: 8px; background: var(--mt-line); content: ""; }
.mt-stepper__dot { display: grid; width: 24px; height: 24px; place-items: center; border: 1.5px solid #cdd9e5; border-radius: 50%; background: #fff; font-size: .78rem; }
.mt-stepper li.is-current { color: var(--mt-brand-dark); }
.mt-stepper li.is-current .mt-stepper__dot { border-color: var(--mt-brand); background: var(--mt-brand); color: #fff; }
.mt-stepper li.is-done .mt-stepper__dot { border-color: #bfe3d6; background: var(--mt-brand-soft); color: var(--mt-brand); }

/* Employee + KPIs */
.mt-employee { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px 16px; margin: 0 0 16px; padding: 14px 18px; border: 1px solid var(--mt-line); border-radius: 12px; background: #fff; }
.mt-employee dt { color: var(--mt-muted); font-size: .76rem; font-weight: 600; }
.mt-employee dd { margin: 2px 0 0; color: var(--mt-ink); font-weight: 600; overflow-wrap: anywhere; }
.mt-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.mt-kpis article { display: grid; gap: 2px; padding: 14px 16px; border: 1px solid var(--mt-line); border-radius: 12px; background: #fff; }
.mt-kpis small { color: var(--mt-muted); font-size: .8rem; font-weight: 600; }
.mt-kpis strong { display: flex; align-items: center; gap: 6px; color: var(--mt-ink); font-size: 1.4rem; line-height: 1.2; }
.mt-kpis span { color: var(--mt-muted); font-size: .8rem; }
.mt-kpis .is-good { border-color: #bfe3d6; background: #f6fbf9; }
.mt-kpis .is-good strong { color: var(--mt-success); }
.mt-kpis .is-under { border-color: #f1d39a; background: #fffbf2; }
.mt-kpis .is-under strong { color: var(--mt-warning); }
.mt-kpis .is-over { border-color: #f2c4bf; background: #fffbfa; }
.mt-kpis .is-over strong { color: var(--mt-danger); }

/* Layout */
.mt-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; align-items: start; gap: 16px; }
.mt-layout > * { min-width: 0; }
.mt-side { display: grid; gap: 16px; }
.mt-card { border: 1px solid var(--mt-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.mt-card__title { margin: 0 0 12px; font-size: 1.05rem; font-weight: 700; outline: 0; }
.mt-main { padding: 16px; }
.mt-main__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
.mt-main__head .mt-card__title { margin: 0; }
.mt-legend { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }
.mt-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; padding: 8px; border-radius: 10px; background: var(--mt-soft); }
.mt-tools__count { padding: 0 6px; color: var(--mt-ink); font-weight: 700; }
.mt-tools select { height: 36px; padding: 0 10px; border: 1px solid #cfdbe7; border-radius: 9px; background: #fff; color: var(--mt-ink); font: inherit; }

/* Tags (icon + text, never colour alone) */
.mt-tag { display: inline-flex; max-width: 100%; align-items: flex-start; gap: 3px; padding: 2px 6px; border-radius: 8px; font-size: .72rem; font-weight: 700; line-height: 1.3; }
/* Long labels (holiday names) wrap to two lines instead of being cut off. */
.mt-tag span { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.mt-tag svg { margin-top: 1px; }
.mt-legend .mt-tag, .mt-row .mt-tag { white-space: nowrap; }
.mt-tag svg { flex: none; }
.mt-tag.is-weekend { background: #eceff3; color: #4f6278; }
/* Inside a day the grey cell already marks the weekend; the word stays, the pill goes, so it fits narrow columns. */
.mt-day .mt-tag.is-weekend { padding: 0; background: none; color: #5d6f84; }
.mt-day .mt-tag.is-weekend svg { display: none; }
.mt-tag.is-holiday { background: #ffe9b8; color: #6b4300; }
.mt-tag.is-leave { background: var(--mt-info-soft); color: var(--mt-info); }
.mt-tag.is-error { background: var(--mt-danger-soft); color: var(--mt-danger); }

/* Calendar grid */
.mt-cal { display: grid; gap: 6px; }
.mt-cal__row { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)) 64px; gap: 6px; }
.mt-cal__head span { padding: 0 4px; color: var(--mt-muted); font-size: .76rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
.mt-cal__head .is-weekend { color: #8193a8; }
.mt-cal__total-head { text-align: right; }
.mt-cal__total { display: flex; align-items: center; justify-content: flex-end; padding-right: 4px; color: var(--mt-ink); font-size: .86rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.mt-day { position: relative; display: flex; min-width: 0; min-height: 92px; flex-direction: column; gap: 4px; padding: 6px 7px; border: 1px solid var(--mt-line); border-radius: 10px; background: #fff; cursor: pointer; transition: border-color .12s, box-shadow .12s; }
.mt-day:hover { border-color: #9fb3c8; }
.mt-day:focus-visible { outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .35); border-color: var(--mt-brand); }
.mt-day.is-blank { border: 0; background: transparent; cursor: default; }
.mt-day__top { display: flex; align-items: center; justify-content: space-between; }
.mt-day__date { color: var(--mt-ink); font-size: .9rem; font-weight: 700; }
.mt-day__comment { color: var(--mt-info); }
.mt-day__warn { color: var(--mt-warning); }
.mt-day__hours { box-sizing: border-box; width: 100%; height: 32px; margin-top: auto; padding: 0 6px; border: 1px solid #cfdbe7; border-radius: 8px; background: #fff; color: var(--mt-ink); font: inherit; font-size: .92rem; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
.mt-day__hours:focus { border-color: var(--mt-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.mt-day__hours[aria-invalid="true"] { border-color: var(--mt-danger); }
.mt-day__value { margin-top: auto; color: var(--mt-ink); font-weight: 600; text-align: right; }
.mt-day.is-weekend { background: #f3f5f8; }
.mt-day.is-weekend .mt-day__date { color: #6d7f94; }
.mt-day.is-holiday { border-color: #f1d39a; background: var(--mt-holiday); }
.mt-day.is-leave, .mt-day.is-half { border-color: #c7d8f3; background: #f7faff; }
.mt-day.has-error { border-color: #ee9d93; background: #fffafa; }
.mt-day.has-comment { box-shadow: inset 0 0 0 2px #8db2ea; }
.mt-day.is-selected { border-color: var(--mt-brand); background: var(--mt-brand-soft); box-shadow: inset 0 0 0 1px var(--mt-brand); }
.mt-day.is-active { border-color: var(--mt-brand); }

/* Phone list */
.mt-list { display: grid; gap: 12px; }
.mt-list__week header { display: flex; justify-content: space-between; padding: 0 4px 6px; color: var(--mt-muted); font-size: .8rem; font-weight: 700; text-transform: uppercase; }
.mt-list__week header strong { color: var(--mt-ink); }
.mt-row { display: grid; grid-template-columns: 44px minmax(0, 1fr) 84px 16px; align-items: center; gap: 10px; min-height: 52px; margin-bottom: 4px; padding: 6px 10px; border: 1px solid var(--mt-line); border-radius: 10px; background: #fff; }
.mt-row:focus-visible { outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .35); }
.mt-row__date { display: grid; text-align: center; line-height: 1.2; }
.mt-row__date strong { color: var(--mt-ink); }
.mt-row__date small { color: var(--mt-muted); font-size: .74rem; }
.mt-row__status { display: flex; min-width: 0; align-items: center; gap: 6px; }
.mt-row__chev { color: var(--mt-muted); }
.mt-row.is-weekend { background: #f3f5f8; }
.mt-row.is-holiday { border-color: #f1d39a; background: var(--mt-holiday); }
.mt-row.is-leave, .mt-row.is-half { border-color: #c7d8f3; background: #f7faff; }
.mt-row.has-error { border-color: #ee9d93; }
.mt-row.is-selected { border-color: var(--mt-brand); background: var(--mt-brand-soft); }
.mt-row .mt-day__hours { margin: 0; }

/* Side cards */
.mt-status, .mt-balance, .mt-approvals { padding: 16px; }
.mt-status__facts { display: grid; gap: 6px; margin: 0 0 12px; }
.mt-status__facts > div { display: flex; justify-content: space-between; gap: 10px; }
.mt-status__facts dt { color: var(--mt-muted); font-size: .84rem; }
.mt-status__facts dd { margin: 0; color: var(--mt-ink); font-weight: 600; text-align: right; }
.mt-timeline { display: grid; gap: 0; margin: 0 0 12px; padding: 0; list-style: none; }
.mt-timeline li { position: relative; display: flex; gap: 10px; padding-bottom: 12px; }
.mt-timeline li:not(:last-child)::after { position: absolute; top: 22px; bottom: 0; left: 10px; width: 2px; background: var(--mt-line); content: ""; }
.mt-timeline__dot { display: grid; width: 22px; height: 22px; flex: none; place-items: center; border-radius: 50%; background: #eef2f6; color: var(--mt-muted); }
.mt-timeline li.is-done .mt-timeline__dot { background: var(--mt-success-soft); color: var(--mt-success); }
.mt-timeline li.is-waiting .mt-timeline__dot { background: var(--mt-warning-soft); color: var(--mt-warning); }
.mt-timeline div { display: grid; }
.mt-timeline strong { color: var(--mt-ink); font-size: .9rem; }
.mt-timeline small { color: var(--mt-muted); font-size: .8rem; }
.mt-balance ul { display: grid; gap: 8px; margin: 0 0 10px; padding: 0; list-style: none; }
.mt-balance li { padding: 10px 12px; border: 1px solid var(--mt-line); border-radius: 10px; }
.mt-balance li.is-live { border-color: #c7d8f3; }
.mt-balance li.is-over { border-color: #ee9d93; background: #fffafa; }
.mt-balance__head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 4px; }
.mt-balance__head strong { color: var(--mt-ink); }
.mt-balance__remaining { color: var(--mt-muted); font-size: .84rem; }
.mt-balance__remaining b { color: var(--mt-ink); font-size: 1.15rem; }
.mt-balance li.is-over .mt-balance__remaining b { color: var(--mt-danger); }
.mt-balance dl { display: grid; gap: 1px; margin: 0; font-size: .8rem; }
.mt-balance dl > div { display: flex; justify-content: space-between; }
.mt-balance dt { color: var(--mt-muted); }
.mt-balance dd { margin: 0; color: var(--mt-text); font-variant-numeric: tabular-nums; }
.mt-balance .is-month dt, .mt-balance .is-month dd { color: var(--mt-info); font-weight: 700; }
.mt-balance__warn { margin: 6px 0 0; color: var(--mt-danger); font-size: .8rem; font-weight: 600; }
.mt-formula { margin-top: 10px; color: var(--mt-muted); font-size: .82rem; }
.mt-formula summary { color: var(--mt-brand-dark); font-weight: 600; cursor: pointer; }
.mt-formula p { margin: 6px 0 0; }
.mt-link { padding: 0; border: 0; background: none; color: var(--mt-brand-dark); font: inherit; font-size: .86rem; font-weight: 600; cursor: pointer; text-align: left; }
.mt-link:hover { text-decoration: underline; transform: none !important; }

/* Review */
.mt-review { padding: 20px 22px; }
.mt-review__grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 28px; }
.mt-review h3 { margin: 0 0 8px; font-size: .95rem; }
.mt-review__totals { display: grid; gap: 0; margin: 0 0 18px; padding: 4px 14px; border: 1px solid var(--mt-line); border-radius: 10px; }
.mt-review__totals > div { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--mt-line); }
.mt-review__totals > div:last-child { border-bottom: 0; }
.mt-review__totals dt { color: var(--mt-muted); }
.mt-review__totals dd { margin: 0; color: var(--mt-ink); font-weight: 600; font-variant-numeric: tabular-nums; }
.mt-review__totals .is-total dd { font-size: 1.05rem; }
.mt-review__totals .is-good dd { color: var(--mt-success); }
.mt-review__totals .is-off dd { color: var(--mt-warning); }
.mt-review__leave { display: grid; gap: 4px; margin: 0; padding: 0; list-style: none; }
.mt-review__leave li { display: flex; justify-content: space-between; gap: 10px; padding: 6px 10px; border-radius: 8px; background: var(--mt-info-soft); color: #1f4a85; font-size: .88rem; }
.mt-issues { display: grid; gap: 6px; margin: 0 0 8px; padding: 0; list-style: none; }
.mt-issues button { display: flex; width: 100%; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid; border-radius: 9px; font: inherit; font-size: .86rem; text-align: left; cursor: pointer; }
.mt-issues button span { flex: 1; }
.mt-issues .is-error button { border-color: #f2c4bf; background: var(--mt-danger-soft); color: #7c2a22; }
.mt-issues .is-warning button { border-color: #f1d39a; background: var(--mt-warning-soft); color: #6b4300; }
.mt-issues button:hover { transform: none !important; filter: brightness(.98); }
.mt-review .mt-field { margin-top: 14px; }
.mt-certify { display: flex; align-items: flex-start; gap: 10px; margin-top: 14px; padding: 12px 14px; border: 1px solid var(--mt-line); border-radius: 10px; color: var(--mt-ink); font-weight: 600; cursor: pointer; }
.mt-certify:has(input:checked) { border-color: #9fd6c3; background: #f6fbf9; }
.mt-certify input { width: 18px; height: 18px; flex: none; margin: 1px 0 0; accent-color: var(--mt-brand); }
.mt-review .mt-alert { margin-top: 12px; }
.mt-review__foot { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--mt-line); }
.mt-review__foot .mt-hint { flex: 1; text-align: right; }

/* Approvals */
.mt-approvals__list { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
.mt-approvals__list li { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--mt-line); border-radius: 10px; }
.mt-approvals__list li > div { display: grid; min-width: 0; flex: 1; }
.mt-approvals__list strong { color: var(--mt-ink); }
.mt-approvals__list span:not(.mt-badge), .mt-approvals__list small { color: var(--mt-muted); font-size: .84rem; }
.mt-decision { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 14px; }
.mt-decision .mt-btn { flex: 1; }
.mt-back { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 12px !important; padding: 6px 2px; border: 0; background: none; color: var(--mt-brand-dark); font: inherit; font-weight: 600; cursor: pointer; }

/* Fields, badges, buttons */
.mt-field { display: grid; gap: 6px; min-width: 0; margin: 0; padding: 0; border: 0; }
.mt .mt-label, .mt-panel .mt-label { display: block; color: var(--mt-ink); font-size: .9rem; font-weight: 600; } /* global styles turn labels into grids */
.mt select:not(.mt-month select), .mt textarea, .mt-panel input[type="number"] { box-sizing: border-box; width: 100%; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--mt-ink); font: inherit; font-size: .95rem; }
.mt-tools select { width: auto !important; }
.mt select:not(.mt-month select), .mt-panel input[type="number"] { height: 42px; padding: 0 12px; }
.mt textarea { padding: 10px 12px; resize: vertical; }
.mt select:focus, .mt textarea:focus, .mt-panel input:focus { border-color: var(--mt-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.mt [aria-invalid="true"] { border-color: var(--mt-danger) !important; }
.mt-error { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--mt-danger); font-size: .82rem; font-weight: 500; }
.mt-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.mt-badge.is-muted { background: #eef2f6; color: #4f6278; }
.mt-badge.is-info { background: var(--mt-info-soft); color: var(--mt-info); }
.mt-badge.is-warning { background: var(--mt-warning-soft); color: var(--mt-warning); }
.mt-badge.is-success { background: var(--mt-success-soft); color: var(--mt-success); }
.mt-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 16px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .9rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
.mt-btn svg { flex: none; }
.mt-btn--sm { min-height: 34px; padding: 0 12px; font-size: .84rem; }
.mt-btn--lg { min-height: 46px; padding: 0 22px; }
.mt-btn--full { width: 100%; }
.mt-btn--primary { background: var(--mt-brand); color: #fff; }
.mt-btn--primary:hover:not(:disabled) { background: var(--mt-brand-dark); }
.mt-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--mt-ink); }
.mt-btn--ghost:hover:not(:disabled) { border-color: #aebfd1; background: var(--mt-soft); }
.mt-btn--text { background: transparent; color: var(--mt-muted); }
.mt-btn--text:hover { color: var(--mt-ink); transform: none !important; }
.mt-btn--danger { background: var(--mt-danger); color: #fff; }
.mt-btn:disabled { opacity: .55; cursor: not-allowed; }
.mt-icon-btn { display: grid; width: 34px; height: 34px; flex: none; place-items: center; padding: 0; border: 0; border-radius: 9px; background: transparent; color: var(--mt-ink); cursor: pointer; }
.mt-icon-btn:hover:not(:disabled) { background: rgba(23, 54, 95, .07); transform: none !important; }
.mt-icon-btn:disabled { opacity: .35; cursor: default; }
.mt-spin { animation: mt-spin 1s linear infinite; }
@keyframes mt-spin { to { transform: rotate(360deg); } }

/* Day panel (side sheet) */
.mt-panel { width: min(100%, 420px); max-width: 100%; height: 100%; max-height: 100%; margin: 0 0 0 auto; padding: 0; border: 0; border-radius: 16px 0 0 16px; box-shadow: -12px 0 40px rgba(16, 36, 61, .2); color: var(--mt-text); font-size: 14px; }
.mt-panel::backdrop { background: rgba(17, 36, 59, .35); }
.mt-panel button { margin: 0; }
.mt-panel__inner { display: flex; height: 100%; flex-direction: column; }
.mt-panel__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 18px 18px 12px; border-bottom: 1px solid var(--mt-line); }
.mt-panel__head h2 { margin: 0; color: var(--mt-ink); font-size: 1.2rem; outline: 0; }
.mt-panel__nav { display: flex; gap: 2px; }
.mt-panel__body { display: grid; flex: 1; align-content: start; gap: 16px; padding: 16px 18px; overflow-y: auto; }
.mt-panel__issues { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
.mt-panel__issues li { display: flex; align-items: flex-start; gap: 6px; padding: 8px 10px; border-radius: 8px; font-size: .86rem; }
.mt-panel__issues svg { flex: none; margin-top: 2px; }
.mt-panel__issues .is-error { background: var(--mt-danger-soft); color: #7c2a22; }
.mt-panel__issues .is-warning { background: var(--mt-warning-soft); color: #6b4300; }
.mt-panel__facts { display: grid; gap: 8px; margin: 0; }
.mt-panel__facts > div { display: flex; justify-content: space-between; gap: 10px; }
.mt-panel__facts dt { color: var(--mt-muted); }
.mt-panel__facts dd { margin: 0; color: var(--mt-ink); font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.mt-parts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.mt-parts legend { margin-bottom: 6px; padding: 0; }
.mt-parts label { display: flex; align-items: center; justify-content: center; gap: 6px; min-height: 40px; border: 1px solid #cfdbe7; border-radius: 9px; color: var(--mt-ink); font-weight: 600; font-size: .86rem; cursor: pointer; }
.mt-parts label.is-checked { border-color: var(--mt-brand); background: var(--mt-brand-soft); color: var(--mt-brand-dark); }
.mt-parts input { position: absolute; opacity: 0; pointer-events: none; }
.mt-parts label:has(input:focus-visible) { box-shadow: 0 0 0 3px rgba(11, 127, 98, .25); }
.mt-panel input:disabled { background: var(--mt-soft); color: var(--mt-muted); }
.mt-file { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 12px; border: 1px dashed #b9c9d9; border-radius: 10px; background: var(--mt-soft); color: var(--mt-muted); }
.mt-file__name { min-width: 0; flex: 1; overflow: hidden; color: var(--mt-ink); font-size: .86rem; text-overflow: ellipsis; white-space: nowrap; }
.mt-panel__foot { display: flex; justify-content: flex-end; padding: 12px 18px; border-top: 1px solid var(--mt-line); background: var(--mt-soft); }
.mt-panel__foot .mt-btn { min-width: 120px; }

.mt-confirm { width: min(100% - 32px, 420px); margin: auto; padding: 22px; border: 0; border-radius: 14px; box-shadow: 0 24px 70px rgba(16, 36, 61, .3); color: var(--mt-text); }
.mt-confirm::backdrop { background: rgba(17, 36, 59, .45); }
.mt-confirm h2 { margin: 0 0 8px; color: var(--mt-ink); font-size: 1.15rem; }
.mt-confirm p { margin: 0 0 18px; }
.mt-confirm div { display: flex; justify-content: flex-end; gap: 8px; }
.mt-confirm button { margin: 0; }

.mt-state { display: grid; justify-items: center; gap: 6px; padding: 44px 24px; border: 1px solid #f2c4bf; border-radius: 14px; background: #fff; color: var(--mt-danger); text-align: center; }
.mt-state h2 { margin: 6px 0 0; font-size: 1.1rem; }
.mt-state p { margin: 0 0 8px; }
.mt-empty { display: flex; align-items: center; gap: 12px; margin: 0; padding: 18px; border: 1px dashed #d6e0ea; border-radius: 12px; color: var(--mt-muted); }
.mt-skeleton { display: grid; gap: 12px; }
.mt-skeleton span { display: block; height: 72px; border-radius: 12px; background: linear-gradient(90deg, #eef2f6 25%, #f6f9fc 50%, #eef2f6 75%); background-size: 200% 100%; animation: mt-shimmer 1.4s ease infinite; }
.mt-skeleton .is-tall { height: 420px; }
@keyframes mt-shimmer { to { background-position: -200% 0; } }
.mt-mobilebar { display: none; }

@media (max-width: 1180px) {
  .mt-layout { grid-template-columns: 1fr; }
  .mt-side { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .mt-employee { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
  .mt { padding-bottom: 88px; }
  .mt-bar { position: static; }
  .mt-bar__title h1 { font-size: 1.3rem; }
  .mt-bar__actions .mt-btn { display: none; } /* the bottom bar carries the actions on phones */
  .mt-month { width: 100%; justify-content: space-between; }
  .mt-employee { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 12px 14px; }
  .mt-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .mt-kpis strong { font-size: 1.15rem; }
  .mt-side { grid-template-columns: 1fr; }
  .mt-main { padding: 12px; }
  .mt-tools .mt-hint { display: none; }
  .mt-review { padding: 16px; }
  .mt-review__grid { grid-template-columns: 1fr; gap: 18px; }
  .mt-review__foot .mt-hint { flex-basis: 100%; order: -1; text-align: left; }
  .mt-review__foot .mt-btn--lg { flex: 1; }
  .mt-stepper li + li::before { width: 12px; }
  .mt-panel { width: 100%; height: auto; max-height: 90%; margin: auto 0 0; border-radius: 16px 16px 0 0; }
  .mt-mobilebar { position: fixed; right: 0; bottom: 0; left: 0; z-index: 30; display: flex; gap: 8px; padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); border-top: 1px solid var(--mt-line); background: #fff; box-shadow: 0 -6px 20px rgba(16, 36, 61, .08); }
  .mt-mobilebar .mt-btn { flex: 1; margin: 0; }
}
@media (prefers-reduced-motion: reduce) { .mt-skeleton span, .mt-spin { animation: none; } }
</style>
