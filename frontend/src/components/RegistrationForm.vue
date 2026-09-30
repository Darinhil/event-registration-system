<script setup>
/**
 * Public registration form — renders exactly what the admin built in the
 * Form Builder (fields, types, labels, options, required flags, descriptions,
 * order, steps, and form settings) via the shared FormRenderer.
 */
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormRenderer from './FormRenderer.vue'
import { useAuthStore } from '../stores/auth'
import { useRegistrationStore } from '../stores/registration'
import api from '../services/api'
import { buildSubmission, displayValue, normalizeStoredValue, validateFields } from '../utils/formValidation'

const route = useRoute(); const router = useRouter(); const store = useRegistrationStore()
const event = ref(null); const fields = ref([]); const values = reactive({}); const loading = ref(true); const error = ref(''); const step = ref(1)
const normalizeType = (field) => { const type = String(field.type || 'text').toLowerCase().replaceAll(' ', '').replace('select/dropdown', 'select'); return type === 'phonenumber' ? 'phone' : type }
const load = async () => { const id = route.params.eventId; try { const eventResponse = await api.get(`/events/${id}`); const formResponse = await api.get(`/events/${id}/form`); event.value = eventResponse.data.data; const formData = formResponse.data.data; fields.value = Array.isArray(formData) ? formData : (formData?.fields || []) } catch (requestError) { const local = JSON.parse(localStorage.getItem('event_list') || '[]').find((item) => String(item.id) === String(id)); if (local) { event.value = local; fields.value = (local.fields || []).map((field, index) => ({ ...field, id: field.id || index + 1, type: normalizeType(field), sort_order: index })) } else if (requestError.response?.status) error.value = `This event could not be loaded (API ${requestError.response.status}).`; else error.value = `Cannot connect to the registration service at ${api.defaults.baseURL}.` } finally { loading.value = false } }
const isRequired = (field) => Boolean(field.required)
const fieldValue = (field) => values[field.id] ?? ''
const updateValue = (field, value) => { values[field.id] = value }
const toggleCheckboxValue = (field, option) => {
  const selected = Array.isArray(fieldValue(field)) ? [...fieldValue(field)] : []
  const index = selected.indexOf(option)
  if (index >= 0) selected.splice(index, 1)
  else selected.push(option)
  updateValue(field, selected)
}
const isMissing = (field) => {
  const value = fieldValue(field)
  return isRequired(field) && (Array.isArray(value) ? value.length === 0 : !value)
}
const reviewValue = (field) => {
  const value = fieldValue(field)
  if (normalizeType(field) === 'file') return value?.name || '—'
  if (normalizeType(field) === 'checkbox' && !field.settings?.multiple) return value ? field.settings?.checkbox_text || 'Selected' : '—'
  if (Array.isArray(value)) return value.join(', ') || '—'
  return value || '—'
}
const updateUrl = (field, value) => {
  const trimmed = value.trim()
  const url = trimmed && !/^[a-z][a-z\d+.-]*:\/\//i.test(trimmed) ? `https://${trimmed}` : trimmed
  updateValue(field, url)
}
const inputPlaceholder = (field) => field.placeholder || (normalizeType(field) === 'url' ? 'https://example.com' : `Enter ${field.label.toLowerCase()}`)
const fileAccept = (field) => (field.settings?.allowed_types || []).map((extension) => `.${extension}`).join(',') || undefined
const validate = () => { const missing = fields.value.find(isMissing); if (missing) { error.value = `${missing.label} is required.`; return false } error.value = ''; return true }
const continueForm = () => { if (validate()) step.value = 2 }
const submit = async () => { if (!validate()) return; const formData = {}; fields.value.forEach((field) => { formData[field.id] = fieldValue(field) }); try { await store.register({ event_id: Number(route.params.eventId), form_data: formData }); router.push('/registration/success') } catch { error.value = store.error || 'Registration could not be submitted.' } }
const eventDate = computed(() => event.value?.starts_at ? new Date(event.value.starts_at).toLocaleDateString(undefined, { dateStyle: 'full' }) : event.value?.start_date || 'Date to be confirmed')
load()
</script>

