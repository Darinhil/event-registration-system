<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import api from '../../services/api'

const route = useRoute(); const router = useRouter()
const loading = ref(true); const saving = ref(false); const error = ref(''); const saved = ref(false)
const registration = ref(null); const event = ref(null); const fields = ref([]); const values = reactive({})
const normalizeType = (field) => String(field.type || 'text').toLowerCase().replaceAll(' ', '').replace('select/dropdown', 'select')
const deadlineLabel = computed(() => registration.value?.registration_deadline ? new Date(registration.value.registration_deadline).toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' }) : 'No deadline set')
const load = async () => {
  try {
    const id = route.params.id
    const registrationResponse = await api.get(`/registrations/${id}`)
    registration.value = registrationResponse.data.data
    const eventResponse = await api.get(`/events/${registration.value.event_id}`)
    const formResponse = await api.get(`/events/${registration.value.event_id}/form`)
    event.value = eventResponse.data.data; fields.value = formResponse.data.data
    fields.value.forEach((field) => { values[field.id] = registration.value.form_data?.[field.id] ?? '' })
    if (!registration.value.can_edit) error.value = 'Editing is closed for this registration.'
  } catch (requestError) { error.value = requestError.response?.data?.message || 'Registration could not be loaded.' }
  finally { loading.value = false }
}
const valueFor = (field) => values[field.id] ?? ''
const updateValue = (field, value) => { values[field.id] = value }
const submit = async () => {
  if (!registration.value?.can_edit) return
  const missing = fields.value.find((field) => field.required && !valueFor(field))
  if (missing) { error.value = `${missing.label} is required.`; return }
  saving.value = true; error.value = ''
  try {
    const formData = Object.fromEntries(fields.value.map((field) => [field.id, valueFor(field)]))
    const { data } = await api.put(`/registrations/${registration.value.id}`, { event_id: registration.value.event_id, form_data: formData })
    registration.value = data.data; saved.value = true; setTimeout(() => { saved.value = false }, 3000)
  } catch (requestError) { error.value = requestError.response?.data?.message || Object.values(requestError.response?.data?.errors || {}).flat()[0] || 'Changes could not be saved.' }
  finally { saving.value = false }
}
load()
</script>

<template>
  <UserLayout><section class="edit-registration-page"><RouterLink class="back-link" to="/me">← Back to My registrations</RouterLink><div v-if="loading" class="dynamic-loading">Loading your registration…</div><div v-else-if="registration && event" class="edit-registration-card"><header class="edit-registration-header"><div><p class="eyebrow">My registration</p><h1>Edit registration</h1><p>{{ event.name }}</p></div><span class="edit-deadline" :class="{ closed: !registration.can_edit }">{{ registration.can_edit ? `Editing until ${deadlineLabel}` : '🔒 Editing closed' }}</span></header><form @submit.prevent="submit"><div class="edit-fields"><label v-for="field in fields" :key="field.id" class="dynamic-field"><span>{{ field.label }} <em v-if="field.required">*</em></span><select v-if="normalizeType(field) === 'select'" :value="valueFor(field)" @change="updateValue(field, $event.target.value)"><option value="">Select {{ field.label.toLowerCase() }}</option><option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option></select><div v-else-if="normalizeType(field) === 'radio'" class="dynamic-radio-group"><label v-for="option in field.options || []" :key="option"><input type="radio" :name="`edit-field-${field.id}`" :value="option" :checked="valueFor(field) === option" @change="updateValue(field, option)" /> {{ option }}</label></div><div v-else-if="normalizeType(field) === 'checkbox'" class="dynamic-radio-group"><label v-for="option in field.options || []" :key="option"><input type="checkbox" :value="option" :checked="Array.isArray(valueFor(field)) && valueFor(field).includes(option)" @change="updateValue(field, Array.from(document.querySelectorAll(`[name='edit-field-${field.id}']:checked`)).map((input) => input.value))" /> {{ option }}</label></div><textarea v-else-if="normalizeType(field) === 'textarea'" :value="valueFor(field)" :required="field.required" rows="4" @input="updateValue(field, $event.target.value)"></textarea><input v-else :type="['email','date','number','tel','url'].includes(normalizeType(field)) ? normalizeType(field) : 'text'" :value="valueFor(field)" :required="field.required" @input="updateValue(field, $event.target.value)" /></label></div><p v-if="error" class="dynamic-form-error">{{ error }}</p><div v-if="saved" class="edit-success">✓ Registration updated successfully.</div><footer class="edit-registration-actions"><button type="button" class="secondary-button" @click="router.push('/me')">Cancel</button><button type="submit" class="primary-button" :disabled="saving || !registration.can_edit">{{ saving ? 'Saving…' : 'Save changes' }}</button></footer></form></div><div v-else class="dynamic-empty"><h2>{{ error || 'Registration not found' }}</h2><RouterLink to="/me">Return to My registrations</RouterLink></div></section></UserLayout>
</template>
