<script setup>
import { computed, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import PersonnelProfileForm from '../../components/personnel/PersonnelProfileForm.vue'
import ComplianceCenter from '../../components/personnel/ComplianceCenter.vue'
import ContractHistory from '../../components/personnel/ContractHistory.vue'
import TrainingCenter from '../../components/personnel/TrainingCenter.vue'
import { useAuthStore } from '../../stores/auth'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const auth = useAuthStore()
const router = useRouter()
const leaveType = ref('Annual')
const leaveStartDate = ref('')
const leaveEndDate = ref('')
const leaveReason = ref('')
const leaveAttachment = ref(null)
const leaveSubmitted = ref(false)
const leaveDays = computed(() => {
  if (!leaveStartDate.value || !leaveEndDate.value) return ''
  const start = new Date(`${leaveStartDate.value}T00:00:00`)
  const end = new Date(`${leaveEndDate.value}T00:00:00`)
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) return ''
  return Math.floor((end - start) / 86400000) + 1
})
const leaveBalances = [
  { type: 'Annual', entitled: 15, used: 3, remaining: 12 },
  { type: 'Sick', entitled: 10, used: 5, remaining: 5 },
  { type: 'Other', entitled: 5, used: 2, remaining: 3 },
]
const overtimeDate = ref('')
const overtimeSupervisor = ref('')
const overtimeStartTime = ref('')
const overtimeEndTime = ref('')
const overtimeReason = ref('')
const overtimeSubmitted = ref(false)
const overtimeHours = computed(() => {
  if (!overtimeStartTime.value || !overtimeEndTime.value) return ''
  const [startHour, startMinute] = overtimeStartTime.value.split(':').map(Number)
  const [endHour, endMinute] = overtimeEndTime.value.split(':').map(Number)
  let minutes = endHour * 60 + endMinute - (startHour * 60 + startMinute)
  if (minutes < 0) minutes += 24 * 60
  return (minutes / 60).toFixed(2).replace(/\.00$/, '')
})
const timesheetMonth = ref(new Date().getMonth() + 1)
const timesheetYear = ref(new Date().getFullYear())
const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const timesheetDays = computed(() => {
  const count = new Date(Number(timesheetYear.value), Number(timesheetMonth.value), 0).getDate()
  return Array.from({ length: count }, (_, index) => {
    const date = new Date(Number(timesheetYear.value), Number(timesheetMonth.value) - 1, index + 1)
    return { key: `${timesheetYear.value}-${String(timesheetMonth.value).padStart(2, '0')}-${String(index + 1).padStart(2, '0')}`, number: index + 1, weekday: date.toLocaleDateString('en-US', { weekday: 'short' }), weekend: date.getDay() === 0 || date.getDay() === 6 }
  })
})
const timesheetHours = ref({})
const timesheetLeave = ref({})
const timesheetTotal = computed(() => Object.entries(timesheetHours.value).reduce((total, [key, value]) => timesheetLeave.value[key] || !value ? total : total + (Number(value) || 0), 0))
const timesheetLeaveCount = computed(() => Object.values(timesheetLeave.value).filter(Boolean).length)
const timesheetSubmitted = ref(false)
const travelPurpose = ref('')
const travelDestination = ref('')
const travelAccommodation = ref('')
const travelStartDate = ref('')
const travelEndDate = ref('')
const travelTransport = ref('Car')
const travelCosts = ref({ transport: '', accommodation: '', meals: '', other: '' })
const travelAttachment = ref(null)
const travelSubmitted = ref(false)
const travelTotalCost = computed(() => Object.values(travelCosts.value).reduce((sum, value) => sum + (Number(value) || 0), 0))
const fuelVehicle = ref('Car-001')
const fuelMonth = ref(new Date().getMonth() + 1)
const fuelYear = ref(new Date().getFullYear())
const fuelRecords = ref([{ date: '', mileage: '', liters: '', cost: '' }])
const fuelSubmitted = ref(false)
const fuelTotalLiters = computed(() => fuelRecords.value.reduce((sum, record) => sum + (Number(record.liters) || 0), 0))
const fuelTotalCost = computed(() => fuelRecords.value.reduce((sum, record) => sum + (Number(record.cost) || 0), 0))
const addFuelRecord = () => { fuelRecords.value.push({ date: '', mileage: '', liters: '', cost: '' }); fuelSubmitted.value = false }
const purchasePurpose = ref('')
const purchaseDepartment = ref('')
const purchaseRequiredDate = ref('')
const purchaseItems = ref([{ description: '', quantity: 1, unitCost: 0 }])
const purchaseJustification = ref('')
const purchaseAttachment = ref(null)
const purchaseSubmitted = ref(false)
const purchaseTotal = computed(() => purchaseItems.value.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unitCost) || 0), 0))
const addPurchaseItem = () => { purchaseItems.value.push({ description: '', quantity: 1, unitCost: 0 }); purchaseSubmitted.value = false }
const requestTypeFilter = ref('all')
const requestStatusFilter = ref('all')
const selectedRequest = ref(null)
const myRequests = ref([{ id: 1, type: 'Leave', submitted: '08 Oct 2026', status: 'Pending', section: 'leave-application', details: 'Leave application submitted for approval.' }])
const filteredMyRequests = computed(() => myRequests.value.filter((request) => (requestTypeFilter.value === 'all' || request.type === requestTypeFilter.value) && (requestStatusFilter.value === 'all' || request.status.toLowerCase() === requestStatusFilter.value)))
const editRequest = (request) => { selectedRequest.value = null; router.push({ query: { ...route.query, section: request.section } }) }
const timesheetLeaveRecords = [
  { type: 'Annual', allowance: '15', balance: '12' },
  { type: 'Sick Leave', allowance: '—', balance: '—' },
  { type: 'Special', allowance: '—', balance: '—' },
  { type: 'Compassionate', allowance: '—', balance: '—' },
  { type: 'Time in Lieu', allowance: '—', balance: '—' },
  { type: 'Paternity', allowance: '—', balance: '—' },
  { type: 'Unpaid', allowance: '—', balance: '—' },
  { type: 'Study', allowance: '—', balance: '—' },
]
const section = computed(() => typeof route.query.section === 'string' ? route.query.section : 'my-profile')
const sectionLabels = {
  compliances: 'Compliances', 'contract-history': 'Contract History', training: 'Staff Online Training',
  'time-management': 'Time Management', 'leave-application': 'Leave Application',
  'overtime-request': 'Overtime Request', 'monthly-timesheet': 'Monthly Timesheet', 'other-requests': 'Other Requests',
  'travel-application': 'Travel Application', 'fuel-log-sheet': 'Fuel Log-Sheet', 'purchase-request': 'Purchase Request',
  'finance-management': 'Finance Management', 'my-requests': 'My Requests',
}
const heading = computed(() => sectionLabels[section.value] || 'Personnel Profile')
const submitLeaveRequest = () => { leaveSubmitted.value = true }
const submitOvertimeRequest = () => { overtimeSubmitted.value = true }
const cancelTimesheet = () => { timesheetHours.value = {}; timesheetLeave.value = {}; timesheetSubmitted.value = false }
const cancelTravelApplication = () => {
  travelPurpose.value = ''; travelDestination.value = ''; travelAccommodation.value = ''
  travelStartDate.value = ''; travelEndDate.value = ''; travelTransport.value = 'Car'
  travelCosts.value = { transport: '', accommodation: '', meals: '', other: '' }
  travelAttachment.value = null; travelSubmitted.value = false
}
const submitFuelLog = () => { fuelSubmitted.value = true }
const cancelPurchaseRequest = () => {
  purchasePurpose.value = ''; purchaseDepartment.value = ''; purchaseRequiredDate.value = ''
  purchaseItems.value = [{ description: '', quantity: 1, unitCost: 0 }]
  purchaseJustification.value = ''; purchaseAttachment.value = null; purchaseSubmitted.value = false
}
</script>

