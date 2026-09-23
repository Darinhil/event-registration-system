import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/public/HomeView.vue'
import RegistrationView from '../views/public/RegistrationView.vue'
import RegistrationSuccessView from '../views/user/RegistrationSuccessView.vue'
import MyInformationView from '../views/user/MyInformationView.vue'
import EditRegistrationView from '../views/user/EditRegistrationView.vue'
import CheckInView from '../views/user/CheckInView.vue'
import DashboardView from '../views/admin/DashboardView.vue'
import UsersView from '../views/admin/UsersView.vue'
import UserDetailView from '../views/admin/UserDetailView.vue'
import CheckInsView from '../views/admin/CheckInsView.vue'
import AuthView from '../views/public/AuthView.vue'
import { useAuthStore } from '../stores/auth'
import EventSetupView from '../views/admin/EventSetupView.vue'
import FormBuilderView from '../views/admin/FormBuilderView.vue'
import EventsView from '../views/admin/EventsView.vue'
import EventDetailsView from '../views/admin/EventDetailsView.vue'
import EventRegistrantsView from '../views/admin/EventRegistrantsView.vue'
import AdminProfileView from '../views/admin/AdminProfileView.vue'

const router = createRouter({ history: createWebHistory(), routes: [
  { path: '/', component: HomeView }, { path: '/login', component: AuthView }, { path: '/account/register', component: AuthView },
  { path: '/register', component: RegistrationView, meta: { auth: true } }, { path: '/events/:eventId/register', component: RegistrationView, meta: { auth: true } },
  { path: '/registration/success', component: RegistrationSuccessView, meta: { auth: true } }, { path: '/me', component: MyInformationView, meta: { auth: true } }, { path: '/registration/:id/edit', component: EditRegistrationView, meta: { auth: true } },
  { path: '/check-in', component: CheckInView, meta: { auth: true } }, { path: '/admin', component: DashboardView, meta: { auth: true, admin: true } }, { path: '/admin/events', component: EventsView, meta: { auth: true, admin: true } }, { path: '/admin/events/new', component: EventSetupView, meta: { auth: true, admin: true } }, { path: '/admin/events/:id/edit', component: EventSetupView, meta: { auth: true, admin: true } },  { path: '/admin/events/:id/registrants', component: EventRegistrantsView, meta: { auth: true, admin: true } }, { path: '/admin/events/:id/form-builder', component: FormBuilderView, meta: { auth: true, admin: true } }, { path: '/admin/events/:id', component: EventDetailsView, meta: { auth: true, admin: true } },
  { path: '/admin/users', component: UsersView, meta: { auth: true, admin: true } }, { path: '/admin/users/:id', component: UserDetailView, meta: { auth: true, admin: true } },
  { path: '/admin/check-ins', component: CheckInsView, meta: { auth: true, admin: true } },
  { path: '/admin/profile', component: AdminProfileView, meta: { auth: true, admin: true } },
] })

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.admin && !auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }
  if (to.meta.admin && auth.user?.role !== 'admin') return '/'
  if (to.meta.auth && !auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }
  if (to.path === '/login' && auth.isAuthenticated) {
    const requestedPath = typeof to.query.redirect === 'string' ? to.query.redirect : ''
    return auth.user?.role === 'admin'
      ? (requestedPath.startsWith('/admin') ? requestedPath : '/admin')
      : (requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/me')
  }
})

  export default router
