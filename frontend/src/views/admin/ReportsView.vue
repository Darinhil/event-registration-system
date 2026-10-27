<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { getEventReportAttendees, getReports } from '../../services/adminService'
import { formatDate } from '../../utils/formatDate'
import { eventPhase, phaseLabels } from '../../utils/eventStats'

const route = useRoute()
const router = useRouter()

/** Write rows as a styled .xlsx: bold header, sized columns, autofilter, frozen header row. */
const saveXlsx = async (headers, rows, fileName, sheetName, columnWidths) => {
  const XLSX = await import('xlsx')
  const worksheet = XLSX.utils.aoa_to_sheet([headers, ...rows])
  worksheet['!cols'] = columnWidths || headers.map(() => ({ wch: 18 }))
  worksheet['!autofilter'] = { ref: `A1:${XLSX.utils.encode_col(headers.length - 1)}${rows.length + 1}` }
  worksheet['!freeze'] = { xSplit: 0, ySplit: 1 }
  headers.forEach((_, columnIndex) => {
    const cell = worksheet[XLSX.utils.encode_cell({ r: 0, c: columnIndex })]
    if (cell) cell.s = { font: { bold: true, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '0B7F62' } }, alignment: { horizontal: 'center' } }
  })
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, sheetName)
  XLSX.writeFile(workbook, `${fileName}.xlsx`, { cellStyles: true })
}

const excelDate = (value) => (value ? formatDate(value) : '')
const today = () => new Date().toISOString().slice(0, 10)

const loading = ref(true)
const error = ref('')
const reports = ref([])
// Filters live in the URL so they survive a refresh or a trip to another page and back.
const searchQuery = ref(typeof route.query.q === 'string' ? route.query.q : '')
const statusFilter = ref(typeof route.query.status === 'string' ? route.query.status : 'all')
const timingFilter = ref(typeof route.query.when === 'string' ? route.query.when : 'all')
const exporting = ref(false)
const notice = ref('')
const exportError = ref('')

const selectedEvent = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const attendeeFilter = ref('all')
const attendeeSearch = ref('')
const attendeePanel = ref(null)
let detailRequest = 0

const statusLabel = (value) => ({
  published: 'Published',
  closed: 'Closed',
  cancelled: 'Cancelled',
  draft: 'Draft',
}[value] || (value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Unknown'))
// Only offer statuses that actually exist in the report.
const statusOptions = computed(() => [...new Set(reports.value.map((row) => row.status).filter(Boolean))].sort().map((value) => ({ value, label: statusLabel(value) })))
const timingOptions = [
  { value: 'all', label: 'Any time' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'ongoing', label: 'Happening now' },
  { value: 'past', label: 'Ended' },
]

const phaseOf = (row) => eventPhase(row)
const hasStarted = (row) => ['ongoing', 'past'].includes(phaseOf(row))
const dateLine = (value) => value ? new Date(value).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : 'Date to be confirmed'
const timeLine = (row) => {
  if (!row.starts_at) return ''
  const clock = (value) => new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
  if (!row.ends_at) return clock(row.starts_at)
  const sameDay = new Date(row.starts_at).toDateString() === new Date(row.ends_at).toDateString()
  return sameDay ? `${clock(row.starts_at)} – ${clock(row.ends_at)}` : `${clock(row.starts_at)} – ${new Date(row.ends_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`
}

const filteredReports = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return reports.value.filter((row) => {
    const haystack = `${row.name} ${row.location || ''} ${statusLabel(row.status)} ${phaseLabels[phaseOf(row)]} ${dateLine(row.starts_at)}`.toLowerCase()
    return (!query || haystack.includes(query))
      && (statusFilter.value === 'all' || row.status === statusFilter.value)
      && (timingFilter.value === 'all' || phaseOf(row) === timingFilter.value)
  })
})
const hasFilters = computed(() => Boolean(searchQuery.value.trim()) || statusFilter.value !== 'all' || timingFilter.value !== 'all')
const clearFilters = () => { searchQuery.value = ''; statusFilter.value = 'all'; timingFilter.value = 'all' }
watch([searchQuery, statusFilter, timingFilter], () => {
  const query = { ...route.query, q: searchQuery.value.trim() || undefined, status: statusFilter.value !== 'all' ? statusFilter.value : undefined, when: timingFilter.value !== 'all' ? timingFilter.value : undefined }
  router.replace({ query }).catch(() => {})
})

