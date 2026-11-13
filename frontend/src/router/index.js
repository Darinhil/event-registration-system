import { ref } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { takeIdleSignOut } from '../utils/idleLogout'

// Keep the initial bundle small. Admin tools, the QR scanner, and form builder
// are only downloaded when the user navigates to them. Each import() must use a
// literal path: Vite cannot bundle a path passed in through a variable, which
// left the production build without any pages.
const HomeView = () => import('../views/public/HomeView.vue')
const RegistrationView = () => import('../views/public/RegistrationView.vue')
const EventView = () => import('../views/public/EventView.vue')
const RegistrationSuccessView = () => import('../views/user/RegistrationSuccessView.vue')
const MyInformationView = () => import('../views/user/MyInformationView.vue')
const EditRegistrationView = () => import('../views/user/EditRegistrationView.vue')
const CheckInView = () => import('../views/user/CheckInView.vue')
const DashboardView = () => import('../views/admin/DashboardView.vue')
const UsersView = () => import('../views/admin/UsersView.vue')
const CheckInsView = () => import('../views/admin/CheckInsView.vue')
const AuthView = () => import('../views/public/AuthView.vue')
const EventSetupView = () => import('../views/admin/EventSetupView.vue')
const FormBuilderView = () => import('../views/admin/FormBuilderView.vue')
const EventsView = () => import('../views/admin/EventsView.vue')
const EventDetailsView = () => import('../views/admin/EventDetailsView.vue')
const AdminProfileView = () => import('../views/admin/AdminProfileView.vue')
const PersonnelProfileView = () => import('../views/admin/PersonnelProfileView.vue')
const ReportsView = () => import('../views/admin/ReportsView.vue')
const AdminAccountsView = () => import('../views/admin/AdminAccountsView.vue')
const SystemSettingsView = () => import('../views/admin/SystemSettingsView.vue')

// `events` marks the event workspace: Master Admins and Event Admins only (see the sidebar).
const staff = { auth: true, staff: true }
const eventStaff = { ...staff, events: true }
const EVENT_DRAFT_RESUME_KEY = 'event_setup_resume'

const router = createRouter({
  history: createWebHistory(),
  // The hash is how the event list links to a section of the event page.
  scrollBehavior: (to, from, saved) => saved || (to.hash ? { el: to.hash } : to.path !== from.path ? { top: 0 } : undefined),
  routes: [
    { path: '/', component: HomeView },
    { path: '/login', component: AuthView },
    { path: '/account/register', component: AuthView },
    { path: '/events', redirect: '/me' },
    { path: '/register', redirect: '/me' },
    { path: '/events/:eventId', component: EventView },
    { path: '/events/:eventId/register', component: RegistrationView, meta: { auth: true, signupFirst: true } },
    { path: '/registration/success', component: RegistrationSuccessView, meta: { auth: true } },
    { path: '/registration/:id/edit', component: EditRegistrationView, meta: { auth: true } },
    { path: '/check-in', component: CheckInView, meta: { auth: true } },
    { path: '/me', component: MyInformationView, meta: { auth: true } },
    { path: '/profile', component: AdminProfileView, meta: { auth: true } },
    { path: '/admin', component: DashboardView, meta: staff },
    { path: '/admin/events', component: EventsView, meta: eventStaff },
    { path: '/admin/events/new', component: EventSetupView, meta: { ...eventStaff, setupPage: true } },
    { path: '/admin/events/:id/edit', component: EventSetupView, meta: { ...eventStaff, setupPage: true } },
    { path: '/admin/events/:id/registrants', redirect: (to) => ({ path: '/admin/users', query: { ...to.query, event: to.params.id } }), meta: eventStaff },
    { path: '/admin/events/:id/form-builder', component: FormBuilderView, meta: eventStaff },
    { path: '/admin/events/:id', component: EventDetailsView, meta: eventStaff },
    { path: '/admin/users', component: UsersView, meta: eventStaff },
    { path: '/admin/check-ins', component: CheckInsView, meta: eventStaff },
    { path: '/admin/reports', component: ReportsView, meta: eventStaff },
    { path: '/admin/personnel-profile', component: PersonnelProfileView, meta: staff },
    { path: '/admin/admin-accounts', component: AdminAccountsView, meta: { ...staff, adminOnly: true } },
    { path: '/admin/settings', component: SystemSettingsView, meta: { ...staff, adminOnly: true } },
    { path: '/admin/profile', component: AdminProfileView, meta: staff },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

const homeAfterSignIn = (auth, requested) => (auth.canAccessAdmin
  ? (requested.startsWith('/admin') ? requested : '/admin')
  : (requested && !requested.startsWith('/admin') ? requested : '/me'))

// After an idle sign-out, the sign-in page explains why the person was signed out.
const signIn = (path, to) => ({ path, query: { redirect: to.fullPath, ...(takeIdleSignOut() ? { idle: '1' } : {}) } })

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.staff && !auth.isAuthenticated) return signIn('/login', to)
  if (to.meta.staff && !auth.canAccessAdmin) return '/'
  if (to.meta.adminOnly && !auth.isAdmin) return '/admin'
  if (to.meta.events && !auth.canManageEvents) return '/admin'
  if (to.meta.auth && !auth.isAuthenticated) return signIn(to.meta.signupFirst ? '/account/register' : '/login', to)
  if (to.path === '/admin/events/new' && !to.query.fresh) {
    try {
      const savedDraft = JSON.parse(localStorage.getItem(EVENT_DRAFT_RESUME_KEY) || 'null')
      if (savedDraft?.id && String(savedDraft.userId) === String(auth.user?.id)) {
        return { path: `/admin/events/${savedDraft.id}/edit`, query: { step: savedDraft.step || 1, creating: '1' } }
      }
      if (savedDraft) localStorage.removeItem(EVENT_DRAFT_RESUME_KEY)
    } catch { /* ignore unavailable or invalid local storage */ }
  }
  if ((to.path === '/login' || to.path === '/account/register') && auth.isAuthenticated && to.query.logout !== '1') {
    return homeAfterSignIn(auth, typeof to.query.redirect === 'string' ? to.query.redirect : '')
  }
  // Staff accounts only use the admin area: public and attendee pages send them to the dashboard.
  if (auth.isAuthenticated && auth.canAccessAdmin && !to.path.startsWith('/admin')) return '/admin'
})

// "Create event" and "Edit event" share EventSetupView, so Vue would reuse the open page when moving between them
// (e.g. "Create event" from an edit or published screen) and keep showing the old event. Each visit gets its own
// page instance instead (App.vue keys the page by this counter). The page's own URL updates go through
// replaceSetupUrl() so the form isn't rebuilt while it is being used.
export const setupPageVisit = ref(0)
let keepSetupPage = false
export const replaceSetupUrl = async (location) => {
  keepSetupPage = true
  try { return await router.replace(location) } finally { keepSetupPage = false }
}
router.afterEach((to, from, failure) => {
  if (!failure && to.meta.setupPage && !keepSetupPage) setupPageVisit.value++
})

export default router
