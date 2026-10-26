<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { getUsers } from '../../services/adminService'
import { useCheckInStore } from '../../stores/checkIn'
import { eventPhase as phaseOf, percentOf, phaseLabels } from '../../utils/eventStats'

const route = useRoute()
const router = useRouter()
const checkInStore = useCheckInStore()

const loading = ref(true)
const eventsError = ref('')
const events = ref([])
const selectedEventId = ref(null)
const eventFilter = ref('')
const eventScope = ref('all')

const attendees = ref([])
const formFields = ref([])
const attendeesLoading = ref(false)
const attendeesError = ref('')

const search = ref('')
const checkFilter = ref('all')
const ageFilter = ref('all')
const genderFilter = ref('all')
const ethnicityFilter = ref('all')
const disabilityFilter = ref('all')
const sortBy = ref('name-asc')
const currentPage = ref(1)
const pageSize = ref(10)
const pageSizes = [10, 25, 50]

const detailKey = ref(null)
const confirming = ref(false)
const pendingCheckIn = ref(null)
const checkInError = ref('')
const notice = ref('')
const exporting = ref(false)
const exportError = ref('')
const now = ref(Date.now())
let clockTimer = null
let noticeTimer = null
let returnFocus = null
let attendeeRequest = 0

// Disability codes used by the registration form, for answers stored only as codes.
const disabilityCodes = { C: 'Difficulty seeing', H: 'Difficulty hearing', M: 'Difficulty moving', R: 'Difficulty remembering', S: 'Difficulty with self-care', X: 'Difficulty communicating' }

const normalizeKey = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '')
const capitalize = (text) => text ? text.charAt(0).toUpperCase() + text.slice(1) : ''
// Choice answers look like "M: ប្រុស/Male"; keep the readable English part for tables and filters.
const readable = (value) => {
  const text = String(value ?? '').trim()
  if (!text) return ''
  const withoutCode = text.replace(/^[A-Z]{1,2}\s*:\s*/, '')
  const parts = withoutCode.split('/').map((part) => part.trim()).filter(Boolean)
  return capitalize(parts.length > 1 ? parts[parts.length - 1] : withoutCode)
}
const toList = (value) => {
  if (Array.isArray(value)) return value
  const text = String(value ?? '').trim()
  if (!text) return []
  if (text.startsWith('[')) {
    try { const parsed = JSON.parse(text); if (Array.isArray(parsed)) return parsed } catch { /* not JSON */ }
  }
  return text.split(',')
}
const isBlank = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)

// Form fields defined for the selected event, so submitted answers can be resolved.
const formFieldOptions = computed(() => formFields.value.map((field) => ({ id: field.id, label: field.label, normalized: normalizeKey(field.label), type: String(field.type || 'text').toLowerCase() })))
const usedFieldIds = computed(() => {
  const used = new Set()
  attendees.value.forEach((user) => (user.registrations || []).forEach((registration) => {
    Object.entries(registration.form_data || {}).forEach(([key, value]) => { if (!isBlank(value)) used.add(String(key)) })
  }))
  return used
})
// Extra form answers exported as their own spreadsheet columns.
const dynamicColumns = computed(() => {
  const fixed = new Set(['fullname', 'name', 'email', 'phone', 'gender', 'age', 'agegroup', 'ethnicity', 'race', 'disability', 'disabilitytype', 'organization', 'address', 'position', 'profilephoto', 'emergencycontactname', 'emergencycontactphone', 'signature', 'photoconsent', 'event', 'eventname', 'registrationcode'])
  return formFieldOptions.value.filter((field) => (usedFieldIds.value.has(String(field.id)) || usedFieldIds.value.has(field.normalized)) && !fixed.has(field.normalized))
})
// Resolve a form field's answer by field id or label (id-keyed data from the
// form builder and legacy label-keyed data), falling back to form_values.
const formFieldAnswer = (registration, field) => {
  if (!registration || !field) return ''
  const formData = registration.form_data || {}
  if (!isBlank(formData[field.id])) return formData[field.id]
  const key = Object.keys(formData).find((item) => normalizeKey(item) === field.normalized)
  if (key !== undefined && !isBlank(formData[key])) return formData[key]
  return (registration.form_values || {})[field.label] ?? ''
}
const formatAnswer = (value) => {
  if (isBlank(value)) return '—'
  if (Array.isArray(value)) return value.join(', ')
  return String(value).trim() || '—'
}
const pickAnswer = (registration, candidates) => {
  for (const candidate of candidates) {
    const field = formFieldOptions.value.find((option) => option.normalized === normalizeKey(candidate))
    const answer = formFieldAnswer(registration, field)
    if (!isBlank(answer)) return answer
  }
  return ''
}

