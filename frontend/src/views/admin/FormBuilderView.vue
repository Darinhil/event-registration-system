<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import api from '../../services/api'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import FormRenderer from '../../components/FormRenderer.vue'
import AppIcon from '../../components/AppIcon.vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import {
  FIELD_CATEGORIES, TYPE_META, FIELD_ICONS, FIELD_WIDTHS, OPTION_TYPES,
  addNonBinaryGenderOption, createField, createSteps, isLegacyStarterForm, starterFields, slugify, baseFieldSettings,
} from '../../utils/formFieldTypes'
import { conditionAnswers, conditionOf, isConditionSource } from '../../utils/formConditions'
import { copyText } from '../../utils/clipboard'

const route = useRoute()
const router = useRouter()
const eventId = route.params.id

/* ---------------- State ---------------- */
const loading = ref(true)
const loadError = ref('')
const notFound = ref(false)
const eventName = ref('')
const eventStatus = ref('draft')
const registrationsCount = ref(0)
const formTitle = ref('Event Registration Form')
const formDescription = ref('Register for this event by completing the form below.')
const fields = ref([])
const steps = ref(createSteps(1))
const activeStepIndex = ref(0)
const stepEditOpen = ref(false)
const stepDraft = reactive({ name: '' })
const selectedId = ref(null)
const activeTab = ref('builder') // builder | settings | publish
const settings = reactive({
  registration_start: '', registration_end: '', max_participants: null,
  one_per_account: true, allow_user_edit: true,
  success_message: 'Thank you! Your registration has been received.',
  show_privacy_policy: false, require_agreement: false, terms_url: '',
})
const lastSavedAt = ref(null)
const saveState = ref('idle') // idle | saving | saved | error
const dirty = ref(false)
const previewOpen = ref(false)
const previewDevice = ref('desktop')
const previewStep = ref(0)
const previewKey = ref(0)
const previewPassed = ref(false)
const settingsOpen = ref(false)
const addPaletteOpen = ref(false)
const impactOpen = ref(false)
const paletteQuery = ref('')
const paletteSearch = ref(null)
const toast = reactive({ show: false, message: '', kind: 'success' })
const deleteTarget = ref(null)
const confirmBox = reactive({ open: false, title: '', body: '', confirmLabel: 'Confirm', danger: false, run: null })
const publishModal = ref(false)
const publishing = ref(false)
const publishSuccess = ref(false)
const publishedLink = ref('')
const copied = ref(false)
const justAdded = ref(null)
const history = ref([])
const historyIndex = ref(-1)

/* ---------------- Derived ---------------- */
const hasRegistrations = computed(() => registrationsCount.value > 0)
const isPublished = computed(() => ['published', 'open'].includes(eventStatus.value))
// Drafts save automatically. Once attendees can see the form, edits only go live on an explicit save.
const isLive = computed(() => eventStatus.value !== 'draft')
const selectedField = computed(() => fields.value.find((field) => field.id === selectedId.value) || null)
const canUndo = computed(() => historyIndex.value > 0)
const canRedo = computed(() => historyIndex.value < history.value.length - 1)
const fieldStep = (field) => Number(field.settings?.step ?? 0)
const visibleFields = computed(() => fields.value.filter((field) => fieldStep(field) === activeStepIndex.value))
const stepCount = (index) => fields.value.filter((field) => fieldStep(field) === index).length
const inputCount = computed(() => fields.value.filter((field) => field.type !== 'heading').length)
const requiredCount = computed(() => fields.value.filter((field) => field.required && field.type !== 'heading').length)
const filteredPalette = computed(() => {
  const query = paletteQuery.value.trim().toLowerCase()
  if (!query) return FIELD_CATEGORIES
  return FIELD_CATEGORIES
    .map((category) => ({ ...category, types: category.types.filter((entry) => `${entry.label} ${entry.description}`.toLowerCase().includes(query)) }))
    .filter((category) => category.types.length)
})
const statusLabel = computed(() => ({ draft: 'Draft', published: 'Published', open: 'Published', closed: 'Closed', archived: 'Archived', cancelled: 'Cancelled' }[eventStatus.value] || eventStatus.value))
const registrationLink = computed(() => publishedLink.value || `${window.location.origin}/events/${eventId}`)
const widthLabel = (field) => FIELD_WIDTHS.find((entry) => entry.value === String(field.settings?.width || '100'))?.label || 'Full'
const fieldById = (id) => fields.value.find((field) => String(field.id) === String(id))
// Position in the attendee's journey: by step, then by order within the step.
const orderOf = (field) => fieldStep(field) * 100000 + fields.value.indexOf(field)

/* ---------------- Checks before publishing / saving a live form ---------------- */
const needsOptions = (field) => ['select', 'radio'].includes(field.type) || (field.type === 'checkbox' && field.settings?.multiple)
const isBlank = (value) => value === null || value === undefined || value === ''
const issues = computed(() => {
  const list = []
  const name = (field) => `“${field.label?.trim() || TYPE_META(field.type).label}”`
  for (const field of fields.value) {
    const add = (message) => list.push({ area: 'build', fieldId: field.id, message })
    if (!field.label?.trim()) add(`A ${TYPE_META(field.type).label.toLowerCase()} field has no label.`)
    if (needsOptions(field)) {
      const options = (field.options || []).map((option) => String(option).trim())
      if (!options.filter(Boolean).length) add(`${name(field)} has no options to choose from.`)
      else if (options.some((option) => !option)) add(`${name(field)} has an empty option.`)
      else if (new Set(options).size !== options.length) add(`${name(field)} lists the same option twice.`)
    }
    const s = field.settings || {}
    if (field.type === 'number' && !isBlank(s.min_value) && !isBlank(s.max_value) && Number(s.min_value) > Number(s.max_value)) add(`${name(field)}: the minimum is higher than the maximum.`)
    if (['text', 'textarea'].includes(field.type) && s.min_length && s.max_length && Number(s.min_length) > Number(s.max_length)) add(`${name(field)}: the minimum length is longer than the maximum.`)
    const condition = conditionOf(field)
    if (condition) {
      const source = fieldById(condition.field)
      if (!source) add(`${name(field)} depends on a question that was deleted.`)
      else if (orderOf(source) >= orderOf(field)) add(`${name(field)} depends on ${name(source)}, which comes after it. Move it below that question.`)
      else if (!conditionAnswers(source).map(String).includes(String(condition.equals))) add(`${name(field)} depends on an answer ${name(source)} no longer offers.`)
    }
  }
  const at = (key, message) => list.push({ area: 'settings', key, message })
  if (settings.registration_start && settings.registration_end && settings.registration_end < settings.registration_start) at('registration_end', 'Registration must close on or after the day it opens.')
  if (!isBlank(settings.max_participants)) {
    const max = Number(settings.max_participants)
    if (!Number.isInteger(max) || max < 1) at('max_participants', 'Enter a whole number of 1 or more, or leave it empty.')
    else if (max < registrationsCount.value) at('max_participants', `${registrationsCount.value} people are already registered, so the limit can’t be lower than that.`)
  }
  if (settings.terms_url && !/^https?:\/\/\S+\.\S+/.test(settings.terms_url)) at('terms_url', 'Enter a full web address starting with https://')
  return list
})
const fieldIssues = (field) => issues.value.filter((issue) => issue.fieldId === field.id)
const settingIssue = (key) => issues.value.find((issue) => issue.key === key)?.message || ''
const buildIssues = computed(() => issues.value.filter((issue) => issue.area === 'build'))
const settingsIssues = computed(() => issues.value.filter((issue) => issue.area === 'settings'))
const goToIssue = (issue) => {
  publishModal.value = false
  if (issue.area === 'settings') { activeTab.value = 'settings'; return }
  const field = fieldById(issue.fieldId)
  if (!field) return
  activeTab.value = 'builder'
  activeStepIndex.value = fieldStep(field)
  selectField(field)
}

/* ---------------- History (undo/redo) ---------------- */
const snapshot = () => JSON.stringify({ fields: fields.value, steps: steps.value, formTitle: formTitle.value, formDescription: formDescription.value })
const pushHistory = () => {
  const next = snapshot()
  if (history.value[historyIndex.value] === next) return
  history.value = history.value.slice(0, historyIndex.value + 1)
  history.value.push(next)
  if (history.value.length > 60) history.value.shift()
  historyIndex.value = history.value.length - 1
}
const restore = (json) => {
  const state = JSON.parse(json)
  fields.value = state.fields
  steps.value = state.steps?.length ? state.steps : createSteps(1)
  formTitle.value = state.formTitle
  formDescription.value = state.formDescription
  activeStepIndex.value = Math.min(activeStepIndex.value, steps.value.length - 1)
  if (!fields.value.some((field) => field.id === selectedId.value)) selectedId.value = null
}
const undo = () => { if (canUndo.value) { historyIndex.value -= 1; restore(history.value[historyIndex.value]); touch() } }
const redo = () => { if (canRedo.value) { historyIndex.value += 1; restore(history.value[historyIndex.value]); touch() } }

