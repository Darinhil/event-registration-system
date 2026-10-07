<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { getCheckIns, getDashboard, getUsers } from '../../services/adminService'

const loading = ref(true)
const error = ref('')
const attendeesLoading = ref(true)
const attendeesError = ref('')
const stats = ref({ users: 0, registrations: 0, check_ins: 0 })
const attendees = ref([])
const attendeeCheckInIds = ref(new Set())
const logCheckInIds = ref(new Set())
const checkedInUserIds = computed(() => new Set([...attendeeCheckInIds.value, ...logCheckInIds.value]))
const searchQuery = ref('')
const statusFilter = ref('all')
const checkInFilter = ref('all')
const openMenu = ref('')
const searchInput = ref(null)
let searchTimer = null
let attendeeRequestVersion = 0
const checkInRate = computed(() => stats.value.registrations ? Math.round((stats.value.check_ins / stats.value.registrations) * 1000) / 10 : 0)
const notCheckedIn = computed(() => Math.max(stats.value.registrations - stats.value.check_ins, 0))
const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '?'
const avatarHue = (name) => Array.from(String(name || '')).reduce((hash, char) => (hash * 31 + char.charCodeAt(0)) % 360, 7)
const relativeTime = (value) => {
  const date = value ? new Date(value) : null
  if (!date || Number.isNaN(date.getTime())) return 'Registered recently'
  const minutes = Math.round((Date.now() - date.getTime()) / 60000)
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.round(hours / 24)
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
const statusOptions = [
  { value: 'all', label: 'All Status' },
  { value: 'registered', label: 'Registered' },
  { value: 'pending', label: 'Pending' },
]
const checkInOptions = [
  { value: 'all', label: 'Check-in Status' },
  { value: 'in', label: 'Checked In' },
  { value: 'out', label: 'Not Checked In' },
]
const statusLabel = computed(() => statusOptions.find((option) => option.value === statusFilter.value)?.label || 'All Status')
const checkInLabel = computed(() => checkInOptions.find((option) => option.value === checkInFilter.value)?.label || 'Check-in Status')
const hasActiveFilters = computed(() => searchQuery.value.trim() !== '' || statusFilter.value !== 'all' || checkInFilter.value !== 'all')
const checkInTime = (attendee) => {
  const checked = (attendee.registrations || []).find((registration) => registration.check_in)
  return checked?.check_in?.checked_in_at || null
}

const getFilters = () => {
  const search = searchQuery.value.trim()
  const params = {}
  if (search) params.search = search
  if (statusFilter.value !== 'all') params.status = statusFilter.value
  if (checkInFilter.value !== 'all') params.check_in = checkInFilter.value
  return params
}

const loadMetrics = async () => {
  loading.value = true
  error.value = ''
  try {
    const dashboardResponse = await getDashboard()
    stats.value = dashboardResponse.data
  } catch {
    error.value = 'Unable to load dashboard metrics.'
  } finally {
    loading.value = false
  }
}

const loadAttendees = async () => {
  const requestVersion = ++attendeeRequestVersion
  attendeesLoading.value = true
  attendeesError.value = ''
  try {
    const usersResponse = await getUsers(getFilters())
    if (requestVersion !== attendeeRequestVersion) return
    attendees.value = usersResponse.data.data || []
    attendeeCheckInIds.value = new Set(
      attendees.value
        .filter((attendee) => attendee.registrations?.some((registration) => registration.check_in))
        .map((attendee) => attendee.id),
    )
  } catch {
    if (requestVersion !== attendeeRequestVersion) return
    attendees.value = []
    attendeeCheckInIds.value = new Set()
    attendeesError.value = 'Unable to load attendee data.'
  } finally {
    if (requestVersion === attendeeRequestVersion) attendeesLoading.value = false
  }
}

const loadCheckInLog = async () => {
  try {
    const checkInsResponse = await getCheckIns()
    logCheckInIds.value = new Set(
      (checkInsResponse.data.data || []).map((item) => item.registration?.user?.id).filter(Boolean),
    )
  } catch {
    logCheckInIds.value = new Set()
  }
}

const loadDashboard = () => {
  void loadMetrics()
  void loadAttendees()
  void loadCheckInLog()
}

const onSearchInput = () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadAttendees, 300)
}
const clearSearch = () => {
  searchQuery.value = ''
  clearTimeout(searchTimer)
  loadAttendees()
}
const clearAllFilters = () => {
  searchQuery.value = ''
  statusFilter.value = 'all'
  checkInFilter.value = 'all'
  clearTimeout(searchTimer)
  loadAttendees()
}
const toggleMenu = (menu) => { openMenu.value = openMenu.value === menu ? '' : menu }
const pickStatus = (value) => { statusFilter.value = value; openMenu.value = ''; loadAttendees() }
const pickCheckIn = (value) => { checkInFilter.value = value; openMenu.value = ''; loadAttendees() }
const onDocumentClick = (event) => { if (!event.target.closest('.toolbar-filter')) openMenu.value = '' }
const exportCsv = () => {
  const rows = attendees.value.map((attendee) => ({
    name: attendee.name,
    email: attendee.email,
    phone: attendee.phone || '',
    registration: attendee.registrations?.length ? 'Registered' : 'None',
    check_in: checkedInUserIds.value.has(attendee.id) ? 'Checked In' : 'Not Checked In',
  }))
  const header = 'Name,Email,Phone,Registration,Check-in'
  const csv = [header, ...rows.map((row) => [row.name, row.email, row.phone, row.registration, row.check_in].map((value) => `"${String(value).replace(/"/g, '""')}"`).join(','))].join('\n')
  const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a')
  link.href = url
  link.download = 'attendees.csv'
  link.click()
  URL.revokeObjectURL(url)
}
onMounted(() => { loadDashboard(); document.addEventListener('click', onDocumentClick); searchInput.value?.focus() })
onBeforeUnmount(() => { clearTimeout(searchTimer); document.removeEventListener('click', onDocumentClick) })
</script>

