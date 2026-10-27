<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import QRScanner from '../../components/QRScanner.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import { useCheckInStore } from '../../stores/checkIn'
import { getAdminEvents, getEventCheckInSummary, regenerateEventCheckInQr } from '../../services/adminService'
import { eventPhase, percentOf, phaseLabels, toCsv } from '../../utils/eventStats'

const route = useRoute()
const router = useRouter()
const store = useCheckInStore()

// ----- Step 1: event -----
const events = ref([])
const eventsLoading = ref(true)
const eventsError = ref('')
const selectedEventId = ref('')
const desk = ref(null) // { event, summary, check_ins } from the event check-in endpoint
const deskLoading = ref(false)
const deskError = ref('')
let deskRequest = 0

const selectedEvent = computed(() => events.value.find((event) => String(event.id) === String(selectedEventId.value)) || null)
const phase = computed(() => selectedEvent.value ? eventPhase(selectedEvent.value) : 'unscheduled')
const summary = computed(() => desk.value?.summary || null)
const registeredCount = computed(() => Number(summary.value?.expected || 0))
const checkedInCount = computed(() => Number(summary.value?.checked_in || 0))
const remainingCount = computed(() => Number(summary.value?.remaining ?? Math.max(registeredCount.value - checkedInCount.value, 0)))
const checkInRate = computed(() => percentOf(checkedInCount.value, registeredCount.value) ?? 0)
const recentCheckIns = computed(() => desk.value?.check_ins || [])
const showAllRecent = ref(false)
const visibleRecent = computed(() => showAllRecent.value ? recentCheckIns.value : recentCheckIns.value.slice(0, 6))

const loadEvents = async () => {
  eventsLoading.value = true
  eventsError.value = ''
  try {
    const { data } = await getAdminEvents()
    events.value = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
    if (!selectedEventId.value && events.value.length) {
      // Event pages link here with ?event=<id>; otherwise prefer the event happening now.
      const requested = events.value.find((item) => String(item.id) === String(route.query.event))
      const ongoing = events.value.find((item) => eventPhase(item) === 'ongoing')
      const upcoming = events.value.filter((item) => eventPhase(item) === 'upcoming').sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))[0]
      selectedEventId.value = (requested || ongoing || upcoming || events.value[0]).id
    }
  } catch {
    eventsError.value = 'We couldn’t load your events.'
  } finally {
    eventsLoading.value = false
  }
}

// Summary, entrance token and the check-in log all come from one event-scoped endpoint.
const loadDesk = async ({ silent = false } = {}) => {
  const eventId = selectedEventId.value
  const ticket = ++deskRequest
  if (!eventId) { desk.value = null; return }
  if (!silent) { deskLoading.value = true; desk.value = null }
  deskError.value = ''
  try {
    const { data } = await getEventCheckInSummary(eventId)
    if (ticket === deskRequest) desk.value = data.data || null
  } catch {
    if (ticket === deskRequest) deskError.value = 'We couldn’t load attendance for this event.'
  } finally {
    if (ticket === deskRequest) deskLoading.value = false
  }
}

watch(selectedEventId, (id, previous) => {
  if (!id) return
  if (previous) resetLookup({ keepMode: true })
  showAllRecent.value = false
  regenConfirm.value = false
  if (String(route.query.event) !== String(id)) router.replace({ query: { ...route.query, event: id } }).catch(() => {})
  loadDesk()
})

// ----- Step 2: find attendee -----
const modes = [
  { value: 'search', label: 'Search', icon: 'search' },
  { value: 'scan', label: 'Scan QR', icon: 'scan' },
  { value: 'code', label: 'Enter code', icon: 'hash' },
]
const mode = ref('search')
const searchQuery = ref('')
const manualCode = ref('')
const cameraOn = ref(false)
const cameraKey = ref(0)
const cameraError = ref('')
const searchInput = ref(null)
const codeInput = ref(null)

const busy = ref(false) // a lookup is running
const lookupError = ref(null) // { title, message, retry? }
const suggestions = ref([])
const attendee = ref(null)
const lastCredential = ref('')
const confirming = ref(false)
const success = ref(null) // { name, at }

const setMode = async (value) => {
  if (mode.value === value) return
  mode.value = value
  cameraError.value = ''
  if (value !== 'scan') cameraOn.value = false
  await nextTick()
  if (value === 'search') searchInput.value?.focus()
  if (value === 'code') codeInput.value?.focus()
}
const startCamera = () => {
  cameraError.value = ''
  cameraKey.value += 1
  cameraOn.value = true
}
const stopCamera = () => { cameraOn.value = false }

