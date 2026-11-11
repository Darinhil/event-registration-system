<script setup>
import { computed, nextTick, ref } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import LocationLink from '../../components/LocationLink.vue'
import ImageViewer from '../../components/ImageViewer.vue'
import api from '../../services/api'

const route = useRoute()
const event = ref(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

const load = async () => {
  loading.value = true
  error.value = ''
  notFound.value = false
  event.value = null
  try {
    const { data } = await api.get(`/events/${route.params.eventId}`)
    event.value = { ...data.data, remaining: data.remaining, registration: data.registration }
  } catch (requestError) {
    notFound.value = requestError.response?.status === 404
    error.value = notFound.value ? 'This event is no longer available.' : 'We couldn’t load this event. Check your connection and try again.'
  } finally {
    loading.value = false
  }
  // Sections only exist once the event has loaded, so the router can't scroll to a #hash on its own.
  if (event.value && route.hash) {
    await nextTick()
    document.getElementById(route.hash.slice(1))?.scrollIntoView({ block: 'start' })
  }
}
load()

/* ---------- Event facts (all in the visitor's local time) ---------- */
const image = computed(() => event.value?.branding?.image || '')
const start = computed(() => event.value?.starts_at ? new Date(event.value.starts_at) : null)
const end = computed(() => event.value?.ends_at ? new Date(event.value.ends_at) : null)
const sameDay = computed(() => !end.value || start.value?.toDateString() === end.value.toDateString())
const longDate = (date) => date.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
const shortDate = (date) => date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
const clock = (date) => date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
const dateLabel = computed(() => {
  if (!start.value) return 'Date to be confirmed'
  return sameDay.value ? longDate(start.value) : `${shortDate(start.value)} – ${shortDate(end.value)}, ${end.value.getFullYear()}`
})
const timeLabel = computed(() => {
  if (!start.value) return 'Time to be confirmed'
  if (!end.value) return clock(start.value)
  return sameDay.value ? `${clock(start.value)} – ${clock(end.value)}` : `Starts ${clock(start.value)} · ends ${clock(end.value)}`
})
const settings = computed(() => event.value?.form_config?.settings || {})
const deadlineLabel = computed(() => settings.value.registration_end ? new Date(`${String(settings.value.registration_end).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) : '')

/* ---------- Registration status (decided by the server) ---------- */
const isOpen = computed(() => event.value?.registration ? event.value.registration.open : event.value?.status === 'published')
const closedReason = computed(() => event.value?.registration?.reason || 'This event isn’t accepting registrations right now.')
const spotsLeft = computed(() => (event.value?.capacity ? Math.max(0, Number(event.value.remaining ?? 0)) : null))
const spotsLabel = computed(() => {
  if (spotsLeft.value === null) return ''
  if (spotsLeft.value === 0) return 'No spots left'
  return spotsLeft.value <= 10 ? `Only ${spotsLeft.value} ${spotsLeft.value === 1 ? 'spot' : 'spots'} left` : `${spotsLeft.value.toLocaleString()} spots left`
})
const registerLink = computed(() => `/events/${event.value?.id}/register`)

/* ---------- Agenda ---------- */
const agenda = computed(() => (Array.isArray(event.value?.form_config?.agenda) ? event.value.form_config.agenda : []))
const toMinutes = (time) => { const [h, m] = String(time || '').split(':').map(Number); return Number.isFinite(h) && Number.isFinite(m) ? h * 60 + m : 9999 }
const sessionTime = (time) => time ? new Date(`2000-01-01T${time}`).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''
const agendaDays = computed(() => {
  const days = new Set(agenda.value.map((session) => Number(session.day) || 1))
  return [...days].sort((a, b) => a - b)
})
const selectedDay = ref(null)
const activeDay = computed(() => agendaDays.value.includes(selectedDay.value) ? selectedDay.value : agendaDays.value[0])
const sessionsFor = (day) => agenda.value.filter((session) => (Number(session.day) || 1) === day).sort((a, b) => toMinutes(a.start_time) - toMinutes(b.start_time))
const dayLabel = (day) => {
  if (!start.value) return `Day ${day}`
  const date = new Date(start.value.getFullYear(), start.value.getMonth(), start.value.getDate() + day - 1)
  return `Day ${day} · ${date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}`
}
const onDayKeydown = (keyEvent, index) => {
  const step = keyEvent.key === 'ArrowRight' ? 1 : keyEvent.key === 'ArrowLeft' ? -1 : 0
  if (!step) return
  keyEvent.preventDefault()
  const next = agendaDays.value[(index + step + agendaDays.value.length) % agendaDays.value.length]
  selectedDay.value = next
  document.getElementById(`pe-day-tab-${next}`)?.focus()
}

/* ---------- Handy actions ---------- */
const mapsLink = computed(() => {
  const place = String(event.value?.location || '').trim()
  if (!place) return ''
  return /^https?:\/\//i.test(place) ? place : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(place)}`
})
</script>

<template>
  <UserLayout>
    <section class="pe-page">
      <!-- Loading -->
      <div v-if="loading" class="pe-loading" role="status" aria-label="Loading event">
        <div class="pe-skel pe-skel--hero"></div>
        <div class="pe-layout"><div class="pe-skel pe-skel--body"></div><div class="pe-skel pe-skel--side"></div></div>
      </div>

      <!-- Not found / error -->
      <div v-else-if="!event" class="pe-state" role="alert">
        <span class="pe-state__icon"><AppIcon :name="notFound ? 'calendar' : 'alert-circle'" :size="26" /></span>
        <h1>{{ error || 'Event not found' }}</h1>
        <p>{{ notFound ? 'It may have ended or been unpublished by the organizer.' : 'Your connection may have dropped.' }}</p>
        <div class="pe-state__actions">
          <button v-if="!notFound" type="button" class="pe-btn" @click="load"><AppIcon name="refresh" :size="16" />Try again</button>
          <RouterLink class="pe-btn pe-btn--primary" to="/">Browse events</RouterLink>
        </div>
      </div>

      <template v-else>
        <!-- Hero -->
        <header class="pe-hero" :class="{ 'has-image': image }">
          <ImageViewer v-if="image" :src="image" :alt="event.name" img-class="pe-hero__image" />
          <div class="pe-hero__body">
            <div class="pe-hero__tags">
              <span v-if="event.category" class="pe-tag">{{ event.category }}</span>
              <span class="pe-pill" :class="isOpen ? 'is-open' : 'is-closed'"><i aria-hidden="true"></i>{{ isOpen ? 'Registration open' : 'Registration closed' }}</span>
            </div>
            <h1>{{ event.name }}</h1>
            <ul class="pe-hero__facts">
              <li><AppIcon name="calendar" :size="17" /><span>{{ dateLabel }}</span></li>
              <li><AppIcon name="clock" :size="17" /><span>{{ timeLabel }}</span></li>
              <li><AppIcon name="pin" :size="17" /><LocationLink :location="event.location" /></li>
            </ul>
          </div>
        </header>

        <div class="pe-layout">
          <div class="pe-main">
            <!-- About -->
            <section v-if="event.description" class="pe-section" aria-labelledby="pe-about">
              <h2 id="pe-about">About this event</h2>
              <p class="pe-description">{{ event.description }}</p>
            </section>

            <!-- Agenda -->
            <section v-if="agenda.length" id="agenda" class="pe-section" aria-labelledby="pe-agenda">
              <div class="pe-section__head">
                <h2 id="pe-agenda">Agenda</h2>
                <span class="pe-muted">{{ agenda.length }} {{ agenda.length === 1 ? 'session' : 'sessions' }}<template v-if="agendaDays.length > 1"> over {{ agendaDays.length }} days</template></span>
              </div>
              <div v-if="agendaDays.length > 1" class="pe-tabs" role="tablist" aria-label="Agenda day">
                <button v-for="(day, index) in agendaDays" :id="`pe-day-tab-${day}`" :key="day" type="button" role="tab" :aria-selected="activeDay === day" :aria-controls="`pe-day-${day}`" :tabindex="activeDay === day ? 0 : -1" :class="{ 'is-active': activeDay === day }" @click="selectedDay = day" @keydown="onDayKeydown($event, index)">
                  {{ dayLabel(day) }}
                </button>
              </div>
              <h3 v-else class="pe-day-title">{{ dayLabel(activeDay) }}</h3>
              <ol :id="`pe-day-${activeDay}`" class="pe-timeline" :role="agendaDays.length > 1 ? 'tabpanel' : undefined" :aria-labelledby="agendaDays.length > 1 ? `pe-day-tab-${activeDay}` : undefined">
                <li v-for="(session, index) in sessionsFor(activeDay)" :key="session.id || index" class="pe-session" :class="{ 'is-break': /break|lunch/i.test(session.session_type || '') }">
                  <div class="pe-session__time"><b>{{ sessionTime(session.start_time) || 'TBC' }}</b><small v-if="session.end_time">{{ sessionTime(session.end_time) }}</small></div>
                  <div class="pe-session__body">
                    <div class="pe-session__title"><h4>{{ session.title || 'Session' }}</h4><span v-if="session.session_type && session.session_type !== 'Session'" class="pe-session__type">{{ session.session_type }}</span></div>
                    <p v-if="session.description">{{ session.description }}</p>
                    <p v-if="session.track || session.speakers" class="pe-session__meta">
                      <span v-if="session.speakers"><AppIcon name="users" :size="14" />{{ session.speakers }}</span>
                      <span v-if="session.track"><AppIcon name="type" :size="14" />{{ session.track }}</span>
                    </p>
                  </div>
                </li>
              </ol>
            </section>
          </div>

          <!-- Register panel: stays in view on desktop -->
          <aside class="pe-side" aria-labelledby="pe-register">
            <div class="pe-register">
              <h2 id="pe-register">{{ isOpen ? 'Join this event' : 'Registration closed' }}</h2>
              <ul class="pe-register__facts">
                <li v-if="isOpen && deadlineLabel"><AppIcon name="clock" :size="16" /><span>Register by <strong>{{ deadlineLabel }}</strong></span></li>
                <li v-if="isOpen && spotsLabel" :class="{ 'is-low': spotsLeft !== null && spotsLeft <= 10 }"><AppIcon name="users" :size="16" /><span>{{ spotsLabel }}</span></li>
                <li v-if="isOpen"><AppIcon name="check-circle" :size="16" /><span>Free to register</span></li>
                <li v-else class="is-closed"><AppIcon name="lock" :size="16" /><span>{{ closedReason }}</span></li>
              </ul>
              <RouterLink v-if="isOpen" class="pe-btn pe-btn--primary pe-btn--block" :to="registerLink">Register now<AppIcon name="arrow-right" :size="17" /></RouterLink>
              <RouterLink v-else class="pe-btn pe-btn--block" to="/me">View my registrations</RouterLink>
              <div v-if="mapsLink" class="pe-register__share">
                <a class="pe-link" :href="mapsLink" target="_blank" rel="noopener noreferrer"><AppIcon name="arrow-up-right" :size="15" />Get directions<span class="sr-only"> (opens Google Maps)</span></a>
              </div>
            </div>
          </aside>
        </div>

        <!-- Mobile: keep the main action within thumb reach -->
        <div v-if="isOpen" class="pe-mobile-cta">
          <div><strong>Registration open</strong><small>{{ spotsLabel || (deadlineLabel ? `Register by ${deadlineLabel}` : '') }}</small></div>
          <RouterLink class="pe-btn pe-btn--primary" :to="registerLink">Register<AppIcon name="arrow-right" :size="16" /></RouterLink>
        </div>
      </template>
    </section>
  </UserLayout>
</template>
