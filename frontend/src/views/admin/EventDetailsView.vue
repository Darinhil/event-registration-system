<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import api from '../../services/api'
import { getEventCheckInSummary, getEventReportAttendees } from '../../services/adminService'
import { copyText } from '../../utils/clipboard'
import { capacityProgress, checkInProgress, publicationStatus, registrationState, scheduleLabels, toCsv, toLocalParts } from '../../utils/eventStats'

const route = useRoute()
const eventId = computed(() => route.params.id)
const loading = ref(true)
const loadError = ref('')
const event = ref(null)
const registered = ref(0)
const checkedIn = ref(null) // null = unknown (summary failed to load)

const loadEvent = async () => {
  loading.value = true; loadError.value = ''
  const [eventResult, summaryResult] = await Promise.allSettled([api.get(`/events/${eventId.value}`), getEventCheckInSummary(eventId.value)])
  if (eventResult.status === 'rejected') {
    const status = eventResult.reason?.response?.status
    loadError.value = status === 404 ? 'This event doesn’t exist or was deleted.' : status === 403 ? 'You don’t have access to this event.' : 'We couldn’t load this event. Check your connection and try again.'
    loading.value = false
    return
  }
  const { data } = eventResult.value
  event.value = data.data
  registered.value = Number(data.registered || 0)
  checkedIn.value = summaryResult.status === 'fulfilled' ? Number(summaryResult.value.data?.data?.summary?.checked_in || 0) : null
  loading.value = false
}
onMounted(loadEvent)

/* ---------- Derived display data ---------- */
const settings = computed(() => event.value?.form_config?.settings || {})
const status = computed(() => publicationStatus(event.value?.status))
const isDraft = computed(() => event.value?.status === 'draft')
const schedule = computed(() => scheduleLabels(event.value?.starts_at, event.value?.ends_at))
const capacity = computed(() => capacityProgress(registered.value, settings.value.max_participants ?? event.value?.capacity))
const checkIns = computed(() => checkInProgress(checkedIn.value ?? 0, registered.value))
const registration = computed(() => registrationState({ status: event.value?.status, settings: settings.value, registered: registered.value, capacity: event.value?.capacity, endsAt: event.value?.ends_at }))
const formatDay = (value) => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''
const registrationWindow = computed(() => {
  const opens = settings.value.registration_start ? formatDay(settings.value.registration_start) : 'When published'
  const closes = settings.value.registration_end ? formatDay(settings.value.registration_end) : 'No deadline'
  return `${opens} → ${closes}`
})
const initials = computed(() => String(event.value?.name || 'Event').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase())
const banner = computed(() => event.value?.branding?.image || '')
const link = computed(() => `${window.location.origin}/events/${eventId.value}`)
const number = (value) => Number(value || 0).toLocaleString()

/* ---------- Feedback ---------- */
const toast = ref(null)
let toastTimer = null
const notify = (message, tone = 'ok') => {
  clearTimeout(toastTimer)
  toast.value = { message, tone }
  toastTimer = setTimeout(() => { toast.value = null }, 3200)
}
onBeforeUnmount(() => clearTimeout(toastTimer))

