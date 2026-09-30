<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import api from '../../services/api'

const search = ref('')
const status = ref('all')
const view = ref('cards')
const loading = ref(true)
const error = ref('')
const deletingId = ref(null)
const events = ref([])

const normalizeEvent = (event) => ({
  ...event,
  status: event.status === 'published' ? 'open' : event.status,
  registered: event.registrations_count ?? event.registered ?? 0,
  maximum_participants: event.capacity ?? event.maximum_participants,
  start_date: event.start_date || event.starts_at?.slice(0, 10),
  start_time: event.start_time || event.starts_at?.slice(11, 16),
  end_time: event.end_time || event.ends_at?.slice(11, 16),
})

const loadEvents = async () => {
  try {
    const { data } = await api.get('/admin/events')
    events.value = (data.data || data).map(normalizeEvent)
  } catch (requestError) {
    const local = JSON.parse(localStorage.getItem('event_list') || '[]')
    events.value = local
    error.value = requestError.response?.data?.message || (local.length ? '' : 'Unable to load events from the API.')
  } finally {
    loading.value = false
  }
}

const deleteEvent = async (event) => {
  if (!event.id || !window.confirm(`Delete “${event.name}”? This cannot be undone.`)) return
  deletingId.value = event.id
  error.value = ''
  try {
    await api.delete(`/admin/events/${event.id}`)
    events.value = events.value.filter((item) => item.id !== event.id)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to delete this event.'
  } finally {
    deletingId.value = null
  }
}