function resetLookup({ keepMode = false } = {}) {
  attendee.value = null
  suggestions.value = []
  lookupError.value = null
  success.value = null
  confirming.value = false
  lastCredential.value = ''
  store.reset()
  if (!keepMode) cameraOn.value = false
}

const describeError = (error, fallbackTitle) => {
  if (!error?.response) return { title: 'Connection problem', message: 'We couldn’t reach the server. Check the connection and try again.', retry: true }
  if (error.response.status === 403) return { title: 'Not allowed', message: 'You don’t have permission to check in attendees for this event.' }
  if (error.response.status >= 500) return { title: 'Something went wrong', message: 'The server had a problem. Please try again in a moment.', retry: true }
  return { title: fallbackTitle, message: store.error || 'Please check the details and try again.' }
}

const isEntranceQr = (value) => /\/check-in\?(?:.*&)?e=/.test(value)

const lookupCredential = async (value, source) => {
  const credential = String(value || '').trim()
  if (!credential || busy.value) return
  // Clear the previous card so nobody confirms the wrong person while the new lookup runs.
  attendee.value = null
  success.value = null
  suggestions.value = []
  lookupError.value = null
  confirming.value = false
  if (isEntranceQr(credential)) {
    attendee.value = null
    lookupError.value = { title: 'That’s the entrance QR', message: 'This code is the event’s entrance poster for self check-in, not a personal pass. Scan the QR on the attendee’s own registration pass instead.' }
    return
  }
  busy.value = true
  lastCredential.value = credential
  try {
    attendee.value = await store.lookup(credential)
  } catch (error) {
    attendee.value = null
    lookupError.value = error?.response?.status === 422
      ? { title: source === 'scan' ? 'QR code not recognised' : 'No registration found', message: source === 'scan' ? 'This QR code doesn’t match any registration. It may be invalid or from an old pass. Try searching by name instead.' : 'No registration matches that code or token. Check it and try again, or search by name.' }
      : describeError(error, 'Lookup failed')
    if (lookupError.value.retry) lookupError.value.action = () => lookupCredential(credential, source)
  } finally {
    busy.value = false
  }
}

const runSearch = async () => {
  const query = searchQuery.value.trim()
  if (!query || busy.value || !selectedEventId.value) return
  busy.value = true
  success.value = null
  attendee.value = null
  suggestions.value = []
  lookupError.value = null
  confirming.value = false
  try {
    const result = await store.search(selectedEventId.value, query)
    if (result.registration) attendee.value = result.registration
    else suggestions.value = result.suggestions || []
  } catch (error) {
    lookupError.value = error?.response?.status === 422
      ? { title: 'No attendee found', message: `No one registered for this event matches “${query}”. Try part of the name, the registration code, email or phone.` }
      : describeError(error, 'Search failed')
    if (lookupError.value.retry) lookupError.value.action = runSearch
  } finally {
    busy.value = false
  }
}
const pickSuggestion = (item) => lookupCredential(item.registration_code, 'search')
const submitCode = () => lookupCredential(manualCode.value, 'code')
const handleScan = (value) => {
  cameraOn.value = false
  return lookupCredential(value, 'scan')
}
const handleCameraError = (message) => {
  cameraOn.value = false
  cameraError.value = /HTTPS/.test(message)
    ? 'The camera only works on a secure (HTTPS) address. Open this page over HTTPS, or use Search or Enter code instead.'
    : 'We couldn’t use the camera. Allow camera access in the browser, close other apps using it, or use Search or Enter code instead.'
}

