<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRScanner from '../../components/QRScanner.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import { useCheckInStore } from '../../stores/checkIn'
import { getEventCheckIns } from '../../services/adminService'
import api from '../../services/api'
import { parseQrPayload } from '../../utils/qr'
import { formatDate } from '../../utils/formatDate'

const store = useCheckInStore()

const events = ref([])
const selectedEventId = ref('')
const desk = ref(null)
const deskLoading = ref(false)
const deskError = ref('')
const scanning = ref(false)
const scanNotice = ref('')
const searchQuery = ref('')
const filter = ref('all')
const copied = ref(false)
let noticeTimer = null

const selectedEvent = computed(() => events.value.find((event) => String(event.id) === String(selectedEventId.value)) || null)
const summary = computed(() => desk.value?.summary || { expected: 0, checked_in: 0, remaining: 0, rate: 0 })
const progressPercent = computed(() => summary.value.expected ? Math.min(100, Math.round((summary.value.checked_in / summary.value.expected) * 100)) : 0)
const checkIns = computed(() => {
  const rows = desk.value?.check_ins || []
  return filter.value === 'today' ? rows.filter((row) => row.checked_in_at && new Date(row.checked_in_at).toDateString() === new Date().toDateString()) : rows
})
const registration = computed(() => store.registration)
const formEntries = computed(() => Object.entries(registration.value?.form_values || {}))
const alreadyIn = computed(() => Boolean(registration.value?.checked_in_at) || Boolean(store.result))
const wrongEvent = computed(() => registration.value && selectedEvent.value && Number(registration.value.event_id) !== Number(selectedEvent.value.id))
/** Entrance QR: a URL attendees can open with any phone camera app. Uses the regenerable server-side token when available. */
const eventQrValue = computed(() => {
  if (!selectedEvent.value) return ''
  const token = desk.value?.event?.check_in_qr_token
  return token ? `${window.location.origin}/check-in?e=${token}` : `${window.location.origin}/check-in?event=${selectedEvent.value.id}`
})
/** Phones cannot open localhost URLs — warn when the desk is opened via localhost. */
const localhostHint = computed(() => /localhost|127\.0\.0\.1/.test(window.location.origin)
  ? 'This QR points at localhost — phones cannot open it. Reopen this page via your PC\u2019s network address (e.g. http://192.168.x.x:5173) before printing.'
  : '')

const flash = (message) => {
  scanNotice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => { scanNotice.value = '' }, 3500)
}

const loadEvents = async () => {
  try {
    const { data } = await api.get('/admin/events')
    events.value = data.data || data || []
    if (!selectedEventId.value && events.value.length) {
      const saved = localStorage.getItem('checkin_event_id')
      selectedEventId.value = events.value.some((event) => String(event.id) === saved) ? saved : String(events.value[0].id)
    }
  } catch { deskError.value = 'Unable to load events. Check your admin session.' }
}

const loadDesk = async () => {
  if (!selectedEventId.value) return
  deskLoading.value = true
  deskError.value = ''
  try {
    desk.value = (await getEventCheckIns(selectedEventId.value)).data.data
  } catch { deskError.value = 'Unable to load the check-in log for this event.' } finally { deskLoading.value = false }
}

const pickEvent = () => {
  localStorage.setItem('checkin_event_id', String(selectedEventId.value))
  store.reset()
  loadDesk()
}

/** Admin desk search: find an attendee by registration code, name, or email. */
const doSearch = async () => {
  if (!selectedEventId.value || !searchQuery.value.trim()) return
  scanning.value = false
  scanNotice.value = ''
  await store.search(selectedEventId.value, searchQuery.value.trim())
}

const pickSuggestion = async (code) => {
  if (!code) return
  searchQuery.value = code
  await store.search(selectedEventId.value, code)
}

/** Optional: admin scanned an attendee pass instead of searching. */
const onScan = async (value) => {
  const token = parseQrPayload(value).qr_token || value.trim()
  if (!token) return
  scanning.value = false
  await store.lookup(token)
  if (!store.registration) flash(store.error || 'Unrecognized QR code.')
}

const confirmCheckIn = async () => {
  if (!registration.value) return
  try {
    await store.submit(registration.value.qr_token)
    flash(`✓ ${registration.value.full_name || 'Attendee'} checked in.`)
    loadDesk()
  } catch { /* error is rendered on the detail card */ }
}

const skip = () => { store.reset(); searchQuery.value = '' }

