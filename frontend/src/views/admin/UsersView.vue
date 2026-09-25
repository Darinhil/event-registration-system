<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import AdminLayout from '../../layouts/AdminLayout.vue'
import api from '../../services/api'
import { getUsers } from '../../services/adminService'

const loading = ref(true)
const error = ref('')
const events = ref([])
const attendees = ref([])
const selectedEventId = ref(null)
const activeTab = ref('attendees')
const search = ref('')
const status = ref('all')
const eventFilter = ref('')
const eventScope = ref('all')
const ageFilter = ref('all')
const genderFilter = ref('all')
const ethnicityFilter = ref('all')
const disabilityFilter = ref('all')
const router = useRouter()

const ageOptions = [
  { value: 'under-18', label: 'K - Under 18' },
  { value: '18-29', label: 'Y - 18-29' },
  { value: '30-60', label: 'A - 30-60' },
  { value: 'above-60', label: 'E - Above 60' },
]
const genderOptions = ['M', 'F', 'Non-binary', 'Prefer not to say']
const ethnicityOptions = ['Ethnic minority', 'Indigenous people', 'Khmer']
const disabilityOptions = [
  { value: 'C', label: 'Difficult' },
  { value: 'H', label: 'Difficult Hearing' },
  { value: 'M', label: 'Difficult moving' },
  { value: 'R', label: 'Difficult Remember' },
  { value: 'S', label: 'Difficult with Self Care' },
  { value: 'X', label: 'Difficult communication' },
]

const normalizeKey = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '')

// Form fields defined for the selected event, so submitted answers can be resolved.
const formFields = ref([])
const formFieldOptions = computed(() => formFields.value.map((field) => ({ id: field.id, label: field.label, normalized: normalizeKey(field.label), type: String(field.type || 'text').toLowerCase() })))
// Fields that at least one attendee actually answered (have content).
const usedFieldIds = computed(() => {
  const used = new Set()
  attendees.value.forEach((user) => (user.registrations || []).forEach((registration) => {
    const formData = registration.form_data || {}
    Object.entries(formData).forEach(([key, value]) => { if (value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && !value.length)) used.add(String(key)) })
  }))
  return used
})
// Dynamic columns: submitted form fields that are not already shown as fixed columns.
const dynamicColumns = computed(() => {
  const fixed = new Set(['fullname', 'name', 'email', 'phone', 'gender', 'age', 'agegroup', 'ethnicity', 'race', 'disability', 'disabilitytype', 'organization', 'address', 'position', 'profilephoto', 'emergencycontactname', 'emergencycontactphone', 'signature', 'photoconsent', 'event', 'eventname', 'registrationcode'])
  return formFieldOptions.value.filter((field) => (usedFieldIds.value.has(String(field.id)) || usedFieldIds.value.has(field.normalized)) && !fixed.has(field.normalized))
})

const normalizeEvent = (event) => ({
  ...event,
  status: event.status === 'published' ? 'open' : event.status,
  registered: event.registrations_count ?? event.registered ?? 0,
  capacity: event.capacity ?? event.maximum_participants,
  start_date: event.start_date || event.starts_at?.slice(0, 10),
  start_time: event.start_time || event.starts_at?.slice(11, 16),
  end_time: event.end_time || event.ends_at?.slice(11, 16),
})

