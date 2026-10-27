<script setup>
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Events are not listed here: attendees open the event link shared by the organizer.
const steps = [
  { icon: 'link', title: 'Open your event link', text: 'Your organizer shares a link or QR code for the event.' },
  { icon: 'calendar-check', title: 'Register once', text: 'Fill in a short form. Your QR pass is ready straight away.' },
  { icon: 'qr-code', title: 'Show your pass', text: 'Scan it at the door, or check yourself in at the venue.' },
]
</script>

<template>
  <UserLayout>
    <section class="hp-hero" aria-labelledby="hp-title">
      <div class="hp-hero__inner">
        <div class="hp-hero__copy">
          <p class="hp-eyebrow">Live &amp; Learn Cambodia · Events</p>
          <h1 id="hp-title">Make room for <em>the people</em> who make things.</h1>
          <p class="hp-lede">Register for events with a short form and check in by scanning the QR code at the venue — a calm, simple registration desk.</p>
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
          <p class="hp-how__note"><AppIcon name="info" :size="15" />Looking for an event? Use the link from your organizer.</p>
        </aside>
      </div>
    </section>
  </UserLayout>
</template>
