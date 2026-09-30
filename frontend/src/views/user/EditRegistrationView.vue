<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import FormRenderer from '../../components/FormRenderer.vue'
import api from '../../services/api'
import { buildSubmission, normalizeStoredValue } from '../../utils/formValidation'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const registration = ref(null)
const event = ref(null)
const formConfig = ref(null)
const serverErrors = ref({})
const initialValues = ref({})
const step = ref(0)

const fields = computed(() => formConfig.value?.fields || [])
const steps = computed(() => formConfig.value?.steps || [])
const settings = computed(() => formConfig.value?.settings || {})
const deadlineLabel = computed(() => registration.value?.registration_deadline
  ? new Date(registration.value.registration_deadline).toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' })
  : 'No deadline set')

const load = async () => {
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
    const values = {}
    for (const field of fields.value) values[field.id] = normalizeStoredValue(field, stored[field.id])
    initialValues.value = values

    if (!registration.value.can_edit) error.value = 'Editing is closed for this registration.'
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Registration could not be loaded.'
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
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  } catch (requestError) {
    const payloadErrors = requestError.response?.data?.errors || {}
    const mapped = {}
    for (const [key, messages] of Object.entries(payloadErrors)) {
      const match = key.match(/^form_data\.(\w+)$/)
      if (match) mapped[match[1]] = Array.isArray(messages) ? messages[0] : String(messages)
      else error.value = Array.isArray(messages) ? messages[0] : String(messages)
    }
    serverErrors.value = mapped
    if (!error.value) error.value = requestError.response?.data?.message || 'Changes could not be saved.'
  } finally {
    saving.value = false
  }
}

load()
</script>

<template>
  <UserLayout>
    <section class="edit-registration-page">
      <RouterLink class="back-link" to="/me">← Back to My registrations</RouterLink>

      <div v-if="loading" class="dynamic-loading">Loading your registration…</div>

      <div v-else-if="registration && event && formConfig" class="edit-registration-card">
        <header class="edit-registration-header">
          <div>
            <p class="eyebrow">My registration</p>
            <h1>Edit registration</h1>
            <p>{{ event.name }}</p>
          </div>
          <span class="edit-deadline" :class="{ closed: !registration.can_edit }">
            {{ registration.can_edit ? `Editing until ${deadlineLabel}` : '🔒 Editing closed' }}
          </span>
        </header>

        <p v-if="error" class="dynamic-form-error" role="alert">{{ error }}</p>
        <div v-if="saved" class="edit-success">✓ Registration updated successfully.</div>

        <FormRenderer
          :fields="fields"
          :steps="steps"
          :active-step="step"
          :form-title="formConfig.form_title"
          :form-description="formConfig.form_description"
          :event-name="event.name"
          :settings="settings"
          :initial-values="initialValues"
          :server-errors="serverErrors"
          submit-label="Save changes"
          :disabled="!registration.can_edit"
          @step-change="onStepChange"
          @submit="submit"
        />
      </div>

      <div v-else class="dynamic-empty">
        <h2>{{ error || 'Registration not found' }}</h2>
        <RouterLink to="/me">Return to My registrations</RouterLink>
      </div>
    </section>
  </UserLayout>
</template>