const ageGroupFromNumber = (age) => age < 18 ? 'Under 18' : age < 30 ? '18–29' : age <= 60 ? '30–60' : 'Above 60'
const buildRow = (user, registration) => {
  const age = registration.age === null || registration.age === undefined || registration.age === '' ? NaN : Number(registration.age)
  const ageGroup = readable(registration.age_group || pickAnswer(registration, ['age group', 'age']))
  const disabilityAnswer = pickAnswer(registration, ['disability', 'disability type'])
  const disabilities = isBlank(disabilityAnswer)
    ? toList(registration.disability_type).map((code) => disabilityCodes[String(code).trim()] || readable(code)).filter(Boolean)
    : toList(disabilityAnswer).map(readable).filter(Boolean)
  const checkInRecord = registration.check_in
  const checkedInAt = registration.checked_in_at || checkInRecord?.checked_in_at
  return {
    key: registration.id,
    user,
    registration,
    name: registration.full_name?.trim() || user.name?.trim() || user.email || user.phone || 'Unnamed attendee',
    email: registration.email || user.email || '',
    phone: registration.phone || registration.telephone || user.phone || '',
    code: registration.registration_code || '',
    gender: readable(pickAnswer(registration, ['gender'])) || capitalize(String(registration.gender || '')),
    age: Number.isFinite(age) ? String(age) : ageGroup,
    ageGroup: ageGroup || (Number.isFinite(age) ? ageGroupFromNumber(age) : ''),
    ethnicity: readable(registration.ethnicity || pickAnswer(registration, ['ethnicity', 'race'])),
    disabilities,
    registeredAt: registration.created_at ? new Date(registration.created_at) : null,
    checkedInAt: checkedInAt ? new Date(checkedInAt) : null,
  }
}

const normalizeEvent = (event) => ({
  ...event,
  registered: event.registrations_count ?? event.registered ?? 0,
  capacity: event.capacity ?? event.maximum_participants,
  // starts_at/ends_at are UTC; keep Date objects so labels show the viewer's local time.
  starts: event.starts_at ? new Date(event.starts_at) : null,
  ends: event.ends_at ? new Date(event.ends_at) : null,
})
const eventPhase = (event) => phaseOf(event, now.value)
const scopes = [
  { value: 'all', label: 'All' },
  { value: 'ongoing', label: 'Now' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'past', label: 'Past' },
]
const scopeCount = (scope) => scope === 'all' ? events.value.length : events.value.filter((event) => eventPhase(event) === scope).length
const visibleEvents = computed(() => {
  const query = eventFilter.value.trim().toLowerCase()
  return events.value.filter((event) => (!query || `${event.name} ${event.location || ''}`.toLowerCase().includes(query)) && (eventScope.value === 'all' || eventPhase(event) === eventScope.value))
})
const selectedEvent = computed(() => events.value.find((event) => event.id === selectedEventId.value) || null)

const rows = computed(() => attendees.value.flatMap((user) => (user.registrations || [])
  .filter((registration) => !selectedEventId.value || Number(registration.event_id) === Number(selectedEventId.value))
  .map((registration) => buildRow(user, registration))))
const totalCount = computed(() => rows.value.length)
const checkedInCount = computed(() => rows.value.filter((row) => row.checkedInAt).length)
const remainingCount = computed(() => Math.max(totalCount.value - checkedInCount.value, 0))
const checkInRate = computed(() => percentOf(checkedInCount.value, totalCount.value) ?? 0)
const registeredFor = (event) => event.id === selectedEventId.value && !attendeesLoading.value && !attendeesError.value ? totalCount.value : event.registered

const distinct = (values) => [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))
const ageOptions = computed(() => distinct(rows.value.map((row) => row.ageGroup)))
const genderOptions = computed(() => distinct(rows.value.map((row) => row.gender)))
const ethnicityOptions = computed(() => distinct(rows.value.map((row) => row.ethnicity)))
const disabilityOptions = computed(() => distinct(rows.value.flatMap((row) => row.disabilities)))
const activeFilterCount = computed(() => [ageFilter, genderFilter, ethnicityFilter, disabilityFilter].filter((filter) => filter.value !== 'all').length)
const hasNarrowing = computed(() => Boolean(search.value.trim()) || activeFilterCount.value > 0 || checkFilter.value !== 'all')

const matchesFilters = (row) => {
  const query = search.value.trim().toLowerCase()
  if (query && !`${row.name} ${row.email} ${row.phone} ${row.code}`.toLowerCase().includes(query)) return false
  if (ageFilter.value !== 'all' && row.ageGroup !== ageFilter.value) return false
  if (genderFilter.value !== 'all' && row.gender !== genderFilter.value) return false
  if (ethnicityFilter.value !== 'all' && row.ethnicity !== ethnicityFilter.value) return false
  if (disabilityFilter.value !== 'all' && !row.disabilities.includes(disabilityFilter.value)) return false
  return true
}
// Rows matching search + filters, before the check-in status tab is applied (used for tab counts).
const matchingRows = computed(() => rows.value.filter(matchesFilters))
const statusCounts = computed(() => ({
  all: matchingRows.value.length,
  in: matchingRows.value.filter((row) => row.checkedInAt).length,
  out: matchingRows.value.filter((row) => !row.checkedInAt).length,
}))
const time = (date) => date ? date.getTime() : 0
const sorters = {
  'name-asc': (a, b) => a.name.localeCompare(b.name),
  'name-desc': (a, b) => b.name.localeCompare(a.name),
  'registered-desc': (a, b) => time(b.registeredAt) - time(a.registeredAt),
  'registered-asc': (a, b) => time(a.registeredAt) - time(b.registeredAt),
  'checkin-desc': (a, b) => (b.checkedInAt ? 1 : 0) - (a.checkedInAt ? 1 : 0) || time(b.checkedInAt) - time(a.checkedInAt) || a.name.localeCompare(b.name),
  'checkin-asc': (a, b) => (b.checkedInAt ? 1 : 0) - (a.checkedInAt ? 1 : 0) || time(a.checkedInAt) - time(b.checkedInAt) || a.name.localeCompare(b.name),
}
const sortOptions = [
  { value: 'name-asc', label: 'Name (A–Z)' },
  { value: 'name-desc', label: 'Name (Z–A)' },
  { value: 'registered-desc', label: 'Newest registrations' },
  { value: 'registered-asc', label: 'Oldest registrations' },
  { value: 'checkin-desc', label: 'Latest check-ins' },
  { value: 'checkin-asc', label: 'Earliest check-ins' },
]
const filteredRows = computed(() => matchingRows.value
  .filter((row) => checkFilter.value === 'all' || (checkFilter.value === 'in' ? row.checkedInAt : !row.checkedInAt))
  .sort(sorters[sortBy.value] || sorters['name-asc']))
