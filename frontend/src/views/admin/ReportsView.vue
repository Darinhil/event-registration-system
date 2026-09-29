<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { getEventReportAttendees, getReports } from '../../services/adminService'
import { formatDate } from '../../utils/formatDate'

const loading = ref(true)
const error = ref('')
const reports = ref([])
const searchQuery = ref('')
const statusFilter = ref('all')

const selectedEvent = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const attendeeFilter = ref('all')
const attendeeSearch = ref('')
const attendeePanel = ref(null)

const statusOptions = [
  { value: 'all', label: 'All statuses' },
  { value: 'published', label: 'Published' },
  { value: 'closed', label: 'Closed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const statusLabel = (value) => ({
  published: 'Published',
  closed: 'Closed',
  cancelled: 'Cancelled',
  draft: 'Draft',
}[value] || (value || 'Unknown'))

const filteredReports = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return reports.value.filter((row) => {
    const matchesQuery = !query || `${row.name} ${row.location}`.toLowerCase().includes(query)
    const matchesStatus = statusFilter.value === 'all' || row.status === statusFilter.value
    return matchesQuery && matchesStatus
  })
})

const totals = computed(() => filteredReports.value.reduce((sum, row) => ({
  events: sum.events + 1,
  registrations: sum.registrations + Number(row.registrations || 0),
  checked_in: sum.checked_in + Number(row.checked_in || 0),
  no_show: sum.no_show + Number(row.no_show || 0),
}), { events: 0, registrations: 0, checked_in: 0, no_show: 0 }))

const overallRate = computed(() =>
  totals.value.registrations ? Math.round((totals.value.checked_in / totals.value.registrations) * 1000) / 10 : 0
)

const rateClass = (rate) => (Number(rate) >= 70 ? 'good' : Number(rate) >= 40 ? 'mid' : 'low')

const loadReports = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getReports()
    reports.value = data.data || data || []
  } catch {
    error.value = 'Unable to load reports. Check your admin session.'
  } finally {
    loading.value = false
  }
}

