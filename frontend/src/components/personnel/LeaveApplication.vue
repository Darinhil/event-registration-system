<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import RequestFormParts from './RequestFormParts.vue'
import { getTimesheet } from '../../services/timesheetService'
import { useAuthStore } from '../../stores/auth'
import { useStaffRequestForm } from '../../utils/staffRequestForm'
import organizationLogo from '../../assets/LLEE_Cambodia_Inverse_L.png'

const REASON_MAX = 500
const LEAVE_TYPES = [
  { type: 'annual', label: 'Annual', note: '' },
  { type: 'sick', label: 'Sick', note: 'Medical documents (if applied)' },
  { type: 'paternity', label: 'Maternity/Paternity', note: 'Document required' },
  { type: 'compassionate', label: 'Compassionate', note: 'Document required' },
  { type: 'special', label: 'Special', note: 'Reason for leave' },
  { type: 'time_in_lieu', label: 'Time in Lieu', note: 'Record of overtime work or other proof' },
  { type: 'unpaid', label: 'Unpaid', note: 'Explanation for request' },
  { type: 'study', label: 'Study Leave', note: 'Document required' },
]
const auth = useAuthStore()
const request = useStaffRequestForm('leave')
const { approvers, approverId, attachment, saving, saved, formError, errors: serverErrors, formEl } = request
const form = reactive({
  name: auth.user?.name || '',
  position: '',
  type: '',
  start: '',
  end: '',
  reason: '',
  staff_signature: '',
  staff_date: new Date().toISOString().slice(0, 10),
  admin_comments: '',
  admin_signature: '',
  admin_date: '',
  authorised_comments: '',
  approval: '',
  rejection_reason: '',
  authorised_signature: '',
  authorised_date: '',
})
const rows = reactive(LEAVE_TYPES.map((item) => ({ ...item, allowance: '', requested: '', used: '' })))
const errors = ref({})
const tried = ref(false)
const balanceError = ref('')
const serverBalances = ref([])
const draftMessage = ref('')
const draftKey = `leave-application-draft-${auth.user?.id || 'user'}`
const activeRow = computed(() => rows.find((row) => row.type === form.type))
const selectedBalance = computed(() => serverBalances.value.find((item) => item.type === form.type))
const overBalance = computed(() => Boolean(selectedBalance.value?.limited && duration.value > selectedBalance.value.remaining))
const balanceOf = (row) => {
  const allowance = Number(row.allowance)
  const used = Number(row.used)
  const requested = Number(row.requested)
  if ([row.allowance, row.used, row.requested].some((value) => value === '' || value === null)) return ''
  return allowance - used - requested
}

const dateValue = (iso) => new Date(`${iso}T00:00:00`)
const workingDays = (start, end) => {
  if (!start || !end || end < start) return 0
  let count = 0
  for (const date = dateValue(start); date <= dateValue(end); date.setDate(date.getDate() + 1)) {
    if (date.getDay() !== 0 && date.getDay() !== 6) count += 1
  }
  return count
}
const duration = computed(() => workingDays(form.start, form.end))
watch(() => [form.type, form.start, form.end], () => {
  if (activeRow.value && form.start && form.end && form.end >= form.start) {
    activeRow.value.requested = String(duration.value)
  }
})

onMounted(async () => {
  try {
    const savedDraft = localStorage.getItem(draftKey)
    if (savedDraft) {
      const draft = JSON.parse(savedDraft)
      Object.assign(form, draft.form || {})
      approverId.value = draft.approverId || ''
      rows.forEach((row) => {
        const values = draft.rows?.find((item) => item.type === row.type)
        if (values) Object.assign(row, values)
      })
    }
  } catch {
    draftMessage.value = 'The saved draft could not be read. You can continue and save a new draft.'
  }

  const today = new Date()
  try {
    const { data } = await getTimesheet(today.getFullYear(), today.getMonth() + 1)
    serverBalances.value = data.data.balances
    for (const row of rows) {
      const item = data.data.balances.find((balance) => balance.type === row.type)
      if (!item) continue
      if (row.allowance === '') row.allowance = String(item.entitlement + item.carried_over)
      if (row.used === '') row.used = String(item.used_before + item.pending + item.used_this_month)
    }
  } catch {
    balanceError.value = 'Leave balances could not be loaded. You can enter the values manually.'
  }
})

