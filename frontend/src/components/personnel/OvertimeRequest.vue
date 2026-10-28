<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import { cancelOvertime, decideOvertime, getMyOvertime, getOvertimeApprovals, getOvertimeSupervisors, submitOvertime } from '../../services/overtimeService'

// Same limits the server enforces (OvertimeController); checked here first for instant feedback.
const MIN_MINUTES = 15
const MAX_MINUTES = 16 * 60
const DAYS_BACK = 60
const DAYS_AHEAD = 60
const REASON_MIN = 10
const REASON_MAX = 1000

const describe = (error, fallback) => (!error.response ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500 ? 'Something went wrong on our side. Please try again in a moment.'
  : error.response.data?.message || fallback)
const STATUS = {
  pending: { label: 'Pending', icon: 'clock', tone: 'warning' },
  approved: { label: 'Approved', icon: 'check-circle', tone: 'success' },
  rejected: { label: 'Rejected', icon: 'x', tone: 'danger' },
  cancelled: { label: 'Cancelled', icon: 'circle-dot', tone: 'muted' },
}
const hours = (minutes) => { const h = Math.floor(minutes / 60); const m = minutes % 60; return [h && `${h} h`, m && `${m} min`].filter(Boolean).join(' ') || '0 min' }
const fmtDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).replace(',', '')
const isoDay = (offset) => { const d = new Date(); d.setDate(d.getDate() + offset); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

/* ---------- Data ---------- */
const tab = ref('mine')
const mine = ref([])
const summary = ref(null)
const canApprove = ref(false)
const supervisors = ref([])
const loading = ref(true)
const loadError = ref('')
const toast = ref('')
const load = async ({ quiet = false } = {}) => {
  if (!quiet) loading.value = true
  loadError.value = ''
  try {
    const [{ data }, people] = await Promise.all([getMyOvertime(), quiet ? Promise.resolve(null) : getOvertimeSupervisors()])
    mine.value = data.data
    summary.value = data.summary
    canApprove.value = data.can_approve
    if (people) supervisors.value = people.data.data
  } catch (error) {
    loadError.value = describe(error, 'Your overtime couldn’t be loaded.')
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---------- New request ---------- */
const form = reactive({ work_date: '', start_time: '', end_time: '', overnight: false, supervisor_id: '', reason: '' })
const errors = ref({})
const tried = ref(false)
const saving = ref(false)
const formError = ref('')
const formEl = ref(null)
const toMinutes = (time) => { const [h, m] = time.split(':').map(Number); return h * 60 + m }
const duration = computed(() => {
  if (!form.start_time || !form.end_time) return null
  const raw = toMinutes(form.end_time) - toMinutes(form.start_time)
  return form.overnight ? raw + 24 * 60 : raw
})
// An end time earlier than the start almost always means the work ran past midnight.
const looksOvernight = computed(() => !form.overnight && form.start_time && form.end_time && form.end_time <= form.start_time)
const validate = () => {
  const e = {}
  if (!form.work_date) e.work_date = 'Choose the day you worked (or will work) the overtime.'
  else if (form.work_date < isoDay(-DAYS_BACK)) e.work_date = `Overtime older than ${DAYS_BACK} days must be claimed through HR.`
  else if (form.work_date > isoDay(DAYS_AHEAD)) e.work_date = `You can request overtime up to ${DAYS_AHEAD} days ahead.`
  if (!form.start_time) e.start_time = 'Enter the start time.'
  if (!form.end_time) e.end_time = 'Enter the end time.'
  else if (duration.value !== null && duration.value <= 0) e.end_time = 'The end time must be after the start time. Tick “Ends the next day” for overnight work.'
  else if (duration.value !== null && duration.value < MIN_MINUTES) e.end_time = `Overtime must be at least ${MIN_MINUTES} minutes.`
  else if (duration.value !== null && duration.value > MAX_MINUTES) e.end_time = `A single request can’t be longer than ${MAX_MINUTES / 60} hours. Split longer work into separate days.`
  if (!form.supervisor_id) e.supervisor_id = 'Choose who should approve this request.'
  if (form.reason.trim().length < REASON_MIN) e.reason = `Describe the work in at least ${REASON_MIN} characters.`
  else if (form.reason.length > REASON_MAX) e.reason = `Keep the reason under ${REASON_MAX} characters.`
  errors.value = e
  return !Object.keys(e).length
}
watch(form, () => { formError.value = ''; if (tried.value) validate() }, { deep: true })
const err = (key) => errors.value[key] || ''
const bind = (key) => ({ id: `ot-${key}`, 'aria-invalid': err(key) ? 'true' : undefined, 'aria-describedby': err(key) ? `ot-${key}-error` : undefined })
const focusFirstError = async () => { await nextTick(); formEl.value?.querySelector('[aria-invalid="true"]')?.focus() }

const submit = async () => {
  tried.value = true
  formError.value = ''
  if (!validate()) return focusFirstError()
  saving.value = true
  try {
    const { data } = await submitOvertime({ ...form, supervisor_id: Number(form.supervisor_id), reason: form.reason.trim() })
    const saved = data.data
    Object.assign(form, { work_date: '', start_time: '', end_time: '', overnight: false, reason: '' }) // keep the supervisor for the next request
    tried.value = false
    errors.value = {}
    await load({ quiet: true })
    toast.value = `Overtime for ${fmtDate(saved.work_date)} (${hours(saved.minutes)}) sent to ${saved.supervisor} for approval.`
  } catch (error) {
    const fieldErrors = error.response?.data?.errors || {}
    errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([key, value]) => [key, [value].flat()[0]]))
    formError.value = Object.keys(errors.value).length ? '' : describe(error, 'Your request wasn’t sent. Please try again.')
    focusFirstError()
  } finally {
    saving.value = false
  }
}

/* ---------- Cancel own pending request ---------- */
const confirmingCancel = ref(null)
const busyId = ref(null)
const rowError = reactive({})
const cancel = async (item) => {
  busyId.value = item.id
  delete rowError[item.id]
  try {
    await cancelOvertime(item.id)
    confirmingCancel.value = null
    await load({ quiet: true })
    toast.value = `Request for ${fmtDate(item.work_date)} cancelled.`
  } catch (error) {
    rowError[item.id] = describe(error, 'The request couldn’t be cancelled.')
  } finally {
    busyId.value = null
  }
}

/* ---------- Approvals (supervisors) ---------- */
const approvals = ref([])
const approvalsLoading = ref(false)
const approvalsError = ref('')
const rejecting = ref(null)
const rejectNote = ref('')
const rejectError = ref('')
const pendingApprovals = computed(() => approvals.value.filter((item) => item.status === 'pending'))
const loadApprovals = async ({ quiet = false } = {}) => {
  if (!quiet) approvalsLoading.value = true
  approvalsError.value = ''
  try { approvals.value = (await getOvertimeApprovals()).data.data } catch (error) { approvalsError.value = describe(error, 'Approvals couldn’t be loaded.') } finally { approvalsLoading.value = false }
}
watch(tab, (value) => { toast.value = ''; if (value === 'approvals') loadApprovals() })
const startReject = async (item) => { rejecting.value = item.id; rejectNote.value = ''; rejectError.value = ''; await nextTick(); document.getElementById(`ot-note-${item.id}`)?.focus() }
const decide = async (item, decision) => {
  if (decision === 'rejected' && !rejectNote.value.trim()) { rejectError.value = 'Give a reason so the staff member knows why it was rejected.'; document.getElementById(`ot-note-${item.id}`)?.focus(); return }
  busyId.value = item.id
  delete rowError[item.id]
  try {
    await decideOvertime(item.id, decision, decision === 'rejected' ? rejectNote.value.trim() : null)
    rejecting.value = null
    await Promise.all([loadApprovals({ quiet: true }), load({ quiet: true })]) // update in place, no skeleton flash
    toast.value = `${item.staff}’s overtime for ${fmtDate(item.work_date)} ${decision}.`
  } catch (error) {
    rowError[item.id] = describe(error, 'Your decision wasn’t saved.')
  } finally {
    busyId.value = null
  }
}
</script>

<template>
  <div class="ot">
    <header class="ot-head">
      <div>
        <h1>Overtime</h1>
        <p>Request overtime and track your supervisor’s decision.</p>
      </div>
      <dl v-if="summary" class="ot-stats">
        <div><dt>Pending</dt><dd>{{ summary.pending }}</dd></div>
        <div><dt>Approved this month</dt><dd>{{ hours(summary.approved_this_month_minutes) }}</dd></div>
        <div v-if="canApprove"><dt>Awaiting my decision</dt><dd>{{ summary.awaiting_my_decision }}</dd></div>
      </dl>
    </header>

    <div v-if="canApprove" class="ot-tabs" role="tablist" aria-label="Overtime">
      <button id="ot-tab-mine" type="button" role="tab" :aria-selected="tab === 'mine'" aria-controls="ot-panel" :class="{ 'is-active': tab === 'mine' }" @click="tab = 'mine'">My overtime</button>
      <button id="ot-tab-approvals" type="button" role="tab" :aria-selected="tab === 'approvals'" aria-controls="ot-panel" :class="{ 'is-active': tab === 'approvals' }" @click="tab = 'approvals'">Approvals<span v-if="summary?.awaiting_my_decision" class="ot-count">{{ summary.awaiting_my_decision }}</span></button>
    </div>

    <p v-if="toast" class="ot-toast" role="status"><AppIcon name="check-circle" :size="18" /><span>{{ toast }}</span><button type="button" class="ot-icon-btn" aria-label="Dismiss" @click="toast = ''"><AppIcon name="x" :size="16" /></button></p>

    <div id="ot-panel" :role="canApprove ? 'tabpanel' : undefined" :aria-labelledby="canApprove ? (tab === 'mine' ? 'ot-tab-mine' : 'ot-tab-approvals') : undefined">
      <div v-if="loading" class="ot-skeleton" aria-busy="true" aria-label="Loading overtime"><span></span><span></span></div>
      <section v-else-if="loadError" class="ot-state ot-state--error" role="alert">
        <AppIcon name="alert-circle" :size="28" /><h2>Overtime didn’t load</h2><p>{{ loadError }}</p>
        <button type="button" class="ot-btn ot-btn--primary" @click="load()"><AppIcon name="refresh" :size="16" />Try again</button>
      </section>

      <!-- ===== My overtime ===== -->
      <div v-else-if="tab === 'mine'" class="ot-layout">
        <form ref="formEl" class="ot-card ot-form" novalidate @submit.prevent="submit">
          <h2 class="ot-card__title">New request</h2>
          <p v-if="formError" class="ot-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ formError }}</span></p>
          <p v-else-if="tried && Object.keys(errors).length" class="ot-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>Please fix {{ Object.keys(errors).length === 1 ? 'the highlighted field' : `the ${Object.keys(errors).length} highlighted fields` }}.</span></p>

          <div class="ot-field">
            <label for="ot-work_date" class="ot-label">Date <span class="ot-req" aria-hidden="true">*</span></label>
            <input v-bind="bind('work_date')" v-model="form.work_date" type="date" :min="isoDay(-DAYS_BACK)" :max="isoDay(DAYS_AHEAD)" />
            <p v-if="err('work_date')" id="ot-work_date-error" class="ot-error"><AppIcon name="alert-circle" :size="14" />{{ err('work_date') }}</p>
          </div>

          <div class="ot-grid">
            <div class="ot-field">
              <label for="ot-start_time" class="ot-label">Start time <span class="ot-req" aria-hidden="true">*</span></label>
              <input v-bind="bind('start_time')" v-model="form.start_time" type="time" />
              <p v-if="err('start_time')" id="ot-start_time-error" class="ot-error"><AppIcon name="alert-circle" :size="14" />{{ err('start_time') }}</p>
            </div>
            <div class="ot-field">
              <label for="ot-end_time" class="ot-label">End time <span class="ot-req" aria-hidden="true">*</span></label>
              <input v-bind="bind('end_time')" v-model="form.end_time" type="time" />
              <p v-if="err('end_time')" id="ot-end_time-error" class="ot-error"><AppIcon name="alert-circle" :size="14" />{{ err('end_time') }}</p>
            </div>
          </div>
          <label class="ot-check"><input v-model="form.overnight" type="checkbox" /><span>Ends the next day <small>(overnight work)</small></span></label>

          <div class="ot-duration" :class="{ 'is-empty': duration === null || duration <= 0 }" aria-live="polite">
            <AppIcon name="clock" :size="20" />
            <div v-if="duration !== null && duration > 0"><strong>{{ hours(duration) }}</strong><span>{{ form.start_time }} – {{ form.end_time }}{{ form.overnight ? ' (next day)' : '' }}</span></div>
            <div v-else-if="looksOvernight"><strong>Did this run past midnight?</strong><span>Tick “Ends the next day” and we’ll count the hours across midnight.</span></div>
            <div v-else><strong>Total hours</strong><span>Calculated from your start and end time.</span></div>
          </div>

          <div class="ot-field">
            <label for="ot-supervisor_id" class="ot-label">Approving supervisor <span class="ot-req" aria-hidden="true">*</span></label>
            <select v-bind="bind('supervisor_id')" v-model="form.supervisor_id" :disabled="!supervisors.length">
              <option value="" disabled>{{ supervisors.length ? 'Choose a supervisor…' : 'No supervisors available' }}</option>
              <option v-for="person in supervisors" :key="person.id" :value="person.id">{{ person.name }}{{ person.role === 'admin' ? ' (Master Admin)' : ' (Manager)' }}</option>
            </select>
            <p v-if="err('supervisor_id')" id="ot-supervisor_id-error" class="ot-error"><AppIcon name="alert-circle" :size="14" />{{ err('supervisor_id') }}</p>
            <p v-else-if="!supervisors.length" class="ot-hint">No managers are set up to approve overtime yet. Contact HR.</p>
            <p v-else class="ot-hint">They’ll see this request in their Approvals tab.</p>
          </div>

          <div class="ot-field">
            <label for="ot-reason" class="ot-label">What did you work on? <span class="ot-req" aria-hidden="true">*</span></label>
            <textarea v-bind="bind('reason')" v-model="form.reason" rows="3" placeholder="e.g. Finishing the donor report before Friday’s deadline"></textarea>
            <p v-if="err('reason')" id="ot-reason-error" class="ot-error"><AppIcon name="alert-circle" :size="14" />{{ err('reason') }}</p>
            <p v-else class="ot-hint ot-hint--right">{{ form.reason.length }} / {{ REASON_MAX }}</p>
          </div>

          <footer class="ot-form__foot">
            <button type="submit" class="ot-btn ot-btn--primary ot-btn--lg" :disabled="saving" :aria-busy="saving"><AppIcon :name="saving ? 'refresh' : 'send'" :size="17" :class="{ 'ot-spin': saving }" />{{ saving ? 'Sending…' : 'Submit for approval' }}</button>
          </footer>
        </form>

        <section class="ot-card ot-history" aria-labelledby="ot-history-title">
          <h2 id="ot-history-title" class="ot-card__title">My requests</h2>
          <p v-if="!mine.length" class="ot-empty"><AppIcon name="timer" :size="22" />No overtime requests yet. Your requests and their approval status will appear here.</p>
          <ul v-else class="ot-list">
            <li v-for="item in mine" :key="item.id" class="ot-item">
              <div class="ot-item__top">
                <div><strong>{{ fmtDate(item.work_date) }}</strong><span>{{ item.start_time }} – {{ item.end_time }}{{ item.overnight ? ' (next day)' : '' }} · {{ hours(item.minutes) }}</span></div>
                <span class="ot-badge" :class="`is-${STATUS[item.status].tone}`"><AppIcon :name="STATUS[item.status].icon" :size="13" />{{ STATUS[item.status].label }}</span>
              </div>
              <p class="ot-item__reason">{{ item.reason }}</p>
              <p class="ot-item__meta">{{ item.status === 'pending' ? `Waiting for ${item.supervisor}` : item.status === 'cancelled' ? 'You cancelled this request' : `${STATUS[item.status].label} by ${item.reviewed_by}` }}</p>
              <p v-if="item.review_note" class="ot-item__note" :class="`is-${STATUS[item.status].tone}`"><strong>Note:</strong> {{ item.review_note }}</p>
              <p v-if="rowError[item.id]" class="ot-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ rowError[item.id] }}</p>
              <div v-if="item.can_cancel" class="ot-item__actions">
                <template v-if="confirmingCancel === item.id">
                  <span>Cancel this request?</span>
                  <button type="button" class="ot-btn ot-btn--ghost ot-btn--sm" @click="confirmingCancel = null">Keep it</button>
                  <button type="button" class="ot-btn ot-btn--danger ot-btn--sm" :disabled="busyId === item.id" @click="cancel(item)">{{ busyId === item.id ? 'Cancelling…' : 'Yes, cancel' }}</button>
                </template>
                <button v-else type="button" class="ot-btn ot-btn--ghost ot-btn--sm" @click="confirmingCancel = item.id">Cancel request</button>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <!-- ===== Approvals ===== -->
      <section v-else class="ot-card ot-approvals" aria-labelledby="ot-approvals-title">
        <h2 id="ot-approvals-title" class="ot-card__title">Requests sent to you <span v-if="pendingApprovals.length" class="ot-count">{{ pendingApprovals.length }} waiting</span></h2>
        <div v-if="approvalsLoading" class="ot-skeleton" aria-busy="true"><span></span></div>
        <p v-else-if="approvalsError" class="ot-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ approvalsError }}</span><button type="button" class="ot-btn ot-btn--ghost ot-btn--sm" @click="loadApprovals()">Try again</button></p>
        <p v-else-if="!approvals.length" class="ot-empty"><AppIcon name="inbox" :size="22" />No overtime requests have been sent to you.</p>
        <ul v-else class="ot-list">
          <li v-for="item in approvals" :key="item.id" class="ot-item" :class="{ 'is-pending': item.status === 'pending' }">
            <div class="ot-item__top">
              <div><strong>{{ item.staff }}</strong><span>{{ fmtDate(item.work_date) }} · {{ item.start_time }} – {{ item.end_time }}{{ item.overnight ? ' (next day)' : '' }} · <b>{{ hours(item.minutes) }}</b></span></div>
              <span class="ot-badge" :class="`is-${STATUS[item.status].tone}`"><AppIcon :name="STATUS[item.status].icon" :size="13" />{{ STATUS[item.status].label }}</span>
            </div>
            <p class="ot-item__reason">{{ item.reason }}</p>
            <p v-if="item.review_note" class="ot-item__note" :class="`is-${STATUS[item.status].tone}`"><strong>Your note:</strong> {{ item.review_note }}</p>
            <p v-if="rowError[item.id]" class="ot-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ rowError[item.id] }}</p>
            <div v-if="item.can_decide" class="ot-item__actions ot-item__actions--decide">
              <template v-if="rejecting === item.id">
                <div class="ot-field ot-field--full">
                  <label :for="`ot-note-${item.id}`" class="ot-label">Reason for rejecting <span class="ot-req" aria-hidden="true">*</span></label>
                  <textarea :id="`ot-note-${item.id}`" v-model="rejectNote" rows="2" :aria-invalid="rejectError ? 'true' : undefined" placeholder="e.g. This wasn’t agreed in advance" @input="rejectError = ''"></textarea>
                  <p v-if="rejectError" class="ot-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ rejectError }}</p>
                </div>
                <button type="button" class="ot-btn ot-btn--ghost ot-btn--sm" @click="rejecting = null">Back</button>
                <button type="button" class="ot-btn ot-btn--danger ot-btn--sm" :disabled="busyId === item.id" @click="decide(item, 'rejected')">{{ busyId === item.id ? 'Saving…' : 'Reject request' }}</button>
              </template>
              <template v-else>
                <button type="button" class="ot-btn ot-btn--ghost ot-btn--sm" :disabled="busyId === item.id" @click="startReject(item)"><AppIcon name="x" :size="15" />Reject</button>
                <button type="button" class="ot-btn ot-btn--primary ot-btn--sm" :disabled="busyId === item.id" @click="decide(item, 'approved')"><AppIcon name="check" :size="15" />{{ busyId === item.id ? 'Saving…' : 'Approve' }}</button>
              </template>
            </div>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<style>
.ot { --ot-ink: #17365f; --ot-text: #3d5470; --ot-muted: #6b8099; --ot-line: #dfe7ef; --ot-soft: #f6f9fc; --ot-brand: #0b7f62; --ot-brand-dark: #086a51; --ot-brand-soft: #e8f5f0; --ot-success: #14804a; --ot-success-soft: #e7f6ee; --ot-warning: #a35f00; --ot-warning-soft: #fff4dc; --ot-danger: #c0392b; --ot-danger-soft: #fdf1f0; width: min(100%, 1180px); margin: 0 auto; padding: 24px 0 48px; color: var(--ot-text); font-size: 14px; line-height: 1.5; }
.ot button { margin: 0; } /* older global styles give every button a top margin and large padding */
.ot h1, .ot h2 { color: var(--ot-ink); letter-spacing: -.01em; }
.ot-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.ot-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.ot-head p { margin: 0; color: var(--ot-muted); }
.ot-stats { display: flex; gap: 10px; margin: 0; }
.ot-stats > div { min-width: 120px; padding: 10px 14px; border: 1px solid var(--ot-line); border-radius: 12px; background: #fff; }
.ot-stats dt { color: var(--ot-muted); font-size: .78rem; font-weight: 600; }
.ot-stats dd { margin: 2px 0 0; color: var(--ot-ink); font-size: 1.2rem; font-weight: 700; }

.ot-tabs { display: inline-flex; gap: 4px; margin-bottom: 16px; padding: 4px; border: 1px solid var(--ot-line); border-radius: 12px; background: #fff; }
.ot-tabs button { display: inline-flex; align-items: center; gap: 8px; min-height: 38px; padding: 0 16px; border: 0; border-radius: 9px; background: transparent; color: var(--ot-muted); font: inherit; font-weight: 600; cursor: pointer; }
.ot-tabs button:hover { color: var(--ot-ink); transform: none !important; }
.ot-tabs button.is-active { background: var(--ot-brand-soft); color: var(--ot-brand-dark); }
.ot-tabs button:focus-visible { outline: 3px solid rgba(11, 127, 98, .25); outline-offset: 1px; }
.ot-count { display: inline-flex; align-items: center; padding: 1px 8px; border-radius: 99px; background: var(--ot-warning-soft); color: var(--ot-warning); font-size: .75rem; font-weight: 700; }

.ot-toast { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 10px 10px 10px 14px; border: 1px solid #bfe3d6; border-radius: 10px; background: var(--ot-brand-soft); color: var(--ot-brand-dark); font-weight: 600; }
.ot-toast span { flex: 1; }
.ot-toast svg { flex: none; }

.ot-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; gap: 20px; }
.ot-card { border: 1px solid var(--ot-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.ot-card__title { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; font-size: 1.1rem; font-weight: 700; }
.ot-form { display: grid; gap: 16px; padding: 24px; }
.ot-form > .ot-card__title { margin: 0; }
.ot-history, .ot-approvals { padding: 22px; }

/* Fields */
.ot-field { display: grid; align-content: start; gap: 6px; min-width: 0; }
.ot-field--full { width: 100%; }
.ot-label { display: block; color: var(--ot-ink); font-size: .9rem; font-weight: 600; }
.ot-req { color: var(--ot-danger); }
.ot-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.ot input[type="date"], .ot input[type="time"], .ot select, .ot textarea { box-sizing: border-box; width: 100%; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--ot-ink); font: inherit; font-size: .95rem; transition: border-color .15s, box-shadow .15s; }
.ot input[type="date"], .ot input[type="time"], .ot select { height: 44px; padding: 0 12px; }
.ot textarea { min-height: 80px; padding: 10px 12px; resize: vertical; }
.ot input:focus, .ot select:focus, .ot textarea:focus { border-color: var(--ot-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.ot select:disabled { background: var(--ot-soft); color: var(--ot-muted); }
.ot [aria-invalid="true"] { border-color: var(--ot-danger) !important; }
.ot-check { display: flex; align-items: center; gap: 10px; margin-top: -6px; color: var(--ot-ink); font-weight: 500; cursor: pointer; }
.ot-check input { width: 18px; height: 18px; margin: 0; accent-color: var(--ot-brand); }
.ot-check small { color: var(--ot-muted); font-size: .82rem; }
.ot-error { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--ot-danger); font-size: .82rem; font-weight: 500; }
.ot-error svg { flex: none; }
.ot-hint { margin: 0; color: var(--ot-muted); font-size: .82rem; }
.ot-hint--right { text-align: right; }
.ot-alert { display: flex; align-items: center; gap: 9px; margin: 0; padding: 10px 14px; border: 1px solid #f2c4bf; border-radius: 10px; background: var(--ot-danger-soft); color: #9b2c22; font-size: .9rem; font-weight: 600; }
.ot-alert span { flex: 1; }
.ot-alert svg { flex: none; }
.ot-duration { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 12px; background: var(--ot-brand-soft); color: var(--ot-brand-dark); }
.ot-duration svg { flex: none; margin-top: 2px; }
.ot-duration > div { display: grid; }
.ot-duration strong { color: var(--ot-ink); font-size: 1.05rem; }
.ot-duration span { font-size: .86rem; }
.ot-duration.is-empty { background: var(--ot-soft); color: var(--ot-muted); }
.ot-form__foot { display: flex; justify-content: flex-end; padding-top: 16px; border-top: 1px solid var(--ot-line); }

/* Lists */
.ot-list { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
.ot-item { display: grid; gap: 6px; padding: 14px 16px; border: 1px solid var(--ot-line); border-radius: 12px; }
.ot-item.is-pending { border-color: #f1dfae; background: #fffdf6; }
.ot-item__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.ot-item__top > div { display: grid; min-width: 0; }
.ot-item__top strong { color: var(--ot-ink); }
.ot-item__top span:not(.ot-badge) { color: var(--ot-muted); font-size: .86rem; }
.ot-item__top b { color: var(--ot-ink); }
.ot-item__reason { margin: 0; color: var(--ot-text); overflow-wrap: anywhere; }
.ot-item__meta { margin: 0; color: var(--ot-muted); font-size: .82rem; }
.ot-item__note { margin: 0; padding: 8px 10px; border-radius: 8px; background: var(--ot-soft); font-size: .86rem; overflow-wrap: anywhere; }
.ot-item__note.is-danger { background: var(--ot-danger-soft); color: #7c2a22; }
.ot-item__note.is-success { background: var(--ot-success-soft); color: #145c38; }
.ot-item__actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; padding-top: 4px; }
.ot-item__actions > span { margin-right: auto; color: var(--ot-ink); font-weight: 600; font-size: .88rem; }
.ot-badge { display: inline-flex; flex: none; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: .76rem; font-weight: 700; white-space: nowrap; }
.ot-badge.is-success { background: var(--ot-success-soft); color: var(--ot-success); }
.ot-badge.is-warning { background: var(--ot-warning-soft); color: var(--ot-warning); }
.ot-badge.is-danger { background: var(--ot-danger-soft); color: var(--ot-danger); }
.ot-badge.is-muted { background: #eef2f6; color: #4f6278; }
.ot-empty { display: flex; align-items: center; gap: 12px; margin: 0; padding: 18px; border: 1px dashed #d6e0ea; border-radius: 12px; color: var(--ot-muted); }
.ot-empty svg { flex: none; }

/* Buttons */
.ot-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 16px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .9rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
.ot-btn svg { flex: none; }
.ot-btn--sm { min-height: 36px; padding: 0 12px; font-size: .85rem; }
.ot-btn--lg { min-height: 48px; padding: 0 24px; font-size: .98rem; }
.ot-btn--primary { background: var(--ot-brand); color: #fff; box-shadow: 0 6px 16px rgba(11, 127, 98, .2); }
.ot-btn--primary:hover:not(:disabled) { background: var(--ot-brand-dark); }
.ot-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--ot-ink); }
.ot-btn--ghost:hover:not(:disabled) { border-color: #aebfd1; background: var(--ot-soft); }
.ot-btn--danger { background: var(--ot-danger); color: #fff; }
.ot-btn--danger:hover:not(:disabled) { background: #a33024; }
.ot-btn:disabled { opacity: .65; cursor: progress; }
.ot-icon-btn { display: grid; width: 34px; height: 34px; flex: none; place-items: center; padding: 0; border: 0; border-radius: 9px; background: transparent; color: inherit; cursor: pointer; }
.ot-icon-btn:hover { background: rgba(23, 54, 95, .07); transform: none !important; }
.ot-spin { animation: ot-spin 1s linear infinite; }
@keyframes ot-spin { to { transform: rotate(360deg); } }

.ot-state { display: grid; justify-items: center; gap: 6px; padding: 44px 24px; border: 1px solid #f2c4bf; border-radius: 14px; background: #fff; color: var(--ot-danger); text-align: center; }
.ot-state h2 { margin: 6px 0 0; font-size: 1.1rem; }
.ot-state p { margin: 0 0 8px; }
.ot-skeleton { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.ot-skeleton span { display: block; height: 260px; border-radius: 14px; background: linear-gradient(90deg, #eef2f6 25%, #f6f9fc 50%, #eef2f6 75%); background-size: 200% 100%; animation: ot-shimmer 1.4s ease infinite; }
.ot-approvals .ot-skeleton { grid-template-columns: 1fr; }
.ot-approvals .ot-skeleton span { height: 120px; }
@keyframes ot-shimmer { to { background-position: -200% 0; } }

@media (max-width: 960px) {
  .ot-layout { grid-template-columns: 1fr; }
  .ot-skeleton { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
  .ot { padding-top: 16px; }
  .ot-head h1 { font-size: 1.35rem; }
  .ot-stats { width: 100%; }
  .ot-stats > div { min-width: 0; flex: 1; }
  .ot-tabs { display: flex; }
  .ot-tabs button { flex: 1; justify-content: center; }
  .ot-form, .ot-history, .ot-approvals { padding: 18px 16px; }
  .ot-form__foot .ot-btn { width: 100%; }
  .ot-item__actions .ot-btn { flex: 1; }
  .ot-item__actions > span { flex-basis: 100%; }
}
@media (prefers-reduced-motion: reduce) { .ot-skeleton span, .ot-spin { animation: none; } }
</style>
