<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRScanner from '../../components/QRScanner.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import { useCheckInStore } from '../../stores/checkIn'
import { getCheckIns, getAdminEvents, getEvent, getEventCheckInSummary, getEventRegistrants, regenerateEventCheckInQr } from '../../services/adminService'

const scanning = ref(false)
const scanMessage = ref('')
const scannerError = ref('')
const code = ref('')
const credential = ref('')
const store = useCheckInStore()
const checkIns = ref([])
const logLoading = ref(false)
const logError = ref('')
const events = ref([])
const route = useRoute()
const selectedEventId = ref('')
const selectedEvent = ref(null)
const registrants = ref([])
const registrantsLoading = ref(false)
const registrantsError = ref('')
const searchQuery = ref('')
const activeMode = ref('code') // 'code', 'scan', 'qr'
const eventQrLoading = ref(false)
const eventQrError = ref('')
const resultsList = ref([])

const loadEvents = async () => {
  try {
    const { data } = await getAdminEvents()
    events.value = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
    if (events.value.length > 0 && !selectedEventId.value) {
      // Event pages link here with ?event=<id> so check-in opens on that event.
      const requested = events.value.find((item) => String(item.id) === String(route.query.event))
      selectedEventId.value = (requested || events.value[0]).id
      await loadSelectedEvent()
    }
  } catch {
    // ignore
  }
}

const loadSelectedEvent = async () => {
  if (!selectedEventId.value) return
  try {
    const [{ data }, summaryResponse] = await Promise.all([
      getEvent(selectedEventId.value),
      getEventCheckInSummary(selectedEventId.value),
    ])
    const attendanceData = summaryResponse.data.data || {}
    selectedEvent.value = {
      ...(data.data || {}),
      registered: data.registered,
      check_in_qr_token: data.data?.check_in_qr_token || attendanceData.event?.check_in_qr_token,
    }
    await loadRegistrants()
  } catch {
    // ignore
  }
}

const loadRegistrants = async () => {
  if (!selectedEventId.value) return
  registrantsLoading.value = true
  registrantsError.value = ''
  try {
    const { data } = await getEventRegistrants(selectedEventId.value)
    registrants.value = data.data || []
  } catch {
    registrantsError.value = 'Failed to load attendees'
  } finally {
    registrantsLoading.value = false
  }
}

const eventCheckInQrValue = computed(() => {
  if (!selectedEvent.value?.check_in_qr_token) return ''
  const origin = window.location.origin
  return `${origin}/check-in?e=${selectedEvent.value.check_in_qr_token}`
})

// The log endpoint covers every event; this desk shows only the selected event.
const eventLog = computed(() => checkIns.value.filter((item) => !selectedEventId.value || String(item.registration?.event_id) === String(selectedEventId.value)))
const loadCheckIns = async () => {
  logLoading.value = true
  logError.value = ''
  try {
    checkIns.value = (await getCheckIns({ per_page: 100 })).data.data || []
  } catch {
    logError.value = 'Unable to load recent check-ins.'
  } finally {
    logLoading.value = false
  }
}

