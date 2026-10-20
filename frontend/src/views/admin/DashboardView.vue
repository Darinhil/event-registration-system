<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import api from '../../services/api'
import { getDashboard } from '../../services/adminService'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const loading = ref(true)
const error = ref('')
const stats = ref({ users: 0, registrations: 0, check_ins: 0 })
const events = ref([])
const reports = ref([])

const number = (value) => Number(value || 0).toLocaleString()
const normalizeEvent = (event) => ({
  ...event,
  status: event.status === 'published' ? 'open' : event.status,
  registered: event.registrations_count ?? event.registered ?? 0,
})
const statusLabel = (status) => ({ open: 'Upcoming', published: 'Upcoming', closed: 'Completed', cancelled: 'Cancelled', draft: 'Draft' }[status] || status || 'Upcoming')
const dateLabel = (value, options = { month: 'short', day: 'numeric', year: 'numeric' }) => value ? new Date(value).toLocaleDateString(undefined, options) : 'Date to be confirmed'
const timeLabel = (value) => value ? new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''
const initials = (name) => String(name || 'Event').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()

const upcomingEvents = computed(() => [...events.value].filter((event) => event.status === 'open' && event.starts_at && new Date(event.starts_at) >= new Date()).sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at)).slice(0, 4))

const statusTotalsFor = (list) => {
  const now = Date.now()
  const values = { completed: 0, ongoing: 0, upcoming: 0, cancelled: 0 }
  list.forEach((event) => {
    if (event.status === 'cancelled') values.cancelled += 1
    else if (event.status === 'closed' || (event.ends_at && new Date(event.ends_at).getTime() < now)) values.completed += 1
    else if (event.starts_at && new Date(event.starts_at).getTime() <= now) values.ongoing += 1
    else values.upcoming += 1
  })
  return values
}
const statusTotals = computed(() => statusTotalsFor(events.value))
const statusRows = computed(() => [
  { label: 'Completed', value: statusTotals.value.completed, color: '#0eb584' },
  { label: 'Ongoing', value: statusTotals.value.ongoing, color: '#3b82f6' },
  { label: 'Upcoming', value: statusTotals.value.upcoming, color: '#f59e0b' },
  { label: 'Cancelled', value: statusTotals.value.cancelled, color: '#dbe1e8' },
])
const statusDonutStyle = computed(() => {
  if (!events.value.length) return { background: '#eef8f5' }
  const total = events.value.length
  const completed = statusTotals.value.completed / total * 100
  const ongoing = statusTotals.value.ongoing / total * 100
  const upcoming = statusTotals.value.upcoming / total * 100
  return { background: `conic-gradient(#0eb584 0 ${completed}%, #3b82f6 ${completed}% ${completed + ongoing}%, #f59e0b ${completed + ongoing}% ${completed + ongoing + upcoming}%, #dbe1e8 ${completed + ongoing + upcoming}% 100%)` }
})

const typeRows = computed(() => {
  const grouped = new Map()
  reports.value.forEach((report) => {
    const category = eventById.value.get(String(report.id))?.category || 'Other'
    grouped.set(category, (grouped.get(category) || 0) + Number(report.registrations || 0))
  })
  return [...grouped.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 5)
})
const eventById = computed(() => new Map(events.value.map((event) => [String(event.id), event])))
const yearRows = computed(() => {
  const grouped = new Map()
  events.value.forEach((event) => {
    const date = event.starts_at ? new Date(event.starts_at) : null
    if (!date || Number.isNaN(date.getTime())) return
    const year = date.getFullYear()
    grouped.set(year, (grouped.get(year) || 0) + 1)
  })
  return [...grouped.entries()].map(([year, value]) => ({ year, value })).sort((a, b) => a.year - b.year)
})
const yearMax = computed(() => Math.max(...yearRows.value.map((row) => row.value), 1))

const loadDashboard = async () => {
  loading.value = true
  error.value = ''
  try {
    const [dashboardResponse, eventsResponse, reportsResponse] = await Promise.all([getDashboard(), api.get('/admin/events'), api.get('/admin/reports')])
    stats.value = dashboardResponse.data
    events.value = (eventsResponse.data.data || eventsResponse.data || []).map(normalizeEvent)
    reports.value = reportsResponse.data.data || reportsResponse.data || []
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load dashboard data.'
  } finally {
    loading.value = false
  }
}
onMounted(loadDashboard)
</script>

