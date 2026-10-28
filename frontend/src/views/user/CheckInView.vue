<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import QRScanner from '../../components/QRScanner.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { useCheckInStore } from '../../stores/checkIn'
import { parseQrPayload } from '../../utils/qr'

const route = useRoute()
const auth = useAuthStore()
const store = useCheckInStore()
const registration = ref(null)
// Checking in needs the entrance token from the QR poster at the venue; it is sent with the check-in.
const entranceToken = ref('')
const isEntranceToken = (value) => typeof value === 'string' && /^[0-9a-f-]{36}$/i.test(value)
// Opened from a venue QR link: go straight to the lookup instead of flashing the scanner first.
const resolving = ref(isEntranceToken(route.query.e))
const lookupError = ref('')
const cameraError = ref('')
const cameraReady = ref(false)
const cameraKey = ref(0)
const resultHeading = ref(null)

const initials = computed(() => (registration.value?.full_name || auth.user?.name || 'A').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase())
const formEntries = computed(() => Object.entries(registration.value?.form_values || {}))
const alreadyIn = computed(() => Boolean(registration.value?.checked_in_at) && !store.result)
const justCheckedIn = computed(() => Boolean(store.result))
const checkedInAt = computed(() => store.result?.check_in?.checked_in_at || registration.value?.checked_in_at)
const fmtDay = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : 'Date to be confirmed')
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '')

/* ---------- Your events (a reminder; checking in still needs the venue QR) ---------- */
const isToday = (iso) => iso && new Date(iso).toDateString() === new Date().toDateString()
const myEvents = computed(() => (auth.user?.registrations || [])
  .filter((item) => !item.checked_in_at && (!item.event_ends_at || new Date(item.event_ends_at) > new Date()))
  .sort((a, b) => new Date(a.event_starts_at || a.event_date) - new Date(b.event_starts_at || b.event_date))
  .slice(0, 4))

/** The venue QR is a /check-in?e=<entrance token> link (or a JSON payload carrying `e`). */
const entranceFromScan = (value) => {
  const payload = parseQrPayload(value)
  if (isEntranceToken(String(payload?.e ?? ''))) return String(payload.e)
  try {
    const entrance = new URL(value).searchParams.get('e')
    if (isEntranceToken(entrance)) return entrance
  } catch { /* plain text */ }
  return ''
}

const focusResult = async () => { await nextTick(); resultHeading.value?.focus() }
const resolveRegistration = async (token) => {
  entranceToken.value = token
  lookupError.value = ''
  resolving.value = true
  registration.value = null
  store.reset()
  try {
    await store.preview({ entrance_token: token })
    registration.value = store.registration
  } catch (error) {
    const status = error.response?.status
    lookupError.value = !error.response ? 'We couldn’t reach the server. Check your connection and try again.'
      : status === 404 ? 'You’re not registered for this event.'
      : status === 422 ? 'This QR code isn’t valid any more. Ask the event team for the current check-in code.'
      : store.error || 'We couldn’t look up your registration.'
  } finally {
    resolving.value = false
    focusResult()
  }
}

/** Deep link from the entrance QR, opened by the phone's camera app: /check-in?e=<token>. */
onMounted(async () => {
  auth.fetchMe().catch(() => { /* the list of events is optional */ })
  if (isEntranceToken(route.query.e)) await resolveRegistration(route.query.e)
})

const onScan = async (value) => {
  const token = entranceFromScan(String(value || '').trim())
  if (!token) { lookupError.value = 'That isn’t the event’s check-in code. Scan the QR code shown at the venue entrance.'; entranceToken.value = ''; focusResult(); return }
  await resolveRegistration(token)
}
const onCameraError = (message) => { cameraError.value = message; cameraReady.value = false }
const retryCamera = () => { cameraError.value = ''; cameraReady.value = false; cameraKey.value += 1 }

const confirm = async () => {
  try {
    await store.submit(registration.value.qr_token, entranceToken.value)
    focusResult()
    auth.fetchMe().catch(() => {}) // drop this event from "Your upcoming events"
  } catch { /* the message is shown on the card */ }
}
const reset = () => { registration.value = null; lookupError.value = ''; entranceToken.value = ''; store.reset(); cameraError.value = ''; cameraReady.value = false; cameraKey.value += 1 }
const steps = [
  { icon: 'pin', title: 'Find the check-in QR', text: 'It’s on the poster or screen at the venue entrance.' },
  { icon: 'scan', title: 'Scan it', text: 'Use your phone’s camera app, or the scanner on this page.' },
  { icon: 'check-circle', title: 'Confirm', text: 'Check your details, then tap “Check me in”.' },
]
</script>

