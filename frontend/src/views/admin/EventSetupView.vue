<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import api from '../../services/api'

const route = useRoute(); const router = useRouter()
const editingId = route.params.id || null
const activeStep = ref(Number(route.query.step) || 1)
const error = ref('')
const saved = ref(false)
const publishedEvent = ref(null)
const previewDevice = ref('desktop')
const previewNotice = ref(false)
const imagePreview = ref('')
const event = reactive({ name: '', description: '', category: 'Conference', image: '', start_date: '', end_date: '', start_time: '', end_time: '', location: '', mode: 'offline', registration_start: '', deadline: '', maximum_participants: 200, allow_registration: true, require_login: true, allow_user_edit: true })
const fields = ref([
  { id: 1, label: 'Full Name', type: 'Text', required: true },
  { id: 2, label: 'Email', type: 'Email', required: true },
  { id: 3, label: 'Phone Number', type: 'Phone', required: true },
  { id: 4, label: 'Department', type: 'Select', required: true, options: ['IT', 'Design', 'Business', 'Marketing'] },
  { id: 5, label: 'Age Group', type: 'Radio', required: false, options: ['Under 18', '18–29', '30–60', 'Above 60'] },
  { id: 6, label: 'Organization / School', type: 'Text', required: false },
])
const fieldTypeOptions = [
  { value: 'Text', label: 'Short text', icon: 'T', hint: 'One line answer' },
  { value: 'Text area', label: 'Long text', icon: '¶', hint: 'Longer response' },
  { value: 'Email', label: 'Email', icon: '@', hint: 'Email address' },
  { value: 'Phone', label: 'Phone number', icon: '⌕', hint: 'Phone number' },
  { value: 'Number', label: 'Number', icon: '#', hint: 'Numeric answer' },
  { value: 'Date', label: 'Date', icon: '□', hint: 'Date picker' },
  { value: 'Select', label: 'Dropdown', icon: '⌄', hint: 'Choose one' },
  { value: 'Radio', label: 'Radio buttons', icon: '◉', hint: 'Choose one' },
  { value: 'Checkbox', label: 'Checkboxes', icon: '☑', hint: 'Choose many' },
  { value: 'File', label: 'File upload', icon: '↑', hint: 'Attach a file' },
  { value: 'URL', label: 'URL', icon: '↗', hint: 'Website link' },
]
const newField = reactive({ label: '', type: 'Text', required: false, helperText: '', placeholder: '', defaultValue: '', minLength: '', maxLength: '', minValue: '', maxValue: '', minDate: '', maxDate: '', options: ['Option 1', 'Option 2'], advancedOpen: false, adminOnly: false, visibilityRule: 'Always visible' })
const showFieldEditor = ref(false)
const editingField = ref(null)
const steps = ['Basic information', 'Date & location', 'Registration', 'Form builder', 'Review & publish']
const canContinue = computed(() => activeStep.value < 5)

