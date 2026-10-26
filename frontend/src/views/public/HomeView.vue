<script setup>
import { computed, onMounted, ref } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Upcoming events people can still register for (the public endpoint already
// leaves out drafts, ended events and full events).
const events = ref([])
const loading = ref(true)
const error = ref('')
const load = async () => {
  loading.value = true; error.value = ''
  try {
    const { data } = await api.get('/events')
    events.value = (Array.isArray(data) ? data : data.data || []).slice().sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))
  } catch {
    error.value = 'We couldn’t load upcoming events right now.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const dateParts = (value) => {
  const date = value ? new Date(value) : null
  return date ? { month: date.toLocaleDateString(undefined, { month: 'short' }), day: date.getDate(), full: date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }), time: date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) } : null
}
const spotsLeft = (event) => event.capacity ? Math.max(0, event.capacity - (event.registrations_count || 0)) : null
const visible = computed(() => events.value.slice(0, 6))
</script>

<template>
  <UserLayout>
    <section class="home-hero">
      <h1>Make room for <em>the people</em> who make things.</h1>
      <p class="home-lede">
        A calm, simple registration desk for a day of useful conversations,
        practical ideas, and good company.
      </p>

      <div class="home-hero-actions">
        <a class="home-cta" href="#upcoming">
          Browse events
          <AppIcon name="arrow-down" :size="18" />
        </a>
        <RouterLink class="home-ghost" :to="auth.isAuthenticated ? '/me' : '/login'">
          <AppIcon :name="auth.isAuthenticated ? 'user' : 'arrow-right'" :size="18" />
          {{ auth.isAuthenticated ? 'My registrations' : 'Sign in' }}
        </RouterLink>
      </div>
      <p class="hm-venue">At the venue? <RouterLink to="/check-in">Check in to your event <AppIcon name="arrow-right" :size="14" /></RouterLink></p>
    </section>

    <section id="upcoming" class="hm-events" aria-labelledby="hm-events-title">
      <header class="hm-events__head">
        <div><h2 id="hm-events-title">Upcoming events</h2><p>Open for registration now.</p></div>
      </header>

      <div v-if="loading" class="hm-grid" role="status" aria-label="Loading events"><div v-for="n in 3" :key="n" class="hm-skel"></div></div>
      <div v-else-if="error" class="hm-state" role="alert"><p>{{ error }}</p><button type="button" class="pe-btn" @click="load"><AppIcon name="refresh" :size="16" />Try again</button></div>
      <div v-else-if="!events.length" class="hm-state">
        <span class="hm-state__icon"><AppIcon name="calendar" :size="24" /></span>
        <strong>No events are open for registration right now</strong>
        <p>Check back soon — new events appear here as soon as organizers publish them.</p>
      </div>
      <ul v-else class="hm-grid">
        <li v-for="event in visible" :key="event.id">
          <RouterLink class="hm-card" :to="`/events/${event.id}`">
            <span class="hm-card__cover">
              <img v-if="event.branding?.image" :src="event.branding.image" alt="" loading="lazy" />
              <span v-else class="hm-card__date" aria-hidden="true"><small>{{ dateParts(event.starts_at)?.month }}</small><b>{{ dateParts(event.starts_at)?.day }}</b></span>
            </span>
            <span class="hm-card__body">
              <span v-if="event.category" class="hm-card__tag">{{ event.category }}</span>
              <strong>{{ event.name }}</strong>
              <span class="hm-card__meta"><AppIcon name="calendar" :size="15" />{{ dateParts(event.starts_at)?.full }} · {{ dateParts(event.starts_at)?.time }}</span>
              <span class="hm-card__meta"><AppIcon name="pin" :size="15" />{{ event.location || 'Location to be confirmed' }}</span>
              <span v-if="spotsLeft(event) !== null && spotsLeft(event) <= 10" class="hm-card__low">Only {{ spotsLeft(event) }} {{ spotsLeft(event) === 1 ? 'spot' : 'spots' }} left</span>
            </span>
            <span class="hm-card__cta">View &amp; register<AppIcon name="arrow-right" :size="15" /></span>
          </RouterLink>
        </li>
      </ul>
    </section>

    <section class="home-features" aria-label="How it works">
      <article class="home-feature home-feature--teal">
        <span class="home-feature-num">01</span>
        <div class="home-feature-icon"><AppIcon name="ticket" :size="22" /></div>
        <h2>Register once</h2>
        <p>Keep your event details and access pass in one place.</p>
      </article>

      <article class="home-feature home-feature--blue">
        <span class="home-feature-num">02</span>
        <div class="home-feature-icon"><AppIcon name="check-circle" :size="22" /></div>
        <h2>Arrive lightly</h2>
        <p>Show your QR pass at the door and get moving.</p>
      </article>

      <article class="home-feature home-feature--green">
        <span class="home-feature-num">03</span>
        <div class="home-feature-icon"><AppIcon name="clock" :size="22" /></div>
        <h2>Stay present</h2>
        <p>Spend less time at the desk and more time in the room.</p>
      </article>
    </section>
  </UserLayout>
</template>