// ----- Step 3: validate -----
const apiOrigin = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/api\/?$/, '')
const photoFailed = ref(false)
watch(attendee, () => { photoFailed.value = false })
const photoUrl = computed(() => {
  const photo = attendee.value?.profile_photo
  if (!photo || photoFailed.value || typeof photo !== 'string') return ''
  return /^(https?:|data:|blob:)/.test(photo) ? photo : `${apiOrigin}/storage/${photo.replace(/^\/?(storage\/)?/, '')}`
})
const initials = (name) => (name || '?').trim().split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()
const wrongEvent = computed(() => attendee.value && selectedEventId.value && String(attendee.value.event_id) !== String(selectedEventId.value))
const otherEvent = computed(() => wrongEvent.value ? events.value.find((event) => String(event.id) === String(attendee.value.event_id)) : null)
const ineligibleReason = computed(() => {
  const status = String(attendee.value?.status || '').toLowerCase()
  return ['cancelled', 'canceled', 'rejected', 'void'].includes(status) ? `This registration is ${status} and can’t be checked in.` : ''
})
const state = computed(() => {
  if (!attendee.value) return ''
  if (wrongEvent.value) return 'wrong-event'
  if (attendee.value.checked_in_at) return 'checked-in'
  if (ineligibleReason.value) return 'ineligible'
  return 'ready'
})
const statusLabel = (status) => ({ confirmed: 'Confirmed', checked_in: 'Confirmed', pending: 'Pending', registered: 'Registered' }[String(status || '').toLowerCase()] || (status ? String(status).charAt(0).toUpperCase() + String(status).slice(1) : 'Registered'))
const details = computed(() => {
  const item = attendee.value
  if (!item) return []
  return [
    ['Email', item.email],
    ['Phone', item.phone || item.telephone],
    ['Organization', item.organization || item.business_entity],
    ['Position', item.position],
  ].filter(([, value]) => value)
})
const answers = computed(() => Object.entries(attendee.value?.form_values || {})
  .filter(([, value]) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && !value.length))
  .map(([label, value]) => ({ label, value: Array.isArray(value) ? value.join(', ') : String(value) })))
const timingNote = computed(() => {
  if (state.value !== 'ready') return ''
  if (phase.value === 'upcoming') return `This event starts ${dateTime(selectedEvent.value.starts_at)}. You can still check people in early.`
  if (phase.value === 'past') return 'This event has already ended. Check-in is still recorded with today’s time.'
  return ''
})

// ----- Step 4: confirm -----
const confirmButton = ref(null)
const nextButton = ref(null)
const confirmCheckIn = async () => {
  const item = attendee.value
  if (!item || state.value !== 'ready' || store.checkingIn) return
  const credential = item.registration_code || item.qr_token
  lookupError.value = null
  try {
    const result = await store.submit(credential)
    // Only now — after the server accepted it — show the attendee as checked in.
    const at = result?.check_in?.checked_in_at || new Date().toISOString()
    attendee.value = { ...item, checked_in_at: at, status: 'checked_in' }
    success.value = { name: item.full_name || 'Attendee', at }
    confirming.value = false
    searchQuery.value = ''
    manualCode.value = ''
    await loadDesk({ silent: true })
    await nextTick()
    nextButton.value?.focus()
  } catch (error) {
    if (error?.response?.status === 422) {
      // Most likely checked in at another desk a moment ago: show the real state.
      lookupError.value = { title: 'Already checked in', message: store.error || 'This registration is already checked in.' }
      try { attendee.value = await store.lookup(credential) } catch { /* keep the previous card */ }
      loadDesk({ silent: true })
    } else {
      lookupError.value = describeError(error, 'Check-in failed')
      lookupError.value.title = 'Check-in not saved'
      if (lookupError.value.retry) lookupError.value.action = confirmCheckIn
    }
  }
}
const nextAttendee = async () => {
  resetLookup({ keepMode: true })
  searchQuery.value = ''
  manualCode.value = ''
  await nextTick()
  if (mode.value === 'scan') startCamera()
  else (mode.value === 'code' ? codeInput : searchInput).value?.focus()
}
const switchToOtherEvent = () => {
  if (!otherEvent.value) return
  const keep = attendee.value
  selectedEventId.value = otherEvent.value.id
  nextTick(() => { attendee.value = keep })
}

