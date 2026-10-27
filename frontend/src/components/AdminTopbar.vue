<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppIcon from './AppIcon.vue'
import { theme, toggleTheme } from '../utils/theme'

const router = useRouter()
const route = useRoute()
const personnelPageTitle = computed(() => ({ compliances: 'Compliances', 'contract-history': 'Contract History', training: 'Staff Online Training' }[route.query.section] || 'My Profile (5 steps)'))
const auth = useAuthStore()
const profileMenuOpen = ref(false)
const notificationOpen = ref(false)
const profilePhotoBroken = ref(false)
const profilePhotoSrc = ref(auth.profilePhotoPreview || '')
const profileLoadError = ref('')
const searchInput = ref(null)
const searchTerm = ref(String(route.query.search || ''))
const canSearchEvents = computed(() => auth.isAdmin || auth.isEventAdmin)
let profilePhotoRequest = 0

const submitSearch = () => {
  const search = searchTerm.value.trim()
  router.push({ path: '/admin/events', query: search ? { search } : {} })
  searchInput.value?.blur()
}

const logout = async () => { await auth.logout(); router.push('/login') }
const loadProfilePhoto = async () => {
  const requestId = ++profilePhotoRequest
  if (!auth.user?.profile_photo) {
    profilePhotoSrc.value = ''
    return
  }
  try {
    const { data } = await api.get('/me/profile-photo', {
      params: { photo: auth.user.profile_photo },
      responseType: 'blob',
    })
    if (requestId !== profilePhotoRequest) return
    profilePhotoSrc.value = auth.setProfilePhotoPreview(data)
    profilePhotoBroken.value = false
  } catch {
    if (requestId !== profilePhotoRequest) return
    profilePhotoSrc.value = ''
    profilePhotoBroken.value = true
  }
}
/* ---------- Notifications: recent registrations and check-ins (GET /admin/notifications) ---------- */
// "Unread" lives in this browser: anything newer than the last time the panel was read.
const notifications = ref([])
const notificationsLoading = ref(false)
const notificationsError = ref('')
const seenKey = computed(() => `admin-notifications-seen:${auth.user?.id ?? 'me'}`)
const readSeen = () => { try { return localStorage.getItem(seenKey.value) } catch { return null } }
const lastSeen = ref(readSeen())
const markSeen = (iso) => { lastSeen.value = iso; try { localStorage.setItem(seenKey.value, iso) } catch { /* storage unavailable */ } }
const isUnread = (item) => !!lastSeen.value && new Date(item.at) > new Date(lastSeen.value)
const unreadCount = computed(() => notifications.value.filter(isUnread).length)
const unreadBadge = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)))
const newestAt = () => notifications.value[0]?.at || new Date().toISOString()
const markAllRead = () => markSeen(newestAt())
let notificationsTicket = 0
const loadNotifications = async () => {
  const ticket = ++notificationsTicket
  notificationsLoading.value = true
  notificationsError.value = ''
  try {
    const items = (await api.get('/admin/notifications')).data.data || []
    if (ticket !== notificationsTicket) return
    notifications.value = items
    // First visit: start counting from now instead of flagging the whole history as new.
    if (!lastSeen.value) markSeen(newestAt())
  } catch {
    if (ticket === notificationsTicket) notificationsError.value = 'Couldn’t load notifications.'
  } finally {
    if (ticket === notificationsTicket) notificationsLoading.value = false
  }
}
const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
const timeAgo = (iso) => {
  const seconds = (new Date(iso).getTime() - Date.now()) / 1000
  const ago = Math.abs(seconds)
  if (ago < 60) return 'Just now'
  // [unit, seconds per unit, use this unit while younger than]
  const units = [['minute', 60, 3600], ['hour', 3600, 86400], ['day', 86400, 604800], ['week', 604800, 2592000], ['month', 2592000, Infinity]]
  const [unit, size] = units.find(([, , below]) => ago < below)
  return relative.format(Math.round(seconds / size), unit)
}
const notificationLink = (item) => (item.type === 'registration' ? `/admin/events/${item.event_id}/registrants` : `/admin/events/${item.event_id}`)
let notificationsTimer
const POLL_MS = 60000
const startPolling = () => { clearInterval(notificationsTimer); notificationsTimer = setInterval(() => { if (document.visibilityState === 'visible') loadNotifications() }, POLL_MS) }

const togglePanel = (panel) => {
  const wasOpen = notificationOpen.value
  profileMenuOpen.value = panel === 'profile' ? !profileMenuOpen.value : false
  notificationOpen.value = panel === 'notifications' ? !notificationOpen.value : false
  if (notificationOpen.value && !wasOpen) loadNotifications()
  // Items stay highlighted while the panel is open, then count as read.
  if (wasOpen && !notificationOpen.value) markAllRead()
}
const closePanels = () => {
  if (notificationOpen.value) markAllRead()
  profileMenuOpen.value = false
  notificationOpen.value = false
}
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
  loadNotifications()
  startPolling()
  try {
    await auth.fetchMe()
    profileLoadError.value = ''
  } catch (requestError) {
    profileLoadError.value = requestError.response?.data?.message || 'Unable to refresh your profile.'
  } finally {
    await loadProfilePhoto()
  }
})
watch(() => auth.user?.profile_photo, loadProfilePhoto)
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onGlobalKeydown)
  document.removeEventListener('click', onDocumentClick)
  clearInterval(notificationsTimer)
})
</script>

