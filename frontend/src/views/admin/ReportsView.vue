<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import api from '../../services/api'
import { getCheckIns, getDashboard, getUsers } from '../../services/adminService'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const loading = ref(true)
const error = ref('')
const stats = ref({ users: 0, registrations: 0, check_ins: 0 })
const events = ref([])
const checkIns = ref([])
const selectedEvent = ref('all')
const attendees = ref([])
const formFields = ref([])
const loadingAttendeeValues = ref(false)

const filteredCheckIns = computed(() => selectedEvent.value === 'all'
  ? checkIns.value
  : checkIns.value.filter((item) => String(item.registration?.event_id) === String(selectedEvent.value)))
const filteredRegistrations = computed(() => selectedEvent.value === 'all'
  ? stats.value.registrations
  : events.value.find((event) => String(event.id) === String(selectedEvent.value))?.registrations_count || 0)
const checkedInCount = computed(() => filteredCheckIns.value.length)
const remainingCount = computed(() => Math.max(filteredRegistrations.value - checkedInCount.value, 0))
const attendanceRate = computed(() => filteredRegistrations.value ? Math.round((checkedInCount.value / filteredRegistrations.value) * 1000) / 10 : 0)
const eventRows = computed(() => events.value.map((event) => {
  const registrations = Number(event.registrations_count || event.registered || 0)
  const checkedIn = checkIns.value.filter((item) => String(item.registration?.event_id) === String(event.id)).length
  return { ...event, registrations, checkedIn, remaining: Math.max(registrations - checkedIn, 0), rate: registrations ? Math.round((checkedIn / registrations) * 1000) / 10 : 0 }
}))
const visibleEventRows = computed(() => selectedEvent.value === 'all'
  ? eventRows.value
  : eventRows.value.filter((event) => String(event.id) === String(selectedEvent.value)))

// --- Attendee submitted form values for the selected event ---
const normalizeKey = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '')
const formFieldOptions = computed(() => formFields.value.map((field) => ({ id: field.id, label: field.label, normalized: normalizeKey(field.label) })))
// Fields that at least one attendee actually answered (have content).
const usedFieldIds = computed(() => {
  const used = new Set()
  attendees.value.forEach((user) => (user.registrations || []).forEach((registration) => {
    const formData = registration.form_data || {}
    Object.entries(formData).forEach(([key, value]) => { if (value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && !value.length)) used.add(String(key)) })
  }))
  return used
})
const dynamicColumns = computed(() => formFieldOptions.value.filter((field) => usedFieldIds.value.has(String(field.id)) || usedFieldIds.value.has(field.normalized)))
const attendeeRows = computed(() => selectedEvent.value === 'all'
  ? []
  : attendees.value.flatMap((user) => (user.registrations || []).map((registration) => ({
      id: `${user.id}-${registration.id}`,
      name: registration.full_name || user.name || 'Unnamed attendee',
      email: registration.email || user.email || '',
      phone: registration.phone || user.phone || '',
      organization: registration.organization || '',
      checkedIn: Boolean(registration.check_in || registration.checked_in_at),
      registration,
    }))))
// Resolve a form field's submitted answer by field id or label (works for both
// id-keyed form_data and legacy label-keyed data), falling back to the
// backend-resolved form_values when available.
const formFieldAnswer = (registration, field) => {
  if (!registration || !field) return ''
  const formData = registration.form_data || {}
  if (formData[field.id] !== undefined && formData[field.id] !== null && formData[field.id] !== '') return formData[field.id]
  const key = Object.keys(formData).find((key) => normalizeKey(key) === field.normalized)
  if (key !== undefined && formData[key] !== '' && formData[key] !== null && formData[key] !== undefined) return formData[key]
  const values = registration.form_values || {}
  return values[field.label] ?? ''
}
const formatAnswer = (value) => {
  if (value === null || value === undefined) return '-'
  if (Array.isArray(value)) return value.length ? value.join(', ') : '-'
  const text = String(value).trim()
  return text === '' ? '-' : text
}
const formValueFor = (registration, field) => formatAnswer(formFieldAnswer(registration, field))

const loadAttendeeValues = async () => {
  const eventId = selectedEvent.value
  if (eventId === 'all') {
    attendees.value = []
    formFields.value = []
    return
  }
  loadingAttendeeValues.value = true
  try {
    const [usersResponse, formResponse] = await Promise.all([
      getUsers({ event_id: eventId, per_page: 10000 }),
      api.get(`/events/${eventId}/form`).catch(() => null),
    ])
    formFields.value = formResponse?.data?.data || []
    attendees.value = usersResponse.data.data || []
  } catch {
    formFields.value = []
    attendees.value = []
  } finally {
    loadingAttendeeValues.value = false
  }
}
watch(selectedEvent, loadAttendeeValues)

