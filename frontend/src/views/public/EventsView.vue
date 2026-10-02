<script setup>
import { computed, ref } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import api from '../../services/api'

const events = ref([])
const loading = ref(true)
const error = ref('')
const search = ref('')
const category = ref('all')

const categories = computed(() => [...new Set(events.value.map((event) => event.category).filter(Boolean))])
const filteredEvents = computed(() => events.value.filter((event) => {
  const query = search.value.trim().toLowerCase()
  const matchesSearch = !query || `${event.name} ${event.description || ''} ${event.location || ''} ${event.category || ''}`.toLowerCase().includes(query)
  return matchesSearch && (category.value === 'all' || event.category === category.value)
}))
const dateLabel = (event) => new Date(event.starts_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
const load = async () => {
  try { const response = await api.get('/events'); events.value = response.data.data || response.data } catch (requestError) { error.value = requestError.response?.data?.message || 'Events could not be loaded. Please try again.' } finally { loading.value = false }
}
load()
</script>

<template>
  <UserLayout>
    <section class="user-events-page">
      <header class="user-events-heading"><div><p class="user-events-eyebrow">Discover what’s next</p><h1>Events</h1><p>Find an event, reserve your place, and keep your registration in one place.</p></div><span class="user-events-count">{{ filteredEvents.length }} available</span></header>
      <div class="user-events-toolbar"><input v-model="search" type="search" placeholder="Search events, places, or categories…" aria-label="Search events" /><select v-model="category" aria-label="Filter by category"><option value="all">All categories</option><option v-for="item in categories" :key="item" :value="item">{{ item }}</option></select></div>
      <div v-if="loading" class="user-events-state">Loading available events…</div>
      <div v-else-if="error" class="user-events-state user-events-state--error"><strong>We couldn’t load events</strong><span>{{ error }}</span><button class="secondary-button" type="button" @click="loading = true; error = ''; load()">Try again</button></div>
      <div v-else-if="filteredEvents.length" class="user-events-grid">
        <RouterLink v-for="event in filteredEvents" :key="event.id" class="user-event-card" :to="`/events/${event.id}`">
          <div class="user-event-image"><img v-if="event.branding?.image" :src="event.branding.image" :alt="`${event.name} banner`" loading="lazy" /><div v-else class="user-event-image-fallback">{{ event.name?.slice(0, 1) }}</div><span>{{ event.category || 'Event' }}</span></div>
          <div class="user-event-card-body"><div class="user-event-date">{{ dateLabel(event) }}</div><h2>{{ event.name }}</h2><p>{{ event.description || 'Join us for an upcoming event.' }}</p><div class="user-event-meta"><span>📍 {{ event.location }}</span><span>⌛ {{ Math.max(0, event.capacity - event.registrations_count) }} seats left</span></div><div class="user-event-footer"><b>Registration open</b><span>View details →</span></div></div>
        </RouterLink>
      </div>
      <div v-else class="user-events-state user-events-empty"><strong>{{ search || category !== 'all' ? 'No matching events' : 'No events are available right now' }}</strong><span>{{ search || category !== 'all' ? 'Try another search or category.' : 'Published events will appear here as soon as they are available.' }}</span></div>
    </section>
  </UserLayout>
</template>