/* ---------------- Loading ---------------- */
const mapServerField = (field) => {
  const type = String(field.type || 'text').toLowerCase()
  const label = field.label || 'Field'
  return {
    id: field.id ?? `f${Date.now()}${Math.random().toString(36).slice(2, 6)}`,
    label,
    description: field.description || '',
    placeholder: field.placeholder || '',
    type,
    required: Boolean(field.required),
    options: field.options || [],
    settings: { ...baseFieldSettings(type, label), ...(field.settings || {}) },
  }
}
const load = async () => {
  loading.value = true
  loadError.value = ''
  notFound.value = false
  let upgradedLegacyStarter = false
  try {
    const { data } = await api.get(`/admin/events/${eventId}/form-builder`)
    const payload = data.data
    eventName.value = payload.event.name
    eventStatus.value = payload.event.status
    registrationsCount.value = payload.event.registrations || 0
    formTitle.value = payload.form_title || formTitle.value
    formDescription.value = payload.form_description || ''
    Object.assign(settings, payload.config?.settings || {})
    settings.registration_start = settings.registration_start?.slice(0, 10) || ''
    settings.registration_end = settings.registration_end?.slice(0, 10) || ''
    fields.value = (payload.fields || []).map(mapServerField)
    if (!fields.value.length) fields.value = starterFields(0)
    else if (!registrationsCount.value && isLegacyStarterForm(fields.value)) {
      fields.value = starterFields(0)
      upgradedLegacyStarter = true
    }
    fields.value = addNonBinaryGenderOption(fields.value)
    const serverSteps = payload.config?.steps
    if (Array.isArray(serverSteps) && serverSteps.length) steps.value = serverSteps
    else {
      const maxStep = fields.value.reduce((max, field) => Math.max(max, fieldStep(field)), 0)
      steps.value = createSteps(maxStep + 1)
    }
    activeStepIndex.value = Math.min(activeStepIndex.value, steps.value.length - 1)
    if (payload.config?.updated_at) lastSavedAt.value = new Date(payload.config.updated_at).getTime()
  } catch (requestError) {
    if (requestError.response?.status === 404) notFound.value = true
    else loadError.value = requestError.response?.status === 403 ? 'You don’t have permission to edit this event’s form.' : 'We couldn’t load the form. Check your connection and try again.'
  } finally {
    loading.value = false
    pushHistory()
    dirty.value = false
    if (upgradedLegacyStarter) touch()
  }
}

/* ---------------- Saving ---------------- */
const serialize = () => ({
  form_title: formTitle.value.trim() || 'Event Registration Form',
  form_description: formDescription.value,
  config: { settings: { ...settings, max_participants: isBlank(settings.max_participants) ? null : Number(settings.max_participants) }, steps: steps.value },
  // The id lets the server update fields in place, so existing registrations keep their answers.
  fields: fields.value.map((field) => ({
    id: field.id,
    label: field.label.trim() || TYPE_META(field.type).label, description: field.description, placeholder: field.placeholder,
    type: field.type, required: field.required, options: field.options, settings: field.settings,
  })),
})
const persist = async () => {
  // A live form must stay valid: attendees would hit the broken question straight away.
  if (isLive.value && issues.value.length) {
    saveState.value = 'idle'
    showToast(`Fix ${issues.value.length} ${issues.value.length === 1 ? 'problem' : 'problems'} before saving — attendees are using this form.`, 'error')
    return false
  }
  saveState.value = 'saving'
  try {
    const { data } = await api.put(`/admin/events/${eventId}/form-builder`, serialize())
    const payload = data.data
    registrationsCount.value = payload.event.registrations || 0
    // New fields get their real ids from the server; conditions that point at them follow along.
    const serverFields = payload.fields || []
    if (serverFields.length === fields.value.length) {
      const idMap = new Map(fields.value.map((field, index) => [String(field.id), serverFields[index].id ?? field.id]))
      if (idMap.has(String(selectedId.value))) selectedId.value = idMap.get(String(selectedId.value))
      fields.value = fields.value.map((field) => {
        const condition = conditionOf(field)
        const settingsCopy = condition && idMap.has(String(condition.field)) ? { ...field.settings, show_if: { ...condition, field: idMap.get(String(condition.field)) } } : field.settings
        return { ...field, id: idMap.get(String(field.id)), settings: settingsCopy }
      })
    }
    eventStatus.value = payload.event.status
    lastSavedAt.value = Date.now()
    saveState.value = 'saved'
    dirty.value = false
    return true
  } catch (requestError) {
    saveState.value = 'error'
    showToast(requestError.response?.status === 422 ? (requestError.response.data?.message || 'Some answers aren’t valid. Check the form and try again.') : 'Couldn’t save. Check your connection and try again.', 'error')
    return false
  }
}
let autosaveTimer = null
const saveNow = () => { clearTimeout(autosaveTimer); return persist() }
const saveChanges = async () => { if (await saveNow()) showToast(isLive.value ? 'Changes saved — attendees now see the updated form' : 'Draft saved') }
const exitBuilder = () => router.push(`/admin/events/${eventId}`)
onBeforeUnmount(() => clearTimeout(autosaveTimer))

/* ---------------- Toast ---------------- */
let toastTimer = null
const showToast = (message, kind = 'success') => {
  toast.show = true; toast.message = message; toast.kind = kind
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.show = false }, 3200)
}

/* ---------------- Confirm dialog ---------------- */
const askConfirm = (options) => Object.assign(confirmBox, { open: true, confirmLabel: 'Confirm', danger: false, ...options })
const runConfirm = async () => { const run = confirmBox.run; confirmBox.open = false; await run?.() }

