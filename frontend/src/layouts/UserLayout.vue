<script setup>
import { useRouter } from 'vue-router'
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import logo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter()
const auth = useAuthStore()
const mobileMenuOpen = ref(false)
const props = defineProps({
  onboarding: { type: Boolean, default: false },
})

const logout = async () => {
  await auth.logout()
  router.push('/')
}

</script>
<template>
  <div class="shell" :class="{ 'mobile-menu-open': mobileMenuOpen, 'onboarding-shell': props.onboarding }">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header v-if="!props.onboarding" class="topbar">
      <RouterLink to="/" class="brand">
        <img :src="logo" alt="Live & Learn Cambodia" />
      </RouterLink>

      <button type="button" class="mobile-menu-toggle" :aria-expanded="mobileMenuOpen" aria-controls="user-navigation" aria-label="Toggle navigation" @click="mobileMenuOpen = !mobileMenuOpen"><span></span><span></span><span></span></button>
      <button v-if="mobileMenuOpen" type="button" class="mobile-menu-backdrop" aria-label="Close navigation" @click="mobileMenuOpen = false"></button>
      <nav id="user-navigation" :class="{ 'is-open': mobileMenuOpen }" aria-label="Main navigation">
        <template v-if="auth.isAuthenticated">
          <RouterLink to="/me" class="nav-link" @click="mobileMenuOpen = false">My information</RouterLink>
          <div class="nav-account-actions">
            <RouterLink to="/profile" class="nav-user-chip" :title="'Edit profile'" aria-label="Edit profile" @click="mobileMenuOpen = false">
              <span class="nav-avatar">
                <img v-if="auth.profilePhotoPreview || auth.user?.profile_photo" :src="auth.profilePhotoPreview || auth.user?.profile_photo" alt="" />
                <svg v-else viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="24" r="10" fill="#fff"/><path d="M17 49c1.3-9.2 7.2-14 15-14s13.7 5.8 15 14" fill="none" stroke="#fff" stroke-linecap="round" stroke-width="6"/></svg>
              </span>
              <span class="nav-user-name">User</span>
            </RouterLink>
            <button class="logout-button" type="button" @click="logout">Logout</button>
          </div>
        </template>

        <template v-else>
          <RouterLink to="/login" class="nav-link" @click="mobileMenuOpen = false">Login</RouterLink>
          <RouterLink to="/account/register" class="cta-button" @click="mobileMenuOpen = false">Sign Up</RouterLink>
        </template>
      </nav>
    </header>

    <main id="main-content" tabindex="-1"><slot /></main>
  </div>
</template>

<style scoped>
.shell.onboarding-shell { min-height: 100vh; background: #f5f7fc; }

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
