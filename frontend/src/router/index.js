import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

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
const StaffSetupPasswordView = () => import('../views/public/StaffSetupPasswordView.vue')
const EventSetupView = () => import('../views/admin/EventSetupView.vue')
const FormBuilderView = () => import('../views/admin/FormBuilderView.vue')
const EventsView = () => import('../views/admin/EventsView.vue')
const EventDetailsView = () => import('../views/admin/EventDetailsView.vue')
const EventRegistrantsView = () => import('../views/admin/EventRegistrantsView.vue')
const AdminProfileView = () => import('../views/admin/AdminProfileView.vue')
const PersonnelProfileView = () => import('../views/admin/PersonnelProfileView.vue')
const ReportsView = () => import('../views/admin/ReportsView.vue')
const AdminAccountsView = () => import('../views/admin/AdminAccountsView.vue')
const SystemSettingsView = () => import('../views/admin/SystemSettingsView.vue')

// Event routes are guarded separately for viewing, participant data, and changes.
const staff = { auth: true, staff: true }
const eventWorkspace = { ...staff, eventWorkspace: true }

const router = createRouter({
  history: createWebHistory(),
  // The hash is how the event list links to a section of the event page.
  scrollBehavior: (to, from, saved) => saved || (to.hash ? { el: to.hash } : to.path !== from.path ? { top: 0 } : undefined),
  routes: [
    { path: '/', component: HomeView },
    { path: '/login', component: AuthView },
    { path: '/account/register', component: AuthView },
    { path: '/staff/setup-password', component: StaffSetupPasswordView },
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
    { path: '/admin/events', component: EventsView, meta: eventWorkspace },
    { path: '/admin/events/new', component: EventSetupView, meta: { ...eventWorkspace, eventCreate: true } },
    { path: '/admin/events/:id/edit', component: EventSetupView, meta: { ...eventWorkspace, eventManage: true } },
    { path: '/admin/events/:id/registrants', component: EventRegistrantsView, meta: { ...eventWorkspace, participants: true } },
    { path: '/admin/events/:id/form-builder', component: FormBuilderView, meta: { ...eventWorkspace, eventManage: true } },
    { path: '/admin/events/:id', component: EventDetailsView, meta: eventWorkspace },
    { path: '/admin/users', component: UsersView, meta: { ...eventWorkspace, participants: true } },
    { path: '/admin/check-ins', component: CheckInsView, meta: { ...eventWorkspace, checkIn: true } },
    { path: '/admin/reports', component: ReportsView, meta: eventWorkspace },
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

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.staff && !auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }
  if (to.meta.staff && !auth.canAccessAdmin) return '/'
  if (to.meta.adminOnly && !auth.isAdmin) return '/admin'
  if (to.meta.eventWorkspace && !auth.canAccessEventWorkspace) return '/admin'
  if (to.meta.eventCreate && !auth.canCreateEvents) return '/admin'
  if (to.meta.eventManage && !auth.canManageEvents) return '/admin'
  if (to.meta.participants && !auth.canViewParticipants) return '/admin'
  if (to.meta.checkIn && !auth.canPerformCheckIn) return '/admin'
  if (to.meta.auth && !auth.isAuthenticated) return { path: to.meta.signupFirst ? '/account/register' : '/login', query: { redirect: to.fullPath } }
  if ((to.path === '/login' || to.path === '/account/register') && auth.isAuthenticated) {
    return homeAfterSignIn(auth, typeof to.query.redirect === 'string' ? to.query.redirect : '')
  }
})

export default router