/* ---------------- Field mutations ---------------- */
const touch = () => {
  dirty.value = true
  if (saveState.value === 'saved') saveState.value = 'idle'
  if (isLive.value) return
  clearTimeout(autosaveTimer)
  autosaveTimer = setTimeout(() => { if (dirty.value) persist() }, 1500)
}
const selectField = async (field) => {
  selectedId.value = field.id
  settingsOpen.value = true
  await nextTick()
  document.querySelector(`[data-field-card="${field.id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}
const flashAdded = (id) => { justAdded.value = id; setTimeout(() => { if (justAdded.value === id) justAdded.value = null }, 1400) }
const addField = (type) => {
  const field = createField(type, activeStepIndex.value)
  const selected = fields.value.find((entry) => entry.id === selectedId.value)
  const anchorIndex = selected && fieldStep(selected) === activeStepIndex.value ? fields.value.indexOf(selected) : -1
  const lastInStep = fields.value.reduce((last, entry, index) => (fieldStep(entry) === activeStepIndex.value ? index : last), -1)
  const insertAt = anchorIndex >= 0 ? anchorIndex + 1 : lastInStep >= 0 ? lastInStep + 1 : fields.value.length
  fields.value.splice(insertAt, 0, field)
  addPaletteOpen.value = false
  pushHistory(); touch()
  flashAdded(field.id)
  selectField(field)
  showToast(`${TYPE_META(type).label} added — set it up on the right`)
}
const duplicateField = (field) => {
  const index = fields.value.findIndex((entry) => entry.id === field.id)
  if (index < 0) return
  const copy = { ...structuredClone(field), id: `f${Date.now()}${Math.random().toString(36).slice(2, 6)}`, label: `${field.label} (copy)` }
  copy.settings = { ...copy.settings, field_name: slugify(copy.label), field_name_locked: false }
  fields.value.splice(index + 1, 0, copy)
  pushHistory(); touch()
  flashAdded(copy.id)
  selectField(copy)
  showToast(`“${field.label}” duplicated`)
}
const dependentsOf = (field) => fields.value.filter((entry) => String(conditionOf(entry)?.field) === String(field.id))
const requestDelete = (field) => { deleteTarget.value = field }
const confirmDelete = () => {
  const target = deleteTarget.value
  if (!target) return
  // Questions that depended on this one are always shown from now on, rather than silently hidden.
  for (const dependent of dependentsOf(target)) { const { show_if: _removed, ...rest } = dependent.settings; dependent.settings = rest }
  fields.value = fields.value.filter((entry) => entry.id !== target.id)
  if (selectedId.value === target.id) selectedId.value = null
  deleteTarget.value = null
  pushHistory(); touch()
  showToast(`“${target.label}” deleted — press Undo to bring it back`)
}
const updateField = () => { pushHistory(); touch() }
const onLabelInput = () => {
  if (selectedField.value && !selectedField.value.settings?.field_name_locked) {
    selectedField.value.settings.field_name = slugify(selectedField.value.label)
  }
  touch()
}
const moveVisible = (visibleIndex, direction) => {
  const visible = visibleFields.value
  const target = visibleIndex + direction
  if (target < 0 || target >= visible.length) return
  const from = fields.value.indexOf(visible[visibleIndex])
  const to = fields.value.indexOf(visible[target])
  const list = [...fields.value]
  ;[list[from], list[to]] = [list[to], list[from]]
  fields.value = list
  pushHistory(); touch()
}
const addOption = () => { selectedField.value.options.push(`Option ${selectedField.value.options.length + 1}`); updateField() }
const removeOption = (index) => { selectedField.value.options.splice(index, 1); updateField() }

/* ---------------- Conditional logic ---------------- */
const conditionSources = computed(() => {
  const field = selectedField.value
  if (!field) return []
  return fields.value.filter((entry) => entry.id !== field.id && isConditionSource(entry) && orderOf(entry) < orderOf(field) && conditionAnswers(entry).length)
})
const conditionEnabled = computed(() => Boolean(conditionOf(selectedField.value)))
const conditionSource = computed(() => fieldById(conditionOf(selectedField.value)?.field))
const toggleCondition = (on) => {
  const field = selectedField.value
  if (on) {
    const source = conditionSources.value.at(-1)
    if (!source) return
    field.settings = { ...field.settings, show_if: { field: source.id, equals: conditionAnswers(source)[0] } }
  } else {
    const { show_if: _removed, ...rest } = field.settings
    field.settings = rest
  }
  updateField()
}
const setConditionSource = (id) => {
  const source = fieldById(id)
  if (!source) return
  selectedField.value.settings = { ...selectedField.value.settings, show_if: { field: source.id, equals: conditionAnswers(source)[0] } }
  updateField()
}
const setConditionAnswer = (answer) => {
  selectedField.value.settings = { ...selectedField.value.settings, show_if: { ...conditionOf(selectedField.value), equals: answer } }
  updateField()
}
const conditionSummary = (field) => {
  const condition = conditionOf(field)
  if (!condition) return ''
  const source = fieldById(condition.field)
  return source ? `Shown when “${source.label}” is ${condition.equals}` : 'Condition needs fixing'
}

/* ---------------- Steps ---------------- */
const selectStep = (index) => { activeStepIndex.value = index; stepEditOpen.value = false }
const addStep = () => {
  steps.value = [...steps.value, { id: `s${Date.now()}${Math.random().toString(36).slice(2, 4)}`, name: `Step ${steps.value.length + 1}` }]
  activeStepIndex.value = steps.value.length - 1
  stepDraft.name = steps.value[activeStepIndex.value].name
  stepEditOpen.value = true
  pushHistory(); touch()
}
const openStepEditor = () => {
  stepDraft.name = steps.value[activeStepIndex.value]?.name || ''
  stepEditOpen.value = true
}
const saveStepName = () => {
  const step = steps.value[activeStepIndex.value]
  if (step && stepDraft.name.trim()) step.name = stepDraft.name.trim()
  stepEditOpen.value = false
  pushHistory(); touch()
}
const moveStep = (direction) => {
  const target = activeStepIndex.value + direction
  if (target < 0 || target >= steps.value.length) return
  const list = [...steps.value]
  ;[list[activeStepIndex.value], list[target]] = [list[target], list[activeStepIndex.value]]
  steps.value = list
  // Remap field step indexes so fields follow their step.
  const a = activeStepIndex.value; const b = target
  fields.value.forEach((field) => {
    if (fieldStep(field) === a) field.settings.step = b
    else if (fieldStep(field) === b) field.settings.step = a
  })
  activeStepIndex.value = target
  pushHistory(); touch()
}
const requestDeleteStep = () => {
  if (steps.value.length <= 1) return
  const index = activeStepIndex.value
  const step = steps.value[index]
  const count = stepCount(index)
  askConfirm({
    title: `Delete “${step.name}”?`,
    body: count ? `Its ${count} ${count === 1 ? 'field moves' : 'fields move'} to “${steps.value[Math.max(0, index - 1)]?.name}”. No answers are lost.` : 'This step has no fields.',
    confirmLabel: 'Delete step', danger: true,
    run: () => {
      const target = index === 0 ? 0 : index - 1
      fields.value.forEach((field) => {
        const current = fieldStep(field)
        if (current === index) field.settings.step = target
        else if (current > index) field.settings.step = current - 1
      })
      steps.value = steps.value.filter((_, i) => i !== index)
      activeStepIndex.value = target
      stepEditOpen.value = false
      pushHistory(); touch()
      showToast('Step deleted')
    },
  })
}

/* ---------------- Drag & drop ---------------- */
const onPaletteDragStart = (event, type) => { event.dataTransfer.setData('application/x-field-type', type); event.dataTransfer.effectAllowed = 'copy' }
const dragVisible = ref(null)
const dropVisible = ref(null)
const onDragStart = (visibleIndex, event) => { dragVisible.value = visibleIndex; event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(visibleIndex)) }
const onDragOver = (visibleIndex, event) => { event.preventDefault(); dropVisible.value = visibleIndex }
const onDrop = (visibleTarget) => {
  const visible = visibleFields.value
  if (dragVisible.value === null || dragVisible.value === visibleTarget) { resetDrag(); return }
  const moved = visible[dragVisible.value]
  const target = visible[visibleTarget]
  const from = fields.value.indexOf(moved)
  const to = fields.value.indexOf(target)
  const list = [...fields.value]
  list.splice(from, 1)
  list.splice(to, 0, moved)
  fields.value = list
  resetDrag()
  pushHistory(); touch()
}
const onDropOnCanvas = (event) => {
  const type = event.dataTransfer.getData('application/x-field-type')
  if (type) addField(type)
  else if (dragVisible.value !== null) onDrop(visibleFields.value.length - 1)
  resetDrag()
}
const onDropOnStep = (index) => {
  if (dragVisible.value === null) return
  const field = visibleFields.value[dragVisible.value]
  if (field && index !== activeStepIndex.value) {
    field.settings.step = index
    pushHistory(); touch()
    showToast(`“${field.label}” moved to ${steps.value[index]?.name}`)
  }
  resetDrag()
}
const resetDrag = () => { dragVisible.value = null; dropVisible.value = null }

/* ---------------- Panels ---------------- */
const openPalette = async () => {
  addPaletteOpen.value = true
  await nextTick()
  paletteSearch.value?.focus()
}

/* ---------------- Preview (local copy — never saves or goes live) ---------------- */
const openPreview = () => { previewStep.value = 0; previewPassed.value = false; previewKey.value += 1; previewOpen.value = true }
const closePreview = () => { previewOpen.value = false }
const restartPreview = () => { previewStep.value = 0; previewPassed.value = false; previewKey.value += 1 }

/* ---------------- Publish ---------------- */
const canPublish = computed(() => inputCount.value > 0 && !issues.value.length)
const openPublishModal = () => { publishModal.value = true }
const confirmPublish = async () => {
  if (!canPublish.value) return
  publishing.value = true
  try {
    if (dirty.value && !(await saveNow())) return
    const { data } = await api.patch(`/admin/events/${eventId}/form-builder/publish`)
    publishedLink.value = data.data.registration_link || `${window.location.origin}/events/${eventId}`
    eventStatus.value = 'published'
    publishModal.value = false
    publishSuccess.value = true
  } catch (requestError) {
    showToast(requestError.response?.data?.message || 'Publishing failed. Try again.', 'error')
  } finally {
    publishing.value = false
  }
}
const requestClose = () => askConfirm({
  title: 'Close registration?',
  body: 'The form stays saved, but new attendees can no longer register. People already registered keep their place. You can reopen it later.',
  confirmLabel: 'Close registration', danger: true,
  run: async () => {
    try { await api.patch(`/admin/events/${eventId}/close`); eventStatus.value = 'closed'; showToast('Registration closed') }
    catch { showToast('Couldn’t close registration. Try again.', 'error') }
  },
})

/* ---------------- Copy / QR ---------------- */
const copyLink = async () => {
  if (await copyText(registrationLink.value)) {
    copied.value = true
    setTimeout(() => { copied.value = false }, 1800)
  } else {
    showToast('Copy failed — select the link and copy it manually.', 'error')
  }
}
const downloadQr = (scope = '.fb-qr') => {
  const canvas = document.querySelector(`${scope} canvas`)
  if (!canvas) return
  const anchor = document.createElement('a')
  anchor.download = `${(eventName.value || 'event').replace(/[^\w-]+/g, '-')}-registration-qr.png`
  anchor.href = canvas.toDataURL('image/png')
  anchor.click()
}

/* ---------------- Keyboard shortcuts & leaving ---------------- */
const onKeydown = (event) => {
  const meta = event.metaKey || event.ctrlKey
  if (event.key === 'Escape') {
    if (confirmBox.open) confirmBox.open = false
    else if (deleteTarget.value) deleteTarget.value = null
    else if (publishModal.value) publishModal.value = false
    else if (publishSuccess.value) publishSuccess.value = false
    else if (previewOpen.value) closePreview()
    else { settingsOpen.value = false; addPaletteOpen.value = false; stepEditOpen.value = false; resetDrag() }
    return
  }
  if (previewOpen.value) return
  const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName)
  if (meta && event.key.toLowerCase() === 's') { event.preventDefault(); saveChanges() }
  else if (!typing && meta && event.key.toLowerCase() === 'z' && !event.shiftKey) { event.preventDefault(); undo() }
  else if (!typing && meta && (event.key.toLowerCase() === 'y' || (event.key.toLowerCase() === 'z' && event.shiftKey))) { event.preventDefault(); redo() }
}
const onBeforeUnload = (event) => {
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('beforeunload', onBeforeUnload)
  load()
})
onBeforeRouteLeave(async () => {
  if (!dirty.value) return true
  // Drafts save on the way out; a live form's edits are only published deliberately.
  if (!isLive.value && await saveNow()) return true
  return window.confirm(isLive.value
    ? 'You have unsaved changes. Attendees won’t see them unless you save. Leave without saving?'
    : 'Your latest changes couldn’t be saved. Leave anyway and lose them?')
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeUnload)
})

const iconPath = (name) => FIELD_ICONS[name] || FIELD_ICONS.text
const workflow = computed(() => [
  { key: 'builder', label: 'Build form', meta: `${inputCount.value} ${inputCount.value === 1 ? 'question' : 'questions'}`, alert: buildIssues.value.length },
  { key: 'settings', label: 'Registration settings', meta: settingsIssues.value.length ? 'Needs attention' : 'Dates, limits, consent', alert: settingsIssues.value.length },
  { key: 'preview', label: 'Preview & test', meta: 'Try it as an attendee' },
  { key: 'publish', label: isPublished.value ? 'Share' : 'Publish', meta: isPublished.value ? 'Live' : eventStatus.value === 'closed' ? 'Closed' : 'Not live yet' },
])
const goWorkflow = (key) => { if (key === 'preview') openPreview(); else activeTab.value = key }
</script>

<template>
  <AdminLayout>
  <div class="fb-shell">
    <!-- ============ Loading / error / not found ============ -->
    <div v-if="loading" class="fb-state" role="status">
      <span class="fb-spinner" aria-hidden="true"></span>
      <p>Loading the form builder…</p>
    </div>

    <div v-else-if="notFound" class="fb-state">
      <span class="fb-state-icon"><AppIcon name="info" :size="26" /></span>
      <h1>Event not found</h1>
      <p>This event may have been removed, or the link is out of date.</p>
      <RouterLink class="fb-btn fb-btn--primary" to="/admin/events">Back to events</RouterLink>
    </div>

    <div v-else-if="loadError" class="fb-state" role="alert">
      <span class="fb-state-icon is-warn"><AppIcon name="alert-circle" :size="26" /></span>
      <h1>Form builder unavailable</h1>
      <p>{{ loadError }}</p>
      <div class="fb-row">
        <RouterLink class="fb-btn fb-btn--ghost" :to="`/admin/events/${eventId}`">Back to event</RouterLink>
        <button class="fb-btn fb-btn--primary" type="button" @click="load"><AppIcon name="refresh" :size="16" />Try again</button>
      </div>
    </div>

    <template v-else>
      <!-- ============ Header ============ -->
      <header class="fb-header">
        <div class="fb-header-top">
          <div class="fb-title">
            <RouterLink class="fb-back" :to="`/admin/events/${eventId}`"><AppIcon name="arrow-left" :size="15" />{{ eventName || 'Event' }}</RouterLink>
            <div class="fb-title-row">
              <h1>Registration form</h1>
              <span class="fb-chip" :class="`is-${eventStatus}`">{{ statusLabel }}</span>
              <span v-if="hasRegistrations" class="fb-chip is-info"><AppIcon name="users" :size="13" />{{ registrationsCount }} registered</span>
            </div>
          </div>

          <div class="fb-header-actions">
            <span class="fb-save" :class="`is-${dirty ? 'dirty' : saveState}`" role="status">
              <span class="fb-save-dot" aria-hidden="true"></span>
              <template v-if="saveState === 'saving'">Saving…</template>
              <template v-else-if="saveState === 'error'">Couldn’t save</template>
              <template v-else-if="dirty">{{ isLive ? 'Unsaved — not live yet' : 'Unsaved changes' }}</template>
              <template v-else>{{ isLive ? 'Attendees see the latest version' : 'Draft saved' }}</template>
            </span>
            <div class="fb-icon-group">
              <button class="fb-icon-btn" type="button" aria-label="Undo" title="Undo (Ctrl+Z)" :disabled="!canUndo" @click="undo">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14L4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 010 11H11" /></svg>
              </button>
              <button class="fb-icon-btn" type="button" aria-label="Redo" title="Redo (Ctrl+Y)" :disabled="!canRedo" @click="redo">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 14l5-5-5-5" /><path d="M20 9H9.5a5.5 5.5 0 000 11H13" /></svg>
              </button>
            </div>
            <button class="fb-btn fb-btn--ghost" type="button" @click="openPreview"><AppIcon name="eye" :size="16" />Preview</button>
            <template v-if="isLive">
              <button class="fb-btn fb-btn--primary" type="button" :disabled="!dirty || saveState === 'saving'" @click="saveChanges">{{ saveState === 'saving' ? 'Saving…' : 'Save changes' }}</button>
            </template>
            <template v-else>
              <button v-if="saveState === 'error'" class="fb-btn fb-btn--ghost" type="button" @click="saveChanges"><AppIcon name="refresh" :size="16" />Retry save</button>
              <button class="fb-btn fb-btn--primary" type="button" @click="openPublishModal">Publish…</button>
            </template>
          </div>
        </div>

        <!-- Workflow -->
        <nav class="fb-flow" aria-label="Form builder workflow">
          <button
            v-for="(item, index) in workflow"
            :key="item.key"
            type="button"
            class="fb-flow-step"
            :class="{ 'is-active': activeTab === item.key }"
            :aria-current="activeTab === item.key ? 'step' : undefined"
            @click="goWorkflow(item.key)"
          >
            <span class="fb-flow-num" :class="{ 'is-alert': item.alert }">{{ item.alert ? '!' : index + 1 }}</span>
            <span class="fb-flow-text"><strong>{{ item.label }}</strong><small>{{ item.alert ? `${item.alert} to fix` : item.meta }}</small></span>
          </button>
        </nav>
      </header>

      <!-- Registrations notice (always available; expands for detail) -->
      <section v-if="hasRegistrations && activeTab === 'builder'" class="fb-notice">
        <AppIcon name="info" :size="18" />
        <p><strong>{{ registrationsCount }} {{ registrationsCount === 1 ? 'person has' : 'people have' }} already registered.</strong> Their answers are kept when you edit this form.</p>
        <button type="button" class="fb-link" :aria-expanded="impactOpen" aria-controls="fb-impact" @click="impactOpen = !impactOpen">{{ impactOpen ? 'Hide details' : 'What changes affect them?' }}</button>
        <ul v-if="impactOpen" id="fb-impact" class="fb-impact">
          <li><b>Rename, reword or reorder a question:</b> existing answers stay linked to it.</li>
          <li><b>Delete a question:</b> new attendees aren’t asked it; past answers stay in exports under the old label.</li>
          <li><b>Change options:</b> answers already given are not changed.</li>
          <li><b>Make a question required:</b> only applies to new registrations and to people editing theirs.</li>
        </ul>
      </section>

      <!-- ============ Build ============ -->
      <div v-if="activeTab === 'builder'" class="fb-workspace">
        <!-- Left: components -->
        <aside class="fb-panel fb-palette" :class="{ 'is-open': addPaletteOpen }" aria-label="Form components">
          <div class="fb-panel-head">
            <div><h2>Add a field</h2><p>Click to add, or drag into the form</p></div>
            <button class="fb-icon-btn fb-drawer-close" type="button" aria-label="Close" @click="addPaletteOpen = false"><AppIcon name="x" :size="18" /></button>
          </div>
          <div class="fb-search">
            <AppIcon name="search" :size="16" />
            <input ref="paletteSearch" v-model="paletteQuery" type="search" placeholder="Search fields" aria-label="Search fields" />
          </div>
          <div class="fb-panel-scroll">
            <section v-for="category in filteredPalette" :key="category.key" class="fb-palette-group">
              <h3>{{ category.label }}</h3>
              <button
                v-for="entry in category.types"
                :key="entry.type"
                type="button"
                class="fb-palette-item"
                draggable="true"
                :aria-label="`Add ${entry.label}: ${entry.description}`"
                @dragstart="onPaletteDragStart($event, entry.type)"
                @click="addField(entry.type)"
              >
                <span class="fb-type-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="iconPath(entry.icon)" /></svg></span>
                <span class="fb-palette-copy"><strong>{{ entry.label }}</strong><small>{{ entry.description }}</small></span>
                <span class="fb-palette-plus" aria-hidden="true"><AppIcon name="plus" :size="14" /></span>
              </button>
            </section>
            <div v-if="!filteredPalette.length" class="fb-palette-empty">
              <p>No fields match “{{ paletteQuery }}”.</p>
              <button type="button" class="fb-link" @click="paletteQuery = ''">Clear search</button>
            </div>
          </div>
        </aside>

        <!-- Center: canvas -->
        <section class="fb-canvas" aria-label="Form canvas" @dragover.prevent @drop="onDropOnCanvas">
          <div class="fb-form-head">
            <input v-model="formTitle" class="fb-form-title" type="text" aria-label="Form title" placeholder="Form title" maxlength="150" @input="touch" @change="pushHistory" />
            <textarea v-model="formDescription" class="fb-form-desc" rows="2" aria-label="Form description" placeholder="Add a short description for attendees (optional)" maxlength="1000" @input="touch" @change="pushHistory"></textarea>
          </div>

          <!-- Steps -->
          <div class="fb-steps">
            <div class="fb-step-tabs" role="tablist" aria-label="Form steps">
              <button
                v-for="(step, index) in steps"
                :key="step.id"
                type="button"
                role="tab"
                class="fb-step-tab"
                :class="{ 'is-active': index === activeStepIndex, 'is-drop': dragVisible !== null && index !== activeStepIndex }"
                :aria-selected="index === activeStepIndex"
                @click="selectStep(index)"
                @dragover="dragVisible !== null && $event.preventDefault()"
                @drop.prevent.stop="onDropOnStep(index)"
              >
                <span class="fb-step-num">{{ index + 1 }}</span>{{ step.name }}<small>{{ stepCount(index) }}</small>
              </button>
              <button class="fb-step-add" type="button" @click="addStep"><AppIcon name="plus" :size="14" />Add step</button>
            </div>
            <button v-if="!stepEditOpen" class="fb-link fb-step-edit" type="button" @click="openStepEditor"><AppIcon name="pen" :size="14" />Edit step</button>
          </div>
          <p v-if="dragVisible !== null && steps.length > 1" class="fb-drop-hint">Drop on another step’s tab to move the field there.</p>

          <div v-if="stepEditOpen" class="fb-step-editor">
            <label class="fb-field-label" for="fb-step-name">Step name</label>
            <div class="fb-step-editor-row">
              <input id="fb-step-name" v-model="stepDraft.name" class="fb-input" type="text" maxlength="60" @keyup.enter="saveStepName" />
              <button class="fb-btn fb-btn--primary fb-btn--sm" type="button" @click="saveStepName">Save</button>
              <button class="fb-btn fb-btn--ghost fb-btn--sm" type="button" @click="stepEditOpen = false">Cancel</button>
            </div>
            <div class="fb-step-editor-row fb-step-editor-more">
              <button class="fb-btn fb-btn--quiet fb-btn--sm" type="button" :disabled="activeStepIndex === 0" @click="moveStep(-1)"><AppIcon name="chevron-left" :size="15" />Move earlier</button>
              <button class="fb-btn fb-btn--quiet fb-btn--sm" type="button" :disabled="activeStepIndex === steps.length - 1" @click="moveStep(1)">Move later<AppIcon name="chevron-right" :size="15" /></button>
              <button class="fb-btn fb-btn--quiet fb-btn--sm is-danger" type="button" :disabled="steps.length <= 1" @click="requestDeleteStep"><AppIcon name="trash" :size="15" />Delete step</button>
            </div>
          </div>

          <!-- Fields -->
          <TransitionGroup name="fb-list" tag="div" class="fb-fields">
            <article
              v-for="(field, index) in visibleFields"
              :key="field.id"
              :data-field-card="field.id"
              class="fb-card"
              :class="[`is-w${field.type === 'heading' ? '100' : (field.settings?.width || '100')}`, {
                'is-selected': field.id === selectedId, 'is-dragging': dragVisible === index, 'is-drop-before': dropVisible === index && dragVisible !== index,
                'is-heading': field.type === 'heading', 'is-new': justAdded === field.id, 'has-issue': fieldIssues(field).length,
              }]"
              draggable="true"
              @dragstart="onDragStart(index, $event)"
              @dragover="onDragOver(index, $event)"
              @dragend="resetDrag"
              @drop.stop="onDrop(index)"
              @click="selectField(field)"
            >
              <span class="fb-handle" title="Drag to reorder" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.4" /><circle cx="15" cy="6" r="1.4" /><circle cx="9" cy="12" r="1.4" /><circle cx="15" cy="12" r="1.4" /><circle cx="9" cy="18" r="1.4" /><circle cx="15" cy="18" r="1.4" /></svg></span>
              <div class="fb-card-body">
                <div class="fb-card-title">
                  <button type="button" class="fb-card-label" :aria-pressed="field.id === selectedId" @click.stop="selectField(field)">
                    <span v-if="field.type === 'heading'" class="fb-card-heading">{{ field.label || 'Untitled section' }}</span>
                    <template v-else>{{ field.label || 'Untitled question' }}<em v-if="field.required" class="fb-req" title="Required">*</em></template>
                  </button>
                  <span class="fb-tag">{{ TYPE_META(field.type).label }}</span>
                  <span v-if="field.type !== 'heading' && String(field.settings?.width || '100') !== '100'" class="fb-tag">{{ widthLabel(field) }} width</span>
                </div>
                <p v-if="field.description" class="fb-card-desc">{{ field.description }}</p>
                <div v-if="field.type !== 'heading'" class="fb-mock" aria-hidden="true">
                  <div v-if="field.type === 'radio' || field.type === 'yesno' || (['select', 'checkbox'].includes(field.type) && field.settings?.multiple)" class="fb-mock-choices">
                    <span v-for="option in (field.type === 'yesno' ? ['Yes', 'No'] : (field.options || []).slice(0, 4))" :key="option" class="fb-mock-choice"><i :class="field.type === 'radio' || field.type === 'yesno' ? 'is-round' : ''"></i>{{ option }}</span>
                    <span v-if="field.type !== 'yesno' && (field.options || []).length > 4" class="fb-mock-more">+{{ field.options.length - 4 }} more</span>
                  </div>
                  <div v-else-if="field.type === 'checkbox'" class="fb-mock-choices"><span class="fb-mock-choice"><i></i>{{ field.settings?.checkbox_text || field.label }}</span></div>
                  <div v-else-if="field.type === 'file'" class="fb-mock-box is-file"><AppIcon name="upload" :size="15" />Upload a file</div>
                  <div v-else-if="field.type === 'textarea'" class="fb-mock-box is-tall">{{ field.placeholder || 'Long answer' }}</div>
                  <div v-else-if="['select', 'country'].includes(field.type)" class="fb-mock-box is-select">{{ field.placeholder || 'Choose an option' }}<AppIcon name="chevron-down" :size="15" /></div>
                  <div v-else class="fb-mock-box">{{ field.placeholder || `Enter ${String(field.label || 'answer').toLowerCase()}` }}</div>
                </div>
                <p v-if="conditionOf(field)" class="fb-card-logic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3v12" /><circle cx="18" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><path d="M18 9a9 9 0 01-9 9" /></svg>{{ conditionSummary(field) }}</p>
                <p v-for="issue in fieldIssues(field)" :key="issue.message" class="fb-card-issue"><AppIcon name="alert-circle" :size="14" />{{ issue.message }}</p>
              </div>
              <div class="fb-card-actions" @click.stop>
                <button class="fb-icon-btn fb-icon-btn--sm" type="button" :disabled="index === 0" :aria-label="`Move ${field.label} up`" title="Move up" @click="moveVisible(index, -1)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" /></svg></button>
                <button class="fb-icon-btn fb-icon-btn--sm" type="button" :disabled="index === visibleFields.length - 1" :aria-label="`Move ${field.label} down`" title="Move down" @click="moveVisible(index, 1)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7" /></svg></button>
                <button class="fb-icon-btn fb-icon-btn--sm" type="button" :aria-label="`Duplicate ${field.label}`" title="Duplicate" @click="duplicateField(field)"><AppIcon name="copy" :size="15" /></button>
                <button class="fb-icon-btn fb-icon-btn--sm is-danger" type="button" :aria-label="`Delete ${field.label}`" title="Delete" @click="requestDelete(field)"><AppIcon name="trash" :size="15" /></button>
              </div>
            </article>
          </TransitionGroup>

          <div v-if="!visibleFields.length" class="fb-empty">
            <span class="fb-empty-icon"><AppIcon name="plus" :size="22" /></span>
            <h3>{{ steps.length > 1 ? `“${steps[activeStepIndex]?.name}” has no fields yet` : 'Start building your form' }}</h3>
            <p>Pick a field from the list on the left, or drag one here.</p>
            <button class="fb-btn fb-btn--primary" type="button" @click="openPalette"><AppIcon name="plus" :size="16" />Add a field</button>
          </div>
          <button v-else class="fb-add" type="button" @click="openPalette"><AppIcon name="plus" :size="16" />Add a field</button>
        </section>

        <!-- Right: field settings -->
        <aside class="fb-panel fb-inspector" :class="{ 'is-open': settingsOpen && selectedField }" aria-label="Field settings">
          <template v-if="!selectedField">
            <div class="fb-panel-head"><div><h2>Field settings</h2><p>Select a field to edit it</p></div></div>
            <div class="fb-panel-scroll fb-intro">
              <dl class="fb-summary">
                <div><dt>Questions</dt><dd>{{ inputCount }}</dd></div>
                <div><dt>Required</dt><dd>{{ requiredCount }}</dd></div>
                <div><dt>Steps</dt><dd>{{ steps.length }}</dd></div>
              </dl>
              <ol class="fb-tips">
                <li><b>Click a field</b> in the form to change its label, options and rules here.</li>
                <li><b>Drag the handle</b> or use the arrows to reorder. Drop on a step tab to move a field to that step.</li>
                <li><b>Make questions conditional</b> — for example, ask for details only when someone answers “Yes”.</li>
                <li><b>Preview</b> to test the form as an attendee before you publish.</li>
              </ol>
            </div>
          </template>

          <template v-else>
            <div class="fb-panel-head fb-inspector-head">
              <span class="fb-type-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="iconPath(TYPE_META(selectedField.type).icon)" /></svg></span>
              <div><h2>{{ TYPE_META(selectedField.type).label }}</h2><p>{{ steps[fieldStep(selectedField)]?.name }}</p></div>
              <button class="fb-icon-btn" type="button" aria-label="Close field settings" @click="selectedId = null; settingsOpen = false"><AppIcon name="x" :size="18" /></button>
            </div>

            <div class="fb-panel-scroll fb-inspector-body">
              <p v-for="issue in fieldIssues(selectedField)" :key="issue.message" class="fb-inline-issue"><AppIcon name="alert-circle" :size="15" />{{ issue.message }}</p>

              <!-- General -->
              <section class="fb-group">
                <h3>General</h3>
                <label class="fb-field-label" for="fb-f-label">{{ selectedField.type === 'heading' ? 'Section title' : 'Question' }}</label>
                <input id="fb-f-label" v-model="selectedField.label" class="fb-input" :class="{ 'is-invalid': !selectedField.label.trim() }" type="text" maxlength="150" @input="onLabelInput" @change="pushHistory" />
                <label class="fb-field-label" for="fb-f-desc">{{ selectedField.type === 'heading' ? 'Text under the title' : 'Help text' }} <span>Optional</span></label>
                <input id="fb-f-desc" v-model="selectedField.description" class="fb-input" type="text" maxlength="300" placeholder="Shown under the question" @input="touch" @change="pushHistory" />
                <template v-if="!['select', 'radio', 'checkbox', 'yesno', 'file', 'heading', 'country'].includes(selectedField.type)">
                  <label class="fb-field-label" for="fb-f-ph">Placeholder <span>Optional</span></label>
                  <input id="fb-f-ph" v-model="selectedField.placeholder" class="fb-input" type="text" maxlength="150" :placeholder="`e.g. Enter ${selectedField.label.toLowerCase()}`" @input="touch" @change="pushHistory" />
                </template>
                <label v-if="selectedField.type !== 'heading'" class="fb-switch">
                  <input type="checkbox" :checked="selectedField.required" @change="selectedField.required = $event.target.checked; updateField()" />
                  <span class="fb-switch-ui" aria-hidden="true"></span>
                  <span><strong>Required</strong><small>Attendees must answer before continuing.</small></span>
                </label>
              </section>

              <!-- Options -->
              <section v-if="OPTION_TYPES.includes(selectedField.type) || ['autocomplete', 'select'].includes(selectedField.type)" class="fb-group">
                <h3>{{ selectedField.type === 'autocomplete' ? 'Suggestions' : 'Options' }}</h3>
                <label v-if="['select', 'checkbox'].includes(selectedField.type)" class="fb-switch">
                  <input v-model="selectedField.settings.multiple" type="checkbox" @change="updateField()" />
                  <span class="fb-switch-ui" aria-hidden="true"></span>
                  <span><strong>Allow several answers</strong><small>{{ selectedField.type === 'select' ? 'Shows the options as a checklist.' : 'Turn off for a single tick box (e.g. consent).' }}</small></span>
                </label>
                <template v-if="selectedField.type !== 'checkbox' || selectedField.settings?.multiple">
                  <ol class="fb-options">
                    <li v-for="(option, index) in selectedField.options" :key="index">
                      <span class="fb-option-mark" :class="{ 'is-round': selectedField.type === 'radio' }" aria-hidden="true"></span>
                      <input v-model="selectedField.options[index]" class="fb-input" :class="{ 'is-invalid': !String(option).trim() }" type="text" maxlength="150" :aria-label="`Option ${index + 1}`" @input="touch" @change="pushHistory" />
                      <button class="fb-icon-btn fb-icon-btn--sm" type="button" :disabled="selectedField.options.length <= 1" :aria-label="`Remove option ${index + 1}`" @click="removeOption(index)"><AppIcon name="x" :size="15" /></button>
                    </li>
                  </ol>
                  <button class="fb-link" type="button" @click="addOption"><AppIcon name="plus" :size="14" />Add option</button>
                </template>
                <template v-else>
                  <label class="fb-field-label" for="fb-f-cb">Tick box text</label>
                  <input id="fb-f-cb" v-model="selectedField.settings.checkbox_text" class="fb-input" type="text" :placeholder="selectedField.label" @input="touch" @change="pushHistory" />
                </template>
              </section>

              <!-- Validation -->
              <section v-if="['text', 'textarea', 'number', 'file'].includes(selectedField.type)" class="fb-group">
                <h3>Validation</h3>
                <div v-if="['text', 'textarea'].includes(selectedField.type)" class="fb-pair">
                  <div><label class="fb-field-label" for="fb-f-minl">Min characters</label><input id="fb-f-minl" v-model.number="selectedField.settings.min_length" class="fb-input" type="number" min="0" @input="touch" @change="pushHistory" /></div>
                  <div><label class="fb-field-label" for="fb-f-maxl">Max characters</label><input id="fb-f-maxl" v-model.number="selectedField.settings.max_length" class="fb-input" type="number" min="0" @input="touch" @change="pushHistory" /></div>
                </div>
                <template v-else-if="selectedField.type === 'number'">
                  <div class="fb-pair">
                    <div><label class="fb-field-label" for="fb-f-minv">Lowest number</label><input id="fb-f-minv" v-model.number="selectedField.settings.min_value" class="fb-input" type="number" @input="touch" @change="pushHistory" /></div>
                    <div><label class="fb-field-label" for="fb-f-maxv">Highest number</label><input id="fb-f-maxv" v-model.number="selectedField.settings.max_value" class="fb-input" type="number" @input="touch" @change="pushHistory" /></div>
                  </div>
                </template>
                <template v-else>
                  <p class="fb-field-label">Accepted file types</p>
                  <div class="fb-chips-select">
                    <label v-for="ext in ['pdf', 'jpg', 'png', 'doc', 'docx', 'zip']" :key="ext" class="fb-check-chip">
                      <input v-model="selectedField.settings.allowed_types" type="checkbox" :value="ext" @change="updateField()" /><span>{{ ext.toUpperCase() }}</span>
                    </label>
                  </div>
                  <label class="fb-field-label" for="fb-f-size">Maximum size (MB)</label>
                  <input id="fb-f-size" v-model.number="selectedField.settings.max_size_mb" class="fb-input" type="number" min="1" max="50" @input="touch" @change="pushHistory" />
                </template>
                <p class="fb-hint">Email, phone, date and web-address fields are checked automatically.</p>
              </section>
              <section v-else-if="['email', 'phone', 'date', 'time', 'url'].includes(selectedField.type)" class="fb-group">
                <h3>Validation</h3>
                <p class="fb-hint">Answers are checked automatically to be a valid {{ { email: 'email address', phone: 'phone number', date: 'date', time: 'time', url: 'web address' }[selectedField.type] }}.</p>
              </section>

              <!-- Default answer -->
              <section v-if="['text', 'textarea', 'number', 'email', 'phone', 'url', 'date', 'time', 'autocomplete', 'address', 'yesno'].includes(selectedField.type) || (selectedField.type === 'checkbox' && !selectedField.settings?.multiple)" class="fb-group">
                <h3>Default answer</h3>
                <label v-if="['checkbox', 'yesno'].includes(selectedField.type)" class="fb-switch">
                  <input v-model="selectedField.settings.default_checked" type="checkbox" @change="updateField()" />
                  <span class="fb-switch-ui" aria-hidden="true"></span>
                  <span><strong>{{ selectedField.type === 'yesno' ? 'Start with “Yes” selected' : 'Ticked by default' }}</strong></span>
                </label>
                <template v-else>
                  <label class="sr-only" for="fb-f-default">Default answer</label>
                  <textarea v-if="selectedField.type === 'textarea'" id="fb-f-default" v-model="selectedField.settings.default_value" class="fb-input" rows="2" placeholder="Leave empty for none" @input="touch" @change="pushHistory"></textarea>
                  <input v-else id="fb-f-default" v-model="selectedField.settings.default_value" class="fb-input" :type="['date', 'time', 'number'].includes(selectedField.type) ? selectedField.type : 'text'" placeholder="Leave empty for none" @input="touch" @change="pushHistory" />
                </template>
              </section>

              <!-- Conditional logic -->
              <section class="fb-group">
                <h3>Show this {{ selectedField.type === 'heading' ? 'section' : 'question' }}</h3>
                <div class="fb-segment" role="radiogroup" aria-label="When to show this field">
                  <button type="button" role="radio" :aria-checked="!conditionEnabled" :class="{ 'is-active': !conditionEnabled }" @click="conditionEnabled && toggleCondition(false)">Always</button>
                  <button type="button" role="radio" :aria-checked="conditionEnabled" :class="{ 'is-active': conditionEnabled }" :disabled="!conditionEnabled && !conditionSources.length" @click="!conditionEnabled && toggleCondition(true)">Only when…</button>
                </div>
                <p v-if="!conditionEnabled && !conditionSources.length" class="fb-hint">To use this, add a Dropdown, Radio or Yes / No question above this one.</p>
                <div v-if="conditionEnabled" class="fb-logic">
                  <label class="fb-field-label" for="fb-f-src">The answer to</label>
                  <select id="fb-f-src" class="fb-input" :value="conditionSource?.id ?? ''" @change="setConditionSource($event.target.value)">
                    <option v-if="!conditionSource" value="" disabled>Choose a question</option>
                    <option v-for="source in conditionSources" :key="source.id" :value="source.id">{{ source.label }}</option>
                  </select>
                  <label class="fb-field-label" for="fb-f-ans">is</label>
                  <select id="fb-f-ans" class="fb-input" :value="conditionOf(selectedField)?.equals" :disabled="!conditionSource" @change="setConditionAnswer($event.target.value)">
                    <option v-for="answer in conditionAnswers(conditionSource)" :key="answer" :value="answer">{{ answer }}</option>
                  </select>
                  <p class="fb-hint">Hidden questions are skipped — even required ones — and their answers aren’t saved.</p>
                </div>
              </section>

              <!-- Layout -->
              <section v-if="selectedField.type !== 'heading' || steps.length > 1" class="fb-group">
                <h3>Layout</h3>
                <template v-if="selectedField.type !== 'heading'">
                  <p class="fb-field-label">Width on large screens</p>
                  <div class="fb-segment" role="radiogroup" aria-label="Field width">
                    <button v-for="option in FIELD_WIDTHS" :key="option.value" type="button" role="radio" :aria-checked="String(selectedField.settings?.width || '100') === option.value" :class="{ 'is-active': String(selectedField.settings?.width || '100') === option.value }" @click="selectedField.settings.width = option.value; updateField()">{{ option.label }}</button>
                  </div>
                </template>
                <template v-if="steps.length > 1">
                  <label class="fb-field-label" for="fb-f-step">Step</label>
                  <select id="fb-f-step" v-model.number="selectedField.settings.step" class="fb-input" @change="activeStepIndex = selectedField.settings.step; updateField()">
                    <option v-for="(step, index) in steps" :key="step.id" :value="index">{{ index + 1 }}. {{ step.name }}</option>
                  </select>
                </template>
                <label v-if="selectedField.type !== 'heading'" class="fb-switch">
                  <input v-model="selectedField.settings.hide_label" type="checkbox" @change="updateField()" />
                  <span class="fb-switch-ui" aria-hidden="true"></span>
                  <span><strong>Hide the question text</strong><small>Screen readers still announce it.</small></span>
                </label>
              </section>

              <!-- Advanced -->
              <details v-if="selectedField.type !== 'heading'" class="fb-group fb-advanced">
                <summary>Advanced</summary>
                <label class="fb-field-label" for="fb-f-name">Field name</label>
                <input id="fb-f-name" v-model="selectedField.settings.field_name" class="fb-input" type="text" placeholder="first_name" @input="selectedField.settings.field_name_locked = true; touch()" @change="pushHistory" />
                <p class="fb-hint">Used for browser autofill. Answers are stored by the question itself, so changing this never affects existing answers.</p>
              </details>
            </div>

            <div class="fb-inspector-foot">
              <button class="fb-btn fb-btn--ghost fb-btn--sm" type="button" @click="duplicateField(selectedField)"><AppIcon name="copy" :size="15" />Duplicate</button>
              <button class="fb-btn fb-btn--ghost fb-btn--sm is-danger" type="button" @click="requestDelete(selectedField)"><AppIcon name="trash" :size="15" />Delete</button>
            </div>
          </template>
        </aside>
      </div>

      <!-- ============ Registration settings ============ -->
      <div v-else-if="activeTab === 'settings'" class="fb-page">
        <div class="fb-page-grid">
          <section class="fb-panel fb-section">
            <h2>Registration window</h2>
            <p class="fb-section-lead">When people can register. Leave a date empty for no limit.</p>
            <div class="fb-pair">
              <div><label class="fb-field-label" for="fb-s-start">Opens on</label><input id="fb-s-start" v-model="settings.registration_start" class="fb-input" type="date" @change="touch" /></div>
              <div>
                <label class="fb-field-label" for="fb-s-end">Closes after</label>
                <input id="fb-s-end" v-model="settings.registration_end" class="fb-input" :class="{ 'is-invalid': settingIssue('registration_end') }" type="date" :min="settings.registration_start || undefined" :aria-describedby="settingIssue('registration_end') ? 'fb-s-end-err' : undefined" @change="touch" />
              </div>
            </div>
            <p v-if="settingIssue('registration_end')" id="fb-s-end-err" class="fb-inline-issue"><AppIcon name="alert-circle" :size="15" />{{ settingIssue('registration_end') }}</p>
            <p v-if="settings.allow_user_edit" class="fb-hint">Attendees can edit their answers until the closing date.</p>
          </section>

          <section class="fb-panel fb-section">
            <h2>Capacity &amp; limits</h2>
            <p class="fb-section-lead">Registration closes automatically when the limit is reached.</p>
            <label class="fb-field-label" for="fb-s-max">Maximum participants <span>Optional</span></label>
            <input id="fb-s-max" v-model="settings.max_participants" class="fb-input" :class="{ 'is-invalid': settingIssue('max_participants') }" type="number" min="1" step="1" placeholder="Use the event’s capacity" :aria-describedby="settingIssue('max_participants') ? 'fb-s-max-err' : undefined" @input="touch" />
            <p v-if="settingIssue('max_participants')" id="fb-s-max-err" class="fb-inline-issue"><AppIcon name="alert-circle" :size="15" />{{ settingIssue('max_participants') }}</p>
            <label class="fb-switch">
              <input v-model="settings.one_per_account" type="checkbox" @change="touch" />
              <span class="fb-switch-ui" aria-hidden="true"></span>
              <span><strong>One registration per account</strong><small>Stops people registering twice for this event.</small></span>
            </label>
          </section>

          <section class="fb-panel fb-section">
            <h2>After registering</h2>
            <p class="fb-section-lead">What attendees see and can do once they’ve signed up.</p>
            <label class="fb-field-label" for="fb-s-msg">Confirmation message</label>
            <textarea id="fb-s-msg" v-model="settings.success_message" class="fb-input" rows="3" maxlength="500" placeholder="Thank you! Your registration has been received." @input="touch"></textarea>
            <p class="fb-hint">Shown on the attendee’s registration pass straight after they register.</p>
            <label class="fb-switch">
              <input v-model="settings.allow_user_edit" type="checkbox" @change="touch" />
              <span class="fb-switch-ui" aria-hidden="true"></span>
              <span><strong>Let attendees edit their answers</strong><small>Until registration closes.</small></span>
            </label>
          </section>

          <section class="fb-panel fb-section">
            <h2>Consent &amp; privacy</h2>
            <p class="fb-section-lead">Shown at the end of the form.</p>
            <label class="fb-switch">
              <input v-model="settings.require_agreement" type="checkbox" @change="touch" />
              <span class="fb-switch-ui" aria-hidden="true"></span>
              <span><strong>Require agreement to the terms</strong><small>Attendees must tick a box before submitting.</small></span>
            </label>
            <label class="fb-switch">
              <input v-model="settings.show_privacy_policy" type="checkbox" @change="touch" />
              <span class="fb-switch-ui" aria-hidden="true"></span>
              <span><strong>Show a privacy note</strong><small>Explains the answers are only used to manage attendance.</small></span>
            </label>
            <template v-if="settings.require_agreement || settings.show_privacy_policy">
              <label class="fb-field-label" for="fb-s-terms">Terms page link <span>Optional</span></label>
              <input id="fb-s-terms" v-model="settings.terms_url" class="fb-input" :class="{ 'is-invalid': settingIssue('terms_url') }" type="url" placeholder="https://…" @input="touch" />
              <p v-if="settingIssue('terms_url')" class="fb-inline-issue"><AppIcon name="alert-circle" :size="15" />{{ settingIssue('terms_url') }}</p>
            </template>
          </section>
        </div>
        <footer class="fb-page-foot">
          <span>{{ isLive ? 'Changes apply to attendees when you save.' : 'Settings save automatically while the form is a draft.' }}</span>
          <button class="fb-btn fb-btn--ghost" type="button" @click="activeTab = 'builder'"><AppIcon name="arrow-left" :size="15" />Back to the form</button>
          <button class="fb-btn fb-btn--primary" type="button" @click="openPreview">Next: preview &amp; test</button>
        </footer>
      </div>

      <!-- ============ Publish & share ============ -->
      <div v-else class="fb-page">
        <div class="fb-page-grid">
          <section class="fb-panel fb-section">
            <div class="fb-section-head"><h2>{{ isPublished ? 'Your form is live' : eventStatus === 'closed' ? 'Registration is closed' : 'Ready to publish?' }}</h2><span class="fb-chip" :class="`is-${eventStatus}`">{{ statusLabel }}</span></div>
            <p class="fb-section-lead">{{ isPublished ? 'Attendees can register with the link or QR code.' : eventStatus === 'closed' ? 'The form is saved, but no one can register. Reopen it to accept registrations again.' : 'Publishing makes the form available to attendees.' }}</p>
            <ul class="fb-checklist">
              <li :class="inputCount ? 'is-ok' : 'is-bad'"><AppIcon :name="inputCount ? 'check-circle' : 'alert-circle'" :size="17" />{{ inputCount ? `${inputCount} ${inputCount === 1 ? 'question' : 'questions'} across ${steps.length} ${steps.length === 1 ? 'step' : 'steps'}` : 'Add at least one question' }}</li>
              <li :class="buildIssues.length ? 'is-bad' : 'is-ok'"><AppIcon :name="buildIssues.length ? 'alert-circle' : 'check-circle'" :size="17" />{{ buildIssues.length ? `${buildIssues.length} ${buildIssues.length === 1 ? 'question needs' : 'questions need'} fixing` : 'Every question is set up correctly' }}</li>
              <li :class="settingsIssues.length ? 'is-bad' : 'is-ok'"><AppIcon :name="settingsIssues.length ? 'alert-circle' : 'check-circle'" :size="17" />{{ settingsIssues.length ? 'Registration settings need attention' : 'Registration settings are valid' }}</li>
            </ul>
            <ul v-if="issues.length" class="fb-issue-list">
              <li v-for="issue in issues" :key="issue.message"><button type="button" class="fb-link" @click="goToIssue(issue)">{{ issue.message }}</button></li>
            </ul>
            <div class="fb-row">
              <button v-if="!isPublished" class="fb-btn fb-btn--primary" type="button" :disabled="!canPublish" @click="openPublishModal">{{ eventStatus === 'closed' ? 'Reopen registration…' : 'Publish form…' }}</button>
              <template v-else>
                <a class="fb-btn fb-btn--ghost" :href="registrationLink" target="_blank" rel="noopener"><AppIcon name="link" :size="15" />Open live form</a>
                <button class="fb-btn fb-btn--ghost is-danger" type="button" @click="requestClose">Close registration</button>
              </template>
            </div>
          </section>

          <section class="fb-panel fb-section">
            <h2>Share</h2>
            <p class="fb-section-lead">{{ isPublished ? 'Send the link or print the QR code.' : 'The link works once the form is published.' }}</p>
            <div class="fb-share">
              <code>{{ registrationLink }}</code>
              <button class="fb-btn fb-btn--ghost fb-btn--sm" type="button" @click="copyLink"><AppIcon :name="copied ? 'check' : 'copy'" :size="15" />{{ copied ? 'Copied' : 'Copy' }}</button>
            </div>
            <div class="fb-qr fb-qr--page"><QRCodeDisplay :value="registrationLink" /></div>
            <button class="fb-btn fb-btn--ghost fb-btn--sm" type="button" @click="downloadQr('.fb-qr--page')"><AppIcon name="download" :size="15" />Download QR code</button>
          </section>

          <section class="fb-panel fb-section">
            <div class="fb-section-head"><h2>Registrations</h2><strong class="fb-big">{{ registrationsCount }}</strong></div>
            <p class="fb-section-lead">{{ hasRegistrations ? 'Review answers and export attendee data.' : 'No one has registered yet.' }}</p>
            <div class="fb-row">
              <RouterLink class="fb-btn fb-btn--ghost" :to="`/admin/events/${eventId}/registrants`">View registrants</RouterLink>
              <RouterLink class="fb-btn fb-btn--ghost" :to="`/admin/events/${eventId}`">Event overview</RouterLink>
            </div>
          </section>
        </div>
      </div>

      <!-- Narrow screens: open the field list -->
      <button v-if="activeTab === 'builder'" class="fb-fab" type="button" aria-label="Add a field" @click="openPalette"><AppIcon name="plus" :size="22" /></button>
      <div v-if="activeTab === 'builder' && (addPaletteOpen || (settingsOpen && selectedField))" class="fb-backdrop" @click="addPaletteOpen = false; settingsOpen = false"></div>

      <!-- ============ Preview & test ============ -->
      <div v-if="previewOpen" class="fb-preview" role="dialog" aria-modal="true" aria-label="Preview and test the form">
        <header class="fb-preview-bar">
          <button class="fb-btn fb-btn--ghost fb-btn--sm" type="button" @click="closePreview"><AppIcon name="arrow-left" :size="15" />Back to editing</button>
          <div class="fb-preview-title"><strong>Preview &amp; test</strong><span>Test mode — nothing is submitted or saved{{ dirty ? ' · showing your unsaved changes' : '' }}</span></div>
          <div class="fb-segment fb-segment--dark" role="radiogroup" aria-label="Preview size">
            <button v-for="device in ['desktop', 'mobile']" :key="device" type="button" role="radio" :aria-checked="previewDevice === device" :class="{ 'is-active': previewDevice === device }" @click="previewDevice = device">{{ device === 'desktop' ? 'Desktop' : 'Mobile' }}</button>
          </div>
        </header>
        <div class="fb-preview-stage">
          <div class="fb-preview-frame" :class="`is-${previewDevice}`">
            <div v-if="previewPassed" class="fb-preview-result" role="status">
              <span class="fb-preview-check"><AppIcon name="check" :size="28" /></span>
              <h2>Test passed</h2>
              <p>Every required answer was given and all answers are valid. In the live form, the attendee would now see their registration pass{{ settings.success_message ? ` with: “${settings.success_message}”` : '' }}.</p>
              <div class="fb-row">
                <button class="fb-btn fb-btn--ghost" type="button" @click="restartPreview"><AppIcon name="refresh" :size="15" />Test again</button>
                <button class="fb-btn fb-btn--primary" type="button" @click="closePreview(); activeTab = 'publish'">{{ isPublished ? 'Done testing' : 'Continue to publish' }}</button>
              </div>
            </div>
            <FormRenderer
              v-else
              :key="previewKey"
              :fields="fields"
              :steps="steps"
              :active-step="previewStep"
              :form-title="formTitle"
              :form-description="formDescription"
              :event-name="eventName"
              :settings="settings"
              submit-label="Submit test"
              @step-change="previewStep = $event"
              @submit="previewPassed = true"
            />
          </div>
        </div>
      </div>
    </template>

    <!-- ============ Delete field ============ -->
    <div v-if="deleteTarget" class="fb-modal-backdrop" @click.self="deleteTarget = null">
      <div class="fb-modal" role="alertdialog" aria-modal="true" aria-labelledby="fb-del-title">
        <h2 id="fb-del-title">Delete “{{ deleteTarget.label || 'this field' }}”?</h2>
        <p>{{ hasRegistrations ? 'New attendees won’t be asked this. Answers already given stay in exports under this label.' : 'Attendees will no longer be asked this.' }} You can undo this.</p>
        <p v-if="dependentsOf(deleteTarget).length" class="fb-modal-note"><AppIcon name="info" :size="15" />{{ dependentsOf(deleteTarget).length }} {{ dependentsOf(deleteTarget).length === 1 ? 'question depends' : 'questions depend' }} on this answer and will be shown to everyone instead.</p>
        <footer class="fb-row fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="deleteTarget = null">Cancel</button>
          <button class="fb-btn fb-btn--danger" type="button" @click="confirmDelete">Delete field</button>
        </footer>
      </div>
    </div>

    <!-- ============ Generic confirm ============ -->
    <div v-if="confirmBox.open" class="fb-modal-backdrop" @click.self="confirmBox.open = false">
      <div class="fb-modal" role="alertdialog" aria-modal="true" aria-labelledby="fb-confirm-title">
        <h2 id="fb-confirm-title">{{ confirmBox.title }}</h2>
        <p>{{ confirmBox.body }}</p>
        <footer class="fb-row fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="confirmBox.open = false">Cancel</button>
          <button class="fb-btn" :class="confirmBox.danger ? 'fb-btn--danger' : 'fb-btn--primary'" type="button" @click="runConfirm">{{ confirmBox.confirmLabel }}</button>
        </footer>
      </div>
    </div>

    <!-- ============ Publish ============ -->
    <div v-if="publishModal" class="fb-modal-backdrop" @click.self="publishModal = false">
      <div class="fb-modal" role="dialog" aria-modal="true" aria-labelledby="fb-pub-title">
        <h2 id="fb-pub-title">{{ eventStatus === 'closed' ? 'Reopen registration?' : 'Publish this form?' }}</h2>
        <template v-if="canPublish">
          <p>Attendees will be able to register straight away at:</p>
          <code class="fb-modal-link">{{ registrationLink }}</code>
          <p class="fb-modal-note"><AppIcon name="info" :size="15" />After publishing, edits are no longer saved automatically — you choose when changes go live with <b>Save changes</b>.</p>
        </template>
        <template v-else>
          <p>Fix these first so attendees don’t run into problems:</p>
          <ul class="fb-issue-list">
            <li v-if="!inputCount">Add at least one question.</li>
            <li v-for="issue in issues" :key="issue.message"><button type="button" class="fb-link" @click="goToIssue(issue)">{{ issue.message }}</button></li>
          </ul>
        </template>
        <footer class="fb-row fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="publishModal = false">Cancel</button>
          <button v-if="canPublish" class="fb-btn fb-btn--ghost" type="button" @click="publishModal = false; openPreview()">Preview first</button>
          <button class="fb-btn fb-btn--primary" type="button" :disabled="!canPublish || publishing" @click="confirmPublish">{{ publishing ? 'Publishing…' : eventStatus === 'closed' ? 'Reopen' : 'Publish' }}</button>
        </footer>
      </div>
    </div>

    <!-- ============ Published ============ -->
    <div v-if="publishSuccess" class="fb-modal-backdrop" @click.self="publishSuccess = false">
      <div class="fb-modal fb-modal--center" role="dialog" aria-modal="true" aria-labelledby="fb-done-title">
        <span class="fb-preview-check"><AppIcon name="check" :size="28" /></span>
        <h2 id="fb-done-title">Your form is live</h2>
        <p>Share the link or QR code so people can register.</p>
        <div class="fb-share">
          <code>{{ registrationLink }}</code>
          <button class="fb-btn fb-btn--ghost fb-btn--sm" type="button" @click="copyLink"><AppIcon :name="copied ? 'check' : 'copy'" :size="15" />{{ copied ? 'Copied' : 'Copy' }}</button>
        </div>
        <div class="fb-qr fb-qr--modal"><QRCodeDisplay :value="registrationLink" /></div>
        <footer class="fb-row fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="downloadQr('.fb-qr--modal')"><AppIcon name="download" :size="15" />Download QR</button>
          <a class="fb-btn fb-btn--primary" :href="registrationLink" target="_blank" rel="noopener">Open the live form</a>
        </footer>
      </div>
    </div>
  </div>

  <Transition name="fb-toast">
    <div v-if="toast.show" class="fb-toast" :class="`is-${toast.kind}`" role="status">{{ toast.message }}</div>
  </Transition>
  </AdminLayout>
</template>
