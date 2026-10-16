<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'

const route = useRoute()
const event = ref(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

const image = computed(() => event.value?.branding?.image || event.value?.image || '')
const dateLabel = computed(() => {
  if (!event.value?.starts_at) return 'Date to be confirmed'
  const start = new Date(event.value.starts_at)
  const end = event.value.ends_at ? new Date(event.value.ends_at) : null
  const format = (date) => date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  if (!end || Number.isNaN(end.getTime()) || start.toDateString() === end.toDateString()) return format(start)
  const sameYear = start.getFullYear() === end.getFullYear()
  const endLabel = sameYear ? end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: undefined }) : format(end)
  return `${format(start)} – ${endLabel}`
})
const timeLabel = computed(() => event.value?.starts_at
  ? `${new Date(event.value.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} – ${new Date(event.value.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
  : 'Time to be confirmed')
const agendaDays = computed(() => [...new Set((event.value?.form_config?.agenda || []).map((session) => Number(session.day)))].sort((a, b) => a - b))
const selectedAgendaDay = ref(null)
const visibleAgendaDay = computed(() => agendaDays.value.includes(selectedAgendaDay.value) ? selectedAgendaDay.value : agendaDays.value[0])
const agendaSessionsForDay = (day) => (event.value?.form_config?.agenda || []).filter((session) => Number(session.day) === day)
const agendaDaySessionCount = (day) => agendaSessionsForDay(day).length
const agendaDayLabel = (day) => {
  if (!event.value?.starts_at) return `Day ${day}`
  const date = new Date(event.value.starts_at)
  date.setDate(date.getDate() + day - 1)
  return `Day ${day} · ${date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' })}`
}
const isOpen = computed(() => event.value?.status === 'published' || event.value?.status === 'open')

const load = async () => {
  loading.value = true
  error.value = ''
  notFound.value = false
  event.value = null
  try {
    const { data } = await api.get(`/events/${route.params.eventId}`)
    event.value = { ...data.data, remaining: data.remaining }
  } catch (requestError) {
    notFound.value = requestError.response?.status === 404
    error.value = notFound.value ? 'This event is no longer available.' : 'The event could not be loaded.'
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
          <ol class="event-flow-progress" aria-label="Event registration steps">
            <li class="is-current" aria-current="step"><span>1</span><div><strong>Review agenda</strong><small>Event overview</small></div></li>
            <li><span>2</span><div><strong>Register</strong><small>Your information</small></div></li>
          </ol>
          <main class="public-event-main">
            <div class="public-event-meta">
              <div><span><AppIcon name="calendar" :size="18" /></span><strong>{{ dateLabel }}</strong><small>Event date</small></div>
              <div><span><AppIcon name="clock" :size="18" /></span><strong>{{ timeLabel }}</strong><small>Event time</small></div>
              <div><span><AppIcon name="pin" :size="18" /></span><strong>{{ event.location || 'Location to be confirmed' }}</strong><small>Location</small></div>
            </div>

            <section v-if="agendaDays.length" class="public-agenda">
              <h2>Agenda &amp; Program</h2>
              <div v-if="agendaDays.length > 1" class="public-agenda-day-tabs" role="tablist" aria-label="Choose an agenda day">
                <button
                  v-for="day in agendaDays"
                  :key="day"
                  type="button"
                  role="tab"
                  :aria-selected="visibleAgendaDay === day"
                  :aria-controls="`agenda-day-panel-${day}`"
                  :class="{ active: visibleAgendaDay === day }"
                  @click="selectedAgendaDay = day"
                >
                  <span>{{ agendaDayLabel(day) }}</span>
                  <small>{{ agendaDaySessionCount(day) }} {{ agendaDaySessionCount(day) === 1 ? 'session' : 'sessions' }}</small>
                </button>
              </div>
              <div v-if="visibleAgendaDay" :id="`agenda-day-panel-${visibleAgendaDay}`" class="public-agenda-day" role="tabpanel" :aria-label="agendaDayLabel(visibleAgendaDay)">
                <h3 v-if="agendaDays.length === 1">{{ agendaDayLabel(visibleAgendaDay) }}</h3>
                <div class="public-agenda-table-scroll" role="region" aria-label="Agenda sessions" tabindex="0">
                  <table class="public-agenda-table">
                    <thead>
                      <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Session type</th>
                        <th scope="col">Title &amp; focus</th>
                        <th scope="col">Track</th>
                        <th scope="col">Speakers / organization</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(session, index) in agendaSessionsForDay(visibleAgendaDay)" :key="session.id || `${visibleAgendaDay}-${index}`">
                        <td>
                          <span class="public-agenda-time">
                            {{ session.start_time || 'Time TBC' }}<template v-if="session.end_time"> – {{ session.end_time }}</template>
                          </span>
                        </td>
                        <td><span v-if="session.session_type" class="public-agenda-type">{{ session.session_type }}</span><span v-else>—</span></td>
                        <td class="public-agenda-session">
                          <h4>{{ session.title }}</h4>
                          <p v-if="session.description">{{ session.description }}</p>
                        </td>
                        <td>{{ session.track || '—' }}</td>
                        <td>{{ session.speakers || '—' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </section>

            <section class="public-event-next-step">
              <div>
                <p class="public-event-label">{{ isOpen ? 'Ready to join?' : 'Registration closed' }}</p>
                <h2>{{ isOpen ? 'Continue to registration' : 'This event is not accepting registrations' }}</h2>
                <p>{{ isOpen ? 'When you are ready, continue to the registration form.' : 'Registration is currently unavailable for this event.' }}</p>
              </div>
              <RouterLink v-if="isOpen" class="primary-button" :to="`/events/${event.id}/register`">Next: Register <span>→</span></RouterLink>
              <RouterLink v-else class="secondary-button" to="/">Return home</RouterLink>
            </section>

          </main>
        </div>
      </div>
      <div v-else class="public-event-empty">
        <h2>{{ error || 'Event not found' }}</h2>
        <button v-if="error && !notFound" type="button" class="secondary-button" @click="load">Try again</button>
        <RouterLink to="/">Return home</RouterLink>
      </div>
    </section>
  </UserLayout>
</template>
