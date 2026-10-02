<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import api from '../../services/api'

const route = useRoute()
const event = ref(null)
const loading = ref(true)
const error = ref('')

const image = computed(() => event.value?.branding?.image || event.value?.image || '')
const dateLabel = computed(() => event.value?.starts_at
  ? new Date(event.value.starts_at).toLocaleDateString(undefined, { dateStyle: 'full' })
  : 'Date to be confirmed')
const timeLabel = computed(() => event.value?.starts_at
  ? `${new Date(event.value.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} – ${new Date(event.value.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
  : 'Time to be confirmed')
const isOpen = computed(() => event.value?.status === 'published' || event.value?.status === 'open')

const load = async () => {
  try {
    const { data } = await api.get(`/events/${route.params.eventId}`)
    event.value = { ...data.data, remaining: data.remaining }
  } catch (requestError) {
    error.value = requestError.response?.status === 404 ? 'This event is no longer available.' : 'The event could not be loaded.'
  } finally {
    loading.value = false
  }
}

load()
</script>

<template>
  <UserLayout>
    <section class="public-event-page">
      <div v-if="loading" class="dynamic-loading">Loading event…</div>
      <div v-else-if="event" class="public-event-shell">
        <div v-if="image" class="public-event-banner">
          <img :src="image" :alt="`${event.name} banner`" loading="lazy" decoding="async" />
        </div>

        <header class="public-event-hero">
          <div>
            <p class="public-event-kicker">{{ event.category || 'Event registration' }}</p>
            <h1>{{ event.name }}</h1>
            <p class="public-event-description">{{ event.description || 'Join us for this event.' }}</p>
          </div>
          <span class="public-event-status" :class="{ closed: !isOpen }">
            {{ isOpen ? 'Registration open' : 'Registration closed' }}
          </span>
        </header>

        <div class="public-event-content">
          <main>
            <div class="public-event-meta">
              <div><span>📅</span><strong>{{ dateLabel }}</strong><small>Event date</small></div>
              <div><span>🕐</span><strong>{{ timeLabel }}</strong><small>Event time</small></div>
              <div><span>📍</span><strong>{{ event.location || 'Location to be confirmed' }}</strong><small>Location</small></div>
              <div><span>👤</span><strong>{{ event.creator?.name || 'Event organizer' }}</strong><small>Organizer</small></div>
            </div>

            <section class="public-event-about">
              <p class="public-event-label">About this event</p>
              <h2>Reserve your place</h2>
              <p>Complete the attendee registration form to secure your place. You will receive a confirmation and pass after registering.</p>
            </section>
          </main>

          <aside class="public-event-register-card">
            <span class="public-event-register-icon">✓</span>
            <h2>{{ isOpen ? 'Ready to join?' : 'Registration closed' }}</h2>
            <p>{{ isOpen ? 'Save your place by completing the registration form.' : 'This event is not accepting registrations right now.' }}</p>
            <RouterLink v-if="isOpen" class="primary-button" :to="`/events/${event.id}/register`">Register now <span>→</span></RouterLink>
            <RouterLink v-else class="secondary-button" to="/">Browse other events</RouterLink>
            <small v-if="event.capacity">{{ event.remaining ?? event.capacity }} places available</small>
          </aside>
        </div>
      </div>
      <div v-else class="public-event-empty">
        <h2>{{ error || 'Event not found' }}</h2>
        <RouterLink to="/">Return home</RouterLink>
      </div>
    </section>
  </UserLayout>
</template>
