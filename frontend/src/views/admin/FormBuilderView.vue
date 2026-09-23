<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../services/api'
import QRCodeDisplay from '../../components/QRCodeDisplay.vue'
import FormRenderer from '../../components/FormRenderer.vue'
import {
  FIELD_CATEGORIES, TYPE_META, FIELD_ICONS, FIELD_WIDTHS, OPTION_TYPES,
  createField, createSteps, starterFields, slugify, baseFieldSettings,
} from '../../utils/formFieldTypes'

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
const activeTab = ref('builder') // builder | actions | settings
const settings = reactive({
  registration_start: '', registration_end: '', max_participants: null,
  multiple_registrations: false, one_per_account: true,
  success_message: 'Thank you! Your registration has been received.',
  redirect_url: '', confirmation_email: true,
  show_privacy_policy: false, require_agreement: false, terms_url: '',
  status: 'draft',
})
const lastSavedAt = ref(null)
const saveState = ref('idle') // idle | saving | saved | error
const dirty = ref(false)
const previewOpen = ref(false)
const previewDevice = ref('desktop')
const previewStep = ref(0)
const settingsOpen = ref(false)
const addPaletteOpen = ref(false)
const structuralWarningDismissed = ref(false)
const paletteQuery = ref('')
const toast = reactive({ show: false, message: '', kind: 'success' })
const deleteTarget = ref(null)
const publishModal = ref(false)
const publishSuccess = ref(false)
const publishedLink = ref('')
const copied = ref(false)
const history = ref([])
const historyIndex = ref(-1)

/* ---------------- Derived ---------------- */
const hasRegistrations = computed(() => registrationsCount.value > 0)
const isPublished = computed(() => ['published', 'open'].includes(eventStatus.value))
const selectedField = computed(() => fields.value.find((field) => field.id === selectedId.value) || null)
const canUndo = computed(() => historyIndex.value > 0)
const canRedo = computed(() => historyIndex.value < history.value.length - 1)
const visibleFields = computed(() => fields.value.filter((field) => Number(field.settings?.step ?? 0) === activeStepIndex.value))
const filteredPalette = computed(() => {
  const query = paletteQuery.value.trim().toLowerCase()
  if (!query) return FIELD_CATEGORIES
  return FIELD_CATEGORIES
    .map((category) => ({ ...category, types: category.types.filter((entry) => `${entry.label} ${entry.description}`.toLowerCase().includes(query)) }))
    .filter((category) => category.types.length)
})
const saveLabel = computed(() => ({ idle: 'Save Draft', saving: 'Saving…', saved: 'Saved ✓', error: 'Retry' }[saveState.value]))
const lastSavedLabel = computed(() => {
  if (saveState.value === 'saving') return 'Saving…'
  if (!lastSavedAt.value) return 'Not saved yet'
  const seconds = Math.round((Date.now() - lastSavedAt.value) / 1000)
  if (seconds < 15) return 'Last saved: Just now'
  if (seconds < 60) return `Last saved: ${seconds}s ago`
  return `Last saved: ${Math.floor(seconds / 60)}m ago`
})
const statusLabel = computed(() => ({ draft: 'Draft', published: 'Published', open: 'Published', closed: 'Closed', archived: 'Archived', cancelled: 'Cancelled' }[eventStatus.value] || eventStatus.value))
const registrationLink = computed(() => publishedLink.value || `${window.location.origin}/events/${eventId}/register`)
const widthLabel = (field) => FIELD_WIDTHS.find((entry) => entry.value === String(field.settings?.width || '100'))?.label || 'Full'

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
const undo = () => { if (canUndo.value) { historyIndex.value -= 1; restore(history.value[historyIndex.value]); dirty.value = true } }
const redo = () => { if (canRedo.value) { historyIndex.value += 1; restore(history.value[historyIndex.value]); dirty.value = true } }

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
  try {
    const { data } = await api.get(`/admin/events/${eventId}/form-builder`)
    const payload = data.data
    eventName.value = payload.event.name
    eventStatus.value = payload.event.status
    registrationsCount.value = payload.event.registrations || 0
    formTitle.value = payload.form_title || formTitle.value
    formDescription.value = payload.form_description || ''
    Object.assign(settings, payload.config?.settings || {})
    fields.value = (payload.fields || []).map(mapServerField)
    if (!fields.value.length) fields.value = starterFields(0)
    const serverSteps = payload.config?.steps
    if (Array.isArray(serverSteps) && serverSteps.length) steps.value = serverSteps
    else {
      const maxStep = fields.value.reduce((max, field) => Math.max(max, Number(field.settings?.step ?? 0)), 0)
      steps.value = createSteps(maxStep + 1)
    }
    activeStepIndex.value = Math.min(activeStepIndex.value, steps.value.length - 1)
  } catch (requestError) {
    if (requestError.response?.status === 404) notFound.value = true
    else {
      loadError.value = requestError.response?.data?.message || 'Cannot reach the API. Start the backend and reload.'
      // Offline fallback so the builder is still usable in demos.
      const local = JSON.parse(localStorage.getItem(`form_builder_${eventId}`) || 'null')
      if (local) {
        fields.value = local.fields || starterFields(0)
        steps.value = local.steps?.length ? local.steps : createSteps(1)
        formTitle.value = local.formTitle || formTitle.value
        formDescription.value = local.formDescription || ''
      }
    }
  } finally {
    loading.value = false
    pushHistory()
    dirty.value = false
  }
}