const validateStep = () => {
  error.value = ''
  if (activeStep.value === 1 && !event.name.trim()) error.value = 'Add an event name to continue.'
  if (activeStep.value === 2 && (!event.start_date || !event.end_date || !event.start_time || !event.end_time || !event.location.trim())) error.value = 'Complete the date, time, and location fields.'
  if (activeStep.value === 2 && event.end_date && event.start_date && event.end_date < event.start_date) error.value = 'End date must be after the start date.'
  if (activeStep.value === 3 && !event.deadline) error.value = 'Choose a registration deadline.'
  return !error.value
}
const nextStep = () => { if (validateStep()) activeStep.value = Math.min(5, activeStep.value + 1) }
const previousStep = () => { error.value = ''; activeStep.value = Math.max(1, activeStep.value - 1) }
const saveDraft = () => { localStorage.setItem('event_draft', JSON.stringify({ ...event, fields: fields.value, status: 'draft' })); saved.value = true; setTimeout(() => { saved.value = false }, 2200) }
const compressImage = (dataUrl, maxWidth = 1600, quality = 0.82) => new Promise((resolve) => { const image = new Image(); image.onload = () => { const scale = Math.min(1, maxWidth / image.width); const canvas = document.createElement('canvas'); canvas.width = Math.round(image.width * scale); canvas.height = Math.round(image.height * scale); canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height); resolve(canvas.toDataURL('image/jpeg', quality)) }; image.onerror = () => resolve(dataUrl); image.src = dataUrl })
const selectImage = (input) => { const file = input.target.files?.[0]; if (!file) return; const reader = new FileReader(); reader.onload = async () => { imagePreview.value = reader.result; event.image = await compressImage(reader.result) }; reader.readAsDataURL(file) }
const resetNewField = () => Object.assign(newField, { label: '', type: 'Text', required: false, helperText: '', placeholder: '', defaultValue: '', minLength: '', maxLength: '', minValue: '', maxValue: '', minDate: '', maxDate: '', options: ['Option 1', 'Option 2'], advancedOpen: false, adminOnly: false, visibilityRule: 'Always visible' })
const openFieldEditor = (field = null) => {
  editingField.value = field
  resetNewField()
  if (field) Object.assign(newField, field, { options: [...(field.options || ['Option 1', 'Option 2'])] })
  showFieldEditor.value = true
}
const saveField = () => {
  if (!newField.label.trim()) return
  const data = { label: newField.label.trim(), type: newField.type, required: newField.required, helperText: newField.helperText, placeholder: newField.placeholder, defaultValue: newField.defaultValue, minLength: newField.minLength, maxLength: newField.maxLength, minValue: newField.minValue, maxValue: newField.maxValue, minDate: newField.minDate, maxDate: newField.maxDate, options: ['Select', 'Radio', 'Checkbox'].includes(newField.type) ? newField.options.filter(Boolean) : [], adminOnly: newField.adminOnly, visibilityRule: newField.visibilityRule }
  if (editingField.value) Object.assign(editingField.value, data)
  else fields.value.push({ id: Date.now(), ...data })
  showFieldEditor.value = false; editingField.value = null
}
const addFieldOption = () => newField.options.push(`Option ${newField.options.length + 1}`)
const removeFieldOption = (index) => { if (newField.options.length > 1) newField.options.splice(index, 1) }
const moveFieldOption = (index, direction) => { const target = index + direction; if (target < 0 || target >= newField.options.length) return; [newField.options[index], newField.options[target]] = [newField.options[target], newField.options[index]] }
const removeField = (field) => { fields.value = fields.value.filter((item) => item.id !== field.id) }
const moveField = (index, direction) => { const target = index + direction; if (target < 0 || target >= fields.value.length) return; const list = [...fields.value]; [list[index], list[target]] = [list[target], list[index]]; fields.value = list }
const persistEvent = (createdId, status = 'open') => { const id = createdId || `evt-${Date.now()}`; const item = { ...event, id, status, fields: fields.value, registered: 0, registration_link: `${window.location.origin}/events/${id}/register` }; const list = JSON.parse(localStorage.getItem('event_list') || '[]').filter((entry) => entry.id !== id); localStorage.setItem('event_list', JSON.stringify([item, ...list])); return item }
const splitDateTime = (value) => ({ date: value?.slice(0, 10) || '', time: value?.slice(11, 16) || '' })
const loadEvent = async () => {
  if (!editingId) return
  try {
    const { data } = await api.get(`/events/${editingId}`)
    const item = data.data
    const start = splitDateTime(item.starts_at); const end = splitDateTime(item.ends_at)
    Object.assign(event, { name: item.name || '', description: item.description || '', category: item.category || 'Conference', image: item.branding?.image || '', start_date: start.date, start_time: start.time, end_date: end.date, end_time: end.time, location: item.location || '', maximum_participants: item.capacity || 200 })
    imagePreview.value = event.image
    if (Array.isArray(item.enabled_fields) && item.enabled_fields.length) fields.value = item.enabled_fields.map((field, index) => ({ id: field.id || index + 1, label: field.label, type: field.type, required: Boolean(field.required), options: field.options || [] }))
    else {
      const { data: formData } = await api.get(`/events/${editingId}/form`)
      if (Array.isArray(formData.data) && formData.data.length) fields.value = formData.data.map((field) => ({ id: field.id, label: field.label, type: String(field.type).toLowerCase() === 'phonenumber' ? 'Phone' : String(field.type).toLowerCase() === 'select/dropdown' ? 'Select' : field.type.charAt(0).toUpperCase() + field.type.slice(1), required: Boolean(field.required), options: field.options || [] }))
    }
  } catch { error.value = 'Could not load this event for editing.' }
}
loadEvent()
const publishEvent = async () => { if (!validateStep()) return; error.value = ''; const payload = { name: event.name, description: event.description, starts_at: new Date(`${event.start_date}T${event.start_time}`).toISOString(), ends_at: new Date(`${event.end_date}T${event.end_time}`).toISOString(), location: event.location, capacity: event.maximum_participants, branding: event.image ? { image: event.image } : null, enabled_fields: fields.value, form_config: { settings: { registration_start: event.registration_start || null, registration_end: event.deadline || null, allow_user_edit: true } }, form_fields: fields.value.map((field) => ({ label: field.label, type: field.type.toLowerCase().replaceAll(' ', ''), required: field.required, options: field.options || [] })) }; try { const { data } = editingId ? await api.put(`/admin/events/${editingId}`, payload) : await api.post('/admin/events', payload); publishedEvent.value = persistEvent(data.id, 'open'); if (!editingId) localStorage.removeItem('event_draft') } catch (requestError) { error.value = requestError.response?.data?.message || 'Unable to save event. Make sure the API is running and try again.' } }
const publishedLink = computed(() => publishedEvent.value?.registration_link || '')
const copyPublishedLink = async () => { await navigator.clipboard?.writeText(publishedLink.value); saved.value = true; setTimeout(() => { saved.value = false }, 1800) }
const downloadPublishedQr = () => { const canvas = document.querySelector('.published-qr canvas'); if (!canvas) return; const anchor = document.createElement('a'); anchor.download = `${publishedEvent.value?.name || 'event'}-registration-qr.png`; anchor.href = canvas.toDataURL('image/png'); anchor.click() }
const printPublishedQr = () => { const canvas = document.querySelector('.published-qr canvas'); if (!canvas) return; const popup = window.open('', '_blank', 'width=600,height=700'); popup?.document.write(`<title>${publishedEvent.value?.name || 'Event'} QR Code</title><img style="width:420px;display:block;margin:60px auto" src="${canvas.toDataURL('image/png')}" onload="window.print()">`) }
const showPreviewNotice = () => { previewNotice.value = true; setTimeout(() => { previewNotice.value = false }, 2600) }
const saveLabel = computed(() => (editingId ? 'Save changes' : 'Publish event'))
</script>