const filteredEvents = computed(() => events.value.filter((event) => `${event.name} ${event.location} ${event.category}`.toLowerCase().includes(search.value.toLowerCase()) && (status.value === 'all' || event.status === status.value)))
const count = (state) => events.value.filter((event) => state === 'all' ? true : event.status === state).length
const dateLabel = (event) => event.start_date ? new Date(`${event.start_date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : 'Date to be confirmed'
onMounted(loadEvents)
</script>

<template>
  <AdminLayout>
    <section class="event-dashboard-page">
      <header class="dashboard-page-heading">
        <div><h1>Events</h1><p>Plan, publish, and manage every registration experience from one place.</p></div>
        <RouterLink class="primary-button dashboard-create" to="/admin/events/new">+ Create event</RouterLink>
      </header>
      <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
      <div class="event-overview-stats">
        <div><span class="stat-mark blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8.5A2.5 2.5 0 0 1 5.5 6h13A2.5 2.5 0 0 1 21 8.5v7A2.5 2.5 0 0 1 18.5 18h-13A2.5 2.5 0 0 1 3 15.5z"/><path d="M3 9.5h18"/></svg></span><div><small>Total events</small><strong>{{ count('all') }}</strong></div></div>
        <div><span class="stat-mark green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span><div><small>Published</small><strong>{{ count('open') }}</strong></div></div>
        <div><span class="stat-mark amber"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 4H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5z"/><path d="M14 4v5h5"/></svg></span><div><small>Drafts</small><strong>{{ count('draft') }}</strong></div></div>
        <div><span class="stat-mark purple"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.87"/></svg></span><div><small>Registrations</small><strong>{{ events.reduce((sum, event) => sum + (event.registered || 0), 0) }}</strong></div></div>
      </div>
      <article class="event-list-panel">
        <div class="event-list-toolbar">
          <div><h2>All events</h2><p v-if="!loading">{{ filteredEvents.length }} event{{ filteredEvents.length === 1 ? '' : 's' }} in your workspace</p></div>
          <div class="toolbar-controls"><label class="events-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg><input v-model="search" placeholder="Search events..." /></label><select v-model="status"><option value="all">All statuses</option><option value="open">Published</option><option value="draft">Draft</option><option value="closed">Closed</option></select><div class="view-toggle"><button type="button" aria-label="Card view" :class="{ active: view === 'cards' }" @click="view = 'cards'"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg></button><button type="button" aria-label="Table view" :class="{ active: view === 'table' }" @click="view = 'table'"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg></button></div></div>
        </div>
        <div v-if="loading" class="dynamic-loading">Loading events…</div>
        <div v-else-if="filteredEvents.length && view === 'cards'" class="event-card-grid">
          <article v-for="event in filteredEvents" :key="event.id" class="managed-event-card">
            <div class="event-card-visual">
              <img v-if="event.branding?.image || event.image" class="event-card-banner" :src="event.branding?.image || event.image" alt="" />
              <div v-else class="event-card-banner event-card-banner--empty">{{ event.name.slice(0, 2).toUpperCase() }}</div>
              <div class="event-card-visual-shade"></div>
              <span class="event-card-overlay-category">{{ event.category || 'Event' }}</span>
              <span class="event-card-overlay-status">● {{ event.status === 'open' ? 'Published' : event.status }}</span>
            </div>
            <div class="event-card-body">
              <h3>{{ event.name }}</h3>
              <p class="event-card-description">{{ event.description || 'No description added yet.' }}</p>
              <div class="event-card-meta"><div><small>Date &amp; schedule</small><span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18"/><path d="M8 2.5v4M16 2.5v4"/></svg>{{ dateLabel(event) }}<template v-if="event.start_time"> · {{ event.start_time }}</template></span></div><div><small>Location</small><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>{{ event.location || 'Location pending' }}</span></div></div>
              <div class="event-card-registration"><div><strong>{{ event.registered || 0 }}</strong><small>registered</small></div><div class="capacity-track"><span :style="{ width: `${Math.min(100, ((event.registered || 0) / (event.maximum_participants || 100)) * 100)}%` }"></span></div><small>{{ event.maximum_participants || '—' }} capacity</small></div>
            </div>
            <footer class="event-card-actions"><RouterLink class="event-action-primary" :to="`/admin/events/${event.id}`"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>View</RouterLink><RouterLink :to="`/admin/events/${event.id}/edit`"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Edit</RouterLink><RouterLink :to="`/admin/events/${event.id}/registrants`"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.87"/></svg>Registrants <b>{{ event.registered || 0 }}</b></RouterLink><RouterLink :to="`/admin/events/${event.id}#qr`"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM21 21h.01M18 21h.01"/></svg>QR Pass</RouterLink><button type="button" class="delete-event-action" :disabled="deletingId === event.id" @click="deleteEvent(event)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="m19 6-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>{{ deletingId === event.id ? 'Deleting…' : 'Delete' }}</button></footer>
          </article>
        </div>
        <div v-else-if="!loading && filteredEvents.length" class="managed-events-table"><table><thead><tr><th>Event</th><th>Date &amp; location</th><th>Registrations</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="event in filteredEvents" :key="event.id"><td><div class="table-event-name"><img v-if="event.branding?.image || event.image" class="event-table-thumb" :src="event.branding?.image || event.image" alt="" /><div><strong>{{ event.name }}</strong><small>{{ event.category || 'Event' }}</small></div></div></td><td>{{ dateLabel(event) }}<small>{{ event.location || 'Location pending' }}</small></td><td><strong>{{ event.registered || 0 }}</strong> / {{ event.maximum_participants || '∞' }}</td><td><span class="event-status" :class="event.status">{{ event.status === 'open' ? 'Published' : event.status }}</span></td><td class="table-event-actions"><RouterLink :to="`/admin/events/${event.id}`">Open →</RouterLink><button type="button" class="delete-event-action" :disabled="deletingId === event.id" @click="deleteEvent(event)">{{ deletingId === event.id ? 'Deleting…' : 'Delete' }}</button></td></tr></tbody></table></div>
        <div v-else-if="!loading" class="event-empty-state"><div class="empty-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8.5A2.5 2.5 0 0 1 5.5 6h13A2.5 2.5 0 0 1 21 8.5v7A2.5 2.5 0 0 1 18.5 18h-13A2.5 2.5 0 0 1 3 15.5z"/><path d="M3 9.5h18"/></svg></div><h3>{{ search || status !== 'all' ? 'No matching events' : 'Your event workspace is ready' }}</h3><p>{{ search || status !== 'all' ? 'Try adjusting your search or status filter.' : 'Create your first event to start collecting registrations and sharing a QR code.' }}</p><RouterLink class="primary-button" to="/admin/events/new">Create your first event</RouterLink></div>
      </article>
    </section>
  </AdminLayout>
</template>