const exportCsv = () => {
  const header = ['Event', 'Date', 'Location', 'Status', 'Capacity', 'Registrations', 'Checked In', 'No Show', 'Attendance Rate %']
  const rows = filteredReports.value.map((row) => [
    row.name,
    row.starts_at ? formatDate(row.starts_at) : '',
    row.location || '',
    statusLabel(row.status),
    row.capacity ?? '',
    row.registrations,
    row.checked_in,
    row.no_show,
    row.attendance_rate,
  ])
  const csv = [header, ...rows]
    .map((line) => line.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
    .join('\n')
  const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a')
  link.href = url
  link.download = `event-reports-${new Date().toISOString().slice(0, 10)}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

/* ---------- Per-event attendee report ---------- */

const loadAttendees = async () => {
  if (!selectedEvent.value) return
  detailLoading.value = true
  detailError.value = ''
  try {
    const { data } = await getEventReportAttendees(selectedEvent.value.id)
    detail.value = data.data || data
  } catch {
    detailError.value = 'Unable to load the attendee list for this event.'
  } finally {
    detailLoading.value = false
  }
}

const selectEvent = async (row) => {
  if (selectedEvent.value?.id === row.id) return
  selectedEvent.value = row
  detail.value = null
  expandedId.value = null
  attendeeFilter.value = 'all'
  attendeeSearch.value = ''
  await loadAttendees()
  await nextTick()
  attendeePanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const closeAttendees = () => {
  selectedEvent.value = null
  detail.value = null
  expandedId.value = null
  attendeeFilter.value = 'all'
  attendeeSearch.value = ''
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

const exportAttendeesCsv = () => {
  const header = ['Name', 'Email', 'Phone', 'Registration Code', 'Registration Status', 'Checked In', 'Checked In At', 'Checked In By']
  const rows = attendeeRows.value.map((row) => [
    row.name,
    row.email,
    row.phone,
    row.registration_code,
    row.status,
    row.checked_in_at ? 'Yes' : 'No',
    row.checked_in_at ? formatDate(row.checked_in_at) : '',
    row.checked_in_by || (row.checked_in_at ? 'Self check-in' : ''),
  ])
  const csv = [header, ...rows]
    .map((line) => line.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
    .join('\n')
  const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a')
  link.href = url
  const slug = String(selectedEvent.value?.name || `event-${selectedEvent.value?.id}`).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
  link.download = `${slug}-attendees-${segmentMeta.value.file}-${new Date().toISOString().slice(0, 10)}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

onMounted(loadReports)
</script>

<template>
  <AdminLayout>
    <section class="reports-page">
      <header class="reports-heading dashboard-page-heading">
        <div>
          <p class="admin-eyebrow">Insights · all events</p>
          <h1>Reports</h1>
          <p>Compare registrations, check-ins, and no-shows across every event — then drill into attendees.</p>
        </div>
        <button type="button" class="secondary-button" :disabled="loading || !filteredReports.length" @click="exportCsv">↓ Export CSV</button>
      </header>

      <p v-if="error" class="inline-error" role="alert">{{ error }} <button type="button" @click="loadReports">Retry</button></p>

      <div class="reports-metrics">
        <article class="metric-card metric-blue">
          <span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg></span>
          <div><small>Events</small><strong>{{ totals.events.toLocaleString() }}</strong></div>
        </article>
        <article class="metric-card metric-green">
          <span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg></span>
          <div><small>Total Registrations</small><strong>{{ totals.registrations.toLocaleString() }}</strong></div>
        </article>
        <article class="metric-card metric-purple">
          <span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="m9 11 3 3L22 4" /></svg></span>
          <div><small>Checked In</small><strong>{{ totals.checked_in.toLocaleString() }}</strong></div>
        </article>
        <article class="metric-card metric-orange">
          <span class="metric-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="M12 8v4m0 4h.01" /></svg></span>
          <div><small>Attendance Rate</small><strong>{{ overallRate }}%</strong></div>
        </article>
      </div>

      <article class="reports-panel">
        <header class="reports-toolbar">
          <div>
            <h2>Per-event breakdown</h2>
            <p v-if="!loading">{{ filteredReports.length }} event{{ filteredReports.length === 1 ? '' : 's' }} shown · click a row to see its attendees</p>
          </div>
          <div class="reports-tools">
            <label class="reports-search">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
              <input v-model="searchQuery" type="search" placeholder="Search events…" aria-label="Search events" />
            </label>
            <select v-model="statusFilter" class="reports-status-select" aria-label="Filter by status">
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
        </header>

        <div v-if="loading" class="dynamic-loading">Loading reports…</div>

        <div v-else-if="filteredReports.length" class="reports-table-wrap">
          <table>
            <thead>
              <tr>
                <th>Event</th>
                <th>Date</th>
                <th>Status</th>
                <th>Registrations</th>
                <th>Checked In</th>
                <th>No Show</th>
                <th>Attendance</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredReports" :key="row.id" class="reports-row" :class="{ 'is-selected': selectedEvent?.id === row.id }" @click="selectEvent(row)">
                <td>
                  <strong>{{ row.name }}</strong>
                  <small>{{ row.location || 'Location pending' }}</small>
                </td>
                <td>{{ row.starts_at ? formatDate(row.starts_at) : 'To be confirmed' }}</td>
                <td><span class="event-status" :class="row.status">{{ statusLabel(row.status) }}</span></td>
                <td>
                  <strong>{{ row.registrations }}</strong>
                  <small v-if="row.capacity">of {{ row.capacity }} capacity</small>
                </td>
                <td>{{ row.checked_in }}</td>
                <td>{{ row.no_show }}</td>
                <td>
                  <div class="reports-rate">
                    <div class="reports-rate-track"><span :class="rateClass(row.attendance_rate)" :style="{ width: `${Math.min(100, Number(row.attendance_rate) || 0)}%` }"></span></div>
                    <b>{{ row.attendance_rate }}%</b>
                  </div>
                </td>
                <td class="reports-row-action"><button type="button" @click.stop="selectEvent(row)">View attendees</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else class="event-empty-state">
          <div class="empty-icon">▥</div>
          <h3>{{ searchQuery || statusFilter !== 'all' ? 'No matching events' : 'No events to report on yet' }}</h3>
          <p>{{ searchQuery || statusFilter !== 'all' ? 'Try adjusting your search or status filter.' : 'Create an event and collect registrations to see reports here.' }}</p>
        </div>
      </article>

      <article v-if="selectedEvent" ref="attendeePanel" class="reports-panel attendee-report-panel">
        <header class="reports-toolbar">
          <div>
            <p class="admin-eyebrow">Attendee report</p>
            <h2>{{ selectedEvent.name }}</h2>
            <p v-if="detail && !detailLoading">{{ summary.checked_in }} checked in · {{ summary.not_checked_in }} not yet checked in · {{ summary.total }} total</p>
          </div>
          <div class="reports-tools">
            <label class="reports-search">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
              <input v-model="attendeeSearch" type="search" placeholder="Search attendees…" aria-label="Search attendees in this event" title="Search by name, email, phone, or registration code" />
            </label>
            <button type="button" class="secondary-button" :disabled="detailLoading || !attendeeRows.length" :title="`Download ${segmentMeta.label} as CSV`" @click="exportAttendeesCsv">↓ {{ segmentMeta.short }} CSV</button>
            <button type="button" class="attendee-close" aria-label="Close attendee report" @click="closeAttendees">✕</button>
          </div>
        </header>

        <div class="attendee-tabs" role="tablist" aria-label="Check-in segments">
          <button type="button" :class="{ active: attendeeFilter === 'all' }" @click="attendeeFilter = 'all'">All ({{ summary.total }})</button>
          <button type="button" :class="{ active: attendeeFilter === 'in' }" @click="attendeeFilter = 'in'">Checked in ({{ summary.checked_in }})</button>
          <button type="button" :class="{ active: attendeeFilter === 'out' }" @click="attendeeFilter = 'out'">Not checked in ({{ summary.not_checked_in }})</button>
        </div>

        <p v-if="detailError" class="inline-error attendee-inline-error" role="alert">{{ detailError }} <button type="button" @click="loadAttendees">Retry</button></p>

        <div v-if="detailLoading" class="dynamic-loading">Loading attendees…</div>

        <div v-else-if="attendeeRows.length" class="reports-table-wrap">
          <table>
            <thead>
              <tr>
                <th>Attendee</th>
                <th>Contact</th>
                <th>Code</th>
                <th>Check-in</th>
                <th>Checked in at</th>
                <th>By</th>
                <th></th>
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
                  <td class="attendee-expand-cell"><span class="attendee-expand" aria-hidden="true">{{ expandedId === row.id ? '▾' : '▸' }}</span></td>
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

        <div v-else class="event-empty-state">
          <div class="empty-icon">♙</div>
          <h3>{{ attendeeSearch ? 'No matching attendees' : attendeeFilter === 'in' ? 'No check-ins yet' : attendeeFilter === 'out' ? 'Everyone has checked in' : 'No attendees yet' }}</h3>
          <p>{{ attendeeSearch ? 'Try a different name, email, or code.' : 'Attendee registrations for this event will appear here.' }}</p>
        </div>
      </article>
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
.reports-search:hover { border-color: #abc9f2; }
.reports-search:focus-within { border-color: #76a8ef; box-shadow: 0 0 0 3px rgba(39, 124, 242, .12); background: #fff; color: #277cf2; }
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
.reports-row-action button { margin: 0; padding: 6px 11px; border: 1px solid #dce8f4; border-radius: 7px; background: #fff; color: #277cf2; font-size: .66rem; font-weight: 700; white-space: nowrap; }
.reports-row-action button:hover { border-color: #8fbaff; background: #f7fbff; }
.reports-rate { display: flex; align-items: center; gap: 10px; }
.reports-rate-track { flex: 0 0 84px; height: 6px; border-radius: 999px; background: #eef3f9; overflow: hidden; }
.reports-rate-track span { display: block; height: 100%; border-radius: 999px; background: #277cf2; transition: width .25s ease; }
.reports-rate-track span.good { background: #0d9b68; }
.reports-rate-track span.mid { background: #d77911; }
.reports-rate-track span.low { background: #db5360; }
.reports-rate b { color: #16345f; font-size: .72rem; }
.attendee-tabs { display: flex; gap: 8px; margin: 4px 22px 14px; }
.attendee-tabs button { margin: 0; padding: 8px 14px; border: 1px solid #dce8f4; border-radius: 999px; background: #fff; color: #526e97; font-size: .68rem; font-weight: 600; }
.attendee-tabs button.active { border-color: #277cf2; background: #eef5ff; color: #216bd8; }
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
.profile-details h4 { margin: 0 0 6px; color: #3978d8; font-size: .62rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
.profile-details dl { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 6px; margin: 0; }
.profile-details dl > div { display: grid; gap: 2px; padding: 8px 11px; border: 1px solid #e9eff6; border-radius: 8px; background: #fbfdff; }
.profile-details dt { color: #8295aa; font-size: .62rem; text-transform: capitalize; }
.profile-details dd { margin: 0; color: #24486f; font-size: .74rem; font-weight: 600; overflow-wrap: anywhere; }
.profile-empty { margin: 0; color: #8295aa; font-size: .72rem; }
@media (max-width: 900px) { .reports-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .reports-heading { flex-direction: column; } .attendee-profile { grid-template-columns: 1fr; } .profile-id-card { border-right: 0; padding-right: 0; border-bottom: 1px solid #edf2f6; padding-bottom: 14px; } }
</style>
