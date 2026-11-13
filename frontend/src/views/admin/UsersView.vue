<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { getUsers } from '../../services/adminService'
import { eventPhase as phaseOf } from '../../utils/eventStats'
import { ageCode, genderCode } from '../../utils/registrationCodes'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const eventsError = ref('')
const events = ref([])
const selectedEventId = ref(null)
const selectedYear = ref('')
const eventSearch = ref('')

const attendees = ref([])
const formFields = ref([])
const archivedFieldLabels = ref({})
const attendeesLoading = ref(false)
const attendeesError = ref('')

const search = ref('')
const ageFilter = ref('all')
const genderFilter = ref('all')
const ethnicityFilter = ref('all')
const disabilityFilter = ref('all')
const sortBy = ref('name-asc')
const currentPage = ref(1)
const pageSize = ref(10)

const detailKey = ref(null)
const editingRow = ref(null)
const deleteRow = ref(null)
const editForm = ref({})
const editError = ref('')
const deleteError = ref('')
const savingRegistrant = ref(false)
const deletingRegistrant = ref(false)
const showFilters = ref(false)
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

const buildRow = (user, registration) => {
  const ageGroup = ageCode(registration.age_group) || ageCode(pickAnswer(registration, ['age group', 'age'])) || ageCode(registration.age)
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
    gender: genderCode(pickAnswer(registration, ['gender'])) || genderCode(registration.gender),
    age: ageGroup,
    ageGroup,
    photoConsent: registration.photo_consent === true,
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
const selectedEvent = computed(() => events.value.find((event) => event.id === selectedEventId.value) || null)
const eventYearRange = (event) => {
  if (!event.starts || Number.isNaN(event.starts.getTime())) return null
  const end = event.ends && !Number.isNaN(event.ends.getTime()) && event.ends >= event.starts
    ? event.ends
    : event.starts
  return { first: event.starts.getFullYear(), last: end.getFullYear() }
}
const eventYears = computed(() => [...new Set(events.value
  .flatMap((event) => {
    const range = eventYearRange(event)
    return range ? Array.from({ length: range.last - range.first + 1 }, (_, index) => range.first + index) : []
  }))].sort((a, b) => b - a))
const yearEvents = computed(() => events.value.filter((event) => {
  const range = eventYearRange(event)
  const year = Number(selectedYear.value)
  return range && range.first <= year && range.last >= year
}))
const sortedYearEvents = computed(() => yearEvents.value.slice()
  .sort((a, b) => (a.starts?.getTime() ?? Number.MAX_SAFE_INTEGER) - (b.starts?.getTime() ?? Number.MAX_SAFE_INTEGER)))
const visibleEvents = computed(() => {
  const query = eventSearch.value.trim().toLowerCase()
  return sortedYearEvents.value
    .filter((event) => !query || `${event.name} ${event.location || ''}`.toLowerCase().includes(query))
})
const completedEventCount = computed(() => yearEvents.value.filter((event) => ['close', 'closed'].includes(String(event.status || '').toLowerCase())).length)
const ongoingEventCount = computed(() => yearEvents.value.filter((event) => ['open', 'published'].includes(String(event.status || '').toLowerCase())).length)

const rows = computed(() => attendees.value.flatMap((user) => (user.registrations || [])
  .filter((registration) => !selectedEventId.value || Number(registration.event_id) === Number(selectedEventId.value))
  .map((registration) => buildRow(user, registration))))
const totalCount = computed(() => rows.value.length)

const distinct = (values) => [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))
const ageOptions = computed(() => distinct(rows.value.map((row) => row.ageGroup)))
const genderOptions = computed(() => distinct(rows.value.map((row) => row.gender)))
const ethnicityOptions = computed(() => distinct(rows.value.map((row) => row.ethnicity)))
const disabilityOptions = computed(() => distinct(rows.value.flatMap((row) => row.disabilities)))
const activeFilterCount = computed(() => [ageFilter, genderFilter, ethnicityFilter, disabilityFilter].filter((filter) => filter.value !== 'all').length)
const hasNarrowing = computed(() => Boolean(search.value.trim()) || activeFilterCount.value > 0)

const matchesFilters = (row) => {
  const query = search.value.trim().toLowerCase()
  if (query && !`${row.name} ${row.email} ${row.phone} ${row.code}`.toLowerCase().includes(query)) return false
  if (ageFilter.value !== 'all' && row.ageGroup !== ageFilter.value) return false
  if (genderFilter.value !== 'all' && row.gender !== genderFilter.value) return false
  if (ethnicityFilter.value !== 'all' && row.ethnicity !== ethnicityFilter.value) return false
  if (disabilityFilter.value !== 'all' && !row.disabilities.includes(disabilityFilter.value)) return false
  return true
}
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
const filteredRows = computed(() => rows.value.filter(matchesFilters).sort(sorters[sortBy.value] || sorters['name-asc']))
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
watch([search, ageFilter, genderFilter, ethnicityFilter, disabilityFilter, sortBy, pageSize, selectedEventId], () => { currentPage.value = 1 })
watch(pageCount, (count) => { if (currentPage.value > count) currentPage.value = count })