const err = (key) => errors.value[key] || serverErrors[{ start: 'start_date', end: 'end_date' }[key] || key] || ''
const validate = () => {
  const found = {}
  if (!form.name.trim()) found.name = 'Enter the staff member’s name.'
  if (!form.position.trim()) found.position = 'Enter the staff member’s position.'
  if (!form.type) found.type = 'Select a type of leave.'
  if (!form.start) found.start = 'Choose the first day of leave.'
  if (!form.end) found.end = 'Choose the last day of leave.'
  else if (form.start && form.end < form.start) found.end = 'The end date can’t be before the start date.'
  else if (form.start && duration.value === 0) found.end = 'Choose at least one working day.'
  if (overBalance.value) found.end = `This request is more than your ${selectedBalance.value.remaining} remaining ${selectedBalance.value.label.toLowerCase()} leave days.`
  if (activeRow.value && Number(activeRow.value.requested) <= 0) found.days = 'Requested days must be greater than zero.'
  if (rows.some((row) => [row.allowance, row.requested, row.used].some((value) => value !== '' && (!Number.isFinite(Number(value)) || Number(value) < 0)))) {
    found.days = 'Allowance, requested, and used days must be zero or more.'
  }
  if (form.reason.length > REASON_MAX) found.reason = `Keep the reason under ${REASON_MAX} characters.`
  if (form.approval === 'no' && !form.rejection_reason.trim()) found.rejection_reason = 'Enter a reason if leave is not approved.'
  if (!approverId.value) found.approver_id = 'Choose who should approve this request.'
  errors.value = found
  return Object.keys(found).length === 0
}
watch([form, approverId], () => { if (tried.value) validate() }, { deep: true })

const saveDraft = () => {
  try {
    localStorage.setItem(draftKey, JSON.stringify({ form: { ...form }, rows, approverId: approverId.value }))
    draftMessage.value = 'Draft saved on this device.'
  } catch {
    draftMessage.value = 'The draft could not be saved in this browser.'
  }
}
const submit = async () => {
  tried.value = true
  if (!validate()) {
    request.focusFirstError()
    return
  }
  await request.submit({
    leave_type: form.type,
    start_date: form.start,
    end_date: form.end,
    reason: form.reason.trim(),
    staff_name: form.name.trim(),
    position: form.position.trim(),
    allowance: activeRow.value?.allowance || null,
    used: activeRow.value?.used || null,
    balance: balanceOf(activeRow.value || {}),
    staff_signature: form.staff_signature.trim(),
  })
}
const reset = () => {
  Object.assign(form, {
    name: auth.user?.name || '',
    position: '',
    type: '',
    start: '',
    end: '',
    reason: '',
    staff_signature: '',
    staff_date: new Date().toISOString().slice(0, 10),
    admin_comments: '',
    admin_signature: '',
    admin_date: '',
    authorised_comments: '',
    approval: '',
    rejection_reason: '',
    authorised_signature: '',
    authorised_date: '',
  })
  rows.forEach((row) => Object.assign(row, { allowance: '', requested: '', used: '' }))
  approverId.value = ''
  attachment.value = null
  request.reset()
  errors.value = {}
  tried.value = false
  draftMessage.value = ''
  try {
    localStorage.removeItem(draftKey)
  } catch {
    draftMessage.value = 'The form was reset, but its saved draft could not be removed from this browser.'
  }
}
const printForm = () => window.print()
</script>

