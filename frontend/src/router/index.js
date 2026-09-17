import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/public/HomeView.vue'
import RegistrationView from '../views/public/RegistrationView.vue'
import RegistrationSuccessView from '../views/user/RegistrationSuccessView.vue'
import MyInformationView from '../views/user/MyInformationView.vue'
import CheckInView from '../views/user/CheckInView.vue'
import DashboardView from '../views/admin/DashboardView.vue'
import UsersView from '../views/admin/UsersView.vue'
import UserDetailView from '../views/admin/UserDetailView.vue'
import CheckInsView from '../views/admin/CheckInsView.vue'

export default createRouter({ history: createWebHistory(), routes: [
  { path: '/', component: HomeView }, { path: '/register', component: RegistrationView },
  { path: '/registration/success', component: RegistrationSuccessView }, { path: '/me', component: MyInformationView },
  { path: '/check-in', component: CheckInView }, { path: '/admin', component: DashboardView },
  { path: '/admin/users', component: UsersView }, { path: '/admin/users/:id', component: UserDetailView },
  { path: '/admin/check-ins', component: CheckInsView },
] })