<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRScanner from '../../components/QRScanner.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import { useCheckInStore } from '../../stores/checkIn'
import { getCheckIns, getAdminEvents, getEvent, getEventCheckInSummary, getEventRegistrants, regenerateEventCheckInQr } from '../../services/adminService'

const scanning = ref(true)
const scanMessage = ref('')
const credential = ref('')
const store = useCheckInStore()
const checkIns = ref([])
const logLoading = ref(false)
const exportLoading = ref(false)
const logError = ref('')
const events = ref([])
const eventsLoading = ref(false)
const eventsError = ref('')
const selectedEventId = ref('')
const selectedEvent = ref(null)
const selectedSummary = ref(null)
const eventLoading = ref(false)
const eventError = ref('')
const registrants = ref([])
const registrantsLoading = ref(false)
const registrantsError = ref('')
const registrantsPage = ref({ current_page: 1, last_page: 1, total: 0 })
const searchQuery = ref('')
const eventQrLoading = ref(false)
const eventQrError = ref('')
const resultsList = ref([])
const searchError = ref('')
const searchCompleted = ref(false)

const loadEvents = async () => {
  eventsLoading.value = true
  eventsError.value = ''
  try {
    const { data } = await getAdminEvents()
    events.value = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
    if (events.value.length > 0 && !selectedEventId.value) {
      selectedEventId.value = events.value[0].id
      await loadSelectedEvent()
    }
  } catch (error) {
    eventsError.value = error.response?.data?.message || 'Unable to load events. Try again.'
  } finally {
    eventsLoading.value = false
  }
}

const loadSelectedEvent = async () => {
  if (!selectedEventId.value) {
    selectedEvent.value = null
    selectedSummary.value = null
    registrants.value = []
    return
  }
  const eventId = selectedEventId.value
  eventLoading.value = true
  eventError.value = ''
  selectedEvent.value = null
  selectedSummary.value = null
  registrants.value = []
  registrantsPage.value = { current_page: 1, last_page: 1, total: 0 }
  searchQuery.value = ''
  resultsList.value = []
  credential.value = ''
  store.attendee = null
  store.error = null
  scanMessage.value = ''
  try {
    const [{ data }, summaryResponse] = await Promise.all([getEvent(eventId), getEventCheckInSummary(eventId)])
    if (eventId !== selectedEventId.value) return
    const attendanceData = summaryResponse.data.data
    selectedEvent.value = {
      ...data.data,
      check_in_qr_token: data.data.check_in_qr_token || attendanceData.event?.check_in_qr_token,
    }
    selectedSummary.value = attendanceData.summary
    await loadRegistrants(1)
  } catch (error) {
    if (eventId === selectedEventId.value) eventError.value = error.response?.data?.message || 'Unable to load this event. Try again.'
  } finally {
    if (eventId === selectedEventId.value) eventLoading.value = false
  }
}

const loadRegistrants = async (page = registrantsPage.value.current_page) => {
  if (!selectedEventId.value) return
  const eventId = selectedEventId.value
  registrantsLoading.value = true
  registrantsError.value = ''
  try {
    const { data } = await getEventRegistrants(eventId, { page })
    if (eventId !== selectedEventId.value) return
    registrants.value = data.data || []
    registrantsPage.value = data.meta || { current_page: 1, last_page: 1, total: registrants.value.length }
  } catch (error) {
    if (eventId === selectedEventId.value) registrantsError.value = error.response?.data?.message || 'Unable to load attendees.'
  } finally {
    if (eventId === selectedEventId.value) registrantsLoading.value = false
  }
}

const eventCheckInQrValue = computed(() => {
  if (!selectedEvent.value?.check_in_qr_token) return ''
  const origin = window.location.origin
  return `${origin}/check-in?e=${selectedEvent.value.check_in_qr_token}`
})

const loadCheckIns = async () => {
  if (!selectedEventId.value) {
    checkIns.value = []
    return
  }
  logLoading.value = true
  logError.value = ''
  try {
    checkIns.value = (await getCheckIns({ per_page: 100 })).data.data || []
  } catch (error) {
    logError.value = error.response?.data?.message || 'Unable to load recent check-ins.'
  } finally {
    logLoading.value = false
  }
}

