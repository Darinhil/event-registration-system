<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { deleteRegistration } from '../../services/registrationService'

const auth = useAuthStore()
const profileLoading = ref(false)
const profileError = ref('')
const loadProfile = async () => {
  profileLoading.value = true
  profileError.value = ''
  try {
    await auth.fetchMe()
  } catch {
    profileError.value = 'We couldn’t refresh your registrations.'
  } finally {
    profileLoading.value = false
  }
}
onMounted(loadProfile)

const initials = computed(() => String(auth.user?.name || 'U').trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'U')

/* ---------- Registrations, split into upcoming and past ---------- */
// Prefer the event's live schedule; the copy stored on the registration can be out of date.
const startOf = (registration) => new Date(registration.event_starts_at || registration.event_date || 0)
const endOf = (registration) => registration.event_ends_at ? new Date(registration.event_ends_at) : startOf(registration)
const registrations = computed(() => auth.user?.registrations || [])
const now = Date.now()
const upcoming = computed(() => registrations.value.filter((r) => endOf(r).getTime() >= now).sort((a, b) => startOf(a) - startOf(b)))
const past = computed(() => registrations.value.filter((r) => endOf(r).getTime() < now).sort((a, b) => startOf(b) - startOf(a)))
const attended = computed(() => registrations.value.filter((r) => r.checked_in_at || String(r.status).toLowerCase() === 'checked_in').length)
const tab = ref('upcoming')
const visible = computed(() => (tab.value === 'upcoming' ? upcoming.value : past.value))

const statusOf = (registration) => {
  const status = String(registration.status || '').toLowerCase()
  if (registration.checked_in_at || status === 'checked_in') return { label: 'Checked in', tone: 'in', icon: 'check-circle' }
  if (status === 'cancelled') return { label: 'Cancelled', tone: 'off', icon: 'x' }
  if (endOf(registration).getTime() < now) return { label: 'Not attended', tone: 'off', icon: 'info' }
  return { label: 'Confirmed', tone: 'ok', icon: 'check' }
}
const dateTile = (registration) => {
  const date = startOf(registration)
  return Number.isNaN(date.getTime()) || !date.getTime() ? null : { month: date.toLocaleDateString(undefined, { month: 'short' }), day: date.getDate() }
}
const whenLabel = (registration) => {
  const start = startOf(registration)
  if (!start.getTime()) return 'Date to be confirmed'
  const day = start.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
  const time = start.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
  return `${day} · ${time}`
}
// The deadline is a calendar date (editing closes at the end of that day), not a timestamp.
const deadlineLabel = (value) => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ''

/* ---------- Cancel a registration ---------- */
const confirmingId = ref(null)
const cancellingId = ref(null)
const notice = ref(null) // { tone, text }
const askCancel = (registration) => {
  confirmingId.value = registration.id
  nextTick(() => document.getElementById(`keep-${registration.id}`)?.focus())
}
const cancelRegistration = async (registration) => {
  cancellingId.value = registration.id
  notice.value = null
  try {
    await deleteRegistration(registration.id)
    auth.user.registrations = registrations.value.filter((item) => item.id !== registration.id)
    try { localStorage.setItem('event_user', JSON.stringify(auth.user)) } catch { /* optional cache */ }
    notice.value = { tone: 'ok', text: `Your registration for ${registration.event_name} was cancelled.` }
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'We couldn’t cancel this registration. Please try again.' }
  } finally {
    cancellingId.value = null
    confirmingId.value = null
  }
}
</script>