/* ---------- Sharing ---------- */
const copied = ref(false)
const copyLink = async () => {
  if (await copyText(link.value)) {
    copied.value = true
    notify('Registration link copied.')
    setTimeout(() => { copied.value = false }, 1800)
  } else {
    notify('Copy failed — select the link and copy it manually.', 'error')
  }
}
const downloadQr = () => {
  const canvas = document.querySelector('.ed-qr canvas')
  if (!canvas) return notify('The QR code isn’t ready yet. Try again in a moment.', 'error')
  const anchor = document.createElement('a')
  anchor.download = `${(event.value?.name || 'event').replace(/[^\w\- ]+/g, '').trim() || 'event'}-registration-qr.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
  notify('QR code downloaded.')
}
const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'
const shareEmailHref = computed(() => `mailto:?subject=${encodeURIComponent(`Register for ${event.value?.name || 'our event'}`)}&body=${encodeURIComponent(`${event.value?.name || 'Event'}\n${schedule.value.date} · ${schedule.value.time}\n\nRegister here: ${link.value}`)}`)
const shareEvent = async () => {
  try {
    await navigator.share({ title: event.value?.name, text: `Register for ${event.value?.name}`, url: link.value })
  } catch (shareError) {
    // Closing the share sheet is not an error worth reporting.
    if (shareError?.name !== 'AbortError') notify('Sharing isn’t available here. Copy the link instead.', 'error')
  }
}

/* ---------- Export ---------- */
const exporting = ref(false)
const exportRegistrants = async () => {
  if (exporting.value) return
  exporting.value = true
  try {
    // The report endpoint returns every registrant (the registrants list is paginated).
    const { data } = await getEventReportAttendees(eventId.value)
    const attendees = data?.data?.attendees || data?.data?.data || []
    if (!attendees.length) { notify('There are no registrants to export yet.', 'error'); return }
    const answerLabels = [...new Set(attendees.flatMap((row) => Object.keys(row.form_answers || {})))]
    const profileKeys = ['organization', 'position', 'gender', 'age']
    const header = ['Registration ID', 'Name', 'Email', 'Phone', 'Status', 'Checked in at', 'Checked in by', ...profileKeys.map((key) => key[0].toUpperCase() + key.slice(1)), ...answerLabels]
    const rows = attendees.map((row) => {
      const checkedAt = toLocalParts(row.checked_in_at)
      return [row.registration_code || row.id, row.name, row.email, row.phone, row.status, checkedAt.value ? `${checkedAt.date} ${checkedAt.time}` : '', row.checked_in_by, ...profileKeys.map((key) => row.profile?.[key]), ...answerLabels.map((label) => { const value = row.form_answers?.[label]; return Array.isArray(value) ? value.join('; ') : value })]
    })
    // BOM so Excel opens Khmer and other non-Latin text correctly.
    const blob = new Blob(['﻿', toCsv(header, rows)], { type: 'text/csv;charset=utf-8' })
    const anchor = document.createElement('a')
    anchor.href = URL.createObjectURL(blob)
    anchor.download = `${(event.value?.name || 'event').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').toLowerCase() || 'event'}-registrants-${toLocalParts(new Date().toISOString()).date}.csv`
    anchor.click()
    setTimeout(() => URL.revokeObjectURL(anchor.href), 1000)
    notify(`Exported ${attendees.length} ${attendees.length === 1 ? 'registrant' : 'registrants'}.`)
  } catch (requestError) {
    notify(requestError.response?.data?.message || 'Export failed. Please try again.', 'error')
  } finally {
    exporting.value = false
  }
}

/* ---------- Status changes (close / cancel) ---------- */
const pendingAction = ref(null)
const statusBusy = ref(false)
const statusActions = {
  close: { title: 'Close this event?', body: 'New registrations stop and the event is marked as closed. Existing registrations and check-ins are kept.', confirm: 'Close event', done: 'Event closed.', status: 'closed' },
  cancel: { title: 'Cancel this event?', body: 'The event is marked as cancelled and attendees can no longer register. Registrations stay in the system. This can’t be undone from this page.', confirm: 'Cancel event', done: 'Event cancelled.', status: 'cancelled' },
}
const askStatusChange = (action) => { pendingAction.value = action; nextTick(() => document.getElementById('ed-dialog-cancel')?.focus()) }
const confirmStatusChange = async () => {
  const action = pendingAction.value
  if (!action || statusBusy.value) return
  statusBusy.value = true
  try {
    await api.patch(`/admin/events/${eventId.value}/${action}`)
    event.value = { ...event.value, status: statusActions[action].status }
    try {
      const list = JSON.parse(localStorage.getItem('event_list') || '[]')
      const item = list.find((entry) => String(entry.id) === String(eventId.value))
      if (item) { item.status = event.value.status; localStorage.setItem('event_list', JSON.stringify(list)) }
    } catch { /* cache is optional */ }
    pendingAction.value = null
    notify(statusActions[action].done)
  } catch (requestError) {
    pendingAction.value = null
    notify(requestError.response?.data?.message || 'That didn’t work. Check your connection and try again.', 'error')
  } finally {
    statusBusy.value = false
  }
}
const onKeydown = (keyEvent) => { if (keyEvent.key === 'Escape' && pendingAction.value && !statusBusy.value) pendingAction.value = null }
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

