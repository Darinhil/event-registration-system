<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import { useRegistrationStore } from '../../stores/registration'
import { getRegistration } from '../../services/registrationService'
import { formatDate } from '../../utils/formatDate'

const route = useRoute()
const store = useRegistrationStore()
const registration = ref(null)
const loading = ref(false)
const error = ref('')
let requestVersion = 0
const qrValue = computed(() => registration.value?.qr_token || registration.value?.registration_code || '')
const statusLabel = computed(() => {
  const status = registration.value?.status || 'confirmed'
  return status.charAt(0).toUpperCase() + status.slice(1)
})

const loadRegistration = async () => {
  const currentRequest = ++requestVersion
  loading.value = false
  error.value = ''
  registration.value = null
  const id = typeof route.query.id === 'string' ? route.query.id : ''

  if (!id) {
    registration.value = store.current
    if (!registration.value) error.value = 'Open this pass from My Information to load its registration details.'
    return
  }

  if (String(store.current?.id) === id) {
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
    error.value = requestError.response?.data?.message || 'Unable to load this registration. Please try again.'
  } finally {
    if (currentRequest === requestVersion) loading.value = false
  }
}

const downloadQr = () => {
  const canvas = document.querySelector('.confirmation-qr canvas')
  if (!canvas || !registration.value) return
  const anchor = document.createElement('a')
  anchor.download = `${registration.value.registration_code || 'registration'}-qr.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
}

const addToCalendar = () => {
  if (!registration.value) return
  const start = registration.value.event_date ? new Date(registration.value.event_date) : new Date()
  const end = new Date(start.getTime() + 2 * 60 * 60 * 1000)
  const format = (value) => value.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z'
  const url = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(registration.value.event_name || 'Event')}&dates=${format(start)}/${format(end)}&location=${encodeURIComponent(registration.value.event_location || '')}`
  window.open(url, '_blank', 'noopener')
}

watch(() => route.query.id, loadRegistration, { immediate: true })
</script>

<template>
  <UserLayout>
    <section class="confirmation-page">
      <div class="confirmation-hero">
        <span class="confirmation-check" aria-hidden="true">{{ registration ? '✓' : 'i' }}</span>
        <p class="eyebrow">{{ registration ? 'Registration details' : 'Registration confirmation' }}</p>
        <h1>{{ registration ? (qrValue ? 'Your registration pass' : 'Registration details') : loading ? 'Loading your pass…' : 'Confirmation unavailable' }}</h1>
        <p v-if="registration">{{ qrValue ? 'Your registration details and event pass are ready below.' : 'Your registration details are ready below.' }}</p>
        <p v-else-if="loading" role="status">Retrieving your registration details.</p>
        <p v-else role="alert">{{ error || 'Registration details could not be found.' }}</p>
      </div>

      <div v-if="registration" class="confirmation-layout">
        <article class="confirmation-details">
          <div class="confirmation-section-heading">
            <div>
              <p class="field-label">Event details</p>
              <h2>{{ registration.event_name }}</h2>
            </div>
            <span class="confirmation-status">{{ statusLabel }}</span>
          </div>
          <div class="confirmation-info">
            <div><span>Registration ID</span><strong>{{ registration.registration_code || registration.id }}</strong></div>
            <div><span>Participant</span><strong>{{ registration.full_name || 'Attendee' }}</strong></div>
            <div><span>Event date</span><strong>{{ formatDate(registration.event_date) || 'To be confirmed' }}</strong></div>
            <div><span>Location</span><strong>{{ registration.event_location || 'To be confirmed' }}</strong></div>
          </div>
        </article>
        <article v-if="qrValue" class="confirmation-pass">
          <p class="field-label">Participant pass</p>
          <div class="confirmation-qr"><QRCodeDisplay :value="qrValue" /></div>
          <p>Present this QR code at check-in.</p>
          <button type="button" class="button" @click="downloadQr">Download QR code</button>
        </article>
      </div>
      <div v-else-if="error && !loading" class="confirmation-details">
        <button v-if="route.query.id" type="button" class="button" @click="loadRegistration">Try again</button>
        <RouterLink class="button" to="/me">View my registrations</RouterLink>
      </div>

      <footer v-if="registration" class="confirmation-actions">
        <RouterLink class="button" to="/me">My registrations</RouterLink>
        <button type="button" class="link-button" @click="addToCalendar">Add to calendar</button>
      </footer>
    </section>
  </UserLayout>
</template>