<template>
  <div class="lv-document-page">
    <header class="lv-page-heading"><h1>Leave Application</h1></header>
    <form ref="formEl" class="lv-document" novalidate @submit.prevent="submit">
      <header class="lv-document__header">
        <img :src="organizationLogo" alt="Live &amp; Learn Cambodia" />
        <h2>LEAVE APPLICATION<br /><span>FORM</span></h2>
      </header>

      <section class="lv-section" aria-labelledby="lv-staff-title">
        <h3 id="lv-staff-title">PART A: STAFF MEMBER</h3>
        <div class="lv-staff-fields">
          <label>Name <span class="lv-required">*</span><input v-model="form.name" autocomplete="name" :aria-invalid="err('name') ? 'true' : undefined" /></label>
          <label>Position <span class="lv-required">*</span><input v-model="form.position" :aria-invalid="err('position') ? 'true' : undefined" /></label>
        </div>
        <p v-if="err('name')" class="lv-error">{{ err('name') }}</p>
        <p v-if="err('position')" class="lv-error">{{ err('position') }}</p>
        <p class="lv-intro">I wish to apply for the following leave:</p>

        <div class="lv-table-scroll">
          <table class="lv-table">
            <thead>
              <tr>
                <th rowspan="2" scope="col">Type of Leave<small>(1)</small></th>
                <th colspan="4" scope="colgroup">Number of Days</th>
                <th colspan="2" scope="colgroup">Schedule</th>
              </tr>
              <tr>
                <th scope="col">Allowance<small>(2)</small></th>
                <th scope="col">Requested<small>(3)</small></th>
                <th scope="col">Used<small>(4)</small></th>
                <th scope="col">Balance<small>(5=2−3−4)</small></th>
                <th scope="col">Start Date<small>(6)</small></th>
                <th scope="col">End Date<small>(7)</small></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.type" :class="{ 'is-selected': form.type === row.type }">
                <th scope="row">
                  <label class="lv-type-choice">
                    <input v-model="form.type" type="radio" name="leave-type" :value="row.type" :aria-invalid="err('type') ? 'true' : undefined" />
                    <span><strong>{{ row.label }}</strong><small v-if="row.note">{{ row.note }}</small></span>
                  </label>
                </th>
                <td><input v-model="row.allowance" type="number" min="0" step="0.5" :aria-label="`${row.label} allowance in days`" /></td>
                <td><input v-model="row.requested" type="number" min="0" step="0.5" :aria-label="`${row.label} requested days`" /></td>
                <td><input v-model="row.used" type="number" min="0" step="0.5" :aria-label="`${row.label} used days`" /></td>
                <td><output :aria-label="`${row.label} balance in days`">{{ balanceOf(row) }}</output></td>
                <td><input v-if="form.type === row.type" v-model="form.start" type="date" aria-label="Leave start date" :aria-invalid="err('start') ? 'true' : undefined" /></td>
                <td><input v-if="form.type === row.type" v-model="form.end" type="date" aria-label="Leave end date" :min="form.start || undefined" :aria-invalid="err('end') ? 'true' : undefined" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="err('type')" class="lv-error">{{ err('type') }}</p>
        <p v-if="err('start')" class="lv-error">{{ err('start') }}</p>
        <p v-if="err('end')" class="lv-error">{{ err('end') }}</p>
          <p v-if="overBalance" class="lv-error">The requested days exceed the recorded leave balance.</p>
          <p v-if="err('days')" class="lv-error">{{ err('days') }}</p>
        <p v-if="balanceError" class="lv-hint" role="status">{{ balanceError }}</p>

        <label class="lv-reason">Reasons (if any):<textarea v-model="form.reason" rows="2" :maxlength="REASON_MAX" :aria-invalid="err('reason') ? 'true' : undefined"></textarea></label>
        <p v-if="err('reason')" class="lv-error">{{ err('reason') }}</p>
        <div class="lv-signature-row">
          <label>Staff Signature<input v-model="form.staff_signature" aria-label="Staff signature" /></label>
          <label>Date<input v-model="form.staff_date" type="date" /></label>
        </div>
      </section>

      <section class="lv-section lv-section--separated" aria-labelledby="lv-admin-title">
        <h3 id="lv-admin-title">PART B: ADMINISTRATION OFFICER</h3>
        <label class="lv-comments">Comments<textarea v-model="form.admin_comments" rows="2"></textarea></label>
        <div class="lv-signature-row">
          <label>Signature<input v-model="form.admin_signature" /></label>
          <label>Date<input v-model="form.admin_date" type="date" /></label>
        </div>
      </section>

      <section class="lv-section lv-section--separated" aria-labelledby="lv-authorised-title">
        <h3 id="lv-authorised-title">PART C: AUTHORISED OFFICER</h3>
        <label class="lv-comments">Comments<textarea v-model="form.authorised_comments" rows="2"></textarea></label>
        <fieldset class="lv-approval">
          <legend>Leave Approved:</legend>
          <label><input v-model="form.approval" type="radio" name="leave-approval" value="yes" /> YES</label>
          <label><input v-model="form.approval" type="radio" name="leave-approval" value="no" /> NO (Give reason)</label>
          <input v-if="form.approval === 'no'" v-model="form.rejection_reason" aria-label="Reason leave was not approved" :aria-invalid="err('rejection_reason') ? 'true' : undefined" />
        </fieldset>
        <p v-if="err('rejection_reason')" class="lv-error">{{ err('rejection_reason') }}</p>
        <div class="lv-signature-row">
          <label>Signature<input v-model="form.authorised_signature" /></label>
          <label>Date<input v-model="form.authorised_date" type="date" /></label>
        </div>
      </section>

      <div class="lv-submit-fields">
        <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="{ ...serverErrors, ...errors }" />
      </div>
      <p v-if="formError" class="lv-form-error" role="alert"><AppIcon name="alert-circle" :size="17" />{{ formError }}</p>
      <p v-if="saved" class="lv-saved" role="status"><AppIcon name="check-circle" :size="17" />Leave request submitted to {{ saved.approver }}.</p>
      <p v-if="draftMessage" class="lv-draft-message" role="status"><AppIcon name="save" :size="15" /><span>{{ draftMessage }}</span></p>
      <footer class="lv-actions">
        <button type="button" class="lv-button lv-button--secondary" @click="saveDraft">Save</button>
        <button type="button" class="lv-button lv-button--secondary" @click="printForm">Print</button>
        <button type="button" class="lv-button lv-button--secondary" @click="reset">Reset</button>
        <button type="submit" class="lv-button lv-button--primary" :disabled="saving || !!saved"><AppIcon name="send" :size="16" />{{ saving ? 'Submitting…' : saved ? 'Submitted' : 'Submit application' }}</button>
      </footer>
    </form>
  </div>
