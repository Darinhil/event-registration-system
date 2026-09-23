<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import api from '../../services/api'

const route = useRoute(); const router = useRouter(); const copied = ref(false); const actionMessage = ref(''); const actionError = ref(false); const loading = ref(true); const event = ref(null)
const link = computed(() => event.value?.registration_link || `${window.location.origin}/events/${route.params.id}/register`)
const copyLink = async () => { await navigator.clipboard?.writeText(link.value); copied.value = true; setTimeout(() => { copied.value = false }, 1800) }
const downloadQr = () => { const canvas = document.querySelector('.ews-qr canvas'); if (!canvas) return; const anchor = document.createElement('a'); anchor.download = `${event.value?.name || 'event'}-qr.png`; anchor.href = canvas.toDataURL('image/png'); anchor.click() }
const dateLabel = computed(() => event.value?.start_date ? new Date(`${event.value.start_date}T00:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) : 'Date to be confirmed')
const timeLabel = computed(() => `${event.value?.start_time || 'Time pending'} – ${event.value?.end_time || '—'}`)
const shareEvent = async () => { if (navigator.share) await navigator.share({ title: event.value?.name, url: link.value }); else await copyLink() }
const updateEventStatus = async (action, message) => { if (action === 'cancel' && !window.confirm('Cancel this event? It will remain in the database but will no longer be active.')) return; actionError.value = false; try { await api.patch(`/admin/events/${event.value.id}/${action}`); event.value = { ...event.value, status: action === 'close' ? 'closed' : 'cancelled' }; const list = JSON.parse(localStorage.getItem('event_list') || '[]'); const item = list.find((entry) => String(entry.id) === String(event.value.id)); if (item) item.status = event.value.status; localStorage.setItem('event_list', JSON.stringify(list)); actionMessage.value = message } catch (requestError) { actionError.value = true; actionMessage.value = requestError.response?.data?.message || `Unable to ${action} this event. Check your admin session and API connection.` } setTimeout(() => { actionMessage.value = ''; actionError.value = false }, 3000) }
const exportRegistrants = () => router.push(`/admin/events/${event.value.id}/registrants`)
const normalizeEvent = (item, registered = 0) => ({ ...item, status: item.status === 'published' ? 'open' : item.status, registered: item.registered ?? registered, maximum_participants: item.maximum_participants ?? item.capacity, start_date: item.start_date || item.starts_at?.slice(0, 10), start_time: item.start_time || item.starts_at?.slice(11, 16), end_time: item.end_time || item.ends_at?.slice(11, 16), registration_link: item.registration_link || `${window.location.origin}/events/${item.id}/register` })
const loadEvent = async () => { try { const { data } = await api.get(`/events/${route.params.id}`); event.value = normalizeEvent(data.data, data.registered) } catch { const local = JSON.parse(localStorage.getItem('event_list') || '[]').find((item) => String(item.id) === String(route.params.id)); event.value = local || null } finally { loading.value = false } }
onMounted(loadEvent)

const banner = computed(() => event.value?.branding?.image || event.value?.image || '')
const icons = {
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z M12 15a3 3 0 100-6 3 3 0 000 6z',
  pencil: 'M12 20h9 M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z',
  megaphone: 'M3 11l18-7-4 16-6-4-3 4-1-6-4-3z',
  clipboard: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2 M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2 m-6 9l2 2 4-4',
  users: 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2 M9 11a4 4 0 100-8 4 4 0 000 8z M23 21v-2a4 4 0 00-3-3.87 M16 3.13a4 4 0 010 7.75',
  link: 'M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7 M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7',
  pause: 'M9 4v16 M15 4v16',
  check: 'M9 12l2 2 4-4 M12 22a10 10 0 100-20 10 10 0 000 20z',
  chart: 'M18 20V10 M12 20V4 M6 20v-6',
  download: 'M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4 M7 10l5 5 5-5 M12 15V3',
  trash: 'M3 6h18 M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2 m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z',
  share: 'M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8 M16 6l-4-4-4 4 M12 2v13',
}
const registrationClosed = computed(() => event.value?.status === 'closed' || event.value?.status === 'cancelled')
const capacityPercent = computed(() => { const max = Number(event.value?.maximum_participants) || 0; if (!max) return 0; return Math.min(100, Math.round(((event.value?.registered || 0) / max) * 100)) })
const checkedIn = computed(() => (event.value?.registrations || []).filter((registration) => registration?.checkIn).length)
const checkInPercent = computed(() => { const total = event.value?.registered || 0; if (!total) return 0; return Math.min(100, Math.round((checkedIn.value / total) * 100)) })
const completionLabel = computed(() => checkInPercent.value ? `${checkInPercent.value}%` : 'No check-ins yet')

const actionGroups = computed(() => [
  {
    key: 'event', label: 'Event',
    actions: [
      { key: 'preview', label: 'Preview', description: 'See attendee view', icon: 'eye', href: link.value, external: true, primary: true },
      { key: 'edit', label: 'Edit event', description: 'Update event details', icon: 'pencil', to: `/admin/events/${event.value?.id}/edit`, primary: true },
      { key: 'share', label: 'Share event', description: 'Use device sharing', icon: 'megaphone', handler: shareEvent },
    ],
  },
  {
    key: 'registration', label: 'Registration',
    actions: [
      { key: 'form', label: 'Manage registration form', description: 'Edit attendee fields', icon: 'clipboard', to: `/admin/events/${event.value?.id}/form-builder`, primary: true },
      { key: 'registrants', label: 'View registrants', description: 'Review attendees', icon: 'users', to: `/admin/events/${event.value?.id}/registrants`, primary: true },
      { key: 'copy', label: 'Copy registration link', description: 'Share the event URL', icon: 'link', handler: copyLink },
      { key: 'close', label: 'Close registration', description: 'Stop new sign-ups', icon: 'pause', handler: () => updateEventStatus('close', 'Registration closed.'), disabled: registrationClosed },
    ],
  },
  {
    key: 'attendance', label: 'Attendance',
    actions: [
      { key: 'checkin', label: 'Check-in participants', description: 'Scan attendee passes', icon: 'check', to: '/admin/check-ins', primary: true },
      { key: 'stats', label: 'View statistics', description: 'Registration overview', icon: 'chart', anchor: '#stats' },
      { key: 'export', label: 'Export registrants', description: 'Download CSV', icon: 'download', handler: exportRegistrants },
    ],
  },
])
</script>

<template>
  <AdminLayout>
    <section class="ews" aria-labelledby="ews-title">
      <!-- Loading skeleton -->
      <div v-if="loading" class="ews-skeleton" aria-busy="true" aria-live="polite">
        <span class="ews-skel-back"></span>
        <div class="ews-skel-head">
          <span class="ews-skel-line ews-skel-line--eyebrow"></span>
          <span class="ews-skel-line ews-skel-line--title"></span>
          <span class="ews-skel-line ews-skel-line--sub"></span>
        </div>
        <div class="ews-skel-cards">
          <span v-for="n in 8" :key="n" class="ews-skel-card"></span>
        </div>
        <div class="ews-skel-bottom">
          <span class="ews-skel-panel"></span>
          <span class="ews-skel-panel ews-skel-panel--qr"></span>
        </div>
      </div>

      <!-- Not found -->
      <section v-else-if="!event" class="event-empty-state">
        <div class="empty-icon">?</div>
        <h3>Event not found</h3>
        <p>This event may have been removed or is still being created.</p>
        <RouterLink class="primary-button" to="/admin/events">Back to events</RouterLink>
      </section>

      <template v-else>
        <nav class="ews-back" aria-label="Breadcrumb">
          <RouterLink to="/admin/events">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            All events
          </RouterLink>
        </nav>

        <header class="ews-header">
          <div class="ews-header-copy">
            <p class="ews-eyebrow">Event workspace</p>
            <div class="ews-title-row">
              <h1 id="ews-title">{{ event.name }}</h1>
              <span class="event-status" :class="event.status">{{ event.status === 'open' ? 'Published' : event.status }}</span>
            </div>
            <p class="ews-description">{{ event.description || 'Manage your event, registration experience, and attendee activity.' }}</p>
          </div>
          <div class="ews-header-actions">
            <a class="ews-btn ews-btn--ghost" :href="link" target="_blank" rel="noreferrer">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" /></svg>
              Preview
            </a>
            <RouterLink class="ews-btn ews-btn--primary" :to="`/admin/events/${event.id}/edit`">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" /></svg>
              Edit event
            </RouterLink>
          </div>
        </header>

        <div v-if="actionMessage" class="ews-toast" :class="{ 'ews-toast--error': actionError }" role="status">{{ actionMessage }}</div>

        <div class="ews-layout">
          <div class="ews-main">
            <!-- Action groups -->
            <div class="ews-actions">
              <section v-for="group in actionGroups" :key="group.key" class="ews-action-group" :aria-label="`${group.label} actions`">
                <h2 class="ews-group-label">{{ group.label }}</h2>
                <div class="ews-action-grid" :class="{ 'ews-action-grid--two': group.actions.length === 3 }">
                  <template v-for="action in group.actions" :key="action.key">
                    <a v-if="action.href" class="ews-action" :class="{ 'ews-action--primary': action.primary, 'ews-action--disabled': action.disabled }" :href="action.disabled ? undefined : action.href" :target="action.external ? '_blank' : undefined" :rel="action.external ? 'noreferrer' : undefined" :aria-disabled="action.disabled || undefined">
                      <span class="ews-action-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icons[action.icon]" /></svg></span>
                      <span class="ews-action-copy"><strong>{{ action.label }}</strong><small>{{ action.description }}</small></span>
                    </a>
                    <RouterLink v-else-if="action.to && !action.disabled" class="ews-action" :class="{ 'ews-action--primary': action.primary }" :to="action.to">
                      <span class="ews-action-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icons[action.icon]" /></svg></span>
                      <span class="ews-action-copy"><strong>{{ action.label }}</strong><small>{{ action.description }}</small></span>
                    </RouterLink>
                    <button v-else type="button" class="ews-action" :class="{ 'ews-action--primary': action.primary, 'ews-action--disabled': action.disabled }" :disabled="action.disabled" @click="action.handler()">
                      <span class="ews-action-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icons[action.icon]" /></svg></span>
                      <span class="ews-action-copy"><strong>{{ action.label }}</strong><small>{{ action.description }}</small></span>
                    </button>
                  </template>
                </div>
              </section>

              <!-- Danger zone -->
              <section class="ews-danger" aria-label="Danger zone">
                <div class="ews-danger-copy">
                  <h2>Danger zone</h2>
                  <p>Cancel this event to mark it inactive. Registrations are kept.</p>
                </div>
                <button type="button" class="ews-btn ews-btn--danger" @click="updateEventStatus('cancel', 'Event cancelled.')">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z" /></svg>
                  Cancel event
                </button>
              </section>
            </div>

            <!-- Registration overview -->
            <article id="stats" class="ews-panel">
              <header class="ews-panel-head">
                <div>
                  <p class="ews-eyebrow">At a glance</p>
                  <h2>Registration overview</h2>
                </div>
                <span class="ews-panel-date">{{ dateLabel }}</span>
              </header>
              <div class="ews-stats">
                <div class="ews-stat">
                  <small>Registered</small>
                  <strong>{{ event.registered || 0 }}</strong>
                  <span>participants</span>
                </div>
                <div class="ews-stat">
                  <small>Capacity</small>
                  <strong>{{ event.maximum_participants || '∞' }}</strong>
                  <span>maximum</span>
                </div>
                <div class="ews-stat">
                  <small>Checked in</small>
                  <strong>{{ checkedIn }}</strong>
                  <span>attendees</span>
                </div>
                <div class="ews-stat">
                  <small>Completion rate</small>
                  <strong :class="{ 'ews-stat-muted': !checkInPercent }">{{ completionLabel }}</strong>
                  <span>{{ checkInPercent ? 'of registered attendees' : 'once check-ins begin' }}</span>
                </div>
              </div>
              <div class="ews-progress-list">
                <div class="ews-progress">
                  <div class="ews-progress-head"><span>Registration capacity</span><strong>{{ event.registered || 0 }} / {{ event.maximum_participants || '∞' }}</strong></div>
                  <div class="ews-track" role="progressbar" :aria-valuenow="capacityPercent" aria-valuemin="0" aria-valuemax="100" aria-label="Registration capacity used">
                    <span :style="{ width: `${capacityPercent}%` }"></span>
                  </div>
                </div>
                <div class="ews-progress">
                  <div class="ews-progress-head"><span>Check-in progress</span><strong>{{ checkedIn }} / {{ event.registered || 0 }}</strong></div>
                  <div class="ews-track" role="progressbar" :aria-valuenow="checkInPercent" aria-valuemin="0" aria-valuemax="100" aria-label="Check-in progress">
                    <span class="is-teal" :style="{ width: `${checkInPercent}%` }"></span>
                  </div>
                </div>
              </div>
              <div class="ews-details">
                <div><small>Date</small><strong>{{ dateLabel }}</strong></div>
                <div><small>Time</small><strong>{{ timeLabel }}</strong></div>
                <div><small>Location</small><strong>{{ event.location || 'Location pending' }}</strong></div>
                <div><small>Format</small><strong>{{ event.mode || 'In person' }}</strong></div>
                <div><small>Registration status</small><strong class="ews-status-text" :class="event.status">{{ event.status === 'open' ? 'Open' : event.status }}</strong></div>
              </div>
            </article>
          </div>

          <!-- Sidebar -->
          <aside class="ews-side">
            <section class="ews-qr" aria-label="Share your event">
              <p class="ews-eyebrow">Share your event</p>
              <h2>Registration QR code</h2>
              <div v-if="banner" class="ews-banner"><img :src="banner" alt="Event banner" /></div>
              <div class="ews-qr-frame">
                <QRCodeDisplay :value="link" />
              </div>
              <p class="ews-qr-help">Scan to register</p>
              <div class="ews-side-actions">
                <button type="button" class="ews-btn ews-btn--primary ews-btn--block" @click="copyLink">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7" /><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7" /></svg>
                  {{ copied ? 'Copied!' : 'Copy registration link' }}
                </button>
                <button type="button" class="ews-btn ews-btn--ghost ews-btn--block" @click="downloadQr">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                  Download QR code
                </button>
                <button type="button" class="ews-btn ews-btn--ghost ews-btn--block" @click="shareEvent">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" /></svg>
                  Share event
                </button>
              </div>
              <div class="ews-link-box">
                <small>Registration link</small>
                <strong>{{ link }}</strong>
              </div>
            </section>
          </aside>
        </div>
      </template>
    </section>
  </AdminLayout>
</template>
