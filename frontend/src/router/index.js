import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Keep the initial bundle small. Admin tools, the QR scanner, and form builder
// are only downloaded when the user navigates to them.
const lazy = (path) => () => import(path)
const HomeView = lazy('../views/public/HomeView.vue')
const RegistrationView = lazy('../views/public/RegistrationView.vue')
const EventView = lazy('../views/public/EventView.vue')
const UserEventsView = lazy('../views/public/EventsView.vue')
const RegistrationSuccessView = lazy('../views/user/RegistrationSuccessView.vue')
const MyInformationView = lazy('../views/user/MyInformationView.vue')
const EditRegistrationView = lazy('../views/user/EditRegistrationView.vue')
const CheckInView = lazy('../views/user/CheckInView.vue')
const DashboardView = lazy('../views/admin/DashboardView.vue')
const UsersView = lazy('../views/admin/UsersView.vue')
const UserDetailView = lazy('../views/admin/UserDetailView.vue')
const CheckInsView = lazy('../views/admin/CheckInsView.vue')
const AuthView = lazy('../views/public/AuthView.vue')
const EventSetupView = lazy('../views/admin/EventSetupView.vue')
const FormBuilderView = lazy('../views/admin/FormBuilderView.vue')
const EventsView = lazy('../views/admin/EventsView.vue')
const EventDetailsView = lazy('../views/admin/EventDetailsView.vue')
const EventRegistrantsView = lazy('../views/admin/EventRegistrantsView.vue')
const AdminProfileView = lazy('../views/admin/AdminProfileView.vue')
const ReportsView = lazy('../views/admin/ReportsView.vue')
const AdminAccountsView = lazy('../views/admin/AdminAccountsView.vue')
const SystemSettingsView = lazy('../views/admin/SystemSettingsView.vue')

const router = createRouter({ history: createWebHistory(), routes: [
  { path: '/', component: HomeView }, { path: '/login', component: AuthView }, { path: '/account/register', component: AuthView }, { path: '/events', component: UserEventsView, meta: { auth: true } },
  { path: '/register', component: RegistrationView, meta: { auth: true } }, { path: '/events/:eventId', component: EventView }, { path: '/events/:eventId/register', component: RegistrationView, meta: { auth: true } },
  { path: '/registration/success', component: RegistrationSuccessView, meta: { auth: true } }, { path: '/me', component: MyInformationView, meta: { auth: true } }, { path: '/registration/:id/edit', component: EditRegistrationView, meta: { auth: true } },
  { path: '/profile', component: AdminProfileView, meta: { auth: true } },
  { path: '/check-in', component: CheckInView, meta: { auth: true } }, { path: '/admin', component: DashboardView, meta: { auth: true, staff: true } }, { path: '/admin/events', component: EventsView, meta: { auth: true, staff: true } }, { path: '/admin/events/new', component: EventSetupView, meta: { auth: true, staff: true } }, { path: '/admin/events/:id/edit', component: EventSetupView, meta: { auth: true, staff: true } },  { path: '/admin/events/:id/registrants', component: EventRegistrantsView, meta: { auth: true, staff: true } }, { path: '/admin/events/:id/form-builder', component: FormBuilderView, meta: { auth: true, staff: true } }, { path: '/admin/events/:id', component: EventDetailsView, meta: { auth: true, staff: true } },
  { path: '/admin/users', component: UsersView, meta: { auth: true, staff: true } }, { path: '/admin/users/:id', component: UserDetailView, meta: { auth: true, staff: true } },
  { path: '/admin/check-ins', component: CheckInsView, meta: { auth: true, staff: true } },
  { path: '/admin/reports', component: ReportsView, meta: { auth: true, staff: true } },
  { path: '/admin/admin-accounts', component: AdminAccountsView, meta: { auth: true, staff: true, adminOnly: true } },
  { path: '/admin/settings', component: SystemSettingsView, meta: { auth: true, staff: true, adminOnly: true } },
  { path: '/admin/profile', component: AdminProfileView, meta: { auth: true, staff: true } },
] })

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.staff && !auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }
  if (to.meta.staff && !auth.canAccessAdmin) return '/'
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