const copyLink = async () => {
  await navigator.clipboard?.writeText(eventQrValue.value)
  copied.value = true
  setTimeout(() => { copied.value = false }, 1600)
}
/** Confirm the admin wants to regenerate the QR (old printed codes stop working). */
const regenerateQr = async () => {
  if (!selectedEvent.value) return
  if (!window.confirm(`Generate a new entrance QR for “${selectedEvent.value.name}”? Any previously printed codes will stop working.`)) return
  try {
    await api.post(`/admin/events/${selectedEvent.value.id}/check-in-qr`)
    await loadDesk()
    flash('New entrance QR generated. Reprint the poster.')
  } catch { deskError.value = 'Unable to generate a new QR code.' }
}

const downloadQr = () => {
  const canvas = document.querySelector('.desk-qr canvas')
  if (!canvas) return
  const anchor = document.createElement('a')
  anchor.download = `${selectedEvent.value?.name || 'event'}-checkin-qr.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
}

onMounted(async () => { await loadEvents(); loadDesk() })
onBeforeUnmount(() => clearTimeout(noticeTimer))
</script>

<template>
  <AdminLayout>
    <section class="checkin-workspace">
      <header class="checkin-heading">
        <div>
          <p class="admin-eyebrow">Live desk · today</p>
          <h1>Check-in participants</h1>
          <p>Search an attendee by code, name, or email to approve their check-in — or print the entrance QR for self check-in.</p>
        </div>
        <span class="live-indicator"><i></i> Live check-in desk</span>
      </header>

      <div class="checkin-layout">
        <article class="scanner-panel">
          <div class="scanner-panel-heading">
            <div>
              <h2>Find attendee</h2>
              <p>Search by registration code, name, or email — then approve their check-in.</p>
            </div>
          </div>

          <form class="desk-search" @submit.prevent="doSearch">
            <input v-model="searchQuery" type="text" placeholder="e.g. CTS-001, Sokha, or sokha@example.com" aria-label="Search attendee by code, name, or email" />
            <button type="submit" class="primary-button" :disabled="store.loading || !searchQuery.trim()">{{ store.loading ? 'Searching…' : 'Search' }}</button>
          </form>

          <p v-if="store.error && !registration" class="desk-error desk-search-error" role="alert">{{ store.error }}</p>

          <ul v-if="store.suggestions.length" class="desk-suggestions">
            <li v-for="person in store.suggestions" :key="person.id">
              <span class="desk-suggestion-avatar">{{ (person.full_name || 'A').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span>
              <div><strong>{{ person.full_name || 'Attendee' }}</strong><small>{{ person.registration_code || 'No code' }} · {{ person.email || 'no email' }}</small></div>
              <button type="button" class="secondary-button" :disabled="Boolean(person.checked_in_at)" @click="pickSuggestion(person.registration_code)">{{ person.checked_in_at ? 'Checked in ✓' : 'Select' }}</button>
            </li>
          </ul>

          <p v-if="scanNotice" class="scan-success">{{ scanNotice }}</p>

          <!-- Scanned attendee review -->
          <div v-if="registration" class="desk-review" :class="{ 'desk-review--done': alreadyIn }">
            <header>
              <span class="desk-review-avatar">{{ (registration.full_name || 'A').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span>
              <div>
                <strong>{{ registration.full_name || 'Attendee' }}</strong>
                <small>{{ registration.registration_code }} · {{ registration.email || 'no email on file' }}</small>
              </div>
              <span class="desk-review-state" :class="{ ok: alreadyIn, warn: wrongEvent }">{{ wrongEvent ? 'Other event' : alreadyIn ? 'Checked in' : 'Ready' }}</span>
            </header>
            <p v-if="wrongEvent" class="desk-warn">⚠ This pass belongs to a different event ({{ registration.event_name }}). Attendee self check-in at the entrance QR is recommended.</p>
            <dl v-if="formEntries.length">
              <div v-for="[label, value] in formEntries" :key="label">
                <dt>{{ label }}</dt>
                <dd>{{ value }}</dd>
              </div>
            </dl>
            <p v-if="store.error" class="desk-error" role="alert">{{ store.error }}</p>
            <footer>
              <button v-if="!alreadyIn && !wrongEvent" type="button" class="primary-button" :disabled="store.checkingIn" @click="confirmCheckIn">
                {{ store.checkingIn ? 'Checking in…' : 'Approve check-in' }}
              </button>
              <button type="button" class="secondary-button" @click="skip">Clear</button>
            </footer>
          </div>

          <div class="desk-scan-toggle">
            <button type="button" class="secondary-button desk-scan-btn" @click="scanning = !scanning">{{ scanning ? 'Hide QR scanner' : 'Or scan a QR pass' }}</button>
            <div v-if="scanning" class="scanner-shell desk-scan-shell"><QRScanner @scan="onScan" /></div>
          </div>
        </article>

        <aside class="checkin-summary">
          <p class="admin-eyebrow">Event</p>
          <select v-model="selectedEventId" class="desk-event-select" aria-label="Select event" @change="pickEvent">
            <option v-if="!events.length" value="">No events yet</option>
            <option v-for="event in events" :key="event.id" :value="String(event.id)">{{ event.name }}</option>
          </select>

          <div class="checkin-progress">
            <div><span>Checked in</span><strong>{{ summary.checked_in }} / {{ summary.expected }}</strong></div>
            <div class="progress-bar"><span :style="{ width: `${progressPercent}%` }"></span></div>
          </div>
          <div class="checkin-stats">
            <div><small>Expected</small><strong>{{ summary.expected }}</strong></div>
            <div><small>Checked in</small><strong>{{ summary.checked_in }}</strong></div>
            <div><small>Remaining</small><strong>{{ summary.remaining }}</strong></div>
          </div>

          <div v-if="selectedEvent" class="desk-qr">
            <p class="admin-eyebrow">Entrance QR</p>
            <QRCodeDisplay :value="eventQrValue" />
            <p class="desk-qr-help">Attendees scan this at the door to review their details and confirm attendance themselves.</p>
            <p v-if="localhostHint" class="desk-qr-warn">⚠ {{ localhostHint }}</p>
            <div class="desk-qr-actions">
              <button type="button" class="secondary-button" @click="copyLink">{{ copied ? 'Copied!' : 'Copy link' }}</button>
              <button type="button" class="secondary-button" @click="downloadQr">Download</button>
              <button type="button" class="secondary-button" @click="regenerateQr">Regenerate</button>
            </div>
          </div>

          <p v-if="deskError" class="desk-error" role="alert">{{ deskError }}</p>
        </aside>
      </div>

      <section class="checkin-table-panel">
        <header>
          <div>
            <p class="admin-eyebrow">Attendance log</p>
            <h2>Checked-in attendees</h2>
          </div>
          <div class="desk-log-tools">
            <select v-model="filter" class="desk-event-select desk-event-select--compact" aria-label="Filter log">
              <option value="all">All check-ins</option>
              <option value="today">Today only</option>
            </select>
            <span class="desk-log-count">{{ checkIns.length }} shown</span>
          </div>
        </header>

        <div v-if="deskLoading" class="recent-empty"><span>…</span><div><strong>Loading log</strong><p>Fetching the attendance list for this event.</p></div></div>
        <div v-else-if="!checkIns.length" class="recent-empty"><span>✓</span><div><strong>No check-ins yet</strong><p>Scanned participants will appear here with their check-in time.</p></div></div>
        <div v-else class="desk-table-wrap">
          <table>
            <thead><tr><th>Attendee</th><th>Contact</th><th>Checked in at</th><th>By</th></tr></thead>
            <tbody>
              <tr v-for="row in checkIns" :key="row.id">
                <td><strong>{{ row.name || 'Unknown attendee' }}</strong><small>{{ row.registration_code }}</small></td>
                <td>{{ row.email || '—' }}<small v-if="row.phone">{{ row.phone }}</small></td>
                <td><span class="desk-time">{{ formatDate(row.checked_in_at) }}</span></td>
                <td>{{ row.checked_in_by || 'Self check-in' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </section>
  </AdminLayout>
</template>

<style scoped>
.desk-event-select { margin: 7px 0 22px; padding: 10px 12px; border: 1px solid #dce8f4; border-radius: 8px; background: #fff; color: #16345f; font: 600 .78rem 'Space Grotesk', sans-serif; }
.desk-search { display: flex; gap: 9px; margin-top: 22px; }
.desk-search input { flex: 1; min-width: 0; min-height: 44px; border: 1px solid #d2dfeb; border-radius: 8px; padding: 10px 13px; background: #fcfdff; font: .78rem 'Space Grotesk', sans-serif; }
.desk-search input:focus { border-color: #76a8ef; outline: 0; }
.desk-search button { margin: 0; white-space: nowrap; }
.desk-search-error { margin-top: 10px; }
.desk-suggestions { display: grid; gap: 8px; margin: 14px 0 0; padding: 0; list-style: none; }
.desk-suggestions li { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border: 1px solid #e2ebf3; border-radius: 9px; background: #fff; }
.desk-suggestion-avatar { display: grid; place-items: center; flex: 0 0 auto; width: 36px; height: 36px; border-radius: 50%; background: #eef5ff; color: #277cf2; font-size: .7rem; font-weight: 700; }
.desk-suggestions li div { display: grid; gap: 2px; min-width: 0; flex: 1; }
.desk-suggestions li strong { color: #16345f; font-size: .76rem; }
.desk-suggestions li small { color: #8295aa; font-size: .64rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.desk-suggestions li button { margin: 0; padding: 7px 12px; font-size: .66rem; }
.desk-scan-toggle { margin-top: 20px; padding-top: 16px; border-top: 1px solid #edf2f6; }
.desk-scan-btn { margin: 0; padding: 8px 12px; font-size: .68rem; }
.desk-scan-shell { margin-top: 12px; }
.desk-event-select--compact { margin: 0; }
.desk-qr { display: grid; justify-items: center; gap: 8px; margin-top: 6px; padding-top: 18px; border-top: 1px solid #edf2f6; text-align: center; }
.desk-qr .admin-eyebrow { margin: 0; }
.desk-qr-help { margin: 0; color: #8a9caf; font-size: .68rem; line-height: 1.5; }
.desk-qr-warn { margin: 0; padding: 8px 10px; border: 1px solid #ffdcab; border-radius: 8px; background: #fffaf2; color: #a06312; font-size: .66rem; line-height: 1.45; text-align: left; }
.desk-qr-actions { display: flex; gap: 8px; }
.desk-qr-actions button { margin: 0; padding: 8px 12px; font-size: .68rem; }
.desk-review { display: grid; gap: 12px; margin-top: 20px; padding: 18px; border: 1px solid #bcd9f7; border-radius: 11px; background: #f7fbff; }
.desk-review--done { border-color: #b5e8cf; background: #f4fcf8; }
.desk-review header { display: flex; align-items: center; gap: 11px; }
.desk-review-avatar { display: grid; place-items: center; flex: 0 0 auto; width: 42px; height: 42px; border-radius: 50%; background: #eef5ff; color: #277cf2; font-weight: 700; }
.desk-review header div { display: grid; gap: 3px; min-width: 0; }
.desk-review strong { color: #16345f; font-size: .85rem; }
.desk-review small { color: #8295aa; font-size: .68rem; }
.desk-review-state { margin-left: auto; padding: 5px 10px; border-radius: 999px; background: #eef5ff; color: #277cf2; font-size: .62rem; font-weight: 700; white-space: nowrap; }
.desk-review-state.ok { background: #dff8ed; color: #0d9b68; }
.desk-review-state.warn { background: #fff1dc; color: #c27c17; }
.desk-warn { margin: 0; padding: 10px 12px; border: 1px solid #ffdcab; border-radius: 8px; background: #fffaf2; color: #a06312; font-size: .72rem; }
.desk-review dl { display: grid; gap: 5px; margin: 0; }
.desk-review dl > div { display: flex; justify-content: space-between; gap: 14px; padding: 8px 11px; border: 1px solid #e2ebf3; border-radius: 7px; background: #fff; }
.desk-review dt { color: #8295aa; font-size: .68rem; }
.desk-review dd { margin: 0; color: #365783; font-size: .72rem; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.desk-error { margin: 0; color: #a33a2a; font-size: .72rem; }
.desk-review footer { display: flex; gap: 9px; }
.desk-review footer button { flex: 1; margin: 0; }
.desk-log-tools { display: flex; align-items: center; gap: 10px; }
.desk-log-count { color: #8a9caf; font-size: .68rem; }
.desk-table-wrap { overflow-x: auto; }
.desk-table-wrap table { width: 100%; border-collapse: collapse; }
.desk-table-wrap th { padding: 12px 20px; border-bottom: 1px solid #edf2f6; color: #8a9caf; font-size: .62rem; letter-spacing: .08em; text-transform: uppercase; text-align: left; }
.desk-table-wrap td { padding: 13px 20px; border-bottom: 1px solid #f2f6fa; color: #456887; font-size: .75rem; vertical-align: top; }
.desk-table-wrap tr:last-child td { border-bottom: 0; }
.desk-table-wrap td strong { display: block; color: #16345f; font-size: .78rem; }
.desk-table-wrap td small { display: block; margin-top: 2px; color: #9aabba; font-size: .65rem; }
.desk-time { font: 600 .7rem 'DM Mono', monospace; color: #0d9b68; }
</style>