/* ---------------- Saving / autosave ---------------- */
const serialize = () => ({
  form_title: formTitle.value.trim() || 'Event Registration Form',
  form_description: formDescription.value,
  config: { settings: { ...settings }, steps: steps.value },
  fields: fields.value.map((field) => ({
    label: field.label, description: field.description, placeholder: field.placeholder,
    type: field.type, required: field.required, options: field.options, settings: field.settings,
  })),
})
const persist = async () => {
  saveState.value = 'saving'
  try {
    const { data } = await api.put(`/admin/events/${eventId}/form-builder`, serialize())
    const payload = data.data
    registrationsCount.value = payload.event.registrations || 0
    // Keep server ids so validation keys stay stable after reload.
    const serverFields = payload.fields || []
    if (serverFields.length === fields.value.length) {
      fields.value = fields.value.map((field, index) => ({ ...field, id: serverFields[index].id ?? field.id }))
    }
    eventStatus.value = payload.event.status
    lastSavedAt.value = Date.now()
    saveState.value = 'saved'
    dirty.value = false
    localStorage.setItem(`form_builder_${eventId}`, JSON.stringify({ fields: fields.value, steps: steps.value, formTitle: formTitle.value, formDescription: formDescription.value }))
    return true
  } catch {
    saveState.value = 'error'
    showToast('Could not save. Check the API connection.', 'error')
    return false
  }
}
let autosaveTimer = null
const scheduleAutosave = () => {
  dirty.value = true
  clearTimeout(autosaveTimer)
  autosaveTimer = setTimeout(() => { if (dirty.value) persist() }, 1500)
}
const saveNow = () => { clearTimeout(autosaveTimer); return persist() }
const saveAndExit = async () => {
  const ok = await saveNow()
  if (ok) router.push(`/admin/events/${eventId}`)
  else showToast('Fix the connection and save before leaving.', 'error')
}
onBeforeUnmount(() => clearTimeout(autosaveTimer))

/* ---------------- Toast ---------------- */
let toastTimer = null
const showToast = (message, kind = 'success') => {
  toast.show = true; toast.message = message; toast.kind = kind
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.show = false }, 2600)
}

/* ---------------- Field mutations ---------------- */
const touch = () => { dirty.value = true; scheduleAutosave() }
const selectField = (field) => { selectedId.value = field.id; settingsOpen.value = true }
const addField = (type) => {
  const field = createField(type, activeStepIndex.value)
  const anchorIndex = fields.value.findIndex((entry) => entry.id === selectedId.value)
  const insertAt = anchorIndex >= 0 ? anchorIndex + 1 : fields.value.length
  fields.value.splice(insertAt, 0, field)
  selectedId.value = field.id
  settingsOpen.value = true
  addPaletteOpen.value = false
  pushHistory(); touch()
  showToast(`${TYPE_META(type).label} field added`)
}
const duplicateField = (field) => {
  const index = fields.value.findIndex((entry) => entry.id === field.id)
  if (index < 0) return
  const copy = { ...structuredClone(field), id: `f${Date.now()}${Math.random().toString(36).slice(2, 6)}` }
  fields.value.splice(index + 1, 0, copy)
  selectedId.value = copy.id
  pushHistory(); touch()
  showToast(`${field.label} duplicated`)
}
const requestDelete = (field) => { deleteTarget.value = field }
const confirmDelete = () => {
  if (!deleteTarget.value) return
  fields.value = fields.value.filter((entry) => entry.id !== deleteTarget.value.id)
  if (selectedId.value === deleteTarget.value.id) selectedId.value = null
  deleteTarget.value = null
  pushHistory(); touch()
  showToast('Field deleted')
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

/* ---------------- Steps ---------------- */
const selectStep = (index) => { activeStepIndex.value = index; stepEditOpen.value = false }
const addStep = () => {
  steps.value = [...steps.value, { id: `s${Date.now()}${Math.random().toString(36).slice(2, 4)}`, name: `Step ${steps.value.length + 1}` }]
  activeStepIndex.value = steps.value.length - 1
  pushHistory(); touch()
  showToast('Step added')
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
    if (Number(field.settings?.step) === a) field.settings.step = b
    else if (Number(field.settings?.step) === b) field.settings.step = a
  })
  activeStepIndex.value = target
  pushHistory(); touch()
}
const requestDeleteStep = () => {
  if (steps.value.length <= 1) return
  const step = steps.value[activeStepIndex.value]
  if (!window.confirm(`Delete “${step.name}”? Its fields will move to the previous step.`)) return
  const index = activeStepIndex.value
  fields.value.forEach((field) => {
    const current = Number(field.settings?.step ?? 0)
    if (current === index) field.settings.step = Math.max(0, index - 1)
    else if (current > index) field.settings.step = current - 1
  })
  steps.value = steps.value.filter((_, i) => i !== index)
  activeStepIndex.value = Math.max(0, index - 1)
  stepEditOpen.value = false
  pushHistory(); touch()
  showToast('Step deleted')
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
const onDropFromPalette = (event) => {
  const type = event.dataTransfer.getData('application/x-field-type')
  if (type) addField(type)
  else if (dragVisible.value !== null) onDrop(visibleFields.value.length - 1)
  resetDrag()
}
const onDragOverStep = (index, event) => {
  if (dragVisible.value === null) return
  event.preventDefault()
  event.dataTransfer.dropEffect = 'move'
}
const onDropOnStep = (index) => {
  if (dragVisible.value === null) return
  const field = visibleFields.value[dragVisible.value]
  if (field) field.settings.step = index
  resetDrag()
  pushHistory(); touch()
  showToast(`${field?.label || 'Field'} moved to ${steps.value[index]?.name}`)
}
const resetDrag = () => { dragVisible.value = null; dropVisible.value = null }

/* ---------------- Preview ---------------- */
const openPreview = async () => { await saveNow(); previewStep.value = 0; previewOpen.value = true }
const closePreview = () => { previewOpen.value = false; previewDevice.value = 'desktop' }
const openPalette = () => { addPaletteOpen.value = true }

/* ---------------- Publish ---------------- */
const canPublish = computed(() => fields.value.length > 0)
const openPublishModal = async () => { await saveNow(); publishModal.value = true }
const confirmPublish = async () => {
  try {
    const { data } = await api.patch(`/admin/events/${eventId}/form-builder/publish`)
    publishedLink.value = data.data.registration_link || `${window.location.origin}/events/${eventId}/register`
    eventStatus.value = 'published'
    settings.status = 'published'
    publishModal.value = false
    publishSuccess.value = true
    pushHistory()
    showToast('Form published successfully')
  } catch (requestError) {
    publishModal.value = false
    showToast(requestError.response?.data?.message || 'Publishing failed. Try again.', 'error')
  }
}
const closePublishSuccess = () => { publishSuccess.value = false }
const closeRegistration = async () => {
  if (!window.confirm('Close registration? Attendees will no longer be able to sign up.')) return
  try { await api.patch(`/admin/events/${eventId}/close`); eventStatus.value = 'closed'; settings.status = 'closed'; showToast('Registration closed') }
  catch { showToast('Could not close registration.', 'error') }
}

/* ---------------- Copy / QR ---------------- */
const copyLink = async (link) => {
  const value = link || registrationLink.value
  try { await navigator.clipboard?.writeText(value); copied.value = true; setTimeout(() => { copied.value = false }, 1800) } catch { showToast('Copy failed — select the link manually.', 'error') }
}
const downloadQr = () => {
  const canvas = document.querySelector('.fb-qr-frame canvas, .fb-qr-frame img')
  if (!canvas) return
  const anchor = document.createElement('a')
  anchor.download = `${eventName.value || 'event'}-registration-qr.png`
  anchor.href = canvas.tagName === 'CANVAS' ? canvas.toDataURL('image/png') : canvas.src
  anchor.click()
}

/* ---------------- Settings helpers ---------------- */
const statusOptions = ['draft', 'published', 'closed', 'archived'].map((value) => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))
const applyStatus = async () => {
  const value = settings.status
  if (value === 'published' && !isPublished.value) { showToast('Use Publish to make the form live.', 'error'); settings.status = eventStatus.value === 'open' ? 'published' : eventStatus.value; return }
  if (value === 'closed') { await closeRegistration(); return }
  eventStatus.value = value === 'published' ? eventStatus.value : value
  pushHistory(); touch()
}

