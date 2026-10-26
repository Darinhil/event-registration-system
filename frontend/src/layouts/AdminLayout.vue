<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AdminTopbar from '../components/AdminTopbar.vue'
import AppIcon from '../components/AppIcon.vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import adminLogo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter(); const route = useRoute(); const auth = useAuthStore()
const isAdmin = computed(() => auth.isAdmin); const roleLabel = computed(() => auth.roleLabel)
const hasEventWorkspace = computed(() => auth.isAdmin || auth.isEventAdmin)
const eventMenuOpen = ref(false)
const mobileMenuOpen = ref(false)
const personnelPortalOpen = ref(true)
const personnelMenuOpen = ref(false)
const timeManagementOpen = ref(true)
const otherRequestsOpen = ref(true)
const isPersonnelSection = computed(() => route.path === '/admin/personnel-profile')
const timeManagementItems = [
  { id: 'leave-application', label: 'Leave Application' },
  { id: 'overtime-request', label: 'Overtime Request' },
  { id: 'monthly-timesheet', label: 'Monthly Timesheet' },
]
const otherRequestItems = [
  { id: 'travel-application', label: 'Travel Application' },
  { id: 'fuel-log-sheet', label: 'Fuel Log-sheet' },
  { id: 'purchase-request', label: 'Purchase Request' },
]
const isOtherRequestSection = computed(() => otherRequestItems.some((item) => route.query.section === item.id))
const isTimeManagementSection = computed(() => timeManagementItems.some((item) => route.query.section === item.id))
const isTimeManagementRoute = computed(() => route.query.section === 'time-management' || isTimeManagementSection.value)
const personnelItems = [
  { id: 'my-profile', label: 'My Profile (5 steps)', icon: 'user' },
  { id: 'compliances', label: 'Compliances', icon: 'lock' },
  { id: 'contract-history', label: 'Contract History', icon: 'calendar' },
  { id: 'training', label: 'Staff Online Training', icon: 'check-circle' },
]
const workspaceItems = [
  { id: 'time-management', label: 'Time Management', icon: 'clock' },
  { id: 'other-requests', label: 'Other Requests', icon: 'inbox' },
  { id: 'finance-management', label: 'Finance Management', icon: 'ticket' },
  { id: 'my-requests', label: 'My Requests', icon: 'inbox' },
]
const eventSectionPaths = ['/admin/events', '/admin/users', '/admin/check-ins', '/admin/reports']
const isEventSection = computed(() => eventSectionPaths.some((path) => route.path === path || route.path.startsWith(`${path}/`)))
watch(() => route.path, (path) => {
  mobileMenuOpen.value = false
  eventMenuOpen.value = ['/admin/users', '/admin/check-ins', '/admin/reports']
    .some((itemPath) => path === itemPath || path.startsWith(`${itemPath}/`))
}, { immediate: true })
watch(isPersonnelSection, (open) => { personnelMenuOpen.value = open }, { immediate: true })
watch(isTimeManagementRoute, (open) => { if (open) timeManagementOpen.value = true }, { immediate: true })
watch(isOtherRequestSection, (open) => { if (open) otherRequestsOpen.value = true }, { immediate: true })
const profilePhotoSrc = ref(auth.profilePhotoPreview || ''); let profilePhotoRequest = 0
const loadProfilePhoto = async () => { const id = ++profilePhotoRequest; if (!auth.user?.profile_photo) { profilePhotoSrc.value = ''; return } try { const { data } = await api.get('/me/profile-photo', { params: { photo: auth.user.profile_photo }, responseType: 'blob' }); if (id === profilePhotoRequest) profilePhotoSrc.value = auth.setProfilePhotoPreview(data) } catch { if (id === profilePhotoRequest) profilePhotoSrc.value = '' } }
onMounted(loadProfilePhoto); watch(() => auth.user?.profile_photo, loadProfilePhoto)
const logout = async () => { await auth.logout(); router.push('/login') }
</script>