const sortColumn = (column) => {
  const [current, direction] = sortBy.value.split('-')
  const first = column === 'name' ? 'asc' : 'desc'
  sortBy.value = `${column}-${current === column ? (direction === 'asc' ? 'desc' : 'asc') : first}`
}
const ariaSort = (column) => {
  const [current, direction] = sortBy.value.split('-')
  return current === column ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'
}

const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value)))
const pageRows = computed(() => filteredRows.value.slice((currentPage.value - 1) * pageSize.value, currentPage.value * pageSize.value))
const pageStart = computed(() => filteredRows.value.length ? (currentPage.value - 1) * pageSize.value + 1 : 0)
const pageEnd = computed(() => Math.min(currentPage.value * pageSize.value, filteredRows.value.length))
const pageItems = computed(() => {
  const total = pageCount.value
  const current = currentPage.value
  if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1)
  const pages = [...new Set([1, current - 1, current, current + 1, total])].filter((page) => page >= 1 && page <= total).sort((a, b) => a - b)
  return pages.flatMap((page, index) => index && page - pages[index - 1] > 1 ? [`gap-${page}`, page] : [page])
})
const goToPage = (page) => { currentPage.value = Math.min(Math.max(page, 1), pageCount.value) }
watch([search, checkFilter, ageFilter, genderFilter, ethnicityFilter, disabilityFilter, sortBy, pageSize, selectedEventId], () => { currentPage.value = 1 })
watch(pageCount, (count) => { if (currentPage.value > count) currentPage.value = count })

