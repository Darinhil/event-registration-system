<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { addNonBinaryGenderOption, isLegacyStarterForm, starterFields } from '../../utils/formFieldTypes'
import { copyText } from '../../utils/clipboard'

const route = useRoute(); const router = useRouter()

/* ------------------------------------------------------------------
   Wizard state
   ------------------------------------------------------------------ */
const steps = [
  { id: 1, label: 'Basic information', hint: 'Details, schedule and registration', optional: false },
  { id: 2, label: 'Agenda & program', hint: 'Sessions attendees will see', optional: true },
  { id: 3, label: 'Registration form', hint: 'What attendees fill in', optional: false },
  { id: 4, label: 'Review & publish', hint: 'Check everything and go live', optional: false },
]
const clampStep = (value) => Math.min(steps.length, Math.max(1, Number(value) || 1))

// The id is reactive because a new event gets one the first time its draft is saved.
const eventId = ref(route.params.id || null)
const eventStatus = ref(eventId.value ? '' : 'new')
const isLive = computed(() => Boolean(eventStatus.value) && !['new', 'draft'].includes(eventStatus.value))
const canSaveDraft = computed(() => !isLive.value)
const activeStep = ref(clampStep(route.query.step))
const furthestStep = ref(eventId.value ? steps.length : activeStep.value)
const loading = ref(Boolean(eventId.value))
const loadError = ref('')
const registeredCount = ref(0)
const loadedFormConfig = ref({})

const publishedEvent = ref(null)
const copied = ref(false)
const previewDevice = ref('desktop')
const previewNotice = ref(false)
const previewOpen = ref(false)
const imagePreview = ref('')
const imageError = ref('')

const event = reactive({ name: '', description: '', category: 'Conference', image: '', start_date: '', end_date: '', start_time: '', end_time: '', location: '', registration_start: '', deadline: '', maximum_participants: 200, allow_registration: true })
const setupFieldType = (type) => ({ text: 'Text', textarea: 'Text area', email: 'Email', phone: 'Phone', number: 'Number', date: 'Date', time: 'Time', select: 'Select', radio: 'Radio', checkbox: 'Checkbox', file: 'File', url: 'URL', yesno: 'Yes/No' })[String(type).toLowerCase()] || type
const fields = ref(starterFields().map((field, index) => ({ ...field, id: index + 1, type: setupFieldType(field.type) })))
const agenda = ref([])

/* ------------------------------------------------------------------
   Date helpers (inputs are local time; the API stores UTC ISO strings)
   ------------------------------------------------------------------ */
