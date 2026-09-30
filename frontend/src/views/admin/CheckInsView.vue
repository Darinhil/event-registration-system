<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRScanner from '../../components/QRScanner.vue'
import { useCheckInStore } from '../../stores/checkIn'
import { getCheckIns } from '../../services/adminService'

const scanning = ref(false)
const scanMessage = ref('')
const scannerError = ref('')
const code = ref('')
const credential = ref('')
const store = useCheckInStore()
const checkIns = ref([])
const logLoading = ref(false)

const loadCheckIns = async () => {
  logLoading.value = true
  try {
    checkIns.value = (await getCheckIns()).data.data || []
  } finally {
    logLoading.value = false
  }
}

const exportLog = () => {
  const header = ['Attendee', 'Registration code', 'Email', 'Checked in at', 'Checked in by']
  const rows = checkIns.value.map((item) => [
    item.registration?.full_name || '',
    item.registration?.registration_code || '',
    item.registration?.email || '',
    item.checked_in_at || '',
    item.staff?.name || item.staff?.email || '',
  ])
  const csv = [header, ...rows].map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `check-in-log-${new Date().toISOString().slice(0, 10)}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

const lookupCredential = async (value) => {
  const credentialText = String(value || '').trim()
  if (!credentialText || store.lookupLoading || store.loading) return

  try {
    credential.value = credentialText
    await store.lookup(credentialText)
    scanning.value = false
  } catch {
    scanMessage.value = ''
  }
}

const confirmCheckIn = async () => {
  if (!credential.value || store.loading) return

  try {
    await store.submit(credential.value)
    scanMessage.value = store.result.message
    code.value = ''
    credential.value = ''
    store.attendee = null
  } catch {}
}

const handleScan = (value) => lookupCredential(value)
const submitCode = () => lookupCredential(code.value)
onMounted(loadCheckIns)
</script>

<template>
  <AdminLayout>
    <section class="checkin-workspace">
      <header class="checkin-heading">
        <div>
          <p class="admin-eyebrow">Live desk · today</p>
          <h1>Check-in participants</h1>
          <p>Scan a participant QR code or enter their attendee code.</p>
        </div>
        <span class="live-indicator"><i></i> Live check-in desk</span>
      </header>

      <div class="checkin-layout">
        <article class="scanner-panel">
          <div class="scanner-panel-heading">
            <div>
              <h2>Check in attendee</h2>
              <p>Scan first to verify the attendee, then confirm check-in.</p>
            </div>
            <button type="button" class="secondary-button" @click="scanning = !scanning">
              {{ scanning ? 'Stop scanner' : 'Start scanner' }}
            </button>
          </div>

          <div class="checkin-code-form">
            <label for="attendee-code">Attendee code</label>
            <div>
              <input id="attendee-code" v-model="code" placeholder="e.g. COMMUNITY-001" autocomplete="off" @keyup.enter="submitCode" />
              <button type="button" class="primary-button" :disabled="store.lookupLoading || store.loading || !code.trim()" @click="submitCode">
                {{ store.lookupLoading ? 'Finding...' : 'Find attendee' }}
              </button>
            </div>
          </div>

          <div v-if="scanning" class="scanner-shell">
            <QRScanner @scan="handleScan" @error="scannerError = $event" />
          </div>
          <div v-else-if="!store.attendee" class="scanner-placeholder">
            <div class="scanner-frame">⌗</div>
            <strong>Scanner is paused</strong>
            <p>Start the scanner when you are ready to scan a QR pass.</p>
            <button type="button" class="primary-button" @click="scanning = true">Start scanner</button>
          </div>

          <div v-if="scannerError" class="error">{{ scannerError }}</div>

          <section v-if="store.attendee" class="attendee-preview" aria-live="polite">
            <div class="attendee-preview-heading">
              <div>
                <p class="admin-eyebrow">Attendee found</p>
                <h3>{{ store.attendee.full_name || 'Unnamed attendee' }}</h3>
              </div>
              <span :class="['attendee-status', store.attendee.checked_in_at ? 'is-checked-in' : 'is-ready']">
                {{ store.attendee.checked_in_at ? 'Already checked in' : 'Ready to check in' }}
              </span>
            </div>

            <div class="attendee-preview-details">
              <div><small>Registration code</small><strong>{{ store.attendee.registration_code }}</strong></div>
              <div><small>Email</small><strong>{{ store.attendee.email || 'Not provided' }}</strong></div>
              <div><small>Event</small><strong>{{ store.attendee.event_name || 'Event registration' }}</strong></div>
              <div><small>Phone</small><strong>{{ store.attendee.phone || 'Not provided' }}</strong></div>
            </div>

            <div class="attendee-preview-actions">
              <button type="button" class="secondary-button" @click="store.attendee = null; credential = ''">Cancel</button>
              <button type="button" class="primary-button" :disabled="store.loading || Boolean(store.attendee.checked_in_at)" @click="confirmCheckIn">
                {{ store.loading ? 'Checking in...' : store.attendee.checked_in_at ? 'Already checked in' : 'Confirm check-in' }}
              </button>
            </div>
          </section>

          <div v-if="scanMessage" class="scan-success">✓ {{ scanMessage }}</div>
          <div v-if="store.error" class="error">{{ store.error }}</div>
        </article>

        <aside class="checkin-summary">
          <p class="admin-eyebrow">Event summary</p>
          <h2>Community Tech Summit</h2>
          <div class="checkin-progress"><div><span>Checked in</span><strong>0 / 0</strong></div><div class="progress-bar"><span></span></div></div>
          <div class="checkin-stats"><div><small>Expected</small><strong>0</strong></div><div><small>Checked in</small><strong>0</strong></div><div><small>Remaining</small><strong>0</strong></div></div>
          <RouterLink to="/admin/events" class="text-link">Change event →</RouterLink>
        </aside>
      </div>

      <section class="checkin-table-panel">
        <header><div><p class="admin-eyebrow">Attendance log</p><h2>Recent check-ins</h2></div><button type="button" class="export-log-button" :disabled="logLoading || !checkIns.length" @click="exportLog">{{ logLoading ? 'Loading...' : 'Export log' }}</button></header>
        <div v-if="!checkIns.length" class="recent-empty"><span>✓</span><div><strong>No check-ins yet</strong><p>Scanned participants will appear here with their check-in time.</p></div></div>
        <div v-else class="checkin-log-list"><div v-for="item in checkIns" :key="item.id" class="checkin-log-row"><div><strong>{{ item.registration?.full_name || 'Unnamed attendee' }}</strong><small>{{ item.registration?.registration_code }}</small></div><time>{{ item.checked_in_at ? new Date(item.checked_in_at).toLocaleString() : 'Just now' }}</time></div></div>
      </section>
    </section>
  </AdminLayout>
</template>
