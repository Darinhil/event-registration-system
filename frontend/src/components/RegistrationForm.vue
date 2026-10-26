<script setup>
/**
 * Public registration page — renders exactly what the organizer built in the
 * Form Builder via the shared FormRenderer, and submits to the API. A success
 * screen is only shown after the server confirms the registration.
 */
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormRenderer from './FormRenderer.vue'
import AppIcon from './AppIcon.vue'
import { useRegistrationStore } from '../stores/registration'
import api from '../services/api'
import { buildSubmission } from '../utils/formValidation'

const route = useRoute(); const router = useRouter(); const store = useRegistrationStore()
const eventId = computed(() => route.params.eventId)
const event = ref(null)
const form = ref({ fields: [], steps: [], settings: {} })
const registration = ref({ open: true, reason: null })
const remaining = ref(null)
const loading = ref(true)
const loadError = ref('')
const step = ref(0)
const submitting = ref(false)
const serverErrors = ref({})
const alert = ref(null) // { tone, title, message, action? }
const alertEl = ref(null)

// Older forms stored some types with spaces or slashes ("Yes/No", "select/dropdown").
const normalizeType = (type) => {
  const value = String(type || 'text').toLowerCase().replaceAll(' ', '')
  return { 'yes/no': 'yesno', 'select/dropdown': 'select', phonenumber: 'phone' }[value] || value
}