const exportLog = () => {
  const header = ['Attendee', 'Registration code', 'Email', 'Checked in at', 'Checked in by']
  const rows = eventLog.value.map((item) => [
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
    resultsList.value = store.attendee ? [store.attendee] : []
  } catch {
    scanMessage.value = ''
    resultsList.value = []
  }
}

const confirmCheckIn = async (cred = null) => {
  const credToUse = cred || credential.value || (store.attendee?.registration_code || store.attendee?.qr_token)
  if (!credToUse || store.loading) return

  try {
    await store.submit(credToUse)
    scanMessage.value = store.result.message
    code.value = ''
    credential.value = ''
    store.attendee = null
    // Update results list to reflect checked-in status
    resultsList.value = resultsList.value.map(item => {
      const itemCred = item.registration_code || item.qr_token
      if (itemCred === credToUse) {
        return { ...item, checked_in_at: new Date().toISOString() }
      }
      return item
    })
    await loadCheckIns()
    await loadRegistrants()
  } catch {}
}

const handleScan = (value) => {
  scannerError.value = ''
  return lookupCredential(value)
}
const toggleScanner = () => {
  scannerError.value = ''
  scanning.value = !scanning.value
}
const submitCode = () => lookupCredential(code.value)

const searchAttendees = async () => {
  if (!selectedEventId.value || !searchQuery.value.trim()) return
  try {
    const result = await store.search(selectedEventId.value, searchQuery.value)
    if (result.registration) {
      resultsList.value = [result.registration]
    } else if (result.suggestions && result.suggestions.length > 0) {
      resultsList.value = result.suggestions
    } else {
      resultsList.value = []
    }
  } catch {
    resultsList.value = []
  }
}

const selectFromSuggestion = (suggestion) => {
  resultsList.value = [suggestion]
}

const regenerateQr = async () => {
  if (!selectedEventId.value) return
  eventQrLoading.value = true
  eventQrError.value = ''
  try {
    await regenerateEventCheckInQr(selectedEventId.value)
    await loadSelectedEvent()
  } catch (err) {
    eventQrError.value = err.response?.data?.message || 'Failed to regenerate QR'
  } finally {
    eventQrLoading.value = false
  }
}

const getCredentialForResult = (item) => item.registration_code || item.qr_token || item.credential

const checkedInCount = computed(() => {
  if (selectedEvent.value?.registrations) {
    return selectedEvent.value.registrations.filter(r => r.checkIn).length
  }
  return checkIns.value.filter(c => c.checked_in_at).length
})

const totalRegistrations = computed(() => {
  return selectedEvent.value?.registrations?.length || selectedEvent.value?.registered || checkIns.value.length || 0
})

const isLocalhost = computed(() => {
  if (typeof window === 'undefined') return false
  return window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
})

const copyQrLink = async () => {
  if (!eventCheckInQrValue.value) return
  try {
    await navigator.clipboard.writeText(eventCheckInQrValue.value)
    scanMessage.value = 'Link copied'
    setTimeout(() => { scanMessage.value = '' }, 1500)
  } catch {}
}

const downloadQr = () => {
  const canvas = document.querySelector('.qr-display canvas')
  if (!canvas) return
  const anchor = document.createElement('a')
  anchor.download = `${selectedEvent.value?.name || 'event'}-checkin-qr.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
}

onMounted(async () => {
  await loadEvents()
  await loadCheckIns()
})
</script>

<template>
  <AdminLayout>
    <section class="checkin-workspace">
      <header class="checkin-heading">
        <div>
          <div class="eyebrow">LIVE DESK - TODAY</div>
          <h1>Check-in participants</h1>
          <p>Search an attendee by code, name, or email to approve their check-in - or print the entrance QR for self check-in.</p>
        </div>
        <span class="live-indicator"><i></i> Live check-in desk</span>
      </header>

      <div class="checkin-layout">
        <article class="find-attendee-panel">
          <div class="panel-header">
            <h2>Find attendee</h2>
            <p>Search by registration code, name, or email - then approve their check-in.</p>
          </div>

          <div class="search-row">
            <input v-model="searchQuery" type="search" aria-label="Search attendees by registration code, name or email" placeholder="e.g. CTS-004, Sokha, or sokha@example.com" @keyup.enter="searchAttendees" />
            <button type="button" class="primary-button" :disabled="store.searchLoading || !selectedEventId || !searchQuery.trim()" @click="searchAttendees">
              {{ store.searchLoading ? 'Searching...' : 'Search' }}
            </button>
          </div>

          <button v-if="scanning" type="button" class="toggle-scanner" @click="toggleScanner">Stop camera</button>

          <div class="qr-scanner-area">
            <div v-if="scanning" class="scanner-video">
              <QRScanner @scan="handleScan" @error="scannerError = $event" />
            </div>
            <!-- Camera off: an honest call to action instead of an empty viewfinder. -->
            <button v-else type="button" class="scanner-start" @click="toggleScanner">
              <span class="scanner-start__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></span>
              <span><strong>Scan a QR pass</strong><small>Start the camera and point it at the attendee’s pass</small></span>
            </button>
            <p v-if="scanning" class="scanner-instruction">Point your camera at the QR code</p>
          </div>

          <div class="code-input-row">
            <input v-model="code" aria-label="Paste a QR code or check-in token" placeholder="...or paste the QR code / token" @keyup.enter="submitCode" />
            <button type="button" class="black-button" :disabled="!code.trim()" @click="submitCode">Use code</button>
          </div>

          <div v-if="resultsList.length" class="results-list">
            <p class="results-title">Attendees found</p>
            <div v-for="item in resultsList" :key="item.id || item.registration_code" class="result-item">
              <div class="result-info">
                <strong>{{ item.full_name || 'Unnamed' }}</strong>
                <div class="result-meta">
                  <span>Code: {{ item.registration_code }}</span>
                  <span v-if="item.email">Email: {{ item.email }}</span>
                  <span v-if="item.event_name">Event: {{ item.event_name }}</span>
                  <span v-if="item.checked_in_at" class="badge checked">Checked in</span>
                  <span v-else class="badge pending">Not checked in</span>
                </div>
              </div>
              <button type="button" class="primary-button small" :disabled="store.loading || item.checked_in_at" @click="confirmCheckIn(getCredentialForResult(item))">
                {{ item.checked_in_at ? 'Checked in' : 'Check in' }}
              </button>
            </div>
          </div>

          <div v-if="store.attendee && !resultsList.length" class="attendee-preview">
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
            </div>
            <div class="attendee-preview-actions">
              <button type="button" class="secondary-button" @click="store.attendee = null">Cancel</button>
              <button type="button" class="primary-button" :disabled="store.loading || Boolean(store.attendee.checked_in_at)" @click="confirmCheckIn(store.attendee.registration_code || store.attendee.qr_token)">
                {{ store.loading ? 'Checking in...' : store.attendee.checked_in_at ? 'Already checked in' : 'Confirm check-in' }}
              </button>
            </div>
          </div>

          <div v-if="scannerError" class="error">{{ scannerError }}</div>
          <div v-if="scanMessage" class="scan-success">✓ {{ scanMessage }}</div>
          <div v-if="store.error" class="error">{{ store.error }}</div>
        </article>

        <aside class="event-qr-panel">
          <section class="event-section">
            <div class="section-label">EVENT</div>
            <select class="event-dropdown" v-model="selectedEventId" aria-label="Event" @change="loadSelectedEvent">
              <option value="" disabled>Select event</option>
              <option v-for="event in events" :key="event.id" :value="event.id">
                {{ event.name }}
              </option>
            </select>
            <div class="event-meta">
              <span>Checked-in</span>
              <span>{{ checkedInCount }} / {{ totalRegistrations }}</span>
            </div>
          </section>

          <div class="metric-cards">
            <div class="metric-card">
              <div class="metric-label">Registered</div>
              <div class="metric-value">{{ totalRegistrations }}</div>
            </div>
            <div class="metric-card">
              <div class="metric-label">Checked-in</div>
              <div class="metric-value">{{ checkedInCount }}</div>
            </div>
            <div class="metric-card">
              <div class="metric-label">Remaining</div>
              <div class="metric-value">{{ Math.max(0, totalRegistrations - checkedInCount) }}</div>
            </div>
          </div>

          <section class="entrance-qr-section">
            <div class="section-label centered">ENTRANCE QR</div>
            <div class="qr-display">
              <QRCodeDisplay v-if="eventCheckInQrValue" :value="eventCheckInQrValue" :size="200" />
              <div v-else class="qr-placeholder">No QR</div>
            </div>
            <p class="qr-desc">Attendees scan this at the door to enter. See details and confirm attendance themselves.</p>
            <div v-if="isLocalhost" class="warning-callout">
              <span class="warning-icon">▲</span>
              <span>This QR points at localhost - phones cannot open it. Reopen this page via PC's network address (e.g. http://192.168.x.x:3713) before printing.</span>
            </div>
            <div class="qr-actions">
              <button type="button" class="secondary-button" @click="copyQrLink">Copy link</button>
              <button type="button" class="secondary-button" @click="downloadQr">Download</button>
              <button type="button" class="secondary-button" :disabled="eventQrLoading" @click="regenerateQr">
                {{ eventQrLoading ? 'Regenerating...' : 'Regenerate' }}
              </button>
            </div>
          </section>
        </aside>
      </div>

      <section class="checkin-table-panel">
        <header><div><p class="admin-eyebrow">Attendance log</p><h2>Recent check-ins</h2></div><button type="button" class="export-log-button" :disabled="logLoading || !eventLog.length" @click="exportLog">{{ logLoading ? 'Loading...' : 'Export log' }}</button></header>
        <div v-if="logError" class="inline-error" role="alert"><span>{{ logError }}</span><button type="button" class="secondary-button" @click="loadCheckIns">Try again</button></div>
        <div v-else-if="logLoading" class="dynamic-loading" role="status">Loading recent check-ins…</div>
        <div v-else-if="!eventLog.length" class="recent-empty"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span><div><strong>No check-ins yet</strong><p>Scanned participants will appear here with their check-in time.</p></div></div>
        <div v-else class="checkin-log-list"><div v-for="item in eventLog" :key="item.id" class="checkin-log-row"><div><strong>{{ item.registration?.full_name || 'Unnamed attendee' }}</strong><small>{{ item.registration?.registration_code }}</small></div><time>{{ item.checked_in_at ? new Date(item.checked_in_at).toLocaleString() : 'Just now' }}</time></div></div>
      </section>

      <section v-if="selectedEventId" class="checkin-table-panel">
        <header><div><p class="admin-eyebrow">Attendees</p><h2>All attendees for event</h2></div></header>
        <div v-if="registrantsLoading" class="dynamic-loading">Loading attendees…</div>
        <div v-else-if="registrantsError" class="inline-error"><span>{{ registrantsError }}</span><button type="button" class="secondary-button" @click="loadRegistrants">Try again</button></div>
        <div v-else-if="!registrants.length" class="recent-empty"><strong>No attendees found</strong></div>
        <div v-else class="checkin-log-list">
          <div v-for="reg in registrants" :key="reg.id" class="checkin-log-row">
            <div>
              <strong>{{ reg.full_name || 'Unnamed' }}</strong>
              <small>{{ reg.registration_code }}{{ reg.email ? ' • ' + reg.email : '' }}</small>
            </div>
            <div class="attendee-actions">
              <span v-if="reg.checked_in_at" class="badge checked">Checked in</span>
              <button v-else type="button" class="primary-button small" :disabled="store.loading" @click="confirmCheckIn(reg.registration_code || reg.qr_token)">Check in</button>
            </div>
          </div>
        </div>
      </section>
    </section>
  </AdminLayout>
</template>

<style scoped>
.checkin-workspace {
  padding: 2rem;
  background: #f4f6fa;
  min-height: 100vh;
}

.checkin-heading {
  margin-bottom: 2rem;
}

.checkin-heading .eyebrow {
  font-size: 0.75rem;
  font-weight: 700;
  color: #0b7f62;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 0.5rem;
}

.checkin-heading h1 {
  font-size: 2.25rem;
  font-weight: 800;
  margin-bottom: 0.5rem;
  color: #0f172a;
  letter-spacing: -0.025em;
}

.checkin-heading p {
  color: #475569;
  font-size: 0.875rem;
  line-height: 1.5;
}

.live-indicator {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: #166534;
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  margin-top: 0.75rem;
}

.live-indicator i {
  width: 0.5rem;
  height: 0.5rem;
  background: #22c55e;
  border-radius: 50%;
  animation: pulse 2s infinite;
  box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.2);
}

@keyframes pulse {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.2); }
  50% { opacity: 0.7; box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.15); }
}

.checkin-layout {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 1.5rem;
  margin-bottom: 1.5rem;
}

@media (max-width: 1024px) {
  .checkin-layout {
    grid-template-columns: 1fr;
  }
}

.scanner-panel-heading {
  margin-bottom: 1.5rem;
}

.scanner-panel-heading h2 {
  font-size: 1.25rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.scanner-panel-heading p {
  color: #64748b;
  font-size: 0.875rem;
}

.checkin-code-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.checkin-code-form label {
  font-weight: 600;
  color: #1e293b;
}

.checkin-code-form > div {
  display: flex;
  gap: 0.75rem;
}

.checkin-code-form input {
  flex: 1;
  padding: 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  font-size: 0.875rem;
}

.primary-button {
  padding: 0.875rem 1.75rem;
  background: #8fd8c2;
  color: #ffffff;
  border: none;
  border-radius: 0.5rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.primary-button:hover:not(:disabled) {
  background: #34c39a;
}

.primary-button:hover:not(:disabled) {
  background: #0b7f62;
}

.primary-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.primary-button.small {
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
}

.secondary-button {
  padding: 0.5rem 1rem;
  background: #f0f4f8;
  color: #102a43;
  border: 1px solid #d8e2ec;
  border-radius: 0.5rem;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.2s;
  font-size: 0.875rem;
}

.secondary-button:hover:not(:disabled) {
  background: #e6edf3;
}

.results-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-top: 1rem;
}

.results-title {
  font-weight: 600;
  color: #1e293b;
}

.result-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  background: transparent;
}

.result-info {
  flex: 1;
}

.result-info strong {
  display: block;
  margin-bottom: 0.375rem;
  color: #1e293b;
}

.result-meta {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.75rem;
  color: #64748b;
}

.badge {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  font-weight: 500;
  width: fit-content;
}

.badge.checked {
  color: #166534;
  font-weight: 600;
}

.badge.pending {
  color: #92400e;
  font-weight: 600;
}

.scanner-placeholder {
  text-align: center;
  padding: 2rem;
  color: #64748b;
}

.scanner-frame {
  margin-bottom: 1rem;
}

.scanner-frame svg {
  width: 3rem;
  height: 3rem;
  color: #94a3b8;
}

.attendee-preview {
  background: transparent;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  padding: 1rem;
  margin-top: 1rem;
}

.attendee-preview-heading {
  margin-bottom: 1rem;
}

.attendee-preview-heading h3 {
  font-size: 1.125rem;
  font-weight: 600;
  margin-bottom: 0.375rem;
}

.attendee-preview-details {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 1rem;
  font-size: 0.875rem;
}

.attendee-preview-details small {
  color: #64748b;
  display: block;
}

.attendee-preview-details strong {
  color: #1e293b;
}

.attendee-preview-actions {
  display: flex;
  gap: 0.75rem;
}

.attendee-status {
  display: inline-block;
  font-size: 0.75rem;
  font-weight: 600;
  margin-top: 0.5rem;
}

.attendee-status.is-checked-in {
  color: #166534;
}

.attendee-status.is-ready {
  color: #065c46;
}

.event-qr-section {
  text-align: center;
  margin: 1.5rem 0;
}

.event-qr-frame {
  display: flex;
  justify-content: center;
  margin-bottom: 1rem;
}

.qr-help {
  color: #64748b;
  font-size: 0.875rem;
  margin-bottom: 1rem;
}

.event-qr-actions {
  display: flex;
  gap: 0.75rem;
  justify-content: center;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.text-link {
  color: #0b9a76;
  text-decoration: none;
  font-weight: 500;
}

.text-link:hover {
  text-decoration: underline;
}

.event-info {
  background: #f8fafc;
  padding: 1rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
}

.event-info p {
  margin-bottom: 0.5rem;
}

.event-info code {
  background: #e2e8f0;
  padding: 0.125rem 0.375rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  word-break: break-all;
}

.scan-success {
  color: #166534;
  padding: 0.75rem 0;
  margin-top: 1rem;
  font-weight: 500;
}

.error {
  color: #dc2626;
  padding: 0.75rem 0;
  margin-top: 1rem;
  font-weight: 500;
}

.info-message {
  text-align: center;
  color: #64748b;
  padding: 2rem;
}

.checkin-summary {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  padding: 1.5rem;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}

.checkin-summary h2 {
  font-size: 1.125rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.checkin-summary-copy {
  color: #64748b;
  font-size: 0.875rem;
  margin-bottom: 1rem;
}

.checkin-summary-stats {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.checkin-summary-stats > div {
  padding: 0.75rem;
  background: #f8fafc;
  border-radius: 0.5rem;
}

.checkin-summary-stats small {
  display: block;
  color: #64748b;
  font-size: 0.75rem;
  margin-bottom: 0.25rem;
}

.checkin-summary-stats strong {
  color: #1e293b;
  font-weight: 600;
}

.admin-eyebrow {
  color: #0b9a76;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.5rem;
}

.checkin-table-panel {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 0.875rem;
  padding: 1.75rem;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}

.checkin-table-panel header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.checkin-table-panel h2 {
  font-size: 1.125rem;
  font-weight: 600;
  margin-top: 0.25rem;
}

.export-log-button {
  padding: 0.5rem 1rem;
  background: #f1f5f9;
  color: #1e293b;
  border: 1px solid #e2e8f0;
  border-radius: 0.375rem;
  font-weight: 500;
  cursor: pointer;
}

.checkin-log-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.checkin-log-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.875rem 1rem;
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 0.75rem;
}

.checkin-log-row strong {
  display: block;
  color: #1e293b;
  margin-bottom: 0.25rem;
}

.checkin-log-row small {
  color: #64748b;
  font-size: 0.75rem;
}

.checkin-log-row time {
  color: #64748b;
  font-size: 0.875rem;
}

.attendee-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.recent-empty {
  text-align: center;
  padding: 2rem;
  color: #64748b;
}

.recent-empty svg {
  width: 2.5rem;
  height: 2.5rem;
  color: #94a3b8;
  margin-bottom: 0.75rem;
}

.dynamic-loading {
  text-align: center;
  color: #64748b;
  padding: 1rem;
}

.inline-error {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fee2e2;
  color: #dc2626;
  padding: 0.75rem;
  border-radius: 0.5rem;
  margin-bottom: 1rem;
}

.scanner-shell {
  margin-top: 1rem;
}

.qr-placeholder {
  padding: 2rem;
  color: #64748b;
  background: transparent;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  min-height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.find-attendee-panel {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 0.875rem;
  padding: 1.75rem;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}

.panel-header {
  margin-bottom: 1.5rem;
}

.panel-header h2 {
  font-size: 1.25rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: #1e293b;
}

.panel-header p {
  color: #64748b;
  font-size: 0.875rem;
}

.search-row {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.search-row input {
  flex: 1;
  padding: 0.875rem;
  border: 1.5px solid #e2e8f0;
  border-radius: 0.625rem;
  font-size: 0.875rem;
  transition: all 0.2s;
  background: #ffffff;
}

.search-row input:focus {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.toggle-scanner {
  background: transparent;
  border: none;
  color: #0b9a76;
  cursor: pointer;
  font-weight: 500;
  margin-bottom: 1rem;
  padding: 0.5rem 0;
}

.qr-scanner-area {
  position: relative;
  background: radial-gradient(circle at center, #1a1a1a 0%, #000 100%);
  border-radius: 0.75rem;
  min-height: 320px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
  overflow: hidden;
  box-shadow: inset 0 2px 8px 0 rgba(0, 0, 0, 0.5);
}

.scanner-video {
  width: 100%;
  height: 100%;
  min-height: 300px;
}

.scanner-placeholder-large {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 300px;
}

.camera-icon {
  position: absolute;
  top: 40px;
  left: 50%;
  transform: translateX(-50%);
  color: #6b7280;
  z-index: 1;
}

.camera-icon svg {
  width: 48px;
  height: 48px;
}

.camera-corners .corner {
  position: absolute;
  width: 40px;
  height: 40px;
  border: 2px solid #ffffff;
}

.corner.tl {
  top: 40px;
  left: 40px;
  border-right: none;
  border-bottom: none;
}

.corner.tr {
  top: 40px;
  right: 40px;
  border-left: none;
  border-bottom: none;
}

.corner.bl {
  bottom: 40px;
  left: 40px;
  border-right: none;
  border-top: none;
}

.corner.br {
  bottom: 40px;
  right: 40px;
  border-left: none;
  border-top: none;
}

.scanner-instruction {
  position: absolute;
  bottom: 20px;
  color: #ffffff;
  background: rgba(0, 0, 0, 0.7);
  padding: 0.375rem 1rem;
  border-radius: 9999px;
  font-size: 0.75rem;
}

.code-input-row {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.code-input-row input {
  flex: 1;
  padding: 0.875rem;
  border: 1.5px solid #e2e8f0;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  transition: all 0.2s;
}

.code-input-row input:focus {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.black-button {
  padding: 0.75rem 1.5rem;
  background: #1f2937;
  color: #ffffff;
  border: none;
  border-radius: 0.5rem;
  font-weight: 600;
  cursor: pointer;
}

.black-button:hover:not(:disabled) {
  background: #111827;
}

.black-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.event-qr-panel {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 0.875rem;
  padding: 1.75rem;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}

.event-section {
  margin-bottom: 1.5rem;
}

.section-label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  color: #64748b;
  letter-spacing: 0.05em;
  margin-bottom: 0.75rem;
}

.section-label.centered {
  text-align: center;
}

.event-dropdown {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  margin-bottom: 0.75rem;
}

.event-meta {
  display: flex;
  justify-content: space-between;
  font-size: 0.875rem;
  color: #64748b;
}

.metric-cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 0.625rem;
  padding: 0.5rem 0.375rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 48px;
}

.metric-label {
  font-size: 0.7rem;
  color: #475569;
  font-weight: 500;
  line-height: 1;
  text-align: center;
}

.metric-value {
  font-size: 1rem;
  font-weight: 600;
  color: #0f172a;
  margin-top: 0.125rem;
}

.entrance-qr-section {
  text-align: center;
}

.qr-display {
  display: flex;
  justify-content: center;
  margin-bottom: 1rem;
}

.qr-desc {
  font-size: 0.75rem;
  color: #64748b;
  margin-bottom: 1rem;
}

.warning-callout {
  background: #fef3c7;
  border: 1px solid #fbbf24;
  border-radius: 0.5rem;
  padding: 0.75rem;
  margin-bottom: 1rem;
  display: flex;
  gap: 0.5rem;
  align-items: flex-start;
  text-align: left;
  font-size: 0.75rem;
  color: #92400e;
}

.warning-icon {
  font-size: 1rem;
}

.qr-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  flex-wrap: nowrap;
}

.qr-actions .secondary-button {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
}
</style>
