<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import AdminTopbar from '../components/AdminTopbar.vue'
import AppIcon from '../components/AppIcon.vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useSettingsStore } from '../stores/settings'
import { setThemeActive } from '../utils/theme'
import api from '../services/api'
import adminLogo from '../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter(); const route = useRoute(); const auth = useAuthStore()
const isAdmin = computed(() => auth.isAdmin); const roleLabel = computed(() => auth.roleLabel)
const hasEventWorkspace = computed(() => auth.isAdmin || auth.isEventAdmin)
const mobileMenuOpen = ref(false)
const PORTAL_KEY = 'admin-nav-portal-open'
const readPortalPref = () => { try { return localStorage.getItem(PORTAL_KEY) !== '0' } catch { return true } }
const personnelPortalOpen = ref(readPortalPref())
watch(personnelPortalOpen, (open) => { try { localStorage.setItem(PORTAL_KEY, open ? '1' : '0') } catch { /* storage unavailable */ } })
const eventMenuOpen = ref(false)
const personnelMenuOpen = ref(false)
const PERSONNEL_PATH = '/admin/personnel-profile'
const isPersonnelSection = computed(() => route.path === PERSONNEL_PATH)
const personnelItems = [
  { id: 'my-profile', label: 'My Profile', hint: '5 steps', icon: 'user' },
  { id: 'compliances', label: 'Compliances', icon: 'shield-check' },
  { id: 'contract-history', label: 'Contract History', icon: 'file-text' },
  { id: 'training', label: 'Staff Online Training', icon: 'graduation' },
]
const workspaceItems = [
  { id: 'time-management', label: 'Time Management', icon: 'clock', children: [
    { id: 'leave-application', label: 'Leave Application', icon: 'calendar-check' },
    { id: 'overtime-request', label: 'Overtime Request', icon: 'timer' },
    { id: 'monthly-timesheet', label: 'Monthly Timesheet', icon: 'calendar' },
  ] },
  { id: 'other-requests', label: 'Other Requests', icon: 'send', children: [
    { id: 'travel-application', label: 'Travel Application', icon: 'plane' },
    { id: 'fuel-log-sheet', label: 'Fuel Log-sheet', icon: 'fuel' },
    { id: 'purchase-request', label: 'Purchase Request', icon: 'cart' },
  ] },
  { id: 'finance-management', label: 'Finance Management', icon: 'receipt' },
  { id: 'my-requests', label: 'My Requests', icon: 'inbox' },
]
// Expand/collapse state for the groups above; each opens itself when one of its pages is shown.
const workspaceOpen = reactive(Object.fromEntries(workspaceItems.filter((item) => item.children).map((item) => [item.id, false])))
const isChildActive = (item) => !!item.children?.some((child) => isSectionActive(child.id))
const eventItems = [
  { to: '/admin/users', label: 'Attendance', icon: 'user-check' },
  { to: '/admin/check-ins', label: 'Check-in', icon: 'scan' },
  { to: '/admin/reports', label: 'Reports', icon: 'chart-column' },
]
const underPath = (path, base) => path === base || path.startsWith(`${base}/`)
const isEventsActive = computed(() => underPath(route.path, '/admin/events'))
const isEventSection = computed(() => isEventsActive.value || eventItems.some((item) => underPath(route.path, item.to)))
const isSectionActive = (id) => isPersonnelSection.value && route.query.section === id
const isWorkspaceActive = computed(() => workspaceItems.some((item) => isSectionActive(item.id) || isChildActive(item)))
const isPersonnelProfileActive = computed(() => isPersonnelSection.value && !isWorkspaceActive.value)
// Open the group that holds the current page; leave the others as the user left them.
watch(() => route.fullPath, () => {
  mobileMenuOpen.value = false
  if (isEventSection.value) eventMenuOpen.value = true
  if (isPersonnelProfileActive.value) personnelMenuOpen.value = true
  if (isPersonnelSection.value) personnelPortalOpen.value = true
  workspaceItems.forEach((item) => { if (item.children && (isSectionActive(item.id) || isChildActive(item))) workspaceOpen[item.id] = true })
}, { immediate: true })
const onKeydown = (event) => { if (event.key === 'Escape' && mobileMenuOpen.value) mobileMenuOpen.value = false }
onMounted(() => window.addEventListener('keydown', onKeydown)); onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
const initials = computed(() => (auth.user?.name || 'Admin').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase())
const profilePhotoSrc = ref(auth.profilePhotoPreview || ''); let profilePhotoRequest = 0
const loadProfilePhoto = async () => { const id = ++profilePhotoRequest; if (!auth.user?.profile_photo) { profilePhotoSrc.value = ''; return } try { const { data } = await api.get('/me/profile-photo', { params: { photo: auth.user.profile_photo }, responseType: 'blob' }); if (id === profilePhotoRequest) profilePhotoSrc.value = auth.setProfilePhotoPreview(data) } catch { if (id === profilePhotoRequest) profilePhotoSrc.value = '' } }
onMounted(loadProfilePhoto); watch(() => auth.user?.profile_photo, loadProfilePhoto)
const settings = useSettingsStore()
// Set during setup (not onMounted) so the first paint already uses the saved theme.
setThemeActive(true)
onBeforeUnmount(() => setThemeActive(false))
onMounted(() => settings.load())
const logout = async () => { await auth.logout(); router.push('/login') }
</script>

