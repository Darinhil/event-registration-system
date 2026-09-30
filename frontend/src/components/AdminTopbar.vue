<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useThemeStore } from '../stores/theme'
import api from '../services/api'
import adminLogo from '../assets/Adminlogo.png'

const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()
const profileMenuOpen = ref(false)
const notificationOpen = ref(false)
const profilePhotoBroken = ref(false)
const profilePhotoSrc = ref('')
const searchInput = ref(null)

const logout = async () => { await auth.logout(); router.push('/login') }
const loadProfilePhoto = async () => {
  if (!auth.user?.profile_photo) {
    profilePhotoSrc.value = ''
    return
  }
  try {
    const { data } = await api.get('/me/profile-photo', { responseType: 'blob' })
    if (profilePhotoSrc.value.startsWith('blob:')) URL.revokeObjectURL(profilePhotoSrc.value)
    profilePhotoSrc.value = URL.createObjectURL(data)
    profilePhotoBroken.value = false
  } catch {
    profilePhotoSrc.value = ''
    profilePhotoBroken.value = true
  }
}
const togglePanel = (panel) => {
  profileMenuOpen.value = panel === 'profile' ? !profileMenuOpen.value : false
  notificationOpen.value = panel === 'notifications' ? !notificationOpen.value : false
}
const closePanels = () => { profileMenuOpen.value = false; notificationOpen.value = false }
const onGlobalKeydown = (event) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    searchInput.value?.focus()
  }
  if (event.key === 'Escape') closePanels()
}
const onDocumentClick = (event) => {
  if (!event.target.closest('.admin-action-wrap, .profile-menu')) closePanels()
}
onMounted(async () => {
  document.addEventListener('keydown', onGlobalKeydown)
  document.addEventListener('click', onDocumentClick)
  try { await auth.fetchMe() } finally { await loadProfilePhoto() }
})
watch(() => auth.user?.profile_photo, loadProfilePhoto)
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onGlobalKeydown)
  document.removeEventListener('click', onDocumentClick)
  if (profilePhotoSrc.value.startsWith('blob:')) URL.revokeObjectURL(profilePhotoSrc.value)
})
</script>

<template>
  <header class="admin-topbar" :class="{ 'is-dark': theme.dark }">
    <label class="admin-search">
      <span class="admin-search-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.2-3.2" /></svg>
      </span>
      <input
        ref="searchInput"
        type="search"
        placeholder="Search events, attendees…"
        aria-label="Search dashboard"
      />
    </label>

    <div class="admin-top-actions">
      <button type="button" class="admin-icon-action theme-toggle" :aria-pressed="theme.dark" aria-label="Toggle dark mode" @click="theme.toggle()">
        <svg class="theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4" /></svg>
        <svg class="theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z" /></svg>
      </button>

      <div class="admin-action-wrap">
        <button type="button" class="admin-notification" aria-label="Notifications" :aria-expanded="notificationOpen" @click="togglePanel('notifications')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8.5 12h5" /></svg><b>3</b>
        </button>
        <div v-if="notificationOpen" class="admin-popover"><strong>Notifications</strong><p>No new notifications.</p></div>
      </div>

      <div class="profile-menu">
        <button type="button" class="profile-menu-trigger" :aria-expanded="profileMenuOpen" @click="togglePanel('profile')">
          <span class="admin-top-avatar">
            <img v-if="profilePhotoSrc && !profilePhotoBroken" :key="profilePhotoSrc" :src="profilePhotoSrc" alt="" @error="profilePhotoBroken = true" />
            <img v-else :src="adminLogo" alt="Event Admin logo" />
          </span>
          <span class="admin-profile-copy"><strong>{{ auth.user?.name || 'Event Admin' }}</strong><small>Administrator</small></span>
          <span class="admin-chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>
          </span>
        </button>
        <div v-if="profileMenuOpen" class="profile-menu-panel"><RouterLink to="/admin/profile" @click="profileMenuOpen = false">Profile settings</RouterLink><button type="button" @click="logout">Sign out</button></div>
      </div>
    </div>
  </header>
</template>
