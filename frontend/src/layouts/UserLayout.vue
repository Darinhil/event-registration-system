<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import logo from '../assets/logo.png'

const router = useRouter()
const auth = useAuthStore()

const logout = async () => {
  await auth.logout()
  router.push('/')
}

</script>
<template>
  <div class="shell">
    <header class="topbar">
      <RouterLink to="/" class="brand">
        <img :src="logo" alt="" />
        <span class="brand-text">Event</span>
      </RouterLink>

      <nav>
        <template v-if="auth.isAuthenticated">
          <RouterLink to="/me" class="nav-link">My information</RouterLink>
          <RouterLink to="/check-in" class="nav-link">Check-in</RouterLink>
          <div class="nav-user-chip" :title="auth.user?.email">
            <span class="nav-avatar">{{ (auth.user?.name || 'U').trim().charAt(0).toUpperCase() }}</span>
            <span class="nav-user-name">{{ auth.user?.name || 'Account' }}</span>
          </div>
          <button class="logout-button" type="button" @click="logout">Logout</button>
        </template>

        <template v-else>
          <RouterLink to="/login" class="nav-link">Login</RouterLink>
          <RouterLink to="/account/register" class="cta-button">Get Started</RouterLink>
        </template>
      </nav>
    </header>

    <main><slot /></main>
  </div>
</template>

<style scoped>
@media (max-width: 640px) {
  .topbar nav { gap: 8px; }
  .topbar nav .nav-user-chip { display: none; }
}
</style>