<template>
  <UserLayout>
    <section class="ci">
      <header class="ci-head">
        <h1>Check in</h1>
        <p>Arrived at the venue? Scan the event’s check-in QR code to confirm your attendance.</p>
      </header>

      <div class="ci-layout">
        <div class="ci-main">
          <!-- 1. Scanning -->
          <section v-if="!registration && !lookupError && !resolving" class="ci-card ci-scan" aria-labelledby="ci-scan-title">
            <h2 id="ci-scan-title" class="ci-card__title">Scan the venue QR code</h2>
            <div v-if="cameraError" class="ci-camera-off" role="alert">
              <span class="ci-icon is-warning"><AppIcon name="alert-circle" :size="26" /></span>
              <strong>Camera not available</strong>
              <p>{{ cameraError }}</p>
              <button type="button" class="ci-btn ci-btn--ghost" @click="retryCamera"><AppIcon name="refresh" :size="16" />Try the camera again</button>
            </div>
            <div v-else class="ci-camera">
              <QRScanner :key="cameraKey" hint="" :show-paste="false" @scan="onScan" @error="onCameraError" @ready="cameraReady = true" />
              <div class="ci-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
              <p v-if="!cameraReady" class="ci-camera__status"><AppIcon name="refresh" :size="16" class="ci-spin" />Starting camera…</p>
            </div>
            <p v-if="!cameraError" class="ci-scan__hint"><AppIcon name="info" :size="15" />Hold your phone so the whole QR code fits inside the frame.</p>

            <p class="ci-rule"><AppIcon name="info" :size="16" />You can only check in by scanning the QR code at the event entrance. Can’t scan it? Ask the check-in desk — staff can check you in.</p>
          </section>

          <!-- Looking up -->
          <section v-else-if="resolving" class="ci-card ci-state" aria-busy="true">
            <span class="ci-icon"><AppIcon name="refresh" :size="26" class="ci-spin" /></span>
            <h2 ref="resultHeading" tabindex="-1">Finding your registration…</h2>
          </section>

          <!-- Not found / invalid code -->
          <section v-else-if="lookupError" class="ci-card ci-state" role="alert">
            <span class="ci-icon is-warning"><AppIcon name="alert-circle" :size="26" /></span>
            <h2 ref="resultHeading" tabindex="-1">{{ lookupError.startsWith('You’re not registered') ? 'No registration found' : 'We couldn’t check you in' }}</h2>
            <p>{{ lookupError }}</p>
            <div class="ci-actions">
              <button type="button" class="ci-btn ci-btn--ghost" @click="reset"><AppIcon name="scan" :size="16" />Scan again</button>
              <RouterLink v-if="lookupError.startsWith('You’re not registered')" class="ci-btn ci-btn--primary" to="/">Browse events</RouterLink>
              <button v-else-if="entranceToken" type="button" class="ci-btn ci-btn--primary" @click="resolveRegistration(entranceToken)"><AppIcon name="refresh" :size="16" />Try again</button>
            </div>
          </section>

          <!-- Checked in (now, or earlier) -->
          <section v-else-if="justCheckedIn || alreadyIn" class="ci-card ci-done" aria-live="polite">
            <span class="ci-done__check"><AppIcon name="check" :size="34" /></span>
            <h2 ref="resultHeading" tabindex="-1">{{ justCheckedIn ? 'You’re checked in!' : 'You’re already checked in' }}</h2>
            <p>Welcome, <strong>{{ (registration.full_name || auth.user?.name || '').split(' ')[0] }}</strong>. Your attendance at <strong>{{ registration.event_name }}</strong> is recorded.</p>
            <p class="ci-done__time"><AppIcon name="clock" :size="15" />Checked in {{ fmtDay(checkedInAt) }} at {{ fmtTime(checkedInAt) }}</p>
            <div class="ci-actions">
              <RouterLink class="ci-btn ci-btn--ghost" to="/me">My registrations</RouterLink>
              <button type="button" class="ci-btn ci-btn--primary" @click="reset"><AppIcon name="scan" :size="16" />Check in to another event</button>
            </div>
          </section>

          <!-- 2. Confirm -->
          <section v-else class="ci-card ci-confirm" aria-labelledby="ci-confirm-title">
            <p class="ci-eyebrow">Registration found</p>
            <h2 id="ci-confirm-title" ref="resultHeading" tabindex="-1">{{ registration.event_name }}</h2>
            <ul class="ci-facts">
              <li><AppIcon name="calendar" :size="16" />{{ fmtDay(registration.event_starts_at || registration.event_date) }}<template v-if="registration.event_starts_at"> · {{ fmtTime(registration.event_starts_at) }}</template></li>
              <li><AppIcon name="pin" :size="16" />{{ registration.event_current_location || registration.event_location || 'Venue to be confirmed' }}</li>
            </ul>
            <div class="ci-person">
              <span class="ci-avatar">{{ initials }}</span>
              <span><strong>{{ registration.full_name || auth.user?.name }}</strong><small>{{ registration.registration_code }}</small></span>
              <span class="ci-badge"><AppIcon name="clock" :size="13" />Not checked in yet</span>
            </div>
            <details v-if="formEntries.length" class="ci-details">
              <summary>Information you provided ({{ formEntries.length }})</summary>
              <dl><div v-for="[label, value] in formEntries" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div></dl>
            </details>
            <p v-if="store.error" class="ci-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ store.error }}</p>
            <button type="button" class="ci-btn ci-btn--primary ci-btn--lg" :disabled="store.loading" :aria-busy="store.loading" @click="confirm">
              <AppIcon :name="store.loading ? 'refresh' : 'check'" :size="18" :class="{ 'ci-spin': store.loading }" />{{ store.loading ? 'Checking you in…' : 'Check me in' }}
            </button>
            <button type="button" class="ci-btn ci-btn--text" @click="reset">This isn’t my event — scan again</button>
          </section>
        </div>

        <!-- Help and a fallback list -->
        <aside class="ci-side">
          <section class="ci-card ci-how" aria-labelledby="ci-how-title">
            <h2 id="ci-how-title" class="ci-card__title">How it works</h2>
            <ol>
              <li v-for="(step, index) in steps" :key="step.title"><span class="ci-how__icon"><AppIcon :name="step.icon" :size="18" /></span><span><strong>{{ index + 1 }}. {{ step.title }}</strong><small>{{ step.text }}</small></span></li>
            </ol>
          </section>
          <section v-if="myEvents.length" class="ci-card ci-events" aria-labelledby="ci-events-title">
            <h2 id="ci-events-title" class="ci-card__title">Your upcoming events</h2>
            <ul>
              <li v-for="item in myEvents" :key="item.id">
                <span class="ci-events__date"><b>{{ new Date(item.event_starts_at || item.event_date).toLocaleDateString(undefined, { month: 'short' }) }}</b>{{ new Date(item.event_starts_at || item.event_date).getDate() }}</span>
                <span class="ci-events__text"><strong>{{ item.event_name }}</strong><small>{{ isToday(item.event_starts_at || item.event_date) ? 'Today' : fmtDay(item.event_starts_at || item.event_date) }} · {{ item.event_current_location || item.event_location }}</small></span>
              </li>
            </ul>
            <p class="ci-muted">When you arrive, scan the QR code at the entrance to check in.</p>
          </section>
        </aside>
      </div>
    </section>
  </UserLayout>
