import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import api from './services/api'
import { useAuthStore } from './stores/auth'
import { startIdleLogout } from './utils/idleLogout'
import './assets/styles/main.css'
import './assets/styles/ui-overrides.css'
import './assets/styles/admin-topbar.css'
import './assets/styles/admin-dashboard.css'
import './assets/styles/event-workspace.css'
import './assets/styles/form-builder.css'
import './assets/styles/form-renderer.css'
import './assets/styles/event-setup-overrides.css'
import './assets/styles/attendee-registration.css'
import './assets/styles/my-information.css'
import './assets/styles/home-hero.css'
import './assets/styles/event-page.css'
import './assets/styles/auth-refresh.css'
import './assets/styles/ui-refresh.css'
import './assets/styles/responsive.css'
import './assets/styles/events-page.css'
import './assets/styles/event-setup.css'
import './assets/styles/event-details.css'
import './assets/styles/registration.css'
import './assets/styles/event-public.css'
import './assets/styles/app-polish.css'
import './assets/styles/attendance.css'
import './assets/styles/checkin-desk.css'
import './assets/styles/reports.css'
import './assets/styles/sidebar.css'
import './assets/styles/theme-dark.css'

const app = createApp(App).use(createPinia()).use(router)

// An expired or revoked token makes every request fail with 401. Drop the stale
// session once and send the user to sign in, then come back to where they were.
const auth = useAuthStore()
// Runs before the first page's route guards, so a session left signed in too long never opens a protected page.
startIdleLogout(auth, router)
api.interceptors.response.use(undefined, (error) => {
  if (error.response?.status === 401 && auth.token) {
    auth.clearSession()
    const current = router.currentRoute.value
    if (current.meta.auth || current.meta.staff) router.replace({ path: '/login', query: { redirect: current.fullPath, expired: '1' } })
  }
  return Promise.reject(error)
})

app.mount('#app')