<template>
  <AdminLayout>
    <div class="profile-workspace" :class="{ 'is-profile-form': section === 'my-profile' || !sectionLabels[section] }">
      <div v-if="sectionLabels[section] && !['compliances', 'contract-history', 'training', 'time-management', 'leave-application', 'overtime-request', 'monthly-timesheet', 'other-requests', 'travel-application', 'fuel-log-sheet', 'purchase-request', 'my-requests'].includes(section)" class="profile-page-top"><div><h1>{{ heading }}</h1><p>{{ '' }}</p></div></div>

      <section v-if="section === 'my-requests'" class="my-requests-page">
        <header class="my-requests-heading"><div><h2>My Requests</h2><p>All your submissions with their approval workflow status.</p></div><div class="my-requests-filters"><label><span class="sr-only">Filter by type</span><select v-model="requestTypeFilter"><option value="all">All types</option><option v-for="type in [...new Set(myRequests.map((request) => request.type))]" :key="type" :value="type">{{ type }}</option></select></label><label><span class="sr-only">Filter by status</span><select v-model="requestStatusFilter"><option value="all">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></label></div></header>
        <section class="my-requests-card"><div class="my-requests-table-wrap"><table class="my-requests-table"><thead><tr><th>#</th><th>Type</th><th>Submitted</th><th>Status</th><th aria-label="Actions"></th></tr></thead><tbody><tr v-for="request in filteredMyRequests" :key="request.id"><td>{{ request.id }}</td><td><strong>{{ request.type }}</strong></td><td>{{ request.submitted }}</td><td><span class="request-status" :class="request.status.toLowerCase()">{{ request.status }}</span></td><td class="request-actions"><button type="button" @click="selectedRequest = request">View</button><button type="button" class="edit-request" @click="editRequest(request)">Edit</button></td></tr><tr v-if="filteredMyRequests.length === 0"><td colspan="5" class="my-requests-empty">No requests match these filters.</td></tr></tbody></table></div></section>
        <div v-if="selectedRequest" class="request-detail-backdrop" @click.self="selectedRequest = null"><section class="request-detail" role="dialog" aria-modal="true" :aria-label="`${selectedRequest.type} request details`"><button class="request-detail-close" type="button" aria-label="Close" @click="selectedRequest = null">×</button><h3>{{ selectedRequest.type }} Request</h3><p><strong>Submitted:</strong> {{ selectedRequest.submitted }}</p><p><strong>Status:</strong> {{ selectedRequest.status }}</p><p>{{ selectedRequest.details }}</p><button class="edit-request" type="button" @click="editRequest(selectedRequest)">Edit Request</button></section></div>
      </section>

      <PersonnelProfileForm v-if="section === 'my-profile' || !sectionLabels[section]" />
      <ComplianceCenter v-else-if="section === 'compliances'" />
      <ContractHistory v-else-if="section === 'contract-history'" />
      <TrainingCenter v-else-if="section === 'training'" />
      <section v-else-if="section === 'time-management' || section === 'leave-application'" class="leave-page">
        <div class="leave-page-heading"><h2>Leave Application</h2><p>Submit a leave request — routed to your supervisor, then to HR for approval.</p></div>
        <div class="leave-content-grid">
          <form class="leave-card leave-request-card" @submit.prevent="submitLeaveRequest">
            <h3>New Request</h3>
            <label class="leave-field">Leave Type <b>*</b><select v-model="leaveType" @change="leaveSubmitted = false"><option>Annual</option><option>Sick</option><option>Other</option></select></label>
            <div class="leave-date-grid">
              <label class="leave-field">Start Date <b>*</b><input v-model="leaveStartDate" type="date" @change="leaveSubmitted = false" /></label>
              <label class="leave-field">End Date <b>*</b><input v-model="leaveEndDate" type="date" :min="leaveStartDate || undefined" @change="leaveSubmitted = false" /></label>
            </div>
            <label class="leave-field">Number of Days<input :value="leaveDays" placeholder="—" readonly /><small>Automatically calculated from the dates.</small></label>
            <label class="leave-field">Reason<textarea v-model="leaveReason" rows="3" placeholder="Brief reason for leave..." @input="leaveSubmitted = false"></textarea></label>
            <label class="leave-field">Attachment (optional — PDF/image, max 5MB)<span class="leave-file-control"><input type="file" accept=".pdf,image/*" @change="leaveAttachment = $event.target.files?.[0] || null; leaveSubmitted = false" /></span></label>
            <p v-if="leaveAttachment && leaveAttachment.size > 5 * 1024 * 1024" class="leave-error" role="alert">The attachment must be 5MB or smaller.</p>
            <div class="leave-submit-row"><span v-if="leaveSubmitted" class="leave-success" role="status">Request submitted successfully.</span><button type="submit">Submit Request</button></div>
          </form>
          <section class="leave-card leave-balance-card" aria-labelledby="leave-balance-title">
            <h3 id="leave-balance-title">Current Leave Balance</h3>
            <div class="leave-table-wrap"><table class="leave-balance-table"><thead><tr><th>Type</th><th>Entitled</th><th>Used</th><th>Remaining</th></tr></thead><tbody><tr v-for="balance in leaveBalances" :key="balance.type"><td>{{ balance.type }}</td><td>{{ balance.entitled }}</td><td>{{ balance.used }}</td><td><strong>{{ balance.remaining }}</strong></td></tr></tbody></table></div>
          </section>
        </div>
      </section>
      <section v-else-if="section === 'overtime-request'" class="overtime-page">
        <div class="overtime-page-heading"><h2>Overtime Request</h2><p>Total hours are calculated automatically.</p></div>
        <form class="overtime-card" @submit.prevent="submitOvertimeRequest">
          <div class="overtime-form-grid">
            <label class="overtime-field">Date <b>*</b><input v-model="overtimeDate" type="date" @input="overtimeSubmitted = false" /></label>
            <label class="overtime-field">Supervisor<input v-model="overtimeSupervisor" type="text" placeholder="Supervisor name" @input="overtimeSubmitted = false" /></label>
            <label class="overtime-field">Start Time <b>*</b><input v-model="overtimeStartTime" type="time" @input="overtimeSubmitted = false" /></label>
            <label class="overtime-field">End Time <b>*</b><input v-model="overtimeEndTime" type="time" @input="overtimeSubmitted = false" /></label>
            <label class="overtime-field overtime-hours-field">Total Hours<input :value="overtimeHours" placeholder="—" readonly /><small>Automatically calculated.</small></label>
            <label class="overtime-field overtime-reason-field">Reason<textarea v-model="overtimeReason" rows="3" @input="overtimeSubmitted = false"></textarea></label>
          </div>
          <div class="overtime-submit-row"><span v-if="overtimeSubmitted" class="leave-success" role="status">Request submitted successfully.</span><button type="submit">Submit Request</button></div>
        </form>
      </section>
      <section v-else-if="section === 'monthly-timesheet'" class="timesheet-page">
        <div class="timesheet-toolbar"><div class="timesheet-page-heading"><h2>Monthly Timesheet</h2><p>Enter your net working hours per day (already excluding the 12:00–13:30 unpaid break).</p></div><div class="timesheet-period"><label><span class="sr-only">Month</span><select v-model="timesheetMonth" @change="timesheetSubmitted = false"><option v-for="(month, index) in monthNames" :key="month" :value="index + 1">{{ month }}</option></select></label><label><span class="sr-only">Year</span><input v-model.number="timesheetYear" type="number" min="2000" max="2100" aria-label="Year" @change="timesheetSubmitted = false" /></label></div></div>
        <section class="timesheet-card timesheet-entry-card">
          <div class="timesheet-employee-grid"><div><small>EMPLOYEE ID</small><strong>ST-00123</strong></div><div><small>FULL NAME</small><strong>{{ auth.user?.name || 'Borey Penh' }}</strong></div><div><small>POSITION</small><strong>Program Officer</strong></div><div><small>DUTY STATION</small><strong>Program</strong></div><div><small>MONTH</small><strong>{{ monthNames[Number(timesheetMonth) - 1] }} {{ timesheetYear }}</strong></div><div><small>PERIOD</small><strong>01–{{ String(timesheetMonth).padStart(2, '0') }}–{{ timesheetYear }} — {{ String(new Date(Number(timesheetYear), Number(timesheetMonth), 0).getDate()).padStart(2, '0') }}–{{ String(timesheetMonth).padStart(2, '0') }}–{{ timesheetYear }}</strong></div></div>
          <div class="timesheet-legend"><span class="weekend-key">Weekend</span><span class="holiday-key">Public holiday</span><span class="leave-key">Leave day</span><small>Non-working days need no hours. Working on a holiday/weekend requires an Overtime request.</small></div>
          <div class="timesheet-calendar-scroll"><table class="timesheet-calendar"><thead><tr><th class="timesheet-row-label">{{ monthNames[Number(timesheetMonth) - 1] }}<small>{{ timesheetYear }}</small></th><th v-for="day in timesheetDays" :key="day.key" :class="{ 'is-weekend': day.weekend }"><small>{{ day.weekday }}</small><strong>{{ day.number }}</strong></th></tr></thead><tbody><tr><th class="timesheet-row-label"><span class="hours-key">HOURS</span></th><td v-for="day in timesheetDays" :key="day.key" :class="{ 'is-weekend': day.weekend }"><input v-if="!day.weekend && !timesheetLeave[day.key]" v-model.number="timesheetHours[day.key]" type="number" min="0" max="24" step="0.25" :aria-label="`Working hours for ${day.weekday} ${day.number}`" @input="timesheetSubmitted = false" /><span v-else class="timesheet-dash">—</span></td></tr><tr><th class="timesheet-row-label"><span class="leave-row-key">LEAVE</span></th><td v-for="day in timesheetDays" :key="day.key" :class="{ 'is-weekend': day.weekend }"><select v-model="timesheetLeave[day.key]" :aria-label="`Leave type for ${day.weekday} ${day.number}`" @change="timesheetSubmitted = false"><option value="">—</option><option>Annual</option><option>Sick</option><option>Special</option><option>Other</option></select></td></tr></tbody></table></div>
          <div class="timesheet-total"><span>Total Working Hours <small>({{ timesheetLeaveCount }} leave day(s) marked)</small></span><strong>{{ timesheetTotal.toFixed(2) }} h</strong></div>
        </section>
        <section class="timesheet-card timesheet-leave-card"><h3>Leaves Record — {{ monthNames[Number(timesheetMonth) - 1] }} {{ timesheetYear }}</h3><div class="timesheet-leave-table-wrap"><table class="timesheet-leave-table"><thead><tr><th>TYPE OF LEAVE</th><th>ALLOWANCE</th><th>USED (THIS MONTH)</th><th>BALANCE</th></tr></thead><tbody><tr v-for="record in timesheetLeaveRecords" :key="record.type"><td>{{ record.type }}</td><td>{{ record.allowance }}</td><td>{{ record.type === 'Annual' ? Object.values(timesheetLeave).filter((leave) => leave === 'Annual').length : Object.values(timesheetLeave).filter((leave) => leave === record.type.replace(' Leave', '')).length }}</td><td>{{ record.type === 'Annual' ? Math.max(0, 12 - Object.values(timesheetLeave).filter((leave) => leave === 'Annual').length) : record.balance }}</td></tr></tbody></table></div><div class="timesheet-actions"><span v-if="timesheetSubmitted" class="leave-success" role="status">Timesheet submitted successfully.</span><small>Submitted by: <strong>{{ auth.user?.name || 'Borey Penh' }}</strong> — approval follows the normal workflow.</small><button class="timesheet-cancel" type="button" @click="cancelTimesheet">Cancel</button><button class="timesheet-submit" type="button" @click="timesheetSubmitted = true">✓ Submit Timesheet</button></div></section>
      </section>
      <section v-else-if="section === 'other-requests' || section === 'travel-application'" class="travel-page">
        <form class="travel-card" @submit.prevent="travelSubmitted = true">
          <h2>Travel Information</h2>
          <div class="travel-form-grid">
            <label class="travel-field travel-wide">Purpose of Travel <b>*</b><textarea v-model="travelPurpose" rows="2" @input="travelSubmitted = false"></textarea></label>
            <label class="travel-field">Destination <b>*</b><input v-model="travelDestination" type="text" @input="travelSubmitted = false" /></label>
            <label class="travel-field">Accommodation<input v-model="travelAccommodation" type="text" placeholder="Hotel / guesthouse" @input="travelSubmitted = false" /></label>
            <label class="travel-field">Start Date <b>*</b><input v-model="travelStartDate" type="date" @input="travelSubmitted = false" /></label>
            <label class="travel-field">End Date <b>*</b><input v-model="travelEndDate" type="date" :min="travelStartDate || undefined" @input="travelSubmitted = false" /></label>
            <fieldset class="travel-transport travel-wide"><legend>Transportation <b>*</b></legend><label v-for="option in ['Car', 'Motorbike', 'Bus', 'Airplane', 'Other']" :key="option" :class="{ selected: travelTransport === option }"><input v-model="travelTransport" type="radio" name="travel-transport" :value="option" @change="travelSubmitted = false" /><span>{{ option }}</span></label></fieldset>
            <div class="travel-section-heading travel-wide">Estimated Cost</div>
            <label v-for="item in [{ id: 'transport', label: 'Transport' }, { id: 'accommodation', label: 'Accommodation' }, { id: 'meals', label: 'Meals' }, { id: 'other', label: 'Other' }]" :key="item.id" class="travel-field">{{ item.label }}<span class="travel-currency-field"><span>$</span><input v-model.number="travelCosts[item.id]" type="number" min="0" step="0.01" placeholder="0.00" @input="travelSubmitted = false" /></span></label>
            <div class="travel-total travel-wide"><span>Total Estimated Cost</span><strong>${{ travelTotalCost.toFixed(2) }}</strong></div>
            <label class="travel-field travel-wide">Attachment (optional — PDF/image, max 5MB)<span class="travel-file-control"><input type="file" accept=".pdf,image/*" @change="travelAttachment = $event.target.files?.[0] || null; travelSubmitted = false" /></span></label>
          </div>
          <div class="travel-actions"><span v-if="travelSubmitted" class="leave-success" role="status">Application submitted successfully.</span><small>Dates and costs are estimates — finance reconciles after the trip.</small><button class="travel-cancel" type="button" @click="cancelTravelApplication">Cancel</button><button class="travel-submit" type="submit">✓ Submit Application</button></div>
        </form>
      </section>
      <section v-else-if="section === 'fuel-log-sheet'" class="fuel-page">
        <div class="fuel-page-heading"><h2>Fuel Log-Sheet</h2><p>Record fuel usage per vehicle for a month and submit.</p></div>
        <form class="fuel-card" @submit.prevent="submitFuelLog">
          <div class="fuel-filters"><label>Vehicle <b>*</b><select v-model="fuelVehicle" @change="fuelSubmitted = false"><option>Car-001</option><option>Car-002</option><option>Motorbike-001</option></select></label><label>Month <b>*</b><span class="fuel-month-controls"><select v-model="fuelMonth" @change="fuelSubmitted = false"><option v-for="(month, index) in monthNames" :key="month" :value="index + 1">{{ month }}</option></select><input v-model.number="fuelYear" type="number" min="2000" max="2100" aria-label="Year" @change="fuelSubmitted = false" /></span></label></div>
          <div class="fuel-record-table-wrap"><table class="fuel-record-table"><thead><tr><th>Date</th><th>Mileage (km)</th><th>Liters</th><th>Cost $</th></tr></thead><tbody><tr v-for="(record, index) in fuelRecords" :key="index"><td><input v-model="record.date" type="date" :aria-label="`Fuel record ${index + 1} date`" @input="fuelSubmitted = false" /></td><td><input v-model.number="record.mileage" type="number" min="0" step="1" :aria-label="`Fuel record ${index + 1} mileage`" @input="fuelSubmitted = false" /></td><td><input v-model.number="record.liters" type="number" min="0" step="0.01" :aria-label="`Fuel record ${index + 1} liters`" @input="fuelSubmitted = false" /></td><td><input v-model.number="record.cost" type="number" min="0" step="0.01" :aria-label="`Fuel record ${index + 1} cost`" @input="fuelSubmitted = false" /></td></tr></tbody></table></div>
          <button class="fuel-add-record" type="button" @click="addFuelRecord">+ Add Record</button>
          <div class="fuel-actions"><div class="fuel-totals"><span>Total Fuel: <strong>{{ fuelTotalLiters.toFixed(2).replace(/\.00$/, '') }} L</strong></span><span>Total Cost: <strong>${{ fuelTotalCost.toFixed(2).replace(/\.00$/, '') }}</strong></span></div><span v-if="fuelSubmitted" class="leave-success" role="status">Log-sheet submitted successfully.</span><button type="submit" class="fuel-submit">Submit Log-Sheet</button></div>
        </form>
      </section>
      <section v-else class="other-section"><h2>{{ heading }}</h2><p>{{ heading }} information will appear here.</p></section>
    </div>
  </AdminLayout>