<template>
  <section class="dynamic-registration-page">
      <div v-if="loading" class="dynamic-loading">Loading event registration form…</div>
      <div v-else-if="event" class="dynamic-registration-shell">
        <header class="dynamic-event-hero"><img v-if="event.branding?.image || event.image" :src="event.branding?.image || event.image" alt="Event banner" /><div class="dynamic-event-hero-copy"><span class="event-category">{{ event.category || 'Event registration' }}</span><h1>{{ event.name }}</h1><p>{{ event.description || 'Complete the form below to reserve your place.' }}</p><div class="dynamic-event-meta"><span>📅 {{ eventDate }}</span><span>🕐 {{ event.starts_at ? new Date(event.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : event.start_time || 'Time pending' }}</span><span>📍 {{ event.location || 'Location pending' }}</span></div></div></header>
        <div class="dynamic-registration-body">
          <div class="dynamic-progress"><span :class="{ active: step === 1, complete: step > 1 }">1</span><i></i><span :class="{ active: step === 2 }">2</span><small>{{ step === 1 ? 'Your information' : 'Review details' }}</small></div>
          <form v-if="step === 1" class="dynamic-form" @submit.prevent="continueForm">
            <div class="dynamic-form-heading"><div><p class="admin-eyebrow">Registration form</p><h2>Register for {{ event.name }}</h2><p>Fields marked with * are required.</p></div><span>{{ fields.length }} fields</span></div>
            <div class="dynamic-fields">
              <label v-for="field in fields" :key="field.id" class="dynamic-field">
                <span>{{ field.label }} <em v-if="isRequired(field)">*</em></span>
                <select v-if="normalizeType(field) === 'select'" :value="fieldValue(field)" @change="updateValue(field, $event.target.value)"><option value="">Select {{ field.label.toLowerCase() }}</option><option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option></select>
                <div v-else-if="normalizeType(field) === 'radio'" class="dynamic-radio-group"><label v-for="option in field.options || []" :key="option"><input type="radio" :name="`field-${field.id}`" :value="option" :checked="fieldValue(field) === option" @change="updateValue(field, option)" /> {{ option }}</label></div>
                <div v-else-if="normalizeType(field) === 'yesno'" class="dynamic-radio-group" role="radiogroup" :aria-label="field.label"><label v-for="option in ['Yes', 'No']" :key="option"><input type="radio" :name="`field-${field.id}`" :value="option" :checked="fieldValue(field) === option" :required="isRequired(field)" @change="updateValue(field, option)" /> {{ option }}</label></div>
                <div v-else-if="normalizeType(field) === 'checkbox' && field.settings?.multiple" class="dynamic-check-group" role="group" :aria-label="field.label"><label v-for="option in field.options || []" :key="option"><input type="checkbox" :checked="Array.isArray(fieldValue(field)) && fieldValue(field).includes(option)" @change="toggleCheckboxValue(field, option)" /> {{ option }}</label></div>
                <label v-else-if="normalizeType(field) === 'checkbox'" class="dynamic-single-check"><input type="checkbox" :checked="Boolean(fieldValue(field))" :required="isRequired(field)" @change="updateValue(field, $event.target.checked)" /><span>{{ field.settings?.checkbox_text || field.label }}</span></label>
                <textarea v-else-if="normalizeType(field) === 'textarea'" :value="fieldValue(field)" :required="isRequired(field)" :placeholder="`Enter ${field.label.toLowerCase()}`" rows="3" @input="updateValue(field, $event.target.value)"></textarea>
                <input v-else-if="normalizeType(field) === 'file'" type="file" :accept="fileAccept(field)" :required="isRequired(field)" @change="updateValue(field, $event.target.files?.[0] || null)" />
                <input v-else :type="['email', 'date', 'number', 'tel', 'url'].includes(normalizeType(field)) ? normalizeType(field) : 'text'" :value="fieldValue(field)" :required="isRequired(field)" :placeholder="inputPlaceholder(field)" @input="updateValue(field, $event.target.value)" @change="normalizeType(field) === 'url' && updateUrl(field, $event.target.value)" />
              </label>
            </div>
            <p v-if="error" class="dynamic-form-error" role="alert">{{ error }}</p>
            <button class="primary-button" type="submit">Continue to review →</button>
          </form>
          <section v-else class="dynamic-review">
            <p class="admin-eyebrow">Review registration</p><h2>Check your information</h2>
            <div class="dynamic-review-list"><div v-for="field in fields" :key="field.id"><span>{{ field.label }}</span><strong>{{ reviewValue(field) }}</strong></div></div>
            <p v-if="error" class="dynamic-form-error">{{ error }}</p>
            <div class="dynamic-review-actions"><button type="button" class="secondary-button" @click="step = 1">Back to form</button><button type="button" class="primary-button" :disabled="store.loading" @click="submit">{{ store.loading ? 'Submitting…' : 'Confirm registration' }}</button></div>
          </section>
        </div>
      </div>
      <div v-else class="dynamic-empty"><h2>{{ error || 'Event not found' }}</h2><RouterLink to="/">Return home</RouterLink></div>
  </section>
</template>
