<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import logo from '../assets/LLEE_Cambodia_Inverse_L.png'

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
        <img :src="logo" alt="Live & Learn Cambodia" />
      </RouterLink>

      <nav>
        <template v-if="auth.isAuthenticated">
          <RouterLink to="/events" class="nav-link">Events</RouterLink>
          <RouterLink to="/me" class="nav-link">My information</RouterLink>
          <RouterLink to="/check-in" class="nav-link">Check-in</RouterLink>
          <RouterLink to="/profile" class="nav-user-chip" :title="auth.user?.email" aria-label="Edit profile">
            <span class="nav-avatar"><img v-if="auth.profilePhotoPreview || auth.user?.profile_photo" :src="auth.profilePhotoPreview || auth.user?.profile_photo" alt="" /><span v-else>{{ (auth.user?.name || 'U').trim().charAt(0).toUpperCase() }}</span></span>
            <span class="nav-user-name">{{ auth.user?.name || 'Account' }}</span>
          </RouterLink>
          <button class="logout-button" type="button" @click="logout">Logout</button>
        </template>

        <template v-else>
          <RouterLink to="/login" class="nav-link">Login</RouterLink>
          <RouterLink to="/account/register" class="cta-button">Sign Up</RouterLink>
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