/* ---------- Management actions ---------- */
const actionGroups = computed(() => [
  { key: 'event', label: 'Event', actions: [
    { key: 'preview', label: 'Preview event page', description: isDraft.value ? 'See it as attendees will once published' : 'Open the page attendees see', icon: 'eye', href: link.value },
    { key: 'edit', label: 'Edit event', description: 'Details, schedule, agenda and form', icon: 'pen', to: `/admin/events/${eventId.value}/edit` },
  ] },
  { key: 'registration', label: 'Registration', actions: [
    { key: 'form', label: 'Manage registration form', description: 'Advanced field and step settings', icon: 'file-text', to: `/admin/events/${eventId.value}/form-builder` },
    { key: 'registrants', label: 'View registrants', description: registered.value ? 'Review everyone who signed up' : 'No one has registered yet', icon: 'users', to: `/admin/events/${eventId.value}/registrants`, badge: number(registered.value) },
  ] },
  { key: 'attendance', label: 'Attendance', actions: [
    { key: 'checkin', label: 'Check in participants', description: 'Scan passes or search by code', icon: 'scan', to: { path: '/admin/check-ins', query: { event: eventId.value } } },
    { key: 'export', label: exporting.value ? 'Preparing export…' : 'Export registrants', description: registered.value ? 'Download a CSV of all registrants' : 'Available once people register', icon: 'arrow-down', handler: exportRegistrants, disabled: !registered.value || exporting.value },
  ] },
])
</script>

