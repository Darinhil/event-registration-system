<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { getAdminEvents, getDashboard, getReports } from '../../services/adminService'
import { useAuthStore } from '../../stores/auth'
import { getMyCompliances } from '../../services/complianceService'
import { getMyTraining } from '../../services/trainingService'
import { getMyContracts } from '../../services/contractService'
import { getMyRequests, getPersonnelProfile } from '../../services/staffRequestService'
import { getTimesheet } from '../../services/timesheetService'

const auth = useAuthStore()
const loading = ref(true)
const loaded = ref(false)
const error = ref('')
const stats = ref({ users: 0, registrations: 0, check_ins: 0 })
const events = ref([])
const reports = ref([])

const number = (value) => Number(value || 0).toLocaleString()
const percent = (part, total) => total ? Math.round(part / total * 1000) / 10 : 0
const normalizeEvent = (event) => ({
  ...event,
  status: event.status === 'published' ? 'open' : event.status,
  registered: event.registrations_count ?? event.registered ?? 0,
})
const dateLabel = (value, options = { month: 'short', day: 'numeric', year: 'numeric' }) => value ? new Date(value).toLocaleDateString(undefined, options) : 'Date to be confirmed'
const timeLabel = (value) => value ? new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''
const initials = (name) => String(name || 'Event').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
const firstName = computed(() => String(auth.user?.name || '').trim().split(/\s+/)[0])
const today = new Date()

// One source of truth for an event's lifecycle phase, used by the donut, badges and lists.
const phaseOf = (event, now = Date.now()) => {
  if (event.status === 'cancelled') return 'cancelled'
  if (event.status === 'draft') return 'draft'
  if (event.status === 'closed' || (event.ends_at && new Date(event.ends_at).getTime() < now)) return 'completed'
  if (event.starts_at && new Date(event.starts_at).getTime() <= now) return 'ongoing'
  return 'upcoming'
}
const phases = {
  upcoming: { label: 'Upcoming', color: '#f59e0b' },
  ongoing: { label: 'Ongoing', color: '#3b82f6' },
  completed: { label: 'Completed', color: '#10b981' },
  draft: { label: 'Draft', color: '#94a3b8' },
  cancelled: { label: 'Cancelled', color: '#ef4444' },
}

const eventById = computed(() => new Map(events.value.map((event) => [String(event.id), event])))
const phaseTotals = computed(() => {
  const now = Date.now()
  const totals = Object.fromEntries(Object.keys(phases).map((key) => [key, 0]))
  events.value.forEach((event) => { totals[phaseOf(event, now)] += 1 })
  return totals
})
const statusRows = computed(() => Object.entries(phases).map(([key, phase]) => ({ key, ...phase, value: phaseTotals.value[key], share: percent(phaseTotals.value[key], events.value.length) })))
const statusDonutStyle = computed(() => {
  if (!events.value.length) return { background: 'var(--rd-line)' }
  let start = 0
  const stops = statusRows.value.filter((row) => row.value).map((row) => {
    const end = start + row.value / events.value.length * 100
    const stop = `${row.color} ${start}% ${end}%`
    start = end
    return stop
  })
  return { background: `conic-gradient(${stops.join(', ')})` }
})

const upcomingEvents = computed(() => events.value
  .filter((event) => event.starts_at && ['upcoming', 'ongoing'].includes(phaseOf(event)))
  .sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))
  .slice(0, 5))

const checkInRate = computed(() => percent(stats.value.check_ins, stats.value.registrations))
const statCards = computed(() => [
  { key: 'events', label: 'Total Events', value: events.value.length, tone: 'teal', note: `${number(phaseTotals.value.upcoming + phaseTotals.value.ongoing)} upcoming or live`, to: '/admin/events', icon: 'calendar' },
  { key: 'users', label: 'Total Participants', value: stats.value.users, tone: 'blue', note: 'Unique people', to: '/admin/users', icon: 'users-group' },
  { key: 'registrations', label: 'Registrations', value: stats.value.registrations, tone: 'amber', note: `Across ${number(events.value.length)} ${events.value.length === 1 ? 'event' : 'events'}`, to: '/admin/reports', icon: 'user-plus' },
  { key: 'check_ins', label: 'Checked In', value: stats.value.check_ins, tone: 'purple', note: `${checkInRate.value}% of registrations`, to: '/admin/check-ins', icon: 'user-check' },
])

// Reports are already ordered by start date (newest first) by the API.
const performanceRows = computed(() => reports.value.slice(0, 6).map((report) => {
  const event = eventById.value.get(String(report.id))
  const phase = phaseOf(event || { ...report, status: report.status === 'published' ? 'open' : report.status })
  return { ...report, phase, rate: Number(report.attendance_rate || 0) }
}))

