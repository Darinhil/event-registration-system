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
          <RouterLink to="/profile" class="nav-user-chip" :title="'Edit profile'" aria-label="Edit profile">
            <span class="nav-avatar">
              <img v-if="auth.profilePhotoPreview || auth.user?.profile_photo" :src="auth.profilePhotoPreview || auth.user?.profile_photo" alt="" />
              <svg v-else viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="24" r="10" fill="#fff"/><path d="M17 49c1.3-9.2 7.2-14 15-14s13.7 4.8 15 14" fill="none" stroke="#fff" stroke-linecap="round" stroke-width="6"/></svg>
            </span>
            <span class="nav-user-name">User</span>
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
    width: 40px;
    height: 40px;
    min-width: 40px;
    justify-content: center;
    padding: 0;
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
