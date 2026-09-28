<script setup>
/**
 * Public registration form — renders exactly what the admin built in the
 * Form Builder (fields, types, labels, options, required flags, descriptions,
 * order, steps, and form settings) via the shared FormRenderer.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormRenderer from './FormRenderer.vue'
import { useAuthStore } from '../stores/auth'
import { useRegistrationStore } from '../stores/registration'
import api from '../services/api'
import { buildSubmission, displayValue, normalizeStoredValue, validateFields } from '../utils/formValidation'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useRegistrationStore()

const event = ref(null)
const formConfig = ref(null)
const loading = ref(true)
const loadError = ref('')
const step = ref(0) // active builder step in the renderer
const review = ref(false)
const errors = ref({})
const fieldValues = ref({})

const eventId = () => route.params.eventId || route.query.event_id
const fields = computed(() => formConfig.value?.fields || [])
const steps = computed(() => formConfig.value?.steps || [])
const settings = computed(() => formConfig.value?.settings || {})

const load = async () => {
  try {
    const { data } = await api.get(`/events/${eventId()}/form`)
    formConfig.value = data.data
    const eventResponse = await api.get(`/events/${eventId()}`)
    event.value = eventResponse.data.data
  } catch (requestError) {
    loadError.value = requestError.response?.status === 404
      ? 'This event does not exist or the registration link is out of date.'
      : 'Cannot reach the registration service. Please try again shortly.'
  } finally {
    loading.value = false
  }
}

/** Prefill text/email/phone fields from the signed-in attendee's account. */
const prefill = () => {
  const user = auth.user || {}
  const values = {}
  for (const field of fields.value) {
    let value = normalizeStoredValue(field, '')
    if (field.type === 'email' && !value) value = user.email || ''
    if (field.type === 'text' && !value && /full.?name/i.test(field.label)) value = user.name || ''
    if (field.type === 'phone' && !value) value = user.phone || ''
    values[field.id] = value
  }
  return values
}

const start = () => { fieldValues.value = prefill() }

/** Renderer validated the current step before emitting; just track the index. */
const onStepChange = (index) => { step.value = index; errors.value = {} }

/** Renderer finished (all steps validated) → show the review screen. */
const startReview = (values) => {
  fieldValues.value = values
  errors.value = {}
  review.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const editAnswers = () => {
  review.value = false
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const submit = async () => {
  store.error = null
  errors.value = {}
  try {
    const formData = buildSubmission(fields.value, fieldValues.value)
    await store.register({ event_id: Number(eventId()), form_data: formData })
    router.push('/registration/success')
  } catch (requestError) {
    const payloadErrors = requestError.response?.data?.errors || {}
    const mapped = {}
    for (const [key, messages] of Object.entries(payloadErrors)) {
      const match = key.match(/^form_data\.(\w+)$/)
      const message = Array.isArray(messages) ? messages[0] : String(messages)
      if (match) mapped[match[1]] = message
      else store.error = store.error || message
    }
    if (!Object.keys(mapped).length && !store.error) {
      store.error = requestError.response?.data?.message || 'Registration could not be submitted.'
    }
    errors.value = mapped
    review.value = false // jump back to the form so errors are visible inline
  }
}

const eventDate = computed(() => event.value?.starts_at
  ? new Date(event.value.starts_at).toLocaleDateString(undefined, { dateStyle: 'full' })
  : event.value?.start_date || 'Date to be confirmed')

load().then(start)
</script>

<template>
  <section class="dynamic-registration-page">
    <div v-if="loading" class="dynamic-loading">Loading event registration form…</div>

    <div v-else-if="event && formConfig" class="dynamic-registration-shell">
      <header class="dynamic-event-hero">
        <img v-if="event.branding?.image || event.image" :src="event.branding?.image || event.image" alt="Event banner" />
        <div class="dynamic-event-hero-copy">
          <span class="event-category">{{ event.category || 'Event registration' }}</span>
          <h1>{{ event.name }}</h1>
          <p>{{ event.description || 'Complete the form below to reserve your place.' }}</p>
          <div class="dynamic-event-meta">
            <span>📅 {{ eventDate }}</span>
            <span>🕐 {{ event.starts_at ? new Date(event.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : event.start_time || 'Time pending' }}</span>
            <span>📍 {{ event.location || 'Location pending' }}</span>
          </div>
        </div>
      </header>

      <div class="dynamic-registration-body">
        <div v-if="!formConfig.registration_open" class="dynamic-form-closed">
          <strong>Registration is closed</strong>
          <p>This event is no longer accepting registrations.</p>
        </div>

        <template v-else>
          <!-- Review screen -->
          <section v-if="review" class="dynamic-review dynamic-form-card">
            <p class="admin-eyebrow">Review registration</p>
            <h2>Check your information</h2>
            <p class="dynamic-review-hint">Confirm everything is correct before submitting.</p>
            <div class="dynamic-review-list">
              <div v-for="field in fields.filter((f) => f.type !== 'heading')" :key="field.id">
                <span>{{ field.label }}<em v-if="field.required" class="dynamic-required">*</em></span>
                <strong>{{ displayValue(field, fieldValues[field.id]) }}</strong>
              </div>
            </div>
            <p v-if="store.error" class="dynamic-form-error" role="alert">{{ store.error }}</p>
            <div class="dynamic-review-actions">
              <button type="button" class="secondary-button" @click="editAnswers">← Back to form</button>
              <button type="button" class="primary-button" :disabled="store.loading" @click="submit">
                {{ store.loading ? 'Submitting…' : 'Confirm registration' }}
              </button>
            </div>
          </section>

          <!-- Live form, exactly as configured in the builder -->
          <div v-else class="dynamic-form-wrapper">
            <p v-if="store.error" class="dynamic-form-error" role="alert">{{ store.error }}</p>
            <FormRenderer
              :fields="fields"
              :steps="steps"
              :active-step="step"
              :form-title="formConfig.form_title"
              :form-description="formConfig.form_description"
              :event-name="event.name"
              :settings="settings"
              :initial-values="fieldValues"
              :server-errors="errors"
              submit-label="Continue to review →"
              @step-change="onStepChange"
              @submit="startReview"
            />
          </div>
        </template>
      </div>
    </div>

    <div v-else class="dynamic-empty">
      <h2>{{ loadError || 'Event not found' }}</h2>
      <RouterLink to="/">Return home</RouterLink>
    </div>
  </section>
</template>
