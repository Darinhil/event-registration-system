<script setup>
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
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
        <RouterLink class="home-cta" to="/register">
          Reserve your place
          <AppIcon name="arrow-right" :size="18" />
        </RouterLink>
        <RouterLink class="home-ghost" to="/check-in">
          <AppIcon name="check-circle" :size="18" />
          Check in
        </RouterLink>
      </div>

      <div class="home-stats">
        <div>
          <strong>1 pass</strong>
          <span>All your events</span>
        </div>
        <div>
          <strong>&lt; 10 sec</strong>
          <span>QR check-in</span>
        </div>
        <div>
          <strong>100% free</strong>
          <span>For attendees</span>
        </div>
      </div>
    </section>

    <section class="home-features">
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