// ----- Entrance QR -----
const qrPanel = ref(null)
const qrNotice = ref('')
const regenConfirm = ref(false)
const regenerating = ref(false)
const regenError = ref('')
// Participants open this on their own phones, so it must be a public address — never localhost.
const configuredBase = (import.meta.env.VITE_PUBLIC_APP_URL || '').trim().replace(/\/+$/, '')
const publicBase = configuredBase || window.location.origin
const localHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', '::1']
const baseIsLocal = (() => { try { return localHosts.includes(new URL(publicBase).hostname) } catch { return true } })()
const entranceToken = computed(() => desk.value?.event?.check_in_qr_token || '')
const entranceUrl = computed(() => entranceToken.value ? `${publicBase}/check-in?e=${encodeURIComponent(entranceToken.value)}` : '')
const flashQrNotice = (message) => {
  qrNotice.value = message
  setTimeout(() => { if (qrNotice.value === message) qrNotice.value = '' }, 2500)
}
const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(entranceUrl.value)
    flashQrNotice('Link copied')
  } catch {
    flashQrNotice('Copy failed — select the link and copy it manually')
  }
}
const qrDataUrl = () => qrPanel.value?.querySelector('canvas')?.toDataURL('image/png') || ''
const fileSafe = (text) => String(text || 'event').replace(/[\\/:*?"<>|]+/g, '').trim() || 'event'
const downloadQr = () => {
  const data = qrDataUrl()
  if (!data) return
  const anchor = document.createElement('a')
  anchor.download = `${fileSafe(selectedEvent.value?.name)}-entrance-qr.png`
  anchor.href = data
  anchor.click()
}
const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]))
const printQr = () => {
  const data = qrDataUrl()
  const win = data && window.open('', '_blank', 'width=720,height=900')
  if (!win) { flashQrNotice('Allow pop-ups to print, or use Download instead'); return }
  const event = selectedEvent.value || {}
  win.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(event.name)} — Entrance check-in</title><style>body{font-family:system-ui,sans-serif;text-align:center;color:#0f1c2e;padding:48px}h1{font-size:30px;margin:0 0 6px}p{margin:4px 0;color:#33475c;font-size:17px}img{width:340px;height:340px;margin:28px auto}ol{display:inline-block;text-align:left;font-size:17px;line-height:1.7}small{display:block;margin-top:24px;color:#5f7286}</style></head><body><h1>${escapeHtml(event.name)}</h1><p>${escapeHtml(dateTime(event.starts_at))}${event.location ? ' · ' + escapeHtml(event.location) : ''}</p><img src="${data}" alt="Entrance check-in QR code"><h2>Check yourself in</h2><ol><li>Scan this code with your phone camera.</li><li>Sign in with the account you registered with.</li><li>Tap “Confirm check-in”.</li></ol><small>Need help? Ask the registration desk.</small></body></html>`)
  win.document.close()
  win.focus()
  // Give the inlined image a moment to decode before opening the print dialog.
  setTimeout(() => { try { win.print() } catch { /* window closed */ } }, 400)
}
const regenerate = async () => {
  if (!selectedEventId.value || regenerating.value) return
  regenerating.value = true
  regenError.value = ''
  try {
    await regenerateEventCheckInQr(selectedEventId.value)
    regenConfirm.value = false
    await loadDesk({ silent: true })
    flashQrNotice('New entrance QR created — reprint any posters')
  } catch (error) {
    regenError.value = error?.response?.status === 403 ? 'Only the event’s organizer can replace its entrance QR.' : 'We couldn’t create a new QR. Please try again.'
  } finally {
    regenerating.value = false
  }
}

