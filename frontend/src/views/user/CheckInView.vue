<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import QRScanner from '../../components/QRScanner.vue'
import { useAuthStore } from '../../stores/auth'
import { useCheckInStore } from '../../stores/checkIn'
import { parseQrPayload } from '../../utils/qr'
import { formatDate } from '../../utils/formatDate'

const route = useRoute()
const auth = useAuthStore()
const store = useCheckInStore()
const registration = ref(null)

const initials = computed(() => (registration.value?.full_name || auth.user?.name || 'A').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase())
const formEntries = computed(() => Object.entries(registration.value?.form_values || {}))
const alreadyIn = computed(() => Boolean(registration.value?.checked_in_at) || Boolean(store.result))
const checkedInAt = computed(() => registration.value?.checked_in_at || store.result?.check_in?.checked_in_at)

/** Venue QRs may be a JSON payload, an /check-in?event=ID link, or /check-in?e=<entrance token>. */
const eventFromScan = (value) => {
  const payload = parseQrPayload(value)
  if (payload?.event_id !== undefined && Number.isFinite(Number(payload.event_id))) return { eventId: Number(payload.event_id) }
  if (payload?.e && /^[0-9a-f-]{36}$/i.test(String(payload.e))) return { entranceToken: payload.e }
  try {
    const url = new URL(value)
    const event = Number(url.searchParams.get('event'))
    if (event) return { eventId: event }
    const entrance = url.searchParams.get('e')
    if (entrance && /^[0-9a-f-]{36}$/i.test(entrance)) return { entranceToken: entrance }
  } catch { /* plain text */ }
  return null
}

const resolveRegistration = async ({ eventId, entranceToken } = {}) => {
  await auth.fetchMe()
  await store.preview(entranceToken ? { entrance_token: entranceToken } : { event_id: eventId })
  registration.value = store.registration
  if (!store.registration) store.error = store.error || 'You are not registered for this event.'
}

/** Deep link from a scanned entrance QR: /check-in?event=ID or ?e=<token>. */
onMounted(async () => {
  const eventId = Number(route.query.event)
  const entranceToken = typeof route.query.e === 'string' ? route.query.e : ''
  if (eventId || entranceToken) await resolveRegistration(entranceToken ? { entranceToken } : { eventId })
})

const onScan = async (value) => {
  const target = eventFromScan(value)
  if (!target) { store.error = 'This QR code is not an event check-in code.'; return }
  await resolveRegistration(target)
}

const confirm = async () => {
  try { await store.submit(registration.value.qr_token) } catch { /* error is shown on the card */ }
}
const reset = () => { registration.value = null; store.reset() }
</script>

<template>
  <UserLayout>
    <section class="panel narrow ci-page">
      <p class="eyebrow">At the door</p>
      <h1>Check in</h1>
      <p class="muted ci-lede">Scan the event QR code at the venue entrance to confirm your attendance.</p>

      <template v-if="!registration">
        <p v-if="store.error" class="error" role="alert">{{ store.error }}</p>
        <p v-if="store.loading" class="muted">Looking up your registration…</p>
        <QRScanner @scan="onScan" />
      </template>

      <template v-else>
        <article v-if="!alreadyIn" class="ci-card">
          <header class="ci-card-head">
            <p class="ci-kicker">Registration found</p>
            <h2>{{ registration.event_name }}</h2>
            <p class="ci-meta">{{ formatDate(registration.event_date) }} · {{ registration.event_location || 'Venue to be confirmed' }}</p>
          </header>

          <div class="ci-identity">
            <span class="ci-avatar">{{ initials }}</span>
            <div>
              <strong>{{ registration.full_name || auth.user?.name }}</strong>
              <small>{{ registration.registration_code }}</small>
            </div>
            <span class="ci-badge" :class="{ 'ci-badge--in': alreadyIn }">{{ alreadyIn ? 'Checked in' : 'Not checked in' }}</span>
          </div>

          <div v-if="formEntries.length" class="ci-values">
            <p class="ci-values-title">Information you provided</p>
            <dl>
              <div v-for="[label, value] in formEntries" :key="label">
                <dt>{{ label }}</dt>
                <dd>{{ value }}</dd>
              </div>
            </dl>
          </div>

          <p v-if="store.error" class="error" role="alert">{{ store.error }}</p>

          <button v-if="!alreadyIn" type="button" class="button ci-confirm" :disabled="store.checkingIn" @click="confirm">
            {{ store.checkingIn ? 'Checking in…' : 'I\'m here — join the event' }}
          </button>
          <button type="button" class="link-button ci-secondary" @click="reset">Scan a different code</button>
        </article>

        <article v-else class="ci-card ci-card--success">
          <span class="ci-success-check">✓</span>
          <h2>You're checked in!</h2>
          <p class="ci-success-copy">Welcome, {{ registration.full_name || auth.user?.name }}. Your attendance at <strong>{{ registration.event_name }}</strong> has been recorded.</p>
          <p class="ci-time">Checked in at {{ formatDate(checkedInAt) }}</p>
          <button type="button" class="link-button ci-secondary" @click="reset">Scan another event</button>
        </article>
      </template>
    </section>
  </UserLayout>