<template>
  <AdminLayout>
    <section class="ed-page" aria-labelledby="ed-title">
      <!-- Loading -->
      <div v-if="loading" class="ed-loading" role="status" aria-live="polite">
        <span class="ed-skel ed-skel--crumb"></span>
        <span class="ed-skel ed-skel--title"></span>
        <div class="ed-layout"><div class="ed-main"><span class="ed-skel ed-skel--card"></span><span class="ed-skel ed-skel--card"></span></div><span class="ed-skel ed-skel--side"></span></div>
        <span class="sr-only">Loading event…</span>
      </div>

      <!-- Error -->
      <div v-else-if="loadError" class="ed-card ed-state" role="alert">
        <span class="ed-state__icon"><AppIcon name="alert-circle" :size="26" /></span>
        <h1 id="ed-title">{{ loadError }}</h1>
        <div class="ed-state__actions"><button type="button" class="es-btn" @click="loadEvent"><AppIcon name="refresh" :size="16" />Try again</button><RouterLink class="es-btn es-btn--primary" to="/admin/events">Back to events</RouterLink></div>
      </div>

      <template v-else>
        <nav class="ed-crumbs" aria-label="Breadcrumb"><RouterLink to="/admin/events"><AppIcon name="chevron-left" :size="16" />All events</RouterLink></nav>

        <!-- A. Header -->
        <header class="ed-header">
          <div class="ed-header__main">
            <span class="ed-thumb" aria-hidden="true"><img v-if="banner" :src="banner" alt="" /><template v-else>{{ initials }}</template></span>
            <div class="ed-header__copy">
              <div class="ed-title-row">
                <h1 id="ed-title">{{ event.name }}</h1>
                <span class="ed-badge" :class="`is-${status.tone}`"><i aria-hidden="true"></i>{{ status.label }}</span>
              </div>
              <p v-if="event.description" class="ed-desc">{{ event.description }}</p>
              <p class="ed-meta"><span><AppIcon name="calendar" :size="15" />{{ schedule.date }}</span><span><AppIcon name="pin" :size="15" />{{ event.location || 'Location to be confirmed' }}</span><span v-if="event.category"><AppIcon name="type" :size="15" />{{ event.category }}</span></p>
            </div>
          </div>
          <div class="ed-header__actions">
            <a class="es-btn" :href="link" target="_blank" rel="noreferrer"><AppIcon name="eye" :size="16" />Preview<span class="sr-only"> (opens in a new tab)</span></a>
            <RouterLink class="es-btn es-btn--primary" :to="`/admin/events/${event.id}/edit`"><AppIcon name="pen" :size="16" />Edit event</RouterLink>
          </div>
        </header>

        <div v-if="isDraft" class="ed-notice" role="status"><AppIcon name="info" :size="18" /><span><strong>This event is a draft.</strong> Attendees can’t see it or register until you publish it.</span><RouterLink class="es-btn es-btn--sm es-btn--primary" :to="{ path: `/admin/events/${event.id}/edit`, query: { step: 4 } }">Review &amp; publish</RouterLink></div>
        <div v-else-if="event.status === 'cancelled' || event.status === 'closed'" class="ed-notice is-muted" role="status"><AppIcon name="info" :size="18" /><span>{{ status.hint }} Registrations and check-ins are kept for your records.</span></div>

        <div class="ed-layout">
          <div class="ed-main">
            <!-- B. Registration overview -->
            <section class="ed-card" aria-labelledby="ed-overview">
              <header class="ed-card__head">
                <h2 id="ed-overview">Registration overview</h2>
                <span class="ed-reg" :class="`is-${registration.tone}`"><i aria-hidden="true"></i>Registration: {{ registration.label }}</span>
              </header>
              <dl class="ed-metrics">
                <div><dt>Registered</dt><dd><strong>{{ number(capacity.registered) }}</strong><small v-if="capacity.capacity">of {{ number(capacity.capacity) }} spots</small><small v-else>No capacity limit</small></dd></div>
                <div><dt>Checked in</dt><dd><strong>{{ checkedIn === null ? '—' : number(checkIns.checkedIn) }}</strong><small v-if="checkedIn === null">Couldn’t load check-ins</small><small v-else-if="checkIns.percent !== null">{{ checkIns.percent }}% of registered</small><small v-else>No registrations yet</small></dd></div>
                <div><dt>Spots left</dt><dd><strong>{{ capacity.capacity ? number(capacity.remaining) : '—' }}</strong><small v-if="capacity.over">{{ number(capacity.registered - capacity.capacity) }} over capacity</small><small v-else-if="capacity.full">Event is full</small><small v-else-if="capacity.capacity">{{ 100 - capacity.bar }}% available</small><small v-else>Unlimited</small></dd></div>
              </dl>
              <div class="ed-bars">
                <div class="ed-bar" :class="{ 'is-high': capacity.bar >= 80 && !capacity.full, 'is-full': capacity.full }">
                  <div class="ed-bar__label"><span>Capacity used</span><b>{{ capacity.percent === null ? 'No limit' : `${capacity.percent}%` }}</b></div>
                  <div class="ed-bar__track" role="progressbar" aria-label="Capacity used" :aria-valuenow="capacity.bar" aria-valuemin="0" aria-valuemax="100" :aria-valuetext="capacity.percent === null ? 'No capacity limit' : `${capacity.registered} of ${capacity.capacity} spots, ${capacity.percent}%`"><i :style="{ width: `${capacity.bar}%` }"></i></div>
                </div>
                <div class="ed-bar is-checkin">
                  <div class="ed-bar__label"><span>Checked in</span><b>{{ checkedIn === null ? '—' : checkIns.percent === null ? '0%' : `${checkIns.percent}%` }}</b></div>
                  <div class="ed-bar__track" role="progressbar" aria-label="Check-in progress" :aria-valuenow="checkIns.bar" aria-valuemin="0" aria-valuemax="100" :aria-valuetext="`${checkIns.checkedIn} of ${checkIns.registered} registered attendees checked in`"><i :style="{ width: `${checkIns.bar}%` }"></i></div>
                </div>
              </div>
              <div v-if="!registered && !isDraft" class="ed-empty">
                <AppIcon name="users" :size="18" />
                <span>No registrations yet. Share the registration link to start collecting sign-ups.</span>
                <button type="button" class="es-btn es-btn--sm" @click="copyLink"><AppIcon :name="copied ? 'check' : 'copy'" :size="15" />{{ copied ? 'Copied' : 'Copy link' }}</button>
              </div>
            </section>

            <!-- C. Event details -->
            <section class="ed-card" aria-labelledby="ed-details">
              <header class="ed-card__head"><h2 id="ed-details">Event details</h2><RouterLink class="es-link" :to="`/admin/events/${event.id}/edit`"><AppIcon name="pen" :size="14" />Edit</RouterLink></header>
              <dl class="ed-dl">
                <div><dt><AppIcon name="calendar" :size="16" />Date</dt><dd>{{ schedule.date }}</dd></div>
                <div><dt><AppIcon name="clock" :size="16" />Time</dt><dd>{{ schedule.time }}</dd></div>
                <div><dt><AppIcon name="pin" :size="16" />Location</dt><dd>{{ event.location || 'To be confirmed' }}</dd></div>
                <div><dt><AppIcon name="type" :size="16" />Category</dt><dd>{{ event.category || 'Not set' }}</dd></div>
                <div><dt><AppIcon name="check-circle" :size="16" />Registration</dt><dd><span class="ed-reg" :class="`is-${registration.tone}`"><i aria-hidden="true"></i>{{ registration.label }}</span><small>{{ registration.detail }}</small></dd></div>
                <div><dt><AppIcon name="timer" :size="16" />Sign-up window</dt><dd>{{ registrationWindow }}</dd></div>
              </dl>
            </section>

            <!-- D. Management actions -->
            <section class="ed-card" aria-labelledby="ed-manage">
              <header class="ed-card__head"><h2 id="ed-manage">Manage</h2></header>
              <div class="ed-groups">
                <div v-for="group in actionGroups" :key="group.key" class="ed-group" role="group" :aria-labelledby="`ed-group-${group.key}`">
                  <h3 :id="`ed-group-${group.key}`">{{ group.label }}</h3>
                  <ul>
                    <li v-for="action in group.actions" :key="action.key">
                      <a v-if="action.href" class="ed-action" :href="action.href" target="_blank" rel="noreferrer">
                        <span class="ed-action__icon"><AppIcon :name="action.icon" :size="18" /></span>
                        <span class="ed-action__copy"><strong>{{ action.label }}</strong><small>{{ action.description }}</small></span>
                        <AppIcon name="arrow-up-right" :size="16" class="ed-action__go" /><span class="sr-only"> (opens in a new tab)</span>
                      </a>
                      <RouterLink v-else-if="action.to" class="ed-action" :to="action.to">
                        <span class="ed-action__icon"><AppIcon :name="action.icon" :size="18" /></span>
                        <span class="ed-action__copy"><strong>{{ action.label }}</strong><small>{{ action.description }}</small></span>
                        <span v-if="action.badge" class="ed-count">{{ action.badge }}</span>
                        <AppIcon name="chevron-right" :size="16" class="ed-action__go" />
                      </RouterLink>
                      <button v-else type="button" class="ed-action" :disabled="action.disabled" :aria-busy="action.key === 'export' && exporting ? 'true' : undefined" @click="action.handler()">
                        <span class="ed-action__icon"><AppIcon :name="action.key === 'export' && exporting ? 'refresh' : action.icon" :size="18" :class="{ 'is-spinning': action.key === 'export' && exporting }" /></span>
                        <span class="ed-action__copy"><strong>{{ action.label }}</strong><small>{{ action.description }}</small></span>
                      </button>
                    </li>
                  </ul>
                </div>
              </div>
            </section>

            <!-- Status changes -->
            <section v-if="event.status === 'published' || (event.status !== 'cancelled' && !isDraft)" class="ed-card ed-danger" aria-labelledby="ed-status">
              <div><h2 id="ed-status">Event status</h2><p>Close the event when it’s over, or cancel it if it won’t take place. Registrations are always kept.</p></div>
              <div class="ed-danger__actions">
                <button v-if="event.status === 'published'" type="button" class="es-btn" @click="askStatusChange('close')"><AppIcon name="lock" :size="16" />Close event</button>
                <button type="button" class="es-btn es-btn--danger" @click="askStatusChange('cancel')"><AppIcon name="x" :size="16" />Cancel event</button>
              </div>
            </section>
          </div>

          <!-- E. Sharing -->
          <aside class="ed-side" aria-labelledby="ed-share">
            <section class="ed-card ed-share">
              <h2 id="ed-share">Share registration</h2>
              <p v-if="isDraft" class="ed-share__warn"><AppIcon name="alert-circle" :size="15" />The link works for attendees once the event is published.</p>
              <p v-else-if="registration.tone !== 'ok'" class="ed-share__warn"><AppIcon name="info" :size="15" />Registration is {{ registration.label.toLowerCase() }}. {{ registration.detail }}</p>
              <div class="ed-qr" :class="{ 'is-muted': isDraft }"><QRCodeDisplay :value="link" /></div>
              <p class="ed-share__hint">Scanning opens the event page, where attendees register.</p>
              <div class="ed-link">
                <input :value="link" readonly aria-label="Registration link" @focus="$event.target.select()" />
                <button type="button" class="es-btn es-btn--sm es-btn--primary" @click="copyLink"><AppIcon :name="copied ? 'check' : 'copy'" :size="15" />{{ copied ? 'Copied' : 'Copy' }}</button>
              </div>
              <div class="ed-share__actions">
                <button type="button" class="es-btn" @click="downloadQr"><AppIcon name="arrow-down" :size="16" />Download QR</button>
                <button v-if="canNativeShare" type="button" class="es-btn" @click="shareEvent"><AppIcon name="send" :size="16" />Share</button>
                <a v-else class="es-btn" :href="shareEmailHref"><AppIcon name="mail" :size="16" />Email link</a>
              </div>
            </section>
          </aside>
        </div>
      </template>
    </section>

    <div v-if="pendingAction" class="modal-backdrop modal-backdrop--enhanced" @click.self="!statusBusy && (pendingAction = null)">
      <section class="es-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ed-dialog-title" aria-describedby="ed-dialog-body">
        <span class="es-dialog__icon" :class="{ 'is-danger': pendingAction === 'cancel' }"><AppIcon :name="pendingAction === 'cancel' ? 'alert-circle' : 'lock'" :size="22" /></span>
        <h2 id="ed-dialog-title">{{ statusActions[pendingAction].title }}</h2>
        <p id="ed-dialog-body">{{ statusActions[pendingAction].body }}</p>
        <footer>
          <button id="ed-dialog-cancel" type="button" class="es-btn" :disabled="statusBusy" @click="pendingAction = null">Keep as is</button>
          <button type="button" class="es-btn" :class="pendingAction === 'cancel' ? 'es-btn--danger-solid' : 'es-btn--primary'" :disabled="statusBusy" @click="confirmStatusChange">{{ statusBusy ? 'Working…' : statusActions[pendingAction].confirm }}</button>
        </footer>
      </section>
    </div>

    <div v-if="toast" class="es-toast" :class="{ 'is-error': toast.tone === 'error' }" role="status" aria-live="polite"><AppIcon :name="toast.tone === 'error' ? 'alert-circle' : 'check'" :size="16" /><span>{{ toast.message }}</span><button type="button" class="es-toast__close" aria-label="Dismiss" @click="toast = null"><AppIcon name="x" :size="15" /></button></div>
  </AdminLayout>
</template>