// KPI totals use the same rows the table shows, so cards and table always reconcile.
const totals = computed(() => filteredReports.value.reduce((sum, row) => {
  const registrations = Number(row.registrations || 0)
  const checkedIn = Number(row.checked_in || 0)
  const phase = phaseOf(row)
  return {
    events: sum.events + 1,
    upcoming: sum.upcoming + (phase === 'upcoming' || phase === 'draft' || phase === 'unscheduled' ? 1 : 0),
    ongoing: sum.ongoing + (phase === 'ongoing' ? 1 : 0),
    ended: sum.ended + (phase === 'past' ? 1 : 0),
    registrations: sum.registrations + registrations,
    capacity: sum.capacity + Number(row.capacity || 0),
    checked_in: sum.checked_in + checkedIn,
    no_show: sum.no_show + Number(row.no_show || 0),
    startedRegistrations: sum.startedRegistrations + (hasStarted(row) ? registrations : 0),
    startedCheckedIn: sum.startedCheckedIn + (hasStarted(row) ? checkedIn : 0),
    started: sum.started + (hasStarted(row) ? 1 : 0),
  }
}, { events: 0, upcoming: 0, ongoing: 0, ended: 0, registrations: 0, capacity: 0, checked_in: 0, no_show: 0, startedRegistrations: 0, startedCheckedIn: 0, started: 0 }))
// Attendance rate = checked in ÷ registered, for events that have started (future events would drag it to 0).
const overallRate = computed(() => totals.value.startedRegistrations ? Math.round((totals.value.startedCheckedIn / totals.value.startedRegistrations) * 1000) / 10 : null)

const rateTone = (rate) => (Number(rate) >= 70 ? 'good' : Number(rate) >= 40 ? 'mid' : 'low')
const rateWord = { good: 'Strong', mid: 'Moderate', low: 'Low' }
const capacityPercent = (row) => row.capacity ? Math.min(100, Math.round((Number(row.registrations || 0) / Number(row.capacity)) * 100)) : null

const loadReports = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getReports()
    reports.value = data.data || data || []
    if (statusFilter.value !== 'all' && !reports.value.some((row) => row.status === statusFilter.value)) statusFilter.value = 'all'
    const requested = reports.value.find((row) => String(row.id) === String(route.query.event))
    if (requested && !selectedEvent.value) selectEvent(requested, { scroll: false })
  } catch {
    error.value = 'We couldn’t load the reports. Check your connection or admin session and try again.'
  } finally {
    loading.value = false
  }
}

const flash = (message) => {
  notice.value = message
  setTimeout(() => { if (notice.value === message) notice.value = '' }, 4000)
}

const exportReport = async () => {
  if (exporting.value || !filteredReports.value.length) return
  exporting.value = true
  exportError.value = ''
  try {
    const headers = ['No.', 'Event', 'Starts', 'Ends', 'Location', 'Status', 'Timing', 'Capacity', 'Registrations', 'Checked In', 'Not Checked In', 'No Show', 'Attendance Rate %']
    const rows = filteredReports.value.map((row, index) => [
      index + 1,
      row.name,
      excelDate(row.starts_at),
      excelDate(row.ends_at),
      row.location || '',
      statusLabel(row.status),
      phaseLabels[phaseOf(row)],
      row.capacity ?? '',
      Number(row.registrations || 0),
      Number(row.checked_in || 0),
      Number(row.not_checked_in ?? Math.max(Number(row.registrations || 0) - Number(row.checked_in || 0), 0)),
      row.no_show ?? 'Not ended',
      hasStarted(row) ? Number(row.attendance_rate || 0) : 'Not started',
    ])
    await saveXlsx(headers, rows, `event-reports${hasFilters.value ? '-filtered' : ''}-${today()}`, 'Event Reports', [
      { wch: 5 }, { wch: 36 }, { wch: 20 }, { wch: 20 }, { wch: 30 }, { wch: 12 }, { wch: 14 }, { wch: 10 }, { wch: 13 }, { wch: 11 }, { wch: 14 }, { wch: 11 }, { wch: 17 },
    ])
    flash(`Exported ${rows.length} ${rows.length === 1 ? 'event' : 'events'} to Excel.`)
  } catch {
    exportError.value = 'The Excel file couldn’t be created. Please try again.'
  } finally {
    exporting.value = false
  }
}

/* ---------- Per-event attendee report ---------- */

const loadAttendees = async () => {
  if (!selectedEvent.value) return
  const ticket = ++detailRequest
  const eventId = selectedEvent.value.id
  detailLoading.value = true
  detailError.value = ''
  try {
    const { data } = await getEventReportAttendees(eventId)
    // Ignore a slow response for an event the user has already moved away from.
    if (ticket === detailRequest) detail.value = data.data || data
  } catch {
    if (ticket === detailRequest) detailError.value = 'We couldn’t load the attendee list for this event.'
  } finally {
    if (ticket === detailRequest) detailLoading.value = false
  }
}