</template>

<style scoped>
.ci-page { display: grid; gap: 16px; }
.ci-lede { margin: 0; font-size: 0.85rem; }
.ci-card { display: grid; gap: 16px; padding: 22px; border: 1px solid #dce8f4; border-radius: 13px; background: #fff; }
.ci-card-head h2 { margin: 4px 0 6px; color: #16345f; font-size: 1.25rem; }
.ci-kicker { margin: 0; color: #3978d8; font: 600 0.62rem 'DM Mono', monospace; letter-spacing: 0.13em; text-transform: uppercase; }
.ci-meta { margin: 0; color: #7890a8; font-size: 0.75rem; }
.ci-identity { display: flex; align-items: center; gap: 12px; padding: 13px; border: 1px solid #e4ebf2; border-radius: 10px; background: #f8fafd; }
.ci-avatar { display: grid; place-items: center; flex: 0 0 auto; width: 44px; height: 44px; border-radius: 50%; background: #eef5ff; color: #277cf2; font-weight: 700; }
.ci-identity div { display: grid; gap: 2px; min-width: 0; }
.ci-identity strong { color: #16345f; font-size: 0.9rem; }
.ci-identity small { color: #8295aa; font: 0.68rem 'DM Mono', monospace; }
.ci-badge { margin-left: auto; padding: 5px 10px; border-radius: 999px; background: #fff1dc; color: #c27c17; font-size: 0.62rem; font-weight: 700; white-space: nowrap; }
.ci-badge--in { background: #dff8eb; color: #0b9b63; }
.ci-values { display: grid; gap: 8px; }
.ci-values-title { margin: 0; color: #8295aa; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
.ci-values dl { display: grid; gap: 6px; margin: 0; }
.ci-values dl > div { display: flex; justify-content: space-between; gap: 14px; padding: 9px 12px; border: 1px solid #e4ebf2; border-radius: 8px; }
.ci-values dt { color: #8295aa; font-size: 0.72rem; }
.ci-values dd { margin: 0; color: #365783; font-size: 0.75rem; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.ci-confirm { width: 100%; margin: 0; }
.ci-secondary { width: 100%; text-align: center; color: #277cf2; font-size: 0.75rem; cursor: pointer; }
.ci-card--success { text-align: center; }
.ci-card--success h2 { margin: 6px 0 8px; color: #16345f; }
.ci-success-check { display: grid; place-items: center; width: 54px; height: 54px; margin: 0 auto; border-radius: 50%; background: #dff8eb; color: #0b9b63; font-size: 1.6rem; font-weight: 700; }
.ci-success-copy { margin: 0; color: #52688a; font-size: 0.85rem; line-height: 1.55; }
.ci-time { margin: 0; color: #0b9b63; font: 700 0.72rem 'DM Mono', monospace; }
</style>