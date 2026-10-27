<script setup>
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import FormRenderer from '../../components/FormRenderer.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { buildSubmission, normalizeStoredValue } from '../../utils/formValidation'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const registration = ref(null)
const event = ref(null)
const formConfig = ref(null)
const serverErrors = ref({})
const initialValues = ref({})
const step = ref(0)

const fields = computed(() => {
  const list = formConfig.value?.fields || []
  const hasGenderChoice = list.some((field) => { const label = String(field.label || '').trim().toLowerCase(); return (label === 'gender' || label.includes('ភេទ')) && field.type === 'radio' })
  return list.filter((field) => {
    const label = String(field.label || '').trim().toLowerCase()
    if (label.includes('business entity') || label.includes('អង្គភាពអាជីវកម្ម')) return false
    return !(hasGenderChoice && (label === 'gender' || label.includes('ភេទ')) && field.type === 'text')
  })
})
const steps = computed(() => formConfig.value?.steps || [])
const settings = computed(() => formConfig.value?.settings || {})
// Same event summary as the registration form (RegistrationForm.vue), so both pages read alike.
const banner = computed(() => event.value?.branding?.image || event.value?.image || '')
const dateLabel = computed(() => {
  if (!event.value?.starts_at) return 'Date to be confirmed'
  const start = new Date(event.value.starts_at)
  const end = event.value.ends_at ? new Date(event.value.ends_at) : null
  const format = (date) => date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
  if (!end || start.toDateString() === end.toDateString()) return format(start)
  return `${format(start)} – ${format(end)}`
})
const timeLabel = computed(() => {
  if (!event.value?.starts_at) return ''
  const time = (value) => new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
  return event.value.ends_at ? `${time(event.value.starts_at)} – ${time(event.value.ends_at)}` : time(event.value.starts_at)
})
// The deadline is a calendar date: editing closes at the end of that day.
const deadlineLabel = computed(() => (registration.value?.registration_deadline
  ? new Date(`${String(registration.value.registration_deadline).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  : ''))
const alertEl = ref(null)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const id = route.params.id
    const registrationResponse = await api.get(`/registrations/${id}`)
    registration.value = registrationResponse.data.data

    const [eventResponse, formResponse] = await Promise.all([
      api.get(`/events/${registration.value.event_id}`),
      api.get(`/events/${registration.value.event_id}/form`),
    ])
    event.value = eventResponse.data.data
    formConfig.value = formResponse.data.data

    const stored = registration.value.form_data || {}
    const labeled = registration.value.form_values || {}
    const profileValues = {
      name: registration.value.full_name,
      'full name': registration.value.full_name,
      email: registration.value.email,
      phone: registration.value.phone,
      gender: registration.value.gender,
      age: registration.value.age,
      'age group': registration.value.age_group,
      disability: registration.value.disability_type,
      'disability type': registration.value.disability_type,
      organization: registration.value.organization,
      institution: registration.value.organization,
      position: registration.value.position,
      address: registration.value.address,
      telephone: registration.value.telephone,
      'business entity': registration.value.business_entity,
      'emergency contact name': registration.value.emergency_contact_name,
      'emergency contact phone': registration.value.emergency_contact_phone,
      'photo consent': registration.value.photo_consent,
      signature: registration.value.signature,
    }
    const normalizedLabel = (label) => String(label || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, ' ')
    const normalizedLabeled = Object.entries(labeled).reduce((result, [label, value]) => {
      result[normalizedLabel(label)] = value
      return result
    }, {})
    const values = {}
    for (const field of fields.value) {
      const hasStored = Object.prototype.hasOwnProperty.call(stored, field.id) || Object.prototype.hasOwnProperty.call(stored, String(field.id))
      const fieldLabel = normalizedLabel(field.label)
      const storedValue = hasStored
        ? stored[field.id]
        : normalizedLabeled[fieldLabel] ?? profileValues[fieldLabel]
      values[field.id] = normalizeStoredValue(field, storedValue)
    }
    initialValues.value = values

  } catch (requestError) {
    error.value = !requestError.response ? 'We couldn’t reach the server. Check your connection and try again.' : requestError.response.status === 403 || requestError.response.status === 404 ? 'This registration isn’t available.' : requestError.response.data?.message || 'Registration could not be loaded.'
  } finally {
    loading.value = false
  }
}

const onStepChange = (index) => { step.value = index; serverErrors.value = {} }

const submit = async (values) => {
  if (!registration.value?.can_edit) return
  saving.value = true
  error.value = ''
  serverErrors.value = {}
  try {
    const formData = buildSubmission(fields.value, values)
    const { data } = await api.put(`/registrations/${registration.value.id}`, {
      event_id: registration.value.event_id,
      form_data: formData,
    })
    registration.value = data.data
    // Return to My information after a successful update so the attendee can
    // immediately see the saved registration details.
    await router.push('/me')
  } catch (requestError) {
    const payloadErrors = requestError.response?.data?.errors || {}
    const mapped = {}
    for (const [key, messages] of Object.entries(payloadErrors)) {
      const match = key.match(/^form_data\.(\w+)$/)
      if (match) mapped[match[1]] = Array.isArray(messages) ? messages[0] : String(messages)
      else error.value = Array.isArray(messages) ? messages[0] : String(messages)
    }
    serverErrors.value = mapped
    if (!error.value) error.value = !requestError.response ? 'Your changes weren’t saved because we couldn’t reach the server.' : requestError.response.status >= 500 ? 'Something went wrong on our side. Please try again.' : requestError.response.data?.message || 'Changes could not be saved.'
    if (!Object.keys(mapped).length) { await nextTick(); alertEl.value?.focus() }
  } finally {
    saving.value = false
  }
}

load()
</script>

<template>
  <UserLayout>
    <section class="rg-page">
      <RouterLink class="rg-back" to="/me"><AppIcon name="chevron-left" :size="16" />Back to my registrations</RouterLink>

      <div v-if="loading" class="rg-layout" role="status" aria-label="Loading your registration">
        <div class="rg-skel rg-skel--side"></div>
        <div class="rg-skel rg-skel--form"></div>
      </div>

      <div v-else-if="!registration || !event || !formConfig" class="rg-card rg-state" role="alert">
        <span class="rg-state__icon is-error"><AppIcon name="alert-circle" :size="26" /></span>
        <h1>{{ error || 'Registration not found' }}</h1>
        <div class="rg-state__actions"><button type="button" class="rg-btn" @click="load"><AppIcon name="refresh" :size="16" />Try again</button><RouterLink class="rg-btn rg-btn--primary" to="/me">My registrations</RouterLink></div>
      </div>

      <div v-else class="rg-layout">
        <!-- Which event and which registration this is -->
        <aside class="rg-event" aria-label="Event summary">
          <img v-if="banner" class="rg-event__banner" :src="banner" alt="" />
          <div class="rg-event__body">
            <span v-if="event.category" class="rg-tag">{{ event.category }}</span>
            <h2>{{ event.name }}</h2>
            <ul class="rg-facts">
              <li><AppIcon name="calendar" :size="16" /><span>{{ dateLabel }}</span></li>
              <li v-if="timeLabel"><AppIcon name="clock" :size="16" /><span>{{ timeLabel }}</span></li>
              <li><AppIcon name="pin" :size="16" /><span>{{ event.location || 'Location to be confirmed' }}</span></li>
              <li><AppIcon name="ticket" :size="16" /><span>Registration <strong>{{ registration.registration_code }}</strong></span></li>
            </ul>
            <p class="rg-status" :class="registration.can_edit ? 'is-open' : 'is-closed'">
              <AppIcon :name="registration.can_edit ? 'pen' : 'lock'" :size="16" />
              <span v-if="registration.can_edit"><strong>Editing open</strong>{{ deadlineLabel ? ` · until ${deadlineLabel}` : '' }}</span>
              <span v-else><strong>Editing closed</strong></span>
            </p>
            <p v-if="event.description" class="rg-event__desc">{{ event.description }}</p>
          </div>
        </aside>

        <div class="rg-main">
          <div v-if="!registration.can_edit" class="rg-card rg-state">
            <span class="rg-state__icon"><AppIcon name="lock" :size="24" /></span>
            <h1>Editing is closed</h1>
            <p>{{ deadlineLabel ? `Changes could be made until ${deadlineLabel}.` : 'The organiser isn’t accepting changes to this registration.' }} Contact the organiser if something needs correcting.</p>
            <div class="rg-state__actions"><RouterLink class="rg-btn" :to="`/events/${registration.event_id}`">View event</RouterLink><RouterLink class="rg-btn rg-btn--primary" to="/me">My registrations</RouterLink></div>
          </div>

          <div v-else class="rg-card rg-form">
            <header class="rg-form__head">
              <h1>Edit your registration</h1>
              <p>Update your details for {{ event.name }}. Your registration code and QR pass stay the same.</p>
            </header>

            <div v-if="error" ref="alertEl" class="rg-alert is-error" role="alert" tabindex="-1">
              <AppIcon name="alert-circle" :size="20" />
              <div><strong>Your changes weren’t saved</strong><p>{{ error }}</p></div>
            </div>

            <FormRenderer
              hide-header
              :fields="fields"
              :steps="steps"
              :active-step="step"
              :settings="settings"
              :initial-values="initialValues"
              :server-errors="serverErrors"
              :submitting="saving"
              submit-label="Save changes"
              submitting-label="Saving…"
              @step-change="onStepChange"
              @submit="submit"
            />
          </div>
        </div>
      </div>
    </section>
  </UserLayout>
</template>