const selectedEvent = computed(() => events.value.find((event) => event.id === selectedEventId.value) || events.value[0] || null)
const visibleEvents = computed(() => events.value.filter((event) => {
  const matchesSearch = !eventFilter.value || event.name.toLowerCase().includes(eventFilter.value.toLowerCase())
  const matchesScope = eventScope.value === 'all' || (eventScope.value === 'upcoming' && event.status === 'open') || (eventScope.value === 'ongoing' && event.status === 'open') || (eventScope.value === 'past' && event.status === 'closed')
  return matchesSearch && matchesScope
}))
const upcomingEventCount = computed(() => events.value.filter((event) => event.status === 'open').length)
const ongoingEventCount = computed(() => events.value.filter((event) => event.status === 'open').length ? 1 : 0)
const pastEventCount = computed(() => events.value.filter((event) => event.status === 'closed').length)
const registrationFor = (user) => user.registrations?.[0] || {}
const isCheckedIn = (registration) => Boolean(registration?.checked_in_at || registration?.check_in)
const checkedInCount = computed(() => attendees.value.filter((user) => user.registrations?.some(isCheckedIn)).length)
const eventAttendeeCount = computed(() => selectedEvent.value?.registered || attendees.value.length)
const pendingCount = computed(() => Math.max(eventAttendeeCount.value - checkedInCount.value, 0))
const activeFilterCount = computed(() => [ageFilter.value, genderFilter.value, ethnicityFilter.value, disabilityFilter.value, status.value].filter((value) => value !== 'all').length)
// Resolve a form field's submitted answer by field id or label (works for both
// id-keyed form_data from the dynamic builder and legacy label-keyed data),
// falling back to the backend-resolved form_values when available.
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
const pickAnswer = (registration, candidates, fallback) => {
  for (const candidate of candidates) {
    const field = formFieldOptions.value.find((option) => option.normalized === normalizeKey(candidate))
    const answer = formFieldAnswer(registration, field)
    if (answer !== '' && answer !== null && answer !== undefined) return answer
  }
  return fallback ?? ''
}
const ageBucket = (registration) => {
  const age = Number(registration.age)
  const group = String(registration.age_group || pickAnswer(registration, ['age group', 'age'], '')).toLowerCase()
  if (group.includes('under') || group.includes('18')) return group.includes('under') ? 'under-18' : '18-29'
  if (group.includes('30')) return '30-60'
  if (group.includes('above') || group.includes('60')) return 'above-60'
  if (Number.isFinite(age)) return age < 18 ? 'under-18' : age < 30 ? '18-29' : age <= 60 ? '30-60' : 'above-60'
  return ''
}
const normalizedGender = (registration) => String(registration.gender || pickAnswer(registration, ['gender'], '')).toLowerCase()
const normalizedEthnicity = (registration) => String(registration.ethnicity || pickAnswer(registration, ['ethnicity', 'race'], '')).toLowerCase()
const displayAge = (registration) => registration.age ?? (ageOptions.find((option) => option.value === ageBucket(registration))?.label || formatAnswer(pickAnswer(registration, ['age'], '')) || '-')
const displayEthnicity = (registration) => registration.ethnicity || formatAnswer(pickAnswer(registration, ['ethnicity', 'race'], '')) || '-'
const displayDisability = (registration) => disabilityValues(registration).join(', ') || '-'
const displayCheckedTime = (user) => {
  const registration = user.registrations?.find(isCheckedIn)
  const checkedInAt = registration?.checked_in_at || registration?.check_in?.checked_in_at
  return checkedInAt ? new Date(checkedInAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'
}
const disabilityValues = (registration) => {
  const value = registration.disability_type || pickAnswer(registration, ['disability', 'disability type'], '')
  if (Array.isArray(value)) return value.map(String)
  return String(value).split(/[,|]/).map((item) => item.trim()).filter(Boolean)
}
const filteredAttendees = computed(() => attendees.value.filter((user) => {
  const query = search.value.trim().toLowerCase()
  const matchesSearch = !query || `${user.name} ${user.email} ${user.phone || ''}`.toLowerCase().includes(query)
  const registered = Boolean(user.registrations?.length)
  const matchesStatus = status.value === 'all' || (status.value === 'registered' && registered) || (status.value === 'pending' && !registered)
  const checkedIn = user.registrations?.some((registration) => registration.check_in)
  const matchesTab = activeTab.value === 'attendees' || (activeTab.value === 'checked-in' && checkedIn) || (activeTab.value === 'not-checked-in' && !checkedIn)
  const registrations = user.registrations || []
  const matchesAge = ageFilter.value === 'all' || registrations.some((registration) => ageBucket(registration) === ageFilter.value)
  const matchesGender = genderFilter.value === 'all' || registrations.some((registration) => normalizedGender(registration) === genderFilter.value.toLowerCase())
  const matchesEthnicity = ethnicityFilter.value === 'all' || registrations.some((registration) => normalizedEthnicity(registration).includes(ethnicityFilter.value.toLowerCase()))
  const matchesDisability = disabilityFilter.value === 'all' || registrations.some((registration) => disabilityValues(registration).some((value) => value.toLowerCase() === disabilityFilter.value.toLowerCase() || value.toLowerCase().startsWith(disabilityFilter.value.toLowerCase())))
  return matchesSearch && matchesStatus && matchesTab && matchesAge && matchesGender && matchesEthnicity && matchesDisability
}))

const loadWorkspace = async () => {
  loading.value = true
  error.value = ''
  const localEvents = JSON.parse(localStorage.getItem('event_list') || '[]').map(normalizeEvent)
  try {
    const eventsResponse = await api.get('/admin/events')
    events.value = (eventsResponse.data.data || eventsResponse.data).map(normalizeEvent)
  } catch {
    events.value = localEvents
  }
  selectedEventId.value = events.value[0]?.id || null
  await loadAttendees()
  loading.value = false
}

// Load attendees for the selected event together with that event's form fields,
// so the submitted form answers can be resolved into the attendance table.
const loadAttendees = async () => {
  error.value = ''
  formFields.value = []
  const eventId = selectedEventId.value
  try {
    if (eventId) {
      const [usersResponse, formResponse] = await Promise.all([
        getUsers({ event_id: eventId, per_page: 200 }),
        api.get(`/events/${eventId}/form`).catch(() => null),
      ])
      formFields.value = formResponse?.data?.data || []
      attendees.value = usersResponse.data.data || []
    } else {
      const usersResponse = await getUsers({ per_page: 200 })
      attendees.value = usersResponse.data.data || []
    }
  } catch {
    attendees.value = []
    error.value = events.value.length ? 'Attendee data is unavailable. Showing event details.' : 'Unable to load attendance data.'
  }
}

const selectEvent = async (event) => {
  if (event.id === selectedEventId.value) return
  selectedEventId.value = event.id
  await loadAttendees()
}

const dateLabel = (event) => event?.start_date ? new Date(`${event.start_date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : 'Date pending'
const timeLabel = (event) => event?.start_time ? `${event.start_time} - ${event.end_time || '04:00'} PM` : 'Time pending'
const eventImage = (event) => event?.branding?.image || event?.image || ''
const initials = (name) => (name || 'U').split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase()
const selectEventScope = (scope) => { eventScope.value = scope }
const openCheckIn = () => router.push('/admin/check-ins')
const clearFilters = () => {
  search.value = ''
  status.value = 'all'
  ageFilter.value = 'all'
  genderFilter.value = 'all'
  ethnicityFilter.value = 'all'
  disabilityFilter.value = 'all'
}
const exportFilteredExcel = () => {
  const baseHeaders = ['#', 'Full Name', 'Gender', 'Age', 'Ethnicity', 'Disability', 'Checked-time', 'Status']
  const dynamicHeaders = dynamicColumns.value.map((field) => field.label)
  const headers = [...baseHeaders, ...dynamicHeaders]
  const rows = filteredAttendees.value.map((user, index) => {
    const registration = registrationFor(user)
    return [
      index + 1,
      user.name,
      registration.gender || normalizedGender(registration) || '-',
      displayAge(registration),
      displayEthnicity(registration),
      displayDisability(registration),
      displayCheckedTime(user),
      user.registrations?.some(isCheckedIn) ? 'Checked in' : 'Pending',
      ...dynamicColumns.value.map((field) => formValueFor(registration, field)),
    ]
  })
  const worksheet = XLSX.utils.aoa_to_sheet([headers, ...rows])
  worksheet['!cols'] = [
    { wch: 6 }, { wch: 28 }, { wch: 16 }, { wch: 12 },
    { wch: 22 }, { wch: 28 }, { wch: 18 }, { wch: 16 },
    ...dynamicColumns.value.map(() => ({ wch: 20 })),
  ]
  worksheet['!autofilter'] = { ref: `A1:${XLSX.utils.encode_col(headers.length - 1)}${rows.length + 1}` }
  worksheet['!freeze'] = { xSplit: 0, ySplit: 1 }
  headers.forEach((_, columnIndex) => {
    const cell = worksheet[XLSX.utils.encode_cell({ r: 0, c: columnIndex })]
    if (cell) cell.s = { font: { bold: true, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '1677D2' } }, alignment: { horizontal: 'center' } }
  })
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Attendances')
  XLSX.writeFile(workbook, `${selectedEvent.value?.name || 'attendances'}-filtered.xlsx`, { cellStyles: true })
}

onMounted(loadWorkspace)
</script>

<template>
  <AdminLayout>
    <section class="attendance-workspace">
      <header class="attendance-workspace-heading">
        <h1>Attendance by Event</h1>
        <p>View all checked-in attendances with full information for each event.</p>
      </header>
      <div class="attendance-workspace-grid">
        <aside class="attendance-event-list">
          <header class="attendance-events-heading"><div><h2>Events <small>{{ events.length }} Total</small></h2></div><RouterLink to="/admin/events/new">+ New</RouterLink></header>
          <label class="attendance-event-search"><span aria-hidden="true">⌕</span><input v-model="eventFilter" type="search" placeholder="Search event..." /></label>
          <div class="attendance-event-tabs"><button type="button" :class="{ active: eventScope === 'all' }" @click="selectEventScope('all')">All Events <b>{{ events.length }}</b></button><button type="button" :class="{ active: eventScope === 'upcoming' }" @click="selectEventScope('upcoming')">Upcoming <b>{{ upcomingEventCount }}</b></button><button type="button" :class="{ active: eventScope === 'ongoing' }" @click="selectEventScope('ongoing')">Ongoing <b>{{ ongoingEventCount }}</b></button><button type="button" :class="{ active: eventScope === 'past' }" @click="selectEventScope('past')">Past <b>{{ pastEventCount }}</b></button></div>
          <div class="attendance-event-items">
            <button v-for="event in visibleEvents" :key="event.id" type="button" class="attendance-event-item" :class="{ active: event.id === selectedEventId }" @click="selectEvent(event)">
              <span class="attendance-event-image"><img v-if="eventImage(event)" :src="eventImage(event)" alt="" /><b v-else>{{ event.name.slice(0, 2).toUpperCase() }}</b></span><span class="attendance-event-copy"><strong>{{ event.name }}</strong><small>Calendar {{ dateLabel(event) }} · {{ timeLabel(event) }}</small><small>Location {{ event.location || 'Location pending' }}</small></span><span class="attendance-event-count"><strong>{{ event.registered || 0 }} / {{ event.capacity || '-' }}</strong><small>attended</small></span><span class="attendance-event-arrow">›</span>
            </button>
          </div>
          <footer class="attendance-terminal"><small>Check-in Terminal</small><strong><i></i> Online</strong><b>Gate A - Phnom Penh</b></footer>
        </aside>
        <main class="attendance-event-detail">
          <div v-if="loading" class="attendance-empty">Loading attendance...</div>
          <template v-else-if="selectedEvent">
            <section class="attendance-summary-card">
              <header class="attendance-event-header"><span class="attendance-event-image attendance-event-image-large"><img v-if="eventImage(selectedEvent)" :src="eventImage(selectedEvent)" alt="" /><b v-else>{{ selectedEvent.name.slice(0, 2).toUpperCase() }}</b></span><div><h2>{{ selectedEvent.name }}</h2><p>Calendar {{ dateLabel(selectedEvent) }} · {{ timeLabel(selectedEvent) }}</p><p>Location {{ selectedEvent.location || 'Location pending' }}</p></div><span class="attendance-live-status">● {{ selectedEvent.status === 'open' ? 'Open' : selectedEvent.status }}</span><div class="attendance-header-actions"><button type="button" class="excel-action" @click="exportFilteredExcel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4zM8 8l3 4-3 4m5-8h3m-3 4h3m-3 4h3" /></svg><span>Export to Excel</span></button><button type="button" class="primary" @click="openCheckIn">Check In Attendance</button></div></header>
              <div class="attendance-summary-tabs"><button type="button" :class="{ active: activeTab === 'attendees' }" @click="activeTab = 'attendees'">Attendances <b>{{ eventAttendeeCount }}</b></button><button type="button" :class="{ active: activeTab === 'not-checked-in' }" @click="activeTab = 'not-checked-in'">Not Checked In <b>{{ pendingCount }}</b></button><button type="button">VIP &amp; Speakers <b>0</b></button></div>
            </section>
            <section class="attendance-table-card">
              <div class="attendance-table-toolbar"><label class="attendance-table-search"><span aria-hidden="true">⌕</span><input v-model="search" type="search" placeholder="Search by name, ID, email, or phone..." /></label><select v-model="ageFilter" :class="{ 'is-active': ageFilter !== 'all' }" aria-label="Filter by age"><option value="all">Age</option><option v-for="option in ageOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><select v-model="genderFilter" :class="{ 'is-active': genderFilter !== 'all' }" aria-label="Filter by gender"><option value="all">Gender</option><option v-for="option in genderOptions" :key="option" :value="option">{{ option }}</option></select><select v-model="ethnicityFilter" :class="{ 'is-active': ethnicityFilter !== 'all' }" aria-label="Filter by ethnicity"><option value="all">Ethnicity</option><option v-for="option in ethnicityOptions" :key="option" :value="option">{{ option }}</option></select><select v-model="disabilityFilter" :class="{ 'is-active': disabilityFilter !== 'all' }" aria-label="Filter by disability"><option value="all">Disability</option><option v-for="option in disabilityOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><select v-model="status" :class="{ 'is-active': status !== 'all' }" aria-label="Filter attendee status"><option value="all">All Status</option><option value="registered">Registered</option><option value="pending">Pending</option></select><button v-if="activeFilterCount || search" type="button" class="attendance-clear-filters" @click="clearFilters">Clear</button></div>
              <div class="attendance-table-scroll"><table class="attendance-table"><thead><tr><th>#</th><th>Photo</th><th>Full Name</th><th>Gender</th><th>Age</th><th>Ethnicity</th><th>Disability</th><th>Checked-time</th><th>Status</th><th>Action</th><th v-for="field in dynamicColumns" :key="`h-${field.id}`">{{ field.label }}</th></tr></thead><tbody><tr v-for="(user, index) in filteredAttendees" :key="user.id"><td>{{ index + 1 }}</td><td><span class="attendance-avatar">{{ initials(user.name) }}</span></td><td><strong>{{ user.name }}</strong></td><td>{{ formatAnswer(registrationFor(user)?.gender || pickAnswer(registrationFor(user), ['gender'], '')) }}</td><td>{{ displayAge(registrationFor(user)) }}</td><td>{{ displayEthnicity(registrationFor(user)) }}</td><td>{{ displayDisability(registrationFor(user)) }}</td><td>{{ displayCheckedTime(user) }}</td><td><span class="attendance-status-pill" :class="user.registrations?.some(isCheckedIn) ? 'checked' : 'pending'">{{ user.registrations?.some(isCheckedIn) ? 'Checked in' : 'Pending' }}</span></td><td><RouterLink :to="`/admin/users/${user.id}`" class="attendance-view-button">View</RouterLink></td><td v-for="field in dynamicColumns" :key="`c-${field.id}`">{{ formValueFor(registrationFor(user), field) }}</td></tr><tr v-if="!filteredAttendees.length"><td :colspan="10 + dynamicColumns.length"><div class="attendance-table-empty"><span>♧</span><strong>{{ search || activeFilterCount ? 'No matching attendances' : 'No attendances yet' }}</strong><small>{{ search || activeFilterCount ? 'Try clearing a filter or changing your search.' : 'Attendance records will appear here when people sign up.' }}</small></div></td></tr></tbody></table></div>
              <footer class="attendance-pagination"><span>Showing 1-{{ filteredAttendees.length }} of {{ eventAttendeeCount }} attendances</span><div><button type="button">‹</button><button type="button" class="current">1</button><button type="button">2</button><button type="button">3</button><button type="button">›</button></div></footer>
            </section>
          </template>
          <div v-else class="attendance-empty attendance-empty-state"><span class="attendance-empty-icon">+</span><strong>No events yet</strong><p>Create an event to start tracking attendance.</p><RouterLink to="/admin/events/new" class="attendance-empty-action">Create event</RouterLink></div>
        </main>
      </div>
    </section>
  </AdminLayout>
</template>
