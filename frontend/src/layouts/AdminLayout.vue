<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AdminTopbar from '../components/AdminTopbar.vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import adminLogo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter(); const auth = useAuthStore()
const isAdmin = computed(() => auth.isAdmin); const roleLabel = computed(() => auth.roleLabel)
const profilePhotoSrc = ref(auth.profilePhotoPreview || ''); let profilePhotoRequest = 0
const loadProfilePhoto = async () => { const id = ++profilePhotoRequest; if (!auth.user?.profile_photo) { profilePhotoSrc.value = ''; return } try { const { data } = await api.get('/me/profile-photo', { params: { photo: auth.user.profile_photo }, responseType: 'blob' }); if (id === profilePhotoRequest) profilePhotoSrc.value = auth.setProfilePhotoPreview(data) } catch { if (id === profilePhotoRequest) profilePhotoSrc.value = '' } }
onMounted(loadProfilePhoto); watch(() => auth.user?.profile_photo, loadProfilePhoto)
const logout = async () => { await auth.logout(); router.push('/login') }
</script>

<template>
  <div class="admin-app-shell"><aside class="admin-sidebar">
    <RouterLink to="/admin" class="admin-brand"><img class="admin-brand-logo" :src="adminLogo" alt="Live &amp; Learn Cambodia" decoding="async" /></RouterLink>
    <nav class="admin-nav" aria-label="Admin navigation">
      <RouterLink to="/admin" exact-active-class="is-active">▦ Dashboard</RouterLink>
      <RouterLink to="/admin/events" active-class="is-active">▣ {{ isAdmin ? 'All Events' : 'My Events' }}</RouterLink>
      <RouterLink to="/admin/users" active-class="is-active">♙ Attendances</RouterLink>
      <RouterLink to="/admin/check-ins" active-class="is-active">✓ Check-in</RouterLink>
      <RouterLink to="/admin/reports" active-class="is-active">▥ Reports</RouterLink>
      <RouterLink v-if="isAdmin" to="/admin/admin-accounts" active-class="is-active">⚙ Admin Accounts</RouterLink>
      <RouterLink v-if="isAdmin" to="/admin/settings" active-class="is-active">⚙ Settings</RouterLink>
      <RouterLink v-else to="/admin/profile" active-class="is-active">♙ Profile</RouterLink>
    </nav>
    <div class="admin-event-card"><div class="admin-event-thumb">CT</div><div><strong>Event workspace</strong><small>{{ roleLabel }} access</small></div><b>Active</b></div>
    <div class="admin-user-card"><RouterLink to="/admin/profile" class="admin-user-link"><span class="admin-avatar"><img v-if="profilePhotoSrc" :src="profilePhotoSrc" alt="" /><span v-else>{{ (auth.user?.name || 'AD').slice(0, 2).toUpperCase() }}</span></span><span><strong>{{ auth.user?.name || 'Admin' }}</strong><small>{{ roleLabel }}</small></span></RouterLink><button type="button" aria-label="Log out" @click="logout">↪</button></div>
  </aside><div class="admin-main-shell"><AdminTopbar /><main><slot /></main></div></div>
</template>
