<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import LeaveApplication from './LeaveApplication.vue'
import OvertimeRequest from './OvertimeRequest.vue'
import MonthlyTimesheet from './MonthlyTimesheet.vue'
import { getMyOvertime } from '../../services/overtimeService'
import { getMyRequests } from '../../services/staffRequestService'
import { getTimesheet } from '../../services/timesheetService'
import { useStaffRequestForm } from '../../utils/staffRequestForm'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const activeForm = ref('leave')
const loading = ref(true)
const loadError = ref('')
const balances = ref([])
const overtimeSummary = ref(null)
const requests = ref([])
const statusFilter = ref('all')
const now = new Date()
const period = { year: now.getFullYear(), month: now.getMonth() + 1 }
const leaveForm = reactive({ type: 'annual', start: '', end: '', reason: '' })
const leaveRequest = useStaffRequestForm('leave')
const submitLeave = () => {
  if (leaveForm.start && leaveForm.end && leaveForm.end < leaveForm.start) return
  leaveRequest.submit({ leave_type: leaveForm.type, start_date: leaveForm.start, end_date: leaveForm.end, reason: leaveForm.reason.trim() })
}
const startNewLeave = () => { Object.assign(leaveForm, { type: 'annual', start: '', end: '', reason: '' }); leaveRequest.reset() }