const pad = (value) => String(value).padStart(2, '0')
const toLocalParts = (iso) => {
  const date = iso ? new Date(iso) : null
  if (!date || Number.isNaN(date.getTime())) return { date: '', time: '' }
  return { date: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`, time: `${pad(date.getHours())}:${pad(date.getMinutes())}` }
}
const localDateTime = (date, time) => {
  if (!date || !time) return null
  const value = new Date(`${date}T${time}`)
  return Number.isNaN(value.getTime()) ? null : value
}
const toIso = (date, time) => localDateTime(date, time)?.toISOString() ?? null
const toMinutes = (time) => { const [hours, minutes] = String(time || '').split(':').map(Number); return Number.isFinite(hours) && Number.isFinite(minutes) ? hours * 60 + minutes : null }
const fromMinutes = (total) => `${pad(Math.floor(Math.min(total, 1439) / 60))}:${pad(Math.min(total, 1439) % 60)}`
const formatDate = (value, options = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) => value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, options) : ''
const formatTime = (value) => value ? new Date(`2000-01-01T${value}`).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''
const dayCount = (start, end) => {
  if (!start || !end) return 0
  const [sy, sm, sd] = start.split('-').map(Number); const [ey, em, ed] = end.split('-').map(Number)
  return Math.floor((Date.UTC(ey, em - 1, ed) - Date.UTC(sy, sm - 1, sd)) / 86400000) + 1
}
const eventDuration = computed(() => {
  const days = dayCount(event.start_date, event.end_date)
  if (!event.start_date || !event.end_date) return ''
  return days < 1 ? '' : `${days} ${days === 1 ? 'day' : 'days'}`
})

/* ------------------------------------------------------------------
   Validation — each step validates only its own fields
   ------------------------------------------------------------------ */
const basicsOrder = ['name', 'start_date', 'start_time', 'end_date', 'end_time', 'location', 'registration_start', 'deadline', 'maximum_participants']
const validateBasics = () => {
  const errors = {}
  if (!event.name.trim()) errors.name = 'Enter an event name.'
  if (!event.start_date) errors.start_date = 'Choose a start date.'
  if (!event.start_time) errors.start_time = 'Choose a start time.'
  if (!event.end_date) errors.end_date = 'Choose an end date.'
  if (!event.end_time) errors.end_time = 'Choose an end time.'
  const start = localDateTime(event.start_date, event.start_time)
  const end = localDateTime(event.end_date, event.end_time)
  if (event.start_date && event.end_date && event.end_date < event.start_date) errors.end_date = 'The end date can’t be before the start date.'
  else if (start && end && end <= start) errors.end_time = 'The event must end after it starts.'
  // The API only accepts future start times when an event is first published.
  if (start && !isLive.value && start <= new Date()) errors.start_time = errors.start_time || 'The event must start in the future.'
  if (!event.location.trim()) errors.location = 'Enter where the event takes place.'
  const capacity = Number(event.maximum_participants)
  if (!Number.isInteger(capacity) || capacity < 1) errors.maximum_participants = 'Enter a whole number of 1 or more.'
  else if (capacity < registeredCount.value) errors.maximum_participants = `Capacity can’t be lower than the ${registeredCount.value} people already registered.`
  if (!event.deadline) errors.deadline = 'Choose when registration closes.'
  else if (event.registration_start && event.deadline < event.registration_start) errors.deadline = 'The deadline must be on or after the opening date.'
  else if (event.end_date && event.deadline > event.end_date) errors.deadline = 'Registration must close by the last day of the event.'
  return errors
}
const validateForm = () => fields.value.length ? {} : { fields: 'Add at least one field so attendees can register.' }
const validatorFor = { 1: validateBasics, 3: validateForm }
const shownSteps = reactive(new Set())
const serverErrors = reactive({})
const errors = computed(() => ({
  ...serverErrors,
  ...(shownSteps.has(1) ? validateBasics() : {}),
  ...(shownSteps.has(3) ? validateForm() : {}),
}))
const currentStepErrors = computed(() => {
  const keys = activeStep.value === 1 ? basicsOrder : activeStep.value === 3 ? ['fields'] : []
  return keys.filter((key) => errors.value[key])
})
const fieldAttrs = (key) => ({ id: `ev-${key}`, 'aria-invalid': errors.value[key] ? 'true' : undefined, 'aria-describedby': errors.value[key] ? `ev-${key}-error` : undefined })
const focusField = async (key) => {
  await nextTick()
  const element = document.getElementById(`ev-${key}`)
  element?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  element?.focus({ preventScroll: true })
}
const stepIsValid = (step) => !Object.keys(validatorFor[step]?.() || {}).length
const stepState = (step) => {
  if (step === activeStep.value) return 'current'
  const valid = stepIsValid(step)
  if (shownSteps.has(step) && !valid) return 'error'
  return step <= furthestStep.value && valid ? 'complete' : 'upcoming'
}

const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })
const showStep = (step) => {
  activeStep.value = step
  furthestStep.value = Math.max(furthestStep.value, step)
  router.replace({ query: { ...route.query, step } })
  scrollToTop()
}
// Moving forward checks every required step in between; moving back is always allowed.
const goToStep = (target) => {
  target = clampStep(target)
  if (target <= activeStep.value) return showStep(target)
  for (let step = activeStep.value; step < target; step += 1) {
    if (!stepIsValid(step)) {
      shownSteps.add(step)
      if (step !== activeStep.value) showStep(step)
      const firstInvalid = (step === 1 ? basicsOrder : ['fields']).find((key) => validatorFor[step]()[key])
      focusField(firstInvalid)
      return
    }
  }
  showStep(target)
}
const canVisit = (step) => step <= furthestStep.value || step === activeStep.value + 1

/* ------------------------------------------------------------------
   Smart defaults while filling in step 1
   ------------------------------------------------------------------ */
let hydrating = false
watch(() => event.start_date, (start, previous) => {
  if (hydrating || !start) return
  if (!event.end_date || event.end_date < start || event.end_date === previous) event.end_date = start
  if (!event.deadline || event.deadline === previous) event.deadline = start
})
watch(() => ({ ...event }), () => { Object.keys(serverErrors).forEach((key) => delete serverErrors[key]) })

/* ------------------------------------------------------------------
   Banner image
   ------------------------------------------------------------------ */
const compressImage = (dataUrl, maxWidth = 1600, quality = 0.82) => new Promise((resolve) => { const image = new Image(); image.onload = () => { const scale = Math.min(1, maxWidth / image.width); const canvas = document.createElement('canvas'); canvas.width = Math.round(image.width * scale); canvas.height = Math.round(image.height * scale); canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height); resolve(canvas.toDataURL('image/jpeg', quality)) }; image.onerror = () => resolve(dataUrl); image.src = dataUrl })
const selectImage = (input) => {
  const file = input.target.files?.[0]
  input.target.value = ''
  imageError.value = ''
  if (!file) return
  if (!file.type.startsWith('image/')) { imageError.value = 'Choose a PNG, JPG or WebP image.'; return }
  if (file.size > 10 * 1024 * 1024) { imageError.value = 'Choose an image smaller than 10 MB.'; return }
  const reader = new FileReader()
  reader.onload = async () => { imagePreview.value = reader.result; event.image = await compressImage(reader.result) }
  reader.readAsDataURL(file)
}
const removeImage = () => { event.image = ''; imagePreview.value = '' }

/* ------------------------------------------------------------------
   Agenda
   ------------------------------------------------------------------ */
const agendaSessionTypes = ['Session', 'Registration', 'Plenary', 'Keynote Panel', 'Workshop', 'Parallel Sessions', 'Networking', 'Break', 'Policy Dialogue', 'Research Showcase', 'Marketplace', 'Recap & Energizer', 'Outcome Adoption', 'Closing Plenary']
const activeAgendaDay = ref(1)
const eventDayCount = computed(() => Math.max(dayCount(event.start_date, event.end_date), 0))
const agendaDayNumbers = computed(() => {
  const agendaDays = agenda.value.map((session) => Number(session.day)).filter((day) => Number.isInteger(day) && day > 0)
  const last = Math.max(eventDayCount.value, ...agendaDays, 1)
  return Array.from({ length: last }, (_, index) => index + 1)
})
const isOutsideEvent = (day) => eventDayCount.value > 0 && day > eventDayCount.value
const agendaDayLabel = (day) => {
  if (!event.start_date) return `Day ${day}`
  const date = new Date(`${event.start_date}T00:00:00`)
  date.setDate(date.getDate() + day - 1)
  return `Day ${day} · ${date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}`
}
const sessionsForDay = (day) => agenda.value.filter((session) => Number(session.day) === day)
const agendaSessionsForDay = computed(() => sessionsForDay(activeAgendaDay.value))
watch(agendaDayNumbers, (days) => { if (!days.includes(activeAgendaDay.value)) activeAgendaDay.value = days[days.length - 1] })
const overlapsPrevious = (index) => {
  const list = agendaSessionsForDay.value
  if (index === 0) return false
  const start = toMinutes(list[index].start_time); const previousEnd = toMinutes(list[index - 1].end_time)
  return start !== null && previousEnd !== null && start < previousEnd
}

const agendaEditing = ref(null) // null | 'new' | session id
const agendaDraft = reactive({ start_time: '', end_time: '', session_type: 'Session', title: '', description: '', track: '', speakers: '' })
const agendaErrors = reactive({})
const openAgendaSession = (session = null) => {
  Object.keys(agendaErrors).forEach((key) => delete agendaErrors[key])
  if (session) {
    agendaEditing.value = session.id
    Object.assign(agendaDraft, { start_time: '', end_time: '', session_type: 'Session', title: '', description: '', track: '', speakers: '' }, session)
  } else {
    // Continue the day where the last session ends so adding a program is quick.
    const last = [...agendaSessionsForDay.value].sort((a, b) => (toMinutes(a.end_time) ?? 0) - (toMinutes(b.end_time) ?? 0)).pop()
    const start = last?.end_time || (activeAgendaDay.value === 1 && event.start_time) || '09:00'
    agendaEditing.value = 'new'
    Object.assign(agendaDraft, { start_time: start, end_time: fromMinutes((toMinutes(start) ?? 540) + 60), session_type: 'Session', title: '', description: '', track: '', speakers: '' })
  }
  nextTick(() => document.getElementById('agenda-title')?.focus())
}
const closeAgendaSession = () => { agendaEditing.value = null }
const saveAgendaSession = () => {
  Object.keys(agendaErrors).forEach((key) => delete agendaErrors[key])
  if (!agendaDraft.title.trim()) agendaErrors.title = 'Give the session a title.'
  const start = toMinutes(agendaDraft.start_time); const end = toMinutes(agendaDraft.end_time)
  if (start !== null && end !== null && end <= start) agendaErrors.end_time = 'End time must be after the start time.'
  if (Object.keys(agendaErrors).length) { nextTick(() => document.getElementById(agendaErrors.title ? 'agenda-title' : 'agenda-end')?.focus()); return }
  const data = { ...agendaDraft, title: agendaDraft.title.trim() }
  if (agendaEditing.value === 'new') agenda.value.push({ id: Date.now(), day: activeAgendaDay.value, ...data })
  else Object.assign(agenda.value.find((session) => session.id === agendaEditing.value) || {}, data)
  agendaEditing.value = null
}
const moveSession = (index, direction) => {
  const list = agendaSessionsForDay.value
  const target = index + direction
  if (target < 0 || target >= list.length) return
  const all = [...agenda.value]
  const a = all.indexOf(list[index]); const b = all.indexOf(list[target])
  ;[all[a], all[b]] = [all[b], all[a]]
  agenda.value = all
}
const sortDayByTime = () => {
  const day = activeAgendaDay.value
  const sorted = sessionsForDay(day).sort((a, b) => (toMinutes(a.start_time) ?? 9999) - (toMinutes(b.start_time) ?? 9999))
  agenda.value = [...agenda.value.filter((session) => Number(session.day) !== day), ...sorted]
}
const isDaySorted = computed(() => agendaSessionsForDay.value.every((session, index, list) => index === 0 || (toMinutes(list[index - 1].start_time) ?? 0) <= (toMinutes(session.start_time) ?? 0)))

/* ------------------------------------------------------------------
   Registration form builder
   ------------------------------------------------------------------ */
const fieldTypeOptions = [
  { value: 'Text', label: 'Short text', icon: 'type', hint: 'One line answer' },
  { value: 'Text area', label: 'Long text', icon: 'align-left', hint: 'Longer response' },
  { value: 'Email', label: 'Email', icon: 'mail', hint: 'Email address' },
  { value: 'Phone', label: 'Phone number', icon: 'phone', hint: 'Phone number' },
  { value: 'Number', label: 'Number', icon: 'hash', hint: 'Numeric answer' },
  { value: 'Date', label: 'Date', icon: 'calendar', hint: 'Date picker' },
  { value: 'Select', label: 'Dropdown', icon: 'chevron-down', hint: 'Choose one' },
  { value: 'Radio', label: 'Radio buttons', icon: 'circle-dot', hint: 'Choose one' },
  { value: 'Checkbox', label: 'Checkboxes', icon: 'square-check', hint: 'Choose many' },
  { value: 'File', label: 'File upload', icon: 'upload', hint: 'Attach a file' },
  { value: 'URL', label: 'URL', icon: 'link', hint: 'Website link' },
]
const typeMeta = (type) => fieldTypeOptions.find((option) => option.value === type) || { label: type, icon: 'type' }
const fieldInputType = (type) => ({ Email: 'email', Phone: 'tel', Number: 'number', Date: 'date', URL: 'url', File: 'file' })[type] || 'text'
const fieldPlaceholder = (field) => field.type === 'File' ? '' : field.placeholder || (field.type === 'URL' ? 'https://example.com' : field.label ? `Enter ${field.label.toLowerCase()}` : 'Enter an answer')
const commonFields = [
  { label: 'Full name', type: 'Text', match: /full name|ឈ្មោះ/i },
  { label: 'Email', type: 'Email', match: /e-?mail|អ៊ីមែល/i },
  { label: 'Phone number', type: 'Phone', match: /phone|ទូរស័ព្ទ/i },
  { label: 'Organization', type: 'Text', match: /organi[sz]ation|អង្គភាព/i },
  { label: 'Position / Job title', type: 'Text', match: /position|job title|តួនាទី/i },
  { label: 'Province / City', type: 'Text', match: /province|city|ខេត្ត/i },
]
const fieldSuggestions = computed(() => commonFields.filter((suggestion) => !fields.value.some((field) => suggestion.match.test(field.label))))
const addCommonField = (suggestion) => fields.value.push({ id: Date.now(), label: suggestion.label, type: suggestion.type, required: false, placeholder: '', options: [], settings: {} })
const newField = reactive({ label: '', type: 'Text', required: false, helperText: '', placeholder: '', defaultValue: '', minLength: '', maxLength: '', minValue: '', maxValue: '', minDate: '', maxDate: '', options: ['Option 1', 'Option 2'], settings: {}, advancedOpen: false, adminOnly: false, visibilityRule: 'Always visible' })
const showFieldEditor = ref(false)
const editingField = ref(null)
const resetNewField = () => Object.assign(newField, { label: '', type: 'Text', required: false, helperText: '', placeholder: '', defaultValue: '', minLength: '', maxLength: '', minValue: '', maxValue: '', minDate: '', maxDate: '', options: ['Option 1', 'Option 2'], settings: {}, advancedOpen: false, adminOnly: false, visibilityRule: 'Always visible' })
const openFieldEditor = (field = null) => {
  editingField.value = field
  resetNewField()
  if (field) Object.assign(newField, field, { options: [...(field.options?.length ? field.options : ['Option 1', 'Option 2'])] })
  showFieldEditor.value = true
  nextTick(() => document.getElementById('field-label-input')?.focus())
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
const moveField = (index, direction) => { const target = index + direction; if (target < 0 || target >= fields.value.length) return; const list = [...fields.value]; [list[index], list[target]] = [list[target], list[index]]; fields.value = list }

/* ------------------------------------------------------------------
   Undo toast for deletions (instead of confirmation pop-ups)
   ------------------------------------------------------------------ */
const toast = ref(null)
let toastTimer = null
const showToast = (message, undo = null) => {
  clearTimeout(toastTimer)
  toast.value = { message, undo }
  toastTimer = setTimeout(() => { toast.value = null }, 6000)
}
const undoToast = () => { toast.value?.undo?.(); toast.value = null }
const removeSession = (session) => {
  const index = agenda.value.indexOf(session)
  agenda.value = agenda.value.filter((item) => item !== session)
  showToast(`Removed “${session.title || 'session'}”.`, () => { const list = [...agenda.value]; list.splice(index, 0, session); agenda.value = list })
}
const removeField = (field) => {
  const index = fields.value.indexOf(field)
  fields.value = fields.value.filter((item) => item !== field)
  showToast(`Removed “${field.label}”.`, () => { const list = [...fields.value]; list.splice(index, 0, field); fields.value = list })
}

/* ------------------------------------------------------------------
   Review checklist
   ------------------------------------------------------------------ */
const readiness = computed(() => {
  const items = []
  const basics = validateBasics()
  basicsOrder.filter((key) => basics[key]).forEach((key) => items.push({ level: 'error', step: 1, key, message: basics[key] }))
  const form = validateForm()
  if (form.fields) items.push({ level: 'error', step: 3, key: 'fields', message: form.fields })
  if (!event.allow_registration) items.push({ level: 'warning', step: 1, key: 'allow_registration', message: 'Registration is switched off, so attendees can’t sign up yet.' })
  if (fields.value.length && !fields.value.some((field) => field.type === 'Email')) items.push({ level: 'warning', step: 3, key: 'fields', message: 'The form has no email field, so you won’t be able to email attendees.' })
  if (!agenda.value.length) items.push({ level: 'info', step: 2, message: 'No agenda added. Attendees won’t see a program — that’s fine for simple events.' })
  if (!event.image) items.push({ level: 'info', step: 1, key: 'banner', message: 'No banner image added.' })
  return items
})
const blockingIssues = computed(() => readiness.value.filter((item) => item.level === 'error'))
const fixIssue = (issue) => {
  if (issue.level === 'error') shownSteps.add(issue.step)
  showStep(issue.step)
  if (issue.key) focusField(issue.key)
}
const scheduleSummary = computed(() => {
  if (!event.start_date) return 'Not scheduled yet'
  const sameDay = event.start_date === event.end_date || !event.end_date
  const time = [formatTime(event.start_time), formatTime(event.end_time)].filter(Boolean).join(' – ')
  return sameDay ? `${formatDate(event.start_date)}${time ? ` · ${time}` : ''}` : `${formatDate(event.start_date)} ${formatTime(event.start_time)} – ${formatDate(event.end_date)} ${formatTime(event.end_time)}`
})

/* ------------------------------------------------------------------
   Saving: drafts, publishing, dirty tracking and local recovery
   ------------------------------------------------------------------ */
const saveState = ref('idle') // idle | saving | saved | error
const saveError = ref('')
const lastSavedAt = ref(null)
const publishing = ref(false)
const confirmOpen = ref(false)
const busy = computed(() => saveState.value === 'saving' || publishing.value)

// Snapshot excludes the raw banner data so the comparison stays cheap.
const snapshotOf = () => JSON.stringify({ event: { ...event, image: `${event.image.length}:${event.image.slice(-24)}` }, agenda: agenda.value, fields: fields.value })
const savedSnapshot = ref(snapshotOf())
const isDirty = computed(() => snapshotOf() !== savedSnapshot.value)
const markClean = () => { savedSnapshot.value = snapshotOf() }

const saveIndicator = computed(() => {
  if (saveState.value === 'saving') return { tone: 'busy', icon: 'refresh', label: publishing.value ? 'Publishing…' : 'Saving…' }
  if (saveState.value === 'error') return { tone: 'error', icon: 'alert-circle', label: 'Couldn’t save' }
  if (isDirty.value) return { tone: 'warn', icon: 'pen', label: 'Unsaved changes' }
  if (lastSavedAt.value) return { tone: 'ok', icon: 'check', label: `Saved ${lastSavedAt.value.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}` }
  if (eventId.value) return { tone: 'ok', icon: 'check', label: 'All changes saved' }
  return { tone: 'idle', icon: 'info', label: 'Not saved yet' }
})

const buildPayload = (status) => {
  const capacity = Number(event.maximum_participants)
  const settings = loadedFormConfig.value.settings || {}
  const payload = {
    name: event.name.trim(),
    category: event.category,
    description: event.description,
    starts_at: toIso(event.start_date, event.start_time),
    ends_at: toIso(event.end_date, event.end_time),
    location: event.location.trim() || null,
    capacity: Number.isInteger(capacity) && capacity > 0 ? capacity : null,
    branding: event.image ? { image: event.image } : null,
    enabled_fields: fields.value,
    // Keep any settings made elsewhere (e.g. the full form builder) and only update ours.
    form_config: { ...loadedFormConfig.value, settings: { ...settings, registration_start: event.registration_start || null, registration_end: event.deadline || null, allow_user_edit: settings.allow_user_edit ?? true, allow_registration: event.allow_registration }, agenda: agenda.value },
    // ids let the server update fields in place so existing answers stay attached.
    form_fields: fields.value.map((field) => ({ id: field.id, label: field.label, type: ({ 'yes/no': 'yesno' })[field.type.toLowerCase()] || field.type.toLowerCase().replaceAll(' ', ''), required: Boolean(field.required), options: field.options || [], settings: field.settings || {} })),
  }
  if (status) payload.status = status
  return payload
}

const apiFieldMap = { name: 'name', starts_at: 'start_time', ends_at: 'end_time', location: 'location', capacity: 'maximum_participants' }
const handleApiError = (requestError, fallback) => {
  const response = requestError.response
  const apiErrors = response?.data?.errors || {}
  let firstKey = null
  Object.entries(apiErrors).forEach(([key, messages]) => {
    const local = apiFieldMap[key] || (key.startsWith('form_fields') ? 'fields' : null)
    if (!local) return
    serverErrors[local] = Array.isArray(messages) ? messages[0] : String(messages)
    firstKey ||= local
  })
  if (requestError.code === 'ECONNABORTED' || !response) return 'The server didn’t respond. Your changes are still here — check your connection and try again.'
  if (firstKey) {
    showStep(firstKey === 'fields' ? 3 : 1)
    focusField(firstKey)
  }
  return response?.data?.message || fallback
}

const autosaveKey = computed(() => `event_setup:${eventId.value || 'new'}`)
const saveDraft = async () => {
  if (busy.value || !canSaveDraft.value) return
  if (!event.name.trim()) {
    serverErrors.name = 'Add an event name before saving a draft.'
    if (activeStep.value !== 1) showStep(1)
    focusField('name')
    return
  }
  saveState.value = 'saving'; saveError.value = ''
  try {
    const payload = buildPayload('draft')
    const { data } = eventId.value ? await api.put(`/admin/events/${eventId.value}`, payload) : await api.post('/admin/events', payload)
    if (!eventId.value) {
      const previousKey = autosaveKey.value
      eventId.value = data.id
      eventStatus.value = 'draft'
      removeAutosave(previousKey)
      // Further saves update this draft instead of creating new events.
      allowNavigation = true
      await router.replace({ path: `/admin/events/${data.id}/edit`, query: { step: activeStep.value } })
      allowNavigation = false
    }
    markClean(); removeAutosave()
    lastSavedAt.value = new Date()
    saveState.value = 'saved'
  } catch (requestError) {
    saveError.value = handleApiError(requestError, 'Unable to save the draft. Try again.')
    saveState.value = 'error'
  }
}

const primaryLabel = computed(() => {
  if (activeStep.value === 2) return agenda.value.length ? 'Continue' : 'Skip agenda'
  if (activeStep.value === 3) return 'Review event'
  if (activeStep.value === 4) return isLive.value ? 'Save changes' : 'Publish event'
  return 'Continue'
})
const onPrimary = () => {
  if (activeStep.value < steps.length) return goToStep(activeStep.value + 1)
  requestPublish()
}
const requestPublish = () => {
  if (busy.value) return
  if (blockingIssues.value.length) {
    [1, 3].forEach((step) => { if (!stepIsValid(step)) shownSteps.add(step) })
    fixIssue(blockingIssues.value[0])
    return
  }
  confirmOpen.value = true
  nextTick(() => document.getElementById('publish-confirm')?.focus())
}
const persistEvent = (createdId, status = 'open') => {
  const id = createdId || `evt-${Date.now()}`
  const item = { ...event, id, status, agenda: agenda.value, fields: fields.value, registered: registeredCount.value, registration_link: `${window.location.origin}/events/${id}` }
  try {
    const list = JSON.parse(localStorage.getItem('event_list') || '[]').filter((entry) => String(entry.id) !== String(id))
    localStorage.setItem('event_list', JSON.stringify([item, ...list]))
  } catch {
    // The events list cache is optional; it refreshes from the API.
  }
  return item
}
const confirmPublish = async () => {
  if (publishing.value) return
  publishing.value = true; saveState.value = 'saving'; saveError.value = ''
  try {
    const payload = buildPayload(isLive.value ? undefined : 'published')
    const { data } = eventId.value ? await api.put(`/admin/events/${eventId.value}`, payload) : await api.post('/admin/events', payload)
    removeAutosave()
    if (!eventId.value) removeLegacyDraft()
    markClean()
    confirmOpen.value = false
    saveState.value = 'saved'
    publishedEvent.value = persistEvent(data.id ?? eventId.value, 'open')
    scrollToTop()
  } catch (requestError) {
    confirmOpen.value = false
    saveError.value = handleApiError(requestError, 'Unable to publish the event. Your changes are still here — try again.')
    saveState.value = 'error'
  } finally {
    publishing.value = false
  }
}

// Local recovery copy so a refresh or crash never loses work.
const autosavePayload = () => ({ savedAt: Date.now(), step: activeStep.value, event: { ...event }, agenda: agenda.value, fields: fields.value })
const removeAutosave = (key = autosaveKey.value) => { try { localStorage.removeItem(key) } catch { /* optional */ } }
const removeLegacyDraft = () => { try { localStorage.removeItem('event_draft') } catch { /* optional */ } }
let autosaveTimer = null
// Stays false until any earlier recovery copy has been offered, so loading an event can't erase it.
const autosaveReady = ref(false)
watch(snapshotOf, () => {
  if (!autosaveReady.value || loading.value || publishedEvent.value || restoreCandidate.value) return
  clearTimeout(autosaveTimer)
  autosaveTimer = setTimeout(() => {
    if (!isDirty.value) return removeAutosave()
    try { localStorage.setItem(autosaveKey.value, JSON.stringify(autosavePayload())) }
    catch { try { localStorage.setItem(autosaveKey.value, JSON.stringify({ ...autosavePayload(), event: { ...event, image: '' } })) } catch { /* storage full */ } }
  }, 800)
})
const restoreCandidate = ref(null)
const findRecovery = () => {
  try {
    const stored = JSON.parse(localStorage.getItem(autosaveKey.value) || 'null')
    if (stored?.event) return stored
    // Drafts saved by the previous version of this page lived under "event_draft".
    const legacy = !eventId.value && JSON.parse(localStorage.getItem('event_draft') || 'null')
    if (legacy?.name !== undefined) return { savedAt: null, step: 1, event: legacy, agenda: legacy.agenda || [], fields: legacy.fields || [], legacy: true }
  } catch { /* ignore broken storage */ }
  return null
}
const restoreRecovery = () => {
  const data = restoreCandidate.value
  if (!data) return
  hydrating = true
  const keys = Object.keys(event)
  Object.assign(event, Object.fromEntries(Object.entries(data.event).filter(([key]) => keys.includes(key))))
  if (Array.isArray(data.agenda)) agenda.value = data.agenda
  if (Array.isArray(data.fields) && data.fields.length) fields.value = data.fields
  imagePreview.value = event.image
  nextTick(() => { hydrating = false })
  if (data.step) showStep(clampStep(data.step))
  restoreCandidate.value = null
}
const discardRecovery = () => {
  if (restoreCandidate.value?.legacy) removeLegacyDraft()
  removeAutosave(); restoreCandidate.value = null
}

let allowNavigation = false
const beforeUnload = (unloadEvent) => { if (isDirty.value && !publishedEvent.value) { unloadEvent.preventDefault(); unloadEvent.returnValue = '' } }
onBeforeRouteLeave(() => {
  if (allowNavigation || !isDirty.value || publishedEvent.value) return true
  return window.confirm('You have unsaved changes. Leave this page? A copy stays on this device and can be restored when you come back.')
})

/* ------------------------------------------------------------------
   Load an existing event
   ------------------------------------------------------------------ */
const loadEvent = async () => {
  if (!eventId.value) return
  loading.value = true; loadError.value = ''
  hydrating = true
  try {
    const { data } = await api.get(`/events/${eventId.value}`)
    const item = data.data
    const start = toLocalParts(item.starts_at); const end = toLocalParts(item.ends_at)
    loadedFormConfig.value = item.form_config && typeof item.form_config === 'object' ? item.form_config : {}
    const settings = loadedFormConfig.value.settings || {}
    eventStatus.value = item.status || 'draft'
    registeredCount.value = Number(data.registered || 0)
    Object.assign(event, { name: item.name || '', description: item.description || '', category: item.category || 'Conference', image: item.branding?.image || '', start_date: start.date, start_time: start.time, end_date: end.date, end_time: end.time, location: item.location || '', maximum_participants: item.capacity || 200, registration_start: settings.registration_start?.slice(0, 10) || '', deadline: settings.registration_end?.slice(0, 10) || '', allow_registration: settings.allow_registration !== false })
    agenda.value = Array.isArray(loadedFormConfig.value.agenda) ? loadedFormConfig.value.agenda.map((session, index) => ({ ...session, id: session.id ?? index + 1, day: Number(session.day) || 1 })) : []
    imagePreview.value = event.image
    if (Array.isArray(item.enabled_fields) && item.enabled_fields.length && item.enabled_fields.every((field) => field && typeof field === 'object' && field.label)) {
      fields.value = item.enabled_fields.map((field, index) => ({ ...field, id: field.id || index + 1, required: Boolean(field.required), options: field.options || [], settings: field.settings || {} }))
    } else {
      const { data: formData } = await api.get(`/events/${eventId.value}/form`)
      const formSettings = formData.data?.settings || formData.data?.config?.settings || {}
      if (!event.registration_start) event.registration_start = formSettings.registration_start?.slice(0, 10) || ''
      if (!event.deadline) event.deadline = formSettings.registration_end?.slice(0, 10) || ''
      const formFields = formData.data?.fields || formData.data || []
      if (Array.isArray(formFields) && formFields.length) fields.value = formFields.map((field) => ({ id: field.id, label: field.label, type: String(field.type).toLowerCase() === 'select/dropdown' ? 'Select' : setupFieldType(String(field.type).toLowerCase()), required: Boolean(field.required), options: field.options || [], settings: field.settings || {} }))
    }
    if (!registeredCount.value && isLegacyStarterForm(fields.value)) fields.value = starterFields().map((field, index) => ({ ...field, id: index + 1, type: setupFieldType(field.type) }))
    else fields.value = addNonBinaryGenderOption(fields.value)
    markClean()
  } catch (requestError) {
    loadError.value = requestError.response?.status === 403 ? 'You don’t have permission to edit this event.' : requestError.response?.status === 404 ? 'This event no longer exists.' : 'Could not load this event. Check your connection and try again.'
  } finally {
    loading.value = false
    await nextTick(); hydrating = false
  }
}

/* ------------------------------------------------------------------
   Published screen helpers
   ------------------------------------------------------------------ */
const publishedLink = computed(() => publishedEvent.value?.registration_link || '')
const copyPublishedLink = async () => {
  if (await copyText(publishedLink.value)) { copied.value = true; setTimeout(() => { copied.value = false }, 1800) }
  else showToast('Copy failed. Select the registration link and copy it manually.')
}
const downloadPublishedQr = () => { const canvas = document.querySelector('.es-qr canvas'); if (!canvas) return; const anchor = document.createElement('a'); anchor.download = `${publishedEvent.value?.name || 'event'}-registration-qr.png`; anchor.href = canvas.toDataURL('image/png'); anchor.click() }
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char])
const printPublishedQr = () => {
  const canvas = document.querySelector('.es-qr canvas'); if (!canvas) return
  const name = escapeHtml(publishedEvent.value?.name || 'Event')
  const popup = window.open('', '_blank', 'width=640,height=760')
  popup?.document.write(`<title>${name} – QR code</title><body style="font-family:system-ui,sans-serif;text-align:center;padding:48px 24px;color:#0f1c2e"><h1 style="margin:0 0 6px;font-size:26px">${name}</h1><p style="margin:0 0 28px;color:#5b7085">Scan to register</p><img style="width:380px;max-width:100%" src="${canvas.toDataURL('image/png')}" onload="window.print()"><p style="margin-top:20px;color:#5b7085;font-size:14px">${escapeHtml(publishedLink.value)}</p></body>`)
  popup?.document.close()
}
const registrationStatus = computed(() => {
  if (!event.allow_registration) return { tone: 'paused', label: 'Registration paused' }
  const today = toLocalParts(new Date().toISOString()).date
  if (event.registration_start && event.registration_start > today) return { tone: 'soon', label: `Opens ${formatDate(event.registration_start, { month: 'short', day: 'numeric' })}` }
  return { tone: 'open', label: 'Registration open' }
})
const showPreviewNotice = () => { previewNotice.value = true; setTimeout(() => { previewNotice.value = false }, 2600) }

const headerTitle = computed(() => isLive.value ? 'Edit event' : eventId.value ? 'Edit draft' : 'Create an event')
const statusBadge = computed(() => isLive.value ? { label: 'Published', tone: 'published' } : eventId.value ? { label: 'Draft', tone: 'draft' } : { label: 'New', tone: 'new' })

const handleKeydown = (keyEvent) => {
  if (keyEvent.key !== 'Escape') return
  if (confirmOpen.value && !publishing.value) confirmOpen.value = false
  else if (showFieldEditor.value) showFieldEditor.value = false
  else if (agendaEditing.value) agendaEditing.value = null
}
onMounted(async () => {
  window.addEventListener('keydown', handleKeydown)
  window.addEventListener('beforeunload', beforeUnload)
  const recovery = findRecovery()
  await loadEvent()
  if (!eventId.value) markClean()
  restoreCandidate.value = loadError.value ? null : recovery
  autosaveReady.value = true
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeydown)
  window.removeEventListener('beforeunload', beforeUnload)
  clearTimeout(autosaveTimer); clearTimeout(toastTimer)
})
</script>

<template>
  <AdminLayout>
    <section v-if="!publishedEvent" class="es-page">
      <RouterLink class="es-back" to="/admin/events"><AppIcon name="chevron-left" :size="16" />Back to events</RouterLink>

      <header class="es-header">
        <div class="es-header__title">
          <div><h1>{{ headerTitle }}</h1><span class="es-status" :class="`is-${statusBadge.tone}`">{{ statusBadge.label }}</span></div>
          <p>{{ event.name.trim() || 'Set up your event in four short steps. You can save a draft at any time.' }}</p>
        </div>
        <div class="es-header__actions">
          <span class="es-save-state" :class="`is-${saveIndicator.tone}`" role="status" aria-live="polite"><AppIcon :name="saveIndicator.icon" :size="15" :class="{ 'is-spinning': saveIndicator.tone === 'busy' }" />{{ saveIndicator.label }}</span>
          <button v-if="canSaveDraft" type="button" class="es-btn" :disabled="busy || loading || Boolean(loadError)" @click="saveDraft"><AppIcon name="save" :size="16" />{{ saveState === 'saving' && !publishing ? 'Saving…' : 'Save draft' }}</button>
        </div>
      </header>

      <div v-if="saveState === 'error' && saveError" class="es-banner is-error" role="alert">
        <AppIcon name="alert-circle" :size="20" /><span>{{ saveError }}</span>
        <button type="button" @click="saveState = 'idle'"><AppIcon name="x" :size="16" /><span class="sr-only">Dismiss</span></button>
      </div>
      <div v-if="restoreCandidate" class="es-banner is-info" role="status">
        <AppIcon name="undo" :size="20" />
        <span>You have unsaved changes on this device<template v-if="restoreCandidate.savedAt"> from {{ new Date(restoreCandidate.savedAt).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) }}</template>. Restore them?</span>
        <button type="button" class="es-btn es-btn--sm es-btn--primary" @click="restoreRecovery">Restore</button>
        <button type="button" class="es-btn es-btn--sm" @click="discardRecovery">Discard</button>
      </div>

      <nav class="es-stepper" aria-label="Event setup steps">
        <ol>
          <li v-for="step in steps" :key="step.id" :class="`is-${stepState(step.id)}`">
            <button type="button" :disabled="!canVisit(step.id) || loading || Boolean(loadError)" :aria-current="activeStep === step.id ? 'step' : undefined" @click="goToStep(step.id)">
              <span class="es-stepper__dot" aria-hidden="true"><AppIcon v-if="stepState(step.id) === 'complete'" name="check" :size="15" /><AppIcon v-else-if="stepState(step.id) === 'error'" name="alert-circle" :size="15" /><template v-else>{{ step.id }}</template></span>
              <span class="es-stepper__text"><b>{{ step.label }}</b><small>{{ step.optional ? 'Optional' : step.hint }}</small></span>
              <span class="sr-only">{{ stepState(step.id) === 'complete' ? '(completed)' : stepState(step.id) === 'error' ? '(needs attention)' : '' }}</span>
            </button>
          </li>
        </ol>
      </nav>

      <div v-if="loading" class="es-card es-card--loading" role="status"><div class="es-skel" v-for="n in 4" :key="n"></div><span class="sr-only">Loading event…</span></div>
      <div v-else-if="loadError" class="es-card es-empty" role="alert">
        <span class="es-empty__icon is-danger"><AppIcon name="alert-circle" :size="26" /></span>
        <h2>{{ loadError }}</h2>
        <div class="es-empty__actions"><button type="button" class="es-btn" @click="loadEvent"><AppIcon name="refresh" :size="16" />Try again</button><RouterLink class="es-btn es-btn--primary" to="/admin/events">Back to events</RouterLink></div>
      </div>

      <form v-else class="es-card" novalidate @submit.prevent="onPrimary">
        <header class="es-step-head">
          <p>Step {{ activeStep }} of {{ steps.length }}<span v-if="steps[activeStep - 1].optional" class="es-optional">Optional</span></p>
          <h2>{{ steps[activeStep - 1].label }}</h2>
        </header>

        <div v-if="currentStepErrors.length" class="es-error-summary" role="alert">
          <AppIcon name="alert-circle" :size="18" />
          <div><strong>{{ currentStepErrors.length === 1 ? 'One thing needs your attention' : `${currentStepErrors.length} things need your attention` }}</strong>
            <ul><li v-for="key in currentStepErrors" :key="key"><a :href="`#ev-${key}`" @click.prevent="focusField(key)">{{ errors[key] }}</a></li></ul></div>
        </div>

        <!-- Step 1: Basic information -->
        <div v-if="activeStep === 1" class="es-step">
          <section class="es-section">
            <div class="es-section__head"><h3>Event details</h3><p>What attendees will see first.</p></div>
            <div class="es-grid">
              <label class="es-field es-span-2"><span class="es-label">Event name <em aria-hidden="true">*</em></span>
                <input v-model="event.name" v-bind="fieldAttrs('name')" maxlength="150" placeholder="e.g. Community Tech Summit" autocomplete="off" required />
                <small v-if="errors.name" id="ev-name-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.name }}</small>
              </label>
              <label class="es-field es-span-2"><span class="es-label">Description</span>
                <textarea v-model="event.description" rows="4" placeholder="What is this event about, and who should attend?"></textarea>
              </label>
              <label class="es-field"><span class="es-label">Category</span>
                <select v-model="event.category"><option>Conference</option><option>Workshop</option><option>Community</option><option>Training</option><option>Other</option></select>
              </label>
              <div id="ev-banner" class="es-field" tabindex="-1">
                <span class="es-label">Event banner</span>
                <div v-if="imagePreview" class="es-banner-preview">
                  <img :src="imagePreview" alt="Event banner preview" />
                  <div><label class="es-btn es-btn--sm"><AppIcon name="image" :size="15" />Replace<input class="sr-only" type="file" accept="image/*" @change="selectImage" /></label><button type="button" class="es-btn es-btn--sm es-btn--danger" @click="removeImage"><AppIcon name="trash" :size="15" />Remove</button></div>
                </div>
                <label v-else class="es-upload">
                  <input class="sr-only" type="file" accept="image/*" @change="selectImage" />
                  <span class="es-upload__icon"><AppIcon name="upload" :size="20" /></span>
                  <span><strong>Upload a banner</strong><small>PNG, JPG or WebP · wide images look best</small></span>
                </label>
                <small v-if="imageError" class="es-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ imageError }}</small>
              </div>
            </div>
          </section>

          <section class="es-section">
            <div class="es-section__head"><h3>Date, time &amp; location</h3><p>Times use your device’s time zone.</p></div>
            <div class="es-grid es-grid--4">
              <label class="es-field"><span class="es-label">Start date <em aria-hidden="true">*</em></span>
                <input v-model="event.start_date" v-bind="fieldAttrs('start_date')" type="date" required />
                <small v-if="errors.start_date" id="ev-start_date-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.start_date }}</small>
              </label>
              <label class="es-field"><span class="es-label">Start time <em aria-hidden="true">*</em></span>
                <input v-model="event.start_time" v-bind="fieldAttrs('start_time')" type="time" required />
                <small v-if="errors.start_time" id="ev-start_time-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.start_time }}</small>
              </label>
              <label class="es-field"><span class="es-label">End date <em aria-hidden="true">*</em></span>
                <input v-model="event.end_date" v-bind="fieldAttrs('end_date')" type="date" :min="event.start_date || undefined" required />
                <small v-if="errors.end_date" id="ev-end_date-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.end_date }}</small>
              </label>
              <label class="es-field"><span class="es-label">End time <em aria-hidden="true">*</em></span>
                <input v-model="event.end_time" v-bind="fieldAttrs('end_time')" type="time" required />
                <small v-if="errors.end_time" id="ev-end_time-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.end_time }}</small>
              </label>
            </div>
            <p v-if="eventDuration" class="es-duration" aria-live="polite"><AppIcon name="clock" :size="15" />{{ eventDuration }} · {{ scheduleSummary }}</p>
            <label class="es-field"><span class="es-label">Location <em aria-hidden="true">*</em></span>
              <input v-model="event.location" v-bind="fieldAttrs('location')" maxlength="200" placeholder="e.g. PNC Campus, Phnom Penh" required />
              <small v-if="errors.location" id="ev-location-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.location }}</small>
            </label>
          </section>

          <section class="es-section">
            <div class="es-section__head"><h3>Registration</h3><p>When people can sign up, and how many.</p></div>
            <div class="es-grid es-grid--3">
              <label class="es-field"><span class="es-label">Registration opens</span>
                <input v-model="event.registration_start" v-bind="fieldAttrs('registration_start')" type="date" :max="event.deadline || undefined" />
                <small class="es-hint">Leave empty to open when you publish.</small>
              </label>
              <label class="es-field"><span class="es-label">Registration deadline <em aria-hidden="true">*</em></span>
                <input v-model="event.deadline" v-bind="fieldAttrs('deadline')" type="date" :min="event.registration_start || undefined" :max="event.end_date || undefined" required />
                <small v-if="errors.deadline" id="ev-deadline-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.deadline }}</small>
                <small v-else class="es-hint">Closes at the end of this day.</small>
              </label>
              <label class="es-field"><span class="es-label">Maximum participants <em aria-hidden="true">*</em></span>
                <input v-model.number="event.maximum_participants" v-bind="fieldAttrs('maximum_participants')" type="number" min="1" step="1" inputmode="numeric" required />
                <small v-if="errors.maximum_participants" id="ev-maximum_participants-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.maximum_participants }}</small>
                <small v-else-if="registeredCount" class="es-hint">{{ registeredCount }} already registered.</small>
              </label>
            </div>
            <label id="ev-allow_registration" class="es-switch">
              <input v-model="event.allow_registration" type="checkbox" />
              <i aria-hidden="true"></i>
              <span><strong>Accept registrations</strong><small>{{ event.allow_registration ? 'Attendees can register once the event is published and registration has opened.' : 'Registration is paused. The event page is visible but nobody can sign up.' }}</small></span>
            </label>
          </section>
        </div>

        <!-- Step 2: Agenda -->
        <div v-else-if="activeStep === 2" class="es-step">
          <p class="es-lead">Add the sessions attendees will see on the event page. Skip this step if your event doesn’t need a program — you can add one later.</p>
          <div v-if="agendaDayNumbers.length > 1" class="es-tabs" role="tablist" aria-label="Agenda day">
            <button v-for="day in agendaDayNumbers" :key="day" type="button" role="tab" :aria-selected="activeAgendaDay === day" :class="{ 'is-active': activeAgendaDay === day, 'is-outside': isOutsideEvent(day) }" @click="activeAgendaDay = day">
              {{ agendaDayLabel(day) }}<span>{{ sessionsForDay(day).length }}</span>
            </button>
          </div>
          <p v-if="isOutsideEvent(activeAgendaDay)" class="es-note is-warn"><AppIcon name="alert-circle" :size="16" />This day is after the event’s end date. Move these sessions or extend the event dates.</p>

          <div class="es-toolbar">
            <strong>{{ agendaDayNumbers.length > 1 ? agendaDayLabel(activeAgendaDay) : 'Sessions' }}</strong>
            <div>
              <button v-if="agendaSessionsForDay.length > 1 && !isDaySorted" type="button" class="es-btn es-btn--sm" @click="sortDayByTime"><AppIcon name="sort" :size="15" />Sort by time</button>
              <button type="button" class="es-btn es-btn--sm es-btn--primary" @click="openAgendaSession()"><AppIcon name="plus" :size="15" />Add session</button>
            </div>
          </div>

          <ol v-if="agendaSessionsForDay.length" class="es-sessions">
            <li v-for="(session, index) in agendaSessionsForDay" :key="session.id" class="es-session">
              <div class="es-session__time"><b>{{ session.start_time ? formatTime(session.start_time) : '—' }}</b><small v-if="session.end_time">{{ formatTime(session.end_time) }}</small></div>
              <div class="es-session__body">
                <div class="es-session__title"><strong>{{ session.title || 'Untitled session' }}</strong><span class="es-tag">{{ session.session_type || 'Session' }}</span></div>
                <p v-if="session.description">{{ session.description }}</p>
                <small v-if="session.speakers || session.track"><template v-if="session.track">{{ session.track }}</template><template v-if="session.track && session.speakers"> · </template>{{ session.speakers }}</small>
                <small v-if="overlapsPrevious(index)" class="es-overlap"><AppIcon name="alert-circle" :size="13" />Overlaps the previous session</small>
              </div>
              <div class="es-row-actions">
                <button type="button" class="es-icon-btn" :disabled="index === 0" :aria-label="`Move ${session.title || 'session'} up`" title="Move up" @click="moveSession(index, -1)"><AppIcon name="arrow-up" :size="16" /></button>
                <button type="button" class="es-icon-btn" :disabled="index === agendaSessionsForDay.length - 1" :aria-label="`Move ${session.title || 'session'} down`" title="Move down" @click="moveSession(index, 1)"><AppIcon name="arrow-down" :size="16" /></button>
                <button type="button" class="es-icon-btn" :aria-label="`Edit ${session.title || 'session'}`" title="Edit" @click="openAgendaSession(session)"><AppIcon name="pen" :size="16" /></button>
                <button type="button" class="es-icon-btn is-danger" :aria-label="`Delete ${session.title || 'session'}`" title="Delete" @click="removeSession(session)"><AppIcon name="trash" :size="16" /></button>
              </div>
            </li>
          </ol>
          <div v-else class="es-empty es-empty--inline">
            <span class="es-empty__icon"><AppIcon name="calendar-plus" :size="24" /></span>
            <h3>No sessions {{ agendaDayNumbers.length > 1 ? 'for this day ' : '' }}yet</h3>
            <p>Add your first session, or continue without an agenda.</p>
            <button type="button" class="es-btn es-btn--primary" @click="openAgendaSession()"><AppIcon name="plus" :size="16" />Add first session</button>
          </div>
        </div>

        <!-- Step 3: Registration form -->
        <div v-else-if="activeStep === 3" class="es-step">
          <p class="es-lead">Choose what attendees fill in when they register. The preview updates as you edit.</p>
          <div class="es-builder">
            <div class="es-builder__list">
              <div class="es-toolbar"><strong>{{ fields.length }} {{ fields.length === 1 ? 'field' : 'fields' }}</strong><button type="button" class="es-btn es-btn--sm es-btn--primary" @click="openFieldEditor()"><AppIcon name="plus" :size="15" />Add custom field</button></div>
              <div v-if="fieldSuggestions.length" class="es-suggestions"><span>Quick add:</span><button v-for="suggestion in fieldSuggestions" :key="suggestion.label" type="button" class="es-chip" @click="addCommonField(suggestion)"><AppIcon name="plus" :size="13" />{{ suggestion.label }}</button></div>
              <ol id="ev-fields" class="es-fields" tabindex="-1" :aria-invalid="errors.fields ? 'true' : undefined">
                <li v-for="(field, index) in fields" :key="field.id" class="es-field-row">
                  <span class="es-field-row__icon" aria-hidden="true"><AppIcon :name="typeMeta(field.type).icon" :size="17" /></span>
                  <div class="es-field-row__body"><strong :title="field.label">{{ field.label }}</strong><small>{{ typeMeta(field.type).label }}<template v-if="field.options?.length"> · {{ field.options.length }} options</template></small></div>
                  <label class="es-mini-switch" :title="field.required ? 'Required' : 'Optional'"><input v-model="field.required" type="checkbox" :aria-label="`${field.label} is required`" /><i aria-hidden="true"></i><span>Required</span></label>
                  <div class="es-row-actions">
                    <button type="button" class="es-icon-btn" :disabled="index === 0" :aria-label="`Move ${field.label} up`" title="Move up" @click="moveField(index, -1)"><AppIcon name="arrow-up" :size="16" /></button>
                    <button type="button" class="es-icon-btn" :disabled="index === fields.length - 1" :aria-label="`Move ${field.label} down`" title="Move down" @click="moveField(index, 1)"><AppIcon name="arrow-down" :size="16" /></button>
                    <button type="button" class="es-icon-btn" :aria-label="`Edit ${field.label}`" title="Edit" @click="openFieldEditor(field)"><AppIcon name="pen" :size="16" /></button>
                    <button type="button" class="es-icon-btn is-danger" :aria-label="`Delete ${field.label}`" title="Delete" @click="removeField(field)"><AppIcon name="trash" :size="16" /></button>
                  </div>
                </li>
              </ol>
              <div v-if="!fields.length" class="es-empty es-empty--inline"><span class="es-empty__icon"><AppIcon name="file-text" :size="24" /></span><h3>Your form is empty</h3><p>Use quick add or create a custom field.</p></div>
              <small v-if="errors.fields" id="ev-fields-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ errors.fields }}</small>
            </div>
            <aside class="es-builder__preview" aria-label="Registration form preview">
              <div class="es-preview-card">
                <p class="es-preview-card__kicker"><AppIcon name="eye" :size="14" />Live preview</p>
                <h4>Register for {{ event.name || 'this event' }}</h4>
                <div v-for="field in fields" :key="field.id" class="preview-form-field">
                  <label>{{ field.label }} <em v-if="field.required">*</em></label>
                  <select v-if="field.type === 'Select'" tabindex="-1"><option value="">Select {{ field.label.toLowerCase() }}</option><option v-for="option in field.options || []" :key="option">{{ option }}</option></select>
                  <textarea v-else-if="field.type === 'Text area'" rows="2" tabindex="-1" :placeholder="fieldPlaceholder(field)"></textarea>
                  <div v-else-if="['Radio', 'Checkbox'].includes(field.type)" class="preview-choice-group"><label v-for="option in field.options || []" :key="option"><input :type="field.type === 'Radio' ? 'radio' : 'checkbox'" tabindex="-1" :name="`builder-preview-${field.id}`" />{{ option }}</label></div>
                  <div v-else-if="field.type === 'Yes/No'" class="preview-choice-group"><label v-for="option in ['Yes', 'No']" :key="option"><input type="radio" tabindex="-1" :name="`builder-preview-${field.id}`" />{{ option }}</label></div>
                  <input v-else :type="fieldInputType(field.type)" tabindex="-1" :placeholder="fieldPlaceholder(field)" />
                </div>
                <p v-if="!fields.length" class="es-hint">Fields you add appear here.</p>
                <span class="es-preview-card__submit" aria-hidden="true">Register</span>
              </div>
            </aside>
          </div>
        </div>

        <!-- Step 4: Review -->
        <div v-else class="es-step">
          <section class="es-readiness" :class="blockingIssues.length ? 'is-blocked' : 'is-ready'">
            <div class="es-readiness__head">
              <span class="es-readiness__icon"><AppIcon :name="blockingIssues.length ? 'alert-circle' : 'check'" :size="20" /></span>
              <div><h3>{{ blockingIssues.length ? `Fix ${blockingIssues.length} ${blockingIssues.length === 1 ? 'item' : 'items'} before publishing` : isLive ? 'Ready to save' : 'Ready to publish' }}</h3>
                <p>{{ blockingIssues.length ? 'Required information is missing or needs correcting.' : isLive ? 'Changes go live as soon as you save.' : 'Everything required is in place.' }}</p></div>
            </div>
            <ul v-if="readiness.length">
              <li v-for="(issue, index) in readiness" :key="index" :class="`is-${issue.level}`"><AppIcon :name="issue.level === 'error' ? 'alert-circle' : issue.level === 'warning' ? 'alert-circle' : 'info'" :size="16" /><span>{{ issue.message }}</span><button type="button" class="es-link" @click="fixIssue(issue)">{{ issue.level === 'error' ? 'Fix' : 'Review' }}</button></li>
            </ul>
          </section>

          <div class="es-review">
            <article class="es-review__card">
              <header><h3>Event details</h3><button type="button" class="es-link" @click="showStep(1)"><AppIcon name="pen" :size="14" />Edit</button></header>
              <div class="es-review__details">
                <img v-if="imagePreview" :src="imagePreview" alt="" />
                <div><span class="es-tag">{{ event.category }}</span><strong>{{ event.name || 'Untitled event' }}</strong><p>{{ event.description || 'No description added.' }}</p></div>
              </div>
            </article>
            <article class="es-review__card">
              <header><h3>Schedule &amp; registration</h3><button type="button" class="es-link" @click="showStep(1)"><AppIcon name="pen" :size="14" />Edit</button></header>
              <dl class="es-dl">
                <div><dt><AppIcon name="calendar" :size="15" />When</dt><dd>{{ scheduleSummary }}</dd></div>
                <div><dt><AppIcon name="pin" :size="15" />Where</dt><dd>{{ event.location || 'Not set' }}</dd></div>
                <div><dt><AppIcon name="clock" :size="15" />Registration</dt><dd>{{ event.registration_start ? formatDate(event.registration_start) : 'Opens on publish' }} → {{ event.deadline ? formatDate(event.deadline) : 'No deadline' }}</dd></div>
                <div><dt><AppIcon name="users" :size="15" />Capacity</dt><dd>{{ Number(event.maximum_participants) > 0 ? `${Number(event.maximum_participants).toLocaleString()} people` : 'Not set' }} · {{ event.allow_registration ? 'Accepting registrations' : 'Registration paused' }}</dd></div>
              </dl>
            </article>
            <article class="es-review__card">
              <header><h3>Agenda</h3><button type="button" class="es-link" @click="showStep(2)"><AppIcon name="pen" :size="14" />Edit</button></header>
              <p v-if="!agenda.length" class="es-hint">No agenda — attendees won’t see a program.</p>
              <div v-else class="es-review__agenda">
                <div v-for="day in agendaDayNumbers.filter((value) => sessionsForDay(value).length)" :key="day">
                  <b v-if="agendaDayNumbers.length > 1">{{ agendaDayLabel(day) }}</b>
                  <ul><li v-for="session in sessionsForDay(day).slice(0, 4)" :key="session.id"><span>{{ formatTime(session.start_time) || '—' }}</span>{{ session.title || 'Untitled session' }}</li><li v-if="sessionsForDay(day).length > 4" class="es-hint">+ {{ sessionsForDay(day).length - 4 }} more</li></ul>
                </div>
              </div>
            </article>
            <article class="es-review__card">
              <header><h3>Registration form</h3><button type="button" class="es-link" @click="showStep(3)"><AppIcon name="pen" :size="14" />Edit</button></header>
              <ul class="es-review__fields"><li v-for="field in fields" :key="field.id"><AppIcon :name="typeMeta(field.type).icon" :size="15" /><span>{{ field.label }}</span><em v-if="field.required">Required</em></li></ul>
              <p v-if="!fields.length" class="es-hint">No fields yet.</p>
            </article>
          </div>

          <section class="es-preview-toggle">
            <button type="button" class="es-disclosure" :aria-expanded="previewOpen" aria-controls="attendee-preview" @click="previewOpen = !previewOpen"><AppIcon name="eye" :size="17" /><span>Preview the attendee page</span><AppIcon name="chevron-down" :size="17" :class="{ 'is-open': previewOpen }" /></button>
            <div v-if="previewOpen" id="attendee-preview">
              <div class="es-segmented" role="group" aria-label="Preview size"><button type="button" :aria-pressed="previewDevice === 'desktop'" :class="{ 'is-active': previewDevice === 'desktop' }" @click="previewDevice = 'desktop'">Desktop</button><button type="button" :aria-pressed="previewDevice === 'mobile'" :class="{ 'is-active': previewDevice === 'mobile' }" @click="previewDevice = 'mobile'">Mobile</button></div>
              <div class="preview-frame-wrap"><div class="user-preview-frame" :class="`is-${previewDevice}`"><div class="user-preview-browser"><span></span><span></span><span></span><small>Event registration</small></div><div class="user-preview-page"><img v-if="imagePreview" class="user-preview-banner" :src="imagePreview" alt="Event banner" /><div class="user-preview-content"><div class="user-preview-event-header"><span class="event-category">{{ event.category }}</span><h3>{{ event.name || 'Your event name' }}</h3><p>{{ event.description || 'Your event description will appear here.' }}</p><div class="user-preview-meta"><span><AppIcon name="calendar" :size="14" /> {{ event.start_date ? formatDate(event.start_date) : 'Date to be confirmed' }}</span><span><AppIcon name="clock" :size="14" /> {{ formatTime(event.start_time) || 'Time' }} – {{ formatTime(event.end_time) || '—' }}</span><span><AppIcon name="pin" :size="14" /> {{ event.location || 'Location to be confirmed' }}</span></div><div class="user-preview-deadline"><span>Registration deadline</span><strong>{{ event.deadline ? formatDate(event.deadline) : 'Not set' }}</strong><span class="preview-seats">{{ event.maximum_participants }} seats available</span></div></div><div class="user-preview-form"><p class="preview-form-kicker">Registration form</p><h4>Register for {{ event.name || 'this event' }}</h4><p class="preview-form-help">Complete the form below to reserve your place.</p><div v-for="field in fields" :key="field.id" class="preview-form-field"><label>{{ field.label }} <em v-if="field.required">*</em></label><select v-if="field.type === 'Select'"><option value="">Select {{ field.label.toLowerCase() }}</option><option v-for="option in field.options || []" :key="option">{{ option }}</option></select><textarea v-else-if="field.type === 'Text area'" rows="3" :placeholder="fieldPlaceholder(field)"></textarea><div v-else-if="['Radio', 'Checkbox'].includes(field.type)" class="preview-choice-group"><label v-for="option in field.options || []" :key="option"><input :type="field.type === 'Radio' ? 'radio' : 'checkbox'" :name="`preview-${field.id}`" />{{ option }}</label></div><div v-else-if="field.type === 'Yes/No'" class="preview-choice-group"><label v-for="option in ['Yes', 'No']" :key="option"><input type="radio" :name="`preview-${field.id}`" />{{ option }}</label></div><input v-else :type="fieldInputType(field.type)" :placeholder="fieldPlaceholder(field)" /><small v-if="field.type === 'File' && field.settings?.allowed_types?.length" class="preview-upload-hint">Allowed: {{ field.settings.allowed_types.join(', ') }} · up to {{ field.settings.max_size_mb || 5 }} MB</small></div><button type="button" class="primary-button preview-register-button" @click="showPreviewNotice">Register</button><div v-if="previewNotice" class="preview-notice">This is preview mode. No registration will be submitted.</div></div></div></div></div></div>
            </div>
          </section>
        </div>

        <footer class="es-footer">
          <button v-if="activeStep > 1" type="button" class="es-btn" :disabled="busy" @click="goToStep(activeStep - 1)"><AppIcon name="chevron-left" :size="16" />Back</button>
          <span class="es-footer__progress" aria-hidden="true"><i v-for="step in steps" :key="step.id" :class="{ 'is-done': step.id <= activeStep }"></i></span>
          <button v-if="activeStep === steps.length && canSaveDraft" type="button" class="es-btn es-footer__draft" :disabled="busy" @click="saveDraft"><AppIcon name="save" :size="16" />Save draft</button>
          <button type="submit" class="es-btn es-btn--primary es-footer__primary" :disabled="busy">
            <template v-if="activeStep === steps.length && publishing">Publishing…</template>
            <template v-else>{{ primaryLabel }}<AppIcon :name="activeStep === steps.length ? (isLive ? 'check' : 'send') : 'arrow-right'" :size="16" /></template>
          </button>
        </footer>
      </form>
    </section>

    <section v-else class="es-page es-done">
      <header class="es-done__hero">
        <span class="es-done__check" aria-hidden="true"><AppIcon name="check" :size="30" /></span>
        <p class="es-done__eyebrow">{{ isLive ? 'Changes saved' : 'Event published' }}</p>
        <h1>{{ isLive ? 'Your event is up to date' : 'Your event is live!' }}</h1>
        <p>{{ isLive ? 'Attendees now see the latest details.' : 'Share the registration link or QR code so people can sign up.' }}</p>
      </header>

      <div class="es-done__grid">
        <div class="es-done__main">
          <article class="es-done__card es-done__event">
            <div class="es-done__cover" :style="{ '--cover-hue': 168 }">
              <img v-if="imagePreview" :src="imagePreview" alt="" />
              <span v-else>{{ (publishedEvent.name || 'Event').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span>
            </div>
            <div class="es-done__event-body">
              <div class="es-done__badges"><span class="es-tag">{{ publishedEvent.category || 'Event' }}</span><span class="es-reg-status" :class="`is-${registrationStatus.tone}`">{{ registrationStatus.label }}</span></div>
              <h2>{{ publishedEvent.name }}</h2>
              <ul class="es-done__facts">
                <li><AppIcon name="calendar" :size="16" /><span>{{ scheduleSummary }}</span></li>
                <li><AppIcon name="pin" :size="16" /><span>{{ publishedEvent.location || 'Location pending' }}</span></li>
                <li><AppIcon name="users" :size="16" /><span>{{ Number(event.maximum_participants).toLocaleString() }} spots<template v-if="event.deadline"> · register by {{ formatDate(event.deadline, { month: 'short', day: 'numeric', year: 'numeric' }) }}</template></span></li>
              </ul>
            </div>
          </article>

          <article class="es-done__card">
            <h3>Share the registration link</h3>
            <p class="es-hint">Anyone with this link can open the registration page.</p>
            <div class="es-done__link">
              <AppIcon name="link" :size="17" />
              <input :value="publishedLink" readonly aria-label="Registration link" @focus="$event.target.select()" />
              <button type="button" class="es-btn es-btn--primary es-btn--sm" @click="copyPublishedLink"><AppIcon :name="copied ? 'check' : 'copy'" :size="15" />{{ copied ? 'Copied' : 'Copy' }}</button>
            </div>
            <a class="es-link es-done__open" :href="publishedLink" target="_blank" rel="noreferrer">Open the attendee page<AppIcon name="arrow-up-right" :size="15" /></a>
          </article>

          <article class="es-done__card">
            <h3>What’s next</h3>
            <nav class="es-done__next" aria-label="Next steps">
              <RouterLink :to="`/admin/events/${publishedEvent.id}`"><span class="es-done__next-icon"><AppIcon name="chart-column" :size="18" /></span><span><strong>Open event dashboard</strong><small>Track registrations and check-ins</small></span><AppIcon name="chevron-right" :size="18" /></RouterLink>
              <RouterLink :to="`/admin/events/${publishedEvent.id}/edit`"><span class="es-done__next-icon"><AppIcon name="pen" :size="18" /></span><span><strong>Edit event</strong><small>Change details, agenda or form</small></span><AppIcon name="chevron-right" :size="18" /></RouterLink>
              <RouterLink to="/admin/events"><span class="es-done__next-icon"><AppIcon name="calendar" :size="18" /></span><span><strong>Back to all events</strong><small>See everything in your workspace</small></span><AppIcon name="chevron-right" :size="18" /></RouterLink>
            </nav>
          </article>
        </div>

        <aside class="es-done__card es-done__qr">
          <h3>Registration QR code</h3>
          <p class="es-hint">Print it on posters or show it on screen — scanning opens the registration page.</p>
          <div class="es-qr"><QRCodeDisplay :value="publishedLink" /></div>
          <div class="es-done__qr-actions">
            <button type="button" class="es-btn" @click="downloadPublishedQr"><AppIcon name="arrow-down" :size="16" />Download PNG</button>
            <button type="button" class="es-btn" @click="printPublishedQr"><AppIcon name="file-text" :size="16" />Print</button>
          </div>
        </aside>
      </div>
    </section>

    <!-- Publish confirmation -->
    <div v-if="confirmOpen" class="modal-backdrop modal-backdrop--enhanced" @click.self="!publishing && (confirmOpen = false)">
      <section class="es-dialog" role="alertdialog" aria-modal="true" aria-labelledby="publish-title" aria-describedby="publish-desc">
        <span class="es-dialog__icon"><AppIcon :name="isLive ? 'check' : 'send'" :size="22" /></span>
        <h2 id="publish-title">{{ isLive ? 'Save changes to this live event?' : `Publish “${event.name}”?` }}</h2>
        <p id="publish-desc">
          <template v-if="isLive">Attendees will see the updated details immediately<template v-if="registeredCount"> — {{ registeredCount }} people are already registered</template>.</template>
          <template v-else-if="!event.allow_registration">The event page goes live, but registration stays paused until you turn it on.</template>
          <template v-else-if="event.registration_start && event.registration_start > toLocalParts(new Date().toISOString()).date">The event page goes live now. Registration opens on {{ formatDate(event.registration_start) }}.</template>
          <template v-else>The event page goes live and attendees can register right away.</template>
        </p>
        <footer><button type="button" class="es-btn" :disabled="publishing" @click="confirmOpen = false">Cancel</button><button id="publish-confirm" type="button" class="es-btn es-btn--primary" :disabled="publishing" @click="confirmPublish">{{ publishing ? (isLive ? 'Saving…' : 'Publishing…') : (isLive ? 'Save changes' : 'Publish event') }}</button></footer>
      </section>
    </div>

    <!-- Agenda session editor -->
    <div v-if="agendaEditing" class="modal-backdrop modal-backdrop--enhanced agenda-modal-backdrop" @click.self="closeAgendaSession">
      <form class="agenda-edit-modal" role="dialog" aria-modal="true" aria-labelledby="agenda-edit-title" novalidate @submit.prevent="saveAgendaSession">
        <header><div><p class="admin-eyebrow">{{ agendaDayLabel(activeAgendaDay) }}</p><h2 id="agenda-edit-title">{{ agendaEditing === 'new' ? 'Add session' : 'Edit session' }}</h2></div><button class="modal-close" type="button" aria-label="Close dialog" @click="closeAgendaSession"><AppIcon name="x" :size="18" /></button></header>
        <div class="agenda-edit-body">
          <div class="agenda-edit-grid">
            <label class="agenda-edit-wide">Session title <em class="es-req">*</em><input id="agenda-title" v-model="agendaDraft.title" maxlength="150" placeholder="e.g. Opening keynote" :aria-invalid="agendaErrors.title ? 'true' : undefined" aria-describedby="agenda-title-error" /><small v-if="agendaErrors.title" id="agenda-title-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ agendaErrors.title }}</small></label>
            <label>Start time<input v-model="agendaDraft.start_time" type="time" /></label>
            <label>End time<input id="agenda-end" v-model="agendaDraft.end_time" type="time" :aria-invalid="agendaErrors.end_time ? 'true' : undefined" aria-describedby="agenda-end-error" /><small v-if="agendaErrors.end_time" id="agenda-end-error" class="es-error"><AppIcon name="alert-circle" :size="14" />{{ agendaErrors.end_time }}</small></label>
            <label class="agenda-edit-wide">Description<textarea v-model="agendaDraft.description" rows="3" placeholder="What happens in this session?"></textarea></label>
            <label>Session type<select v-model="agendaDraft.session_type"><option v-if="!agendaSessionTypes.includes(agendaDraft.session_type)" :value="agendaDraft.session_type">{{ agendaDraft.session_type }}</option><option v-for="type in agendaSessionTypes" :key="type">{{ type }}</option></select></label>
            <label>Track <small class="es-optional-label">optional</small><input v-model="agendaDraft.track" placeholder="e.g. Track A" /></label>
            <label class="agenda-edit-wide">Speakers / organization <small class="es-optional-label">optional</small><input v-model="agendaDraft.speakers" placeholder="Names or organizations" /></label>
          </div>
        </div>
        <footer><button type="button" class="secondary-button" @click="closeAgendaSession">Cancel</button><button type="submit" class="primary-button">{{ agendaEditing === 'new' ? 'Add session' : 'Save session' }}</button></footer>
      </form>
    </div>

    <!-- Field editor -->
    <div v-if="showFieldEditor" class="modal-backdrop modal-backdrop--enhanced" @click.self="showFieldEditor = false">
      <form class="field-modal field-modal--builder" role="dialog" aria-modal="true" aria-labelledby="field-modal-title" novalidate @submit.prevent="saveField">
        <header class="field-modal-header">
          <div><p class="admin-eyebrow">Registration form</p><h2 id="field-modal-title">{{ editingField ? 'Edit field' : 'Add field' }}</h2><p class="field-modal-help">Create a clear question attendees can answer during registration.</p></div>
          <button type="button" class="modal-close" aria-label="Close dialog" @click="showFieldEditor = false"><AppIcon name="x" :size="18" /></button>
        </header>
        <div class="field-modal-body">
          <label class="modal-field-label">Field label <input id="field-label-input" v-model="newField.label" maxlength="80" placeholder="e.g. Date of birth" /><small class="field-hint">{{ newField.label.length }}/80 · This is what attendees will see.</small></label>
          <div class="field-type-grid" role="radiogroup" aria-label="Field type">
            <button v-for="option in fieldTypeOptions" :key="option.value" type="button" role="radio" :aria-checked="newField.type === option.value" class="field-type-option" :class="{ active: newField.type === option.value }" @click="newField.type = option.value"><span class="field-type-icon"><AppIcon :name="option.icon" :size="17" /></span><span><strong>{{ option.label }}</strong><small>{{ option.hint }}</small></span></button>
          </div>
          <section v-if="['Select', 'Radio', 'Checkbox'].includes(newField.type)" class="field-modal-section">
            <div class="modal-section-heading"><h3>Options</h3><button type="button" class="modal-link-button" @click="addFieldOption">+ Add option</button></div>
            <div v-for="(option, index) in newField.options" :key="index" class="field-option-row"><span class="option-number">{{ index + 1 }}</span><input v-model="newField.options[index]" :aria-label="`Option ${index + 1}`" /><button type="button" :aria-label="`Remove option ${index + 1}`" @click="removeFieldOption(index)"><AppIcon name="x" :size="15" /></button></div>
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
        <footer class="field-modal-footer"><button type="button" class="secondary-button" @click="showFieldEditor = false">Cancel</button><button type="submit" class="primary-button" :disabled="!newField.label.trim()">Save field</button></footer>
      </form>
    </div>

    <div v-if="toast" class="es-toast" role="status" aria-live="polite"><span>{{ toast.message }}</span><button v-if="toast.undo" type="button" @click="undoToast"><AppIcon name="undo" :size="15" />Undo</button><button type="button" class="es-toast__close" aria-label="Dismiss" @click="toast = null"><AppIcon name="x" :size="15" /></button></div>
  </AdminLayout>
</template>