async function selectEvent(row, { scroll = true } = {}) {
  if (selectedEvent.value?.id === row.id) {
    if (scroll) attendeePanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    return
  }
  selectedEvent.value = row
  detail.value = null
  expandedId.value = null
  attendeeFilter.value = 'all'
  attendeeSearch.value = ''
  router.replace({ query: { ...route.query, event: row.id } }).catch(() => {})
  const loadingList = loadAttendees()
  if (scroll) {
    await nextTick()
    attendeePanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    attendeePanel.value?.focus({ preventScroll: true })
  }
  await loadingList
}
// Row clicks open the report too, but never when the click came from a control inside the row.
const onRowClick = (event, row) => {
  if (event.target.closest('button, a, input, select')) return
  selectEvent(row)
}

const closeAttendees = () => {
  const id = selectedEvent.value?.id
  detailRequest++
  selectedEvent.value = null
  detail.value = null
  expandedId.value = null
  attendeeFilter.value = 'all'
  attendeeSearch.value = ''
  detailLoading.value = false
  router.replace({ query: { ...route.query, event: undefined } }).catch(() => {})
  nextTick(() => document.querySelector(`[data-report-action="${id}"]`)?.focus())
}

const summary = computed(() => detail.value?.summary || { total: 0, checked_in: 0, not_checked_in: 0 })

const expandedId = ref(null)
const toggleProfile = (row) => { expandedId.value = expandedId.value === row.id ? null : row.id }
const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '?'
const avatarHue = (name) => Array.from(String(name || '')).reduce((hash, char) => (hash * 31 + char.charCodeAt(0)) % 360, 7)
const profileEntries = (row) => Object.entries(row.profile || {})
const formEntries = (row) => Object.entries(row.form_answers || {})
const hasProfileData = (row) => profileEntries(row).length > 0 || formEntries(row).length > 0

const attendeeRows = computed(() => {
  const query = attendeeSearch.value.trim().toLowerCase()
  return (detail.value?.attendees || []).filter((row) => {
    const checked = Boolean(row.checked_in_at)
    const matchesSegment = attendeeFilter.value === 'all' || (attendeeFilter.value === 'in' ? checked : !checked)
    const matchesQuery = !query || `${row.name} ${row.email} ${row.phone} ${row.registration_code}`.toLowerCase().includes(query)
    return matchesSegment && matchesQuery
  })
})

const segmentMeta = computed(() => ({
  all: { label: 'all attendees', file: 'all', short: 'All' },
  in: { label: 'checked-in attendees', file: 'checked-in', short: 'Checked-in' },
  out: { label: 'not yet checked in', file: 'not-checked-in', short: 'Not-checked-in' },
})[attendeeFilter.value])

const exportAttendeesCsv = async () => {
  const headers = ['No.', 'Name', 'Email', 'Phone', 'Registration Code', 'Registration Status', 'Checked In', 'Checked In At', 'Checked In By']
  const rows = attendeeRows.value.map((row, index) => [
    index + 1,
    row.name,
    row.email || '',
    row.phone || '',
    row.registration_code || '',
    row.status || '',
    row.checked_in_at ? 'Yes' : 'No',
    excelDate(row.checked_in_at),
    row.checked_in_at ? (row.checked_in_by || 'Self check-in') : '',
  ])
  const slug = String(selectedEvent.value?.name || `event-${selectedEvent.value?.id}`).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
  try {
    await saveXlsx(headers, rows, `${slug}-attendees-${segmentMeta.value.file}-${today()}`, 'Attendees', [
      { wch: 5 }, { wch: 26 }, { wch: 28 }, { wch: 16 }, { wch: 28 }, { wch: 16 }, { wch: 11 }, { wch: 22 }, { wch: 16 },
    ])
    flash(`Exported ${rows.length} ${rows.length === 1 ? 'attendee' : 'attendees'} to Excel.`)
  } catch {
    detailError.value = 'The Excel file couldn’t be created. Please try again.'
  }
}

onMounted(loadReports)
</script>

