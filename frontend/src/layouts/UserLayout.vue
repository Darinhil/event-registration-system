<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useSettingsStore } from '../stores/settings'
import AppIcon from '../components/AppIcon.vue'
import logo from '../assets/LLEE_Cambodia_Inverse_L.png'

// Onboarding pages (the /me profile steps) use a full-screen layout without the top bar.
const props = defineProps({
  onboarding: { type: Boolean, default: false },
})
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const settings = useSettingsStore()

// The bar uses its own un-* classes: older global stylesheets style .topbar / .nav-link heavily.
// No Check in link: attendees reach /check-in only by scanning the entrance QR at the venue.
const links = computed(() => (auth.isAuthenticated
  ? [{ to: '/', label: 'Home', icon: 'layout-grid', exact: true }, { to: '/me', label: 'My information', icon: 'user' }]
  : [{ to: '/', label: 'Home', icon: 'layout-grid', exact: true }]))
const isActive = (link) => (link.exact ? route.path === link.to : route.path === link.to || route.path.startsWith(`${link.to}/`))
const firstName = computed(() => auth.user?.name?.trim().split(/\s+/)[0] || 'Account')
const initials = computed(() => (auth.user?.name || '').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '?')
const photo = computed(() => auth.profilePhotoPreview || auth.user?.profile_photo || '')
const contact = computed(() => auth.user?.email || auth.user?.phone || '')

/* ---------- Account menu ---------- */
const menuOpen = ref(false)
const menuEl = ref(null)
const menuButton = ref(null)
const openMenu = async () => { menuOpen.value = true; await nextTick(); menuEl.value?.querySelector('a, button')?.focus() }
const closeMenu = (refocus = false) => { menuOpen.value = false; if (refocus) menuButton.value?.focus() }
const onMenuKeydown = (event) => {
  const items = [...(menuEl.value?.querySelectorAll('a, button') || [])]
  const index = items.indexOf(document.activeElement)
  if (event.key === 'Escape') { event.preventDefault(); closeMenu(true) }
  if (event.key === 'ArrowDown') { event.preventDefault(); items[(index + 1) % items.length]?.focus() }
  if (event.key === 'ArrowUp') { event.preventDefault(); items[(index - 1 + items.length) % items.length]?.focus() }
  if (event.key === 'Tab') closeMenu()
}

/* ---------- Mobile panel ---------- */
const mobileOpen = ref(false)
watch(() => route.fullPath, () => { mobileOpen.value = false; menuOpen.value = false })

const onDocumentClick = (event) => { if (menuOpen.value && !event.target.closest('.un-account')) closeMenu() }
const onKeydown = (event) => { if (event.key === 'Escape' && mobileOpen.value) mobileOpen.value = false }
onMounted(() => { settings.load(); document.addEventListener('click', onDocumentClick); document.addEventListener('keydown', onKeydown) })
onBeforeUnmount(() => { document.removeEventListener('click', onDocumentClick); document.removeEventListener('keydown', onKeydown) })

const logout = async () => {
  menuOpen.value = false
  mobileOpen.value = false
  await auth.logout()
  router.push('/')
}
</script>

