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
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="topbar">
      <RouterLink to="/" class="brand">
        <img :src="logo" alt="Live & Learn Cambodia" />
      </RouterLink>

      <nav aria-label="Main navigation">
        <template v-if="auth.isAuthenticated">
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

    <main id="main-content" tabindex="-1"><slot /></main>
  </div>
</template>

<style scoped>
@media (max-width: 640px) {
  .topbar {
    top: 0;
    gap: 8px;
    margin-inline: 0;
    padding: 8px 12px;
    border-radius: 0;
  }

  .topbar nav {
    min-width: 0;
    gap: 4px;
    flex-wrap: nowrap;
  }

  .topbar nav .nav-user-chip {
    display: inline-flex;
    min-width: 36px;
    justify-content: center;
    padding-inline: 6px;
  }

  .topbar nav .nav-user-name { display: none; }

  .topbar nav .nav-link,
  .topbar nav .logout-button,
  .topbar nav .cta-button {
    padding-inline: 9px;
    font-size: .75rem;
    white-space: nowrap;
  }
}
</style>