<template>
  <UserLayout>
    <section class="my-page">
      <header class="my-header">
        <div>
          <h1>My information</h1>
          <p>Your profile and the events you’ve registered for.</p>
        </div>
      </header>

      <div v-if="profileError" class="my-banner is-error" role="alert">
        <AppIcon name="alert-circle" :size="18" /><span>{{ profileError }}<template v-if="auth.user"> Showing your last saved details.</template></span>
        <button type="button" class="my-btn my-btn--sm" @click="loadProfile">Try again</button>
      </div>
      <div v-if="notice" class="my-banner" :class="notice.tone === 'ok' ? 'is-ok' : 'is-error'" role="status">
        <AppIcon :name="notice.tone === 'ok' ? 'check' : 'alert-circle'" :size="18" /><span>{{ notice.text }}</span>
        <button type="button" class="my-icon-btn" aria-label="Dismiss" @click="notice = null"><AppIcon name="x" :size="16" /></button>
      </div>

      <div v-if="!auth.user && profileLoading" class="my-skel" role="status" aria-label="Loading your profile"></div>

      <template v-if="auth.user">
        <!-- Profile -->
        <section class="my-profile" aria-label="Your profile">
          <span class="my-avatar" aria-hidden="true">{{ initials }}</span>
          <div class="my-profile__copy">
            <h2>{{ auth.user.name }}</h2>
            <p>
              <span v-if="auth.user.email"><AppIcon name="mail" :size="15" />{{ auth.user.email }}</span>
              <span v-if="auth.user.phone"><AppIcon name="phone" :size="15" />{{ auth.user.phone }}</span>
            </p>
          </div>
          <dl class="my-stats">
            <div><dt>Upcoming</dt><dd>{{ upcoming.length }}</dd></div>
            <div><dt>Attended</dt><dd>{{ attended }}</dd></div>
          </dl>
          <RouterLink class="my-btn" to="/profile"><AppIcon name="pen" :size="15" />Edit profile</RouterLink>
        </section>

        <!-- Registrations -->
        <section class="my-regs" aria-labelledby="my-regs-title">
          <div class="my-regs__head">
            <h2 id="my-regs-title">My registrations</h2>
            <div class="my-tabs" role="tablist" aria-label="Registrations">
              <button type="button" role="tab" :aria-selected="tab === 'upcoming'" :class="{ 'is-active': tab === 'upcoming' }" @click="tab = 'upcoming'">Upcoming<span>{{ upcoming.length }}</span></button>
              <button type="button" role="tab" :aria-selected="tab === 'past'" :class="{ 'is-active': tab === 'past' }" @click="tab = 'past'">Past<span>{{ past.length }}</span></button>
            </div>
          </div>

          <ul v-if="visible.length" class="my-list" role="tabpanel">
            <li v-for="registration in visible" :key="registration.id" class="my-card" :class="{ 'is-past': tab === 'past' }">
              <span class="my-date" aria-hidden="true"><template v-if="dateTile(registration)"><small>{{ dateTile(registration).month }}</small><b>{{ dateTile(registration).day }}</b></template><AppIcon v-else name="calendar" :size="20" /></span>
              <div class="my-card__body">
                <div class="my-card__title">
                  <RouterLink :to="`/events/${registration.event_id}`">{{ registration.event_name }}</RouterLink>
                  <span class="my-status" :class="`is-${statusOf(registration).tone}`"><AppIcon :name="statusOf(registration).icon" :size="13" />{{ statusOf(registration).label }}</span>
                </div>
                <p class="my-card__meta">
                  <span><AppIcon name="clock" :size="14" />{{ whenLabel(registration) }}</span>
                  <span v-if="registration.event_current_location || registration.event_location"><AppIcon name="pin" :size="14" />{{ registration.event_current_location || registration.event_location }}</span>
                </p>
                <p class="my-card__ref">Registration ID <code>{{ registration.registration_code || registration.id }}</code><template v-if="tab === 'upcoming' && registration.can_edit && registration.registration_deadline"> · You can edit until {{ deadlineLabel(registration.registration_deadline) }}</template></p>
              </div>

              <div class="my-card__actions">
                <template v-if="confirmingId === registration.id">
                  <span class="my-confirm" role="alert">Cancel this registration?</span>
                  <button :id="`keep-${registration.id}`" type="button" class="my-btn my-btn--sm" :disabled="cancellingId === registration.id" @click="confirmingId = null">Keep it</button>
                  <button type="button" class="my-btn my-btn--sm my-btn--danger" :disabled="cancellingId === registration.id" @click="cancelRegistration(registration)">{{ cancellingId === registration.id ? 'Cancelling…' : 'Yes, cancel' }}</button>
                </template>
                <template v-else>
                  <RouterLink class="my-btn" :class="{ 'my-btn--primary': tab === 'upcoming' }" :to="`/registration/success?id=${registration.id}`"><AppIcon name="qr-code" :size="15" />{{ tab === 'upcoming' ? 'Show pass' : 'View pass' }}</RouterLink>
                  <RouterLink v-if="tab === 'upcoming' && registration.can_edit" class="my-btn" :to="`/registration/${registration.id}/edit`"><AppIcon name="pen" :size="15" />Edit details</RouterLink>
                  <button v-if="tab === 'upcoming' && registration.can_cancel !== false && !registration.checked_in_at" type="button" class="my-link-danger" @click="askCancel(registration)">Cancel registration</button>
                </template>
              </div>
            </li>
          </ul>

          <div v-else-if="!profileLoading" class="my-empty">
            <span class="my-empty__icon"><AppIcon :name="tab === 'upcoming' ? 'calendar-plus' : 'clock'" :size="24" /></span>
            <strong>{{ tab === 'upcoming' ? 'No upcoming events' : 'No past events yet' }}</strong>
            <p>{{ tab === 'upcoming' ? 'Open the event link from your organizer to register — your pass and details will then appear here.' : 'Events you’ve registered for will move here once they’re over.' }}</p>
          </div>
        </section>
      </template>
    </section>
  </UserLayout>
</template>