// ----- Log export -----
const exportLog = () => {
  const header = ['Attendee', 'Registration code', 'Email', 'Phone', 'Checked in at', 'Checked in by']
  const rows = recentCheckIns.value.map((item) => [item.name, item.registration_code, item.email, item.phone, item.checked_in_at ? new Date(item.checked_in_at).toLocaleString() : '', item.checked_in_by])
  const blob = new Blob(['﻿' + toCsv(header, rows)], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${fileSafe(selectedEvent.value?.name)}-check-ins-${new Date().toISOString().slice(0, 10)}.csv`
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

// ----- Formatting -----
const dateTime = (value) => value ? new Date(value).toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Date to be announced'
const clock = (value) => new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
const shortTime = (value) => {
  if (!value) return ''
  const date = new Date(value)
  return date.toDateString() === new Date().toDateString() ? clock(value) : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
const scheduleLabel = (event) => {
  if (!event?.starts_at) return 'Date to be announced'
  const starts = new Date(event.starts_at)
  const ends = event.ends_at ? new Date(event.ends_at) : null
  const day = starts.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
  if (!ends) return `${day} · ${clock(starts)}`
  return starts.toDateString() === ends.toDateString()
    ? `${day} · ${clock(starts)} – ${clock(ends)}`
    : `${day} · ${clock(starts)} – ${ends.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}, ${clock(ends)}`
}

onMounted(async () => {
  store.reset()
  await loadEvents()
})
onBeforeUnmount(() => { cameraOn.value = false })
</script>

<template>
  <AdminLayout>
    <section class="ck-page">
      <header class="ck-header">
        <div>
          <h1>Participant Check-in</h1>
          <p>Verify registrations and check in participants quickly.</p>
        </div>
        <span v-if="phase === 'ongoing'" class="ck-live"><i aria-hidden="true" />Event in progress</span>
      </header>

      <p v-if="eventsError" class="ck-alert ck-alert--error" role="alert">
        <AppIcon name="alert-circle" :size="18" /><span>{{ eventsError }}</span>
        <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" :disabled="eventsLoading" @click="loadEvents">Try again</button>
      </p>

      <!-- 1. Event overview -->
      <section class="ck-card ck-event" aria-labelledby="ck-event-label">
        <div class="ck-event__pick">
          <label id="ck-event-label" for="ck-event-select" class="ck-label"><span class="ck-step">1</span>Event</label>
          <div class="ck-select">
            <select id="ck-event-select" v-model="selectedEventId" :disabled="eventsLoading || !events.length">
              <option v-if="eventsLoading" value="">Loading events…</option>
              <option v-else-if="!events.length" value="">No events yet</option>
              <option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option>
            </select>
            <AppIcon name="chevron-down" :size="18" />
          </div>
          <div v-if="selectedEvent" class="ck-event__meta">
            <span class="ck-badge" :class="`ck-badge--${phase}`">{{ phaseLabels[phase] }}</span>
            <span class="ck-meta"><AppIcon name="calendar" :size="15" />{{ scheduleLabel(selectedEvent) }}</span>
            <span class="ck-meta"><AppIcon name="pin" :size="15" />{{ selectedEvent.location || 'Location to be announced' }}</span>
          </div>
          <p v-else-if="!eventsLoading && !events.length" class="ck-muted">Create an event first — <RouterLink to="/admin/events/new">new event</RouterLink>.</p>
        </div>

        <div class="ck-stats" :aria-busy="deskLoading">
          <div class="ck-stat"><span>Registered</span><strong>{{ deskLoading || !summary ? '—' : registeredCount }}</strong></div>
          <div class="ck-stat ck-stat--in"><span>Checked in</span><strong>{{ deskLoading || !summary ? '—' : checkedInCount }}</strong></div>
          <div class="ck-stat ck-stat--wait"><span>Remaining</span><strong>{{ deskLoading || !summary ? '—' : remainingCount }}</strong></div>
          <div class="ck-stat"><span>Attendance</span><strong>{{ deskLoading || !summary ? '—' : `${checkInRate}%` }}</strong>
            <span class="ck-progress" role="progressbar" aria-label="Attendance" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="checkInRate"><span :style="{ width: `${checkInRate}%` }" /></span>
          </div>
        </div>
        <p v-if="deskError" class="ck-alert ck-alert--error ck-event__error" role="alert">
          <AppIcon name="alert-circle" :size="18" /><span>{{ deskError }}</span>
          <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" @click="loadDesk()">Try again</button>
        </p>
        <p v-else-if="summary && !registeredCount" class="ck-alert ck-alert--info ck-event__error">
          <AppIcon name="info" :size="18" /><span>No one has registered for this event yet, so there’s no one to check in.</span>
        </p>
      </section>

      <div class="ck-grid">
        <!-- 2–4. Check-in workspace -->
        <section class="ck-card ck-work" aria-labelledby="ck-work-title">
          <header class="ck-work__head">
            <h2 id="ck-work-title"><span class="ck-step">2</span>Find attendee</h2>
            <div class="ck-modes" role="group" aria-label="How to find the attendee">
              <button v-for="item in modes" :key="item.value" type="button" :aria-pressed="mode === item.value" :class="{ 'is-active': mode === item.value }" :disabled="!selectedEventId" @click="setMode(item.value)">
                <AppIcon :name="item.icon" :size="17" />{{ item.label }}
              </button>
            </div>
          </header>

          <form v-if="mode === 'search'" class="ck-find" @submit.prevent="runSearch">
            <label for="ck-search" class="ck-label">Name, registration code, email or phone</label>
            <div class="ck-find__row">
              <div class="ck-input"><AppIcon name="search" :size="18" /><input id="ck-search" ref="searchInput" v-model="searchQuery" type="search" autocomplete="off" placeholder="e.g. Sokha Leng, CTS-004 or 012 345 678" :disabled="!selectedEventId" /></div>
              <button type="submit" class="ck-btn ck-btn--primary" :disabled="busy || !selectedEventId || !searchQuery.trim()">{{ busy ? 'Searching…' : 'Search' }}</button>
            </div>
            <p class="ck-hint">Only attendees registered for <b>{{ selectedEvent?.name || 'the selected event' }}</b> are searched.</p>
          </form>

          <div v-else-if="mode === 'scan'" class="ck-scan">
            <div v-if="cameraOn" class="ck-camera">
              <QRScanner :key="cameraKey" @scan="handleScan" @error="handleCameraError" />
              <p class="ck-camera__hint" aria-live="polite">Hold the attendee’s pass QR inside the frame</p>
            </div>
            <div v-else class="ck-camera ck-camera--off">
              <AppIcon name="qr-code" :size="34" />
              <p>{{ busy ? 'Checking the scanned code…' : 'Scan the QR code on the attendee’s personal registration pass.' }}</p>
              <button type="button" class="ck-btn ck-btn--primary" :disabled="busy" @click="startCamera"><AppIcon name="scan" :size="17" />{{ attendee || lookupError ? 'Scan another pass' : 'Start camera' }}</button>
            </div>
            <div class="ck-scan__foot">
              <button v-if="cameraOn" type="button" class="ck-btn ck-btn--ghost ck-btn--sm" @click="stopCamera">Stop camera</button>
              <span class="ck-hint">Camera not working? <button type="button" class="ck-link" @click="setMode('code')">Enter the code instead</button></span>
            </div>
            <p v-if="cameraError" class="ck-alert ck-alert--error" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ cameraError }}</span></p>
          </div>

          <form v-else class="ck-find" @submit.prevent="submitCode">
            <label for="ck-code" class="ck-label">Registration code or QR token</label>
            <div class="ck-find__row">
              <div class="ck-input"><AppIcon name="hash" :size="18" /><input id="ck-code" ref="codeInput" v-model="manualCode" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="e.g. CTS-004 or the token under the QR" :disabled="!selectedEventId" /></div>
              <button type="submit" class="ck-btn ck-btn--primary" :disabled="busy || !manualCode.trim()">{{ busy ? 'Checking…' : 'Look up' }}</button>
            </div>
            <p class="ck-hint">Use this when a pass can’t be scanned — type the code printed on it.</p>
          </form>

          <!-- Results -->
          <div class="ck-result" aria-live="polite">
            <p v-if="busy && mode !== 'scan'" class="ck-loading"><span class="ck-spinner" aria-hidden="true" />Looking up attendee…</p>

            <div v-if="lookupError" class="ck-alert ck-alert--error ck-alert--block" role="alert">
              <AppIcon name="alert-circle" :size="20" />
              <div><strong>{{ lookupError.title }}</strong><p>{{ lookupError.message }}</p></div>
              <button v-if="lookupError.action" type="button" class="ck-btn ck-btn--ghost ck-btn--sm" @click="lookupError.action()"><AppIcon name="refresh" :size="16" />Try again</button>
            </div>

            <div v-if="suggestions.length" class="ck-suggest">
              <p class="ck-label">{{ suggestions.length }} people match — choose one</p>
              <ul>
                <li v-for="item in suggestions" :key="item.id">
                  <button type="button" class="ck-suggest__item" :disabled="busy" @click="pickSuggestion(item)">
                    <span class="ck-avatar" aria-hidden="true">{{ initials(item.full_name) }}</span>
                    <span class="ck-suggest__text"><strong>{{ item.full_name || 'Unnamed attendee' }}</strong><small>{{ [item.registration_code, item.email, item.phone].filter(Boolean).join(' · ') }}</small></span>
                    <span v-if="item.checked_in_at" class="ck-badge ck-badge--in">Checked in</span>
                    <span v-else class="ck-badge ck-badge--wait">Not checked in</span>
                    <AppIcon name="chevron-right" :size="18" />
                  </button>
                </li>
              </ul>
            </div>

            <article v-if="attendee" class="ck-person" :class="`is-${state}`" aria-labelledby="ck-person-name">
              <div v-if="success" class="ck-success" role="status">
                <AppIcon name="check-circle" :size="22" />
                <div><strong>{{ success.name }} is checked in</strong><p>Recorded at {{ clock(success.at) }}.</p></div>
              </div>

              <header class="ck-person__head">
                <span class="ck-photo"><img v-if="photoUrl" :src="photoUrl" alt="" @error="photoFailed = true" /><b v-else>{{ initials(attendee.full_name) }}</b></span>
                <div class="ck-person__id">
                  <h3 id="ck-person-name">{{ attendee.full_name || 'Unnamed attendee' }}</h3>
                  <p v-if="attendee.registration_code" class="ck-code">{{ attendee.registration_code }}</p>
                  <div class="ck-person__badges">
                    <span class="ck-badge ck-badge--neutral">{{ statusLabel(attendee.status) }}</span>
                    <span v-if="attendee.checked_in_at" class="ck-badge ck-badge--in"><AppIcon name="check" :size="13" />Checked in</span>
                    <span v-else class="ck-badge ck-badge--wait">Not checked in</span>
                  </div>
                </div>
              </header>

              <dl class="ck-facts">
                <div><dt>Event</dt><dd>{{ attendee.event_name || otherEvent?.name || selectedEvent?.name }}</dd></div>
                <div v-for="[label, value] in details" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div>
              </dl>
              <details v-if="answers.length" class="ck-answers">
                <summary>Registration answers ({{ answers.length }})</summary>
                <dl><div v-for="answer in answers" :key="answer.label"><dt>{{ answer.label }}</dt><dd>{{ answer.value }}</dd></div></dl>
              </details>

              <!-- Step 4 -->
              <footer class="ck-person__foot">
                <template v-if="state === 'ready'">
                  <p v-if="timingNote" class="ck-alert ck-alert--warn"><AppIcon name="info" :size="18" /><span>{{ timingNote }}</span></p>
                  <div class="ck-actions">
                    <button ref="confirmButton" type="button" class="ck-btn ck-btn--primary ck-btn--lg" :disabled="store.checkingIn" :aria-busy="store.checkingIn" @click="confirmCheckIn">
                      <span v-if="store.checkingIn" class="ck-spinner ck-spinner--light" aria-hidden="true" /><AppIcon v-else name="user-check" :size="19" />{{ store.checkingIn ? 'Checking in…' : 'Confirm Check-in' }}
                    </button>
                    <button type="button" class="ck-btn ck-btn--ghost ck-btn--lg" :disabled="store.checkingIn" @click="nextAttendee">Cancel</button>
                  </div>
                </template>
                <template v-else-if="state === 'checked-in'">
                  <p v-if="!success" class="ck-alert ck-alert--ok"><AppIcon name="check-circle" :size="18" /><span>Already checked in {{ shortTime(attendee.checked_in_at) }}. No need to check in again.</span></p>
                  <button ref="nextButton" type="button" class="ck-btn ck-btn--primary ck-btn--lg ck-btn--block" @click="nextAttendee"><AppIcon :name="mode === 'scan' ? 'scan' : 'arrow-right'" :size="18" />{{ mode === 'scan' ? 'Scan next attendee' : 'Next attendee' }}</button>
                </template>
                <template v-else-if="state === 'wrong-event'">
                  <p class="ck-alert ck-alert--error"><AppIcon name="alert-circle" :size="18" /><span>This pass is for <b>{{ attendee.event_name || 'another event' }}</b>, not {{ selectedEvent?.name }}. They can’t be checked in here.</span></p>
                  <div class="ck-actions">
                    <button v-if="otherEvent" type="button" class="ck-btn ck-btn--ghost ck-btn--lg" @click="switchToOtherEvent">Switch to that event</button>
                    <button type="button" class="ck-btn ck-btn--primary ck-btn--lg" @click="nextAttendee">Next attendee</button>
                  </div>
                </template>
                <template v-else>
                  <p class="ck-alert ck-alert--error"><AppIcon name="alert-circle" :size="18" /><span>{{ ineligibleReason }}</span></p>
                  <button type="button" class="ck-btn ck-btn--primary ck-btn--lg ck-btn--block" @click="nextAttendee">Next attendee</button>
                </template>
              </footer>
            </article>

            <div v-if="!busy && !attendee && !suggestions.length && !lookupError && mode !== 'scan'" class="ck-empty">
              <AppIcon name="user-check" :size="26" />
              <p>Find an attendee to verify their registration and check them in.</p>
            </div>
          </div>
        </section>

        <div class="ck-side">
          <!-- Entrance QR -->
          <section ref="qrPanel" class="ck-card ck-qr" aria-labelledby="ck-qr-title">
            <header>
              <h2 id="ck-qr-title">Entrance QR</h2>
              <span class="ck-badge ck-badge--neutral">Self check-in</span>
            </header>
            <p class="ck-qr__lead">One shared code for the door — participants scan it with their own phone. It is <b>not</b> a personal pass and contains no attendee data.</p>

            <div v-if="deskLoading" class="ck-qr__box ck-skeleton" />
            <div v-else-if="!selectedEventId || deskError" class="ck-qr__box ck-qr__box--empty"><p>Select an event to see its entrance QR.</p></div>
            <div v-else-if="!entranceToken" class="ck-qr__box ck-qr__box--empty">
              <p>This event doesn’t have an entrance QR yet.</p>
              <button type="button" class="ck-btn ck-btn--primary ck-btn--sm" :disabled="regenerating" @click="regenerate">{{ regenerating ? 'Creating…' : 'Create entrance QR' }}</button>
            </div>
            <template v-else>
              <div class="ck-qr__box"><QRCodeDisplay :value="entranceUrl" /></div>
              <p class="ck-qr__url" :title="entranceUrl">{{ entranceUrl }}</p>
              <div v-if="baseIsLocal" class="ck-qr__setup" role="note">
                <AppIcon name="alert-circle" :size="20" />
                <div>
                  <strong>Test mode — this link only works on this computer</strong>
                  <p>Phones can’t open <code>{{ publicBase }}</code>. To test, open the link here while signed in as a participant. Before printing, set <code>VITE_PUBLIC_APP_URL</code> in <code>frontend/.env</code> to your site’s public address and rebuild.</p>
                  <a class="ck-btn ck-btn--ghost ck-btn--sm ck-qr__try" :href="entranceUrl" target="_blank" rel="noopener"><AppIcon name="link" :size="16" />Open check-in link</a>
                </div>
              </div>
              <div class="ck-qr__actions">
                <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" @click="printQr"><AppIcon name="file-text" :size="16" />Print</button>
                <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" @click="downloadQr"><AppIcon name="download" :size="16" />Download</button>
                <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" @click="copyLink"><AppIcon name="copy" :size="16" />Copy link</button>
              </div>
              <ol class="ck-qr__steps">
                <li>Print it and place it at the entrance.</li>
                <li>Participants scan it with their phone camera.</li>
                <li>They sign in with the account they registered with and tap “Check me in”. Without scanning this code, participants can’t check themselves in.</li>
              </ol>
              <div class="ck-qr__regen">
                <template v-if="!regenConfirm">
                  <button type="button" class="ck-link" @click="regenConfirm = true">Replace this QR code…</button>
                </template>
                <div v-else class="ck-alert ck-alert--warn ck-alert--block">
                  <AppIcon name="alert-circle" :size="18" />
                  <div><strong>Replace the entrance QR?</strong><p>Printed copies of the current code will stop working.</p>
                    <div class="ck-actions">
                      <button type="button" class="ck-btn ck-btn--primary ck-btn--sm" :disabled="regenerating" @click="regenerate">{{ regenerating ? 'Replacing…' : 'Replace QR' }}</button>
                      <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" :disabled="regenerating" @click="regenConfirm = false">Keep current</button>
                    </div>
                  </div>
                </div>
              </div>
            </template>
            <p v-if="regenError" class="ck-alert ck-alert--error" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ regenError }}</span></p>
            <p class="ck-qr__notice" role="status">{{ qrNotice }}</p>
          </section>

          <!-- Recent check-ins -->
          <section class="ck-card ck-log" aria-labelledby="ck-log-title">
            <header>
              <h2 id="ck-log-title">Recent check-ins</h2>
              <button type="button" class="ck-btn ck-btn--ghost ck-btn--sm" :disabled="!recentCheckIns.length" @click="exportLog"><AppIcon name="download" :size="16" />Export</button>
            </header>
            <p v-if="deskLoading" class="ck-muted">Loading…</p>
            <p v-else-if="!recentCheckIns.length" class="ck-muted">No one has checked in to this event yet.</p>
            <ul v-else>
              <li v-for="item in visibleRecent" :key="item.id">
                <span class="ck-avatar ck-avatar--sm" aria-hidden="true">{{ initials(item.name) }}</span>
                <span class="ck-log__text"><strong>{{ item.name || 'Unnamed attendee' }}</strong><small>{{ item.registration_code }}<template v-if="item.checked_in_by"> · by {{ item.checked_in_by }}</template></small></span>
                <time :datetime="item.checked_in_at">{{ shortTime(item.checked_in_at) }}</time>
              </li>
            </ul>
            <div class="ck-log__foot">
              <button v-if="recentCheckIns.length > 6" type="button" class="ck-link" @click="showAllRecent = !showAllRecent">{{ showAllRecent ? 'Show fewer' : `Show all ${recentCheckIns.length}` }}</button>
              <RouterLink v-if="selectedEventId" :to="{ path: '/admin/users', query: { event: selectedEventId } }" class="ck-link">Full attendee list<AppIcon name="arrow-up-right" :size="15" /></RouterLink>
            </div>
          </section>
        </div>
      </div>
    </section>
  </AdminLayout>
</template>