<template>
  <AdminLayout>
    <section class="reports-page rp-page">
      <header class="rp-head">
        <div>
          <h1>Reports</h1>
          <p>Registrations and attendance for {{ hasFilters ? 'the events shown below' : 'all your events' }}.</p>
        </div>
        <button type="button" class="rp-btn rp-btn--secondary" :disabled="loading || exporting || !filteredReports.length" :aria-busy="exporting" :title="hasFilters ? 'Exports only the events matching your filters' : 'Exports every event in the report'" @click="exportReport">
          <AppIcon name="download" :size="17" />{{ exporting ? 'Exporting…' : hasFilters ? `Export ${filteredReports.length} to Excel` : 'Export to Excel' }}
        </button>
      </header>

      <p v-if="error" class="rp-alert" role="alert">
        <AppIcon name="alert-circle" :size="18" /><span>{{ error }}</span>
        <button type="button" class="rp-btn rp-btn--secondary rp-btn--sm" :disabled="loading" @click="loadReports">{{ loading ? 'Retrying…' : 'Try again' }}</button>
      </p>
      <p v-if="exportError" class="rp-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ exportError }}</span></p>

      <div v-if="loading" class="rp-kpis" aria-busy="true"><div v-for="n in 4" :key="n" class="rp-kpi rp-skeleton" /></div>
      <dl v-else-if="!error" class="rp-kpis">
        <div class="rp-kpi">
          <dt><span class="rp-kpi__icon"><AppIcon name="calendar" :size="18" /></span>Events</dt>
          <dd>{{ totals.events.toLocaleString() }}</dd>
          <p>{{ totals.upcoming }} upcoming · {{ totals.ongoing }} now · {{ totals.ended }} ended</p>
        </div>
        <div class="rp-kpi">
          <dt><span class="rp-kpi__icon"><AppIcon name="users" :size="18" /></span>Registrations</dt>
          <dd>{{ totals.registrations.toLocaleString() }}</dd>
          <p>{{ totals.capacity ? `${Math.round(totals.registrations / totals.capacity * 100)}% of ${totals.capacity.toLocaleString()} spots filled` : 'No capacity limits set' }}</p>
        </div>
        <div class="rp-kpi rp-kpi--in">
          <dt><span class="rp-kpi__icon"><AppIcon name="user-check" :size="18" /></span>Checked in</dt>
          <dd>{{ totals.checked_in.toLocaleString() }}</dd>
          <p>{{ totals.ended ? `${totals.no_show.toLocaleString()} ${totals.no_show === 1 ? 'no-show' : 'no-shows'} at ended events` : 'No events have ended yet' }}</p>
        </div>
        <div class="rp-kpi" :class="overallRate !== null ? `rp-kpi--${rateTone(overallRate)}` : ''">
          <dt><span class="rp-kpi__icon"><AppIcon name="chart-pie" :size="18" /></span>Attendance rate</dt>
          <dd>{{ overallRate === null ? '—' : `${overallRate}%` }}</dd>
          <p :title="'Checked in ÷ registered, counting only events that have started'">{{ overallRate === null ? 'No events have started yet' : `Checked in ÷ registered · ${totals.started} started ${totals.started === 1 ? 'event' : 'events'}` }}</p>
        </div>
      </dl>

      <section class="rp-card rp-events" aria-labelledby="rp-events-title">
        <header class="rp-toolbar">
          <div>
            <h2 id="rp-events-title">Per-event breakdown</h2>
            <p v-if="!loading && !error" aria-live="polite">{{ filteredReports.length }} of {{ reports.length }} {{ reports.length === 1 ? 'event' : 'events' }} · select an event to see its attendees</p>
          </div>
          <div class="rp-filters">
            <label class="rp-search">
              <AppIcon name="search" :size="17" />
              <span class="sr-only">Search events</span>
              <input v-model="searchQuery" type="search" placeholder="Search name, venue, status or date" :disabled="loading" />
            </label>
            <label class="rp-select" :class="{ 'is-active': statusFilter !== 'all' }">
              <span class="sr-only">Filter by status</span>
              <select v-model="statusFilter" :disabled="loading">
                <option value="all">All statuses</option>
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
            <label class="rp-select" :class="{ 'is-active': timingFilter !== 'all' }">
              <span class="sr-only">Filter by timing</span>
              <select v-model="timingFilter" :disabled="loading">
                <option v-for="option in timingOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
            <button v-if="hasFilters" type="button" class="rp-btn rp-btn--text" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear</button>
          </div>
        </header>

        <div v-if="loading" class="rp-loading" aria-busy="true"><span class="sr-only" role="status">Loading reports…</span><div v-for="n in 4" :key="n" class="rp-skeleton rp-skeleton--row" /></div>
        <div v-else-if="error" class="rp-state">
          <AppIcon name="alert-circle" :size="26" />
          <strong>Reports unavailable</strong>
          <p>Try again to load the latest report data.</p>
        </div>
        <div v-else-if="!filteredReports.length" class="rp-state">
          <AppIcon :name="hasFilters ? 'search' : 'chart-column'" :size="26" />
          <strong>{{ hasFilters ? 'No events match these filters' : 'No events to report on yet' }}</strong>
          <p>{{ hasFilters ? 'Try a different search, status or time range.' : 'Create an event and collect registrations to see reports here.' }}</p>
          <button v-if="hasFilters" type="button" class="rp-btn rp-btn--secondary" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
          <RouterLink v-else to="/admin/events/new" class="rp-btn rp-btn--primary"><AppIcon name="plus" :size="16" />Create event</RouterLink>
        </div>
        <div v-else class="rp-table-wrap">
          <table class="rp-table">
            <thead>
              <tr>
                <th scope="col">Event</th>
                <th scope="col">Date</th>
                <th scope="col">Status</th>
                <th scope="col" class="rp-num">Registered</th>
                <th scope="col" class="rp-num">Checked in</th>
                <th scope="col" class="rp-num"><span title="Registered but not checked in, counted once the event has ended">No-show</span></th>
                <th scope="col">Attendance</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredReports" :key="row.id" :class="{ 'is-selected': selectedEvent?.id === row.id }" @click="onRowClick($event, row)">
                <td class="rp-cell-event">
                  <strong>{{ row.name }}</strong>
                  <span class="rp-sub"><AppIcon name="pin" :size="13" />{{ row.location || 'Location to be announced' }}</span>
                </td>
                <td data-label="Date"><span class="rp-date">{{ dateLine(row.starts_at) }}</span><span class="rp-sub">{{ timeLine(row) }}</span></td>
                <td data-label="Status">
                  <span class="rp-badge" :class="`rp-badge--${row.status}`">{{ statusLabel(row.status) }}</span>
                  <span class="rp-sub rp-phase" :class="`rp-phase--${phaseOf(row)}`">{{ phaseLabels[phaseOf(row)] }}</span>
                </td>
                <td data-label="Registered" class="rp-num">
                  <strong>{{ Number(row.registrations || 0).toLocaleString() }}</strong>
                  <span v-if="row.capacity" class="rp-sub" :title="`${capacityPercent(row)}% of capacity`">of {{ Number(row.capacity).toLocaleString() }} · {{ capacityPercent(row) }}%</span>
                </td>
                <td data-label="Checked in" class="rp-num"><strong>{{ Number(row.checked_in || 0).toLocaleString() }}</strong></td>
                <td data-label="No-show" class="rp-num">
                  <strong v-if="row.no_show !== null && row.no_show !== undefined">{{ Number(row.no_show).toLocaleString() }}</strong>
                  <span v-else class="rp-sub" title="No-shows are counted once the event has ended">Not ended</span>
                </td>
                <td data-label="Attendance" class="rp-cell-rate">
                  <div v-if="hasStarted(row) && Number(row.registrations)" class="rp-rate" :class="`rp-rate--${rateTone(row.attendance_rate)}`">
                    <span class="rp-rate__track" role="progressbar" :aria-valuenow="Number(row.attendance_rate) || 0" aria-valuemin="0" aria-valuemax="100" :aria-label="`Attendance for ${row.name}`"><span :style="{ width: `${Math.min(100, Number(row.attendance_rate) || 0)}%` }" /></span>
                    <b>{{ row.attendance_rate }}%</b>
                    <small>{{ rateWord[rateTone(row.attendance_rate)] }}</small>
                  </div>
                  <span v-else class="rp-sub">{{ Number(row.registrations) ? 'Not started' : 'No registrations' }}</span>
                </td>
                <td class="rp-cell-action">
                  <button type="button" class="rp-btn rp-btn--secondary rp-btn--sm" :data-report-action="row.id" title="View attendees" :aria-expanded="selectedEvent?.id === row.id" aria-controls="rp-attendees" @click="selectEvent(row)">
                    <AppIcon name="users" :size="16" /><span class="rp-btn__label">View attendees</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <article v-if="selectedEvent" id="rp-attendees" ref="attendeePanel" tabindex="-1" class="reports-panel attendee-report-panel" aria-labelledby="rp-attendees-title">
        <header class="reports-toolbar">
          <div>
            <p class="admin-eyebrow">Attendee report</p>
            <h2 id="rp-attendees-title">{{ selectedEvent.name }}</h2>
            <p v-if="detail && !detailLoading">{{ summary.checked_in }} checked in · {{ summary.not_checked_in }} not yet checked in · {{ summary.total }} total</p>
          </div>
          <div class="reports-tools">
            <label class="reports-search">
              <AppIcon name="search" :size="15" />
              <input v-model="attendeeSearch" type="search" placeholder="Search attendees…" aria-label="Search attendees in this event" title="Search by name, email, phone, or registration code" />
            </label>
            <button type="button" class="rp-btn rp-btn--secondary rp-btn--sm" :disabled="detailLoading || !attendeeRows.length" :title="`Download ${segmentMeta.label} as Excel`" @click="exportAttendeesCsv"><AppIcon name="download" :size="16" />{{ segmentMeta.short }} Excel</button>
            <button type="button" class="attendee-close" aria-label="Close attendee report" @click="closeAttendees"><AppIcon name="x" :size="18" /></button>
          </div>
        </header>

        <div class="attendee-tabs" role="group" aria-label="Check-in segments">
          <button type="button" :aria-pressed="attendeeFilter === 'all'" :class="{ active: attendeeFilter === 'all' }" @click="attendeeFilter = 'all'">All ({{ summary.total }})</button>
          <button type="button" :aria-pressed="attendeeFilter === 'in'" :class="{ active: attendeeFilter === 'in' }" @click="attendeeFilter = 'in'">Checked in ({{ summary.checked_in }})</button>
          <button type="button" :aria-pressed="attendeeFilter === 'out'" :class="{ active: attendeeFilter === 'out' }" @click="attendeeFilter = 'out'">Not checked in ({{ summary.not_checked_in }})</button>
        </div>

        <p v-if="detailError" class="inline-error attendee-inline-error" role="alert">{{ detailError }} <button type="button" @click="loadAttendees">Retry</button></p>

        <div v-if="detailLoading" class="dynamic-loading" role="status">Loading attendees…</div>

        <div v-else-if="attendeeRows.length" class="reports-table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">Attendee</th>
                <th scope="col">Contact</th>
                <th scope="col">Code</th>
                <th scope="col">Check-in</th>
                <th scope="col">Checked in at</th>
                <th scope="col">By</th>
                <th scope="col"><span class="sr-only">Details</span></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in attendeeRows" :key="row.id">
                <tr class="attendee-row" :class="{ 'is-expanded': expandedId === row.id }" @click="toggleProfile(row)">
                  <td>
                    <div class="attendee-name-cell">
                      <span class="table-avatar" :style="{ '--avatar-hue': avatarHue(row.name) }">{{ initials(row.name) }}</span>
                      <strong>{{ row.name || 'Unnamed attendee' }}</strong>
                    </div>
                  </td>
                  <td>{{ row.email || '—' }}<small v-if="row.phone">{{ row.phone }}</small></td>
                  <td>{{ row.registration_code || row.id }}</td>
                  <td>
                    <span class="checkin-pill" :class="row.checked_in_at ? 'checked' : 'pending'">{{ row.checked_in_at ? 'Checked in' : 'Not yet' }}</span>
                  </td>
                  <td>{{ row.checked_in_at ? formatDate(row.checked_in_at) : '—' }}</td>
                  <td>{{ row.checked_in_at ? (row.checked_in_by || 'Self check-in') : '—' }}</td>
                  <td class="attendee-expand-cell"><button type="button" class="attendee-expand-btn" :aria-expanded="expandedId === row.id" :aria-label="`${expandedId === row.id ? 'Hide' : 'Show'} details for ${row.name || 'attendee'}`" @click.stop="toggleProfile(row)"><AppIcon :name="expandedId === row.id ? 'chevron-down' : 'chevron-right'" :size="16" /></button></td>
                </tr>
                <tr v-if="expandedId === row.id" class="attendee-profile-row">
                  <td colspan="7">
                    <div class="attendee-profile">
                      <aside class="profile-id-card">
                        <span class="profile-avatar-lg" :style="{ '--avatar-hue': avatarHue(row.name) }">{{ initials(row.name) }}</span>
                        <div>
                          <strong>{{ row.name || 'Unnamed attendee' }}</strong>
                          <small>{{ row.registration_code || `#${row.id}` }}</small>
                          <span class="checkin-pill" :class="row.checked_in_at ? 'checked' : 'pending'">{{ row.checked_in_at ? 'Checked in' : 'Not yet' }}</span>
                        </div>
                      </aside>
                      <div class="profile-details">
                        <section>
                          <h4>Contact</h4>
                          <dl>
                            <div><dt>Email</dt><dd>{{ row.email || '—' }}</dd></div>
                            <div><dt>Phone</dt><dd>{{ row.phone || '—' }}</dd></div>
                            <div v-if="row.checked_in_at"><dt>Checked in by</dt><dd>{{ row.checked_in_by || 'Self check-in' }}</dd></div>
                          </dl>
                        </section>
                        <section v-if="profileEntries(row).length">
                          <h4>Profile</h4>
                          <dl>
                            <div v-for="[label, value] in profileEntries(row)" :key="label"><dt>{{ label.replace(/_/g, ' ') }}</dt><dd>{{ value }}</dd></div>
                          </dl>
                        </section>
                        <section v-if="formEntries(row).length">
                          <h4>Form answers</h4>
                          <dl>
                            <div v-for="[label, value] in formEntries(row)" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div>
                          </dl>
                        </section>
                        <p v-if="!hasProfileData(row)" class="profile-empty">No additional profile details were collected for this attendee.</p>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div v-else-if="!detailError" class="event-empty-state">
          <div class="empty-icon"><AppIcon name="users" :size="22" /></div>
          <h3>{{ attendeeSearch ? 'No matching attendees' : attendeeFilter === 'in' ? 'No check-ins yet' : attendeeFilter === 'out' ? 'Everyone has checked in' : 'No attendees yet' }}</h3>
          <p>{{ attendeeSearch ? 'Try a different name, email, or code.' : 'Attendee registrations for this event will appear here.' }}</p>
        </div>
      </article>

      <p class="rp-toast" :class="{ 'is-visible': notice }" role="status" aria-live="polite"><AppIcon v-if="notice" name="check-circle" :size="18" />{{ notice }}</p>
    </section>
  </AdminLayout>