</template>

<style scoped>
.lv-document-page { width: min(100%, 1180px); margin: 0 auto; padding: 22px 0 48px; color: #152b40; font: 16px/1.55 Arial, Helvetica, sans-serif; }
.lv-page-heading { margin-bottom: 16px; }
.lv-page-heading h1 { margin: 0; color: #17365f; font-size: 1.7rem; font-weight: 750; letter-spacing: -.025em; }
.lv-document { display: grid; gap: 18px; padding: 32px 36px; border: 1px solid #d7e1eb; border-radius: 14px; background: #fff; box-shadow: 0 12px 32px #17365f12, 0 2px 6px #17365f08; }
.lv-document__header { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 4px 0 18px; border-bottom: 1px solid #e6edf3; }
  .lv-document__header img { display: block; width: min(30%, 220px); height: auto; object-fit: contain; object-position: left center; }
.lv-document__header h2 { margin: 0; color: #102943; font-size: 1.55rem; font-weight: 750; letter-spacing: .025em; line-height: 1.2; text-align: right; }
.lv-document__header h2 span { font-size: 1.15rem; }
.lv-section h3 { display: flex; align-items: center; gap: 10px; margin: 4px 0 14px; color: #17365f; font-size: 1.12rem; font-weight: 750; letter-spacing: .015em; }
.lv-section h3::before { width: 4px; height: 1.15em; border-radius: 4px; background: #087f66; content: ""; }
.lv-section--separated { margin-top: 3px; padding-top: 20px; border-top: 1px dashed #9aa8b6; }
.lv-staff-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; }
.lv-staff-fields label, .lv-signature-row label { display: flex; align-items: baseline; gap: 9px; min-width: 0; color: #293e53; font-weight: 650; }
.lv-required { color: #b42318; }
.lv-document input:not([type="radio"]):not([type="checkbox"]), .lv-document textarea { box-sizing: border-box; min-width: 0; width: 100%; padding: 8px 10px; border: 1px solid #c5d1dc; border-radius: 6px; background: #fbfdff; color: #152b40; font: inherit; transition: border-color .16s, box-shadow .16s, background-color .16s; }
.lv-staff-fields input, .lv-signature-row input { flex: 1; }
.lv-document input:focus, .lv-document textarea:focus { border-color: #0b8067; outline: 0; background: #fff; box-shadow: 0 0 0 3px #0b80671c; }
.lv-document [aria-invalid="true"] { border-color: #bf3d39 !important; box-shadow: 0 0 0 2px #bf3d3914; }
.lv-intro { margin: 16px 0 8px; color: #273f56; font-weight: 650; }
.lv-table-scroll { width: 100%; overflow-x: auto; }
.lv-table { width: 100%; min-width: 940px; border-collapse: collapse; table-layout: fixed; }
.lv-table th, .lv-table td { border: 1px solid #111; }
.lv-table thead th { padding: 9px 6px; background: #b6d1ed; color: #18395b; text-align: center; font-size: 13px; line-height: 1.3; }
.lv-table thead tr:first-child th { background: #a8c8e9; }
.lv-table thead th:first-child { width: 25%; }
.lv-table thead tr:first-child th:first-child { font-size: 11px; }
.lv-table thead tr:first-child th:first-child small { font-size: 9px; }
.lv-table thead tr:first-child th:nth-child(2) { width: 48%; }
.lv-table thead tr:first-child th:nth-child(3) { width: 27%; }
.lv-table thead small { display: block; margin-top: 2px; font-size: 11px; font-weight: 400; }
.lv-table tbody th, .lv-table tbody td { height: 52px; padding: 6px 8px; font-size: 13px; transition: background-color .14s; }
.lv-table tbody th { text-align: left; }
.lv-table tbody tr:hover th, .lv-table tbody tr:hover td { background: #f3f8fc; }
.lv-table tbody tr.is-selected th, .lv-table tbody tr.is-selected td { background: #edf7f4; }
.lv-table tbody tr.is-selected th { box-shadow: inset 3px 0 #087f66; }
.lv-type-choice { display: flex; align-items: flex-start; gap: 9px; cursor: pointer; }
.lv-type-choice input { width: 16px; height: 16px; flex: none; margin: 3px 0 0; accent-color: #087f66; }
.lv-type-choice span { display: grid; }
.lv-type-choice strong { font-size: 13px; }
.lv-type-choice small { color: #53677a; font-size: 11px; font-weight: 400; }
.lv-table td input { height: 36px; padding: 4px 5px !important; border: 1px solid #d5e0e9 !important; border-radius: 4px !important; background: #fff !important; text-align: center; font-size: 13px !important; }
.lv-table td output { display: block; color: #17365f; font-weight: 700; text-align: center; font-variant-numeric: tabular-nums; }
.lv-reason, .lv-comments { display: grid; gap: 6px; margin-top: 13px; color: #293e53; font-weight: 650; }
.lv-reason textarea, .lv-comments textarea { min-height: 42px; resize: vertical; }
.lv-signature-row { display: grid; grid-template-columns: 1fr 1fr; gap: 56px; margin-top: 16px; }
.lv-signature-row input { border: 0 !important; border-bottom: 1px solid #65788b !important; border-radius: 0 !important; background: transparent !important; }
.lv-approval { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; margin: 14px 0 0; padding: 12px 14px; border: 1px solid #dbe5ed; border-radius: 7px; background: #f8fafc; }
.lv-approval legend { float: left; margin-right: 4px; padding: 0; font-weight: 600; }
.lv-approval label { display: inline-flex; align-items: center; gap: 4px; }
.lv-approval input[type="radio"] { width: 16px; height: 16px; accent-color: #087f66; }
.lv-approval > input { flex: 1; }
.lv-error, .lv-hint, .lv-form-error, .lv-saved, .lv-draft-message { margin: 5px 0 0; font-size: .92rem; }
.lv-error, .lv-form-error { color: #a12622; }
.lv-hint,   .lv-draft-message { margin: 6px 0 0; font-size: .86rem; color: #5b6672; display: flex; align-items: center; gap: 7px; }
.lv-form-error, .lv-saved { display: flex; align-items: center; gap: 8px; }
.lv-saved { color: #176b47; }
.lv-submit-fields { padding: 16px 0 0; border-top: 1px solid #e0e8ef; }
.lv-submit-fields :deep(.rq-part) { display: grid; gap: 8px; margin: 0; }
.lv-submit-fields :deep(.rq-part--attachment) { margin-top: 8px; }
.lv-submit-fields :deep(.rq-part__label) { display: flex; width: fit-content; align-items: center; gap: 7px; margin: 0; color: #293e53; font-size: .95rem; font-weight: 650; }
.lv-submit-fields :deep(.lv-req) { display: inline; color: #b42318; }
.lv-submit-fields :deep(.lv-optional) { margin: 0; padding: 2px 8px; border-radius: 99px; background: #e8eef3; color: #5b6d7f; font-size: .75rem; font-weight: 600; }
.lv-submit-fields :deep(.rq-select) { box-sizing: border-box; width: 100%; min-height: 42px; padding: 7px 12px; border: 1px solid #cbd7e2; border-radius: 6px; background: #fff; color: #314b63; font: inherit; font-size: .88rem; transition: border-color .16s, box-shadow .16s; }
.lv-submit-fields :deep(.rq-select:focus) { border-color: #0b8067; outline: 0; box-shadow: 0 0 0 3px #0b80671c; }
.lv-submit-fields :deep(.rq-file-box) { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 10px; margin-top: 0; padding: 8px 10px; border: 1px dashed #cbd8e4; border-radius: 7px; background: #f8fafc; color: #53677a; }
.lv-submit-fields :deep(.rq-file-box.has-file) { border-style: solid; border-color: #9acbbb; background: #f2faf7; }
.lv-submit-fields :deep(.rq-file-box.has-error) { border-color: #bf3d39; background: #fff8f7; }
.lv-submit-fields :deep(.rq-file-box > svg:first-child) { flex: none; color: #087f66; }
.lv-submit-fields :deep(.lv-file__text) { display: grid; min-width: 0; gap: 3px; }
.lv-submit-fields :deep(.lv-file__text strong) { overflow: hidden; color: #314b63; font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
.lv-submit-fields :deep(.lv-file__text small) { color: #6a7f92; font-size: .72rem; }
.lv-submit-fields :deep(.rq-file-box > .lv-btn--ghost:not(.rq-file-choose)) { grid-column: 3; justify-self: end; padding: 5px 8px; border: 1px solid #d4dfe8; border-radius: 6px; background: #fff; color: #52677b; font: inherit; font-size: .75rem; cursor: pointer; }
.lv-submit-fields :deep(.rq-file-box > .lv-btn--ghost:not(.rq-file-choose):hover) { color: #a12622; text-decoration: underline; }
.lv-submit-fields :deep(.rq-file-choose) { display: inline-flex; width: auto; min-width: 90px; min-height: 32px; flex: none; flex-direction: row; gap: 6px; padding: 5px 10px; border: 1px solid #d3dee8; border-radius: 6px; background: #fff; color: #35516a; font: inherit; font-size: .75rem; font-weight: 600; box-shadow: 0 1px 2px #17365f0b; cursor: pointer; transition: background-color .15s, border-color .15s, box-shadow .15s; }
.lv-submit-fields :deep(.rq-file-choose:hover) { border-color: #8ea9bf; background: #f1f6fa; box-shadow: 0 2px 5px #17365f12; }
.lv-submit-fields :deep(.rq-file-choose:focus-visible) { outline: 3px solid #8bcdb7; outline-offset: 3px; }
.lv-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; padding-top: 16px; border-top: 1px solid #e0e8ef; }
.lv-button { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; gap: 8px; padding: 0 18px; border: 1px solid #c7d3de; border-radius: 7px; background: #fff; color: #17365f; font: inherit; font-size: 1rem; font-weight: 650; cursor: pointer; transition: background-color .15s, border-color .15s, box-shadow .15s, transform .15s; }
.lv-button--secondary:hover { border-color: #7d9bb4; background: #f5f8fb; }
.lv-button--primary { border-color: #087f66; background: #087f66; color: #fff; box-shadow: 0 4px 10px #087f6626; }
.lv-button--primary:hover:not(:disabled) { transform: translateY(-1px); background: #066a55; box-shadow: 0 6px 14px #087f6630; }
.lv-button:focus-visible { outline: 3px solid #90c6b7; outline-offset: 2px; }
.lv-button:disabled { opacity: .6; cursor: wait; }
@media (max-width: 700px) {
  .lv-document-page { padding: 14px 0 28px; }
  .lv-document { gap: 14px; padding: 20px 16px; border-radius: 9px; }
  .lv-document__header img { width: 45%; }
  .lv-document__header h2 { font-size: 1rem; }
  .lv-staff-fields, .lv-signature-row { grid-template-columns: 1fr; gap: 10px; }
  .lv-table { min-width: 940px; }
  .lv-submit-fields :deep(.rq-file-box) { gap: 8px; padding: 8px; }
  .lv-actions { justify-content: stretch; }
  .lv-button { flex: 1 1 calc(50% - 10px); padding-inline: 9px; }
}
@media (max-width: 420px) {
  .lv-submit-fields :deep(.rq-file-box) { grid-template-columns: auto minmax(0, 1fr); }
  .lv-submit-fields :deep(.rq-file-box > .lv-btn--ghost:not(.rq-file-choose)), .lv-submit-fields :deep(.rq-file-choose) { grid-column: 2; justify-self: start; }
}
@media print {
  :global(body) { background: #fff !important; }
  .lv-document-page { width: 100%; padding: 0; color: #000; font-size: 11px; }
  .lv-page-heading, .lv-submit-fields, .lv-actions, .lv-form-error, .lv-saved, .lv-draft-message { display: none !important; }
  .lv-document { gap: 8px; padding: 0; border: 0; border-radius: 0; box-shadow: none; }
  .lv-table-scroll { overflow: visible; }
  .lv-table { min-width: 0; }
  .lv-document input, .lv-document textarea { color: #000 !important; }
}
</style>