const forms = [
  { id: 'leave', title: 'Leave Application', description: 'Request time off and review your leave balance.', action: 'Apply for leave', icon: 'calendar-check', section: 'leave-application' },
  { id: 'overtime', title: 'Overtime Request', description: 'Record extra hours and send them for approval.', action: 'Request overtime', icon: 'timer', section: 'overtime-request' },
  { id: 'timesheet', title: 'Monthly Timesheet', description: 'Enter daily hours and submit your monthly timesheet.', action: 'Open timesheet', icon: 'calendar', section: 'monthly-timesheet' },
]
const currentForm = computed(() => forms.find((form) => form.id === activeForm.value))
const filteredRequests = computed(() => statusFilter.value === 'all' ? requests.value : requests.value.filter((item) => item.type_label?.toLowerCase().includes(statusFilter.value)))
const statuses = ['all', 'leave', 'overtime']
const statusLabel = (status) => status === 'all' ? 'All activity' : status.charAt(0).toUpperCase() + status.slice(1)
const dateLabel = (value) => value ? new Date(value.length === 10 ? `${value}T00:00:00` : value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const remainingAnnual = computed(() => balances.value.find((item) => item.type === 'annual')?.remaining)
const overtimeHours = computed(() => ((overtimeSummary.value?.approved_this_month_minutes || 0) / 60).toFixed(1))
const timesheetHours = computed(() => {
  const entries = timesheet.value?.entries || {}
  return Object.values(entries).reduce((total, entry) => total + Number(entry?.hours || 0), 0).toFixed(1)
})
const timesheet = ref(null)

onMounted(async () => {
  try {
    const [sheet, overtime, requestList] = await Promise.all([getTimesheet(period.year, period.month), getMyOvertime(), getMyRequests()])
    timesheet.value = sheet.data.data
    balances.value = sheet.data.data.balances || []
    overtimeSummary.value = overtime.data.summary || {}
    requests.value = [
      ...requestList.data.data.map((item) => ({ ...item, rowKey: `request-${item.id}` })),
      ...overtime.data.data.map((item) => ({ rowKey: `overtime-${item.id}`, type_label: 'Overtime', summary: `${(item.minutes / 60).toFixed(1)} hours on ${dateLabel(item.work_date)}`, status: item.status, submitted_at: item.submitted_at, approver: item.supervisor })),
    ].sort((a, b) => new Date(b.submitted_at) - new Date(a.submitted_at))
  } catch {
    loadError.value = 'Some time management details could not be loaded. You can still open each form below.'
  } finally {
    loading.value = false
  }
})

const openForm = (id) => {
  activeForm.value = id
  document.getElementById('tm-workstation')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
const openFullPage = () => router.push({ path: '/admin/personnel-profile', query: { section: currentForm.value.section } })
</script>

<template>
  <main class="tm" aria-labelledby="tm-title">
    <header class="tm-heading">
      <div><h1 id="tm-title">Time Management</h1><p>Review your time off, overtime and monthly work record.</p></div>
    </header>

    <p v-if="loadError" class="tm-notice" role="status">{{ loadError }}</p>

    <section class="tm-metrics" aria-label="Time management summary">
      <article class="tm-metric">
        <span class="tm-icon tm-icon--green"><AppIcon name="calendar-check" :size="18" /></span><small>Annual leave balance</small>
        <strong>{{ loading ? '—' : remainingAnnual ?? '—' }}<span v-if="!loading && remainingAnnual !== undefined"> days remaining</span></strong><p>Available leave days</p>
      </article>
      <article class="tm-metric">
        <span class="tm-icon tm-icon--blue"><AppIcon name="clock" :size="18" /></span><small>Overtime this month</small>
        <strong>{{ loading ? '—' : overtimeHours }}<span> hours logged</span></strong><p>Approved overtime</p>
      </article>
      <article class="tm-metric">
        <span class="tm-icon tm-icon--orange"><AppIcon name="calendar" :size="18" /></span><small>Monthly timesheet</small>
        <strong>{{ loading ? '—' : timesheetHours }}<span> hours entered</span></strong><p>{{ new Intl.DateTimeFormat('en', { month: 'long', year: 'numeric' }).format(now) }}</p>
      </article>
    </section>

    <section class="tm-panel" aria-labelledby="tm-access-title">
      <div class="tm-panel-heading"><div><h2 id="tm-access-title">Direct Access Workstation</h2><p>Choose a form to start or continue your request.</p></div><button class="tm-text-link" type="button" @click="router.push({ path: '/admin/personnel-profile', query: { section: 'my-requests' } })">Track requests <span aria-hidden="true">→</span></button></div>
      <div class="tm-shortcuts">
        <button v-for="form in forms" :key="form.id" type="button" class="tm-shortcut" @click="openForm(form.id)">
          <span class="tm-shortcut-icon" :class="`tm-shortcut-icon--${form.id}`"><AppIcon :name="form.icon" :size="17" /></span><strong>{{ form.title }}</strong><span>{{ form.description }}</span><b>{{ form.action }}</b>
        </button>
      </div>
    </section>

    <section id="tm-workstation" class="tm-panel tm-workstation" aria-labelledby="tm-form-title">
      <div class="tm-panel-heading"><div><h2 id="tm-form-title">Direct Form Access Workstation</h2><p>Complete and submit your selected time management form.</p></div><button class="tm-full-link" type="button" @click="openFullPage">Full page view <span aria-hidden="true">↗</span></button></div>
      <div class="tm-tabs" role="tablist" aria-label="Time management forms">
        <button v-for="form in forms" :key="form.id" type="button" role="tab" :aria-selected="activeForm === form.id" :class="{ 'is-active': activeForm === form.id }" @click="activeForm = form.id">{{ form.title }}</button>
      </div>
      <div v-if="activeForm === 'leave'" class="tm-direct-form">
        <div v-if="leaveRequest.saved.value" class="tm-success" role="status"><span class="tm-icon tm-icon--green"><AppIcon name="check-circle" :size="18" /></span><div><strong>Leave request submitted</strong><p>Your request has been sent to your approver.</p></div><button type="button" class="tm-secondary" @click="startNewLeave">New request</button></div>
        <form v-else class="tm-leave-form" @submit.prevent="submitLeave">
          <p v-if="leaveRequest.formError.value" class="tm-notice" role="alert">{{ leaveRequest.formError.value }}</p>
          <div class="tm-field"><label>Employee name</label><input :value="auth.user?.name || ''" readonly /></div>
          <div class="tm-form-grid">
            <div class="tm-field"><label for="tm-leave-type">Leave type</label><select id="tm-leave-type" v-model="leaveForm.type"><option value="annual">Annual</option><option value="sick">Sick</option><option value="unpaid">Unpaid</option></select></div>
            <div class="tm-field"><label for="tm-leave-start">Start date</label><DateInput id="tm-leave-start" v-model="leaveForm.start" required /></div>
            <div class="tm-field"><label for="tm-leave-end">End date</label><DateInput id="tm-leave-end" v-model="leaveForm.end" :min="leaveForm.start || undefined" required /></div>
          </div>
          <div class="tm-field"><label for="tm-leave-reason">Reason / message to supervisor</label><textarea id="tm-leave-reason" v-model="leaveForm.reason" rows="2" maxlength="500" placeholder="Add a short reason or handover note"></textarea></div>
          <div class="tm-assignee"><RequestFormParts v-model:approver-id="leaveRequest.approverId.value" v-model:attachment="leaveRequest.attachment.value" :approvers="leaveRequest.approvers.value" :errors="leaveRequest.errors" :with-attachment="false" /></div>
          <footer class="tm-form-footer"><span>Required fields are marked. Your request will be sent for approval.</span><div><button type="submit" class="tm-submit" :disabled="leaveRequest.saving.value">{{ leaveRequest.saving.value ? 'Submitting…' : 'Submit leave request' }}</button><button type="button" class="tm-secondary" @click="openFullPage">Full page view</button></div></footer>
        </form>
      </div>
      <KeepAlive v-else><component :is="activeForm === 'overtime' ? OvertimeRequest : MonthlyTimesheet" /></KeepAlive>
    </section>

    <section class="tm-panel tm-ledger" aria-labelledby="tm-ledger-title">
      <div class="tm-panel-heading"><div><h2 id="tm-ledger-title">Recent Activity &amp; Approvals Ledger</h2><p>Follow the status of your submitted time requests.</p></div><div class="tm-filters" aria-label="Filter requests by status"><button v-for="status in statuses" :key="status" type="button" :class="{ 'is-active': statusFilter === status }" @click="statusFilter = status">{{ statusLabel(status) }}</button></div></div>
      <div class="tm-table-wrap"><table><thead><tr><th>Request</th><th>Module</th><th>Request / Description</th><th>Submitted</th><th>Approver</th><th>Status</th></tr></thead><tbody>
        <tr v-if="loading"><td colspan="6" class="tm-empty">Loading recent activity…</td></tr>
        <tr v-else-if="!filteredRequests.length"><td colspan="6" class="tm-empty">No requests match this filter.</td></tr>
        <tr v-for="(item, index) in filteredRequests" :key="item.rowKey"><td>{{ item.id || index + 1 }}</td><td>{{ item.type_label }}</td><td>{{ item.summary }}</td><td>{{ dateLabel(item.submitted_at) }}</td><td>{{ item.approver || '—' }}</td><td><span class="tm-status" :class="`is-${item.status}`">{{ statusLabel(item.status) }}</span></td></tr>
      </tbody></table></div>
    </section>
  </main>
</template>

<style scoped>
.tm { display:grid; width:100%; max-width:none; gap:12px; margin:0; color:#15314a; }
.tm-heading h1 { margin:0; color:#0d3155; font-size:25px; line-height:1.2; }
.tm-heading p,.tm-panel-heading p { margin:3px 0 0; color:#72849b; font-size:12px; }
.tm-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
.tm-metric,.tm-panel { min-width:0; border:1px solid #d9e3ef; border-radius:10px; background:#fff; box-shadow:0 3px 10px #1d41620c; }
.tm-metric { display:grid; align-content:start; gap:6px; min-height:104px; padding:12px 14px; border-top:3px solid #16b98f; background:linear-gradient(135deg,#fff 34%,#e4f7f1 100%); }
.tm-metric:nth-child(2) { border-top-color:#4b87ff; background:linear-gradient(135deg,#fff 34%,#e9f0ff 100%); }
.tm-metric:nth-child(3) { border-top-color:#f4a51c; background:linear-gradient(135deg,#fff 34%,#fff1d9 100%); }
.tm-icon,.tm-shortcut-icon { display:grid; place-items:center; width:26px; height:26px; border-radius:8px; }
.tm-icon--green { color:#087a5d; background:#c9f1e4; }.tm-icon--blue { color:#245ed0; background:#d7e5ff; }.tm-icon--orange { color:#b66a00; background:#ffebc8; }
.tm-metric small { color:#68809a; font-size:11px; }.tm-metric strong { color:#122b47; font-size:22px; line-height:1; }.tm-metric strong span { font-size:10px; font-weight:500; }.tm-metric p { margin:0; color:#8798aa; font-size:9px; }
.tm-panel { padding:12px 14px; }.tm-panel-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }.tm-panel-heading h2 { margin:0; color:#14365b; font-size:14px; }.tm-text-link,.tm-full-link { border:0; color:#0b7e72; background:transparent; font-size:10px; cursor:pointer; }.tm-full-link { padding:6px 9px; border:1px solid #d6e4f1; border-radius:5px; color:#316ca7; }
.tm-shortcuts { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }.tm-shortcut { display:grid; justify-items:start; gap:6px; min-width:0; padding:10px; text-align:left; color:#244360; border:1px solid #dce5ef; border-radius:8px; background:#fff; cursor:pointer; }.tm-shortcut:hover,.tm-shortcut:focus-visible { border-color:#74a9d9; box-shadow:0 0 0 2px #deedfc; }.tm-shortcut-icon { color:#447fc1; background:#eef5ff; }.tm-shortcut-icon--leave { color:#087a5d; background:#c9f1e4; }.tm-shortcut-icon--overtime { color:#245ed0; background:#d7e5ff; }.tm-shortcut-icon--timesheet { color:#b66a00; background:#ffebc8; }.tm-shortcut strong { font-size:12px; }.tm-shortcut > span:not(.tm-shortcut-icon) { min-height:25px; color:#778ba1; font-size:10px; }.tm-shortcut b { display:block; width:100%; padding:6px 8px; border-radius:4px; color:#fff; background:#078476; text-align:center; font-size:10px; }
.tm-workstation { scroll-margin-top:20px; }.tm-tabs { display:flex; gap:16px; border-bottom:1px solid #e0e7ef; }.tm-tabs button { padding:8px 3px; border:0; border-bottom:2px solid transparent; color:#657b92; background:transparent; font-size:10px; cursor:pointer; }.tm-tabs button.is-active { border-color:#078476; color:#08776d; font-weight:700; }
.tm-tabs + :deep(*) { margin-top:12px; }
 .tm-direct-form { padding-top:12px; }
.tm-leave-form { display:grid; gap:10px; }
.tm-form-grid { display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:9px; }
.tm-field { display:grid; gap:4px; min-width:0; }
.tm-field label { color:#536b84; font-size:10px; font-weight:600; }
.tm-field input,.tm-field select,.tm-field textarea,.tm-assignee :deep(select) { box-sizing:border-box; width:100%; min-height:31px; padding:6px 8px; border:1px solid #d8e2ee; border-radius:5px; color:#29445e; background:#fff; font:inherit; font-size:11px; }
.tm-field input[readonly] { background:#f7f9fc; }.tm-field textarea { min-height:45px; resize:vertical; }.tm-field input:focus,.tm-field select:focus,.tm-field textarea:focus,.tm-assignee :deep(select:focus) { outline:2px solid #b7d4fa; border-color:#4284f4; }
.tm-assignee :deep(.lv-field) { gap:4px; }.tm-assignee :deep(.lv-label) { color:#536b84; font-size:10px; font-weight:600; }.tm-assignee :deep(.lv-file) { display:none; }
.tm-form-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; padding-top:8px; border-top:1px solid #e7edf4; }.tm-form-footer > span { color:#73869b; font-size:9px; }.tm-form-footer > div { display:flex; gap:7px; }.tm-submit,.tm-secondary { min-height:28px; padding:6px 10px; border:1px solid #d5e0ec; border-radius:5px; color:#42617d; background:#fff; font-size:10px; cursor:pointer; }.tm-submit { border-color:#078476; color:#fff; background:#078476; }.tm-submit:disabled { opacity:.65; cursor:wait; }
.tm-success { display:flex; align-items:center; gap:10px; padding:13px; border:1px solid #cce8dd; border-radius:7px; background:#f1faf6; }.tm-success strong { color:#17644e; font-size:12px; }.tm-success p { margin:3px 0 0; color:#6a8279; font-size:10px; }.tm-success .tm-secondary { margin-left:auto; }
/* Keep the existing, fully validated forms compact inside the dashboard workstation. */
.tm-workstation :deep(.lv-head),.tm-workstation :deep(.ot-head) { display:none; }
.tm-workstation :deep(.lv-layout),.tm-workstation :deep(.ot-layout) { display:block; }
.tm-workstation :deep(.lv-form),.tm-workstation :deep(.ot-form) { gap:10px; padding:10px 2px 2px; border:0; box-shadow:none; }
.tm-workstation :deep(.lv-balance),.tm-workstation :deep(.ot-history),.tm-workstation :deep(.ot-approvals) { display:none; }
.tm-workstation :deep(.lv-duration) { padding:8px 10px; }
.tm-workstation :deep(.lv-grid) { gap:10px; }
.tm-workstation :deep(.lv-field),.tm-workstation :deep(.ot-field) { gap:4px; }
.tm-workstation :deep(.mt-head) { margin-bottom:8px; }
.tm-filters { display:flex; flex-wrap:wrap; gap:4px; }.tm-filters button { padding:4px 7px; border:1px solid #dce5ee; border-radius:4px; color:#687f95; background:#fff; font-size:9px; cursor:pointer; }.tm-filters button.is-active { color:#08786e; border-color:#a8d9cf; background:#edf9f5; }
.tm-table-wrap { overflow-x:auto; }table { width:100%; border-collapse:collapse; font-size:10px; }th { padding:7px; color:#6a8099; background:#f4f7fb; text-align:left; font-size:9px; font-weight:600; text-transform:uppercase; }td { padding:8px 7px; border-bottom:1px solid #edf1f5; color:#415c75; }.tm-empty { padding:18px; color:#8494a6; text-align:center; }.tm-status { padding:3px 7px; border-radius:99px; color:#8b6820; background:#fff5d8; font-size:9px; }.tm-status.is-approved { color:#08785c; background:#e2f5ed; }.tm-status.is-rejected { color:#ae3c43; background:#fdebed; }.tm-status.is-cancelled { color:#6d7d8e; background:#eef1f4; }.tm-notice { margin:0; padding:8px 10px; border:1px solid #f0d694; border-radius:6px; color:#765a1d; background:#fff9e9; font-size:11px; }
.tm-heading h1 { font-size:27px; }.tm-heading p,.tm-panel-heading p { font-size:12px; }.tm-panel-heading h2 { font-size:16px; }
.tm-metric small { font-size:12px; }.tm-metric strong { font-size:25px; }.tm-metric strong span { font-size:11px; }.tm-metric p { font-size:10px; }
.tm-shortcut strong { font-size:13px; }.tm-shortcut > span:not(.tm-shortcut-icon) { font-size:11px; }.tm-shortcut b { font-size:11px; padding:8px 10px; }
.tm-tabs button { font-size:11px; }.tm-field label,.tm-assignee :deep(.lv-label) { font-size:11px; }.tm-field input,.tm-field select,.tm-field textarea,.tm-assignee :deep(select) { min-height:36px; font-size:12px; }.tm-field textarea { min-height:56px; }
.tm-form-footer > span { font-size:10px; }.tm-submit,.tm-secondary { min-height:34px; font-size:11px; }.tm-filters button { min-height:28px; font-size:10px; }
table { font-size:11px; }th { font-size:9px; }.tm-status { font-size:9px; }
.tm button:focus-visible,.tm a:focus-visible { outline:3px solid #8ab4f8; outline-offset:2px; }
@media(max-width:700px) { .tm-metrics,.tm-shortcuts { grid-template-columns:1fr; }.tm-metric { min-height:auto; grid-template-columns:30px 1fr; align-items:center; }.tm-metric small,.tm-metric strong,.tm-metric p { grid-column:2; }.tm-icon { grid-row:1 / span 3; }.tm-panel { padding:11px; }.tm-panel-heading { align-items:flex-start; flex-wrap:wrap; }.tm-tabs { gap:9px; overflow:auto; }.tm-tabs button { flex:none; }.tm-filters { width:100%; }.tm-form-grid { grid-template-columns:1fr 1fr; }.tm-form-grid .tm-field:first-child { grid-column:1 / -1; }.tm-form-footer { align-items:flex-start; flex-direction:column; }.tm-form-footer > div { width:100%; }.tm-submit { flex:1; } }
</style>