const dateLabel = (date) => date ? date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : 'Date to be announced'
const clock = (date) => date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
const timeLabel = (event) => {
  if (!event?.starts) return ''
  if (!event.ends) return clock(event.starts)
  const sameDay = event.starts.toDateString() === event.ends.toDateString()
  return sameDay ? `${clock(event.starts)} – ${clock(event.ends)}` : `${clock(event.starts)} – ${event.ends.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}, ${clock(event.ends)}`
}
const dateTimeLabel = (date) => date ? date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'
const checkedTimeLabel = (date) => {
  if (!date) return ''
  return date.toDateString() === new Date(now.value).toDateString() ? clock(date) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
const eventImage = (event) => event?.branding?.image || event?.image || ''
const initials = (name) => (name || 'U').trim().split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()

// The users endpoint caps pages at 100, so walk every page instead of silently truncating.
const fetchAllAttendees = async (eventId) => {
  const all = []
  let page = 1
  let lastPage = 1
  do {
    const { data } = await getUsers({ event_id: eventId, per_page: 100, page })
    all.push(...(data.data || []))
    lastPage = Number(data.last_page) || 1
    page += 1
  } while (page <= lastPage)
  return all
}

const loadAttendees = async ({ silent = false } = {}) => {
  const eventId = selectedEventId.value
  const ticket = ++attendeeRequest
  attendeesError.value = ''
  if (!eventId) {
    attendees.value = []
    formFields.value = []
    attendeesLoading.value = false
    return
  }
  if (!silent) {
    attendeesLoading.value = true
    attendees.value = []
    formFields.value = []
  }
  try {
    const [list, formResponse] = await Promise.all([
      fetchAllAttendees(eventId),
      api.get(`/events/${eventId}/form`).catch(() => null),
    ])
    if (ticket !== attendeeRequest) return
    const fields = formResponse?.data?.data?.fields ?? formResponse?.data?.data
    formFields.value = Array.isArray(fields) ? fields : []
    attendees.value = list
  } catch {
    if (ticket === attendeeRequest) attendeesError.value = 'We couldn’t load the attendees for this event.'
  } finally {
    if (ticket === attendeeRequest) attendeesLoading.value = false
  }
}

const defaultEventId = () => {
  const requested = events.value.find((event) => String(event.id) === String(route.query.event))
  if (requested) return requested.id
  const ongoing = events.value.find((event) => eventPhase(event) === 'ongoing')
  if (ongoing) return ongoing.id
  const upcoming = events.value.filter((event) => eventPhase(event) === 'upcoming').sort((a, b) => a.starts - b.starts)[0]
  return (upcoming || events.value[0])?.id ?? null
}

const loadWorkspace = async () => {
  loading.value = true
  eventsError.value = ''
  try {
    const { data } = await api.get('/admin/events')
    events.value = (data.data || data).map(normalizeEvent)
  } catch {
    let cached = []
    try {
      const saved = JSON.parse(localStorage.getItem('event_list') || '[]')
      if (Array.isArray(saved)) cached = saved.map(normalizeEvent)
    } catch { cached = [] }
    events.value = cached
    eventsError.value = cached.length ? 'We couldn’t refresh events, so this is the last saved list.' : 'We couldn’t load your events.'
  }
  selectedEventId.value = defaultEventId()
  loading.value = false
  await loadAttendees()
}

const resetFilters = () => {
  search.value = ''
  checkFilter.value = 'all'
  ageFilter.value = 'all'
  genderFilter.value = 'all'
  ethnicityFilter.value = 'all'
  disabilityFilter.value = 'all'
}
const clearFilters = resetFilters
const selectEvent = async (event) => {
  if (event.id === selectedEventId.value) return
  closeDetails()
  resetFilters()
  selectedEventId.value = event.id
  router.replace({ query: { ...route.query, event: event.id } }).catch(() => {})
  await loadAttendees()
}
const openCheckInDesk = () => router.push({ path: '/admin/check-ins', query: selectedEventId.value ? { event: selectedEventId.value } : {} })

// ----- Attendee details + check-in -----
const detailRow = computed(() => rows.value.find((row) => row.key === detailKey.value) || null)
const detailAnswers = computed(() => Object.entries(detailRow.value?.registration.form_values || {})
  .filter(([, value]) => !isBlank(value))
  .map(([label, value]) => ({ label, value: formatAnswer(value) })))
const openDetails = async (row, { confirm = false } = {}) => {
  returnFocus = document.activeElement
  detailKey.value = row.key
  confirming.value = confirm && !row.checkedInAt
  checkInError.value = ''
  await nextTick()
  document.querySelector(confirming.value ? '.at-drawer [data-confirm]' : '.at-drawer__close')?.focus()
}
function closeDetails() {
  if (detailKey.value === null) return
  detailKey.value = null
  confirming.value = false
  checkInError.value = ''
  const target = returnFocus
  returnFocus = null
  nextTick(() => target?.isConnected && target.focus())
}
const startConfirm = async () => {
  confirming.value = true
  checkInError.value = ''
  await nextTick()
  document.querySelector('.at-drawer [data-confirm]')?.focus()
}
const announce = (message) => {
  notice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => { notice.value = '' }, 5000)
}
// The row only flips to "Checked in" after the API confirms; the server rejects duplicates too.
const confirmCheckIn = async (row) => {
  if (!row || row.checkedInAt || pendingCheckIn.value !== null) return
  const credential = row.code || row.registration.qr_token
  if (!credential) {
    checkInError.value = 'This registration has no code to check in with. Use the check-in desk instead.'
    return
  }
  pendingCheckIn.value = row.key
  checkInError.value = ''
  try {
    const result = await checkInStore.submit(credential)
    row.registration.check_in = result?.check_in || { checked_in_at: new Date().toISOString() }
    row.registration.status = 'checked_in'
    confirming.value = false
    announce(`${row.name} is checked in.`)
    await nextTick()
    document.querySelector('.at-drawer__close')?.focus()
  } catch (error) {
    checkInError.value = checkInStore.error || 'Check-in failed. Please try again.'
    // Another desk may have checked this person in already; refresh so the row shows the real state.
    if (error?.response?.status === 422) await loadAttendees({ silent: true })
  } finally {
    pendingCheckIn.value = null
  }
}
const onKeydown = (event) => {
  if (event.key === 'Escape' && detailKey.value !== null && pendingCheckIn.value === null) closeDetails()
  if (event.key === 'Tab' && detailKey.value !== null) {
    const focusable = [...document.querySelectorAll('.at-drawer__panel a[href], .at-drawer__panel button:not(:disabled)')]
    if (!focusable.length) return
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
  }
}

const exportFilteredExcel = async () => {
  if (exporting.value || !filteredRows.value.length) return
  exporting.value = true
  exportError.value = ''
  try {
    const XLSX = await import('xlsx')
    const baseHeaders = ['#', 'Full Name', 'Registration Code', 'Email', 'Phone', 'Gender', 'Age', 'Ethnicity', 'Disability', 'Registered At', 'Status', 'Checked-in Time']
    const headers = [...baseHeaders, ...dynamicColumns.value.map((field) => field.label)]
    const rowsOut = filteredRows.value.map((row, index) => [
      index + 1,
      row.name,
      row.code || '-',
      row.email || '-',
      row.phone || '-',
      row.gender || '-',
      row.age || '-',
      row.ethnicity || '-',
      row.disabilities.join(', ') || '-',
      row.registeredAt ? dateTimeLabel(row.registeredAt) : '-',
      row.checkedInAt ? 'Checked in' : 'Not checked in',
      row.checkedInAt ? dateTimeLabel(row.checkedInAt) : '-',
      ...dynamicColumns.value.map((field) => formatAnswer(formFieldAnswer(row.registration, field)).replace('—', '-')),
    ])
    const worksheet = XLSX.utils.aoa_to_sheet([headers, ...rowsOut])
    worksheet['!cols'] = [
      { wch: 6 }, { wch: 28 }, { wch: 18 }, { wch: 28 }, { wch: 16 }, { wch: 12 },
      { wch: 10 }, { wch: 20 }, { wch: 30 }, { wch: 22 }, { wch: 16 }, { wch: 22 },
      ...dynamicColumns.value.map(() => ({ wch: 22 })),
    ]
    worksheet['!autofilter'] = { ref: `A1:${XLSX.utils.encode_col(headers.length - 1)}${rowsOut.length + 1}` }
    worksheet['!freeze'] = { xSplit: 0, ySplit: 1 }
    headers.forEach((_, columnIndex) => {
      const cell = worksheet[XLSX.utils.encode_cell({ r: 0, c: columnIndex })]
      if (cell) cell.s = { font: { bold: true, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '0B7F62' } }, alignment: { horizontal: 'center' } }
    })
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Attendances')
    XLSX.writeFile(workbook, `${selectedEvent.value?.name || 'attendances'}-filtered.xlsx`, { cellStyles: true })
    announce(`Exported ${rowsOut.length} ${rowsOut.length === 1 ? 'attendee' : 'attendees'} to Excel.`)
  } catch {
    exportError.value = 'The Excel file couldn’t be created. Please try again.'
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  loadWorkspace()
  document.addEventListener('keydown', onKeydown)
  clockTimer = setInterval(() => { now.value = Date.now() }, 60000)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  clearInterval(clockTimer)
  clearTimeout(noticeTimer)
})
</script>

