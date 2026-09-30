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
        <div><span class="stat-mark blue">◈</span><div><small>Total events</small><strong>{{ count('all') }}</strong></div></div>
        <div><span class="stat-mark green">✓</span><div><small>Published</small><strong>{{ count('open') }}</strong></div></div>
        <div><span class="stat-mark amber">◷</span><div><small>Drafts</small><strong>{{ count('draft') }}</strong></div></div>
        <div><span class="stat-mark purple">♧</span><div><small>Registrations</small><strong>{{ events.reduce((sum, event) => sum + (event.registered || 0), 0) }}</strong></div></div>
      </div>
      <article class="event-list-panel">
        <div class="event-list-toolbar">
          <div><h2>All events</h2><p v-if="!loading">{{ filteredEvents.length }} event{{ filteredEvents.length === 1 ? '' : 's' }} in your workspace</p></div>
          <div class="toolbar-controls"><label class="events-search">⌕<input v-model="search" placeholder="Search events..." /></label><select v-model="status"><option value="all">All statuses</option><option value="open">Published</option><option value="draft">Draft</option><option value="closed">Closed</option></select><div class="view-toggle"><button type="button" :class="{ active: view === 'cards' }" @click="view = 'cards'">▦</button><button type="button" :class="{ active: view === 'table' }" @click="view = 'table'">☷</button></div></div>
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
              <div class="event-card-meta"><div><small>Date &amp; schedule</small><span>◷ {{ dateLabel(event) }}<template v-if="event.start_time"> · {{ event.start_time }}</template></span></div><div><small>Location</small><span>⌖ {{ event.location || 'Location pending' }}</span></div></div>
              <div class="event-card-registration"><div><strong>{{ event.registered || 0 }}</strong><small>registered</small></div><div class="capacity-track"><span :style="{ width: `${Math.min(100, ((event.registered || 0) / (event.maximum_participants || 100)) * 100)}%` }"></span></div><small>{{ event.maximum_participants || '—' }} capacity</small></div>
            </div>
            <footer class="event-card-actions"><RouterLink class="event-action-primary" :to="`/admin/events/${event.id}`">◉ View Event</RouterLink><RouterLink :to="`/admin/events/${event.id}/edit`">✎ Edit</RouterLink><RouterLink :to="`/admin/events/${event.id}/registrants`">♙ Registrants <b>{{ event.registered || 0 }}</b></RouterLink><RouterLink :to="`/admin/events/${event.id}#qr`">▦ QR Pass</RouterLink><button type="button" class="delete-event-action" :disabled="deletingId === event.id" @click="deleteEvent(event)">{{ deletingId === event.id ? 'Deleting…' : 'Delete' }}</button></footer>
          </article>
        </div>
        <div v-else-if="!loading && filteredEvents.length" class="managed-events-table"><table><thead><tr><th>Event</th><th>Date &amp; location</th><th>Registrations</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="event in filteredEvents" :key="event.id"><td><div class="table-event-name"><img v-if="event.branding?.image || event.image" class="event-table-thumb" :src="event.branding?.image || event.image" alt="" /><div><strong>{{ event.name }}</strong><small>{{ event.category || 'Event' }}</small></div></div></td><td>{{ dateLabel(event) }}<small>{{ event.location || 'Location pending' }}</small></td><td><strong>{{ event.registered || 0 }}</strong> / {{ event.maximum_participants || '∞' }}</td><td><span class="event-status" :class="event.status">{{ event.status === 'open' ? 'Published' : event.status }}</span></td><td class="table-event-actions"><RouterLink :to="`/admin/events/${event.id}`">Open →</RouterLink><button type="button" class="delete-event-action" :disabled="deletingId === event.id" @click="deleteEvent(event)">{{ deletingId === event.id ? 'Deleting…' : 'Delete' }}</button></td></tr></tbody></table></div>
        <div v-else-if="!loading" class="event-empty-state"><div class="empty-icon">✦</div><h3>{{ search || status !== 'all' ? 'No matching events' : 'Your event workspace is ready' }}</h3><p>{{ search || status !== 'all' ? 'Try adjusting your search or status filter.' : 'Create your first event to start collecting registrations and sharing a QR code.' }}</p><RouterLink class="primary-button" to="/admin/events/new">Create your first event</RouterLink></div>
      </article>
    </section>
  </AdminLayout>
</template>