<template>
  <AdminLayout>
    <section class="reference-dashboard">
      <header class="reference-dashboard__heading">
        <div><h1>Dashboard</h1></div>
        <time class="reference-dashboard__date" :datetime="new Date().toISOString()">{{ dateLabel(new Date(), { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}</time>
      </header>
      <p v-if="error" class="reference-dashboard__error" role="alert">{{ error }} <button type="button" @click="loadDashboard">Retry</button></p>
      <div v-if="loading" class="reference-dashboard__loading" role="status">Loading your dashboard…</div>

      <template v-else>
        <div class="reference-dashboard__layout">
          <section class="reference-stats reference-dashboard__stats" aria-label="Event statistics">
              <article class="reference-stat stat-teal"><span class="reference-stat__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 2v4m8-4v4M3 10h18"/></svg></span><span>Total Events</span><strong>{{ number(events.length) }}</strong></article>
              <article class="reference-stat stat-blue"><span class="reference-stat__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M15.5 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Total Participants</span><strong>{{ number(stats.users) }}</strong></article>
              <article class="reference-stat stat-amber"><span class="reference-stat__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M17 11h6"/></svg></span><span>Registered Participants</span><strong>{{ number(stats.registrations) }}</strong></article>
              <article class="reference-stat stat-purple"><span class="reference-stat__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></span><span>Checked In</span><strong>{{ number(stats.check_ins) }}</strong></article>
          </section>

          <article class="reference-card reference-status"><header><h2>Event Status</h2><RouterLink to="/admin/events">View all →</RouterLink></header><div class="reference-status__body"><div class="reference-donut" :style="statusDonutStyle"><div><strong>{{ number(events.length) }}</strong><small>Total Events</small></div></div><div class="reference-legend"><p v-for="row in statusRows" :key="row.label"><i :style="{ background: row.color, '--dot': row.color }"></i><span>{{ row.label }}</span><b>{{ row.value }}</b><small>{{ events.length ? Math.round(row.value / events.length * 1000) / 10 : 0 }}%</small></p></div></div></article>
          <article class="reference-card reference-years"><header><div><h2>Total Events by Year</h2><p>Events scheduled in your workspace</p></div><RouterLink to="/admin/events">View all →</RouterLink></header><div v-if="yearRows.length" class="reference-year-bars"><div v-for="row in yearRows" :key="row.year" class="reference-year-bar"><span><i :style="{ height: `${Math.max(row.value / yearMax * 100, 8)}%` }"></i></span><b>{{ number(row.value) }}</b><small>{{ row.year }}</small></div></div><div v-else class="reference-empty">Event totals by year will appear after events are created.</div></article>

          <article class="reference-card reference-upcoming"><header><h2>Upcoming Events</h2><RouterLink to="/admin/events">View all →</RouterLink></header><div v-if="upcomingEvents.length" class="reference-event-list"><RouterLink v-for="event in upcomingEvents" :key="event.id" :to="`/admin/events/${event.id}`" class="reference-event"><div class="reference-event__thumb"><img v-if="event.branding?.image" :src="event.branding.image" alt="" /><span v-else>{{ initials(event.name) }}</span></div><div class="reference-event__body"><div class="reference-event__top"><strong>{{ event.name }}</strong><em :class="`status-${event.status}`">{{ statusLabel(event.status) }}</em></div><small class="reference-event__date">{{ dateLabel(event.starts_at) }} · {{ timeLabel(event.starts_at) }}</small><small class="reference-event__place">{{ event.location || 'Location pending' }}</small></div></RouterLink></div><div v-else class="reference-empty">No upcoming events yet.</div></article>

          <article class="reference-card reference-actions"><h2>Quick Actions</h2><div><RouterLink to="/admin/events/new" class="qa-teal"><span class="reference-stat__icon qa-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>Create Event <b>→</b></RouterLink><RouterLink to="/admin/users" class="qa-blue"><span class="reference-stat__icon qa-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M5 21v-1a7 7 0 0 1 14 0v1"/></svg></span>View Participant <b>→</b></RouterLink><RouterLink to="/admin/check-ins" class="qa-amber"><span class="reference-stat__icon qa-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 12 4 4L15 8"/><path d="M21 5v9a4 4 0 0 1-4 4h-5"/></svg></span>Check-in Event <b>→</b></RouterLink><RouterLink to="/admin/reports" class="qa-purple"><span class="reference-stat__icon qa-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M8 17v-6M13 17V8M18 17v-9"/></svg></span>View Reports <b>→</b></RouterLink></div></article>
        </div>
      </template>
    </section>
  </AdminLayout>
</template>