const load = async () => {
  loading.value = true; loadError.value = ''
  try {
    const [eventResponse, formResponse] = await Promise.all([api.get(`/events/${eventId.value}`), api.get(`/events/${eventId.value}/form`)])
    event.value = eventResponse.data.data
    remaining.value = eventResponse.data.remaining ?? null
    registration.value = eventResponse.data.registration || { open: formResponse.data.data?.registration_open !== false, reason: formResponse.data.data?.registration_closed_reason || null }
    const data = formResponse.data.data || {}
    const fields = Array.isArray(data) ? data : (data.fields || [])
    form.value = {
      fields: fields.map((field) => ({ ...field, type: normalizeType(field.type) })),
      steps: Array.isArray(data.steps) ? data.steps : [],
      settings: data.settings || {},
      title: data.form_title,
      description: data.form_description,
    }
  } catch (requestError) {
    const status = requestError.response?.status
    loadError.value = status === 404 ? 'This event isn’t available. It may have ended or been unpublished.' : 'We couldn’t load the registration form. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}
load()

/* ---------- Event summary ---------- */
const banner = computed(() => event.value?.branding?.image || '')
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
const deadlineLabel = computed(() => {
  const deadline = form.value.settings?.registration_end
  return deadline ? new Date(`${String(deadline).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''
})
const spotsLabel = computed(() => {
  if (remaining.value === null || !event.value?.capacity) return ''
  return remaining.value <= 10 ? `Only ${remaining.value} ${remaining.value === 1 ? 'spot' : 'spots'} left` : `${remaining.value} spots left`
})

/* ---------- Submit ---------- */
const showAlert = async (value) => {
  alert.value = value
  await nextTick()
  alertEl.value?.focus()
  alertEl.value?.scrollIntoView({ block: 'center' })
}
const onSubmit = async (values) => {
  if (submitting.value) return // ignore double clicks / repeated Enter presses
  submitting.value = true
  serverErrors.value = {}
  alert.value = null
  try {
    const result = await store.register({ event_id: Number(eventId.value), form_data: buildSubmission(form.value.fields, values) })
    // Only now — after the server confirmed — move to the confirmation screen.
    await router.push({ path: '/registration/success', query: { id: result.id, new: '1' } })
  } catch (requestError) {
    const response = requestError.response
    const errors = response?.data?.errors || {}
    if (response?.status === 422) {
      const fieldErrors = Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith('form_data.')))
      const eventError = [errors.event_id, errors.email].flat().filter(Boolean)[0]
      if (Object.keys(fieldErrors).length) {
        // The step with the first problem must be visible for its message to show.
        const firstId = Object.keys(fieldErrors)[0].split('.')[1]
        const firstField = form.value.fields.find((field) => String(field.id) === firstId)
        if (firstField && form.value.steps.length > 1) step.value = Number(firstField.settings?.step ?? 0)
        serverErrors.value = fieldErrors
      }
      if (eventError) {
        const duplicate = /already registered/i.test(eventError)
        if (!duplicate) { registration.value = { open: false, reason: eventError }; load() }
        showAlert({ tone: duplicate ? 'info' : 'error', title: duplicate ? 'You’re already registered' : 'Registration isn’t possible', message: duplicate ? 'This account or email address is already registered for this event.' : eventError, action: duplicate ? { label: 'View my registrations', to: '/me' } : null })
      } else if (!Object.keys(fieldErrors).length) {
        showAlert({ tone: 'error', title: 'Please check your answers', message: response.data?.message || 'Some answers need attention.' })
      }
    } else if (!response) {
      showAlert({ tone: 'error', title: 'We couldn’t reach the server', message: 'Your answers are still here. Check your connection and try again.' })
    } else if (response.status === 401) {
      showAlert({ tone: 'error', title: 'Please sign in again', message: 'Your session expired. Sign in and your answers will need to be entered again.', action: { label: 'Sign in', to: { path: '/login', query: { redirect: route.fullPath } } } })
    } else {
      showAlert({ tone: 'error', title: 'Something went wrong on our side', message: 'Your registration was not completed. Your answers are still here — please try again in a moment.' })
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="rg-page">
    <RouterLink class="rg-back" :to="`/events/${eventId}`"><AppIcon name="chevron-left" :size="16" />Back to event</RouterLink>

    <div v-if="loading" class="rg-layout" role="status" aria-label="Loading registration form">
      <div class="rg-skel rg-skel--side"></div>
      <div class="rg-skel rg-skel--form"></div>
    </div>

    <div v-else-if="loadError" class="rg-card rg-state" role="alert">
      <span class="rg-state__icon is-error"><AppIcon name="alert-circle" :size="26" /></span>
      <h1>{{ loadError }}</h1>
      <div class="rg-state__actions"><button type="button" class="rg-btn" @click="load"><AppIcon name="refresh" :size="16" />Try again</button><RouterLink class="rg-btn rg-btn--primary" to="/">Browse events</RouterLink></div>
    </div>

    <div v-else class="rg-layout">
      <!-- Which event this is -->
      <aside class="rg-event" aria-label="Event summary">
        <img v-if="banner" class="rg-event__banner" :src="banner" alt="" />
        <div class="rg-event__body">
          <span v-if="event.category" class="rg-tag">{{ event.category }}</span>
          <h2>{{ event.name }}</h2>
          <ul class="rg-facts">
            <li><AppIcon name="calendar" :size="16" /><span>{{ dateLabel }}</span></li>
            <li v-if="timeLabel"><AppIcon name="clock" :size="16" /><span>{{ timeLabel }}</span></li>
            <li><AppIcon name="pin" :size="16" /><span>{{ event.location || 'Location to be confirmed' }}</span></li>
          </ul>
          <p class="rg-status" :class="registration.open ? 'is-open' : 'is-closed'">
            <AppIcon :name="registration.open ? 'check-circle' : 'lock'" :size="16" />
            <span v-if="registration.open"><strong>Registration open</strong><template v-if="deadlineLabel"> · closes {{ deadlineLabel }}</template><template v-if="spotsLabel"> · {{ spotsLabel }}</template></span>
            <span v-else><strong>Registration closed</strong></span>
          </p>
          <p v-if="event.description" class="rg-event__desc">{{ event.description }}</p>
        </div>
      </aside>

      <div class="rg-main">
        <div v-if="!registration.open" class="rg-card rg-state">
          <span class="rg-state__icon"><AppIcon name="lock" :size="24" /></span>
          <h1>Registration is closed</h1>
          <p>{{ registration.reason || 'This event isn’t accepting registrations right now.' }}</p>
          <div class="rg-state__actions"><RouterLink class="rg-btn" :to="`/events/${eventId}`">Back to event</RouterLink><RouterLink class="rg-btn rg-btn--primary" to="/me">My registrations</RouterLink></div>
        </div>

        <div v-else class="rg-card rg-form">
          <header class="rg-form__head">
            <h1>Register for {{ event.name }}</h1>
            <p>{{ form.description || 'Fill in your details below. It only takes a minute.' }}</p>
          </header>

          <div v-if="alert" ref="alertEl" class="rg-alert" :class="`is-${alert.tone}`" role="alert" tabindex="-1">
            <AppIcon :name="alert.tone === 'info' ? 'info' : 'alert-circle'" :size="20" />
            <div><strong>{{ alert.title }}</strong><p>{{ alert.message }}</p><RouterLink v-if="alert.action" class="rg-link" :to="alert.action.to">{{ alert.action.label }} →</RouterLink></div>
          </div>

          <FormRenderer
            :fields="form.fields"
            :steps="form.steps"
            :active-step="step"
            :settings="form.settings"
            :server-errors="serverErrors"
            :submitting="submitting"
            hide-header
            submit-label="Complete registration"
            submitting-label="Registering…"
            @step-change="step = $event"
            @submit="onSubmit"
          />
        </div>
      </div>
    </div>
  </section>
</template>