</template>

<style scoped>
.reports-page { display: grid; gap: 18px; max-width: 1280px; }
.reports-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin: 30px 0 4px; }
.reports-heading p:not(.admin-eyebrow) { margin: 6px 0 0; color: #7890a8; font-size: .8rem; }
.reports-heading button { margin: 0; white-space: nowrap; }
.reports-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.reports-metrics .metric-card { margin: 0; }
.reports-panel { padding: 0 0 16px; border: 1px solid var(--ui-border); border-radius: var(--ui-radius); background: #fff; box-shadow: var(--ui-shadow); }
.reports-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 20px 22px 14px; }
.reports-toolbar h2 { margin: 0; color: #16345f; font-size: 1rem; }
.reports-toolbar p { margin: 4px 0 0; color: #8295aa; font-size: .7rem; }
.reports-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; }
.reports-tools .secondary-button { margin: 0; height: 38px; padding: 0 14px; display: inline-flex; align-items: center; font-size: .7rem; white-space: nowrap; }
.attendee-close { margin: 0; height: 38px; padding: 0 12px; border: 1px solid #dce8f4; border-radius: 8px; background: #fff; color: #7890a8; display: inline-flex; align-items: center; }
.attendee-close:hover { border-color: #f3c1c7; color: #b42318; }
.reports-search { display: flex; align-items: center; gap: 8px; height: 38px; padding: 0 12px; border: 1px solid #d2dfeb; border-radius: 8px; background: #fcfdff; color: #9aabba; transition: border-color .15s ease, box-shadow .15s ease, color .15s ease; }
.reports-search:hover { border-color: #a6dccb; }
.reports-search:focus-within { border-color: #7cc9b2; box-shadow: 0 0 0 3px rgba(11, 127, 98, .12); background: #fff; color: #0b7f62; }
.reports-search svg { flex: 0 0 auto; }
.reports-search input { width: 220px; min-width: 0; border: 0; outline: 0; background: transparent; font: .74rem 'Space Grotesk', sans-serif; color: #16345f; }
.reports-search input::placeholder { color: #9aabba; }
.reports-status-select { height: 38px; padding: 0 30px 0 12px; border: 1px solid #d2dfeb; border-radius: 8px; background: #fff; color: #16345f; font-size: .72rem; font-weight: 600; }
.reports-table-wrap { overflow-x: auto; padding: 0 18px; }
.reports-table-wrap table { width: 100%; border-collapse: collapse; min-width: 720px; }
.reports-table-wrap th { padding: 11px 12px; border-bottom: 1px solid #edf2f6; background: #f6faff; color: #365783; font-size: .62rem; letter-spacing: .06em; text-transform: uppercase; text-align: left; }
.reports-table-wrap td { padding: 13px 12px; border-bottom: 1px solid #f2f6fa; color: #456887; font-size: .74rem; vertical-align: top; }
.reports-table-wrap tr:last-child td { border-bottom: 0; }
.reports-table-wrap td strong { display: block; color: #16345f; font-size: .78rem; }
.reports-table-wrap td small { display: block; margin-top: 2px; color: #9aabba; font-size: .65rem; }
.reports-row { cursor: pointer; }
.reports-row:hover td { background: #fafdff; }
.reports-row.is-selected td { background: #f2f8ff; }
.reports-row-action button { margin: 0; padding: 6px 11px; border: 1px solid #dce8f4; border-radius: 7px; background: #fff; color: #0b7f62; font-size: .66rem; font-weight: 700; white-space: nowrap; }
.reports-row-action button:hover { border-color: #7cc9b2; background: #f7fbff; }
.reports-rate { display: flex; align-items: center; gap: 10px; }
.reports-rate-track { flex: 0 0 84px; height: 6px; border-radius: 999px; background: #eef3f9; overflow: hidden; }
.reports-rate-track span { display: block; height: 100%; border-radius: 999px; background: #0b7f62; transition: width .25s ease; }
.reports-rate-track span.good { background: #0d9b68; }
.reports-rate-track span.mid { background: #d77911; }
.reports-rate-track span.low { background: #db5360; }
.reports-rate b { color: #16345f; font-size: .72rem; }
.attendee-tabs { display: flex; gap: 8px; margin: 4px 22px 14px; }
.attendee-tabs button { margin: 0; padding: 8px 14px; border: 1px solid #dce8f4; border-radius: 999px; background: #fff; color: #526e97; font-size: .68rem; font-weight: 600; }
.attendee-tabs button.active { border-color: #0b7f62; background: #ecf8f4; color: #0b7f62; }
.attendee-inline-error { margin: 0 22px 14px; }
.checkin-pill { display: inline-block; padding: 5px 10px; border-radius: 999px; font-size: .62rem; font-weight: 700; }
.checkin-pill.checked { background: #d9f7e9; color: #0b9b63; }
.checkin-pill.pending { background: #fff0dc; color: #d77911; }
.attendee-row { cursor: pointer; }
.attendee-row:hover td { background: #fafdff; }
.attendee-row:hover .table-avatar { transform: scale(1.08); }
.attendee-row.is-expanded td { background: #f4f9ff; border-bottom-color: transparent; }
.attendee-name-cell { gap: 12px; }
.attendee-name-cell .table-avatar { width: 32px; height: 32px; font-size: .64rem; }
.attendee-name-cell { display: flex; align-items: center; gap: 10px; }
.attendee-avatar { display: grid; place-items: center; flex: 0 0 auto; width: 32px; height: 32px; border-radius: 50%; background: hsl(var(--avatar-hue), 62%, 92%); color: hsl(var(--avatar-hue), 55%, 32%); font-size: .64rem; font-weight: 700; }
.attendee-expand-cell { width: 34px; text-align: right; }
.attendee-expand { color: #9aabba; font-size: .8rem; }
.attendee-profile-row td { padding: 0 18px 14px; background: #f4f9ff; border-bottom: 1px solid #f2f6fa; }
.attendee-profile { display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: 18px; padding: 18px; border: 1px solid #dbe7f3; border-radius: 11px; background: #fff; }
.profile-id-card { display: flex; align-items: flex-start; gap: 12px; padding-right: 18px; border-right: 1px solid #edf2f6; }
.profile-avatar-lg { display: grid; place-items: center; flex: 0 0 auto; width: 52px; height: 52px; border-radius: 50%; background: linear-gradient(135deg, hsl(var(--avatar-hue, 217), 78%, 62%), hsl(calc(var(--avatar-hue, 217) + 40), 72%, 48%)); color: #fff; font-size: .95rem; font-weight: 700; letter-spacing: .02em; box-shadow: 0 8px 18px -8px hsl(var(--avatar-hue, 217) 78% 48% / .6); }
.profile-id-card div { display: grid; gap: 4px; justify-items: start; }
.profile-id-card strong { color: #16345f; font-size: .85rem; }
.profile-id-card small { color: #8295aa; font-size: .66rem; }
.profile-details { display: grid; gap: 14px; }
.profile-details h4 { margin: 0 0 6px; color: #0b7f62; font-size: .62rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
.profile-details dl { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 6px; margin: 0; }
.profile-details dl > div { display: grid; gap: 2px; padding: 8px 11px; border: 1px solid #e9eff6; border-radius: 8px; background: #fbfdff; }
.profile-details dt { color: #8295aa; font-size: .62rem; text-transform: capitalize; }
.profile-details dd { margin: 0; color: #24486f; font-size: .74rem; font-weight: 600; overflow-wrap: anywhere; }
.profile-empty { margin: 0; color: #8295aa; font-size: .72rem; }
@media (max-width: 900px) { .reports-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .reports-heading { flex-direction: column; } .attendee-profile { grid-template-columns: 1fr; } .profile-id-card { border-right: 0; padding-right: 0; border-bottom: 1px solid #edf2f6; padding-bottom: 14px; } }
.attendee-expand-btn { display: grid; place-items: center; width: 34px; height: 34px; margin: 0; padding: 0; border: 1px solid #e3eaf0; border-radius: 8px; background: #fff; color: #5f7286; cursor: pointer; }
.attendee-expand-btn:hover { border-color: #a6dccb; color: #087a5e; }
.attendee-report-panel:focus { outline: none; }
.attendee-report-panel .reports-toolbar h2 { font-size: 1.15rem; color: #0f1c2e; }
.attendee-report-panel .reports-toolbar p { font-size: .86rem; color: #5f7286; }
.attendee-report-panel .reports-search input { font-size: .9rem; }
.attendee-report-panel .reports-table-wrap th { font-size: .72rem; color: #6f8295; background: #fafcfd; }
.attendee-report-panel .reports-table-wrap td { font-size: .88rem; color: #33475c; vertical-align: middle; }
.attendee-report-panel .reports-table-wrap td strong { font-size: .92rem; color: #0f1c2e; }
.attendee-report-panel .reports-table-wrap td small { font-size: .8rem; color: #6f8295; }
.attendee-tabs button { font-size: .84rem; min-height: 36px; }
.checkin-pill { font-size: .76rem; }
.checkin-pill.pending { color: #8a5a06; background: #fff4dc; }
.checkin-pill.checked { color: #08704f; background: #e3f6ee; }
.attendee-close { min-width: 38px; justify-content: center; }
</style>