<template>
  <AdminLayout>
    <section v-if="!publishedEvent" class="event-flow-page">
      <RouterLink class="back-link" to="/admin/events">← Back to events</RouterLink>
      <header class="flow-heading"><div><p class="admin-eyebrow">Events · {{ editingId ? 'edit event' : 'new event' }}</p><h1>{{ editingId ? 'Edit event' : 'Create an event' }}</h1><p>Set up the experience in a few focused steps. You can save a draft and return anytime.</p></div><button class="ghost-save" type="button" @click="saveDraft">{{ saved ? 'Draft saved' : 'Save draft' }}</button></header>
      <nav class="create-stepper" aria-label="Event creation progress"><button v-for="(step, index) in steps" :key="step" type="button" :class="{ active: activeStep === index + 1, complete: activeStep > index + 1 }" @click="index + 1 < activeStep ? activeStep = index + 1 : null"><span>{{ activeStep > index + 1 ? '✓' : index + 1 }}</span><b>{{ step }}</b></button></nav>

      <form class="step-card" @submit.prevent="canContinue ? nextStep() : publishEvent()">
        <div v-if="activeStep === 1" class="step-content"><div class="step-intro"><span class="section-icon">01</span><div><h2>Basic information</h2><p>Give your event a clear identity so attendees know what to expect.</p></div></div><div class="form-grid"><label class="span-2">Event name <em>*</em><input v-model="event.name" placeholder="e.g. Community Tech Summit" required /></label><label class="span-2">Description<textarea v-model="event.description" rows="5" placeholder="What is this event about?"></textarea></label><label>Category<select v-model="event.category"><option>Conference</option><option>Workshop</option><option>Community</option><option>Training</option><option>Other</option></select></label><label class="upload-field">Event banner<input type="file" accept="image/*" @change="selectImage" /><span v-if="!imagePreview">Upload an image <small>PNG or JPG</small></span><img v-else :src="imagePreview" alt="Event banner preview" /></label></div></div>
        <div v-else-if="activeStep === 2" class="step-content"><div class="step-intro"><span class="section-icon">02</span><div><h2>Date &amp; location</h2><p>Tell attendees when and where the event will happen.</p></div></div><div class="form-grid"><label>Start date <em>*</em><input v-model="event.start_date" type="date" required /></label><label>End date <em>*</em><input v-model="event.end_date" type="date" required /></label><label>Start time <em>*</em><input v-model="event.start_time" type="time" required /></label><label>End time <em>*</em><input v-model="event.end_time" type="time" required /></label><label class="span-2">Location <em>*</em><input v-model="event.location" placeholder="e.g. PNC Campus, Phnom Penh" required /></label><fieldset class="span-2"><legend>Event format</legend><div class="choice-grid"><label><input v-model="event.mode" type="radio" value="offline" /> In person</label><label><input v-model="event.mode" type="radio" value="online" /> Online event</label><label><input v-model="event.mode" type="radio" value="hybrid" /> Hybrid</label></div></fieldset></div></div>
        <div v-else-if="activeStep === 3" class="step-content"><div class="step-intro"><span class="section-icon">03</span><div><h2>Registration settings</h2><p>Control access, capacity, and the registration window.</p></div></div><div class="form-grid"><label>Registration opens<input v-model="event.registration_start" type="datetime-local" /></label><label>Registration deadline <em>*</em><input v-model="event.deadline" type="datetime-local" required /></label><label>Maximum participants<input v-model="event.maximum_participants" type="number" min="1" /></label><div class="setting-list span-2"><label><input v-model="event.allow_registration" type="checkbox" /> Allow registration</label><small>Attendees can register as soon as the event is published.</small><label><input v-model="event.require_login" type="checkbox" /> Require user login before registration</label><small>Useful when registrations need to be tied to an account.</small></div></div></div>
        <div v-else-if="activeStep === 4" class="step-content"><div class="step-intro"><span class="section-icon">04</span><div><h2>Registration form</h2><p>Choose what attendees need to share. Keep the form short to improve completion.</p></div><button type="button" class="outline-button" @click="openFieldEditor()">+ Add field</button></div><div class="field-builder"><div v-for="(field, index) in fields" :key="field.id" class="builder-field"><span class="drag-handle" aria-hidden="true">⠿</span><div><strong>{{ field.label }}</strong><small>{{ field.type }} · {{ field.required ? 'Required' : 'Optional' }}</small></div><button type="button" class="icon-button" :disabled="index === 0" @click="moveField(index, -1)">↑</button><button type="button" class="icon-button" :disabled="index === fields.length - 1" @click="moveField(index, 1)">↓</button><button type="button" class="text-action" @click="openFieldEditor(field)">Edit</button><button type="button" class="delete-action" @click="removeField(field)">Delete</button></div></div></div>
        <div v-else class="step-content preview-step">
          <div class="preview-toolbar"><div class="step-intro"><span class="section-icon">05</span><div><p class="preview-mode-label">Preview mode</p><h2>User view preview</h2><p>Review the exact experience attendees will see before publishing.</p></div></div><div class="preview-toolbar-actions"><button type="button" class="secondary-button" @click="activeStep = 4">← Back to edit</button><div class="device-switcher"><button type="button" :class="{ active: previewDevice === 'desktop' }" @click="previewDevice = 'desktop'">Desktop</button><button type="button" :class="{ active: previewDevice === 'mobile' }" @click="previewDevice = 'mobile'">Mobile</button></div><button type="button" class="primary-button" @click="publishEvent">{{ saveLabel }}</button></div></div>
          <div class="preview-frame-wrap"><div class="user-preview-frame" :class="`is-${previewDevice}`"><div class="user-preview-browser"><span></span><span></span><span></span><small>Event registration</small></div><div class="user-preview-page"><img v-if="imagePreview" class="user-preview-banner" :src="imagePreview" alt="Event banner" /><div class="user-preview-content"><div class="user-preview-event-header"><span class="event-category">{{ event.category }}</span><h3>{{ event.name || 'Your event name' }}</h3><p>{{ event.description || 'Your event description will appear here.' }}</p><div class="user-preview-meta"><span>📅 {{ event.start_date || 'Date to be confirmed' }}</span><span>🕐 {{ event.start_time || 'Time' }} – {{ event.end_time || '—' }}</span><span>📍 {{ event.location || 'Location to be confirmed' }}</span></div><div class="user-preview-deadline"><span>Registration deadline</span><strong>{{ event.deadline || 'Not set' }}</strong><span class="preview-seats">{{ event.maximum_participants }} seats available</span></div></div><div class="user-preview-form"><p class="preview-form-kicker">Registration form</p><h4>Register for {{ event.name || 'this event' }}</h4><p class="preview-form-help">Complete the form below to reserve your place.</p><div v-for="field in fields" :key="field.id" class="preview-form-field"><label>{{ field.label }} <em v-if="field.required">*</em></label><select v-if="field.type === 'Select'"><option>Select {{ field.label.toLowerCase() }}</option></select><textarea v-else-if="field.type === 'Text area'" rows="3" :placeholder="`Enter ${field.label.toLowerCase()}`"></textarea><input v-else :type="field.type === 'Email' ? 'email' : field.type === 'Phone' ? 'tel' : field.type === 'Date' ? 'date' : 'text'" :placeholder="`Enter ${field.label.toLowerCase()}`" /></div><button type="button" class="primary-button preview-register-button" @click="showPreviewNotice">Register</button><div v-if="previewNotice" class="preview-notice">This is preview mode. No registration will be submitted.</div></div></div></div></div></div>
        </div>
        <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
        <footer class="step-actions"><button v-if="activeStep > 1" type="button" class="secondary-button" @click="previousStep">Back</button><span>Step {{ activeStep }} of {{ steps.length }}</span><button type="submit" class="primary-button">{{ canContinue ? 'Continue' : saveLabel }} <span>→</span></button></footer>
      </form>
    </section>
    <section v-else class="event-published-page">
      <div class="published-hero"><span class="published-check">✓</span><p class="admin-eyebrow">Event {{ editingId ? 'updated' : 'published' }}</p><h1>{{ editingId ? 'Event updated successfully!' : 'Event published successfully!' }}</h1><p>Registration is now open. Share the link or QR code below with your attendees.</p></div>
      <div class="published-event-name"><span class="event-category">{{ publishedEvent.category || 'Event' }}</span><h2>{{ publishedEvent.name }}</h2><p>{{ publishedEvent.start_date || 'Date to be confirmed' }} · {{ publishedEvent.start_time || 'Time pending' }} – {{ publishedEvent.end_time || '—' }} · {{ publishedEvent.location || 'Location pending' }}</p></div>
      <div class="published-actions"><button class="primary-button" type="button" @click="copyPublishedLink">{{ saved ? 'Copied!' : 'Copy registration link' }}</button><a class="secondary-button" :href="publishedLink" target="_blank" rel="noreferrer">Open registration page ↗</a><button class="secondary-button" type="button" @click="downloadPublishedQr">Download QR code</button></div>
      <div class="published-share-grid"><article class="published-link-card"><p class="admin-eyebrow">Registration link</p><h2>Invite attendees</h2><p>Anyone with this link can open the event registration page.</p><div class="published-link-value">{{ publishedLink }}</div><button class="text-link-button" type="button" @click="copyPublishedLink">{{ saved ? 'Link copied' : 'Copy link' }}</button></article><article class="published-qr-card"><p class="admin-eyebrow">Scan to register</p><h2>Event QR code</h2><div class="published-qr"><QRCodeDisplay :value="publishedLink" /></div><p>Users can scan this QR code to register for the event.</p><div class="published-qr-actions"><button class="secondary-button" type="button" @click="downloadPublishedQr">↓ Download</button><button class="secondary-button" type="button" @click="printPublishedQr">Print QR code</button></div></article></div>
      <footer class="published-footer"><RouterLink class="secondary-button" :to="`/admin/events/${publishedEvent.id}`">Go to event dashboard</RouterLink><a class="text-link-button" :href="publishedLink" target="_blank">View attendee experience →</a></footer>
    </section>
    <div v-if="showFieldEditor" class="modal-backdrop modal-backdrop--enhanced" @click.self="showFieldEditor = false"><div class="field-modal field-modal--builder" role="dialog" aria-modal="true"><header class="field-modal-header"><div><p class="admin-eyebrow">Form builder</p><h2>{{ editingField ? 'Edit field' : 'Add field' }}</h2><p class="field-modal-help">Create a clear question attendees can answer during registration.</p></div><button type="button" class="modal-close" @click="showFieldEditor = false">×</button></header><div class="field-modal-body"><label class="modal-field-label">Field label <input v-model="newField.label" maxlength="80" placeholder="e.g. Date of birth" /><small class="field-hint">{{ newField.label.length }}/80 · This is what attendees will see.</small></label><div class="field-type-grid"><button v-for="option in fieldTypeOptions" :key="option.value" type="button" class="field-type-option" :class="{ active: newField.type === option.value }" @click="newField.type = option.value"><span class="field-type-icon">{{ option.icon }}</span><span><strong>{{ option.label }}</strong><small>{{ option.hint }}</small></span></button></div><section v-if="['Select', 'Radio', 'Checkbox'].includes(newField.type)" class="field-modal-section"><div class="modal-section-heading"><h3>Options</h3><button type="button" class="modal-link-button" @click="addFieldOption">+ Add option</button></div><div v-for="(option, index) in newField.options" :key="index" class="field-option-row"><span class="option-number">{{ index + 1 }}</span><input v-model="newField.options[index]" /><button type="button" @click="removeFieldOption(index)">×</button></div></section><section v-if="['Text', 'Text area'].includes(newField.type)" class="field-modal-section"><h3>Text settings</h3><div class="modal-input-grid"><label>Placeholder<input v-model="newField.placeholder" /></label><label>Minimum length<input v-model.number="newField.minLength" type="number" /></label><label>Maximum length<input v-model.number="newField.maxLength" type="number" /></label></div></section><section class="required-setting"><label class="toggle-row"><span><strong>Required field</strong><small>Attendees must complete this field before submitting.</small></span><input v-model="newField.required" type="checkbox" /><i></i></label></section><section class="field-preview-panel"><div class="preview-panel-heading"><span>Live preview</span><small>Attendee view</small></div><label>{{ newField.label || 'Your field label' }} <em v-if="newField.required">*</em></label><input :placeholder="newField.placeholder || 'Enter an answer'" /></section></div><footer class="field-modal-footer"><button type="button" class="secondary-button" @click="showFieldEditor = false">Cancel</button><button type="button" class="primary-button" :disabled="!newField.label.trim()" @click="saveField">Save field</button></footer></div></div>
    <div v-if="showFieldEditor" class="modal-backdrop" @click.self="showFieldEditor = false"><div class="field-modal" role="dialog" aria-modal="true" aria-labelledby="field-modal-title"><header><div><p class="admin-eyebrow">Form builder</p><h2 id="field-modal-title">{{ editingField ? 'Edit field' : 'Add field' }}</h2><p class="field-modal-help">Add a question attendees can answer during registration.</p></div><button type="button" class="modal-close" aria-label="Close dialog" @click="showFieldEditor = false">×</button></header><div class="field-modal-body"><label>Field label<input v-model="newField.label" placeholder="e.g. Date of birth" /></label><label>Field type<select v-model="newField.type"><option>Text</option><option>Email</option><option>Phone</option><option>Date</option><option>Select</option><option>Text area</option></select></label><label class="checkbox-label"><input v-model="newField.required" type="checkbox" /><span><strong>Required field</strong><small>Attendees must complete this field before submitting.</small></span></label></div><footer><button type="button" class="secondary-button" @click="showFieldEditor = false">Cancel</button><button type="button" class="primary-button" @click="saveField">Save field</button></footer></div></div>
  </AdminLayout>
</template>