</template>

<style scoped>
.profile-workspace{width:min(100%,1180px);margin:0 auto;padding:12px 0 42px;color:#172b4d;font-size:12px}.profile-workspace.is-profile-form{padding:0}.profile-page-top{margin-bottom:14px}.profile-page-top h1{margin:0 0 4px;font-size:17px;font-weight:700;letter-spacing:-.2px}.profile-page-top p{margin:0;color:#8291a5;font-size:11px}.other-section{padding:28px;border:1px solid #e2e8f0;border-radius:9px;background:#fff}.other-section h2{margin:0 0 8px}.other-section p{color:#64748b}
@media(max-width:700px){.profile-workspace:not(.is-profile-form){width:100%;padding:14px 16px 16px}}
.leave-page{max-width:1120px;padding:12px 0 40px}.leave-page-heading{margin-bottom:14px}.leave-page-heading h2{margin:0 0 4px;color:#172b4d;font-size:18px;font-weight:700}.leave-page-heading p{margin:0;color:#8291a5;font-size:11px}.leave-content-grid{display:grid;grid-template-columns:repeat(2,minmax(0,380px));align-items:stretch;gap:12px}.leave-card{min-width:0;padding:16px;border:1px solid #e4eaf0;border-radius:12px;background:#fff;box-shadow:0 4px 16px rgba(22,48,78,.035)}.leave-card h3{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #edf1f4;color:#213650;font-size:12px;font-weight:700}.leave-request-card{display:flex;flex-direction:column;gap:10px}.leave-field{display:grid;gap:5px;color:#34475c;font-size:10px;font-weight:650}.leave-field>b{display:inline;color:#d14d4d}.leave-field input,.leave-field select,.leave-field textarea{box-sizing:border-box;width:100%;min-height:36px;padding:0 10px;border:1px solid #dce4ec;border-radius:7px;background:#fff;color:#34475c;font:inherit;font-size:10px;font-weight:400}.leave-field input:focus,.leave-field select:focus,.leave-field textarea:focus{border-color:#59a99d;outline:0;box-shadow:0 0 0 3px rgba(8,121,109,.1)}.leave-field input[readonly]{background:#fbfcfd}.leave-field textarea{min-height:56px;padding:9px 10px;resize:vertical}.leave-field input::placeholder,.leave-field textarea::placeholder{color:#97a6b7}.leave-field small{color:#9aa7b6;font-size:9px;font-weight:400}.leave-date-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.leave-file-control{display:flex;align-items:center;min-height:42px;padding:5px 8px;border:1px dashed #d7e0e8;border-radius:8px;background:#fafbfd}.leave-file-control input{min-height:28px;padding:3px 5px;font-size:9px}.leave-submit-row{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:0}.leave-submit-row button{min-height:36px;padding:0 15px;border:1px solid #08796d;border-radius:7px;background:#08796d;color:#fff;font:inherit;font-size:10px;font-weight:700;cursor:pointer}.leave-submit-row button:hover:not(:disabled){background:#06685e}.leave-submit-row button:disabled{opacity:.55;cursor:not-allowed}.leave-success{margin-right:auto;color:#16834a;font-size:9px}.leave-error{margin:0;color:#bd3434;font-size:9px}.leave-balance-card{min-height:300px}.leave-table-wrap{overflow-x:auto}.leave-balance-table{width:100%;border-collapse:collapse;text-align:left;font-size:9px}.leave-balance-table th{padding:9px 8px;background:#f8fafc;color:#8391a1;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.leave-balance-table td{padding:10px 8px;border-top:1px solid #edf0f4;color:#34475c}.leave-balance-table th:not(:first-child),.leave-balance-table td:not(:first-child){text-align:right}.leave-balance-table td:first-child{color:#172b4d}.leave-balance-table td strong{color:#16834a;font-weight:700}
@media(max-width:850px){.leave-content-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.leave-page{padding-top:10px}.leave-content-grid{grid-template-columns:1fr}.leave-balance-card{min-height:0}}
.overtime-page{max-width:1120px;padding:12px 0 40px}.overtime-page-heading{margin-bottom:18px}.overtime-page-heading h2{margin:0 0 5px;color:#172b4d;font-size:21px;font-weight:750;letter-spacing:-.3px}.overtime-page-heading p{margin:0;color:#8291a5;font-size:13px}.overtime-card{width:min(100%,640px);box-sizing:border-box;padding:22px 24px 20px;border:1px solid #e1e8ef;border-radius:14px;background:#fff;box-shadow:0 3px 10px rgba(22,48,78,.035)}.overtime-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 16px}.overtime-field{display:grid;align-content:start;gap:7px;color:#172b4d;font-size:13px;font-weight:650}.overtime-field>b{display:inline;color:#d14d4d}.overtime-field input,.overtime-field textarea{box-sizing:border-box;width:100%;min-height:44px;padding:0 12px;border:1px solid #dce4ec;border-radius:10px;background:#fff;color:#34475c;font:inherit;font-size:13px;font-weight:400}.overtime-field input::placeholder{color:#9aa8ba}.overtime-field input:focus,.overtime-field textarea:focus{border-color:#59a99d;outline:0;box-shadow:0 0 0 3px rgba(8,121,109,.1)}.overtime-field input[readonly]{background:#fff}.overtime-field small{margin-top:0;color:#9aa7b6;font-size:12px;font-weight:400}.overtime-field textarea{min-height:82px;padding:10px 12px;resize:vertical}.overtime-hours-field,.overtime-reason-field{grid-column:1/-1}.overtime-submit-row{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:20px}.overtime-submit-row button{min-height:44px;padding:0 21px;border:1px solid #08796d;border-radius:10px;background:#08796d;color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer}.overtime-submit-row button:hover{background:#06685e}
@media(max-width:600px){.overtime-page{padding-top:10px}.overtime-page-heading h2{font-size:19px}.overtime-card{padding:17px}.overtime-form-grid{grid-template-columns:1fr;gap:14px}.overtime-hours-field,.overtime-reason-field{grid-column:auto}}
.timesheet-page{width:min(100%,718px);padding:8px 0 38px}.timesheet-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:10px}.timesheet-page-heading h2{margin:0 0 3px;color:#172b4d;font-size:15px;font-weight:750}.timesheet-page-heading p{margin:0;color:#8291a5;font-size:9px}.timesheet-period{display:flex;gap:7px;flex:none}.timesheet-period select,.timesheet-period input{box-sizing:border-box;height:29px;padding:0 9px;border:1px solid #dce4ec;border-radius:6px;background:#fff;color:#34475c;font:inherit;font-size:9px}.timesheet-period select{width:84px}.timesheet-period input{width:62px}.timesheet-card{padding:15px;border:1px solid #e1e8ef;border-radius:10px;background:#fff;box-shadow:0 3px 10px rgba(22,48,78,.03)}.timesheet-employee-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px 14px;padding:11px;border:1px solid #e3e9ef;border-radius:7px;background:#fcfdfe}.timesheet-employee-grid>div{display:grid;gap:3px;min-width:0}.timesheet-employee-grid small{color:#8b99a9;font-size:7px;font-weight:750;letter-spacing:.04em}.timesheet-employee-grid strong{overflow:hidden;color:#233850;font-size:9px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.timesheet-legend{display:flex;align-items:center;flex-wrap:wrap;gap:5px;margin:9px 0 8px}.timesheet-legend>span{padding:3px 7px;border-radius:99px;font-size:7px;font-weight:700}.weekend-key{background:#f0f3f7;color:#66778a}.holiday-key{background:#fff1c7;color:#a76a00}.leave-key{background:#dfebff;color:#3068b4}.timesheet-legend small{color:#8997a8;font-size:7px}.timesheet-calendar-scroll{overflow-x:auto;border:1px solid #e4e9ef;border-radius:6px;scrollbar-color:#99a6b4 #edf1f4;scrollbar-width:thin}.timesheet-calendar{width:max-content;min-width:100%;border-collapse:collapse;table-layout:fixed;text-align:center}.timesheet-calendar th,.timesheet-calendar td{width:68px;min-width:68px;height:27px;padding:3px 4px;border-right:1px solid #e9edf2;border-bottom:1px solid #e9edf2}.timesheet-calendar thead th{height:35px;background:#fbfcfd;color:#233850}.timesheet-calendar th small{display:block;color:#8b99a9;font-size:7px;font-weight:600}.timesheet-calendar th strong{display:block;margin-top:1px;font-size:9px}.timesheet-calendar th.is-weekend,.timesheet-calendar td.is-weekend{background:#f7f8fa}.timesheet-calendar .timesheet-row-label{position:sticky;left:0;z-index:1;width:56px;min-width:56px;background:#fff;text-align:left}.timesheet-calendar thead .timesheet-row-label{z-index:2;background:#fbfcfd;font-size:8px}.timesheet-calendar .timesheet-row-label small{margin-top:2px}.timesheet-calendar .hours-key,.timesheet-calendar .leave-row-key{display:inline-block;padding:3px 5px;border-radius:4px;font-size:7px;font-weight:800}.hours-key{background:#e4f6f0;color:#07846c}.leave-row-key{background:#deebff;color:#3470c5}.timesheet-calendar td input{width:100%;height:22px;padding:0;border:0;background:transparent;color:#34475c;text-align:center;font:inherit;font-size:8px}.timesheet-calendar td select{width:100%;height:22px;padding:0;border:0;background:transparent;color:#66778a;font:inherit;font-size:8px}.timesheet-calendar td input:focus,.timesheet-calendar td select:focus{outline:1px solid #70b9a9;outline-offset:-1px}.timesheet-dash{color:#9aa7b6;font-size:8px}.timesheet-total{display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding:9px 11px;border-radius:6px;background:#e6f6f2;color:#34475c;font-size:9px}.timesheet-total small{color:#8b99a9;font-size:7px}.timesheet-total strong{color:#07846c;font-size:10px}.timesheet-leave-card{margin-top:10px}.timesheet-leave-card h3{margin:0 0 8px;padding-bottom:8px;border-bottom:1px solid #edf1f4;color:#263b53;font-size:9px;font-weight:700}.timesheet-leave-table-wrap{overflow-x:auto}.timesheet-leave-table{width:100%;border-collapse:collapse;text-align:left;font-size:8px}.timesheet-leave-table th{padding:7px 8px;background:#f8fafc;color:#8795a5;font-size:7px;font-weight:750;white-space:nowrap}.timesheet-leave-table td{padding:7px 8px;border-bottom:1px solid #edf0f4;color:#465a70}.timesheet-leave-table tr:last-child td{border-bottom:0}.timesheet-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-top:10px}.timesheet-actions>small{margin-right:auto;color:#98a4b2;font-size:7px}.timesheet-actions .timesheet-cancel,.timesheet-actions .timesheet-submit{min-height:29px;padding:0 10px;border:1px solid #dce4ec;border-radius:6px;background:#fff;color:#34475c;font:inherit;font-size:8px;font-weight:700;cursor:pointer}.timesheet-actions .timesheet-submit{border-color:#08796d;background:#08796d;color:#fff}.timesheet-actions .leave-success{font-size:8px}
@media(max-width:600px){.timesheet-page{padding-top:10px}.timesheet-toolbar{align-items:flex-start;flex-direction:column}.timesheet-period{align-self:flex-end}.timesheet-employee-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.timesheet-card{padding:10px}.timesheet-actions{flex-wrap:wrap}.timesheet-actions>small{flex-basis:100%;margin:0 0 4px}}
.travel-page{width:min(100%,800px);padding:0 0 38px}.travel-card{padding:18px 20px 16px;border:1px solid #e1e8ef;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(22,48,78,.035)}.travel-card>h2{margin:0 0 14px;padding-bottom:11px;border-bottom:1px solid #edf1f4;color:#213650;font-size:13px;font-weight:700}.travel-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px 12px}.travel-field{display:grid;align-content:start;gap:6px;min-width:0;color:#172b4d;font-size:10px;font-weight:650}.travel-field>b,.travel-transport legend b{color:#d14d4d}.travel-wide{grid-column:1/-1}.travel-field>input,.travel-field>textarea,.travel-currency-field{box-sizing:border-box;width:100%;min-height:36px;padding:0 10px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:10px;font-weight:400}.travel-field>textarea{min-height:52px;padding:9px 10px;resize:vertical}.travel-field>input:focus,.travel-field>textarea:focus,.travel-currency-field:focus-within{border-color:#59a99d;outline:0;box-shadow:0 0 0 3px rgba(8,121,109,.1)}.travel-field input::placeholder{color:#9aa8ba}.travel-transport{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin:0;padding:0;border:0}.travel-transport legend{margin-bottom:7px;color:#172b4d;font-size:10px;font-weight:650}.travel-transport>label{display:flex;align-items:center;justify-content:center;gap:6px;min-height:35px;border:1px solid #dce4ec;border-radius:99px;background:#fff;color:#8291a5;font-size:9px;cursor:pointer}.travel-transport>label.selected{border-color:#168f80;background:#e6f6f2;color:#08796d;font-weight:700}.travel-transport input{width:12px;height:12px;margin:0;accent-color:#08796d}.travel-section-heading{padding:3px 0 9px;border-bottom:1px solid #edf1f4;color:#213650;font-size:13px;font-weight:700}.travel-currency-field{display:flex;align-items:center;gap:8px}.travel-currency-field>span{color:#8291a5}.travel-currency-field>input{width:100%;height:32px;padding:0;border:0;outline:0;background:transparent;color:#34475c;font:inherit;font-size:10px}.travel-total{display:flex;align-items:center;justify-content:space-between;min-height:40px;padding:0 12px;border-radius:7px;background:#e6f6f2;color:#34475c;font-size:10px}.travel-total strong{color:#08796d;font-size:12px}.travel-file-control{display:flex;align-items:center;min-height:42px;padding:5px 9px;border:1px dashed #d7e0e8;border-radius:8px;background:#fafbfd}.travel-file-control input{width:100%;min-height:28px;padding:3px 5px;border:1px solid #e3e8ed;border-radius:6px;background:#fff;font-size:9px}.travel-actions{display:flex;align-items:center;gap:7px;margin-top:12px}.travel-actions>small{margin-right:auto;color:#98a4b2;font-size:8px}.travel-actions .travel-cancel,.travel-actions .travel-submit{min-height:36px;padding:0 13px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.travel-actions .travel-submit{border-color:#08796d;background:#08796d;color:#fff}.travel-actions .leave-success{font-size:8px}
@media(max-width:600px){.travel-page{padding-top:10px}.travel-card{padding:15px}.travel-form-grid{grid-template-columns:1fr}.travel-wide{grid-column:auto}.travel-transport{grid-template-columns:repeat(2,minmax(0,1fr))}.travel-transport legend{grid-column:1/-1}.travel-actions{flex-wrap:wrap}.travel-actions>small{flex-basis:100%;margin:0 0 4px}}
.fuel-page{width:min(100%,956px);padding:8px 0 40px}.fuel-page-heading{margin-bottom:15px}.fuel-page-heading h2{margin:0 0 4px;color:#172b4d;font-size:17px;font-weight:750}.fuel-page-heading p{margin:0;color:#8291a5;font-size:10px}.fuel-card{padding:18px 20px 16px;border:1px solid #e1e8ef;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(22,48,78,.035)}.fuel-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:13px}.fuel-filters>label{display:grid;gap:6px;color:#172b4d;font-size:10px;font-weight:650}.fuel-filters b{color:#d14d4d}.fuel-filters select,.fuel-month-controls input{box-sizing:border-box;width:100%;height:36px;padding:0 11px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:10px;font-weight:400}.fuel-month-controls{display:grid;grid-template-columns:minmax(0,1fr) 82px;gap:7px}.fuel-record-table-wrap{overflow-x:auto}.fuel-record-table{width:100%;border-collapse:collapse;table-layout:fixed;text-align:left}.fuel-record-table th{height:32px;padding:0 11px;background:#f8fafc;color:#8795a5;font-size:8px;font-weight:750;letter-spacing:.03em;text-transform:uppercase}.fuel-record-table td{padding:8px 11px}.fuel-record-table td input{box-sizing:border-box;width:100%;height:36px;padding:0 10px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:10px}.fuel-record-table td input:focus,.fuel-filters select:focus,.fuel-month-controls input:focus{border-color:#59a99d;outline:0;box-shadow:0 0 0 3px rgba(8,121,109,.1)}.fuel-add-record{width:100%;min-height:36px;margin-top:5px;border:1px dashed #cbd6e1;border-radius:7px;background:#fff;color:#40576e;font:inherit;font-size:9px;cursor:pointer}.fuel-add-record:hover{border-color:#168f80;color:#08796d}.fuel-actions{display:flex;align-items:center;gap:10px;margin-top:10px}.fuel-totals{display:flex;gap:18px;color:#34475c;font-size:10px}.fuel-totals strong{color:#172b4d;font-weight:750}.fuel-actions .leave-success{margin-left:auto;font-size:9px}.fuel-submit{min-height:36px;margin-left:auto;padding:0 15px;border:1px solid #08796d;border-radius:8px;background:#08796d;color:#fff;font:inherit;font-size:10px;font-weight:700;cursor:pointer}.fuel-actions .leave-success+.fuel-submit{margin-left:0}
@media(max-width:600px){.fuel-page{padding-top:10px}.fuel-card{padding:14px}.fuel-filters{grid-template-columns:1fr}.fuel-record-table{min-width:660px}.fuel-actions{align-items:flex-start;flex-wrap:wrap}.fuel-totals{flex-basis:100%;justify-content:space-between}.fuel-submit{margin-left:auto}.fuel-actions .leave-success{margin-left:0}}
.purchase-page{width:min(100%,800px);padding:8px 0 40px}.purchase-page-heading{margin-bottom:15px}.purchase-page-heading h2{margin:0 0 4px;color:#172b4d;font-size:17px;font-weight:750}.purchase-page-heading p{margin:0;color:#8291a5;font-size:10px}.purchase-card{padding:18px 20px 16px;border:1px solid #e1e8ef;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(22,48,78,.035)}.purchase-card h3{margin:0 0 13px;padding-bottom:10px;border-bottom:1px solid #edf1f4;color:#213650;font-size:12px;font-weight:700}.purchase-field{display:grid;gap:6px;color:#172b4d;font-size:10px;font-weight:650}.purchase-field>b{color:#d14d4d}.purchase-field textarea,.purchase-field>input{box-sizing:border-box;width:100%;min-height:36px;padding:0 10px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:10px;font-weight:400}.purchase-field textarea{min-height:52px;padding:9px 10px;resize:vertical}.purchase-field textarea:focus,.purchase-field>input:focus,.purchase-items-table input:focus{border-color:#59a99d;outline:0;box-shadow:0 0 0 3px rgba(8,121,109,.1)}.purchase-wide{grid-column:1/-1}.purchase-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:13px}.purchase-items-heading{margin:20px 0 12px!important;padding-bottom:9px!important}.purchase-items-wrap{overflow-x:auto}.purchase-items-table{width:100%;border-collapse:collapse;table-layout:fixed;text-align:left}.purchase-items-table th{height:32px;padding:0 10px;background:#f8fafc;color:#8795a5;font-size:8px;font-weight:750;text-transform:uppercase}.purchase-items-table th:first-child{width:56%}.purchase-items-table th:nth-child(2){width:10%}.purchase-items-table th:nth-child(3){width:18%}.purchase-items-table th:last-child{width:16%}.purchase-items-table td{padding:8px 10px;color:#34475c;font-size:9px}.purchase-items-table td:last-child{white-space:nowrap}.purchase-items-table input{box-sizing:border-box;width:100%;height:36px;padding:0 10px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:10px}.purchase-items-table td:nth-child(2) input{min-width:45px}.purchase-add-item{width:100%;min-height:36px;margin-top:3px;border:1px dashed #cbd6e1;border-radius:7px;background:#fff;color:#40576e;font:inherit;font-size:9px;cursor:pointer}.purchase-add-item:hover{border-color:#168f80;color:#08796d}.purchase-total{display:flex;align-items:center;justify-content:space-between;min-height:41px;margin-top:10px;padding:0 12px;border-radius:7px;background:#e6f6f2;color:#34475c;font-size:10px}.purchase-total strong{color:#08796d;font-size:12px}.purchase-justification{margin:12px 0}.purchase-file-control{display:flex;align-items:center;min-height:42px;padding:5px 9px;border:1px dashed #d7e0e8;border-radius:8px;background:#fafbfd}.purchase-file-control input{width:100%;min-height:28px;padding:3px 5px;border:1px solid #e3e8ed;border-radius:6px;background:#fff;font:inherit;font-size:9px}.purchase-actions{display:flex;align-items:center;gap:7px;margin-top:12px}.purchase-actions>small{margin-right:auto;color:#98a4b2;font-size:8px}.purchase-actions .purchase-cancel,.purchase-actions .purchase-submit{min-height:36px;padding:0 13px;border:1px solid #dce4ec;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.purchase-actions .purchase-submit{border-color:#08796d;background:#08796d;color:#fff}.purchase-actions .leave-success{font-size:8px}
@media(max-width:600px){.purchase-page{padding-top:10px}.purchase-card{padding:14px}.purchase-form-grid{grid-template-columns:1fr}.purchase-actions{flex-wrap:wrap}.purchase-actions>small{flex-basis:100%;margin:0 0 4px}.purchase-items-table{min-width:590px}}
.my-requests-page{width:min(100%,1148px);padding:10px 0 40px}.my-requests-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 17px}.my-requests-heading h2{margin:0 0 5px;color:#172b4d;font-size:21px;font-weight:750}.my-requests-heading p{margin:0;color:#8291a5;font-size:13px}.my-requests-filters{display:flex;gap:9px}.my-requests-filters select{min-width:122px;height:44px;padding:0 14px;border:1px solid #dce4ec;border-radius:10px;background:#fff;color:#172b4d;font:inherit;font-size:13px}.my-requests-card{padding:20px 24px;border:1px solid #e1e8ef;border-radius:14px;background:#fff;box-shadow:0 3px 10px rgba(22,48,78,.035)}.my-requests-table-wrap{overflow-x:auto;border:1px solid #e1e8ef;border-radius:12px}.my-requests-table{width:100%;border-collapse:collapse;text-align:left}.my-requests-table th{height:40px;padding:0 14px;background:#f8fafc;color:#60738a;font-size:11px;font-weight:750;text-transform:uppercase}.my-requests-table td{height:56px;padding:0 14px;border-top:1px solid #e7edf2;color:#172b4d;font-size:13px}.my-requests-table td:nth-child(2){font-weight:650}.request-status{display:inline-flex;padding:5px 12px;border-radius:99px;background:#fff3c4;color:#a96400;font-size:11px;font-weight:700}.request-status.approved{background:#e4f5e9;color:#18834a}.request-status.rejected{background:#fde8e8;color:#bd3737}.request-actions{white-space:nowrap}.request-actions button,.request-detail button:not(.request-detail-close){min-height:35px;margin-right:7px;padding:0 14px;border:1px solid #dce4ec;border-radius:9px;background:#fff;color:#172b4d;font:inherit;font-size:12px;font-weight:650;cursor:pointer}.request-actions .edit-request,.request-detail .edit-request{border-color:#08796d!important;background:#08796d!important;color:#fff!important}.my-requests-empty{text-align:center;color:#8291a5!important}.request-detail-backdrop{position:fixed;z-index:60;inset:0;display:grid;place-items:center;padding:20px;background:#11243b77}.request-detail{position:relative;width:min(100%,440px);padding:26px;border:1px solid #e2e8ef;border-radius:14px;background:#fff;box-shadow:0 24px 80px #10243d40}.request-detail h3{margin:0 0 16px;color:#172b4d;font-size:18px}.request-detail p{color:#53677c;font-size:13px;line-height:1.6}.request-detail-close{position:absolute;top:10px;right:12px;border:0;background:transparent;color:#77889a;font-size:24px;cursor:pointer}@media(max-width:650px){.my-requests-page{padding-top:8px}.my-requests-heading{align-items:flex-start;flex-direction:column}.my-requests-filters{width:100%}.my-requests-filters label{flex:1}.my-requests-filters select{width:100%;min-width:0}.my-requests-card{padding:12px}.my-requests-table{min-width:650px}}
</style>


