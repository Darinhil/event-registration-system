<script setup>
import { onMounted, ref, watch } from 'vue'
import AdminTopbar from '../components/AdminTopbar.vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import adminLogo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter()
const auth = useAuthStore()
const profilePhotoSrc = ref(auth.profilePhotoPreview || '')
let profilePhotoRequest = 0

const loadProfilePhoto = async () => {
  const requestId = ++profilePhotoRequest
  if (!auth.user?.profile_photo) {
    profilePhotoSrc.value = ''
    return
  }
  try {
    const { data } = await api.get('/me/profile-photo', { params: { photo: auth.user.profile_photo }, responseType: 'blob' })
    if (requestId !== profilePhotoRequest) return
    profilePhotoSrc.value = auth.setProfilePhotoPreview(data)
  } catch {
    if (requestId === profilePhotoRequest) profilePhotoSrc.value = ''
  }
}

onMounted(loadProfilePhoto)
watch(() => auth.user?.profile_photo, loadProfilePhoto)
const logout = async () => { await auth.logout(); router.push('/login') }
</script>

<template>
  <div class="admin-app-shell">
    <aside class="admin-sidebar">
      <RouterLink to="/admin" class="admin-brand"><img class="admin-brand-logo" :src="adminLogo" alt="Live &amp; Learn Cambodia" /></RouterLink>
      <nav class="admin-nav" aria-label="Admin navigation">
        <RouterLink to="/admin" exact-active-class="is-active"><span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg></span> Dashboard</RouterLink>
        <RouterLink to="/admin/events" active-class="is-active"><span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18"/><path d="M8 2.5v4M16 2.5v4"/></svg></span> Events</RouterLink>
        <RouterLink to="/admin/users" active-class="is-active"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.87"/></svg></span> Attendances</RouterLink>
        <RouterLink to="/admin/check-ins" active-class="is-active"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 11 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></span> Check-in</RouterLink>
        <RouterLink to="/admin/reports" class="reports-link" active-class="is-active"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg></span> Reports</RouterLink>
        <RouterLink to="/admin/profile" class="settings-link" active-class="is-active"><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> Settings</RouterLink>
      </nav>
      <div class="admin-event-card"><div class="admin-event-thumb">CT</div><div><strong>Community Tech Summit</strong><small>Sep 18, 2026 · Bangkok</small></div><b>Event active</b></div>
      <div class="admin-user-card"><RouterLink to="/admin/profile" class="admin-user-link"><span class="admin-avatar"><img v-if="profilePhotoSrc" :src="profilePhotoSrc" alt="" /><span v-else>{{ (auth.user?.name || 'AD').slice(0, 2).toUpperCase() }}</span></span><span><strong>{{ auth.user?.name || 'Admin' }}</strong><small>Administrator</small></span></RouterLink><button type="button" aria-label="Log out" @click="logout"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg></button></div>
    </aside>
    <div class="admin-main-shell">
      <AdminTopbar />
      <main><slot /></main>
    </div>
  </div>
</template>
