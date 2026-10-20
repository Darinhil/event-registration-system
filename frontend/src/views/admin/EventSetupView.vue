<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { addNonBinaryGenderOption, isLegacyStarterForm, starterFields } from '../../utils/formFieldTypes'
import { copyText } from '../../utils/clipboard'

const route = useRoute(); const router = useRouter()
const editingId = route.params.id || null
const activeStep = ref(Number(route.query.step) || 1)
const error = ref('')
const saved = ref(false)
const publishedEvent = ref(null)
const previewDevice = ref('desktop')
const previewNotice = ref(false)
const imagePreview = ref('')
const event = reactive({ name: '', description: '', category: 'Conference', image: '', start_date: '', end_date: '', start_time: '', end_time: '', location: '', registration_start: '', deadline: '', maximum_participants: 200, allow_registration: true, allow_user_edit: true })
const setupFieldType = (type) => ({ text: 'Text', textarea: 'Text area', email: 'Email', phone: 'Phone', number: 'Number', date: 'Date', time: 'Time', select: 'Select', radio: 'Radio', checkbox: 'Checkbox', file: 'File', url: 'URL', yesno: 'Yes/No' })[String(type).toLowerCase()] || type
const fields = ref(starterFields().map((field, index) => ({ ...field, id: index + 1, type: setupFieldType(field.type) })))
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
const fieldInputType = (type) => ({ Email: 'email', Phone: 'tel', Number: 'number', Date: 'date', URL: 'url', File: 'file' })[type] || 'text'
const fieldPlaceholder = (field) => field.type === 'File' ? '' : field.placeholder || (field.type === 'URL' ? 'https://example.com' : field.label ? `Enter ${field.label.toLowerCase()}` : 'Enter an answer')
const newField = reactive({ label: '', type: 'Text', required: false, helperText: '', placeholder: '', defaultValue: '', minLength: '', maxLength: '', minValue: '', maxValue: '', minDate: '', maxDate: '', options: ['Option 1', 'Option 2'], settings: {}, advancedOpen: false, adminOnly: false, visibilityRule: 'Always visible' })
const showFieldEditor = ref(false)
const editingField = ref(null)
const steps = ['Basic information', 'Agenda & Program', 'Form builder', 'Review & publish']
const canContinue = computed(() => activeStep.value < steps.length)
const eventDuration = computed(() => {
  if (!event.start_date || !event.end_date) return 'Select dates'
  const [startYear, startMonth, startDay] = event.start_date.split('-').map(Number)
  const [endYear, endMonth, endDay] = event.end_date.split('-').map(Number)
  const start = Date.UTC(startYear, startMonth - 1, startDay)
  const end = Date.UTC(endYear, endMonth - 1, endDay)
  if (end < start) return 'Check dates'
  const days = Math.floor((end - start) / 86400000) + 1
  return `${days} ${days === 1 ? 'day' : 'days'}`
})
const agendaDayNumbers = computed(() => {
  if (event.start_date && event.end_date) {
    const [startYear, startMonth, startDay] = event.start_date.split('-').map(Number)
    const [endYear, endMonth, endDay] = event.end_date.split('-').map(Number)
    const start = Date.UTC(startYear, startMonth - 1, startDay)
    const end = Date.UTC(endYear, endMonth - 1, endDay)
    if (end >= start) {
      const dateDays = Math.floor((end - start) / 86400000) + 1
      return Array.from({ length: dateDays }, (_, index) => index + 1)
    }
  }
  const agendaDays = agenda.value.map((session) => Number(session.day)).filter((day) => Number.isInteger(day) && day > 0)
  const lastAgendaDay = agendaDays.length ? Math.max(...agendaDays) : 1
  return Array.from({ length: lastAgendaDay }, (_, index) => index + 1)
})
const agendaSessionTypes = ['Registration', 'Plenary', 'Group Photos', 'Networking', 'Keynote Panel', 'Research Showcase', 'Break', 'Parallel Sessions', 'Recap & Energizer', 'Policy Dialogue', 'Marketplace', 'Outcome Adoption', 'Closing Plenary', 'Session']
const agenda = ref([
  { id: 1, day: 1, start_time: '08:00', end_time: '08:30', session_type: 'Registration', title: 'Arrival and registration', description: 'Arrival, registration, and distribution of forum materials.', track: '', speakers: '' },
  { id: 2, day: 1, start_time: '08:30', end_time: '09:30', session_type: 'Plenary', title: 'Opening Ceremony & Setting the Vision', description: 'Welcome by a youth representative; remarks by Siem Reap Provincial Government; opening speech by MoE or MAFF leadership.', track: 'Plenary', speakers: 'Youth representative; Provincial Government; MoE / MAFF' },
  { id: 3, day: 1, start_time: '09:30', end_time: '09:45', session_type: 'Plenary', title: 'Group Photos', description: 'Distinguished delegates and participants.', track: '', speakers: '' },
  { id: 4, day: 1, start_time: '09:45', end_time: '10:15', session_type: 'Networking', title: 'Health Break & Exhibition Walk', description: 'Networking at the Green Solutions Marketplace.', track: '', speakers: '' },
  { id: 5, day: 1, start_time: '10:15', end_time: '10:45', session_type: 'Keynote Panel', title: 'Framing the Challenge', description: 'Aligning youth action with Cambodia’s NDC 3.0 and Pentagonal Strategy Phase I.', track: 'Plenary', speakers: 'MoE; MAFF; NGO representatives' },
  { id: 6, day: 1, start_time: '10:30', end_time: '12:00', session_type: 'Research Showcase', title: 'Voices from the Field: Girls-Led Action Research', description: 'Community studies led by adolescent girls on water scarcity, heat stress, climate-smart agriculture, and WASH in schools.', track: 'Plenary', speakers: 'Plan International' },
  { id: 7, day: 1, start_time: '12:00', end_time: '13:15', session_type: 'Break', title: 'Lunch & Networking', description: '', track: '', speakers: '' },
  { id: 8, day: 1, start_time: '13:15', end_time: '15:00', session_type: 'Parallel Sessions', title: 'Youth Climate Solutions: Skills & Innovation', description: 'Track A: Design climate-smart adaptation projects, monitoring frameworks, and small-grant proposals. Track B: Showcase climate technology, agroecology, rainwater harvesting, and nature-based adaptation.', track: 'Track A / Track B', speakers: 'Session leads to be identified; youth enterprises and NGO/private-sector partners' },
  { id: 9, day: 1, start_time: '15:00', end_time: '15:15', session_type: 'Break', title: 'Coffee Break & Marketplace Voting', description: 'Interactive voting on Green Solutions Marketplace projects.', track: '', speakers: '' },
  { id: 10, day: 1, start_time: '15:15', end_time: '16:30', session_type: 'Parallel Sessions', title: 'Advocacy & Circular Economy', description: 'Track A: Advocacy, storytelling, and data visualization. Track B: Youth-led recycling, plastic management, and clean-energy enterprise models.', track: 'Track A / Track B', speakers: 'Session leads and youth-led projects' },
  { id: 11, day: 1, start_time: '16:30', end_time: '17:00', session_type: 'Plenary', title: 'Day 1 Debrief', description: 'Synthesize capacity needs and research highlights; prepare for Day 2 policy dialogues.', track: 'Plenary', speakers: '' },
  { id: 12, day: 2, start_time: '08:30', end_time: '09:00', session_type: 'Recap & Energizer', title: 'Day 1 Reflection', description: 'Youth delegates summarize consensus points and policy requests.', track: 'Plenary', speakers: 'Youth delegates' },
  { id: 13, day: 2, start_time: '09:00', end_time: '10:30', session_type: 'Policy Dialogue', title: 'Bridging the Policy Gap', description: 'Inclusive children and youth climate governance; discuss youth-responsive engagement, child rights, climate resilience, and disaster risk reduction.', track: 'Plenary', speakers: 'MoE, MoEYS, MoWRAM, MoWA, MoSVY, MAFF, NCDD, local authorities' },
  { id: 14, day: 2, start_time: '10:30', end_time: '10:45', session_type: 'Networking', title: 'Health Break & Media Briefing', description: 'Press interviews with youth representatives.', track: '', speakers: 'Youth representatives; media' },
  { id: 15, day: 2, start_time: '10:45', end_time: '12:00', session_type: 'Marketplace', title: 'The Climate Action Marketplace', description: 'Interactive pitches connecting youth teams, funders, private sector, international NGOs, and CSOs to explore climate-resilience projects and partnerships.', track: 'Breakout tables', speakers: 'Youth networks; ESG/CSR leads; donors; NGOs; CSOs' },
  { id: 16, day: 2, start_time: '12:00', end_time: '13:15', session_type: 'Break', title: 'Lunch, Public Exhibition & Live Media', description: 'Public exhibition and live media broadcasting.', track: '', speakers: '' },
  { id: 17, day: 2, start_time: '13:15', end_time: '14:45', session_type: 'Session', title: 'Program to be confirmed', description: 'Session details to be set.', track: '', speakers: '' },
  { id: 18, day: 2, start_time: '14:45', end_time: '15:30', session_type: 'Outcome Adoption', title: 'Climate Youth Declaration & Call-to-Action', description: 'Read consensus statements and hand over the Youth Declaration to government ministers and official stakeholders.', track: 'Plenary', speakers: 'Youth delegates; government stakeholders' },
  { id: 19, day: 2, start_time: '15:30', end_time: '16:30', session_type: 'Closing Plenary', title: 'Closing Ceremony & Next Steps', description: 'Recognize outstanding marketplace booths, share next steps, and deliver closing remarks.', track: 'Plenary', speakers: 'Forum funder and organizers' },
])
const activeAgendaDay = ref(1)
const agendaSessionBeingEdited = ref(null)
const agendaSessionDraft = reactive({ start_time: '', end_time: '', session_type: 'Session', title: '', description: '', track: '', speakers: '' })
const agendaSessionsForDay = computed(() => agenda.value.filter((session) => session.day === activeAgendaDay.value))
const agendaDayLabel = (day) => {
  const firstDay = new Date(`${event.start_date || '2026-12-05'}T00:00:00`)
  firstDay.setDate(firstDay.getDate() + day - 1)
  return `Day ${day} · ${firstDay.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`
}
const addAgendaSession = () => agenda.value.push({
  id: Date.now(),
  day: activeAgendaDay.value,
  start_time: '',
  end_time: '',
  session_type: 'Session',
  title: '',
  description: '',
  track: '',
  speakers: '',
})
const removeAgendaSession = (id) => { agenda.value = agenda.value.filter((session) => session.id !== id) }
const editAgendaSession = (session) => {
  agendaSessionBeingEdited.value = session
  Object.assign(agendaSessionDraft, session)
}
const saveAgendaSession = () => {
  if (!agendaSessionBeingEdited.value) return
  Object.assign(agendaSessionBeingEdited.value, agendaSessionDraft)
  agendaSessionBeingEdited.value = null
}
const validateStep = () => {
  error.value = ''
  if (activeStep.value === 1 && !event.name.trim()) error.value = 'Add an event name to continue.'
  if (activeStep.value === 1 && (!event.start_date || !event.end_date || !event.start_time || !event.end_time || !event.location.trim())) error.value = 'Complete the date, time, and location fields.'
  if (activeStep.value === 1 && event.end_date && event.start_date && event.end_date < event.start_date) error.value = 'End date must be the same as or after the start date.'
  if (activeStep.value === 1 && event.start_date && event.end_date && event.start_time && event.end_time) {
    const startDateTime = new Date(`${event.start_date}T${event.start_time}`)
    const endDateTime = new Date(`${event.end_date}T${event.end_time}`)
    if (!Number.isNaN(startDateTime.getTime()) && !Number.isNaN(endDateTime.getTime()) && endDateTime <= startDateTime) {
      error.value = 'End date and time must be after the start date and time.'
    }
  }
  if (activeStep.value === 1 && !event.deadline) error.value = 'Choose a registration deadline.'
  return !error.value
}
const nextStep = () => { if (validateStep()) activeStep.value = Math.min(steps.length, activeStep.value + 1) }
const previousStep = () => { error.value = ''; activeStep.value = Math.max(1, activeStep.value - 1) }
const saveDraft = () => { localStorage.setItem('event_draft', JSON.stringify({ ...event, agenda: agenda.value, fields: fields.value, status: 'draft' })); saved.value = true; setTimeout(() => { saved.value = false }, 2200) }
const compressImage = (dataUrl, maxWidth = 1600, quality = 0.82) => new Promise((resolve) => { const image = new Image(); image.onload = () => { const scale = Math.min(1, maxWidth / image.width); const canvas = document.createElement('canvas'); canvas.width = Math.round(image.width * scale); canvas.height = Math.round(image.height * scale); canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height); resolve(canvas.toDataURL('image/jpeg', quality)) }; image.onerror = () => resolve(dataUrl); image.src = dataUrl })
const selectImage = (input) => { const file = input.target.files?.[0]; if (!file) return; const reader = new FileReader(); reader.onload = async () => { imagePreview.value = reader.result; event.image = await compressImage(reader.result) }; reader.readAsDataURL(file) }
const resetNewField = () => Object.assign(newField, { label: '', type: 'Text', required: false, helperText: '', placeholder: '', defaultValue: '', minLength: '', maxLength: '', minValue: '', maxValue: '', minDate: '', maxDate: '', options: ['Option 1', 'Option 2'], settings: {}, advancedOpen: false, adminOnly: false, visibilityRule: 'Always visible' })
const openFieldEditor = (field = null) => {
  editingField.value = field
  resetNewField()
  if (field) Object.assign(newField, field, { options: [...(field.options || ['Option 1', 'Option 2'])] })
  showFieldEditor.value = true
}
const saveField = () => {
  if (!newField.label.trim()) return
  const data = { label: newField.label.trim(), type: newField.type, required: newField.required, helperText: newField.helperText, placeholder: newField.placeholder, defaultValue: newField.defaultValue, minLength: newField.minLength, maxLength: newField.maxLength, minValue: newField.minValue, maxValue: newField.maxValue, minDate: newField.minDate, maxDate: newField.maxDate, options: ['Select', 'Radio', 'Checkbox'].includes(newField.type) ? newField.options.filter(Boolean) : [], settings: { ...newField.settings, ...(newField.type === 'Checkbox' ? { multiple: true } : {}) }, adminOnly: newField.adminOnly, visibilityRule: newField.visibilityRule }
  if (editingField.value) Object.assign(editingField.value, data)
  else fields.value.push({ id: Date.now(), ...data })
  showFieldEditor.value = false; editingField.value = null
}
const addFieldOption = () => newField.options.push(`Option ${newField.options.length + 1}`)
const removeFieldOption = (index) => { if (newField.options.length > 1) newField.options.splice(index, 1) }
const moveFieldOption = (index, direction) => { const target = index + direction; if (target < 0 || target >= newField.options.length) return; [newField.options[index], newField.options[target]] = [newField.options[target], newField.options[index]] }
const removeField = (field) => { fields.value = fields.value.filter((item) => item.id !== field.id) }
const moveField = (index, direction) => { const target = index + direction; if (target < 0 || target >= fields.value.length) return; const list = [...fields.value]; [list[index], list[target]] = [list[target], list[index]]; fields.value = list }
const persistEvent = (createdId, status = 'open') => { const id = createdId || `evt-${Date.now()}`; const item = { ...event, id, status, agenda: agenda.value, fields: fields.value, registered: 0, registration_link: `${window.location.origin}/events/${id}` }; const list = JSON.parse(localStorage.getItem('event_list') || '[]').filter((entry) => entry.id !== id); localStorage.setItem('event_list', JSON.stringify([item, ...list])); return item }
const splitDateTime = (value) => ({ date: value?.slice(0, 10) || '', time: value?.slice(11, 16) || '' })
const loadEvent = async () => {
  if (!editingId) return
  try {
    const { data } = await api.get(`/events/${editingId}`)
    const item = data.data
    const start = splitDateTime(item.starts_at); const end = splitDateTime(item.ends_at)
    const registrationSettings = item.form_config?.settings || {}
    Object.assign(event, { name: item.name || '', description: item.description || '', category: item.category || 'Conference', image: item.branding?.image || '', start_date: start.date, start_time: start.time, end_date: end.date, end_time: end.time, location: item.location || '', maximum_participants: item.capacity || 200, registration_start: registrationSettings.registration_start?.slice(0, 10) || '', deadline: registrationSettings.registration_end?.slice(0, 10) || '' })
    agenda.value = Array.isArray(item.form_config?.agenda) ? item.form_config.agenda : []
    imagePreview.value = event.image
    if (Array.isArray(item.enabled_fields) && item.enabled_fields.length) fields.value = item.enabled_fields.map((field, index) => ({ id: field.id || index + 1, label: field.label, type: field.type, required: Boolean(field.required), options: field.options || [], settings: field.settings || {} }))
    else {
      const { data: formData } = await api.get(`/events/${editingId}/form`)
      const formRegistrationSettings = formData.data?.settings || formData.data?.config?.settings || {}
      event.registration_start = formRegistrationSettings.registration_start?.slice(0, 10) || ''
      event.deadline = formRegistrationSettings.registration_end?.slice(0, 10) || ''
      const formFields = formData.data?.fields || formData.data || []
      if (Array.isArray(formFields) && formFields.length) fields.value = formFields.map((field) => ({ id: field.id, label: field.label, type: String(field.type).toLowerCase() === 'select/dropdown' ? 'Select' : setupFieldType(String(field.type).toLowerCase()), required: Boolean(field.required), options: field.options || [], settings: field.settings || {} }))
    }
    if (!data.registered && isLegacyStarterForm(fields.value)) fields.value = starterFields().map((field, index) => ({ ...field, id: index + 1, type: setupFieldType(field.type) }))
    else fields.value = addNonBinaryGenderOption(fields.value)
  } catch { error.value = 'Could not load this event for editing.' }
}
loadEvent()
const publishEvent = async () => { if (!validateStep()) return; error.value = ''; const payload = { name: event.name, category: event.category, description: event.description, starts_at: new Date(`${event.start_date}T${event.start_time}`).toISOString(), ends_at: new Date(`${event.end_date}T${event.end_time}`).toISOString(), location: event.location, capacity: event.maximum_participants, branding: event.image ? { image: event.image } : null, enabled_fields: fields.value, form_config: { settings: { registration_start: event.registration_start || null, registration_end: event.deadline || null, allow_user_edit: true }, agenda: agenda.value }, form_fields: fields.value.map((field) => ({ label: field.label, type: field.type.toLowerCase().replaceAll(' ', ''), required: field.required, options: field.options || [], settings: field.settings || {} })) }; try { const { data } = editingId ? await api.put(`/admin/events/${editingId}`, payload) : await api.post('/admin/events', payload); publishedEvent.value = persistEvent(data.id, 'open'); if (!editingId) localStorage.removeItem('event_draft') } catch (requestError) { error.value = requestError.response?.data?.message || 'Unable to save event. Make sure the API is running and try again.' } }
const publishedLink = computed(() => publishedEvent.value?.registration_link || '')
const copyPublishedLink = async () => {
  error.value = ''
  if (await copyText(publishedLink.value)) {
    saved.value = true
    setTimeout(() => { saved.value = false }, 1800)
  } else {
    error.value = 'Copy failed. Please select the registration link and copy it manually.'
  }
}
const downloadPublishedQr = () => { const canvas = document.querySelector('.published-qr canvas'); if (!canvas) return; const anchor = document.createElement('a'); anchor.download = `${publishedEvent.value?.name || 'event'}-registration-qr.png`; anchor.href = canvas.toDataURL('image/png'); anchor.click() }
const printPublishedQr = () => { const canvas = document.querySelector('.published-qr canvas'); if (!canvas) return; const popup = window.open('', '_blank', 'width=600,height=700'); popup?.document.write(`<title>${publishedEvent.value?.name || 'Event'} QR Code</title><img style="width:420px;display:block;margin:60px auto" src="${canvas.toDataURL('image/png')}" onload="window.print()">`) }
const showPreviewNotice = () => { previewNotice.value = true; setTimeout(() => { previewNotice.value = false }, 2600) }
const saveLabel = computed(() => (editingId ? 'Save changes' : 'Publish event'))