<template>
  <div class="admin-app-shell" :class="{ 'mobile-menu-open': mobileMenuOpen }"><a class="skip-link" href="#main-content">Skip to content</a><button type="button" class="admin-mobile-menu-toggle" :aria-expanded="mobileMenuOpen" aria-controls="admin-sidebar" aria-label="Toggle navigation" @click="mobileMenuOpen = !mobileMenuOpen"><span></span><span></span><span></span></button><button v-if="mobileMenuOpen" type="button" class="admin-mobile-menu-backdrop" aria-label="Close navigation" @click="mobileMenuOpen = false"></button><aside id="admin-sidebar" class="admin-sidebar">
    <div class="sb-brand-block"><RouterLink to="/admin" class="admin-brand sb-brand" aria-label="Live &amp; Learn Cambodia — go to Dashboard"><img class="admin-brand-logo sb-brand-logo" :src="adminLogo" alt="" decoding="async" /></RouterLink><p class="sb-brand-caption"><span class="sb-brand-name">{{ settings.loaded ? settings.site_name : '' }}</span><span class="sb-brand-tag">Admin</span></p></div>
    <nav class="sb-nav" aria-label="Admin navigation">
      <section class="sb-section" aria-labelledby="sb-label-workspace">
        <h2 id="sb-label-workspace" class="sb-label">Workspace</h2>
        <ul class="sb-list">
          <li><RouterLink to="/admin" class="sb-link" exact-active-class="is-active"><AppIcon class="sb-icon" name="layout-grid" :size="18" /><span class="sb-text">Dashboard</span></RouterLink></li>
          <li v-if="hasEventWorkspace" class="sb-group" :class="{ 'is-open': eventMenuOpen, 'has-active': isEventSection }">
            <div class="sb-row">
              <RouterLink to="/admin/events" class="sb-link" :class="{ 'is-active': isEventsActive, 'is-parent-active': isEventSection && !isEventsActive }" :aria-current="isEventsActive ? 'page' : undefined"><AppIcon class="sb-icon" name="calendar" :size="18" /><span class="sb-text">{{ isAdmin ? 'All Events' : 'My Events' }}</span></RouterLink>
              <button type="button" class="sb-toggle" :aria-expanded="eventMenuOpen" aria-controls="sb-events" :aria-label="`${eventMenuOpen ? 'Collapse' : 'Expand'} event pages`" @click="eventMenuOpen = !eventMenuOpen"><AppIcon name="chevron-down" :size="16" /></button>
            </div>
            <ul v-show="eventMenuOpen" id="sb-events" class="sb-sublist">
              <li v-for="item in eventItems" :key="item.to"><RouterLink :to="item.to" class="sb-sublink" active-class="is-active"><AppIcon class="sb-icon" :name="item.icon" :size="16" /><span class="sb-text">{{ item.label }}</span></RouterLink></li>
            </ul>
          </li>
        </ul>
      </section>

      <section class="sb-section" :class="{ 'is-collapsed': !personnelPortalOpen }">
        <h2 class="sb-label sb-label--toggle">
          <button type="button" :aria-expanded="personnelPortalOpen" aria-controls="sb-portal" @click="personnelPortalOpen = !personnelPortalOpen"><span>Personnel Portal</span><AppIcon name="chevron-down" :size="14" /></button>
        </h2>
        <ul v-show="personnelPortalOpen" id="sb-portal" class="sb-list">
          <li class="sb-group" :class="{ 'is-open': personnelMenuOpen, 'has-active': isPersonnelProfileActive }">
            <div class="sb-row">
              <RouterLink :to="PERSONNEL_PATH" class="sb-link" :class="{ 'is-active': isPersonnelProfileActive && !route.query.section, 'is-parent-active': isPersonnelProfileActive && !!route.query.section }" :aria-current="isPersonnelProfileActive && !route.query.section ? 'page' : undefined"><AppIcon class="sb-icon" name="user" :size="18" /><span class="sb-text">Personnel Profile</span></RouterLink>
              <button type="button" class="sb-toggle" :aria-expanded="personnelMenuOpen" aria-controls="sb-personnel" :aria-label="`${personnelMenuOpen ? 'Collapse' : 'Expand'} personnel profile pages`" @click="personnelMenuOpen = !personnelMenuOpen"><AppIcon name="chevron-down" :size="16" /></button>
            </div>
            <ul v-show="personnelMenuOpen" id="sb-personnel" class="sb-sublist">
              <li v-for="item in personnelItems" :key="item.id"><RouterLink :to="{ path: PERSONNEL_PATH, query: { section: item.id } }" class="sb-sublink" :class="{ 'is-active': isSectionActive(item.id) }" :aria-current="isSectionActive(item.id) ? 'page' : undefined"><AppIcon class="sb-icon" :name="item.icon" :size="16" /><span class="sb-text">{{ item.label }}</span><small v-if="item.hint" class="sb-hint">{{ item.hint }}</small></RouterLink></li>
            </ul>
          </li>
          <template v-for="item in workspaceItems" :key="item.id">
            <li v-if="item.children" class="sb-group" :class="{ 'is-open': workspaceOpen[item.id], 'has-active': isChildActive(item) }">
              <div class="sb-row">
                <RouterLink :to="{ path: PERSONNEL_PATH, query: { section: item.id } }" class="sb-link" :class="{ 'is-active': isSectionActive(item.id), 'is-parent-active': isChildActive(item) }" :aria-current="isSectionActive(item.id) ? 'page' : undefined"><AppIcon class="sb-icon" :name="item.icon" :size="18" /><span class="sb-text">{{ item.label }}</span></RouterLink>
                <button type="button" class="sb-toggle" :aria-expanded="workspaceOpen[item.id]" :aria-controls="`sb-${item.id}`" :aria-label="`${workspaceOpen[item.id] ? 'Collapse' : 'Expand'} ${item.label.toLowerCase()} pages`" @click="workspaceOpen[item.id] = !workspaceOpen[item.id]"><AppIcon name="chevron-down" :size="16" /></button>
              </div>
              <ul v-show="workspaceOpen[item.id]" :id="`sb-${item.id}`" class="sb-sublist">
                <li v-for="child in item.children" :key="child.id"><RouterLink :to="{ path: PERSONNEL_PATH, query: { section: child.id } }" class="sb-sublink" :class="{ 'is-active': isSectionActive(child.id) }" :aria-current="isSectionActive(child.id) ? 'page' : undefined"><AppIcon class="sb-icon" :name="child.icon" :size="16" /><span class="sb-text">{{ child.label }}</span></RouterLink></li>
              </ul>
            </li>
            <li v-else><RouterLink :to="{ path: PERSONNEL_PATH, query: { section: item.id } }" class="sb-link" :class="{ 'is-active': isSectionActive(item.id) }" :aria-current="isSectionActive(item.id) ? 'page' : undefined"><AppIcon class="sb-icon" :name="item.icon" :size="18" /><span class="sb-text">{{ item.label }}</span></RouterLink></li>
          </template>
        </ul>
      </section>

      <section class="sb-section" :aria-labelledby="isAdmin ? 'sb-label-admin' : 'sb-label-account'">
        <h2 v-if="isAdmin" id="sb-label-admin" class="sb-label">Administration</h2>
        <h2 v-else id="sb-label-account" class="sb-label">Account</h2>
        <ul class="sb-list">
          <li v-if="isAdmin"><RouterLink to="/admin/admin-accounts" class="sb-link" active-class="is-active"><AppIcon class="sb-icon" name="user-plus" :size="18" /><span class="sb-text">Admin Accounts</span></RouterLink></li>
          <li v-if="isAdmin"><RouterLink to="/admin/settings" class="sb-link" active-class="is-active"><svg class="sb-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.2 2h-.4a2 2 0 0 0-2 2v.2a2 2 0 0 1-1 1.7l-.4.3a2 2 0 0 1-2 0l-.2-.1a2 2 0 0 0-2.7.7l-.2.4a2 2 0 0 0 .7 2.7l.2.1a2 2 0 0 1 1 1.7v.6a2 2 0 0 1-1 1.7l-.2.1a2 2 0 0 0-.7 2.7l.2.4a2 2 0 0 0 2.7.7l.2-.1a2 2 0 0 1 2 0l.4.3a2 2 0 0 1 1 1.7v.2a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2v-.2a2 2 0 0 1 1-1.7l.4-.3a2 2 0 0 1 2 0l.2.1a2 2 0 0 0 2.7-.7l.2-.4a2 2 0 0 0-.7-2.7l-.2-.1a2 2 0 0 1-1-1.7v-.6a2 2 0 0 1 1-1.7l.2-.1a2 2 0 0 0 .7-2.7l-.2-.4a2 2 0 0 0-2.7-.7l-.2.1a2 2 0 0 1-2 0l-.4-.3a2 2 0 0 1-1-1.7V4a2 2 0 0 0-2-2Z"/><circle cx="12" cy="12" r="3"/></svg><span class="sb-text">Settings</span></RouterLink></li>
          <li v-else><RouterLink to="/admin/profile" class="sb-link" active-class="is-active"><AppIcon class="sb-icon" name="user" :size="18" /><span class="sb-text">Profile</span></RouterLink></li>
        </ul>
      </section>
    </nav>
    <div class="sb-footer">
      <RouterLink to="/admin/profile" class="sb-user" :title="`${auth.user?.name || 'Admin'} — view profile`">
        <span class="sb-avatar admin-avatar"><img v-if="profilePhotoSrc" :src="profilePhotoSrc" alt="" /><span v-else>{{ initials }}</span></span>
        <span class="sb-user-copy"><strong>{{ auth.user?.name || 'Admin' }}</strong><small>{{ roleLabel }}</small></span>
      </RouterLink>
      <button type="button" class="sb-logout" aria-label="Log out" title="Log out" @click="logout"><AppIcon name="log-out" :size="18" /></button>
    </div>
  </aside><div class="admin-main-shell"><AdminTopbar /><main id="main-content" tabindex="-1"><div v-if="settings.maintenance_mode" class="sb-maintenance" role="status"><AppIcon name="alert-circle" :size="18" /><span><strong>Maintenance mode is on.</strong> Attendees can’t submit new registrations.</span><RouterLink v-if="isAdmin && route.path !== '/admin/settings'" to="/admin/settings">Manage</RouterLink></div><slot /></main></div></div>
</template>
