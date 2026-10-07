<script setup>
import { computed, onMounted, ref } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { formatDate } from '../../utils/formatDate'

const auth = useAuthStore()
const profileLoading = ref(false)
const profileError = ref('')
const loadProfile = async () => {
  profileLoading.value = true
  profileError.value = ''
  try {
    await auth.fetchMe()
  } catch {
    profileError.value = 'Unable to refresh your profile and registrations.'
  } finally {
    profileLoading.value = false
  }
}
onMounted(loadProfile)

const initials = computed(() => (auth.user?.name || 'U')
  .trim()
  .split(/\s+/)
  .slice(0, 2)
  .map((part) => part.charAt(0).toUpperCase())
  .join('') || 'U')

const registrations = computed(() => auth.user?.registrations || [])
const total = computed(() => registrations.value.length)
const confirmed = computed(() =>
  registrations.value.filter((r) => ['confirmed', 'registered', 'checked'].includes((r.status || '').toLowerCase())).length)

const isConfirmed = (status) => ['confirmed', 'registered', 'checked'].includes((status || '').toLowerCase())
const isCancelled = (status) => (status || '').toLowerCase() === 'cancelled'
const isPending = (status) => ['pending', 'draft'].includes((status || '').toLowerCase())

const formatDeadline = (value) => {
  if (!value) return ''
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}
</script>

<template>
  <UserLayout>
    <section class="mi-page">
      <header class="mi-heading">
        <div class="mi-heading-copy">
          <p class="mi-kicker">Attendee portal</p>
          <h1>My information</h1>
          <p>Manage your profile and keep track of every event you have joined.</p>
        </div>
      </header>

      <div v-if="profileLoading" class="dynamic-loading" role="status">
        {{ auth.user ? 'Refreshing your profile…' : 'Loading your profile…' }}
      </div>
      <div v-if="profileError" class="inline-error" role="alert">
        <span>{{ profileError }}<template v-if="auth.user"> Your saved profile is still shown.</template></span>
        <button type="button" @click="loadProfile">Retry</button>
      </div>

      <!-- Identity card -->
      <div v-if="auth.user" class="mi-profile-card">
        <div class="mi-identity">
          <div class="mi-avatar">{{ initials }}</div>
          <div class="mi-identity-copy">
            <h2>{{ auth.user.name }}</h2>
            <span class="mi-identity-email"><AppIcon name="mail" :size="15" />{{ auth.user.email }}</span>
            <div class="mi-identity-tags">
              <span class="mi-chip mi-chip--green">● Active member</span>
              <span class="mi-chip">{{ total }} event{{ total === 1 ? '' : 's' }} joined</span>
            </div>
          </div>
        </div>

        <div class="mi-quickstats">
          <div class="mi-stat mi-stat--blue">
            <span class="mi-stat-icon"><AppIcon name="ticket" :size="20" /></span>
            <div class="mi-stat-copy">
              <small>Registrations</small>
              <strong>{{ total }}</strong>
              <span>Total events joined</span>
            </div>
          </div>
          <div class="mi-stat mi-stat--green">
            <span class="mi-stat-icon"><AppIcon name="check-circle" :size="20" /></span>
            <div class="mi-stat-copy">
              <small>Confirmed</small>
              <strong>{{ confirmed }}</strong>
              <span>Ready to attend</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Registrations -->
      <div v-if="auth.user" class="mi-section-heading">
        <h3>My registrations</h3>
        <span class="mi-section-count">{{ total }} total</span>
      </div>

      <div v-if="auth.user && total" class="mi-registrations">
        <article
          v-for="registration in registrations"
          :key="registration.id"
          class="mi-reg-card"
          :class="{
            'is-confirmed': isConfirmed(registration.status),
            'is-cancelled': isCancelled(registration.status),
            'is-pending': isPending(registration.status),
          }"
        >
          <span class="mi-reg-icon"><AppIcon name="ticket" :size="20" /></span>
          <div class="mi-reg-main">
            <RouterLink class="mi-event-link" :to="`/events/${registration.event_id}`">{{ registration.event_name }}</RouterLink>
            <div class="mi-reg-meta">
              <span>{{ formatDate(registration.event_date) }}</span>
            </div>
            <small v-if="registration.registration_deadline" class="mi-reg-deadline">
              Editing deadline: {{ formatDeadline(registration.registration_deadline) }}
            </small>
          </div>

          <StatusBadge :status="registration.status" />

          <div class="mi-reg-actions">
            <RouterLink class="mi-view-pass" :to="`/registration/success?id=${registration.id}`"><AppIcon name="ticket" :size="14" />View pass</RouterLink>
            <RouterLink v-if="registration.can_edit" class="mi-edit-btn" :to="`/registration/${registration.id}/edit`"><AppIcon name="pen" :size="14" />Edit registration</RouterLink>
            <span v-else class="mi-edit-closed"><AppIcon name="lock" :size="13" />Editing closed</span>
          </div>
        </article>
      </div>

      <div v-else-if="auth.user && !profileLoading" class="mi-empty">
        <span class="mi-empty-icon"><AppIcon name="inbox" :size="26" /></span>
        <strong>No registrations yet</strong>
        <p>Your registrations will appear here after you register using an event link from the organizer.</p>
      </div>
    </section>
  </UserLayout>
</template>