const handleEditorKeydown = (e) => {
  if (e.key !== 'Escape') return
  if (showFieldEditor.value) showFieldEditor.value = false
  if (agendaSessionBeingEdited.value) agendaSessionBeingEdited.value = null
}
onMounted(() => window.addEventListener('keydown', handleEditorKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', handleEditorKeydown))
</script>

<template>
  <AdminLayout>
    <section v-if="!publishedEvent" class="event-flow-page">
      <RouterLink class="back-link" to="/admin/events">← Back to events</RouterLink>
      <header class="flow-heading"><div><h1>{{ editingId ? 'Edit event details' : 'Create an event' }}</h1></div><button class="ghost-save" type="button" @click="saveDraft">{{ saved ? 'Draft saved' : 'Save draft' }}</button></header>
      <nav class="create-stepper" aria-label="Event creation progress"><button v-for="(step, index) in steps" :key="step" type="button" :class="{ active: activeStep === index + 1, complete: activeStep > index + 1 }" @click="index + 1 < activeStep ? activeStep = index + 1 : null"><span>{{ activeStep > index + 1 ? '✓' : index + 1 }}</span><b>{{ step }}</b></button></nav>

      <form class="step-card" @submit.prevent="canContinue ? nextStep() : publishEvent()">
        <div v-if="activeStep === 1" class="step-content basic-information-step">
          <div class="form-grid">
            <label class="span-2">Event name <em>*</em><input v-model="event.name" placeholder="e.g. Community Tech Summit" required /></label>
            <label class="span-2">Description<textarea v-model="event.description" rows="5" placeholder="What is this event about?"></textarea></label>
            <label>Category<select v-model="event.category"><option>Conference</option><option>Workshop</option><option>Community</option><option>Training</option><option>Other</option></select></label>
            <label class="upload-field">Event banner<input type="file" accept="image/*" @change="selectImage" /><span v-if="!imagePreview">Upload an image <small>PNG or JPG</small></span><img v-else :src="imagePreview" alt="Event banner preview" /></label>
          </div>
          <div class="basic-event-details">
            <div class="event-schedule-grid">
              <label><span>Start event date <em>*</em></span><input v-model="event.start_date" type="date" required /></label>
              <label><span>End event date <em>*</em></span><input v-model="event.end_date" type="date" required /></label>
              <div class="event-duration" aria-live="polite"><small>Duration</small><strong>{{ eventDuration }}</strong></div>
              <label><span>Start time <em>*</em></span><input v-model="event.start_time" type="time" required /></label>
              <label><span>End time <em>*</em></span><input v-model="event.end_time" type="time" required /></label>
            </div>
            <label class="event-location-field"><span>Location <em>*</em></span><input v-model="event.location" placeholder="e.g. PNC Campus, Phnom Penh" required /></label>
            <div class="event-registration-grid">
              <label>Registration opens<input v-model="event.registration_start" type="date" /></label>
              <label><span>Registration deadline <em>*</em></span><input v-model="event.deadline" type="date" required /></label>
              <label>Maximum participants<input v-model="event.maximum_participants" type="number" min="1" /></label>
            </div>
            <div class="setting-list basic-registration-setting"><label><input v-model="event.allow_registration" type="checkbox" /> Allow registration</label><small>{{ event.allow_registration ? 'Attendees can register as soon as the event is published.' : 'Registration is closed. Attendees cannot register for this event.' }}</small></div>
          </div>
        </div>
        <div v-else-if="activeStep === 2" class="step-content agenda-step">
          <div class="agenda-toolbar">
            <div v-if="agendaDayNumbers.length > 1" class="agenda-day-tabs" role="group" aria-label="Agenda day">
              <button v-for="day in agendaDayNumbers" :key="day" type="button" :aria-pressed="activeAgendaDay === day" :class="{ active: activeAgendaDay === day }" @click="activeAgendaDay = day">
                <span>{{ agendaDayLabel(day) }}</span>
              </button>
            </div>
            <button type="button" class="outline-button agenda-add-session" @click="addAgendaSession">＋ Add session</button>
          </div>
          <div class="agenda-table-scroll" role="region" :aria-label="`${agendaDayLabel(activeAgendaDay)} sessions`" tabindex="0">
            <table class="agenda-table">
              <thead><tr><th scope="col">Time</th><th scope="col">Session type</th><th scope="col">Title &amp; focus</th><th scope="col">Track</th><th scope="col">Speakers / organization</th><th scope="col">Actions</th></tr></thead>
              <tbody>
                <tr v-for="(session, index) in agendaSessionsForDay" :key="session.id">
                  <td><span class="agenda-time-display">{{ session.start_time || '—' }}<template v-if="session.end_time"> – {{ session.end_time }}</template></span></td>
                  <td><span class="agenda-type-display">{{ session.session_type }}</span></td>
                  <td class="agenda-focus-cell"><strong>{{ session.title || 'Untitled session' }}</strong><p v-if="session.description">{{ session.description }}</p></td>
                  <td>{{ session.track || '—' }}</td>
                  <td>{{ session.speakers || '—' }}</td>
                  <td>
                    <div class="agenda-row-actions">
                    <button class="agenda-edit-button" type="button" :aria-label="`Edit ${session.title || 'session'}`" :title="`Edit ${session.title || 'session'}`" @click="editAgendaSession(session)"><AppIcon name="pen" :size="15" /><span class="sr-only">Edit</span></button>
                    <button class="agenda-remove-button" type="button" :aria-label="`Delete ${session.title || 'session'}`" :title="`Delete ${session.title || 'session'}`" @click="removeAgendaSession(session.id)"><AppIcon name="trash" :size="15" /><span class="sr-only">Delete</span></button>
                    </div>
                  </td>
                </tr>
                <tr v-if="agendaSessionsForDay.length === 0"><td class="agenda-empty" colspan="6">No sessions for this day yet. Add a session to build the program.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div v-else-if="activeStep === 3" class="step-content"><div class="step-intro"><button type="button" class="outline-button" @click="openFieldEditor()">+ Add field</button></div><div class="field-builder"><div v-for="(field, index) in fields" :key="field.id" class="builder-field"><span class="drag-handle" aria-hidden="true">⠿</span><div><strong>{{ field.label }}</strong><small>{{ field.type }} · {{ field.required ? 'Required' : 'Optional' }}</small></div><button type="button" class="icon-button" :disabled="index === 0" @click="moveField(index, -1)">↑</button><button type="button" class="icon-button" :disabled="index === fields.length - 1" @click="moveField(index, 1)">↓</button><button type="button" class="text-action" @click="openFieldEditor(field)">Edit</button><button type="button" class="delete-action" @click="removeField(field)">Delete</button></div></div></div>
        <div v-else class="step-content preview-step">
          <div class="preview-toolbar"><div class="preview-toolbar-actions"><button type="button" class="secondary-button" @click="activeStep = 3">← Back to edit</button><div class="device-switcher"><button type="button" :class="{ active: previewDevice === 'desktop' }" @click="previewDevice = 'desktop'">Desktop</button><button type="button" :class="{ active: previewDevice === 'mobile' }" @click="previewDevice = 'mobile'">Mobile</button></div><button type="button" class="primary-button" @click="publishEvent">{{ saveLabel }}</button></div></div>
          <div class="preview-frame-wrap"><div class="user-preview-frame" :class="`is-${previewDevice}`"><div class="user-preview-browser"><span></span><span></span><span></span><small>Event registration</small></div><div class="user-preview-page"><img v-if="imagePreview" class="user-preview-banner" :src="imagePreview" alt="Event banner" /><div class="user-preview-content"><div class="user-preview-event-header"><span class="event-category">{{ event.category }}</span><h3>{{ event.name || 'Your event name' }}</h3><p>{{ event.description || 'Your event description will appear here.' }}</p><div class="user-preview-meta"><span>📅 {{ event.start_date || 'Date to be confirmed' }}</span><span>🕐 {{ event.start_time || 'Time' }} – {{ event.end_time || '—' }}</span><span>📍 {{ event.location || 'Location to be confirmed' }}</span></div><div class="user-preview-deadline"><span>Registration deadline</span><strong>{{ event.deadline || 'Not set' }}</strong><span class="preview-seats">{{ event.maximum_participants }} seats available</span></div></div><div class="user-preview-form"><p class="preview-form-kicker">Registration form</p><h4>Register for {{ event.name || 'this event' }}</h4><p class="preview-form-help">Complete the form below to reserve your place.</p><div v-for="field in fields" :key="field.id" class="preview-form-field"><label>{{ field.label }} <em v-if="field.required">*</em></label><select v-if="field.type === 'Select'"><option value="">Select {{ field.label.toLowerCase() }}</option><option v-for="option in field.options || []" :key="option">{{ option }}</option></select><textarea v-else-if="field.type === 'Text area'" rows="3" :placeholder="fieldPlaceholder(field)"></textarea><div v-else-if="['Radio', 'Checkbox'].includes(field.type)" class="preview-choice-group"><label v-for="option in field.options || []" :key="option"><input :type="field.type === 'Radio' ? 'radio' : 'checkbox'" :name="`preview-${field.id}`" />{{ option }}</label></div><div v-else-if="field.type === 'Yes/No'" class="preview-choice-group"><label v-for="option in ['Yes', 'No']" :key="option"><input type="radio" :name="`preview-${field.id}`" />{{ option }}</label></div><input v-else :type="fieldInputType(field.type)" :placeholder="fieldPlaceholder(field)" /><small v-if="field.type === 'File' && field.settings?.allowed_types?.length" class="preview-upload-hint">Allowed: {{ field.settings.allowed_types.join(', ') }} · up to {{ field.settings.max_size_mb || 5 }} MB</small></div><button type="button" class="primary-button preview-register-button" @click="showPreviewNotice">Register</button><div v-if="previewNotice" class="preview-notice">This is preview mode. No registration will be submitted.</div></div></div></div></div></div>
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
    <div v-if="agendaSessionBeingEdited" class="modal-backdrop modal-backdrop--enhanced agenda-modal-backdrop" @click.self="agendaSessionBeingEdited = null">
      <section class="agenda-edit-modal" role="dialog" aria-modal="true" aria-labelledby="agenda-edit-title">
        <header><div><p class="admin-eyebrow">Agenda session</p><h2 id="agenda-edit-title">Edit session</h2></div><button class="modal-close" type="button" aria-label="Close dialog" @click="agendaSessionBeingEdited = null">×</button></header>
        <div class="agenda-edit-body">
          <div class="agenda-edit-grid">
            <label>Start time<input v-model="agendaSessionDraft.start_time" type="time" /></label>
            <label>End time<input v-model="agendaSessionDraft.end_time" type="time" /></label>
            <label>Session type<select v-model="agendaSessionDraft.session_type"><option v-if="!agendaSessionTypes.includes(agendaSessionDraft.session_type)" :value="agendaSessionDraft.session_type">{{ agendaSessionDraft.session_type }}</option><option v-for="type in agendaSessionTypes" :key="type">{{ type }}</option></select></label>
            <label>Track<input v-model="agendaSessionDraft.track" placeholder="e.g. Track A" /></label>
            <label class="agenda-edit-wide">Session title<input v-model="agendaSessionDraft.title" placeholder="Session title" /></label>
            <label class="agenda-edit-wide">Focus and description<textarea v-model="agendaSessionDraft.description" rows="4" placeholder="Describe the session"></textarea></label>
            <label class="agenda-edit-wide">Speakers / organization<input v-model="agendaSessionDraft.speakers" placeholder="Names or organizations" /></label>
          </div>
        </div>
        <footer><button type="button" class="secondary-button" @click="agendaSessionBeingEdited = null">Cancel</button><button type="button" class="primary-button" @click="saveAgendaSession">Save session</button></footer>
      </section>
    </div>
    <div v-if="showFieldEditor" class="modal-backdrop modal-backdrop--enhanced" @click.self="showFieldEditor = false">
      <div class="field-modal field-modal--builder" role="dialog" aria-modal="true" aria-labelledby="field-modal-title">
        <header class="field-modal-header">
          <div><p class="admin-eyebrow">Form builder</p><h2 id="field-modal-title">{{ editingField ? 'Edit field' : 'Add field' }}</h2><p class="field-modal-help">Create a clear question attendees can answer during registration.</p></div>
          <button type="button" class="modal-close" aria-label="Close dialog" @click="showFieldEditor = false">×</button>
        </header>
        <div class="field-modal-body">
          <label class="modal-field-label">Field label <input v-model="newField.label" maxlength="80" placeholder="e.g. Date of birth" /><small class="field-hint">{{ newField.label.length }}/80 · This is what attendees will see.</small></label>
          <div class="field-type-grid">
            <button v-for="option in fieldTypeOptions" :key="option.value" type="button" class="field-type-option" :class="{ active: newField.type === option.value }" @click="newField.type = option.value"><span class="field-type-icon">{{ option.icon }}</span><span><strong>{{ option.label }}</strong><small>{{ option.hint }}</small></span></button>
          </div>
          <section v-if="['Select', 'Radio', 'Checkbox'].includes(newField.type)" class="field-modal-section">
            <div class="modal-section-heading"><h3>Options</h3><button type="button" class="modal-link-button" @click="addFieldOption">+ Add option</button></div>
            <div v-for="(option, index) in newField.options" :key="index" class="field-option-row"><span class="option-number">{{ index + 1 }}</span><input v-model="newField.options[index]" /><button type="button" @click="removeFieldOption(index)">×</button></div>
          </section>
          <section v-if="['Text', 'Text area'].includes(newField.type)" class="field-modal-section">
            <h3>Text settings</h3>
            <div class="modal-input-grid"><label>Placeholder<input v-model="newField.placeholder" /></label><label>Minimum length<input v-model.number="newField.minLength" type="number" /></label><label>Maximum length<input v-model.number="newField.maxLength" type="number" /></label></div>
          </section>
          <section class="required-setting"><label class="toggle-row"><span><strong>Required field</strong><small>Attendees must complete this field before submitting.</small></span><input v-model="newField.required" type="checkbox" /><i></i></label></section>
          <section class="field-preview-panel">
            <div class="preview-panel-heading"><span>Live preview</span><small>Attendee view</small></div>
            <label class="field-preview-label">{{ newField.label || 'Your field label' }}<em v-if="newField.required">*</em></label>
            <textarea v-if="newField.type === 'Text area'" class="field-preview-control" :placeholder="fieldPlaceholder(newField)" rows="3"></textarea>
            <select v-else-if="newField.type === 'Select'" class="field-preview-control"><option value="">Select {{ newField.label.toLowerCase() }}</option><option v-for="option in newField.options" :key="option">{{ option }}</option></select>
            <div v-else-if="['Radio', 'Checkbox'].includes(newField.type)" class="field-preview-choices"><label v-for="option in newField.options" :key="option"><input :type="newField.type === 'Radio' ? 'radio' : 'checkbox'" :name="`field-preview-${newField.type}`" />{{ option }}</label></div>
            <input v-else class="field-preview-control" :type="fieldInputType(newField.type)" :placeholder="fieldPlaceholder(newField)" />
          </section>
        </div>
        <footer class="field-modal-footer"><button type="button" class="secondary-button" @click="showFieldEditor = false">Cancel</button><button type="button" class="primary-button" :disabled="!newField.label.trim()" @click="saveField">Save field</button></footer>
      </div>
    </div>
  </AdminLayout>
</template>