<template>
  <div class="shell" :class="{ 'onboarding-shell': props.onboarding }">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header v-if="!props.onboarding" class="un-bar">
      <div class="un-inner">
        <RouterLink to="/" class="un-brand" aria-label="Live & Learn Cambodia — home">
          <img :src="logo" alt="" />
        </RouterLink>

        <nav class="un-links" aria-label="Main navigation">
          <RouterLink v-for="link in links" :key="link.to" :to="link.to" class="un-link" :class="{ 'is-active': isActive(link) }" :aria-current="isActive(link) ? 'page' : undefined">{{ link.label }}</RouterLink>
        </nav>

        <div class="un-right">
          <!-- Signed in: one account menu instead of a separate chip and logout button -->
          <div v-if="auth.isAuthenticated" class="un-account">
            <button ref="menuButton" type="button" class="un-account__button" :aria-expanded="menuOpen" aria-haspopup="menu" aria-controls="un-account-menu" @click.stop="menuOpen ? closeMenu() : openMenu()">
              <span class="un-avatar"><img v-if="photo" :src="photo" alt="" /><template v-else>{{ initials }}</template></span>
              <span class="un-account__name">{{ firstName }}</span>
              <AppIcon name="chevron-down" :size="16" class="un-account__chev" />
            </button>
            <div v-show="menuOpen" id="un-account-menu" ref="menuEl" class="un-menu" role="menu" aria-label="Account" @keydown="onMenuKeydown">
              <div class="un-menu__who">
                <span class="un-avatar un-avatar--lg"><img v-if="photo" :src="photo" alt="" /><template v-else>{{ initials }}</template></span>
                <span><strong>{{ auth.user?.name }}</strong><small v-if="contact">{{ contact }}</small></span>
              </div>
              <RouterLink to="/me" class="un-menu__item" role="menuitem" @click="closeMenu()"><AppIcon name="ticket" :size="16" />My information</RouterLink>
              <RouterLink to="/profile" class="un-menu__item" role="menuitem" @click="closeMenu()"><AppIcon name="user" :size="16" />Profile settings</RouterLink>
              <button type="button" class="un-menu__item is-danger" role="menuitem" @click="logout"><AppIcon name="log-out" :size="16" />Log out</button>
            </div>
          </div>
          <template v-else>
            <RouterLink to="/login" class="un-btn un-btn--ghost">Log in</RouterLink>
            <RouterLink to="/account/register" class="un-btn un-btn--primary">Sign up</RouterLink>
          </template>

          <button type="button" class="un-burger" :aria-expanded="mobileOpen" aria-controls="un-mobile" :aria-label="mobileOpen ? 'Close menu' : 'Open menu'" @click="mobileOpen = !mobileOpen">
            <AppIcon :name="mobileOpen ? 'x' : 'list'" :size="22" />
          </button>
        </div>
      </div>

      <!-- Phones: links and account actions in a drop-down panel -->
      <div v-show="mobileOpen" id="un-mobile" class="un-mobile">
        <div v-if="auth.isAuthenticated" class="un-menu__who">
          <span class="un-avatar un-avatar--lg"><img v-if="photo" :src="photo" alt="" /><template v-else>{{ initials }}</template></span>
          <span><strong>{{ auth.user?.name }}</strong><small v-if="contact">{{ contact }}</small></span>
        </div>
        <RouterLink v-for="link in links" :key="link.to" :to="link.to" class="un-menu__item" :class="{ 'is-active': isActive(link) }" :aria-current="isActive(link) ? 'page' : undefined"><AppIcon :name="link.icon" :size="18" />{{ link.label }}</RouterLink>
        <template v-if="auth.isAuthenticated">
          <RouterLink to="/profile" class="un-menu__item" :class="{ 'is-active': route.path === '/profile' }"><AppIcon name="pen" :size="18" />Profile settings</RouterLink>
          <button type="button" class="un-menu__item is-danger" @click="logout"><AppIcon name="log-out" :size="18" />Log out</button>
        </template>
        <div v-else class="un-mobile__auth">
          <RouterLink to="/login" class="un-btn un-btn--ghost">Log in</RouterLink>
          <RouterLink to="/account/register" class="un-btn un-btn--primary">Sign up</RouterLink>
        </div>
      </div>
    </header>
    <button v-if="mobileOpen" type="button" class="un-scrim" aria-label="Close menu" tabindex="-1" @click="mobileOpen = false"></button>

    <div v-if="settings.maintenance_mode || settings.registration_notice" class="site-notices">
      <p v-if="settings.maintenance_mode" class="site-notice site-notice--warn" role="status"><AppIcon name="alert-circle" :size="18" /><span><strong>Scheduled maintenance.</strong> New registrations are paused for now. You can still view events and your existing registrations.</span></p>
      <p v-if="settings.registration_notice" class="site-notice" role="note"><AppIcon name="info" :size="18" /><span>{{ settings.registration_notice }}</span></p>
    </div>
    <main id="main-content" tabindex="-1"><slot /></main>
  </div>
</template>

