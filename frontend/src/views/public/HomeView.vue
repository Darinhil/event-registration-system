<script setup>
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Events are not listed here: attendees open the event link shared by the organizer.
const steps = [
  { icon: 'link', title: 'Open your event link', text: 'Your organizer shares a link or QR code for the event.' },
  { icon: 'calendar-check', title: 'Register once', text: 'Fill in a short form. Your QR pass is ready straight away.' },
  { icon: 'qr-code', title: 'Show your pass', text: 'Scan the QR code at the entrance, or show your pass at the desk.' },
]
</script>

<template>
  <UserLayout>
    <section class="hp-hero" aria-labelledby="hp-title">
      <div class="hp-hero__inner">
        <div class="hp-hero__copy">
          <p class="hp-eyebrow">Live &amp; Learn Cambodia · Events</p>
          <h1 id="hp-title">Register for events and check in <em>with a QR code.</em></h1>
          <div class="hp-actions">
            <RouterLink v-if="auth.isAuthenticated" class="hp-btn hp-btn--primary" to="/me"><AppIcon name="ticket" :size="17" />My registrations</RouterLink>
            <RouterLink v-else class="hp-btn hp-btn--primary" to="/login">Sign in<AppIcon name="arrow-right" :size="17" /></RouterLink>
          </div>
          <p v-if="!auth.isAuthenticated" class="hp-signup">New here? <RouterLink to="/account/register">Create a free account</RouterLink></p>
        </div>

        <aside class="hp-how" aria-labelledby="hp-how-title">
          <h2 id="hp-how-title">How it works</h2>
          <ol>
            <li v-for="(step, index) in steps" :key="step.title">
              <span class="hp-how__icon" aria-hidden="true"><AppIcon :name="step.icon" :size="19" /></span>
              <div><strong><span class="hp-how__num">{{ index + 1 }}.</span> {{ step.title }}</strong><p>{{ step.text }}</p></div>
            </li>
          </ol>
        </aside>
      </div>
    </section>
  </UserLayout>
</template>