const exportLog = async () => {
  exportLoading.value = true
  logError.value = ''
  let allCheckIns = []
  try {
    const firstPage = (await getCheckIns({ per_page: 100, page: 1 })).data
    allCheckIns = [...(firstPage.data || [])]
    const lastPage = firstPage.meta?.last_page || 1
    for (let page = 2; page <= lastPage; page += 1) {
      const { data } = await getCheckIns({ per_page: 100, page })
      allCheckIns.push(...(data.data || []))
    }
  } catch (error) {
    logError.value = error.response?.data?.message || 'Unable to export the complete check-in log.'
    exportLoading.value = false
    return
  }
  const header = ['Attendee', 'Registration code', 'Email', 'Checked in at', 'Checked in by']
  const rows = allCheckIns.map((item) => [
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
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  exportLoading.value = false
}

const normalizeCredential = (value) => {
  const raw = String(value || '').trim()
  if (!raw) return ''

  try {
    const payload = JSON.parse(raw)
    if (payload?.qr_token || payload?.registration_code) return payload.qr_token || payload.registration_code
  } catch { /* QR may contain a plain code or URL instead of JSON. */ }

  try {
    const url = new URL(raw)
    const params = url.searchParams
    const token = params.get('qr_token') || params.get('token') || params.get('credential') || params.get('code')
    if (token) return token
    if (params.has('e') || params.has('event') || params.has('entrance_token')) return ''
  } catch { /* Treat non-URL content as the registration code. */ }

  return raw
}

const lookupCredential = async (value) => {
  const credentialText = normalizeCredential(value)
  if (!credentialText || store.lookupLoading || store.loading) {
    if (!credentialText && value) store.error = 'That is an event entrance QR. Scan the attendee?s personal registration QR instead.'
    return
  }

  try {
    scanMessage.value = ''
    searchError.value = ''
    searchCompleted.value = false
    resultsList.value = []
    credential.value = credentialText
    await store.lookup(credentialText)
    scanning.value = false
    const attendee = store.attendee
    const attendeeEventId = attendee?.event_id
    if (attendeeEventId && String(attendeeEventId) !== String(selectedEventId.value) && events.some((event) => String(event.id) === String(attendeeEventId))) {
      selectedEventId.value = attendeeEventId
      await loadSelectedEvent()
    }
    store.attendee = attendee
    resultsList.value = attendee ? [attendee] : []
  } catch (error) {
    scanMessage.value = ''
    resultsList.value = []
    searchError.value = error.response?.data?.message || store.error || 'No attendee matched that QR or code.'
  } finally {
    scanning.value = false
  }
}

const confirmCheckIn = async (cred = null) => {
  const credToUse = cred || credential.value || (store.attendee?.registration_code || store.attendee?.qr_token)
  if (!credToUse || store.loading) return

  try {
    await store.submit(credToUse)
    scanMessage.value = store.result.message
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
    await Promise.all([loadRegistrants(), loadEventSummary()])
  } catch {}
}

const loadEventSummary = async () => {
  if (!selectedEventId.value) return
  const eventId = selectedEventId.value
  try {
    const { data } = await getEventCheckInSummary(eventId)
    if (eventId === selectedEventId.value) selectedSummary.value = data.data.summary
  } catch (error) {
    if (eventId === selectedEventId.value) eventError.value = error.response?.data?.message || 'Unable to refresh event attendance.'
  }
}

const handleScan = (value) => lookupCredential(value)
const toggleScanner = () => {
  scanning.value = !scanning.value
}

const searchAttendees = async () => {
  if (!selectedEventId.value || !searchQuery.value.trim() || store.searchLoading) return
  const eventId = selectedEventId.value
  searchError.value = ''
  searchCompleted.value = false
  resultsList.value = []
  store.attendee = null
  try {
    const result = await store.search(eventId, searchQuery.value)
    if (eventId !== selectedEventId.value) return
    if (result.registration) {
      resultsList.value = [result.registration]
    } else if (result.suggestions && result.suggestions.length > 0) {
      resultsList.value = result.suggestions
    } else {
      resultsList.value = []
    }
    searchCompleted.value = true
  } catch (error) {
    if (eventId === selectedEventId.value) {
      resultsList.value = []
      searchCompleted.value = true
      searchError.value = error.response?.data?.errors?.query?.[0] || error.response?.data?.message || store.error || 'No attendees matched that search.'
    }
  }
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
  return selectedSummary.value?.checked_in || 0
})

const totalRegistrations = computed(() => {
  return selectedSummary.value?.expected || 0
})



const copyQrLink = async () => {
  if (!eventCheckInQrValue.value) return
  eventQrError.value = ''
  try {
    await navigator.clipboard.writeText(eventCheckInQrValue.value)
    scanMessage.value = 'Link copied'
    setTimeout(() => { scanMessage.value = '' }, 1500)
  } catch { eventQrError.value = 'Unable to copy the link. You can copy it from the address bar.' }
}

const downloadQr = () => {
  eventQrError.value = ''
  const canvas = document.querySelector('.qr-display canvas')
  if (!canvas) { eventQrError.value = 'The QR image is not ready yet.'; return }
  const anchor = document.createElement('a')
  anchor.download = `${selectedEvent.value?.name || 'event'}-checkin-qr.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
  scanMessage.value = 'QR downloaded'
}

onMounted(async () => {
  await Promise.all([loadEvents(), loadCheckIns()])
})
</script>

<template>
  <AdminLayout>
    <section class="checkin-workspace">
      <header class="checkin-heading">
        <div>
          <div class="eyebrow">LIVE DESK - TODAY</div>
          <h1>Check-in participants</h1>
          <p>Find a registration, confirm attendance, or share the entrance QR so attendees can check themselves in.</p>
        </div>
        <span class="live-indicator"><i></i> Live check-in desk</span>
      </header>

      <div class="checkin-layout">
        <article class="find-attendee-panel">
          <div class="panel-header">
            <h2>Find attendee</h2>
            <p>Search by registration code, attendee name, or email, then record their attendance.</p>
          </div>

          <div class="search-row">
            <input v-model="searchQuery" placeholder="e.g. CTS-004, Sokha, or sokha@example.com" @keyup.enter="searchAttendees" />
            <button type="button" class="primary-button" :disabled="store.searchLoading || !selectedEventId || !searchQuery.trim()" @click="searchAttendees">
              {{ store.searchLoading ? 'Searching...' : 'Search' }}
            </button>
          </div>

          <div v-if="store.searchLoading" class="search-feedback" role="status">Searching attendees...</div>
          <div v-else-if="searchError" class="search-feedback search-feedback--error" role="alert">{{ searchError }}</div>
          <div v-else-if="searchCompleted && !resultsList.length" class="search-feedback" role="status">No attendees matched. Check the code and selected event, then try again.</div>

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

          <button type="button" class="toggle-scanner" @click="toggleScanner">
            {{ scanning ? 'Hide QR scanner' : 'Show QR scanner' }}
          </button>

          <div class="qr-scanner-area">
            <div v-if="scanning" class="scanner-video">
              <QRScanner @scan="handleScan" />
            </div>
            <div v-else class="scanner-off" role="status">Camera scanner is off. Select Show QR scanner to scan an attendee pass.</div>
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

          <div v-if="scanMessage" class="scan-success">✓ {{ scanMessage }}</div>
          <div v-if="store.error" class="error">{{ store.error }}</div>
        </article>

        <aside class="event-qr-panel">
          <section class="event-section">
            <div class="section-label">EVENT</div>
            <div v-if="eventsLoading" class="event-state" role="status">Loading events…</div>
            <div v-else-if="eventsError" class="event-state event-state--error" role="alert">{{ eventsError }} <button type="button" @click="loadEvents">Try again</button></div>
            <div v-else-if="!events.length" class="event-state">No events are available for your account.</div>
            <select v-else class="event-dropdown" v-model="selectedEventId" @change="loadSelectedEvent">
              <option value="" disabled>Select event</option>
              <option v-for="event in events" :key="event.id" :value="event.id">
                {{ event.name }}
              </option>
            </select>
            <div v-if="eventLoading" class="event-state" role="status">Loading event attendance…</div>
            <div v-if="eventError" class="event-state event-state--error" role="alert">{{ eventError }} <button type="button" @click="loadSelectedEvent">Try again</button></div>
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
              <div v-else class="qr-placeholder">Select an event to show its QR</div>
            </div>
            <p class="qr-desc">Attendees scan this at the door to enter. See details and confirm attendance themselves.</p>

            <div class="qr-actions">
              <button type="button" class="secondary-button" :disabled="!eventCheckInQrValue" @click="copyQrLink">Copy link</button>
              <button type="button" class="secondary-button" :disabled="!eventCheckInQrValue" @click="downloadQr">Download</button>
              <button type="button" class="secondary-button" :disabled="eventQrLoading || !selectedEventId" @click="regenerateQr">
                {{ eventQrLoading ? 'Regenerating...' : 'Regenerate' }}
              </button>
            </div>
            <p v-if="eventQrError" class="event-state event-state--error" role="alert">{{ eventQrError }}</p>
          </section>
        </aside>
      </div>

      <section class="checkin-table-panel">
        <header><div><p class="admin-eyebrow">Attendance log</p><h2>Recent check-ins</h2></div><button type="button" class="export-log-button" :disabled="exportLoading || !checkIns.length" @click="exportLog">{{ exportLoading ? 'Preparing export…' : 'Export full log' }}</button></header>
        <div v-if="logError" class="inline-error" role="alert"><span>{{ logError }}</span><button type="button" class="secondary-button" @click="loadCheckIns">Try again</button></div>
        <div v-else-if="logLoading" class="dynamic-loading" role="status">Loading recent check-ins…</div>
        <div v-else-if="!checkIns.length" class="recent-empty"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span><div><strong>No check-ins yet</strong><p>Scanned participants will appear here with their check-in time.</p></div></div>
        <div v-else class="checkin-log-list"><div v-for="item in checkIns" :key="item.id" class="checkin-log-row"><div><strong>{{ item.registration?.full_name || 'Unnamed attendee' }}</strong><small>{{ item.registration?.registration_code }}</small></div><time>{{ item.checked_in_at ? new Date(item.checked_in_at).toLocaleString() : 'Just now' }}</time></div></div>
      </section>

      <section v-if="selectedEventId" class="checkin-table-panel">
        <header><div><p class="admin-eyebrow">Attendees</p><h2>Event attendee list <small v-if="registrantsPage.total">{{ registrantsPage.total }}</small></h2></div></header>
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
        <footer v-if="registrantsPage.last_page > 1" class="attendee-pagination">
          <span>Page {{ registrantsPage.current_page }} of {{ registrantsPage.last_page }}</span>
          <div><button type="button" class="secondary-button" :disabled="registrantsLoading || registrantsPage.current_page <= 1" @click="loadRegistrants(registrantsPage.current_page - 1)">Previous</button><button type="button" class="secondary-button" :disabled="registrantsLoading || registrantsPage.current_page >= registrantsPage.last_page" @click="loadRegistrants(registrantsPage.current_page + 1)">Next</button></div>
        </footer>
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
  color: #2563eb;
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
  background: #93c5fd;
  color: #ffffff;
  border: none;
  border-radius: 0.5rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.primary-button:hover:not(:disabled) {
  background: #60a5fa;
}

.primary-button:hover:not(:disabled) {
  background: #2563eb;
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

.search-feedback {
  margin: -.25rem 0 1rem;
  color: #64748b;
  font-size: .875rem;
}

.search-feedback--error { color: #b42332; }

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
  color: #1e40af;
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
  color: #3b82f6;
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
  color: #3b82f6;
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

.checkin-table-panel h2 small { margin-left: 6px; color: #64748b; font-size: .75rem; font-weight: 500; }
.attendee-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px; color: #64748b; font-size: .8rem; }
.attendee-pagination > div { display: flex; gap: 8px; }
.attendee-pagination .secondary-button { padding: .5rem .75rem; }

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
  color: #3b82f6;
  cursor: pointer;
  font-weight: 500;
  margin-bottom: 1rem;
  padding: 0.5rem 0;
}

.qr-scanner-area {
  margin-bottom: 1rem;
}

.scanner-video { width: 100%; }

.scanner-off {
  display: grid;
  place-items: center;
  min-height: 220px;
  padding: 24px;
  color: #64748b;
  background: #f8fbff;
  text-align: center;
  font-size: .9rem;
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

.event-state { margin: 0 0 .75rem; color: #64748b; font-size: .8rem; line-height: 1.5; }
.event-state--error { color: #a33d45; }
.event-state button { margin-left: .4rem; border: 0; background: transparent; color: #2563eb; font: inherit; font-weight: 700; cursor: pointer; }

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