// Events load independently of the stats so the event dropdown still fills in
// even when the dashboard or check-in endpoints fail, mirroring EventsView.
const loadEvents = async () => {
  try {
    const eventsResponse = await api.get('/admin/events')
    events.value = eventsResponse.data.data || eventsResponse.data || []
  } catch {
    events.value = JSON.parse(localStorage.getItem('event_list') || '[]')
  }
}

const loadReport = async () => {
  loading.value = true
  error.value = ''
  loadEvents()
  try {
    const [dashboardResponse, checkInsResponse] = await Promise.all([
      getDashboard(),
      getCheckIns({ per_page: 10000 }),
    ])
    stats.value = dashboardResponse.data
    checkIns.value = checkInsResponse.data.data || []
  } catch (requestError) {
    const status = requestError.response?.status
    error.value = status === 401
      ? 'Your admin session has expired. Sign in again to view reports.'
      : status === 403
        ? 'Your account does not have permission to view reports.'
        : status
          ? `Unable to load report data (API ${status}).`
          : 'Unable to connect to the report service.'
  } finally {
    loading.value = false
  }
}

const exportReport = () => {
  const includeAttendees = selectedEvent.value !== 'all' && attendeeRows.value.length > 0
  const header = includeAttendees
    ? ['Attendee', 'Email', 'Phone', 'Organization', 'Registration status', 'Checked in', ...dynamicColumns.value.map((field) => field.label)]
    : ['Event', 'Registrations', 'Checked in', 'Remaining', 'Attendance rate']
  const rows = includeAttendees
    ? attendeeRows.value.map((row) => [
        row.name,
        row.email,
        row.phone,
        row.organization,
        row.registration.status || 'confirmed',
        row.checkedIn ? 'Yes' : 'No',
        ...dynamicColumns.value.map((field) => formValueFor(row.registration, field)),
      ])
    : visibleEventRows.value.map((row) => [row.name, row.registrations, row.checkedIn, row.remaining, `${row.rate}%`])
  const eventName = events.value.find((event) => String(event.id) === String(selectedEvent.value))?.name
  const csv = [header, ...rows].map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n')
  const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a')
  link.href = url
  link.download = includeAttendees
    ? `attendee-values-${eventName || selectedEvent.value}-${new Date().toISOString().slice(0, 10)}.csv`
    : `event-report-${new Date().toISOString().slice(0, 10)}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

const signInAgain = async () => {
  await auth.logout()
  router.push('/login')
}

onMounted(loadReport)
</script>

<template>
  <AdminLayout>
    <section class="reports-page">
      <header class="reports-heading">
        <div><p class="admin-eyebrow">Performance overview</p><h1>Reports</h1><p>Track registrations and attendance across your events.</p></div>
        <button class="reports-export" type="button" :disabled="loading || !visibleEventRows.length" @click="exportReport">Export report</button>
      </header>

      <p v-if="error" class="reports-error">{{ error }} <button v-if="error.includes('session')" type="button" @click="signInAgain">Sign in again</button></p>
      <div v-if="loading" class="reports-loading">Loading report data...</div>
      <template v-else>
        <div class="reports-toolbar"><label for="report-event">Event</label><select id="report-event" v-model="selectedEvent"><option value="all">All events</option><option v-for="event in events" :key="event.id" :value="String(event.id)">{{ event.name }}</option></select></div>
        <div class="report-metrics"><article><small>Total registrations</small><strong>{{ filteredRegistrations }}</strong><span>People registered</span></article><article><small>Checked in</small><strong>{{ checkedInCount }}</strong><span>Attendance confirmed</span></article><article><small>Remaining</small><strong>{{ remainingCount }}</strong><span>Not checked in</span></article><article><small>Attendance rate</small><strong>{{ attendanceRate }}%</strong><span>Of registrations</span></article></div>
        <section class="report-table-panel"><header><div><p class="admin-eyebrow">Event breakdown</p><h2>Attendance by event</h2></div><span>{{ visibleEventRows.length }} event{{ visibleEventRows.length === 1 ? '' : 's' }}</span></header><div v-if="visibleEventRows.length" class="report-table-wrap"><table><thead><tr><th>Event</th><th>Registrations</th><th>Checked in</th><th>Remaining</th><th>Rate</th></tr></thead><tbody><tr v-for="row in visibleEventRows" :key="row.id"><td><strong>{{ row.name }}</strong><small>{{ row.location || 'Location not set' }}</small></td><td>{{ row.registrations }}</td><td>{{ row.checkedIn }}</td><td>{{ row.remaining }}</td><td><b>{{ row.rate }}%</b><div class="report-progress"><span :style="{ width: `${row.rate}%` }"></span></div></td></tr></tbody></table></div><div v-else class="reports-empty">No event data available yet.</div></section>
        <section v-if="selectedEvent !== 'all'" class="report-table-panel"><header><div><p class="admin-eyebrow">Submitted form values</p><h2>Attendee form values</h2></div><span>{{ attendeeRows.length }} registration{{ attendeeRows.length === 1 ? '' : 's' }}</span></header><div v-if="loadingAttendeeValues" class="reports-loading">Loading attendee values...</div><div v-else-if="attendeeRows.length" class="report-table-wrap"><table><thead><tr><th>Attendee</th><th>Contact</th><th>Status</th><th>Checked in</th><th v-for="field in dynamicColumns" :key="`field-${field.id}`">{{ field.label }}</th></tr></thead><tbody><tr v-for="row in attendeeRows" :key="row.id"><td><strong>{{ row.name }}</strong><small>{{ row.organization || 'No organization' }}</small></td><td>{{ row.email || '—' }}<small>{{ row.phone || '—' }}</small></td><td>{{ row.registration.status || 'confirmed' }}</td><td>{{ row.checkedIn ? 'Checked in' : 'Not checked in' }}</td><td v-for="field in dynamicColumns" :key="`value-${field.id}`">{{ formValueFor(row.registration, field) }}</td></tr></tbody></table></div><div v-else class="reports-empty">No form values submitted for this event yet.</div></section>
      </template>
    </section>
  </AdminLayout>
</template>

<style scoped>
.reports-page { max-width: 1280px; margin: 0 auto; padding: 34px 0 70px; }
.reports-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin: 16px 0 28px; }
.reports-heading h1 { margin: 6px 0 8px; color: #142f56; font-size: clamp(2.25rem, 4vw, 3.4rem); letter-spacing: -.06em; line-height: 1; }
.reports-heading p:last-child { margin: 0; color: #71869e; }
.reports-export { min-height: 42px; padding: 0 18px; border: 1px solid #1f8f72; border-radius: 8px; background: #168365; box-shadow: 0 8px 18px rgba(22, 131, 101, .18); color: #fff; font-weight: 700; cursor: pointer; }
.reports-export:hover { background: #106b53; }
.reports-export:disabled { cursor: not-allowed; opacity: .5; }
.reports-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; color: #365783; font-size: .75rem; font-weight: 700; }
.reports-toolbar select { min-width: 240px; padding: 10px 12px; border: 1px solid #dce7f2; border-radius: 8px; background: #fff; color: #365783; }
.report-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 18px; }
.report-metrics article { padding: 19px; border: 1px solid #dce7f2; border-radius: 12px; background: #fff; box-shadow: 0 10px 30px rgba(28, 63, 100, .06); }
.report-metrics small, .report-metrics span { display: block; color: #8295aa; font-size: .68rem; }
.report-metrics strong { display: block; margin: 10px 0 4px; color: #173b67; font-size: 1.8rem; }
.report-table-panel { overflow: hidden; border: 1px solid #dce7f2; border-radius: 12px; background: #fff; box-shadow: 0 10px 30px rgba(28, 63, 100, .06); }
.report-table-panel > header { display: flex; align-items: center; justify-content: space-between; padding: 20px; border-bottom: 1px solid #edf2f6; }
.report-table-panel h2 { margin: 4px 0 0; color: #173b67; font-size: 1rem; }
.report-table-panel > header > span { color: #8295aa; font-size: .7rem; }
.report-table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 680px; }
th, td { padding: 15px 20px; border-bottom: 1px solid #edf2f6; color: #526e8d; font-size: .72rem; text-align: left; vertical-align: top; }
th { color: #8295aa; font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
td strong, td small { display: block; } td strong { color: #173b67; } td small { margin-top: 4px; color: #9aabbe; font-size: .64rem; }
.report-progress { width: 80px; height: 5px; margin-top: 7px; overflow: hidden; border-radius: 5px; background: #e8eff5; }.report-progress span { display: block; height: 100%; border-radius: inherit; background: #168365; }
.reports-error, .reports-empty, .reports-loading { padding: 15px; border-radius: 8px; background: #fff0f1; color: #b42318; font-size: .78rem; }.reports-loading, .reports-empty { background: #f7faff; color: #71869e; }
.reports-error button { margin-left: 8px; padding: 5px 9px; border: 1px solid #e3a4aa; border-radius: 6px; background: #fff; color: #b42318; font: inherit; font-weight: 700; cursor: pointer; }
@media (max-width: 800px) { .reports-heading { align-items: flex-start; flex-direction: column; }.reports-export { width: 100%; }.report-metrics { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 500px) { .report-metrics { grid-template-columns: 1fr; }.reports-toolbar { align-items: stretch; flex-direction: column; }.reports-toolbar select { width: 100%; } }
</style>