/* ---------------- Keyboard shortcuts ---------------- */
const onKeydown = (event) => {
  if (previewOpen.value) return
  const meta = event.metaKey || event.ctrlKey
  if (meta && event.key.toLowerCase() === 'z' && !event.shiftKey) { event.preventDefault(); undo() }
  else if (meta && (event.key.toLowerCase() === 'y' || (event.key.toLowerCase() === 'z' && event.shiftKey))) { event.preventDefault(); redo() }
  else if (meta && event.key.toLowerCase() === 's') { event.preventDefault(); saveNow().then((ok) => ok && showToast('Draft saved')) }
  else if (event.key === 'Escape') { settingsOpen.value = false; addPaletteOpen.value = false; stepEditOpen.value = false; resetDrag() }
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
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeUnload)
})

const iconPath = (name) => FIELD_ICONS[name] || FIELD_ICONS.text
</script>

<template>
  <div class="fb-shell" :class="{ 'is-previewing': previewOpen }">
    <!-- ============ Loading / error / not found ============ -->
    <div v-if="loading" class="fb-state">
      <span class="fb-spinner" aria-hidden="true"></span>
      <p>Loading form builder…</p>
    </div>

    <div v-else-if="notFound" class="fb-state">
      <div class="fb-state-icon">?</div>
      <h2>Event not found</h2>
      <p>This event may have been removed, or the link is out of date.</p>
      <RouterLink class="fb-btn fb-btn--primary" to="/admin/events">Back to events</RouterLink>
    </div>

    <div v-else-if="loadError && !fields.length" class="fb-state">
      <div class="fb-state-icon">!</div>
      <h2>Builder unavailable</h2>
      <p>{{ loadError }}</p>
      <button class="fb-btn fb-btn--primary" type="button" @click="load">Retry</button>
    </div>

    <!-- ============ Builder ============ -->
    <template v-else>
      <!-- Top header: breadcrumb + title + tabs + actions -->
      <header class="fb-header">
        <nav class="fb-breadcrumb" aria-label="Breadcrumb">
          <RouterLink to="/admin">Dashboard</RouterLink>
          <span>/</span>
          <RouterLink to="/admin/events">Events</RouterLink>
          <span>/</span>
          <span class="fb-crumb-event">{{ eventName }}</span>
          <span>/</span>
          <b aria-current="page">Form Builder</b>
        </nav>
        <div class="fb-header-row">
          <div class="fb-header-title">
            <h1>{{ formTitle || 'Event Registration Form' }}</h1>
            <small>
              {{ eventName }}
              <span class="fb-status-chip" :class="`is-${eventStatus}`">{{ statusLabel }}</span>
              <span v-if="hasRegistrations" class="fb-reg-badge">{{ registrationsCount }} registration{{ registrationsCount === 1 ? '' : 's' }}</span>
            </small>
          </div>

          <nav class="fb-tabs" role="tablist" aria-label="Builder sections">
            <button type="button" role="tab" :aria-selected="activeTab === 'builder'" :class="{ active: activeTab === 'builder' }" @click="activeTab = 'builder'">Form Builder</button>
            <button type="button" role="tab" :aria-selected="activeTab === 'actions'" :class="{ active: activeTab === 'actions' }" @click="activeTab = 'actions'">Actions</button>
            <button type="button" role="tab" :aria-selected="activeTab === 'settings'" :class="{ active: activeTab === 'settings' }" @click="activeTab = 'settings'">Form Settings</button>
          </nav>

          <div class="fb-header-actions">
            <span class="fb-save-state" :class="`is-${saveState}`">
              <template v-if="saveState === 'saving'">Saving…</template>
              <template v-else-if="dirty">Unsaved changes</template>
              <template v-else-if="saveState === 'error'">Save failed</template>
              <template v-else>{{ lastSavedLabel }}</template>
            </span>
            <button class="fb-icon-btn" type="button" title="Undo (Ctrl+Z)" :disabled="!canUndo" @click="undo">↶</button>
            <button class="fb-icon-btn" type="button" title="Redo (Ctrl+Y)" :disabled="!canRedo" @click="redo">↷</button>
            <button class="fb-btn fb-btn--ghost" type="button" @click="openPreview">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" /></svg>
              Preview
            </button>
            <button class="fb-btn fb-btn--primary" type="button" :disabled="saveState === 'saving'" @click="saveAndExit">Save &amp; Exit</button>
          </div>
        </div>
      </header>

      <!-- Structural edit warning -->
      <div v-if="activeTab === 'builder' && hasRegistrations && !structuralWarningDismissed" class="fb-banner fb-banner--warning">
        <div>
          <strong>This form already has {{ registrationsCount }} registration{{ registrationsCount === 1 ? '' : 's' }}.</strong>
          <p>Changing or deleting fields may affect existing registration data.</p>
        </div>
        <button class="fb-banner-dismiss" type="button" @click="structuralWarningDismissed = true">Dismiss</button>
      </div>

      <!-- ============ TAB: Form Builder ============ -->
      <div v-if="activeTab === 'builder'" class="fb-workspace">
        <!-- Left: field palette -->
        <aside class="fb-palette" :class="{ 'is-open': addPaletteOpen }" aria-label="Field types">
          <div class="fb-palette-head">
            <h2>Form Components</h2>
            <button class="fb-icon-btn fb-palette-close" type="button" aria-label="Close components" @click="addPaletteOpen = false">×</button>
          </div>
          <label class="fb-palette-search">
            <input v-model="paletteQuery" type="search" placeholder="Search components…" />
          </label>
          <div class="fb-palette-scroll">
            <section v-for="category in filteredPalette" :key="category.key" class="fb-palette-group">
              <h3>{{ category.label }}</h3>
              <div
                v-for="entry in category.types"
                :key="entry.type"
                class="fb-palette-item"
                draggable="true"
                role="button"
                tabindex="0"
                :title="`Drag into the form or click to add · ${entry.description}`"
                @dragstart="onPaletteDragStart($event, entry.type)"
                @click="addField(entry.type)"
                @keydown.enter.prevent="addField(entry.type)"
              >
                <span class="fb-palette-icon">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="iconPath(entry.icon)" /></svg>
                </span>
                <span class="fb-palette-copy">
                  <strong>{{ entry.label }}</strong>
                  <small>{{ entry.description }}</small>
                </span>
                <span class="fb-palette-add" aria-hidden="true">+</span>
              </div>
            </section>
            <p v-if="!filteredPalette.length" class="fb-palette-empty">No components match “{{ paletteQuery }}”.</p>
          </div>
        </aside>

        <!-- Center: canvas -->
        <section class="fb-canvas-col" aria-label="Form canvas">
          <div class="fb-canvas" @dragover.prevent @drop="onDropFromPalette">
            <!-- Form header card -->
            <div class="fb-form-header-card">
              <label class="fb-inline-label">
                <span>Form title</span>
                <input v-model="formTitle" type="text" placeholder="Event Registration Form" @input="touch" @change="pushHistory" />
              </label>
              <label class="fb-inline-label">
                <span>Description</span>
                <textarea v-model="formDescription" rows="2" placeholder="Register for this event by completing the form below." @input="touch" @change="pushHistory"></textarea>
              </label>
              <button class="fb-settings-link" type="button" @click="activeTab = 'settings'">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z" /></svg>
                Form settings
              </button>
            </div>

            <!-- Step tabs -->
            <div class="fb-steps-bar" @dragover="onDragOverStep(activeStepIndex, $event)" @drop.prevent="onDropOnStep(activeStepIndex)">
              <div class="fb-step-tabs" role="tablist" aria-label="Form steps">
                <button
                  v-for="(step, index) in steps"
                  :key="step.id"
                  type="button"
                  role="tab"
                  class="fb-step-tab"
                  :class="{ active: index === activeStepIndex, 'is-drop': dropVisible === null && dragVisible !== null && index === activeStepIndex }"
                  :aria-selected="index === activeStepIndex"
                  @click="selectStep(index)"
                  @dragover="onDragOverStep(index, $event)"
                  @drop.prevent="onDropOnStep(index)"
                >
                  {{ step.name }}
                  <small>{{ fields.filter((field) => Number(field.settings?.step ?? 0) === index).length }}</small>
                </button>
                <button class="fb-step-add" type="button" title="Add a new step" @click="addStep">+</button>
              </div>
              <button v-if="dragVisible !== null" class="fb-step-drop-hint" type="button" tabindex="-1">Drop here to move the field to “{{ steps[activeStepIndex]?.name }}”</button>
            </div>

            <!-- Step editor strip -->
            <div v-if="stepEditOpen" class="fb-step-editor">
              <label>
                <span>Step name</span>
                <input v-model="stepDraft.name" type="text" maxlength="60" @keyup.enter="saveStepName" />
              </label>
              <div class="fb-step-editor-actions">
                <button class="fb-btn fb-btn--ghost" type="button" :disabled="activeStepIndex === 0" title="Move step left" @click="moveStep(-1)">← Move</button>
                <button class="fb-btn fb-btn--ghost" type="button" :disabled="activeStepIndex === steps.length - 1" title="Move step right" @click="moveStep(1)">Move →</button>
                <button class="fb-btn fb-btn--danger" type="button" :disabled="steps.length <= 1" @click="requestDeleteStep">Delete step</button>
                <button class="fb-btn fb-btn--primary" type="button" @click="saveStepName">Save name</button>
              </div>
            </div>
            <button v-else class="fb-step-edit-toggle" type="button" @click="openStepEditor">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" /></svg>
              Edit “{{ steps[activeStepIndex]?.name }}”
            </button>

            <!-- Fields of the active step -->
            <TransitionGroup name="fb-list" tag="div" class="fb-fields">
              <article
                v-for="(field, index) in visibleFields"
                :key="field.id"
                class="fb-field-card"
                :class="[`is-w${field.settings?.width || '100'}`, { 'is-selected': field.id === selectedId, 'is-dragging': dragVisible === index, 'is-drop-before': dropVisible === index && dragVisible !== index, 'is-heading': field.type === 'heading' }]"
                draggable="true"
                @dragstart="onDragStart(index, $event)"
                @dragover="onDragOver(index, $event)"
                @dragend="resetDrag"
                @drop.stop="onDrop(index)"
                @click="selectField(field)"
              >
                <span class="fb-drag-handle" title="Drag to reorder" aria-hidden="true">⋮⋮</span>
                <span class="fb-type-icon" :class="`t-${field.type}`">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="iconPath(TYPE_META(field.type).icon)" /></svg>
                </span>
                <div class="fb-field-body">
                  <div class="fb-field-title-row">
                    <strong v-if="field.type === 'heading'" class="fb-heading-preview">{{ field.label }}</strong>
                    <strong v-else>{{ field.label }} <em v-if="field.required" class="fb-required" title="Required">*</em></strong>
                    <span class="fb-field-type-chip">{{ TYPE_META(field.type).label }}</span>
                    <span v-if="String(field.settings?.width || '100') !== '100' && field.type !== 'heading'" class="fb-width-chip">{{ widthLabel(field) }}</span>
                  </div>
                  <p v-if="field.description" class="fb-field-desc">{{ field.description }}</p>
                  <div class="fb-field-mock">
                    <template v-if="field.type === 'heading'"></template>
                    <select v-else-if="['select', 'country'].includes(field.type) && !field.settings?.multiple"><option>{{ field.placeholder || `Select ${field.label.toLowerCase()}` }} ▾</option></select>
                    <div v-else-if="field.type === 'select' && field.settings?.multiple" class="fb-mock-choices"><span v-for="option in (field.options || []).slice(0, 4)" :key="option" class="fb-mock-choice">▢ {{ option }}</span></div>
                    <div v-else-if="field.type === 'radio'" class="fb-mock-choices"><span v-for="option in (field.options || []).slice(0, 4)" :key="option" class="fb-mock-choice">◯ {{ option }}</span></div>
                    <div v-else-if="field.type === 'checkbox' && field.settings?.multiple" class="fb-mock-choices"><span v-for="option in (field.options || []).slice(0, 4)" :key="option" class="fb-mock-choice">▢ {{ option }}</span></div>
                    <div v-else-if="field.type === 'checkbox'" class="fb-mock-choices"><span class="fb-mock-choice">▢ {{ field.settings?.checkbox_text || field.label }}</span></div>
                    <div v-else-if="field.type === 'yesno'" class="fb-mock-choices"><span class="fb-mock-choice">◯ Yes</span><span class="fb-mock-choice">◯ No</span></div>
                    <div v-else-if="field.type === 'file'" class="fb-mock-file">⬆ Upload file</div>
                    <textarea v-else-if="field.type === 'textarea'" rows="2" disabled :placeholder="field.placeholder || 'Type your answer…'"></textarea>
                    <input v-else type="text" disabled :placeholder="field.placeholder || `Enter ${field.label.toLowerCase()}`" />
                  </div>
                </div>
                <div class="fb-field-actions" @click.stop>
                  <button class="fb-action-btn" type="button" :disabled="index === 0" title="Move up" @click="moveVisible(index, -1)">↑</button>
                  <button class="fb-action-btn" type="button" :disabled="index === visibleFields.length - 1" title="Move down" @click="moveVisible(index, 1)">↓</button>
                  <button class="fb-action-btn" type="button" :title="`Settings for ${field.label}`" @click="selectField(field)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z" /></svg>
                  </button>
                  <button class="fb-action-btn" type="button" :title="`Duplicate ${field.label}`" @click="duplicateField(field)">⧉</button>
                  <button class="fb-action-btn fb-action-btn--danger" type="button" :title="`Delete ${field.label}`" @click="requestDelete(field)">🗑</button>
                </div>
              </article>
            </TransitionGroup>

            <!-- Empty state -->
            <div v-if="!visibleFields.length" class="fb-empty">
              <div class="fb-empty-icon">⌘</div>
              <h3>Start building your form</h3>
              <p>Drag a field from the left panel or click a component to add it.</p>
              <button class="fb-btn fb-btn--primary" type="button" @click="openPalette">+ Add Field</button>
            </div>

            <!-- Add field button -->
            <div v-if="visibleFields.length" class="fb-add-row">
              <button class="fb-add-btn" type="button" @click="openPalette">+ Add Field</button>
            </div>
          </div>
        </section>

        <!-- Right: settings panel -->
        <aside class="fb-settings" :class="{ 'is-open': settingsOpen }" aria-label="Field settings">
          <div class="fb-settings-head">
            <h2>Field Settings</h2>
            <button class="fb-icon-btn" type="button" aria-label="Close settings" @click="settingsOpen = false">×</button>
          </div>

          <div v-if="!selectedField" class="fb-settings-empty">
            <div class="fb-settings-empty-icon">☝</div>
            <p>Select a field to edit its label, options, and validation.</p>
          </div>

          <div v-else class="fb-settings-scroll">
            <div class="fb-setting-group">
              <small class="fb-setting-label">Field type</small>
              <div class="fb-field-type-display">
                <span class="fb-type-icon" :class="`t-${selectedField.type}`"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="iconPath(TYPE_META(selectedField.type).icon)" /></svg></span>
                <strong>{{ TYPE_META(selectedField.type).label }}</strong>
              </div>
            </div>

            <div class="fb-setting-group">
              <label class="fb-setting-field">
                <span>Label <em>*</em></span>
                <input v-model="selectedField.label" type="text" @input="onLabelInput" @change="pushHistory" />
              </label>
              <label v-if="selectedField.type !== 'heading'" class="fb-setting-field">
                <span>Field name</span>
                <input v-model="selectedField.settings.field_name" type="text" placeholder="first_name" @input="touch" @change="pushHistory" />
                <small class="fb-setting-hint">Stored with each submission. Auto-generated from the label.</small>
              </label>
              <label v-if="selectedField.type !== 'heading'" class="fb-setting-field">
                <span>Description</span>
                <input v-model="selectedField.description" type="text" placeholder="Help text shown under the label" @input="touch" @change="pushHistory" />
              </label>
              <label v-if="!['select', 'radio', 'checkbox', 'yesno', 'file', 'heading', 'country'].includes(selectedField.type)" class="fb-setting-field">
                <span>Placeholder</span>
                <input v-model="selectedField.placeholder" type="text" :placeholder="`e.g. Enter ${selectedField.label.toLowerCase()}`" @input="touch" @change="pushHistory" />
              </label>
              <label v-if="!['select', 'radio', 'checkbox', 'yesno', 'file', 'heading', 'country'].includes(selectedField.type)" class="fb-setting-field">
                <span>Default value</span>
                <input v-if="selectedField.type !== 'textarea'" v-model="selectedField.settings.default_value" type="text" @input="touch" @change="pushHistory" />
                <textarea v-else v-model="selectedField.settings.default_value" rows="2" @input="touch" @change="pushHistory"></textarea>
              </label>
            </div>

            <!-- Layout -->
            <div v-if="selectedField.type !== 'heading'" class="fb-setting-group">
              <small class="fb-setting-label">Width</small>
              <div class="fb-width-options" role="group" aria-label="Field width">
                <button
                  v-for="option in FIELD_WIDTHS"
                  :key="option.value"
                  type="button"
                  :class="{ active: String(selectedField.settings?.width || '100') === option.value }"
                  @click="selectedField.settings.width = option.value; updateField()"
                >{{ option.label }}</button>
              </div>
              <label v-if="steps.length > 1" class="fb-setting-field fb-setting-field--step">
                <span>Step</span>
                <select v-model.number="selectedField.settings.step" @change="updateField()">
                  <option v-for="(step, index) in steps" :key="step.id" :value="index">{{ step.name }}</option>
                </select>
              </label>
            </div>

            <!-- Toggles -->
            <div v-if="selectedField.type !== 'heading'" class="fb-setting-group">
              <label class="fb-setting-toggle">
                <input type="checkbox" :checked="selectedField.required" @change="selectedField.required = $event.target.checked; updateField()" />
                <span>Required field</span>
                <small>Attendees must complete this field before submitting.</small>
              </label>
              <label class="fb-setting-toggle">
                <input type="checkbox" v-model="selectedField.settings.hide_label" @change="updateField()" />
                <span>Hide label</span>
                <small>Show only the input — useful for compact layouts.</small>
              </label>
            </div>

            <!-- Options editor -->
            <div v-if="OPTION_TYPES.includes(selectedField.type) || ['autocomplete', 'select'].includes(selectedField.type)" class="fb-setting-group">
              <small class="fb-setting-label">Options</small>
              <div v-for="(option, index) in selectedField.options" :key="index" class="fb-option-row">
                <input v-model="selectedField.options[index]" type="text" @input="touch" @change="pushHistory" />
                <button class="fb-option-remove" type="button" :disabled="selectedField.options.length <= 1" :aria-label="`Remove option ${option}`" @click="selectedField.options.splice(index, 1); updateField()">✕</button>
              </div>
              <button class="fb-add-option" type="button" @click="selectedField.options.push(`Option ${selectedField.options.length + 1}`); updateField()">+ Add Option</button>
            </div>

            <!-- Choice extras -->
            <div v-if="['select', 'checkbox'].includes(selectedField.type)" class="fb-setting-group">
              <label class="fb-setting-toggle">
                <input type="checkbox" v-model="selectedField.settings.multiple" @change="updateField()" />
                <span>Allow multiple selection</span>
                <small>{{ selectedField.type === 'select' ? 'Renders the dropdown as a checkbox list.' : 'Attendees can check several options.' }}</small>
              </label>
            </div>
            <div v-if="['checkbox', 'yesno'].includes(selectedField.type)" class="fb-setting-group">
              <label v-if="selectedField.type === 'checkbox' && !selectedField.settings?.multiple" class="fb-setting-field">
                <span>Checkbox text</span>
                <input v-model="selectedField.settings.checkbox_text" type="text" :placeholder="selectedField.label" @input="touch" @change="pushHistory" />
              </label>
              <label class="fb-setting-toggle">
                <input type="checkbox" v-model="selectedField.settings.default_checked" @change="updateField()" />
                <span>{{ selectedField.type === 'yesno' ? 'Default to Yes' : 'Checked by default' }}</span>
              </label>
            </div>

            <!-- Validation -->
            <div v-if="['text', 'textarea', 'number'].includes(selectedField.type)" class="fb-setting-group">
              <small class="fb-setting-label">Validation</small>
              <template v-if="['text', 'textarea'].includes(selectedField.type)">
                <div class="fb-setting-pair">
                  <label><span>Min length</span><input v-model.number="selectedField.settings.min_length" type="number" min="0" @input="touch" @change="pushHistory" /></label>
                  <label><span>Max length</span><input v-model.number="selectedField.settings.max_length" type="number" min="0" @input="touch" @change="pushHistory" /></label>
                </div>
              </template>
              <template v-else>
                <div class="fb-setting-pair">
                  <label><span>Min value</span><input v-model.number="selectedField.settings.min_value" type="number" @input="touch" @change="pushHistory" /></label>
                  <label><span>Max value</span><input v-model.number="selectedField.settings.max_value" type="number" @input="touch" @change="pushHistory" /></label>
                </div>
                <label class="fb-setting-toggle"><input v-model="selectedField.settings.integer_only" type="checkbox" @change="updateField()" /><span>Whole numbers only</span></label>
              </template>
            </div>

            <!-- File settings -->
            <div v-if="selectedField.type === 'file'" class="fb-setting-group">
              <small class="fb-setting-label">Accepted file types</small>
              <div class="fb-file-types">
                <label v-for="ext in ['pdf', 'jpg', 'png', 'doc', 'docx', 'zip']" :key="ext" class="fb-file-type">
                  <input v-model="selectedField.settings.allowed_types" type="checkbox" :value="ext" @change="updateField()" />
                  <span>{{ ext.toUpperCase() }}</span>
                </label>
                <label class="fb-setting-field"><span>Maximum file size (MB)</span><input v-model.number="selectedField.settings.max_size_mb" type="number" min="1" max="50" @input="touch" @change="pushHistory" /></label>
              </div>
            </div>
          </div>

          <div v-if="selectedField" class="fb-settings-footer">
            <button class="fb-btn fb-btn--ghost fb-btn--block" type="button" @click="duplicateField(selectedField)">Duplicate field</button>
            <button class="fb-btn fb-btn--danger fb-btn--block" type="button" @click="requestDelete(selectedField)">Delete field</button>
          </div>
        </aside>
      </div>

      <!-- ============ TAB: Actions ============ -->
      <div v-else-if="activeTab === 'actions'" class="fb-page">
        <section class="fb-page-grid">
          <article class="fb-panel">
            <header class="fb-panel-head">
              <div><p class="fb-panel-eyebrow">Publishing</p><h2>Form status</h2></div>
              <span class="fb-status-chip" :class="`is-${eventStatus}`">{{ statusLabel }}</span>
            </header>
            <div class="fb-panel-body">
              <p class="fb-panel-text">{{ isPublished ? 'This form is live. Attendees can register using the link or QR code.' : 'Publish the form to make it available to attendees.' }}</p>
              <div class="fb-panel-actions">
                <button v-if="!isPublished" class="fb-btn fb-btn--primary" type="button" :disabled="!canPublish" @click="openPublishModal">Publish form</button>
                <template v-else>
                  <button class="fb-btn fb-btn--ghost" type="button" @click="publishSuccess = true">Show QR &amp; link</button>
                  <button class="fb-btn fb-btn--danger" type="button" :disabled="eventStatus === 'closed'" @click="closeRegistration">Close registration</button>
                </template>
              </div>
            </div>
          </article>

          <article class="fb-panel">
            <header class="fb-panel-head">
              <div><p class="fb-panel-eyebrow">Share</p><h2>Registration link</h2></div>
            </header>
            <div class="fb-panel-body">
              <div class="fb-publish-link">
                <small>Registration link</small>
                <code>{{ registrationLink }}</code>
                <button class="fb-btn fb-btn--ghost" type="button" @click="copyLink()">{{ copied ? 'Copied!' : 'Copy Link' }}</button>
              </div>
              <div class="fb-panel-actions">
                <a class="fb-btn fb-btn--ghost" :href="registrationLink" target="_blank" rel="noreferrer">Open form ↗</a>
                <button class="fb-btn fb-btn--ghost" type="button" @click="downloadQr">Download QR</button>
              </div>
            </div>
          </article>

          <article class="fb-panel">
            <header class="fb-panel-head">
              <div><p class="fb-panel-eyebrow">Attendees</p><h2>Registrations</h2></div>
              <strong class="fb-panel-stat">{{ registrationsCount }}</strong>
            </header>
            <div class="fb-panel-body">
              <p class="fb-panel-text">{{ hasRegistrations ? 'Review submitted responses and export attendee data.' : 'No registrations yet. Share the form to start collecting responses.' }}</p>
              <div class="fb-panel-actions">
                <RouterLink class="fb-btn fb-btn--ghost" :to="`/admin/events/${eventId}/registrants`">View registrants</RouterLink>
                <RouterLink class="fb-btn fb-btn--ghost" :to="`/admin/events/${eventId}`">Event dashboard</RouterLink>
              </div>
            </div>
          </article>
        </section>
      </div>

      <!-- ============ TAB: Form Settings ============ -->
      <div v-else class="fb-page">
        <section class="fb-page-grid fb-page-grid--settings">
          <article class="fb-panel">
            <header class="fb-panel-head"><div><p class="fb-panel-eyebrow">Presentation</p><h2>Form header</h2></div></header>
            <div class="fb-panel-body">
              <label class="fb-setting-field"><span>Form title</span><input v-model="formTitle" type="text" @input="touch" @change="pushHistory" /></label>
              <label class="fb-setting-field"><span>Form description</span><textarea v-model="formDescription" rows="2" @input="touch" @change="pushHistory"></textarea></label>
            </div>
          </article>

          <article class="fb-panel">
            <header class="fb-panel-head"><div><p class="fb-panel-eyebrow">Access</p><h2>Registration settings</h2></div></header>
            <div class="fb-panel-body">
              <div class="fb-setting-pair">
                <label class="fb-setting-field"><span>Registration start</span><input v-model="settings.registration_start" type="datetime-local" @change="updateField" /></label>
                <label class="fb-setting-field"><span>Registration end</span><input v-model="settings.registration_end" type="datetime-local" @change="updateField" /></label>
              </div>
              <div class="fb-setting-pair">
                <label class="fb-setting-field"><span>Maximum participants</span><input v-model.number="settings.max_participants" type="number" min="1" @change="updateField" /></label>
                <label class="fb-setting-toggle"><input v-model="settings.multiple_registrations" type="checkbox" @change="updateField" /><span>Allow multiple registrations</span></label>
              </div>
              <label class="fb-setting-toggle"><input v-model="settings.allow_user_edit" type="checkbox" @change="updateField" /><span>Allow users to edit their registration</span><small>Users can update their information until the registration deadline.</small></label>
              <label class="fb-setting-toggle"><input v-model="settings.one_per_account" type="checkbox" @change="updateField" /><span>One registration per account</span></label>
            </div>
          </article>

          <article class="fb-panel">
            <header class="fb-panel-head"><div><p class="fb-panel-eyebrow">Submissions</p><h2>After submission</h2></div></header>
            <div class="fb-panel-body">
              <label class="fb-setting-field"><span>Success message</span><textarea v-model="settings.success_message" rows="2" @change="updateField"></textarea></label>
              <label class="fb-setting-field"><span>Redirect after submission (URL)</span><input v-model="settings.redirect_url" type="url" placeholder="https://…" @change="updateField" /></label>
              <label class="fb-setting-toggle"><input v-model="settings.confirmation_email" type="checkbox" @change="updateField" /><span>Send confirmation email</span></label>
            </div>
          </article>

          <article class="fb-panel">
            <header class="fb-panel-head"><div><p class="fb-panel-eyebrow">Status</p><h2>Form status</h2></div></header>
            <div class="fb-panel-body">
              <div class="fb-status-options">
                <label v-for="option in statusOptions" :key="option.value" class="fb-status-option">
                  <input v-model="settings.status" type="radio" name="fb-status" :value="option.value" @change="applyStatus" />
                  <span>{{ option.label }}</span>
                </label>
              </div>
            </div>
          </article>

          <article class="fb-panel">
            <header class="fb-panel-head"><div><p class="fb-panel-eyebrow">Privacy</p><h2>Consent &amp; privacy</h2></div></header>
            <div class="fb-panel-body">
              <label class="fb-setting-toggle"><input v-model="settings.show_privacy_policy" type="checkbox" @change="updateField" /><span>Show privacy policy note</span></label>
              <label class="fb-setting-toggle"><input v-model="settings.require_agreement" type="checkbox" @change="updateField" /><span>Require agreement checkbox</span></label>
              <label v-if="settings.require_agreement || settings.show_privacy_policy" class="fb-setting-field"><span>Terms and conditions URL</span><input v-model="settings.terms_url" type="url" placeholder="https://…" @change="updateField" /></label>
            </div>
          </article>
        </section>
        <footer class="fb-page-foot">
          <span class="fb-save-state" :class="`is-${saveState}`">{{ dirty ? 'Unsaved changes' : lastSavedLabel }}</span>
          <button class="fb-btn fb-btn--primary" type="button" :disabled="saveState === 'saving'" @click="saveNow().then((ok) => ok && showToast('Settings saved'))">Save settings</button>
        </footer>
      </div>

      <!-- Mobile: floating add button -->
      <button class="fb-fab" type="button" aria-label="Add field" @click="openPalette">+</button>

      <!-- Backdrop for mobile panels -->
      <div v-if="settingsOpen || addPaletteOpen" class="fb-backdrop" @click="settingsOpen = false; addPaletteOpen = false"></div>

      <!-- ============ Preview overlay ============ -->
      <div v-if="previewOpen" class="fb-preview-overlay">
        <header class="fb-preview-toolbar">
          <button class="fb-btn fb-btn--ghost" type="button" @click="closePreview">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
            Back to Builder
          </button>
          <strong>Preview — attendee view</strong>
          <div class="fb-device-switch">
            <button v-for="device in ['desktop', 'tablet', 'mobile']" :key="device" type="button" :class="{ active: previewDevice === device }" @click="previewDevice = device">{{ device.charAt(0).toUpperCase() + device.slice(1) }}</button>
          </div>
        </header>
        <div class="fb-preview-stage">
          <div class="fb-preview-frame" :class="`is-${previewDevice}`">
            <div class="fb-preview-browser"><span></span><span></span><span></span></div>
            <div class="fb-preview-page">
              <FormRenderer
                :fields="fields"
                :steps="steps"
                :active-step="previewStep"
                :form-title="formTitle"
                :form-description="formDescription"
                :event-name="eventName"
                :settings="settings"
                @step-change="previewStep = $event"
                @submit="() => showToast('This is a preview — nothing was submitted.')"
              />
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ============ Delete confirmation ============ -->
    <div v-if="deleteTarget" class="fb-modal-backdrop" @click.self="deleteTarget = null">
      <div class="fb-modal fb-modal--small" role="alertdialog" aria-modal="true" aria-label="Delete field">
        <h2>Delete “{{ deleteTarget.label }}”?</h2>
        <p>This field will be removed from the form. Attendees will no longer be asked for this information.</p>
        <footer class="fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="deleteTarget = null">Cancel</button>
          <button class="fb-btn fb-btn--danger" type="button" @click="confirmDelete">Delete</button>
        </footer>
      </div>
    </div>

    <!-- ============ Publish confirmation ============ -->
    <div v-if="publishModal" class="fb-modal-backdrop" @click.self="publishModal = false">
      <div class="fb-modal" role="dialog" aria-modal="true" aria-label="Publish form">
        <header class="fb-modal-head">
          <div>
            <p class="fb-modal-eyebrow">Ready to go live?</p>
            <h2>Publish Registration Form?</h2>
          </div>
          <button class="fb-icon-btn" type="button" aria-label="Close" @click="publishModal = false">×</button>
        </header>
        <p class="fb-modal-body fb-modal-body--text">Your form will become available to users after publishing.</p>
        <div class="fb-modal-body">
          <div class="fb-publish-link">
            <small>Registration link</small>
            <code>{{ registrationLink }}</code>
            <button class="fb-btn fb-btn--ghost" type="button" @click="copyLink()">{{ copied ? 'Copied!' : 'Copy Link' }}</button>
          </div>
          <div class="fb-publish-qr">
            <div class="fb-qr-frame"><QRCodeDisplay :value="registrationLink" /></div>
            <button class="fb-btn fb-btn--ghost" type="button" @click="downloadQr">Download QR</button>
          </div>
        </div>
        <footer class="fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="publishModal = false">Cancel</button>
          <button class="fb-btn fb-btn--primary" type="button" @click="confirmPublish">Publish Form</button>
        </footer>
      </div>
    </div>

    <!-- ============ Publish success ============ -->
    <div v-if="publishSuccess" class="fb-modal-backdrop" @click.self="publishSuccess = false">
      <div class="fb-modal" role="dialog" aria-modal="true" aria-label="Form published">
        <div class="fb-publish-hero">
          <span class="fb-publish-check">✓</span>
          <p class="fb-modal-eyebrow">Success</p>
          <h2>Form Published Successfully</h2>
        </div>
        <div class="fb-modal-body">
          <div class="fb-publish-link">
            <small>Registration link</small>
            <code>{{ registrationLink }}</code>
            <button class="fb-btn fb-btn--ghost" type="button" @click="copyLink(publishedLink)">{{ copied ? 'Copied!' : 'Copy Link' }}</button>
          </div>
          <div class="fb-publish-qr">
            <div class="fb-qr-frame"><QRCodeDisplay :value="registrationLink" /></div>
            <button class="fb-btn fb-btn--ghost" type="button" @click="downloadQr">Download QR</button>
          </div>
        </div>
        <footer class="fb-modal-foot">
          <button class="fb-btn fb-btn--ghost" type="button" @click="publishSuccess = false">Close</button>
          <a class="fb-btn fb-btn--primary" :href="registrationLink" target="_blank" rel="noreferrer">View Form ↗</a>
        </footer>
      </div>
    </div>
  </div>

  <!-- ============ Toast ============ -->
  <Transition name="fb-toast">
    <div v-if="toast.show" class="fb-toast" :class="`is-${toast.kind}`" role="status">{{ toast.message }}</div>
  </Transition>
</template>