<template>
  <AdminLayout>
    <section class="at-page">
      <header class="at-header">
        <h1>Attendance by Event</h1>
        <p>Pick an event to see who registered, who has arrived, and check people in.</p>
      </header>

      <p v-if="eventsError" class="at-alert" role="alert">
        <AppIcon name="alert-circle" :size="18" />
        <span>{{ eventsError }}</span>
        <button type="button" class="at-btn at-btn--ghost" :disabled="loading" @click="loadWorkspace">{{ loading ? 'Retrying…' : 'Retry' }}</button>
      </p>

      <div class="at-layout">
        <aside class="at-card at-events" aria-labelledby="at-events-title">
          <div class="at-events__head">
            <h2 id="at-events-title">Events <span class="at-count">{{ events.length }}</span></h2>
            <RouterLink to="/admin/events/new" class="at-btn at-btn--ghost at-btn--sm"><AppIcon name="plus" :size="16" />New</RouterLink>
          </div>
          <label class="at-search">
            <AppIcon name="search" :size="17" />
            <span class="sr-only">Search events</span>
            <input v-model="eventFilter" type="search" placeholder="Search events or venues" />
          </label>
          <div class="at-scope" role="group" aria-label="Show events by date">
            <button v-for="scope in scopes" :key="scope.value" type="button" :aria-pressed="eventScope === scope.value" :class="{ 'is-active': eventScope === scope.value }" @click="eventScope = scope.value">
              {{ scope.label }} <span>{{ scopeCount(scope.value) }}</span>
            </button>
          </div>

          <ul v-if="loading" class="at-event-list" aria-busy="true">
            <li v-for="n in 3" :key="n" class="at-skeleton at-skeleton--event" />
          </ul>
          <ul v-else-if="visibleEvents.length" class="at-event-list">
            <li v-for="event in visibleEvents" :key="event.id">
              <button type="button" class="at-event" :class="{ 'is-selected': event.id === selectedEventId }" :aria-current="event.id === selectedEventId ? 'true' : undefined" @click="selectEvent(event)">
                <span class="at-thumb"><img v-if="eventImage(event)" :src="eventImage(event)" alt="" /><b v-else>{{ initials(event.name) }}</b></span>
                <span class="at-event__body">
                  <strong class="at-event__name">{{ event.name }}</strong>
                  <span class="at-meta"><AppIcon name="calendar" :size="14" />{{ dateLabel(event.starts) }}</span>
                  <span v-if="event.starts" class="at-meta"><AppIcon name="clock" :size="14" />{{ timeLabel(event) }}</span>
                  <span class="at-meta"><AppIcon name="pin" :size="14" />{{ event.location || 'Location to be announced' }}</span>
                  <span class="at-event__foot">
                    <span class="at-badge" :class="`at-badge--${eventPhase(event)}`">{{ phaseLabels[eventPhase(event)] }}</span>
                    <span class="at-event__count"><b>{{ registeredFor(event) }}</b> registered<template v-if="event.capacity"> · {{ event.capacity }} spots</template></span>
                  </span>
                </span>
              </button>
            </li>
          </ul>
          <div v-else class="at-events__empty">
            <template v-if="events.length">
              <p>No events match {{ eventFilter ? `“${eventFilter}”` : 'this view' }}.</p>
              <button type="button" class="at-btn at-btn--ghost at-btn--sm" @click="eventFilter = ''; eventScope = 'all'">Show all events</button>
            </template>
            <p v-else>No events yet.</p>
          </div>
        </aside>

        <div class="at-main">
          <div v-if="loading" class="at-card at-skeleton at-skeleton--head" aria-busy="true"><span class="sr-only">Loading attendance…</span></div>

          <template v-else-if="selectedEvent">
            <section class="at-card at-overview" aria-labelledby="at-event-title">
              <div class="at-overview__top">
                <span class="at-thumb at-thumb--lg"><img v-if="eventImage(selectedEvent)" :src="eventImage(selectedEvent)" alt="" /><b v-else>{{ initials(selectedEvent.name) }}</b></span>
                <div class="at-overview__copy">
                  <span class="at-badge" :class="`at-badge--${eventPhase(selectedEvent)}`">{{ phaseLabels[eventPhase(selectedEvent)] }}</span>
                  <h2 id="at-event-title">{{ selectedEvent.name }}</h2>
                  <p class="at-meta"><AppIcon name="calendar" :size="15" />{{ dateLabel(selectedEvent.starts) }}<template v-if="selectedEvent.starts"> · {{ timeLabel(selectedEvent) }}</template></p>
                  <p class="at-meta"><AppIcon name="pin" :size="15" />{{ selectedEvent.location || 'Location to be announced' }}</p>
                </div>
                <div class="at-overview__actions">
                  <button type="button" class="at-btn at-btn--secondary" :disabled="exporting || attendeesLoading || !filteredRows.length" :aria-busy="exporting" @click="exportFilteredExcel">
                    <AppIcon name="download" :size="17" />{{ exporting ? 'Exporting…' : 'Export to Excel' }}
                  </button>
                  <button type="button" class="at-btn at-btn--primary" @click="openCheckInDesk"><AppIcon name="scan" :size="17" />Check In Attendance</button>
                </div>
              </div>
              <p v-if="exportError" class="at-inline-error" role="alert">{{ exportError }}</p>
              <p v-if="filteredRows.length && hasNarrowing" class="at-export-note">Export includes the {{ filteredRows.length }} {{ filteredRows.length === 1 ? 'attendee' : 'attendees' }} currently shown.</p>

              <dl class="at-stats" :aria-busy="attendeesLoading">
                <div class="at-stat">
                  <dt><AppIcon name="users" :size="16" />Registered</dt>
                  <dd>{{ attendeesLoading ? '—' : totalCount }}</dd>
                  <small v-if="selectedEvent.capacity">of {{ selectedEvent.capacity }} spots</small>
                </div>
                <div class="at-stat at-stat--in">
                  <dt><AppIcon name="user-check" :size="16" />Checked in</dt>
                  <dd>{{ attendeesLoading ? '—' : checkedInCount }}</dd>
                  <small>{{ attendeesLoading ? '' : `${checkInRate}% of registered` }}</small>
                </div>
                <div class="at-stat">
                  <dt><AppIcon name="timer" :size="16" />Not checked in</dt>
                  <dd>{{ attendeesLoading ? '—' : remainingCount }}</dd>
                  <small>still to arrive</small>
                </div>
                <div class="at-stat at-stat--rate">
                  <dt><AppIcon name="chart-pie" :size="16" />Check-in rate</dt>
                  <dd>{{ attendeesLoading ? '—' : `${checkInRate}%` }}</dd>
                  <span class="at-progress" role="progressbar" aria-label="Check-in progress" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="checkInRate"><span :style="{ width: `${checkInRate}%` }" /></span>
                </div>
              </dl>
            </section>

            <section class="at-card at-roster" aria-labelledby="at-roster-title">
              <div class="at-roster__head">
                <h2 id="at-roster-title">Attendees</h2>
                <div class="at-tabs" role="group" aria-label="Filter by check-in status">
                  <button type="button" :aria-pressed="checkFilter === 'all'" :class="{ 'is-active': checkFilter === 'all' }" @click="checkFilter = 'all'">All <span>{{ statusCounts.all }}</span></button>
                  <button type="button" :aria-pressed="checkFilter === 'in'" :class="{ 'is-active': checkFilter === 'in' }" @click="checkFilter = 'in'">Checked in <span>{{ statusCounts.in }}</span></button>
                  <button type="button" :aria-pressed="checkFilter === 'out'" :class="{ 'is-active': checkFilter === 'out' }" @click="checkFilter = 'out'">Not checked in <span>{{ statusCounts.out }}</span></button>
                </div>
              </div>

              <div class="at-toolbar">
                <label class="at-search at-search--wide">
                  <AppIcon name="search" :size="17" />
                  <span class="sr-only">Search attendees</span>
                  <input v-model="search" type="search" placeholder="Search name, email, phone or code" :disabled="attendeesLoading" />
                </label>
                <label class="at-select">
                  <span class="sr-only">Sort attendees</span>
                  <AppIcon name="sort" :size="16" />
                  <select v-model="sortBy">
                    <option v-for="option in sortOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                </label>
              </div>
              <div v-if="ageOptions.length || genderOptions.length || ethnicityOptions.length || disabilityOptions.length" class="at-filters">
                <label v-if="ageOptions.length" class="at-select" :class="{ 'is-active': ageFilter !== 'all' }">
                  <span class="sr-only">Filter by age</span>
                  <select v-model="ageFilter"><option value="all">Any age</option><option v-for="option in ageOptions" :key="option" :value="option">{{ option }}</option></select>
                </label>
                <label v-if="genderOptions.length" class="at-select" :class="{ 'is-active': genderFilter !== 'all' }">
                  <span class="sr-only">Filter by gender</span>
                  <select v-model="genderFilter"><option value="all">Any gender</option><option v-for="option in genderOptions" :key="option" :value="option">{{ option }}</option></select>
                </label>
                <label v-if="ethnicityOptions.length" class="at-select" :class="{ 'is-active': ethnicityFilter !== 'all' }">
                  <span class="sr-only">Filter by ethnicity</span>
                  <select v-model="ethnicityFilter"><option value="all">Any ethnicity</option><option v-for="option in ethnicityOptions" :key="option" :value="option">{{ option }}</option></select>
                </label>
                <label v-if="disabilityOptions.length" class="at-select" :class="{ 'is-active': disabilityFilter !== 'all' }">
                  <span class="sr-only">Filter by disability</span>
                  <select v-model="disabilityFilter"><option value="all">Any disability</option><option v-for="option in disabilityOptions" :key="option" :value="option">{{ option }}</option></select>
                </label>
                <button v-if="hasNarrowing" type="button" class="at-btn at-btn--text" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
              </div>

              <div v-if="attendeesLoading" class="at-table-loading" aria-busy="true">
                <span class="sr-only" role="status">Loading attendees…</span>
                <div v-for="n in 5" :key="n" class="at-skeleton at-skeleton--row" />
              </div>
              <div v-else-if="attendeesError" class="at-state" role="alert">
                <AppIcon name="alert-circle" :size="26" />
                <strong>{{ attendeesError }}</strong>
                <p>Check your connection and try again.</p>
                <button type="button" class="at-btn at-btn--secondary" @click="loadAttendees()"><AppIcon name="refresh" :size="16" />Try again</button>
              </div>
              <div v-else-if="!rows.length" class="at-state">
                <AppIcon name="users" :size="26" />
                <strong>No one has registered yet</strong>
                <p>Attendees will appear here as soon as people sign up for this event.</p>
              </div>
              <div v-else-if="!filteredRows.length" class="at-state">
                <AppIcon name="search" :size="26" />
                <strong>No attendees match</strong>
                <p>Try a different search or clear the filters.</p>
                <button type="button" class="at-btn at-btn--secondary" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
              </div>
              <template v-else>
                <p class="sr-only" role="status">Showing {{ pageStart }} to {{ pageEnd }} of {{ filteredRows.length }} attendees</p>
                <div class="at-table-wrap">
                  <table class="at-table">
                    <thead>
                      <tr>
                        <th scope="col" :aria-sort="ariaSort('name')"><button type="button" class="at-sort" @click="sortColumn('name')">Attendee<AppIcon :name="ariaSort('name') === 'descending' ? 'arrow-down' : ariaSort('name') === 'ascending' ? 'arrow-up' : 'sort'" :size="14" /></button></th>
                        <th scope="col">Gender</th>
                        <th scope="col">Age</th>
                        <th scope="col" class="at-col-registered" :aria-sort="ariaSort('registered')"><button type="button" class="at-sort" @click="sortColumn('registered')">Registered<AppIcon :name="ariaSort('registered') === 'descending' ? 'arrow-down' : ariaSort('registered') === 'ascending' ? 'arrow-up' : 'sort'" :size="14" /></button></th>
                        <th scope="col" :aria-sort="ariaSort('checkin')"><button type="button" class="at-sort" @click="sortColumn('checkin')">Check-in<AppIcon :name="ariaSort('checkin') === 'descending' ? 'arrow-down' : ariaSort('checkin') === 'ascending' ? 'arrow-up' : 'sort'" :size="14" /></button></th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in pageRows" :key="row.key" :class="{ 'is-open': row.key === detailKey }">
                        <td class="at-cell-person">
                          <div class="at-person">
                            <span class="at-avatar" aria-hidden="true">{{ initials(row.name) }}</span>
                            <span class="at-person__text">
                              <strong>{{ row.name }}</strong>
                              <small>{{ row.email || row.phone || 'No contact details' }}</small>
                              <small v-if="row.code" class="at-code">{{ row.code }}</small>
                            </span>
                          </div>
                        </td>
                        <td data-label="Gender">{{ row.gender || '—' }}</td>
                        <td data-label="Age">{{ row.age || '—' }}</td>
                        <td data-label="Registered" class="at-col-registered"><time v-if="row.registeredAt" :datetime="row.registeredAt.toISOString()">{{ row.registeredAt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) }}</time><template v-else>—</template></td>
                        <td data-label="Check-in">
                          <span v-if="row.checkedInAt" class="at-badge at-badge--in"><AppIcon name="check" :size="13" />Checked in · {{ checkedTimeLabel(row.checkedInAt) }}</span>
                          <span v-else class="at-badge at-badge--out">Not checked in</span>
                        </td>
                        <td class="at-cell-actions">
                          <button type="button" class="at-btn at-btn--ghost at-btn--sm at-btn--details" :aria-label="`View details for ${row.name}`" title="View details" @click="openDetails(row)"><AppIcon name="eye" :size="17" /><span class="at-btn__label">Details</span></button>
                          <button v-if="!row.checkedInAt" type="button" class="at-btn at-btn--primary at-btn--sm" :disabled="pendingCheckIn !== null" :aria-label="`Check in ${row.name}`" @click="openDetails(row, { confirm: true })">Check in</button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <footer class="at-pagination">
                  <p>Showing <b>{{ pageStart }}–{{ pageEnd }}</b> of <b>{{ filteredRows.length }}</b></p>
                  <label class="at-page-size">Rows
                    <select v-model.number="pageSize"><option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option></select>
                  </label>
                  <nav v-if="pageCount > 1" class="at-pager" aria-label="Attendee pages">
                    <button type="button" :disabled="currentPage === 1" aria-label="Previous page" @click="goToPage(currentPage - 1)"><AppIcon name="chevron-left" :size="16" /></button>
                    <template v-for="item in pageItems" :key="item">
                      <span v-if="typeof item === 'string'" class="at-pager__gap" aria-hidden="true">…</span>
                      <button v-else type="button" :class="{ 'is-current': item === currentPage }" :aria-current="item === currentPage ? 'page' : undefined" :aria-label="`Page ${item}`" @click="goToPage(item)">{{ item }}</button>
                    </template>
                    <button type="button" :disabled="currentPage === pageCount" aria-label="Next page" @click="goToPage(currentPage + 1)"><AppIcon name="chevron-right" :size="16" /></button>
                  </nav>
                </footer>
              </template>
            </section>
          </template>

          <div v-else class="at-card at-state at-state--page">
            <AppIcon name="calendar-plus" :size="28" />
            <strong>{{ events.length ? 'Select an event' : 'No events yet' }}</strong>
            <p>{{ events.length ? 'Choose an event from the list to see its attendance.' : 'Create an event to start tracking attendance.' }}</p>
            <RouterLink v-if="!events.length" to="/admin/events/new" class="at-btn at-btn--primary"><AppIcon name="plus" :size="16" />Create event</RouterLink>
          </div>
        </div>
      </div>

      <p class="at-toast" :class="{ 'is-visible': notice }" role="status" aria-live="polite"><AppIcon v-if="notice" name="check-circle" :size="18" />{{ notice }}</p>
    </section>

    <Teleport to="body">
      <div v-if="detailRow" class="at-drawer">
        <div class="at-drawer__scrim" @click="pendingCheckIn === null && closeDetails()" />
        <aside class="at-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="at-drawer-title">
          <header class="at-drawer__head">
            <span class="at-avatar at-avatar--lg" aria-hidden="true">{{ initials(detailRow.name) }}</span>
            <div>
              <h2 id="at-drawer-title">{{ detailRow.name }}</h2>
              <p v-if="detailRow.code" class="at-code">{{ detailRow.code }}</p>
            </div>
            <button type="button" class="at-drawer__close" aria-label="Close details" :disabled="pendingCheckIn !== null" @click="closeDetails"><AppIcon name="x" :size="20" /></button>
          </header>

          <div class="at-drawer__body">
            <section class="at-checkin" :class="detailRow.checkedInAt ? 'is-in' : 'is-out'" aria-live="polite">
              <template v-if="detailRow.checkedInAt">
                <AppIcon name="check-circle" :size="22" />
                <div><strong>Checked in</strong><p>{{ dateTimeLabel(detailRow.checkedInAt) }}</p></div>
              </template>
              <template v-else-if="!confirming">
                <AppIcon name="timer" :size="22" />
                <div><strong>Not checked in yet</strong><p>Check them in once they arrive at the venue.</p></div>
                <button type="button" class="at-btn at-btn--primary" :disabled="pendingCheckIn !== null" @click="startConfirm"><AppIcon name="user-check" :size="17" />Check in</button>
              </template>
              <template v-else>
                <AppIcon name="user-check" :size="22" />
                <div><strong>Check in {{ detailRow.name }}?</strong><p>Confirm this person is here<template v-if="detailRow.code"> with code <b>{{ detailRow.code }}</b></template>. This can’t be undone.</p></div>
                <div class="at-checkin__actions">
                  <button type="button" data-confirm class="at-btn at-btn--primary" :disabled="pendingCheckIn !== null" :aria-busy="pendingCheckIn === detailRow.key" @click="confirmCheckIn(detailRow)">{{ pendingCheckIn === detailRow.key ? 'Checking in…' : 'Confirm check-in' }}</button>
                  <button type="button" class="at-btn at-btn--ghost" :disabled="pendingCheckIn !== null" @click="confirming = false; checkInError = ''">Cancel</button>
                </div>
              </template>
            </section>
            <p v-if="checkInError" class="at-inline-error" role="alert">{{ checkInError }}</p>

            <section class="at-detail-group">
              <h3>Contact</h3>
              <dl>
                <div><dt>Email</dt><dd>{{ detailRow.email || '—' }}</dd></div>
                <div><dt>Phone</dt><dd>{{ detailRow.phone || '—' }}</dd></div>
                <div><dt>Registered</dt><dd>{{ dateTimeLabel(detailRow.registeredAt) }}</dd></div>
              </dl>
            </section>
            <section class="at-detail-group">
              <h3>Profile</h3>
              <dl>
                <div><dt>Gender</dt><dd>{{ detailRow.gender || '—' }}</dd></div>
                <div><dt>Age</dt><dd>{{ detailRow.age || '—' }}</dd></div>
                <div><dt>Ethnicity</dt><dd>{{ detailRow.ethnicity || '—' }}</dd></div>
                <div><dt>Disability</dt><dd>{{ detailRow.disabilities.join(', ') || 'None reported' }}</dd></div>
              </dl>
            </section>
            <section v-if="detailAnswers.length" class="at-detail-group">
              <h3>Registration answers</h3>
              <dl class="at-answers">
                <div v-for="answer in detailAnswers" :key="answer.label"><dt>{{ answer.label }}</dt><dd>{{ answer.value }}</dd></div>
              </dl>
            </section>
          </div>

          <footer class="at-drawer__foot">
            <RouterLink :to="`/admin/users/${detailRow.user.id}`" class="at-btn at-btn--ghost">Open full profile<AppIcon name="arrow-up-right" :size="16" /></RouterLink>
          </footer>
        </aside>
      </div>
    </Teleport>
  </AdminLayout>
</template>