<template>
  <header class="admin-topbar">
    <strong v-if="route.path === '/admin/personnel-profile'" class="profile-context-title">{{ personnelPageTitle }}</strong>
    <form v-if="canSearchEvents" class="admin-search" role="search" @submit.prevent="submitSearch">
      <span class="admin-search-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.2-3.2" /></svg>
      </span>
      <input
        ref="searchInput"
        v-model="searchTerm"
        type="search"
        placeholder="Search events…"
        aria-label="Search events"
      />
    </form>

    <div class="admin-top-actions">
      <button type="button" class="admin-icon-action admin-theme-toggle" :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'" :title="theme === 'dark' ? 'Light theme' : 'Dark theme'" @click="toggleTheme">
        <AppIcon :name="theme === 'dark' ? 'sun' : 'moon'" :size="20" />
      </button>
      <div class="admin-action-wrap">
        <button type="button" class="admin-notification" :aria-label="unreadCount ? `Notifications, ${unreadCount} unread` : 'Notifications'" aria-controls="nt-panel" :aria-expanded="notificationOpen" @click="togglePanel('notifications')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8.5 12h5" /></svg>
          <span v-if="unreadCount" class="nt-badge" aria-hidden="true">{{ unreadBadge }}</span>
        </button>
        <section v-if="notificationOpen" id="nt-panel" class="nt-panel" aria-labelledby="nt-title">
          <header class="nt-head">
            <h2 id="nt-title">Notifications</h2>
            <button v-if="unreadCount" type="button" class="nt-link" @click="markAllRead">Mark all as read</button>
          </header>

          <div v-if="notificationsLoading && !notifications.length" class="nt-state" aria-busy="true">Loading…</div>
          <div v-else-if="notificationsError && !notifications.length" class="nt-state" role="alert">
            <p>{{ notificationsError }}</p>
            <button type="button" class="nt-link" @click="loadNotifications">Try again</button>
          </div>
          <div v-else-if="!notifications.length" class="nt-state">
            <AppIcon name="inbox" :size="22" />
            <strong>No activity yet</strong>
            <p>New registrations and check-ins for your events will show up here.</p>
          </div>
          <ul v-else class="nt-list">
            <li v-for="item in notifications" :key="item.id">
              <RouterLink :to="notificationLink(item)" class="nt-item" :class="{ 'is-unread': isUnread(item) }" @click="closePanels">
                <span class="nt-icon" :class="item.type === 'check_in' ? 'is-checkin' : 'is-reg'" aria-hidden="true"><AppIcon :name="item.type === 'check_in' ? 'user-check' : 'user-plus'" :size="16" /></span>
                <span class="nt-copy">
                  <strong>{{ item.title }}</strong>
                  <span>{{ item.event }}</span>
                </span>
                <time class="nt-time" :datetime="item.at">{{ timeAgo(item.at) }}</time>
                <span v-if="isUnread(item)" class="sr-only">(new)</span>
              </RouterLink>
            </li>
          </ul>
          <p v-if="notificationsError && notifications.length" class="nt-foot-error" role="status">{{ notificationsError }} Showing the last list.</p>
        </section>
      </div>

      <div class="profile-menu">
        <button type="button" class="profile-menu-trigger" :aria-expanded="profileMenuOpen" @click="togglePanel('profile')">
          <span class="admin-top-avatar">
            <img v-if="profilePhotoSrc && !profilePhotoBroken" :key="profilePhotoSrc" :src="profilePhotoSrc" alt="" @error="profilePhotoBroken = true" />
            <span v-else class="admin-avatar-fallback">{{ (auth.user?.name || 'Admin').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span>
          </span>
          <span class="admin-profile-copy"><strong>{{ auth.user?.name || 'Event Admin' }}</strong><small>{{ auth.roleLabel }}</small></span>
          <span class="admin-chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>
          </span>
        </button>
        <div v-if="profileMenuOpen" class="profile-menu-panel"><p v-if="profileLoadError" class="profile-alert profile-alert-error" role="alert">{{ profileLoadError }}</p><RouterLink to="/admin/profile" @click="profileMenuOpen = false">Profile settings</RouterLink><button type="button" @click="logout">Sign out</button></div>
      </div>
    </div>
  </header>
</template>

<style scoped>
.profile-context-title{flex:none;margin-right:auto;color:#172b4d;font-size:14px;font-weight:700;letter-spacing:-.1px}
@media(max-width:720px){.profile-context-title{font-size:12px}}
</style>
