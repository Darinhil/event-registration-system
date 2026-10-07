<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AdminTopbar from '../components/AdminTopbar.vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import adminLogo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter(); const route = useRoute(); const auth = useAuthStore()
const isAdmin = computed(() => auth.isAdmin); const roleLabel = computed(() => auth.roleLabel)
const eventMenuOpen = ref(false)
const eventSectionPaths = ['/admin/events', '/admin/users', '/admin/check-ins', '/admin/reports']
const isEventSection = computed(() => eventSectionPaths.some((path) => route.path === path || route.path.startsWith(`${path}/`)))
watch(() => route.path, (path) => {
  eventMenuOpen.value = ['/admin/users', '/admin/check-ins', '/admin/reports']
    .some((itemPath) => path === itemPath || path.startsWith(`${itemPath}/`))
}, { immediate: true })
const profilePhotoSrc = ref(auth.profilePhotoPreview || ''); let profilePhotoRequest = 0
const loadProfilePhoto = async () => { const id = ++profilePhotoRequest; if (!auth.user?.profile_photo) { profilePhotoSrc.value = ''; return } try { const { data } = await api.get('/me/profile-photo', { params: { photo: auth.user.profile_photo }, responseType: 'blob' }); if (id === profilePhotoRequest) profilePhotoSrc.value = auth.setProfilePhotoPreview(data) } catch { if (id === profilePhotoRequest) profilePhotoSrc.value = '' } }
onMounted(loadProfilePhoto); watch(() => auth.user?.profile_photo, loadProfilePhoto)
const logout = async () => { await auth.logout(); router.push('/login') }
</script>

<template>
  <div class="admin-app-shell"><a class="skip-link" href="#main-content">Skip to content</a><aside class="admin-sidebar">
    <RouterLink to="/admin" class="admin-brand"><img class="admin-brand-logo" :src="adminLogo" alt="Live &amp; Learn Cambodia" decoding="async" /></RouterLink>
    <nav class="admin-nav" :class="{ 'has-open-submenu': eventMenuOpen }" aria-label="Admin navigation">
      <RouterLink to="/admin" exact-active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>Dashboard</RouterLink>
      <div class="admin-nav-group" :class="{ 'is-expanded': eventMenuOpen, 'has-active-child': isEventSection }">
        <div class="admin-nav-group-heading">
          <RouterLink to="/admin/events" class="admin-nav-group-link" :class="{ 'is-active': route.path === '/admin/events' || route.path.startsWith('/admin/events/') }">
            <span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M7 2v4m10-4v4M3 9h18"/></svg></span>{{ isAdmin ? 'All Events' : 'My Events' }}
          </RouterLink>
          <button type="button" class="admin-nav-toggle" :aria-expanded="eventMenuOpen" aria-controls="admin-event-subnav" :aria-label="`${eventMenuOpen ? 'Collapse' : 'Expand'} event navigation`" @click="eventMenuOpen = !eventMenuOpen">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
          </button>
        </div>
        <div v-if="eventMenuOpen" id="admin-event-subnav" class="admin-nav-submenu">
          <RouterLink to="/admin/users" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0m2-9a3 3 0 1 1 0 6m0-6a5 5 0 0 1 4 8"/></svg></span>Attendance</RouterLink>
          <RouterLink to="/admin/check-ins" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/><circle cx="12" cy="12" r="9"/></svg></span>Check-in</RouterLink>
          <RouterLink to="/admin/reports" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V5m0 14h16M8 16v-4m4 4V8m4 8v-6"/></svg></span>Reports</RouterLink>
        </div>
      </div>
      <RouterLink to="/admin/personnel-profile" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/><path d="M19 8h3m-1.5-1.5v3"/></svg></span>Personnel Profile</RouterLink>
      <RouterLink v-if="isAdmin" to="/admin/admin-accounts" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0m5-9v6m3-3h-6"/></svg></span>Admin Accounts</RouterLink>
      <RouterLink v-if="isAdmin" to="/admin/settings" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v2m0 14v2M3 12h2m14 0h2m-3.4-6.6-1.4 1.4M7.8 17.8l-1.4 1.4m0-14.4 1.4 1.4m9.4 9.4 1.4 1.4"/><circle cx="12" cy="12" r="4"/></svg></span>Settings</RouterLink>
      <RouterLink v-else to="/admin/profile" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/></svg></span>Profile</RouterLink>
    </nav>
    <div class="admin-event-card"><div class="admin-event-thumb">LW</div><div><strong>Live workspace</strong><small>{{ roleLabel }} access</small></div><b>Ready</b></div>
    <div class="admin-user-card"><RouterLink to="/admin/profile" class="admin-user-link"><span class="admin-avatar"><img v-if="profilePhotoSrc" :src="profilePhotoSrc" alt="" /><span v-else>{{ (auth.user?.name || 'AD').slice(0, 2).toUpperCase() }}</span></span><span><strong>{{ auth.user?.name || 'Admin' }}</strong><small>{{ roleLabel }}</small></span></RouterLink><button type="button" aria-label="Log out" @click="logout">↪</button></div>
  </aside><div class="admin-main-shell"><AdminTopbar /><main id="main-content" tabindex="-1"><slot /></main></div></div>
</template>