<template>
  <AdminLayout>
    <section class="event-dashboard">
      <header class="event-dashboard-heading"><div><h1>Event Dashboard</h1></div></header>
      <p v-if="error" class="dashboard-error" role="alert">{{ error }} <button type="button" @click="loadDashboard">Retry</button></p>
      <div v-if="loading && !error" class="metric-grid dashboard-metric-skeleton" role="status" aria-label="Loading dashboard metrics">
        <article v-for="index in 4" :key="index" class="metric-card"><span></span><div><i></i><b></b></div></article>
      </div>

      <div v-if="!loading && !error" class="metric-grid">
        <article class="metric-card metric-blue"><span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><div><small>Registered Attendees</small><strong>{{ stats.users.toLocaleString() }}</strong></div></article>
        <article class="metric-card metric-green"><span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg></span><div><small>Checked In</small><strong>{{ stats.check_ins.toLocaleString() }}</strong></div></article>
        <article class="metric-card metric-orange"><span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span><div><small>Not Checked In</small><strong>{{ notCheckedIn.toLocaleString() }}</strong></div></article>
        <article class="metric-card metric-purple"><span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span><div><small>Check-in Rate</small><strong>{{ checkInRate }}%</strong></div></article>
      </div>

      <div v-if="!loading && !error" class="dashboard-panels">
        <article class="dashboard-panel overview-panel"><header><h2>Registration overview</h2><div>Across your visible events</div></header><div class="dashboard-data-summary"><div class="dashboard-data-counts"><div class="dashboard-data-count"><span>Unique attendees</span><strong>{{ stats.users.toLocaleString() }}</strong><small>People with at least one registration</small></div><div class="dashboard-data-count"><span>Registration records</span><strong>{{ stats.registrations.toLocaleString() }}</strong><small>Places reserved across events</small></div></div><RouterLink class="dashboard-data-link" to="/admin/users">View attendee records <span aria-hidden="true">→</span></RouterLink></div></article>
        <article class="dashboard-panel status-panel"><header><h2>Registration Status</h2></header><div class="donut-wrap"><div class="donut" :style="{ '--rate': `${checkInRate}%` }"><div><strong>{{ stats.registrations }}</strong><span>Total</span></div></div><div class="donut-legend"><p><span class="legend-green-dot"></span><b>Checked In</b><strong>{{ stats.check_ins }} ({{ checkInRate }}%)</strong></p><p><span class="legend-orange-dot"></span><b>Not Checked In</b><strong>{{ notCheckedIn }} ({{ Math.max(100 - checkInRate, 0) }}%)</strong></p></div></div></article>
        <article class="dashboard-panel activity-panel"><header><h2>Recent Activity</h2><RouterLink to="/admin/users">View all</RouterLink></header><div v-if="attendeesLoading" class="activity-empty" role="status">Loading recent activity…</div><div v-else-if="attendees.length" class="activity-list"><div v-for="attendee in attendees.slice(0, 5)" :key="attendee.id"><span :class="checkedInUserIds.has(attendee.id) ? 'activity-check' : 'activity-avatar'" :style="{ '--avatar-hue': avatarHue(attendee.name) }"><svg v-if="checkedInUserIds.has(attendee.id)" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><template v-else>{{ initials(attendee.name) }}</template></span><p><strong>{{ attendee.name }}</strong><small>{{ checkedInUserIds.has(attendee.id) ? `Checked in${checkInTime(attendee) ? ` · ${relativeTime(checkInTime(attendee))}` : ''}` : 'Registered' }} · {{ relativeTime(attendee.created_at) }}</small></p></div></div><div v-else class="activity-empty"><span class="activity-empty-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg></span><strong>{{ attendeesError ? 'Activity unavailable' : 'Nothing here yet' }}</strong><p>{{ attendeesError || 'Registrations and check-ins will appear here as they happen.' }}</p></div></article>
      </div>

      <article class="attendee-panel"><header><h2><span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>Registered Attendees<span class="panel-count">{{ attendees.length }}</span></h2><RouterLink to="/admin/users">View all →</RouterLink></header><p v-if="attendeesError" class="inline-error" role="alert">{{ attendeesError }} <button type="button" @click="loadAttendees">Retry</button></p><div class="attendee-toolbar"><label class="table-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg><input ref="searchInput" v-model="searchQuery" type="search" placeholder="Search by name, email, phone, or organization..." aria-label="Search attendees" @input="onSearchInput" @keydown.esc.prevent="clearSearch" /></label><div class="toolbar-filter" :class="{ 'is-open': openMenu === 'status', 'is-active': statusFilter !== 'all' }"><button type="button" :aria-expanded="openMenu === 'status'" aria-haspopup="true" @click="toggleMenu('status')"><svg class="lead-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg><span>{{ statusLabel }}</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg></button><div v-if="openMenu === 'status'" class="filter-menu"><button v-for="option in statusOptions" :key="option.value" type="button" :class="{ selected: statusFilter === option.value }" @click="pickStatus(option.value)">{{ option.label }}</button></div></div><div class="toolbar-filter" :class="{ 'is-open': openMenu === 'checkin', 'is-active': checkInFilter !== 'all' }"><button type="button" :aria-expanded="openMenu === 'checkin'" aria-haspopup="true" @click="toggleMenu('checkin')"><svg class="lead-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg><span>{{ checkInLabel }}</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg></button><div v-if="openMenu === 'checkin'" class="filter-menu"><button v-for="option in checkInOptions" :key="option.value" :value="option.value" type="button" :class="{ selected: checkInFilter === option.value }" @click="pickCheckIn(option.value)">{{ option.label }}</button></div></div><button type="button" class="toolbar-export" :disabled="attendeesLoading || !attendees.length" @click="exportCsv">Export<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0 4-4m-4 4-4-4M5 20h14" /></svg></button><button v-if="hasActiveFilters" type="button" class="toolbar-clear" @click="clearAllFilters">Clear ✕</button></div><div class="attendee-table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Registration</th><th>Check-in</th><th>Actions</th></tr></thead><tbody><tr v-if="attendeesLoading"><td colspan="5">Loading attendees…</td></tr><tr v-else-if="attendeesError"><td colspan="5">Attendee list is unavailable.</td></tr><tr v-else-if="!attendees.length"><td colspan="5">{{ searchQuery.trim() ? `No attendees found for “${searchQuery.trim()}”.` : 'No attendees registered yet.' }}</td></tr><tr v-for="attendee in attendees.slice(0, 6)" v-else :key="attendee.id"><td><div class="attendee-name-cell"><span class="table-avatar" :style="{ '--avatar-hue': avatarHue(attendee.name) }">{{ initials(attendee.name) }}</span><strong>{{ attendee.name }}</strong></div></td><td>{{ attendee.email }}</td><td><span class="status-pill registered">Registered</span></td><td><span class="status-pill" :class="checkedInUserIds.has(attendee.id) ? 'checked' : 'pending'">{{ checkedInUserIds.has(attendee.id) ? `Checked In${checkInTime(attendee) ? ` · ${relativeTime(checkInTime(attendee))}` : ''}` : 'Not Checked In' }}</span></td><td><RouterLink :to="`/admin/users/${attendee.id}`">View</RouterLink></td></tr></tbody></table></div><footer v-if="!attendeesLoading && attendees.length" class="attendee-footer"><span>Showing <b>{{ Math.min(attendees.length, 6) }}</b> of <b>{{ attendees.length }}</b> attendees</span><RouterLink to="/admin/users">View all attendees →</RouterLink></footer></article>
    </section>
  </AdminLayout>
</template>
