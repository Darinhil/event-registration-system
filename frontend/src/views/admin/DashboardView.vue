<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { getCheckIns, getDashboard, getUsers } from '../../services/adminService'

const loading = ref(true)
const error = ref('')
const stats = ref({ users: 0, registrations: 0, check_ins: 0 })
const attendees = ref([])
const checkedInUserIds = ref(new Set())
const checkInRate = computed(() => stats.value.registrations ? Math.round((stats.value.check_ins / stats.value.registrations) * 1000) / 10 : 0)
const notCheckedIn = computed(() => Math.max(stats.value.registrations - stats.value.check_ins, 0))

const loadDashboard = async () => {
  loading.value = true; error.value = ''
  try {
    const [dashboardResponse, usersResponse, checkInsResponse] = await Promise.all([getDashboard(), getUsers(), getCheckIns()])
    stats.value = dashboardResponse.data
    attendees.value = usersResponse.data.data || []
    checkedInUserIds.value = new Set((checkInsResponse.data.data || []).map((item) => item.registration?.user?.id).filter(Boolean))
  } catch { error.value = 'Unable to load dashboard data.' } finally { loading.value = false }
}
onMounted(loadDashboard)
</script>

<template>
  <AdminLayout>
    <section class="event-dashboard">
      <header class="event-dashboard-heading"><div><h1>Event Dashboard</h1></div></header>
      <p v-if="error" class="dashboard-error" role="alert">{{ error }} <button type="button" @click="loadDashboard">Retry</button></p>

      <div class="metric-grid">
        <article class="metric-card metric-blue"><span class="metric-icon">♙</span><div><small>Total Registered Users</small><strong>{{ stats.registrations.toLocaleString() }}</strong><em>↑ 12% <b>vs. last event</b></em></div></article>
        <article class="metric-card metric-green"><span class="metric-icon">✓</span><div><small>Checked In</small><strong>{{ stats.check_ins.toLocaleString() }}</strong><em>↑ 18% <b>vs. last event</b></em></div></article>
        <article class="metric-card metric-orange"><span class="metric-icon">◷</span><div><small>Not Checked In</small><strong>{{ notCheckedIn.toLocaleString() }}</strong><em>↓ 6% <b>vs. last event</b></em></div></article>
        <article class="metric-card metric-purple"><span class="metric-icon">⌗</span><div><small>Check-in Rate</small><strong>{{ checkInRate }}%</strong><em>↑ 10% <b>vs. last event</b></em></div></article>
      </div>

      <div class="dashboard-panels">
        <article class="dashboard-panel overview-panel"><header><h2>Registration &amp; Check-in Overview</h2><div><span class="legend-blue"></span>Registered <span class="legend-green"></span>Checked In</div></header><div class="chart-area"><div class="chart-y"><span>200</span><span>150</span><span>100</span><span>50</span><span>0</span></div><svg viewBox="0 0 600 190" role="img" aria-label="Registration and check-in trend"><path d="M12 165H590M12 120H590M12 75H590M12 30H590" stroke="#e7eef5"/><path d="M12 143L127 117L242 92L357 64L472 46L590 16V165H12Z" fill="#e5f1ff"/><path d="M12 143L127 117L242 92L357 64L472 46L590 16" stroke="#277cf2" stroke-width="3" fill="none"/><path d="M12 155L127 139L242 123L357 103L472 86L590 61" stroke="#15b77a" stroke-width="3" fill="none"/><g fill="#277cf2"><circle cx="12" cy="143" r="4"/><circle cx="127" cy="117" r="4"/><circle cx="242" cy="92" r="4"/><circle cx="357" cy="64" r="4"/><circle cx="472" cy="46" r="4"/><circle cx="590" cy="16" r="4"/></g><g fill="#15b77a"><circle cx="12" cy="155" r="4"/><circle cx="127" cy="139" r="4"/><circle cx="242" cy="123" r="4"/><circle cx="357" cy="103" r="4"/><circle cx="472" cy="86" r="4"/><circle cx="590" cy="61" r="4"/></g></svg><div class="chart-x"><span>Sep 13</span><span>Sep 14</span><span>Sep 15</span><span>Sep 16</span><span>Sep 17</span><span>Sep 18</span></div></div></article>
        <article class="dashboard-panel status-panel"><header><h2>Registration Status</h2></header><div class="donut-wrap"><div class="donut" :style="{ '--rate': `${checkInRate}%` }"><div><strong>{{ stats.registrations }}</strong><span>Total</span></div></div><div class="donut-legend"><p><span class="legend-green-dot"></span><b>Checked In</b><strong>{{ stats.check_ins }} ({{ checkInRate }}%)</strong></p><p><span class="legend-orange-dot"></span><b>Not Checked In</b><strong>{{ notCheckedIn }} ({{ Math.max(100 - checkInRate, 0) }}%)</strong></p></div></div></article>
        <article class="dashboard-panel activity-panel"><header><h2>Recent Activity</h2><RouterLink to="/admin/users">View all</RouterLink></header><div v-if="loading" class="activity-empty">Loading…</div><div v-else-if="attendees.length" class="activity-list"><div v-for="attendee in attendees.slice(0, 5)" :key="attendee.id"><span :class="checkedInUserIds.has(attendee.id) ? 'activity-check' : 'activity-user'">{{ checkedInUserIds.has(attendee.id) ? '✓' : '♙' }}</span><p><strong>{{ attendee.name }}</strong><small>{{ checkedInUserIds.has(attendee.id) ? 'Checked in' : 'Registered' }} recently</small></p></div></div><div v-else class="activity-empty">No recent activity.</div></article>
      </div>

      <article class="attendee-panel"><header><h2>Registered Attendees</h2><RouterLink to="/admin/users">View all →</RouterLink></header><div class="attendee-toolbar"><label class="table-search">⌕<input placeholder="Search by name, phone, or organization..." /></label><button type="button">All Status⌄</button><button type="button">Check-in Status⌄</button><button type="button">Export ↓</button></div><div class="attendee-table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Registration</th><th>Check-in</th><th>Actions</th></tr></thead><tbody><tr v-if="loading"><td colspan="5">Loading attendees…</td></tr><tr v-else-if="!attendees.length"><td colspan="5">No attendees registered yet.</td></tr><tr v-for="attendee in attendees.slice(0, 6)" v-else :key="attendee.id"><td><strong>{{ attendee.name }}</strong></td><td>{{ attendee.email }}</td><td><span class="status-pill registered">Registered</span></td><td><span class="status-pill" :class="checkedInUserIds.has(attendee.id) ? 'checked' : 'pending'">{{ checkedInUserIds.has(attendee.id) ? 'Checked In' : 'Not Checked In' }}</span></td><td><RouterLink :to="`/admin/users/${attendee.id}`">View</RouterLink></td></tr></tbody></table></div></article>
    </section>
  </AdminLayout>
</template>