<template>
  <div class="admin-app-shell" :class="{ 'mobile-menu-open': mobileMenuOpen }"><a class="skip-link" href="#main-content">Skip to content</a><button type="button" class="admin-mobile-menu-toggle" :aria-expanded="mobileMenuOpen" aria-controls="admin-sidebar" aria-label="Toggle navigation" @click="mobileMenuOpen = !mobileMenuOpen"><span></span><span></span><span></span></button><button v-if="mobileMenuOpen" type="button" class="admin-mobile-menu-backdrop" aria-label="Close navigation" @click="mobileMenuOpen = false"></button><aside id="admin-sidebar" class="admin-sidebar">
    <RouterLink to="/admin" class="admin-brand"><img class="admin-brand-logo" :src="adminLogo" alt="Live &amp; Learn Cambodia" decoding="async" /></RouterLink>
    <nav class="admin-nav" :class="{ 'has-open-submenu': eventMenuOpen || personnelMenuOpen }" aria-label="Admin navigation">
      <p class="admin-nav-section-label">WORKSPACE</p>
      <div v-if="hasEventWorkspace" class="admin-nav-group" :class="{ 'is-expanded': eventMenuOpen, 'has-active-child': isEventSection }">
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
      <RouterLink to="/admin" exact-active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>Dashboard</RouterLink>
      <button type="button" class="personnel-portal-heading" :aria-expanded="personnelPortalOpen" aria-controls="personnel-portal-menu" @click="personnelPortalOpen = !personnelPortalOpen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2.8 2.8 5.7 6.3.9-4.5 4.4 1.1 6.2-5.7-3-5.7 3 1.1-6.2-4.5-4.4 6.3-.9L12 2.8Z"/></svg><span>Personnel Portal</span><svg class="portal-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg></button>
      <div v-if="personnelPortalOpen" id="personnel-portal-menu" class="personnel-portal-menu">
      <div class="admin-nav-group" :class="{ 'is-expanded': personnelMenuOpen, 'has-active-child': isPersonnelSection }">
        <div class="admin-nav-group-heading">
          <RouterLink to="/admin/personnel-profile" class="admin-nav-group-link" :class="{ 'is-active': isPersonnelSection }"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/><path d="M19 8h3m-1.5-1.5v3"/></svg></span>Personnel Profile</RouterLink>
          <button type="button" class="admin-nav-toggle" :aria-expanded="personnelMenuOpen" aria-controls="admin-personnel-subnav" aria-label="Toggle personnel navigation" @click="personnelMenuOpen = !personnelMenuOpen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg></button>
        </div>
        <div v-if="personnelMenuOpen" id="admin-personnel-subnav" class="admin-nav-submenu admin-personnel-submenu">
          <RouterLink v-for="item in personnelItems" :key="item.id" :to="{ path: '/admin/personnel-profile', query: { section: item.id } }" :class="{ 'is-active': route.query.section === item.id }"><span class="admin-nav-icon" aria-hidden="true"><AppIcon :name="item.icon" :size="17" /></span>{{ item.label }}</RouterLink>
        </div>
      </div>
      <div class="admin-nav-group time-management-group" :class="{ 'is-expanded': timeManagementOpen, 'has-active-child': isTimeManagementSection }">
        <div class="admin-nav-group-heading">
          <RouterLink :to="{ path: '/admin/personnel-profile', query: { section: 'time-management' } }" class="admin-nav-group-link" :class="{ 'is-active': route.query.section === 'time-management' }"><span class="admin-nav-icon" aria-hidden="true"><AppIcon name="clock" :size="17" /></span>Time Management</RouterLink>
          <button type="button" class="admin-nav-toggle" :aria-expanded="timeManagementOpen" aria-controls="admin-time-management-subnav" :aria-label="`${timeManagementOpen ? 'Collapse' : 'Expand'} time management navigation`" @click="timeManagementOpen = !timeManagementOpen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg></button>
        </div>
        <div v-if="timeManagementOpen" id="admin-time-management-subnav" class="admin-nav-submenu time-management-submenu">
          <RouterLink v-for="item in timeManagementItems" :key="item.id" :to="{ path: '/admin/personnel-profile', query: { section: item.id } }" :class="{ 'is-active': route.query.section === item.id }">{{ item.label }}</RouterLink>
        </div>
      </div>
      <div class="admin-nav-group other-requests-group" :class="{ 'is-expanded': otherRequestsOpen, 'has-active-child': isOtherRequestSection }">
        <div class="admin-nav-group-heading">
          <RouterLink :to="{ path: '/admin/personnel-profile', query: { section: 'other-requests' } }" class="admin-nav-group-link" :class="{ 'is-active': route.query.section === 'other-requests' }"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m21 3-7.4 18-3.8-7.8L2 9.4 21 3Z"/><path d="M9.8 13.2 15 8"/></svg></span>Other Requests</RouterLink>
          <button type="button" class="admin-nav-toggle" :aria-expanded="otherRequestsOpen" aria-controls="admin-other-requests-subnav" :aria-label="`${otherRequestsOpen ? 'Collapse' : 'Expand'} other requests navigation`" @click="otherRequestsOpen = !otherRequestsOpen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg></button>
        </div>
        <div v-if="otherRequestsOpen" id="admin-other-requests-subnav" class="admin-nav-submenu other-requests-submenu">
          <RouterLink v-for="item in otherRequestItems" :key="item.id" :to="{ path: '/admin/personnel-profile', query: { section: item.id } }" :class="{ 'is-active': route.query.section === item.id }">{{ item.label }}</RouterLink>
        </div>
      </div>
      <RouterLink v-for="item in workspaceItems.filter((item) => !['time-management', 'other-requests'].includes(item.id))" :key="item.id" :to="{ path: '/admin/personnel-profile', query: { section: item.id } }" :class="{ 'is-active': route.query.section === item.id }"><span class="admin-nav-icon" aria-hidden="true"><AppIcon :name="item.icon" :size="17" /></span>{{ item.label }}</RouterLink>
      </div>
      <p v-if="isAdmin" class="admin-nav-section-label administration-section-label">ADMINISTRATION</p>
      <RouterLink v-if="isAdmin" to="/admin/admin-accounts" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0m5-9v6m3-3h-6"/></svg></span>Admin Accounts</RouterLink>
      <RouterLink v-if="isAdmin" to="/admin/settings" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v2m0 14v2M3 12h2m14 0h2m-3.4-6.6-1.4 1.4M7.8 17.8l-1.4 1.4m0-14.4 1.4 1.4m9.4 9.4 1.4 1.4"/><circle cx="12" cy="12" r="4"/></svg></span>Settings</RouterLink>
      <RouterLink v-else to="/admin/profile" active-class="is-active"><span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/></svg></span>Profile</RouterLink>
    </nav>
    <div class="admin-event-card"><div class="admin-event-thumb">LW</div><div><strong>Live workspace</strong><small>{{ roleLabel }} access</small></div><b>Ready</b></div>
    <div class="admin-user-card"><RouterLink to="/admin/profile" class="admin-user-link"><span class="admin-avatar"><img v-if="profilePhotoSrc" :src="profilePhotoSrc" alt="" /><span v-else>{{ (auth.user?.name || 'AD').slice(0, 2).toUpperCase() }}</span></span><span><strong>{{ auth.user?.name || 'Admin' }}</strong><small>{{ roleLabel }}</small></span></RouterLink></div>
    <button class="admin-sidebar-logout" type="button" @click="logout"><span class="admin-sidebar-logout-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg></span><span>Log out</span></button>
  </aside><div class="admin-main-shell"><AdminTopbar /><main id="main-content" tabindex="-1"><slot /></main></div></div>
</template>