const loadDashboard = async () => {
  loading.value = true
  error.value = ''
  try {
    const [dashboardResponse, eventsResponse, reportsResponse] = await Promise.all([getDashboard(), getAdminEvents(), getReports()])
    stats.value = dashboardResponse.data
    events.value = (eventsResponse.data.data || eventsResponse.data || []).map(normalizeEvent)
    reports.value = reportsResponse.data.data || reportsResponse.data || []
    loaded.value = true
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load dashboard data. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}
// Staff home: each card shows the person's real records; a card that can't load shows a dash.
const complianceSummary = ref(null)
const training = ref(null)
const profile = ref(null)
const contract = ref(null)
const leaveBalances = ref([])
const recentRequests = ref([])
const upcomingTraining = computed(() => (training.value?.data || []).filter((course) => course.status !== 'completed').slice(0, 3))
const profileLabel = computed(() => !profile.value ? '—' : profile.value.submitted_at ? 'Submitted' : profile.value.profile ? 'In progress' : 'Not started')
const shortDay = (iso) => new Date(iso.length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const loadStaffHome = () => {
  const today = new Date()
  getMyCompliances().then(({ data }) => { complianceSummary.value = data.summary }).catch(() => {})
  getMyTraining().then(({ data }) => { training.value = data }).catch(() => {})
  getPersonnelProfile().then(({ data }) => { profile.value = data.data }).catch(() => {})
  getMyContracts().then(({ data }) => { contract.value = data.data.find((item) => item.status === 'active') || null }).catch(() => {})
  getMyRequests().then(({ data }) => { recentRequests.value = data.data.slice(0, 3) }).catch(() => {})
  getTimesheet(today.getFullYear(), today.getMonth() + 1).then(({ data }) => { leaveBalances.value = data.data.balances.filter((item) => item.limited) }).catch(() => {})
}
onMounted(() => { if (auth.canManageEvents) loadDashboard(); else { loading.value = false; loadStaffHome() } })
</script>

<template>
  <AdminLayout>
    <section v-if="!auth.canManageEvents" class="staff-home">
      <header class="staff-home__title"><h1>Welcome, {{ firstName || 'there' }}</h1></header>
      <section class="staff-home__metrics" aria-label="Your staff overview">
        <article><small>Profile</small><strong>{{ profileLabel }}</strong><RouterLink to="/admin/personnel-profile?section=my-profile">View profile <AppIcon name="chevron-right" :size="13" /></RouterLink></article>
        <article><small>Training</small><strong>{{ training ? `${training.summary.percent}%` : '—' }}</strong><div class="staff-home__track"><i :style="{ width: `${training?.summary.percent || 0}%` }"></i></div><RouterLink to="/admin/personnel-profile?section=training">View training <AppIcon name="chevron-right" :size="13" /></RouterLink></article>
        <article><small>Contract</small><strong>{{ contract ? contract.position : 'No active contract' }}</strong><div v-if="contract" class="staff-home__metric-note">{{ contract.end_date ? `Ends ${shortDay(contract.end_date)}` : 'Open-ended' }}</div><RouterLink to="/admin/personnel-profile?section=contract-history">View contract <AppIcon name="chevron-right" :size="13" /></RouterLink></article>
        <article><small>Compliance</small><strong>{{ complianceSummary ? `${complianceSummary.percent}%` : '—' }}</strong><div class="staff-home__track"><i :style="{ width: `${complianceSummary?.percent || 0}%` }"></i></div><RouterLink to="/admin/personnel-profile?section=compliances">View compliance <AppIcon name="chevron-right" :size="13" /></RouterLink></article>
      </section>
      <section class="staff-home__panel staff-home__quick"><h2>Quick actions</h2><div class="staff-home__quick-grid">
        <RouterLink to="/admin/personnel-profile?section=leave-application"><span><AppIcon name="calendar-check" :size="18" /></span>Apply for leave</RouterLink><RouterLink to="/admin/personnel-profile?section=overtime-request"><span><AppIcon name="timer" :size="18" /></span>Request overtime</RouterLink><RouterLink to="/admin/personnel-profile?section=travel-application"><span><AppIcon name="plane" :size="18" /></span>Travel application</RouterLink><RouterLink to="/admin/personnel-profile?section=purchase-request"><span><AppIcon name="cart" :size="18" /></span>Purchase request</RouterLink><RouterLink to="/admin/personnel-profile?section=fuel-log-sheet"><span><AppIcon name="fuel" :size="18" /></span>Fuel log-sheet</RouterLink><RouterLink to="/admin/personnel-profile?section=finance-management"><span><AppIcon name="receipt" :size="18" /></span>Voucher</RouterLink>
      </div></section>
      <div class="staff-home__columns">
        <section class="staff-home__panel"><header><h2>My requests</h2><RouterLink to="/admin/personnel-profile?section=my-requests">View all <AppIcon name="chevron-right" :size="13" /></RouterLink></header><ul v-if="recentRequests.length" class="staff-home__list"><li v-for="request in recentRequests" :key="request.id"><RouterLink to="/admin/personnel-profile?section=my-requests"><strong>{{ request.type_label }}</strong><small>{{ request.status }}</small></RouterLink></li></ul><p v-else class="staff-home__empty">No requests yet.</p></section>
        <section class="staff-home__panel"><header><h2>Training to finish</h2><RouterLink to="/admin/personnel-profile?section=training">View all <AppIcon name="chevron-right" :size="13" /></RouterLink></header><ul v-if="upcomingTraining.length" class="staff-home__list"><li v-for="course in upcomingTraining" :key="course.id"><RouterLink :to="{ path: '/admin/personnel-profile', query: { section: 'training', course: course.id } }"><strong>{{ course.title }}</strong><small :class="{ 'is-overdue': course.overdue }">{{ course.overdue ? 'Overdue' : course.due_date ? `Due ${new Date(`${course.due_date}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}` : `${course.percent}% done` }}</small></RouterLink></li></ul><p v-else class="staff-home__empty">You’re up to date.</p></section>
        <section class="staff-home__panel"><header><h2>Leave balance</h2><RouterLink to="/admin/personnel-profile?section=leave-application">Apply <AppIcon name="chevron-right" :size="13" /></RouterLink></header><ul v-if="leaveBalances.length" class="staff-home__list"><li v-for="item in leaveBalances" :key="item.type"><RouterLink to="/admin/personnel-profile?section=leave-application"><strong>{{ item.label }}</strong><small>{{ item.remaining }} of {{ item.entitlement + item.carried_over }} days left</small></RouterLink></li></ul><p v-else class="staff-home__empty">No leave allowance on record.</p></section>
      </div>
    </section>

    <section v-else class="reference-dashboard">
      <header class="reference-dashboard__heading">
        <div>
          <h1>Dashboard</h1>
        </div>
        <div class="reference-dashboard__heading-actions">
          <time class="reference-dashboard__date" :datetime="today.toISOString().slice(0, 10)">{{ dateLabel(today, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}</time>
          <button type="button" class="rd-button rd-button--ghost" :disabled="loading" aria-label="Refresh dashboard" @click="loadDashboard">
            <AppIcon name="refresh" :size="17" :class="{ 'is-spinning': loading && loaded }" />
          </button>
          <RouterLink to="/admin/events/new" class="rd-button rd-button--primary"><AppIcon name="plus" :size="17" />New event</RouterLink>
        </div>
      </header>

      <div v-if="error" class="reference-dashboard__error" role="alert">
        <AppIcon name="alert-circle" :size="20" />
        <span>{{ error }}</span>
        <button type="button" :disabled="loading" @click="loadDashboard">{{ loading ? 'Retrying…' : 'Retry' }}</button>
      </div>

      <div v-if="loading && !loaded" class="reference-dashboard__layout" role="status" aria-label="Loading dashboard">
        <div class="reference-stats reference-dashboard__stats"><div v-for="n in 4" :key="n" class="reference-skeleton reference-skeleton--stat"></div></div>
        <div class="reference-skeleton reference-skeleton--card reference-status"></div>
        <div class="reference-skeleton reference-skeleton--card reference-upcoming"></div>
        <span class="sr-only">Loading your dashboard…</span>
      </div>

      <div v-else-if="loaded" class="reference-dashboard__layout" :aria-busy="loading">
        <section class="reference-stats reference-dashboard__stats" aria-label="Event statistics">
          <RouterLink v-for="card in statCards" :key="card.key" :to="card.to" class="reference-stat" :class="`stat-${card.tone}`">
            <span class="reference-stat__icon" aria-hidden="true"><AppIcon :name="card.icon" :size="22" /></span>
            <span class="reference-stat__body">
              <span class="reference-stat__label">{{ card.label }}</span>
              <strong>{{ number(card.value) }}</strong>
              <small>{{ card.note }}</small>
            </span>
          </RouterLink>
        </section>

        <article class="reference-card reference-status">
          <header><h2>Event Status</h2><RouterLink to="/admin/events">View all<AppIcon name="chevron-right" :size="16" /></RouterLink></header>
          <div v-if="events.length" class="reference-status__body">
            <div class="reference-donut" :style="statusDonutStyle" role="img" :aria-label="statusRows.map((row) => `${row.label}: ${row.value}`).join(', ')">
              <div><strong>{{ number(events.length) }}</strong><small>{{ events.length === 1 ? 'Event' : 'Events' }}</small></div>
            </div>
            <ul class="reference-legend">
              <li v-for="row in statusRows" :key="row.key" :class="{ 'is-zero': !row.value }"><i :style="{ background: row.color }"></i><span>{{ row.label }}</span><b>{{ number(row.value) }}</b><small>{{ row.share }}%</small></li>
            </ul>
          </div>
          <div v-else class="reference-empty">
            <span class="reference-empty__icon"><AppIcon name="chart-pie" :size="22" /></span>
            <p>Status breakdown appears once you create an event.</p>
          </div>
        </article>

        <article class="reference-card reference-upcoming">
          <header><h2>Upcoming Events</h2><RouterLink to="/admin/events">View all<AppIcon name="chevron-right" :size="16" /></RouterLink></header>
          <ul v-if="upcomingEvents.length" class="reference-event-list">
            <li v-for="event in upcomingEvents" :key="event.id">
              <RouterLink :to="`/admin/events/${event.id}`" class="reference-event">
                <span class="reference-event__date-tile" aria-hidden="true"><small>{{ dateLabel(event.starts_at, { month: 'short' }) }}</small><b>{{ dateLabel(event.starts_at, { day: 'numeric' }) }}</b></span>
                <span class="reference-event__body">
                  <strong>{{ event.name }}</strong>
                  <span class="reference-event__facts"><span><AppIcon name="clock" :size="14" />{{ dateLabel(event.starts_at, { weekday: 'short' }) }}, {{ timeLabel(event.starts_at) }}</span><span v-if="event.location"><AppIcon name="pin" :size="14" />{{ event.location }}</span></span>
                </span>
                <span class="reference-event__meta">
                  <em class="rd-badge" :class="`rd-badge--${phaseOf(event)}`">{{ phases[phaseOf(event)].label }}</em>
                  <small><AppIcon name="users" :size="14" />{{ number(event.registered) }}<template v-if="event.capacity"> / {{ number(event.capacity) }}</template></small>
                </span>
              </RouterLink>
            </li>
          </ul>
          <div v-else class="reference-empty">
            <span class="reference-empty__icon"><AppIcon name="calendar-plus" :size="22" /></span>
            <p>No upcoming events scheduled.</p>
            <RouterLink to="/admin/events/new" class="rd-button rd-button--soft"><AppIcon name="plus" :size="16" />Create an event</RouterLink>
          </div>
        </article>

        <article class="reference-card reference-performance">
          <header><h2>Event list</h2><RouterLink to="/admin/reports">Full report<AppIcon name="chevron-right" :size="16" /></RouterLink></header>
          <div v-if="performanceRows.length" class="reference-table-wrap">
            <table class="reference-table">
              <thead><tr><th scope="col">Event</th><th scope="col" class="col-status">Status</th><th scope="col" class="num">Registered</th><th scope="col" class="num col-checked">Checked in</th><th scope="col" class="col-rate">Attendance</th></tr></thead>
              <tbody>
                <tr v-for="row in performanceRows" :key="row.id">
                  <td><RouterLink :to="`/admin/events/${row.id}`" class="reference-table__event"><span class="reference-table__thumb" aria-hidden="true"><img v-if="eventById.get(String(row.id))?.branding?.image" :src="eventById.get(String(row.id)).branding.image" alt="" /><template v-else>{{ initials(row.name) }}</template></span><span><strong>{{ row.name }}</strong><small><AppIcon name="calendar" :size="13" />{{ dateLabel(row.starts_at) }}</small></span></RouterLink></td>
                  <td class="col-status"><em class="rd-badge" :class="`rd-badge--${row.phase}`">{{ phases[row.phase].label }}</em></td>
                  <td class="num">{{ number(row.registrations) }}<small v-if="row.capacity"> / {{ number(row.capacity) }}</small></td>
                  <td class="num col-checked">{{ number(row.checked_in) }}</td>
                  <td class="col-rate"><span class="reference-meter" role="img" :aria-label="`${row.rate}% attendance`"><span><i :style="{ width: `${Math.min(row.rate, 100)}%` }"></i></span><b>{{ row.rate }}%</b></span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="reference-empty">
            <span class="reference-empty__icon"><AppIcon name="trending-up" :size="22" /></span>
            <p>Event performance appears once registrations start coming in.</p>
          </div>
        </article>

      </div>
    </section>
  </AdminLayout>
</template>
