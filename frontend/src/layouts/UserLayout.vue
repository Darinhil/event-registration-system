<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import logo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const isRegistrationPage = computed(() => route.path.endsWith('/register') && route.path.startsWith('/events/'))
const settings = ref({ registration_notice: '', maintenance_mode: false, site_name: '' })
const settingsLoaded = ref(false)

onMounted(async () => {
  try {
    const response = await api.get('/public-settings')
    settings.value = { ...settings.value, ...response.data?.data }
    if (settings.value.site_name) document.title = settings.value.site_name
  } catch {
    // Public settings are optional for the page shell; keep the page usable if unavailable.
  } finally {
    settingsLoaded.value = true
  }
})

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

    <main id="main-content" tabindex="-1">
      <div v-if="isRegistrationPage && settings.registration_notice && !settings.maintenance_mode" class="registration-notice" role="note">
        <strong>Registration notice</strong>
        <p>{{ settings.registration_notice }}</p>
      </div>
      <section v-if="settingsLoaded && settings.maintenance_mode && !auth.isAdmin" class="maintenance-page" role="status">
        <span class="maintenance-mark" aria-hidden="true">!</span>
        <p class="maintenance-eyebrow">System maintenance</p>
        <h1>{{ settings.site_name || 'Event Registration System' }} is temporarily unavailable</h1>
        <p>We’re performing maintenance. Please check back soon.</p>
      </section>
      <slot v-else />
    </main>
  </div>
</template>

<style scoped>
.registration-notice {
  width: min(100% - 32px, 1080px);
  margin: 18px auto 0;
  padding: 15px 18px;
  border: 1px solid #b9d5f5;
  border-radius: 12px;
  background: #f0f7ff;
  color: #244e79;
}
.registration-notice strong { font-size: .85rem; }
.registration-notice p { margin: 4px 0 0; line-height: 1.5; }
.maintenance-page {
  display: grid;
  justify-items: center;
  width: min(100% - 32px, 700px);
  margin: clamp(48px, 12vh, 120px) auto;
  padding: clamp(28px, 6vw, 56px);
  border: 1px solid #dce7f2;
  border-radius: 22px;
  background: #fff;
  box-shadow: 0 18px 50px rgba(27, 67, 108, .09);
  text-align: center;
}
.maintenance-mark { display: grid; place-items: center; width: 52px; height: 52px; border-radius: 16px; background: #fff4db; color: #b77912; font-size: 1.6rem; font-weight: 800; }
.maintenance-eyebrow { margin: 18px 0 6px; color: #66809b; font-size: .75rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.maintenance-page h1 { margin: 0; color: #173b67; font-size: clamp(1.6rem, 5vw, 2.4rem); letter-spacing: -.035em; line-height: 1.15; }
.maintenance-page > p:last-child { margin: 14px 0 0; color: #71839a; line-height: 1.6; }

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
