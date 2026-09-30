<script setup>
import AdminTopbar from '../components/AdminTopbar.vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import adminLogo from '../assets/Adminlogo.png'

const router = useRouter()
const auth = useAuthStore()
const logout = async () => { await auth.logout(); router.push('/login') }
</script>

<template>
  <div class="admin-app-shell">
    <aside class="admin-sidebar">
      <RouterLink to="/admin" class="admin-brand"><img class="admin-brand-logo" :src="adminLogo" alt="" /><span>LLC-Event</span></RouterLink>
      <nav class="admin-nav" aria-label="Admin navigation">
        <RouterLink to="/admin" exact-active-class="is-active"><span>▦</span> Dashboard</RouterLink>
        <RouterLink to="/admin/events" active-class="is-active"><span>▣</span> Events</RouterLink>
        <RouterLink to="/admin/users" active-class="is-active"><span>♙</span> Attendances</RouterLink>
        <RouterLink to="/admin/check-ins" active-class="is-active"><span>⌗</span> Check-in</RouterLink>
        <RouterLink to="/admin/reports" class="reports-link" active-class="is-active"><span>▥</span> Reports</RouterLink>
        <RouterLink to="/admin/profile" class="settings-link" active-class="is-active"><span>⚙</span> Settings</RouterLink>
      </nav>
      <div class="admin-event-card"><div class="admin-event-thumb">CT</div><div><strong>Community Tech Summit</strong><small>Sep 18, 2026 · Bangkok</small></div><b>Event active</b></div>
      <div class="admin-user-card"><RouterLink to="/admin/profile" class="admin-user-link"><span class="admin-avatar">{{ (auth.user?.name || 'AD').slice(0, 2).toUpperCase() }}</span><span><strong>{{ auth.user?.name || 'Admin' }}</strong><small>Administrator</small></span></RouterLink><button type="button" aria-label="Log out" @click="logout">↗</button></div>
    </aside>
    <div class="admin-main-shell">
      <AdminTopbar />
      <main><slot /></main>
    </div>
  </div>
</template>
