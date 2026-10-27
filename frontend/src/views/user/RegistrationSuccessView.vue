<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useRegistrationStore } from '../../stores/registration'
import { getRegistration } from '../../services/registrationService'

const route = useRoute()
const store = useRegistrationStore()
const registration = ref(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const notFound = ref(false)
let requestVersion = 0
const justRegistered = computed(() => route.query.new === '1')
const qrValue = computed(() => registration.value?.qr_token || registration.value?.registration_code || '')
const code = computed(() => registration.value?.registration_code || registration.value?.id || '')

/* ---------- Display helpers ---------- */
const startsAt = computed(() => registration.value?.event_starts_at || registration.value?.event_date || null)
const endsAt = computed(() => registration.value?.event_ends_at || null)
const location = computed(() => registration.value?.event_current_location || registration.value?.event_location || '')
const sameDay = (a, b) => new Date(a).toDateString() === new Date(b).toDateString()
const fmtDay = (iso) => new Date(iso).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
const fmtTime = (iso) => new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
const dateLabel = computed(() => startsAt.value ? fmtDay(startsAt.value) : 'Date to be confirmed')
const timeLabel = computed(() => {
  if (!startsAt.value) return ''
  if (!endsAt.value) return fmtTime(startsAt.value)
  return sameDay(startsAt.value, endsAt.value) ? `${fmtTime(startsAt.value)} – ${fmtTime(endsAt.value)}` : `${fmtTime(startsAt.value)} – ${fmtDay(endsAt.value)}, ${fmtTime(endsAt.value)}`
})
const month = computed(() => startsAt.value ? new Date(startsAt.value).toLocaleDateString(undefined, { month: 'short' }) : '')
const day = computed(() => startsAt.value ? new Date(startsAt.value).getDate() : '')
const isPast = computed(() => Boolean((endsAt.value || startsAt.value) && new Date(endsAt.value || startsAt.value) < new Date()))
const status = computed(() => {
  const r = registration.value
  if (r?.checked_in_at || r?.status === 'checked_in') return { key: 'in', label: 'Checked in' }
  if (r?.status === 'cancelled') return { key: 'off', label: 'Cancelled' }
  if (isPast.value) return { key: 'past', label: 'Event ended' }
  return { key: 'ok', label: 'Confirmed' }
})
const showSteps = computed(() => status.value.key === 'ok')

const loadRegistration = async () => {
  const currentRequest = ++requestVersion
  loading.value = false
  error.value = ''
  notFound.value = false
  registration.value = null
  const id = typeof route.query.id === 'string' ? route.query.id : ''

  if (!id) {
    registration.value = store.current
    if (!registration.value) error.value = 'Open this pass from My information to load its registration details.'
    return
  }

  if (String(store.current?.id) === id && store.current?.event_starts_at !== undefined) {
    registration.value = store.current
    return
  }

  loading.value = true
  try {
    const { data } = await getRegistration(encodeURIComponent(id))
    if (currentRequest !== requestVersion) return
    if (!data.data) throw new Error('The registration response did not include registration details.')
    registration.value = data.data
    store.current = data.data
  } catch (requestError) {
    if (currentRequest !== requestVersion) return
    const status = requestError.response?.status
    notFound.value = status === 404 || status === 403
    error.value = status === 404 || status === 403 ? 'We couldn’t find this registration on your account.' : 'We couldn’t load your pass. Please check your connection and try again.'
  } finally {
    if (currentRequest === requestVersion) loading.value = false
  }
}

/* ---------- Actions ---------- */
const flash = (message) => { notice.value = message; setTimeout(() => { if (notice.value === message) notice.value = '' }, 2500) }
const fileSafe = (value) => String(value || 'registration').replace(/[^\w-]+/g, '-')
const downloadQr = () => {
  const canvas = document.querySelector('.rp-qr canvas')
  if (!canvas || !registration.value) return
  const anchor = document.createElement('a')
  anchor.download = `${fileSafe(code.value)}-pass.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
  flash('QR code downloaded')
}
const copyCode = async () => {
  try { await navigator.clipboard.writeText(String(code.value)); flash('Registration ID copied') } catch { flash('Couldn’t copy — select the ID and copy it manually') }
}
const printPass = () => window.print()
const icsDate = (date) => date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
const icsText = (value) => String(value || '').replace(/[\\;,]/g, (c) => `\\${c}`).replace(/\n/g, '\\n')
const addToCalendar = () => {
  const start = new Date(startsAt.value)
  const end = endsAt.value ? new Date(endsAt.value) : new Date(start.getTime() + 60 * 60 * 1000)
  const lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Live & Learn Cambodia//Events//EN', 'BEGIN:VEVENT',
    `UID:registration-${registration.value.id}@events`, `DTSTAMP:${icsDate(new Date())}`, `DTSTART:${icsDate(start)}`, `DTEND:${icsDate(end)}`,
    `SUMMARY:${icsText(registration.value.event_name)}`, `LOCATION:${icsText(location.value)}`,
    `DESCRIPTION:${icsText(`Registration ID: ${code.value}\nScan the check-in QR code at the entrance when you arrive.`)}`, 'END:VEVENT', 'END:VCALENDAR']
  const anchor = document.createElement('a')
  anchor.href = URL.createObjectURL(new Blob([lines.join('\r\n')], { type: 'text/calendar' }))
  anchor.download = `${fileSafe(registration.value.event_name)}.ics`
  anchor.click()
  setTimeout(() => URL.revokeObjectURL(anchor.href), 1000)
}

const steps = [
  { icon: 'pin', title: 'Arrive at the venue', text: 'Bring your phone and be signed in to this account.' },
  { icon: 'scan', title: 'Scan the entrance QR', text: 'Use your phone camera on the check-in poster at the door.' },
  { icon: 'check-circle', title: 'Tap “Check me in”', text: 'Confirm your details — you’re done in seconds.' },
]

watch(() => route.query.id, loadRegistration, { immediate: true })
</script>

<template>
  <UserLayout>
    <section class="rp">
      <RouterLink class="rp-back" to="/me"><AppIcon name="arrow-left" :size="16" />My registrations</RouterLink>

      <!-- Loading -->
      <div v-if="loading" class="rp-skeleton" role="status" aria-label="Loading your pass">
        <div class="rp-skel rp-skel--title" /><div class="rp-skel rp-skel--ticket" />
      </div>

      <!-- Error -->
      <div v-else-if="!registration" class="rp-card rp-empty" role="alert">
        <span class="rp-empty__icon"><AppIcon name="alert-circle" :size="26" /></span>
        <h1>Pass not available</h1>
        <p>{{ error || 'Registration details could not be found.' }}</p>
        <div class="rp-row">
          <button v-if="route.query.id && !notFound" type="button" class="rp-btn rp-btn--ghost" @click="loadRegistration"><AppIcon name="refresh" :size="16" />Try again</button>
          <RouterLink class="rp-btn rp-btn--primary" to="/me">View my registrations</RouterLink>
        </div>
      </div>

      <template v-else>
        <!-- Just registered -->
        <div v-if="justRegistered" class="rp-success" role="status">
          <span class="rp-success__icon"><AppIcon name="check" :size="22" /></span>
          <div>
            <strong>You’re registered!</strong>
            <p v-if="registration.success_message" class="rp-success__message">{{ registration.success_message }}</p>
            <p>Your place is confirmed. No confirmation email is sent — this pass is always available under <RouterLink to="/me">My registrations</RouterLink>.</p>
          </div>
        </div>

        <header class="rp-head">
          <div>
            <p class="rp-eyebrow">Registration pass</p>
            <h1>{{ registration.event_name }}</h1>
          </div>
          <span class="rp-status" :class="`is-${status.key}`"><AppIcon :name="status.key === 'in' ? 'check-circle' : status.key === 'ok' ? 'calendar-check' : 'info'" :size="15" />{{ status.label }}</span>
        </header>

        <!-- Ticket -->
        <article class="rp-ticket" aria-label="Event pass">
          <div class="rp-ticket__main">
            <div class="rp-when">
              <span v-if="startsAt" class="rp-cal" aria-hidden="true"><b>{{ month }}</b>{{ day }}</span>
              <div>
                <strong>{{ dateLabel }}</strong>
                <span v-if="timeLabel"><AppIcon name="clock" :size="15" />{{ timeLabel }}</span>
              </div>
            </div>
            <p class="rp-where"><AppIcon name="pin" :size="17" /><span>{{ location || 'Venue to be confirmed' }}</span></p>

            <dl class="rp-facts">
              <div><dt>Participant</dt><dd>{{ registration.full_name || 'Attendee' }}</dd></div>
              <div>
                <dt>Registration ID</dt>
                <dd class="rp-code">
                  <span>{{ code }}</span>
                  <button type="button" class="rp-icon-btn" aria-label="Copy registration ID" title="Copy" @click="copyCode"><AppIcon name="copy" :size="15" /></button>
                </dd>
              </div>
              <div v-if="registration.email"><dt>Email</dt><dd>{{ registration.email }}</dd></div>
              <div v-if="registration.phone || registration.telephone"><dt>Phone</dt><dd>{{ registration.phone || registration.telephone }}</dd></div>
            </dl>

            <div class="rp-row rp-actions">
              <button v-if="startsAt && status.key === 'ok'" type="button" class="rp-btn rp-btn--ghost" @click="addToCalendar"><AppIcon name="calendar-plus" :size="16" />Add to calendar</button>
              <RouterLink v-if="registration.can_edit && status.key === 'ok'" class="rp-btn rp-btn--ghost" :to="`/registration/${registration.id}/edit`"><AppIcon name="pen" :size="16" />Edit details</RouterLink>
              <RouterLink class="rp-btn rp-btn--ghost" :to="`/events/${registration.event_id}`"><AppIcon name="info" :size="16" />Event page</RouterLink>
            </div>
          </div>

          <div class="rp-ticket__stub">
            <p class="rp-stub__label">Personal pass</p>
            <div v-if="qrValue" class="rp-qr" :class="{ 'is-used': status.key !== 'ok' }"><QRCodeDisplay :value="qrValue" /></div>
            <p class="rp-stub__code">{{ code }}</p>
            <p class="rp-stub__hint">If you can’t scan the entrance QR, show this pass at the check-in desk.</p>
            <div class="rp-row rp-stub__actions">
              <button v-if="qrValue" type="button" class="rp-btn rp-btn--primary" @click="downloadQr"><AppIcon name="download" :size="16" />Download</button>
              <button type="button" class="rp-btn rp-btn--ghost" @click="printPass"><AppIcon name="file-text" :size="16" />Print</button>
            </div>
          </div>
        </article>
        <p class="rp-notice" role="status">{{ notice }}</p>

        <!-- What happens next -->
        <section v-if="showSteps" class="rp-card rp-steps" aria-labelledby="rp-steps-title">
          <h2 id="rp-steps-title">How to check in on the day</h2>
          <ol>
            <li v-for="(step, index) in steps" :key="step.title">
              <span class="rp-steps__icon"><AppIcon :name="step.icon" :size="20" /></span>
              <span><strong>{{ index + 1 }}. {{ step.title }}</strong><small>{{ step.text }}</small></span>
            </li>
          </ol>
        </section>
        <section v-else-if="status.key === 'in'" class="rp-card rp-done">
          <AppIcon name="check-circle" :size="22" />
          <p>You checked in on <strong>{{ fmtDay(registration.checked_in_at) }}</strong> at <strong>{{ fmtTime(registration.checked_in_at) }}</strong>. Thanks for coming!</p>
        </section>
      </template>
    </section>
  </UserLayout>
</template>

<style scoped>
.rp { --rp-ink: #17365f; --rp-text: #3d5470; --rp-muted: #6b8099; --rp-line: #dfe7ef; --rp-soft: #f6f9fc; --rp-brand: #0b7f62; --rp-brand-dark: #086a51; --rp-brand-soft: #e8f5f0; width: min(100% - 32px, 1040px); margin: 0 auto; padding: 24px 0 56px; color: var(--rp-text); font-size: 15px; line-height: 1.5; }
.rp button { margin: 0; }
.rp h1, .rp h2 { color: var(--rp-ink); letter-spacing: -.01em; }
.rp-back { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 16px; color: var(--rp-muted); font-weight: 600; font-size: .9rem; text-decoration: none; }
.rp-back:hover { color: var(--rp-ink); }
.rp-card { border: 1px solid var(--rp-line); border-radius: 16px; background: #fff; box-shadow: 0 6px 20px rgba(23, 54, 95, .05); }
.rp-row { display: flex; flex-wrap: wrap; gap: 10px; }

/* Success banner */
.rp-success { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px; padding: 16px 18px; border: 1px solid #bfe5d6; border-radius: 14px; background: var(--rp-brand-soft); }
.rp-success__icon { display: grid; width: 40px; height: 40px; flex: none; place-items: center; border-radius: 50%; background: var(--rp-brand); color: #fff; }
.rp-success strong { color: var(--rp-brand-dark); font-size: 1.05rem; }
.rp-success p { margin: 2px 0 0; color: var(--rp-text); font-size: .92rem; }
.rp-success a { color: var(--rp-brand-dark); font-weight: 600; }

/* Heading */
.rp-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.rp-eyebrow { margin: 0 0 4px; color: var(--rp-brand); font-size: .76rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.rp-head h1 { margin: 0; font-size: clamp(1.6rem, 3vw, 2.1rem); font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
.rp-status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 99px; font-size: .84rem; font-weight: 700; }
.rp-status.is-ok { background: var(--rp-brand-soft); color: var(--rp-brand-dark); }
.rp-status.is-in { background: var(--rp-brand); color: #fff; }
.rp-status.is-past { background: #eef2f6; color: #4e6175; }
.rp-status.is-off { background: #fdf1f0; color: #8f2a20; }

/* Ticket */
.rp-ticket { display: grid; grid-template-columns: minmax(0, 1fr) 320px; overflow: hidden; border: 1px solid var(--rp-line); border-radius: 20px; background: #fff; box-shadow: 0 12px 32px rgba(23, 54, 95, .08); }
.rp-ticket__main { display: grid; align-content: start; gap: 18px; padding: 28px; }
.rp-ticket__stub { position: relative; display: grid; justify-items: center; align-content: start; gap: 10px; padding: 28px 24px; border-left: 2px dashed var(--rp-line); background: var(--rp-soft); text-align: center; }
.rp-ticket__stub::before, .rp-ticket__stub::after { position: absolute; left: -13px; width: 24px; height: 24px; border: 1px solid var(--rp-line); border-radius: 50%; background: #f4f7fa; content: ''; }
.rp-ticket__stub::before { top: -13px; }
.rp-ticket__stub::after { bottom: -13px; }
.rp-when { display: flex; align-items: center; gap: 14px; }
.rp-when > div { display: grid; gap: 2px; }
.rp-when strong { color: var(--rp-ink); font-size: 1.08rem; }
.rp-when > div > span { display: inline-flex; align-items: center; gap: 6px; color: var(--rp-muted); }
.rp-cal { display: grid; width: 58px; height: 62px; flex: none; place-items: center; align-content: center; border-radius: 14px; background: var(--rp-brand); color: #fff; font-size: 1.5rem; font-weight: 700; line-height: 1.05; }
.rp-cal b { font-size: .7rem; letter-spacing: .08em; text-transform: uppercase; opacity: .9; }
.rp-where { display: flex; align-items: flex-start; gap: 8px; margin: 0; color: var(--rp-text); font-weight: 500; }
.rp-where svg { flex: none; margin-top: 3px; color: var(--rp-brand); }
.rp-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0; margin: 0; border-top: 1px solid var(--rp-line); }
.rp-facts > div { display: grid; gap: 2px; padding: 14px 0; border-bottom: 1px solid var(--rp-line); }
.rp-facts > div:nth-child(odd) { padding-right: 16px; }
.rp-facts > div:last-child:nth-child(odd) { grid-column: 1 / -1; }
.rp-facts dt { color: var(--rp-muted); font-size: .8rem; font-weight: 600; }
.rp-facts dd { margin: 0; color: var(--rp-ink); font-weight: 600; overflow-wrap: anywhere; }
.rp-code { display: flex; align-items: center; gap: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.rp-icon-btn { display: inline-grid; width: 30px; height: 30px; padding: 0; place-items: center; border: 1px solid transparent; border-radius: 8px; background: none; color: var(--rp-muted); cursor: pointer; }
.rp-icon-btn:hover { border-color: var(--rp-line); background: var(--rp-soft); color: var(--rp-ink); transform: none; }
.rp-stub__label { margin: 0; color: var(--rp-muted); font-size: .76rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.rp-qr { padding: 12px; border: 1px solid var(--rp-line); border-radius: 16px; background: #fff; }
.rp-qr :deep(.qr) { margin: 0; padding: 0; border: 0; background: none; }
.rp-qr :deep(canvas) { display: block; width: 200px !important; height: 200px !important; }
.rp-qr.is-used { opacity: .45; }
.rp-stub__code { margin: 0; color: var(--rp-ink); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 1.05rem; font-weight: 700; letter-spacing: .04em; }
.rp-stub__hint { max-width: 240px; margin: 0; color: var(--rp-muted); font-size: .84rem; }
.rp-stub__actions { justify-content: center; margin-top: 4px; }
.rp-notice { min-height: 1.3em; margin: 8px 0 0; color: var(--rp-brand-dark); font-size: .88rem; font-weight: 600; text-align: center; }

/* Steps */
.rp-steps { margin-top: 12px; padding: 22px 24px; }
.rp-steps h2 { margin: 0 0 16px; font-size: 1.08rem; font-weight: 700; }
.rp-steps ol { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin: 0; padding: 0; list-style: none; }
.rp-steps li { display: flex; gap: 12px; }
.rp-steps li > span:last-child { display: grid; gap: 2px; }
.rp-steps strong { color: var(--rp-ink); font-size: .94rem; }
.rp-steps small { color: var(--rp-muted); font-size: .85rem; }
.rp-steps__icon { display: grid; width: 42px; height: 42px; flex: none; place-items: center; border-radius: 12px; background: var(--rp-brand-soft); color: var(--rp-brand); }
.rp-done { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 16px 20px; color: var(--rp-brand-dark); }
.rp-done p { margin: 0; color: var(--rp-text); }

/* Empty / loading */
.rp-empty { display: grid; justify-items: center; gap: 8px; padding: 48px 24px; text-align: center; }
.rp-empty h1 { margin: 6px 0 0; font-size: 1.4rem; }
.rp-empty p { max-width: 420px; margin: 0 0 8px; color: var(--rp-muted); }
.rp-empty__icon { display: grid; width: 56px; height: 56px; place-items: center; border-radius: 50%; background: #fff4dc; color: #8a5300; }
.rp-skeleton { display: grid; gap: 16px; }
.rp-skel { border-radius: 16px; background: linear-gradient(90deg, #eef2f6 0%, #f7f9fb 50%, #eef2f6 100%); background-size: 200% 100%; animation: rp-shimmer 1.2s ease-in-out infinite; }
.rp-skel--title { width: 50%; height: 44px; }
.rp-skel--ticket { height: 360px; }
@keyframes rp-shimmer { to { background-position: -200% 0; } }

/* Buttons */
.rp-btn { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 0 16px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .92rem; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; }
.rp-btn svg { flex: none; }
.rp-btn--primary { background: var(--rp-brand); color: #fff; box-shadow: 0 6px 16px rgba(11, 127, 98, .22); }
.rp-btn--primary:hover { background: var(--rp-brand-dark); transform: none; }
.rp-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--rp-ink); }
.rp-btn--ghost:hover { border-color: #aebfd1; background: var(--rp-soft); transform: none; }
.rp-btn:focus-visible, .rp-icon-btn:focus-visible, .rp-back:focus-visible { outline: 3px solid rgba(11, 127, 98, .3); outline-offset: 2px; }

@media (max-width: 860px) {
  .rp-ticket { grid-template-columns: 1fr; }
  .rp-ticket__stub { order: -1; border-bottom: 2px dashed var(--rp-line); border-left: 0; }
  .rp-ticket__stub::before, .rp-ticket__stub::after { top: auto; bottom: -13px; }
  .rp-ticket__stub::before { left: -13px; }
  .rp-ticket__stub::after { right: -13px; left: auto; }
  .rp-steps ol { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
  .rp { width: min(100% - 24px, 1040px); padding-top: 16px; }
  .rp-ticket__main { padding: 20px; }
  .rp-facts { grid-template-columns: 1fr; }
  .rp-facts > div:nth-child(odd) { padding-right: 0; }
  .rp-actions .rp-btn, .rp-stub__actions .rp-btn { flex: 1; }
}
@media (prefers-reduced-motion: reduce) { .rp-skel { animation: none; } }
@media print {
  :global(.un-bar), :global(footer), .rp-back, .rp-actions, .rp-stub__actions, .rp-notice, .rp-success { display: none !important; }
  .rp-ticket { box-shadow: none; }
}
</style>
