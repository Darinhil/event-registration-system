<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { useAuthStore } from '../../stores/auth'

const route = useRoute()
const search = ref(String(route.query.search || ''))
// The admin topbar search navigates here with ?search=…
watch(() => route.query.search, (value) => { search.value = String(value || '') })
const status = ref('all')
const view = ref('cards')
const loading = ref(true)
const error = ref('')
const deletingId = ref(null)
const openMenuId = ref(null)
const events = ref([])
const auth = useAuthStore()
const eventHeading = computed(() => auth.isAdmin ? 'All events' : 'My events')

const readCachedEvents = () => {
  try {
    const cached = JSON.parse(localStorage.getItem('event_list') || '[]')
    return Array.isArray(cached) ? cached.map(normalizeEvent) : []
  } catch {
    return []
  }
}

const saveCachedEvents = (items) => {
  try {
    localStorage.setItem('event_list', JSON.stringify(items))
  } catch {
    // Storage is optional; the API remains the source of truth.
  }
}

// The API returns UTC timestamps; show them in the viewer's local time, as entered in the event form.
const localParts = (iso) => {
  const date = iso ? new Date(iso) : null
  if (!date || Number.isNaN(date.getTime())) return {}
  const pad = (value) => String(value).padStart(2, '0')
  return { date: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`, time: `${pad(date.getHours())}:${pad(date.getMinutes())}` }
}
const normalizeEvent = (event) => {
  const start = localParts(event.starts_at); const end = localParts(event.ends_at)
  return {
    ...event,
    status: event.status === 'published' ? 'open' : event.status,
    registered: event.registrations_count ?? event.registered ?? 0,
    maximum_participants: event.capacity ?? event.maximum_participants,
    start_date: start.date || event.start_date,
    start_time: start.time || event.start_time,
    end_time: end.time || event.end_time,
  }
}

const loadEvents = async () => {
  loading.value = true
  error.value = ''
  const cached = readCachedEvents()
  if (cached.length) {
    // Show the last known workspace immediately while refreshing in the
    // background. This removes the blank loading state on repeat visits.
    events.value = cached
    loading.value = false
  }
  try {
    // The list already carries each event's branding, so the server copy is always
    // current; events without a banner show the initials placeholder.
    const { data } = await api.get('/admin/events')
    events.value = (data.data || data).map(normalizeEvent)
    saveCachedEvents(events.value)
  } catch (requestError) {
    if (!events.value.length) events.value = cached
    const message = requestError.code === 'ECONNABORTED'
      ? 'The events service is taking too long to respond.'
      : requestError.response?.data?.message || 'Unable to load events from the API.'
    error.value = events.value.length ? `${message} Showing saved data; retry to refresh.` : message
  } finally {
    loading.value = false
  }
}

const deleteEvent = async (event) => {
  openMenuId.value = null
  if (!event.id || !window.confirm(`Delete “${event.name}”? This cannot be undone.`)) return
  deletingId.value = event.id
  error.value = ''
  try {
    await api.delete(`/admin/events/${event.id}`)
    events.value = events.value.filter((item) => item.id !== event.id)
    saveCachedEvents(events.value)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to delete this event.'
  } finally {
    deletingId.value = null
  }
}

const statusMeta = {
  open: { label: 'Published', tone: 'published' },
  draft: { label: 'Draft', tone: 'draft' },
  closed: { label: 'Closed', tone: 'closed' },
  cancelled: { label: 'Cancelled', tone: 'cancelled' },
}
const statusOf = (event) => statusMeta[event.status] || { label: event.status || 'Unknown', tone: 'draft' }

const searchMatches = computed(() => {
  const term = search.value.trim().toLowerCase()
  return events.value.filter((event) => !term || `${event.name} ${event.location} ${event.category} ${event.description}`.toLowerCase().includes(term))
})
const filteredEvents = computed(() => searchMatches.value.filter((event) => status.value === 'all' || event.status === status.value))
const statusTabs = computed(() => [
  { value: 'all', label: 'All', count: searchMatches.value.length },
  ...['open', 'draft', 'closed', 'cancelled']
    .map((value) => ({ value, label: statusMeta[value].label, count: searchMatches.value.filter((event) => event.status === value).length }))
    // Keep the tab row short: hide empty statuses unless that filter is active.
    .filter((tab) => tab.count || tab.value === status.value || tab.value !== 'cancelled'),
])
const totalRegistered = computed(() => events.value.reduce((sum, event) => sum + Number(event.registered || 0), 0))
const isFiltered = computed(() => Boolean(search.value.trim()) || status.value !== 'all')
const clearFilters = () => { search.value = ''; status.value = 'all' }

const number = (value) => Number(value || 0).toLocaleString()
const dateLabel = (event, options = { month: 'short', day: 'numeric', year: 'numeric' }) => event.start_date ? new Date(`${event.start_date}T00:00:00`).toLocaleDateString(undefined, options) : 'Date to be confirmed'
const timeRange = (event) => [event.start_time, event.end_time].filter(Boolean).join(' – ')
const initials = (name) => String(name || 'Event').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
const bannerOf = (event) => event.branding?.image || event.image || ''
// Fallback covers pick a stable tint from a curated palette (teal, blue, indigo, amber, rose, cyan)
// so they vary between events but never change between visits.
const coverHues = [168, 214, 236, 32, 346, 192]
const coverHue = (event) => coverHues[[...String(event.id ?? event.name)].reduce((hash, char) => (hash * 31 + char.charCodeAt(0)) >>> 0, 7) % coverHues.length]
const capacityOf = (event) => Number(event.maximum_participants || 0)
const fillPercent = (event) => capacityOf(event) ? Math.min(100, Math.round(Number(event.registered || 0) / capacityOf(event) * 100)) : 0
const fillTone = (event) => {
  const percent = fillPercent(event)
  return percent >= 100 ? 'full' : percent >= 80 ? 'high' : 'normal'
}

const toggleMenu = (id) => { openMenuId.value = openMenuId.value === id ? null : id }
const onDocumentClick = (event) => { if (!event.target.closest('.ev-menu')) openMenuId.value = null }
const onKeydown = (event) => { if (event.key === 'Escape') openMenuId.value = null }
onMounted(() => {
  loadEvents()
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <AdminLayout>
    <section class="ev-page">
      <header class="ev-header">
        <div>
          <h1>Events</h1>
          <p>Create, publish and manage registrations for your events.</p>
        </div>
        <RouterLink class="rd-button rd-button--primary" to="/admin/events/new"><AppIcon name="plus" :size="17" />Create event</RouterLink>
      </header>

      <div v-if="error" class="ev-alert" role="alert">
        <AppIcon name="alert-circle" :size="20" />
        <span>{{ error }}</span>
        <button type="button" :disabled="loading" @click="loadEvents">{{ loading ? 'Retrying…' : 'Retry' }}</button>
      </div>

      <article class="ev-panel">
        <div class="ev-toolbar">
          <div class="ev-toolbar__title">
            <h2>{{ eventHeading }}</h2>
            <p v-if="!loading || events.length">{{ number(events.length) }} {{ events.length === 1 ? 'event' : 'events' }} · {{ number(totalRegistered) }} total registrations</p>
            <p v-else>Loading your workspace…</p>
          </div>
          <div class="ev-toolbar__controls">
            <label class="ev-search">
              <AppIcon name="search" :size="18" />
              <input v-model="search" type="search" placeholder="Search events" aria-label="Search events" />
              <button v-if="search" type="button" class="ev-search__clear" aria-label="Clear search" @click="search = ''"><AppIcon name="x" :size="15" /></button>
            </label>
            <div class="ev-segmented" role="group" aria-label="Layout">
              <button type="button" :class="{ 'is-active': view === 'cards' }" :aria-pressed="view === 'cards'" aria-label="Card view" title="Card view" @click="view = 'cards'"><AppIcon name="layout-grid" :size="18" /></button>
              <button type="button" :class="{ 'is-active': view === 'table' }" :aria-pressed="view === 'table'" aria-label="Table view" title="Table view" @click="view = 'table'"><AppIcon name="list" :size="18" /></button>
            </div>
          </div>
        </div>

        <div class="ev-tabs" role="tablist" aria-label="Filter by status">
          <button v-for="tab in statusTabs" :key="tab.value" type="button" role="tab" :aria-selected="status === tab.value" :class="{ 'is-active': status === tab.value }" @click="status = tab.value">
            {{ tab.label }}<span>{{ number(tab.count) }}</span>
          </button>
        </div>

        <div v-if="loading && !events.length" class="ev-grid" role="status" aria-label="Loading events">
          <div v-for="n in 3" :key="n" class="ev-card ev-card--skeleton" aria-hidden="true"><div class="ev-skel ev-skel--cover"></div><div class="ev-card__body"><div class="ev-skel ev-skel--title"></div><div class="ev-skel ev-skel--line"></div><div class="ev-skel ev-skel--line ev-skel--short"></div><div class="ev-skel ev-skel--bar"></div></div></div>
        </div>

        <div v-else-if="filteredEvents.length && view === 'cards'" class="ev-grid" :class="{ 'ev-grid--single': filteredEvents.length === 1 }">
          <article v-for="event in filteredEvents" :key="event.id" class="ev-card" :class="{ 'is-deleting': deletingId === event.id }">
            <RouterLink :to="`/admin/events/${event.id}`" class="ev-card__cover" :style="{ '--cover-hue': coverHue(event) }" tabindex="-1" aria-hidden="true">
              <img v-if="bannerOf(event)" :src="bannerOf(event)" alt="" loading="lazy" />
              <span v-else class="ev-card__fallback"><span>{{ initials(event.name) }}</span></span>
              <span class="ev-chip">{{ event.category || 'Event' }}</span>
              <span class="ev-badge ev-badge--floating" :class="`ev-badge--${statusOf(event).tone}`">{{ statusOf(event).label }}</span>
            </RouterLink>

            <div class="ev-card__body">
              <div class="ev-card__heading">
                <h3><RouterLink :to="`/admin/events/${event.id}`" :title="event.name">{{ event.name }}</RouterLink></h3>
                <p :class="{ 'is-empty': !event.description }">{{ event.description || 'No description added yet.' }}</p>
              </div>

              <ul class="ev-facts">
                <li><AppIcon name="calendar" :size="16" /><span>{{ dateLabel(event, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}</span></li>
                <li v-if="timeRange(event)"><AppIcon name="clock" :size="16" /><span>{{ timeRange(event) }}</span></li>
                <li><AppIcon name="pin" :size="16" /><span :title="event.location">{{ event.location || 'Location pending' }}</span></li>
              </ul>

              <div class="ev-capacity" :class="`is-${fillTone(event)}`">
                <div class="ev-capacity__row">
                  <span><strong>{{ number(event.registered) }}</strong><template v-if="capacityOf(event)"> / {{ number(capacityOf(event)) }}</template> registered</span>
                  <b v-if="capacityOf(event)">{{ fillPercent(event) }}%</b>
                  <b v-else>No limit</b>
                </div>
                <div class="ev-capacity__track" role="progressbar" :aria-valuenow="fillPercent(event)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${event.name} capacity used`"><i :style="{ width: `${capacityOf(event) ? Math.max(fillPercent(event), event.registered ? 2 : 0) : 0}%` }"></i></div>
              </div>
            </div>

            <footer class="ev-card__actions">
              <RouterLink class="ev-btn ev-btn--primary" :to="`/admin/events/${event.id}`"><AppIcon name="eye" :size="16" />View</RouterLink>
              <RouterLink class="ev-btn" :to="`/admin/events/${event.id}/edit`"><AppIcon name="pen" :size="16" />Edit</RouterLink>
              <RouterLink class="ev-btn" :to="`/admin/events/${event.id}/registrants`"><AppIcon name="users" :size="16" />Registrants</RouterLink>
              <div class="ev-menu">
                <button type="button" class="ev-btn ev-btn--icon" :aria-expanded="openMenuId === event.id" aria-haspopup="menu" :aria-label="`More actions for ${event.name}`" @click="toggleMenu(event.id)"><AppIcon name="more" :size="18" /></button>
                <div v-if="openMenuId === event.id" class="ev-menu__panel" role="menu">
                  <RouterLink role="menuitem" :to="`/admin/events/${event.id}#qr`"><AppIcon name="qr-code" :size="16" />QR Pass</RouterLink>
                  <hr />
                  <button type="button" role="menuitem" class="is-danger" :disabled="deletingId === event.id" @click="deleteEvent(event)"><AppIcon name="trash" :size="16" />{{ deletingId === event.id ? 'Deleting…' : 'Delete event' }}</button>
                </div>
              </div>
            </footer>
          </article>
        </div>

        <div v-else-if="filteredEvents.length" class="ev-table-wrap">
          <table class="ev-table">
            <thead><tr><th scope="col">Event</th><th scope="col">Date &amp; time</th><th scope="col" class="col-location">Location</th><th scope="col" class="col-capacity">Registrations</th><th scope="col">Status</th><th scope="col" class="col-actions"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
              <tr v-for="event in filteredEvents" :key="event.id" :class="{ 'is-deleting': deletingId === event.id }">
                <td class="col-event">
                  <RouterLink :to="`/admin/events/${event.id}`" class="ev-table__event">
                    <span class="ev-table__thumb" :style="{ '--cover-hue': coverHue(event) }"><img v-if="bannerOf(event)" :src="bannerOf(event)" alt="" loading="lazy" /><template v-else>{{ initials(event.name) }}</template></span>
                    <span><strong :title="event.name">{{ event.name }}</strong><small>{{ event.category || 'Event' }}</small></span>
                  </RouterLink>
                </td>
                <td><span class="ev-table__stack"><span>{{ dateLabel(event) }}</span><small v-if="timeRange(event)">{{ timeRange(event) }}</small></span></td>
                <td class="col-location"><span class="ev-table__ellipsis" :title="event.location">{{ event.location || 'Location pending' }}</span></td>
                <td class="col-capacity">
                  <div class="ev-capacity ev-capacity--compact" :class="`is-${fillTone(event)}`">
                    <div class="ev-capacity__row"><span><strong>{{ number(event.registered) }}</strong><template v-if="capacityOf(event)"> / {{ number(capacityOf(event)) }}</template></span><b v-if="capacityOf(event)">{{ fillPercent(event) }}%</b></div>
                    <div v-if="capacityOf(event)" class="ev-capacity__track"><i :style="{ width: `${Math.max(fillPercent(event), event.registered ? 2 : 0)}%` }"></i></div>
                  </div>
                </td>
                <td><span class="ev-badge" :class="`ev-badge--${statusOf(event).tone}`">{{ statusOf(event).label }}</span></td>
                <td class="col-actions">
                  <div class="ev-table__actions">
                    <RouterLink class="ev-btn ev-btn--icon" :to="`/admin/events/${event.id}`" :aria-label="`View ${event.name}`" title="View"><AppIcon name="eye" :size="17" /></RouterLink>
                    <RouterLink class="ev-btn ev-btn--icon" :to="`/admin/events/${event.id}/edit`" :aria-label="`Edit ${event.name}`" title="Edit"><AppIcon name="pen" :size="17" /></RouterLink>
                    <div class="ev-menu">
                      <button type="button" class="ev-btn ev-btn--icon" :aria-expanded="openMenuId === event.id" aria-haspopup="menu" :aria-label="`More actions for ${event.name}`" @click="toggleMenu(event.id)"><AppIcon name="more" :size="18" /></button>
                      <div v-if="openMenuId === event.id" class="ev-menu__panel" role="menu">
                        <RouterLink role="menuitem" :to="`/admin/events/${event.id}/registrants`"><AppIcon name="users" :size="16" />Registrants</RouterLink>
                        <RouterLink role="menuitem" :to="`/admin/events/${event.id}#qr`"><AppIcon name="qr-code" :size="16" />QR Pass</RouterLink>
                        <hr />
                        <button type="button" role="menuitem" class="is-danger" :disabled="deletingId === event.id" @click="deleteEvent(event)"><AppIcon name="trash" :size="16" />{{ deletingId === event.id ? 'Deleting…' : 'Delete event' }}</button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else-if="isFiltered && events.length" class="ev-empty">
          <span class="ev-empty__icon"><AppIcon name="search" :size="26" /></span>
          <h3>No matching events</h3>
          <p>No events match <template v-if="search.trim()">“{{ search.trim() }}”</template><template v-if="search.trim() && status !== 'all'"> in </template><template v-if="status !== 'all'">{{ statusOf({ status }).label.toLowerCase() }}</template>. Try a different search or status.</p>
          <button type="button" class="rd-button rd-button--soft" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
        </div>

        <div v-else-if="!error" class="ev-empty">
          <span class="ev-empty__icon ev-empty__icon--brand"><AppIcon name="calendar-plus" :size="28" /></span>
          <h3>Your event workspace is ready</h3>
          <p>Create your first event to start collecting registrations and sharing a check-in QR code.</p>
          <RouterLink class="rd-button rd-button--primary" to="/admin/events/new"><AppIcon name="plus" :size="17" />Create your first event</RouterLink>
        </div>

        <div v-else class="ev-empty">
          <span class="ev-empty__icon ev-empty__icon--danger"><AppIcon name="alert-circle" :size="26" /></span>
          <h3>Events couldn’t be loaded</h3>
          <p>Check your connection and try again.</p>
          <button type="button" class="rd-button rd-button--soft" :disabled="loading" @click="loadEvents"><AppIcon name="refresh" :size="16" />Try again</button>
        </div>
      </article>
    </section>
  </AdminLayout>
</template>
