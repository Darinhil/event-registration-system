import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Keep the initial bundle small. Admin tools, the QR scanner, and form builder
// are only downloaded when the user navigates to them.
const HomeView = () => import('../views/public/HomeView.vue')
const RegistrationView = () => import('../views/public/RegistrationView.vue')
const EventView = () => import('../views/public/EventView.vue')
const RegistrationSuccessView = () => import('../views/user/RegistrationSuccessView.vue')
const MyInformationView = () => import('../views/user/MyInformationView.vue')
const EditRegistrationView = () => import('../views/user/EditRegistrationView.vue')
const DashboardView = () => import('../views/admin/DashboardView.vue')
const UsersView = () => import('../views/admin/UsersView.vue')
const UserDetailView = () => import('../views/admin/UserDetailView.vue')
const CheckInsView = () => import('../views/admin/CheckInsView.vue')
const AuthView = () => import('../views/public/AuthView.vue')
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
const CheckInView = () => import('../views/user/CheckInView.vue')

const router = createRouter({ history: createWebHistory(), routes: [
  { path: '/', component: HomeView }, { path: '/login', component: AuthView }, { path: '/account/register', component: AuthView }, { path: '/events', redirect: '/me', meta: { auth: true } },
  { path: '/register', redirect: '/me' }, { path: '/events/:eventId', component: EventView }, { path: '/events/:eventId/register', component: RegistrationView, meta: { auth: true } },
  { path: '/registration/success', component: RegistrationSuccessView, meta: { auth: true } }, { path: '/me', component: MyInformationView, meta: { auth: true } }, { path: '/registration/:id/edit', component: EditRegistrationView, meta: { auth: true } },
  { path: '/check-in', component: CheckInView, meta: { auth: true } },
  { path: '/profile', component: AdminProfileView, meta: { auth: true } },
  { path: '/admin', component: DashboardView, meta: { auth: true, staff: true, permission: 'events.view' } }, { path: '/admin/events', component: EventsView, meta: { auth: true, staff: true, permission: 'events.view' } }, { path: '/admin/events/new', component: EventSetupView, meta: { auth: true, staff: true, permission: 'events.manage' } }, { path: '/admin/events/:id/edit', component: EventSetupView, meta: { auth: true, staff: true, permission: 'events.manage' } },  { path: '/admin/events/:id/registrants', component: EventRegistrantsView, meta: { auth: true, staff: true, permission: 'events.view' } }, { path: '/admin/events/:id/form-builder', component: FormBuilderView, meta: { auth: true, staff: true, permission: 'forms.create' } }, { path: '/admin/events/:id', component: EventDetailsView, meta: { auth: true, staff: true, permission: 'events.view' } },
  { path: '/admin/users', component: UsersView, meta: { auth: true, staff: true, permission: 'users.manage' } }, { path: '/admin/users/:id', component: UserDetailView, meta: { auth: true, staff: true, permission: 'users.manage' } },
  { path: '/admin/check-ins', component: CheckInsView, meta: { auth: true, staff: true, permission: 'checkins.manage' } },
  { path: '/admin/personnel-profile', component: PersonnelProfileView, meta: { auth: true, staff: true } },
  { path: '/admin/reports', component: ReportsView, meta: { auth: true, staff: true, permission: 'reports.view' } },
  { path: '/admin/admin-accounts', component: AdminAccountsView, meta: { auth: true, staff: true, userManager: true } },
  { path: '/admin/settings', component: SystemSettingsView, meta: { auth: true, staff: true, settingsAccess: true } },
  { path: '/admin/profile', component: AdminProfileView, meta: { auth: true, staff: true } },
] })

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.staff && !auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }
  if (to.meta.staff && !auth.canAccessAdmin) return '/'
  if (to.meta.userManager && !auth.canManageUsers) return '/admin'
  if (to.meta.settingsAccess && !auth.canAccessSettings) return '/admin'
  if (to.meta.permission && !auth.hasPermission(to.meta.permission)) return auth.canManageUsers ? '/admin/admin-accounts' : '/'
  if (to.meta.adminOnly && !auth.isAdmin) return '/admin'
  if (to.meta.auth && !auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }
  if (to.path === '/login' && auth.isAuthenticated) {
    const requestedPath = typeof to.query.redirect === 'string' ? to.query.redirect : ''
    return auth.canAccessAdmin
      ? (requestedPath.startsWith('/admin') ? requestedPath : '/admin')
      : (requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/me')
  }
})

  export default router