</template>

<style scoped>
.ci { --ci-ink: #17365f; --ci-text: #3d5470; --ci-muted: #6b8099; --ci-line: #dfe7ef; --ci-soft: #f6f9fc; --ci-brand: #0b7f62; --ci-brand-dark: #086a51; --ci-brand-soft: #e8f5f0; width: min(100% - 32px, 1080px); margin: 0 auto; padding: 32px 0 56px; color: var(--ci-text); font-size: 15px; line-height: 1.5; }
.ci button { margin: 0; }
.ci h1, .ci h2 { color: var(--ci-ink); letter-spacing: -.01em; }
.ci-head { margin-bottom: 20px; }
.ci-head h1 { margin: 0 0 4px; font-size: 1.9rem; font-weight: 700; }
.ci-head p { max-width: 560px; margin: 0; color: var(--ci-muted); }
.ci-layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(280px, 1fr); align-items: start; gap: 20px; }
.ci-layout > * { min-width: 0; }
.ci-side { display: grid; gap: 16px; }
.ci-card { border: 1px solid var(--ci-line); border-radius: 16px; background: #fff; box-shadow: 0 6px 20px rgba(23, 54, 95, .05); }
.ci-card__title { margin: 0 0 14px; font-size: 1.05rem; font-weight: 700; }
.ci-muted { margin: 10px 0 0; color: var(--ci-muted); font-size: .84rem; }
.ci-eyebrow { margin: 0 0 4px; color: var(--ci-brand); font-size: .76rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }

/* Scanner */
.ci-scan { padding: 22px; }
.ci-camera { position: relative; overflow: hidden; border-radius: 14px; background: #0f2638; aspect-ratio: 4 / 3; }
.ci-camera :deep(.scanner) { display: block; height: 100%; margin: 0; padding: 0; border: 0; background: none; }
.ci-camera :deep(.camera-reader) { width: 100% !important; height: 100%; min-height: 0; border: 0 !important; border-radius: 0; background: transparent; }
.ci-camera :deep(video) { width: 100% !important; height: 100% !important; object-fit: cover; }
.ci-camera :deep(#qr-shaded-region), .ci-camera :deep([id$="__dashboard"]) { display: none !important; }
.ci-frame { position: absolute; inset: 50% auto auto 50%; width: min(62%, 260px); aspect-ratio: 1; transform: translate(-50%, -50%); pointer-events: none; }
.ci-frame i { position: absolute; width: 34px; height: 34px; border-color: #6ee7b7; border-style: solid; }
.ci-frame i:nth-child(1) { top: 0; left: 0; border-width: 4px 0 0 4px; border-radius: 12px 0 0 0; }
.ci-frame i:nth-child(2) { top: 0; right: 0; border-width: 4px 4px 0 0; border-radius: 0 12px 0 0; }
.ci-frame i:nth-child(3) { bottom: 0; left: 0; border-width: 0 0 4px 4px; border-radius: 0 0 0 12px; }
.ci-frame i:nth-child(4) { right: 0; bottom: 0; border-width: 0 4px 4px 0; border-radius: 0 0 12px 0; }
.ci-camera__status { position: absolute; bottom: 14px; left: 50%; display: flex; align-items: center; gap: 8px; margin: 0; padding: 6px 12px; border-radius: 99px; background: rgba(10, 30, 45, .75); color: #e6f0f3; font-size: .85rem; transform: translateX(-50%); white-space: nowrap; }
.ci-camera-off { display: grid; justify-items: center; gap: 6px; padding: 32px 20px; border: 1px dashed #d6e0ea; border-radius: 14px; background: var(--ci-soft); text-align: center; }
.ci-camera-off strong { color: var(--ci-ink); font-size: 1.05rem; }
.ci-camera-off p { max-width: 420px; margin: 0 0 8px; color: var(--ci-muted); font-size: .9rem; }
.ci-scan__hint { display: flex; align-items: center; gap: 6px; margin: 12px 0 0; color: var(--ci-muted); font-size: .86rem; }
.ci-rule { display: flex; align-items: flex-start; gap: 8px; margin: 14px 0 0; padding: 12px 14px; border-radius: 10px; background: var(--ci-soft); color: var(--ci-text); font-size: .86rem; }
.ci-rule svg { flex: none; margin-top: 2px; color: var(--ci-brand); }

/* States */
.ci-state, .ci-done { display: grid; justify-items: center; gap: 8px; padding: 40px 24px; text-align: center; }
.ci-state h2, .ci-done h2 { margin: 6px 0 0; font-size: 1.35rem; font-weight: 700; outline: 0; } /* older global h2 rules lighten the weight */
.ci-state p, .ci-done p { max-width: 440px; margin: 0; color: var(--ci-muted); }
.ci-icon { display: grid; width: 56px; height: 56px; place-items: center; border-radius: 50%; background: var(--ci-brand-soft); color: var(--ci-brand); }
.ci-icon.is-warning { background: #fff4dc; color: #8a5300; }
.ci-done__check { display: grid; width: 72px; height: 72px; place-items: center; border-radius: 50%; background: var(--ci-brand); color: #fff; box-shadow: 0 0 0 10px var(--ci-brand-soft); }
.ci-done p strong { color: var(--ci-ink); }
.ci-done__time { display: inline-flex !important; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 99px; background: var(--ci-brand-soft); color: var(--ci-brand-dark) !important; font-weight: 600; font-size: .88rem; }
.ci-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 12px; }

/* Confirm */
.ci-confirm { display: grid; gap: 14px; padding: 24px; }
.ci-confirm h2 { margin: 0; font-size: 1.4rem; font-weight: 700; outline: 0; }
.ci-facts { display: grid; gap: 4px; margin: -6px 0 0; padding: 0; list-style: none; color: var(--ci-muted); font-size: .92rem; }
.ci-facts li { display: flex; align-items: center; gap: 8px; }
.ci-person { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px; border: 1px solid var(--ci-line); border-radius: 12px; background: var(--ci-soft); }
.ci-person > span:nth-child(2) { display: grid; min-width: 0; flex: 1; }
.ci-person strong { color: var(--ci-ink); }
.ci-person small { overflow: hidden; color: var(--ci-muted); font-family: ui-monospace, monospace; font-size: .8rem; text-overflow: ellipsis; }
.ci-avatar { display: grid; width: 46px; height: 46px; flex: none; place-items: center; border-radius: 50%; background: linear-gradient(135deg, #0b7f62, #13a37f); color: #fff; font-weight: 700; }
.ci-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 99px; background: #fff4dc; color: #8a5300; font-size: .78rem; font-weight: 700; }
.ci-details { border: 1px solid var(--ci-line); border-radius: 12px; }
.ci-details summary { padding: 12px 14px; color: var(--ci-ink); font-weight: 600; font-size: .92rem; cursor: pointer; }
.ci-details dl { display: grid; gap: 0; margin: 0; padding: 0 14px 8px; }
.ci-details dl > div { display: flex; justify-content: space-between; gap: 14px; padding: 8px 0; border-top: 1px solid var(--ci-line); }
.ci-details dt { color: var(--ci-muted); font-size: .86rem; }
.ci-details dd { margin: 0; color: var(--ci-ink); font-weight: 600; font-size: .88rem; text-align: right; overflow-wrap: anywhere; }
.ci-alert { display: flex; align-items: center; gap: 8px; margin: 0; padding: 10px 14px; border: 1px solid #f2c4bf; border-radius: 10px; background: #fdf1f0; color: #8f2a20; font-weight: 600; font-size: .9rem; }

/* Side */
.ci-how { padding: 20px; }
.ci-how ol { display: grid; gap: 14px; margin: 0; padding: 0; list-style: none; }
.ci-how li { display: flex; gap: 12px; }
.ci-how li > span:last-child { display: grid; }
.ci-how strong { color: var(--ci-ink); font-size: .92rem; }
.ci-how small { color: var(--ci-muted); font-size: .84rem; }
.ci-how__icon { display: grid; width: 36px; height: 36px; flex: none; place-items: center; border-radius: 10px; background: var(--ci-brand-soft); color: var(--ci-brand); }
.ci-events { padding: 20px; }
.ci-events ul { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
.ci-events li { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid var(--ci-line); border-radius: 12px; }
.ci-events__date { display: grid; width: 44px; height: 46px; flex: none; place-items: center; align-content: center; border-radius: 10px; background: var(--ci-brand-soft); color: var(--ci-brand-dark); font-size: 1.05rem; font-weight: 700; line-height: 1.1; }
.ci-events__date b { font-size: .66rem; letter-spacing: .06em; text-transform: uppercase; }
.ci-events__text { display: grid; min-width: 0; flex: 1; }
.ci-events__text strong { overflow: hidden; color: var(--ci-ink); font-size: .9rem; text-overflow: ellipsis; white-space: nowrap; }
.ci-events__text small { overflow: hidden; color: var(--ci-muted); font-size: .8rem; text-overflow: ellipsis; white-space: nowrap; }

/* Buttons */
.ci-btn { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 8px; padding: 0 18px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .94rem; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; }
.ci-btn svg { flex: none; }
.ci-btn--sm { min-height: 36px; padding: 0 12px; font-size: .85rem; }
.ci-btn--lg { width: 100%; min-height: 52px; font-size: 1.02rem; }
.ci-btn--primary { background: var(--ci-brand); color: #fff; box-shadow: 0 6px 16px rgba(11, 127, 98, .22); }
.ci-btn--primary:hover:not(:disabled) { background: var(--ci-brand-dark); }
.ci-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--ci-ink); }
.ci-btn--ghost:hover:not(:disabled) { border-color: #aebfd1; background: var(--ci-soft); }
.ci-btn--text { min-height: 40px; background: none; color: var(--ci-muted); font-weight: 500; }
.ci-btn--text:hover { color: var(--ci-ink); transform: none; }
.ci-btn:disabled { opacity: .6; cursor: progress; }
.ci-btn:focus-visible, .ci-details summary:focus-visible { outline: 3px solid rgba(11, 127, 98, .3); outline-offset: 2px; }
.ci-spin { animation: ci-spin 1s linear infinite; }
@keyframes ci-spin { to { transform: rotate(360deg); } }

@media (max-width: 860px) {
  .ci-layout { grid-template-columns: 1fr; }
  .ci-side { order: 2; }
}
@media (max-width: 560px) {
  .ci { width: min(100% - 24px, 1080px); padding-top: 20px; }
  .ci-head h1 { font-size: 1.5rem; }
  .ci-scan, .ci-confirm { padding: 16px; }
  .ci-camera { aspect-ratio: 1; }
  .ci-actions { flex-direction: column; width: 100%; }
  .ci-actions .ci-btn { width: 100%; }
  .ci-badge { margin-left: 58px; }
}
@media (prefers-reduced-motion: reduce) { .ci-spin { animation: none; } }
</style>
