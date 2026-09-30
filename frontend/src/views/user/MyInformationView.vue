<script setup>
import { computed, onMounted } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { formatDate } from '../../utils/formatDate'

const auth = useAuthStore()
onMounted(() => auth.fetchMe())

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
      <div class="mi-section-heading">
        <h3>My registrations</h3>
        <span class="mi-section-count">{{ total }} total</span>
      </div>

      <div v-if="total" class="mi-registrations">
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
            <strong>{{ registration.event_name }}</strong>
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

      <div v-else class="mi-empty">
        <span class="mi-empty-icon"><AppIcon name="inbox" :size="26" /></span>
        <strong>No registrations yet</strong>
        <p>Browse available events and secure your spot in a few clicks.</p>
        <RouterLink to="/register">Register for an event</RouterLink>
      </div>
    </section>
  </UserLayout>
</template>