const dateLabel = (date) => date ? date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : 'Date to be announced'
const dateRangeLabel = (event) => {
  if (!event?.starts) return dateLabel(null)
  if (!event.ends || event.starts.toDateString() === event.ends.toDateString()) return dateLabel(event.starts)
  return `${dateLabel(event.starts)} – ${dateLabel(event.ends)}`
}
const clock = (date) => date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
const timeRangeLabel = (event) => {
  if (!event?.starts) return ''
  if (!event.ends) return clock(event.starts)
  return `${clock(event.starts)} – ${clock(event.ends)}`
}
const dateTimeLabel = (date) => date ? date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'
const checkedTimeLabel = (date) => {
  if (!date) return ''
  return date.toDateString() === new Date(now.value).toDateString() ? clock(date) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
const initials = (name) => (name || 'U').trim().split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()
const eventTimeLabel = (event) => event?.starts
  ? `${clock(event.starts)}${event.ends ? ` – ${clock(event.ends)}` : ''}`
  : 'Time to be announced'

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
    archivedFieldLabels.value = {}
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
    archivedFieldLabels.value = formResponse?.data?.data?.config?.archived_fields || {}
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

const defaultYear = (eventId) => {
  const selected = events.value.find((event) => event.id === eventId)
  if (selected?.starts && !Number.isNaN(selected.starts.getTime())) return String(selected.starts.getFullYear())
  const currentYear = String(new Date(now.value).getFullYear())
  return eventYears.value.includes(Number(currentYear)) ? currentYear : String(eventYears.value[0] ?? '')
}

const loadWorkspace = async () => {
  loading.value = true
  eventsError.value = ''
  try {
    const { data } = await api.get('/admin/events', { timeout: 8000 })
    const list = data.data ?? data
    if (!Array.isArray(list)) throw new Error('The events response was not a list.')
    events.value = list.map(normalizeEvent)
  } catch (requestError) {
    let cached = []
    try {
      const saved = JSON.parse(localStorage.getItem('event_list') || '[]')
      if (Array.isArray(saved)) cached = saved.map(normalizeEvent)
    } catch { cached = [] }
    events.value = cached
    const status = requestError.response?.status
    const reason = status === 401
      ? 'Your session expired. Sign in again to load events.'
      : status === 403
        ? 'You don’t have permission to view these events.'
        : requestError.code === 'ECONNABORTED'
          ? 'Loading events took too long.'
          : 'We couldn’t load your events. Check your connection and try again.'
    eventsError.value = cached.length ? `${reason} Showing the last saved event list.` : reason
  }
  selectedEventId.value = defaultEventId()
  selectedYear.value = defaultYear(selectedEventId.value)
  loading.value = false
  await loadAttendees()
}

const resetFilters = () => {
  search.value = ''
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
const selectYear = async (year) => {
  selectedYear.value = year
  if (selectedEvent.value && eventYearRange(selectedEvent.value)?.first <= Number(year) && eventYearRange(selectedEvent.value)?.last >= Number(year)) return
  const nextEvent = sortedYearEvents.value[0]
  if (nextEvent) {
    await selectEvent(nextEvent)
    return
  }
  closeDetails()
  selectedEventId.value = null
  attendees.value = []
  formFields.value = []
  router.replace({ query: { ...route.query, event: null } }).catch(() => {})
}

// ----- Attendee details + check-in -----
const detailRow = computed(() => rows.value.find((row) => row.key === detailKey.value) || null)
const detailAnswers = computed(() => Object.entries(detailRow.value?.registration.form_values || {})
  .filter(([, value]) => !isBlank(value))
  .map(([label, value]) => {
    const normalized = normalizeKey(label)
    if (normalized === 'gender') return { label, value: genderCode(value) || '—' }
    if (['age', 'agegroup'].includes(normalized)) return { label, value: ageCode(value) || '—' }
    return { label, value: formatAnswer(value) }
  }))
const editableFormFields = computed(() => {
  const registration = editingRow.value?.registration
  if (!registration) return []
  const activeFields = formFields.value.filter((field) => field.type !== 'heading')
  const activeIds = new Set(activeFields.map((field) => String(field.id)))
  const labelsByKey = new Map(Object.entries(registration.form_values || {}).map(([label]) => {
    const key = Object.keys(registration.form_data || {}).find((id) => normalizeKey(id) === normalizeKey(label))
    return [key, label]
  }).filter(([key]) => key !== undefined))
  const archivedFields = Object.entries(registration.form_data || {})
    .filter(([id]) => !activeIds.has(String(id)))
    .map(([id, value]) => ({
      id,
      label: archivedFieldLabels.value[id] || labelsByKey.get(id) || id,
      type: 'text',
      required: false,
      options: [],
      settings: {},
      archived: true,
      initialValue: value,
    }))
  return [...activeFields, ...archivedFields]
})
const isMultipleField = (field) => Boolean(field.settings?.multiple) && ['checkbox', 'select'].includes(field.type)
const isSingleCheckbox = (field) => field.type === 'checkbox' && !isMultipleField(field)
const hasActiveAnswerField = (...labels) => editableFormFields.value.some((field) => !field.archived && labels.includes(normalizeKey(field.label)))
const editFieldValue = (field) => editForm.value.form_data?.[field.id]
const editFieldOptions = (field) => field.type === 'yesno' ? ['Yes', 'No'] : (field.options || [])
const editFieldType = (field) => ({
  email: 'email',
  phone: 'tel',
  number: 'number',
  date: 'date',
  time: 'time',
  url: 'url',
})[field.type] || 'text'
const storedFileLabel = (field) => {
  const value = editFieldValue(field)
  return typeof value === 'string' ? value.split(/[\\/]/).pop() : ''
}
const openDetails = async (row) => {
  returnFocus = document.activeElement
  detailKey.value = row.key
  await nextTick()
  document.querySelector('.at-drawer__close')?.focus()
}
function closeDetails() {
  if (detailKey.value === null) return
  detailKey.value = null
  const target = returnFocus
  returnFocus = null
  nextTick(() => target?.isConnected && target.focus())
}
const announce = (message) => {
  notice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => { notice.value = '' }, 5000)
}
const requestMessage = (error, fallback) => Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || fallback
const openEditRegistrant = async (row) => {
  closeDetails()
  editingRow.value = row
  editError.value = ''
  const rawGender = String(row.registration.gender || '').toLowerCase()
  const formData = { ...(row.registration.form_data || {}) }
  formFields.value.forEach((field) => {
    if (!(String(field.id) in formData)) return
    if (isSingleCheckbox(field)) {
      formData[field.id] = formData[field.id] === true || ['true', 'yes', '1'].includes(String(formData[field.id]).toLowerCase())
    } else if (isMultipleField(field)) {
      formData[field.id] = toList(formData[field.id])
    }
  })
  editForm.value = {
    full_name: row.registration.full_name || row.name,
    email: row.registration.email || row.email,
    phone: row.registration.phone || row.registration.telephone || row.phone,
    gender: ['male', 'female', 'other'].includes(rawGender) ? rawGender : rawGender === 'm' ? 'male' : rawGender === 'f' ? 'female' : rawGender === 'n' ? 'other' : '',
    age: row.registration.age ?? '',
    organization: row.registration.organization || '',
    position: row.registration.position || '',
    address: row.registration.address || '',
    emergency_contact_name: row.registration.emergency_contact_name || '',
    emergency_contact_phone: row.registration.emergency_contact_phone || '',
    disability_type: toList(row.registration.disability_type).map((value) => String(value).trim()).filter(Boolean),
    photo_consent: Boolean(row.registration.photo_consent),
    signature: row.registration.signature || '',
    form_data: formData,
    form_files: {},
  }
  await nextTick()
  document.querySelector('.at-modal input[name="full_name"]')?.focus()
}
const closeRegistrantDialog = () => {
  if (savingRegistrant.value || deletingRegistrant.value) return
  editingRow.value = null
  deleteRow.value = null
  editError.value = ''
  deleteError.value = ''
}
const saveRegistrant = async () => {
  if (!editingRow.value || savingRegistrant.value) return
  savingRegistrant.value = true
  editError.value = ''
  try {
    const payload = new FormData()
    payload.append('_method', 'PUT')
    payload.append('event_id', String(selectedEventId.value))
    payload.append('form_data[__empty]', '')
    const coreValues = {
      full_name: editForm.value.full_name,
      email: editForm.value.email,
      phone: editForm.value.phone,
      gender: editForm.value.gender,
      age: editForm.value.age === '' ? '' : String(Number(editForm.value.age)),
      organization: editForm.value.organization,
      position: editForm.value.position,
      address: editForm.value.address,
      emergency_contact_name: editForm.value.emergency_contact_name,
      emergency_contact_phone: editForm.value.emergency_contact_phone,
      photo_consent: editForm.value.photo_consent ? '1' : '0',
      signature: editForm.value.signature,
    }
    Object.entries(coreValues).forEach(([key, value]) => payload.append(key, value ?? ''))
    if (!hasActiveAnswerField('disability', 'disabilitytype') && !editForm.value.disability_type.length) {
      payload.append('disability_type[__empty]', '')
    } else {
      editForm.value.disability_type.forEach((value) => payload.append('disability_type[]', value))
    }
    editableFormFields.value.forEach((field) => {
      if (field.type === 'file') {
        const file = editForm.value.form_files[field.id]
        if (file) payload.append(`form_data[${field.id}]`, file)
        return
      }
      const value = editFieldValue(field)
      if (Array.isArray(value)) value.forEach((item) => payload.append(`form_data[${field.id}][]`, String(item)))
      else if (value !== undefined) payload.append(`form_data[${field.id}]`, value === null ? '' : String(value))
    })
    await api.post(`/admin/events/${selectedEventId.value}/registrants/${editingRow.value.registration.id}`, payload)
    editingRow.value = null
    await loadAttendees({ silent: true })
    announce('Registrant details saved.')
  } catch (error) {
    editError.value = requestMessage(error, 'Registrant details could not be saved. Check the fields and try again.')
  } finally {
    savingRegistrant.value = false
  }
}
const openDeleteRegistrant = (row) => {
  closeDetails()
  deleteRow.value = row
  deleteError.value = ''
}
const confirmDeleteRegistrant = async () => {
  if (!deleteRow.value || deletingRegistrant.value) return
  deletingRegistrant.value = true
  deleteError.value = ''
  try {
    await api.delete(`/admin/events/${selectedEventId.value}/registrants/${deleteRow.value.registration.id}`)
    deleteRow.value = null
    await loadAttendees({ silent: true })
    announce('Registrant deleted.')
  } catch (error) {
    deleteError.value = requestMessage(error, 'Registrant could not be deleted. Please try again.')
  } finally {
    deletingRegistrant.value = false
  }
}
const onKeydown = (event) => {
  if (event.key === 'Escape' && detailKey.value !== null) closeDetails()
  if (event.key === 'Escape' && (editingRow.value || deleteRow.value) && !savingRegistrant.value && !deletingRegistrant.value) closeRegistrantDialog()
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
    const baseHeaders = ['#', 'Full Name', 'Registration Code', 'Email', 'Phone', 'Gender', 'Age', 'Photo/Media', 'Ethnicity', 'Disability', 'Registered At', 'Status', 'Checked-in Time']
    const headers = [...baseHeaders, ...dynamicColumns.value.map((field) => field.label)]
    const rowsOut = filteredRows.value.map((row, index) => [
      index + 1,
      row.name,
      row.code || '-',
      row.email || '-',
      row.phone || '-',
      row.gender || '-',
      row.age || '-',
      row.photoConsent ? 'Yes' : 'No',
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
      { wch: 10 }, { wch: 12 }, { wch: 20 }, { wch: 30 }, { wch: 22 }, { wch: 16 }, { wch: 22 },
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

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[character]))
const exportFilteredPdf = () => {
  if (!filteredRows.value.length) return
  exportError.value = ''
  const popup = window.open('', '_blank')
  if (!popup) {
    exportError.value = 'Allow pop-ups to print or save the attendee list as a PDF.'
    return
  }
  popup.onload = () => { popup.focus(); popup.print() }
  const headers = ['#', 'Full name', 'Registration code', 'Email', 'Phone', 'Gender', 'Age', 'Photo/Media', 'Status', 'Checked-in time', ...dynamicColumns.value.map((field) => field.label)]
  const rowsHtml = filteredRows.value.map((row, index) => {
    const values = [index + 1, row.name, row.code || '—', row.email || '—', row.phone || '—', row.gender || '—', row.age || '—', row.photoConsent ? 'Yes' : 'No', row.checkedInAt ? 'Checked in' : 'Not checked in', row.checkedInAt ? dateTimeLabel(row.checkedInAt) : '—', ...dynamicColumns.value.map((field) => formatAnswer(formFieldAnswer(row.registration, field)))]
    return `<tr>${values.map((value) => `<td>${escapeHtml(value)}</td>`).join('')}</tr>`
  }).join('')
  const title = selectedEvent.value?.name || 'Attendee list'
  const date = selectedEvent.value ? dateRangeLabel(selectedEvent.value) : ''
  popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)} — Attendees</title><style>
    @page{size:landscape;margin:12mm}*{box-sizing:border-box}body{font:12px/1.4 Arial,sans-serif;color:#18324b;margin:0}h1{font-size:20px;margin:0 0 4px}p{margin:0 0 16px;color:#61758a}table{width:100%;border-collapse:collapse}th,td{padding:7px 8px;border:1px solid #d9e2e9;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#eaf3f1;color:#17365f;font-size:11px}tr:nth-child(even){background:#f7f9fb}thead{display:table-header-group}tr{break-inside:avoid}
    </style></head><body><h1>${escapeHtml(title)} — Attendees</h1><p>${escapeHtml(date)} · ${filteredRows.value.length} ${filteredRows.value.length === 1 ? 'attendee' : 'attendees'}</p><table><thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${rowsHtml}</tbody></table></body></html>`)
  popup.document.close()
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
        <h1>Event Detail</h1>
        <div v-if="!loading && events.length" class="at-event-list__year">
          <label for="at-event-year">Total event by year</label>
          <select id="at-event-year" :value="selectedYear" @change="selectYear($event.target.value)">
            <option v-for="year in eventYears" :key="year" :value="String(year)">{{ year }}</option>
          </select>
          <span class="at-event-list__total"><AppIcon name="calendar" :size="14" />Total: {{ yearEvents.length }} events</span>
        </div>
      </header>

      <p v-if="eventsError" class="at-alert" role="alert">
        <AppIcon name="alert-circle" :size="18" />
        <span>{{ eventsError }}</span>
        <button type="button" class="at-btn at-btn--ghost" :disabled="loading" @click="loadWorkspace">{{ loading ? 'Retrying…' : 'Retry' }}</button>
      </p>

      <template v-if="!loading && events.length">
        <section class="at-card at-event-list" aria-label="Event List">
          <div class="at-event-list__summary" aria-live="polite">
            <span><strong>{{ completedEventCount }}</strong> Completed Events</span>
            <span><strong>{{ ongoingEventCount }}</strong> On-going Events</span>
            <RouterLink to="/admin/events/new" class="at-btn at-btn--ghost at-btn--sm"><AppIcon name="plus" :size="16" />New Event</RouterLink>
          </div>
          <div class="at-event-list__toolbar">
            <label class="at-search">
              <AppIcon name="search" :size="15" />
              <span class="sr-only">Search events</span>
              <input v-model="eventSearch" type="search" placeholder="Search event…" />
            </label>
            <span class="at-event-list__hint"><i aria-hidden="true"></i>Click any event row to view registrants</span>
          </div>
          <div class="at-event-list__scroll" role="region" aria-label="Events" tabindex="0">
            <table class="at-event-table">
              <caption class="sr-only">Events for {{ selectedYear }}</caption>
              <thead><tr><th scope="col">Event title</th><th scope="col">Schedule</th><th scope="col">Location</th></tr></thead>
              <tbody>
                <tr v-for="event in visibleEvents" :key="event.id" :class="{ 'is-selected': event.id === selectedEventId }" @click="selectEvent(event)">
                  <td>
                    <button type="button" class="at-event-table__select" :aria-pressed="event.id === selectedEventId" @click.stop="selectEvent(event)">
                      {{ event.name }}
                      <span v-if="event.id === selectedEventId" class="at-event-table__selected">Selected</span>
                    </button>
                  </td>
                  <td><span class="at-event-table__schedule"><AppIcon name="clock" :size="14" />{{ dateRangeLabel(event) }}<template v-if="event.starts"> · {{ eventTimeLabel(event) }}</template></span></td>
                  <td><span class="at-event-table__location"><AppIcon name="pin" :size="14" />{{ event.location || 'Location to be announced' }}</span></td>
                </tr>
                <tr v-if="!visibleEvents.length"><td colspan="3" class="at-event-table__empty">No events match this year and search.</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <template v-if="selectedEvent">
          <section class="at-card at-roster" aria-labelledby="at-roster-title">
            <header class="at-roster__head at-roster__head--reference">
              <div class="at-roster__title">
                <h2 id="at-roster-title">Registrant List</h2>
                <span class="at-count">{{ attendeesLoading ? '…' : totalCount }} {{ totalCount === 1 ? 'Registrant' : 'Registrants' }}</span>
              </div>
              <div class="at-overview__actions at-reference-actions">
                <button type="button" class="at-btn at-btn--secondary at-btn--sm" :disabled="exporting || attendeesLoading || !filteredRows.length" :aria-busy="exporting" @click="exportFilteredExcel">
                  <AppIcon name="download" :size="15" />{{ exporting ? 'Exporting…' : 'Excel' }}
                </button>
                <button type="button" class="at-btn at-btn--secondary at-btn--sm" aria-label="Export filtered registrants to PDF" :disabled="attendeesLoading || !filteredRows.length" @click="exportFilteredPdf"><AppIcon name="file-text" :size="15" />PDF</button>
              </div>
            </header>

            <p v-if="exportError" class="at-inline-error at-reference-error" role="alert">{{ exportError }}</p>
            <p v-if="filteredRows.length && hasNarrowing" class="at-export-note at-reference-export-note">Exports include the {{ filteredRows.length }} registrants currently matching your filters.</p>

            <div class="at-toolbar at-toolbar--reference">
              <label class="at-search at-search--wide">
                <AppIcon name="search" :size="16" />
                <span class="sr-only">Search registrants</span>
                <input v-model="search" type="search" placeholder="Search name, email, phone or code" :disabled="attendeesLoading" />
              </label>
              <label class="at-select at-sort-select">
                <AppIcon name="sort" :size="15" />
                <span class="sr-only">Sort registrants</span>
                <select v-model="sortBy">
                  <option v-for="option in sortOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
              </label>
              <button v-if="ageOptions.length || genderOptions.length || ethnicityOptions.length || disabilityOptions.length" type="button" class="at-btn at-btn--ghost at-btn--sm at-filter-toggle" :aria-expanded="showFilters" @click="showFilters = !showFilters">
                <AppIcon name="filter-x" :size="15" />Filters<span v-if="activeFilterCount">{{ activeFilterCount }}</span>
              </button>
            </div>
            <div v-if="showFilters && (ageOptions.length || genderOptions.length || ethnicityOptions.length || disabilityOptions.length)" class="at-filters at-filters--reference">
              <label v-if="ageOptions.length" class="at-select" :class="{ 'is-active': ageFilter !== 'all' }">
                <span class="sr-only">Filter by age</span><select v-model="ageFilter"><option value="all">Any age</option><option v-for="option in ageOptions" :key="option" :value="option">{{ option }}</option></select>
              </label>
              <label v-if="genderOptions.length" class="at-select" :class="{ 'is-active': genderFilter !== 'all' }">
                <span class="sr-only">Filter by gender</span><select v-model="genderFilter"><option value="all">Any gender</option><option v-for="option in genderOptions" :key="option" :value="option">{{ option }}</option></select>
              </label>
              <label v-if="ethnicityOptions.length" class="at-select" :class="{ 'is-active': ethnicityFilter !== 'all' }">
                <span class="sr-only">Filter by ethnicity</span><select v-model="ethnicityFilter"><option value="all">Any ethnicity</option><option v-for="option in ethnicityOptions" :key="option" :value="option">{{ option }}</option></select>
              </label>
              <label v-if="disabilityOptions.length" class="at-select" :class="{ 'is-active': disabilityFilter !== 'all' }">
                <span class="sr-only">Filter by disability</span><select v-model="disabilityFilter"><option value="all">Any disability</option><option v-for="option in disabilityOptions" :key="option" :value="option">{{ option }}</option></select>
              </label>
              <button v-if="hasNarrowing" type="button" class="at-btn at-btn--text" @click="clearFilters"><AppIcon name="filter-x" :size="15" />Clear filters</button>
            </div>

            <div v-if="attendeesLoading" class="at-table-loading" aria-busy="true">
              <span class="sr-only" role="status">Loading registrants…</span>
              <div v-for="n in 4" :key="n" class="at-skeleton at-skeleton--row" />
            </div>
            <div v-else-if="attendeesError" class="at-state" role="alert">
              <AppIcon name="alert-circle" :size="26" /><strong>{{ attendeesError }}</strong><p>Check your connection and try again.</p>
              <button type="button" class="at-btn at-btn--secondary" @click="loadAttendees()"><AppIcon name="refresh" :size="16" />Try again</button>
            </div>
            <div v-else-if="!rows.length" class="at-state">
              <AppIcon name="users" :size="26" /><strong>No one has registered yet</strong><p>Attendees will appear here as soon as people sign up for this event.</p>
            </div>
            <div v-else-if="!filteredRows.length" class="at-state">
              <AppIcon name="search" :size="26" /><strong>No registrants match</strong><p>Try a different search or clear the filters.</p>
              <button type="button" class="at-btn at-btn--secondary" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
            </div>
            <template v-else>
              <p class="sr-only" role="status">Showing {{ pageStart }} to {{ pageEnd }} of {{ filteredRows.length }} registrants</p>
              <div class="at-table-wrap at-table-wrap--reference">
                <table class="at-table at-table--reference">
                  <caption class="sr-only">Registrants for {{ selectedEvent.name }}</caption>
                  <thead><tr>
                    <th scope="col" :aria-sort="ariaSort('name')"><button type="button" class="at-sort" @click="sortColumn('name')">Name<AppIcon :name="ariaSort('name') === 'descending' ? 'arrow-down' : ariaSort('name') === 'ascending' ? 'arrow-up' : 'sort'" :size="13" /></button></th>
                    <th scope="col">Gender</th>
                    <th scope="col">Age Group</th>
                    <th scope="col">Photo/Media</th>
                    <th scope="col" :aria-sort="ariaSort('registered')"><button type="button" class="at-sort" @click="sortColumn('registered')">Registered Date<AppIcon :name="ariaSort('registered') === 'descending' ? 'arrow-down' : ariaSort('registered') === 'ascending' ? 'arrow-up' : 'sort'" :size="13" /></button></th>
                    <th scope="col" class="at-reference-actions-heading">Actions</th>
                  </tr></thead>
                  <tbody>
                    <tr v-for="row in pageRows" :key="row.key">
                      <td class="at-cell-person">
                        <div class="at-person">
                          <span class="at-avatar" aria-hidden="true">{{ initials(row.name) }}</span>
                          <span class="at-person__text"><strong>{{ row.name }}</strong><small>{{ row.email || row.phone || 'No contact details' }}</small></span>
                        </div>
                      </td>
                      <td data-label="Gender"><span class="at-code-badge">{{ row.gender || '—' }}</span></td>
                      <td data-label="Age Group"><span class="at-code-badge">{{ row.age || '—' }}</span></td>
                      <td data-label="Photo/Media"><span class="at-consent-badge" :class="row.photoConsent ? 'is-yes' : 'is-no'">Photo/Media: {{ row.photoConsent ? 'Yes' : 'No' }}</span></td>
                      <td data-label="Registered Date" class="at-reference-time"><time v-if="row.registeredAt" :datetime="row.registeredAt.toISOString()">{{ row.registeredAt.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }) }}</time><template v-else>—</template></td>
                      <td class="at-cell-actions at-reference-row-actions">
                        <button type="button" class="at-icon-action" :aria-label="`View ${row.name}`" title="View" @click="openDetails(row)"><AppIcon name="eye" :size="15" /></button>
                        <button type="button" class="at-icon-action is-edit" :aria-label="`Edit ${row.name}`" title="Edit" @click="openEditRegistrant(row)"><AppIcon name="pen" :size="15" /></button>
                        <button type="button" class="at-icon-action is-delete" :aria-label="`Delete ${row.name}`" title="Delete" @click="openDeleteRegistrant(row)"><AppIcon name="trash" :size="15" /></button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <footer class="at-pagination at-pagination--reference">
                <p>Showing {{ pageStart }} to {{ pageEnd }} of {{ filteredRows.length }} registrants</p>
                <nav class="at-pager" aria-label="Registrant pages">
                  <button type="button" :disabled="currentPage === 1" aria-label="Previous page" @click="goToPage(currentPage - 1)"><AppIcon name="chevron-left" :size="15" /></button>
                  <template v-for="item in pageItems" :key="item">
                    <span v-if="typeof item === 'string'" class="at-pager__gap" aria-hidden="true">…</span>
                    <button v-else type="button" :class="{ 'is-current': item === currentPage }" :aria-current="item === currentPage ? 'page' : undefined" :aria-label="`Page ${item}`" @click="goToPage(item)">{{ item }}</button>
                  </template>
                  <button type="button" :disabled="currentPage === pageCount" aria-label="Next page" @click="goToPage(currentPage + 1)"><AppIcon name="chevron-right" :size="15" /></button>
                </nav>
              </footer>
            </template>
          </section>
        </template>
        <div v-else class="at-card at-state at-state--page">
          <AppIcon name="calendar-plus" :size="28" /><strong>Select an event</strong><p>Choose an event above to see its registrants.</p>
        </div>
      </template>

      <div v-else-if="loading" class="at-loading-workspace" role="status" aria-label="Loading event details">
        <div class="at-loading-picker"><span></span><i></i><b></b></div>
        <div class="at-loading-summary"><span></span><b></b><i></i><i></i></div>
        <div class="at-loading-roster">
          <div class="at-loading-roster__head"><b></b><span></span></div>
          <div class="at-loading-roster__toolbar"><i></i><i></i></div>
          <div v-for="row in 4" :key="row" class="at-loading-roster__row"><i></i><span></span><b></b><b></b><em></em></div>
        </div>
        <span class="sr-only">Loading events…</span>
      </div>
      <div v-else class="at-card at-state at-state--page">
        <AppIcon name="calendar-plus" :size="28" /><strong>No events yet</strong><p>Create an event to start collecting registrations.</p>
        <RouterLink to="/admin/events/new" class="at-btn at-btn--primary"><AppIcon name="plus" :size="16" />Create event</RouterLink>
      </div>

      <p class="at-toast" :class="{ 'is-visible': notice }" role="status" aria-live="polite"><AppIcon v-if="notice" name="check-circle" :size="18" />{{ notice }}</p>
    </section>

    <Teleport to="body">
      <div v-if="detailRow" class="at-drawer">
        <div class="at-drawer__scrim" @click="closeDetails" />
        <aside class="at-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="at-drawer-title">
          <header class="at-drawer__head">
            <span class="at-avatar at-avatar--lg" aria-hidden="true">{{ initials(detailRow.name) }}</span>
            <div>
              <h2 id="at-drawer-title">{{ detailRow.name }}</h2>
              <p v-if="detailRow.code" class="at-code">{{ detailRow.code }}</p>
            </div>
            <button type="button" class="at-drawer__close" aria-label="Close details" @click="closeDetails"><AppIcon name="x" :size="20" /></button>
          </header>

          <div class="at-drawer__body">
            <section v-if="detailRow.checkedInAt" class="at-checkin is-in" aria-live="polite">
                <AppIcon name="check-circle" :size="22" />
                <div><strong>Checked in</strong><p>{{ dateTimeLabel(detailRow.checkedInAt) }}</p></div>
            </section>

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
                <div><dt>Photo/Media consent</dt><dd>{{ detailRow.photoConsent ? 'Yes' : 'No' }}</dd></div>
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
            <button type="button" class="at-btn at-btn--secondary" @click="openEditRegistrant(detailRow)"><AppIcon name="pen" :size="16" />Edit registrant</button>
            <button type="button" class="at-btn at-btn--danger" @click="openDeleteRegistrant(detailRow)"><AppIcon name="trash" :size="16" />Delete registrant</button>
          </footer>
        </aside>
      </div>
      <div v-if="editingRow" class="at-modal-backdrop" @click.self="closeRegistrantDialog">
        <section class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-edit-title">
          <header class="at-modal__head">
            <div><h2 id="at-edit-title">Edit registrant</h2><p>Edit the answers submitted on the registration form.</p></div>
            <button type="button" class="at-modal__close" aria-label="Close dialog" :disabled="savingRegistrant" @click="closeRegistrantDialog"><AppIcon name="x" :size="18" /></button>
          </header>
          <form class="at-modal__form" @submit.prevent="saveRegistrant">
            <section class="at-edit-answers">
              <h3>Registration answers</h3>
              <p v-if="!editableFormFields.length" class="at-edit-empty">No registration answers are available to edit.</p>
              <template v-for="field in editableFormFields" :key="field.id">
                <fieldset v-if="isMultipleField(field)" class="at-edit-checks">
                  <legend>{{ field.label }}<span v-if="field.required"> *</span></legend>
                  <label v-for="option in editFieldOptions(field)" :key="option">
                    <input v-model="editForm.form_data[field.id]" type="checkbox" :value="option" />
                    <span>{{ option }}</span>
                  </label>
                  <small v-if="field.description">{{ field.description }}</small>
                </fieldset>
                <label v-else-if="isSingleCheckbox(field)" class="at-edit-check">
                  <input v-model="editForm.form_data[field.id]" type="checkbox" />
                  <span>{{ field.settings?.checkbox_text || field.label }}</span>
                </label>
                <label v-else-if="field.type === 'file'">
                  {{ field.label }}<span v-if="field.required"> *</span>
                  <small v-if="storedFileLabel(field)">Current file: {{ storedFileLabel(field) }} (leave blank to keep it)</small>
                  <input type="file" :accept="(field.settings?.allowed_types || []).map((type) => `.${type}`).join(',')" @change="editForm.form_files[field.id] = $event.target.files?.[0] || null" />
                  <small v-if="field.description">{{ field.description }}</small>
                </label>
                <label v-else-if="field.type === 'textarea'">
                  {{ field.label }}<span v-if="field.required"> *</span><textarea v-model="editForm.form_data[field.id]" :placeholder="field.placeholder" rows="3"></textarea>
                  <small v-if="field.description">{{ field.description }}</small>
                </label>
                <label v-else-if="['select', 'radio', 'yesno', 'country'].includes(field.type)">
                  {{ field.label }}<span v-if="field.required"> *</span>
                  <select v-model="editForm.form_data[field.id]" :multiple="isMultipleField(field)">
                    <option v-if="!field.required && !isMultipleField(field)" value="">Not provided</option>
                    <option v-for="option in editFieldOptions(field)" :key="option" :value="option">{{ option }}</option>
                  </select>
                  <small v-if="field.description">{{ field.description }}</small>
                </label>
                <label v-else>
                  {{ field.label }}<span v-if="field.required"> *</span><input v-model="editForm.form_data[field.id]" :type="editFieldType(field)" :placeholder="field.placeholder" />
                  <small v-if="field.description">{{ field.description }}</small>
                </label>
              </template>
            </section>
            <p v-if="editError" class="at-inline-error" role="alert">{{ editError }}</p>
            <footer class="at-modal__actions">
              <button type="button" class="at-btn at-btn--ghost" :disabled="savingRegistrant" @click="closeRegistrantDialog">Cancel</button>
              <button type="submit" class="at-btn at-btn--primary" :disabled="savingRegistrant">{{ savingRegistrant ? 'Saving…' : 'Save changes' }}</button>
            </footer>
          </form>
        </section>
      </div>
      <div v-if="deleteRow" class="at-modal-backdrop" @click.self="closeRegistrantDialog">
        <section class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-delete-title">
          <header class="at-modal__head">
            <div><h2 id="at-delete-title">Delete registrant?</h2><p>This removes {{ deleteRow.name }} from this event.</p></div>
            <button type="button" class="at-modal__close" aria-label="Close dialog" :disabled="deletingRegistrant" @click="closeRegistrantDialog"><AppIcon name="x" :size="18" /></button>
          </header>
          <p class="at-modal__warning">This action cannot be undone. Any check-in record for this registrant will also be deleted.</p>
          <p v-if="deleteError" class="at-inline-error" role="alert">{{ deleteError }}</p>
          <footer class="at-modal__actions">
            <button type="button" class="at-btn at-btn--ghost" :disabled="deletingRegistrant" @click="closeRegistrantDialog">Cancel</button>
            <button type="button" class="at-btn at-btn--danger" :disabled="deletingRegistrant" @click="confirmDeleteRegistrant">{{ deletingRegistrant ? 'Deleting…' : 'Delete registrant' }}</button>
          </footer>
        </section>
      </div>
    </Teleport>
  </AdminLayout>
</template>