<style scoped>
/* Below the sign-in page's full-screen overlay (z-index 30), which must cover the bar. */
.un-bar { position: sticky; top: 0; z-index: 20; border-bottom: 1px solid #e3eaf1; background: rgba(255, 255, 255, .92); backdrop-filter: saturate(1.4) blur(10px); }
.un-inner { display: flex; max-width: 1180px; height: 68px; align-items: center; gap: 28px; margin: 0 auto; padding: 0 24px; }
.un-brand { display: flex; flex: none; align-items: center; border-radius: 8px; }
.un-brand img { display: block; width: auto; height: 42px; }
.un-brand:focus-visible, .un-link:focus-visible, .un-account__button:focus-visible, .un-btn:focus-visible, .un-burger:focus-visible, .un-menu__item:focus-visible { outline: 3px solid rgba(11, 127, 98, .3); outline-offset: 2px; }

/* Links: text with a teal underline for the current page */
.un-links { display: flex; height: 100%; align-items: stretch; gap: 4px; }
.un-link { position: relative; display: flex; align-items: center; padding: 0 12px; color: #4b6079; font-size: .94rem; font-weight: 600; text-decoration: none; transition: color .15s; }
.un-link:hover { color: #17365f; }
.un-link::after { position: absolute; right: 12px; bottom: -1px; left: 12px; height: 3px; border-radius: 3px 3px 0 0; background: transparent; content: ""; transition: background .15s; }
.un-link.is-active { color: #0b6e57; }
.un-link.is-active::after { background: #0b7f62; }

.un-right { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.un-btn { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; padding: 0 16px; border: 1px solid transparent; border-radius: 10px; font-size: .92rem; font-weight: 600; text-decoration: none; white-space: nowrap; }
.un-btn--ghost { color: #17365f; }
.un-btn--ghost:hover { background: #f2f6f9; }
.un-btn--primary { background: #0b7f62; color: #fff; }
.un-btn--primary:hover { background: #086a51; }

/* Account menu */
.un-account { position: relative; }
.un-account__button { display: inline-flex; min-height: 44px; align-items: center; gap: 8px; margin: 0; padding: 4px 10px 4px 4px; border: 1px solid #e3eaf1; border-radius: 999px; background: #fff; color: #17365f; font: inherit; font-weight: 600; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
.un-account__button:hover { border-color: #c9d6e2; box-shadow: 0 2px 8px rgba(23, 54, 95, .06); transform: none; }
.un-account__button[aria-expanded="true"] { border-color: #9fd6c3; }
.un-account__button[aria-expanded="true"] .un-account__chev { transform: rotate(180deg); }
.un-account__chev { color: #6b8099; transition: transform .15s; }
.un-account__name { max-width: 120px; overflow: hidden; font-size: .92rem; text-overflow: ellipsis; white-space: nowrap; }
.un-avatar { display: grid; width: 34px; height: 34px; flex: none; place-items: center; overflow: hidden; border-radius: 50%; background: linear-gradient(135deg, #0b7f62, #13a37f); color: #fff; font-size: .78rem; font-weight: 700; }
.un-avatar img { width: 100%; height: 100%; object-fit: cover; }
.un-avatar--lg { width: 40px; height: 40px; font-size: .86rem; }
.un-menu { position: absolute; top: calc(100% + 8px); right: 0; width: 260px; padding: 6px; border: 1px solid #e3eaf1; border-radius: 14px; background: #fff; box-shadow: 0 16px 40px rgba(16, 36, 61, .16); }
.un-menu__who { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; padding: 10px 10px 12px; border-bottom: 1px solid #edf1f5; }
.un-menu__who > span:last-child { display: grid; min-width: 0; }
.un-menu__who strong { overflow: hidden; color: #17365f; font-size: .94rem; text-overflow: ellipsis; white-space: nowrap; }
.un-menu__who small { overflow: hidden; color: #6b8099; font-size: .8rem; text-overflow: ellipsis; white-space: nowrap; }
.un-menu__item { display: flex; width: 100%; min-height: 42px; align-items: center; gap: 10px; margin: 0; padding: 0 10px; border: 0; border-radius: 9px; background: transparent; color: #24405f; font: inherit; font-size: .92rem; font-weight: 500; text-align: left; text-decoration: none; cursor: pointer; }
.un-menu__item svg { color: #6b8099; }
.un-menu__item:hover { background: #f2f6f9; transform: none; }
.un-menu__item.is-active { background: #e8f5f0; color: #0b6e57; font-weight: 600; }
.un-menu__item.is-active svg { color: #0b7f62; }
.un-menu__item.is-danger { color: #b42d20; }
.un-menu__item.is-danger svg { color: #b42d20; }
.un-menu__item.is-danger:hover { background: #fdf1f0; }

/* Phones */
.un-burger { display: none; width: 44px; height: 44px; place-items: center; margin: 0; padding: 0; border: 1px solid #e3eaf1; border-radius: 10px; background: #fff; color: #17365f; cursor: pointer; }
.un-mobile { display: none; }
.un-scrim { display: none; }

.site-notices { display: grid; gap: 8px; max-width: 1180px; margin: 8px auto 0; padding: 0 20px; }
.site-notice { display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 11px 14px; border: 1px solid #cfe6dd; border-radius: 10px; background: #f0faf6; color: #0b4d3c; font-size: .9rem; line-height: 1.5; white-space: pre-line; }
.site-notice svg { flex: none; margin-top: 2px; }
.site-notice--warn { border-color: #f1d8a8; background: #fffaf0; color: #6b4504; }

@media (max-width: 720px) {
  .un-inner { height: 60px; gap: 12px; padding: 0 16px; }
  .un-brand img { height: 36px; }
  .un-links, .un-account, .un-right > .un-btn { display: none; }
  .un-burger { display: grid; }
  .un-mobile { position: absolute; top: 100%; right: 0; left: 0; z-index: 41; display: grid; gap: 2px; padding: 8px 12px 14px; border-bottom: 1px solid #e3eaf1; background: #fff; box-shadow: 0 16px 30px rgba(16, 36, 61, .12); }
  .un-mobile .un-menu__item { min-height: 48px; font-size: 1rem; }
  .un-mobile__auth { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding-top: 8px; }
  .un-scrim { position: fixed; inset: 0; z-index: 19; display: block; border: 0; background: rgba(17, 36, 59, .3); }
  .site-notices { padding: 0 12px; }
}
@media (prefers-reduced-motion: reduce) { .un-account__chev, .un-link, .un-link::after { transition: none; } }
.shell.onboarding-shell { min-height: 100vh; background: #f5f7fc; }
</style>
