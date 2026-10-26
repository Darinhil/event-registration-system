<script setup>
/**
 * FormRenderer — renders a registration form exactly as the admin built it.
 * Shared by the builder's preview mode, the public registration flow and the
 * "edit registration" page. Emits `submit` with a { fieldId: value } map
 * (files as real File objects).
 *
 * Supports two modes:
 *  - `fields` only  → single-page form (backwards compatible)
 *  - `fields` + `steps` → multi-step form; renderStep controls which step shows
 */
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { COUNTRIES } from '../utils/formFieldTypes'
import { validateFields } from '../utils/formValidation'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  steps: { type: Array, default: () => [] },
  activeStep: { type: Number, default: 0 },
  formTitle: { type: String, default: 'Event Registration Form' },
  formDescription: { type: String, default: '' },
  eventBanner: { type: String, default: '' },
  eventName: { type: String, default: '' },
  settings: { type: Object, default: () => ({}) },
  initialValues: { type: Object, default: () => ({}) },
  serverErrors: { type: Object, default: () => ({}) },
  submitLabel: { type: String, default: 'Submit registration' },
  disabled: { type: Boolean, default: false },
  // While the parent is sending the form: buttons are locked and show progress.
  submitting: { type: Boolean, default: false },
  submittingLabel: { type: String, default: 'Submitting…' },
  hideHeader: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'step-change'])
const uid = `fr${Math.random().toString(36).slice(2, 8)}`
const formEl = ref(null)

/* ---------------- Step logic ---------------- */
const hasSteps = computed(() => props.steps.length > 1)
const currentStep = computed(() => Math.min(Math.max(props.activeStep, 0), props.steps.length - 1))
const stepFields = computed(() =>
  hasSteps.value ? props.fields.filter((field) => Number(field.settings?.step ?? 0) === currentStep.value) : props.fields
)
const nextStep = () => emit('step-change', Math.min(currentStep.value + 1, props.steps.length - 1))
const prevStep = () => { stepErrors.value = {}; summaryVisible.value = false; emit('step-change', Math.max(currentStep.value - 1, 0)) }
const lastStep = computed(() => !hasSteps.value || currentStep.value === props.steps.length - 1)
const inputFields = computed(() => stepFields.value.filter((field) => field.type !== 'heading'))
const hasRequired = computed(() => inputFields.value.some((field) => field.required))

/* ---------------- Values ---------------- */
const seedValue = (field) => {
  const s = field.settings || {}
  if (field.type === 'checkbox') {
    if (s.multiple && Array.isArray(s.default_value)) return [...s.default_value]
    if (!s.multiple && s.default_checked) return [true]
    return []
  }
  if (field.type === 'select' && s.multiple) return Array.isArray(s.default_value) ? [...s.default_value] : []
  if (field.type === 'yesno') return s.default_checked ? 'Yes' : ''
  return s.default_value ?? ''
}
const initValues = (forceInitialValues = false) => {
  for (const field of props.fields) {
    if (!forceInitialValues && values[field.id] !== undefined) continue
    const initial = props.initialValues?.[field.id]
    values[field.id] = initial !== undefined && initial !== null ? initial : seedValue(field)
  }
}
const values = reactive({})
// Fields and saved answers are loaded independently on the edit page. Seed
// defaults first, then hydrate once the API-provided initial values arrive.
// Without the second pass, blank defaults prevented saved answers from showing.
watch(() => props.fields, () => initValues(false), { immediate: true })
watch(() => props.initialValues, () => initValues(true), { immediate: true })

const clearError = (field) => {
  if (stepErrors.value[field.id]) delete stepErrors.value[field.id]
  dismissedServer.value.add(String(field.id))
}
const setValue = (field, value) => { values[field.id] = value; clearError(field) }
const toggleArrayValue = (field, option) => {
  const current = Array.isArray(values[field.id]) ? [...values[field.id]] : []
  const index = current.indexOf(option)
  if (index >= 0) current.splice(index, 1)
  else current.push(option)
  values[field.id] = current
  clearError(field)
}
const onFileChange = (field, event) => { values[field.id] = event.target.files?.[0] || null; clearError(field) }

/* ---------------- Validation display ---------------- */
const stepErrors = ref({})
const summaryVisible = ref(false)
const dismissedServer = ref(new Set())
const agreement = ref(false)
const agreementError = ref('')
// Server keys look like "form_data.12" or "form_data.12.0" (one option of a multi-choice).
const serverErrorFor = (field) => {
  if (dismissedServer.value.has(String(field.id))) return ''
  const prefix = `form_data.${field.id}`
  const key = Object.keys(props.serverErrors || {}).find((name) => name === prefix || name.startsWith(`${prefix}.`))
  const message = key ? props.serverErrors[key] : ''
  return Array.isArray(message) ? message[0] : message
}
const errorFor = (field) => stepErrors.value[field.id] || serverErrorFor(field) || ''
const errorList = computed(() => inputFields.value.filter((field) => errorFor(field)).map((field) => ({ field, message: errorFor(field) })))
watch(() => props.serverErrors, () => {
  dismissedServer.value = new Set()
  if (errorList.value.length) focusFirstError()
})

const controlId = (field) => `${uid}-f${field.id}`
const describedBy = (field) => [field.description && `${controlId(field)}-help`, errorFor(field) && `${controlId(field)}-error`].filter(Boolean).join(' ') || undefined
const focusField = (field) => {
  const target = document.getElementById(controlId(field)) || formEl.value?.querySelector(`[data-field="${field.id}"] input, [data-field="${field.id}"] select, [data-field="${field.id}"] textarea`)
  target?.focus()
  target?.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
}
const focusFirstError = async () => {
  await nextTick()
  const summary = formEl.value?.querySelector('.fr-error-summary')
  if (summary && errorList.value.length > 1) { summary.focus(); summary.scrollIntoView({ block: 'center' }) }
  else if (errorList.value[0]) focusField(errorList.value[0].field)
}

/** Guards a step: validate its fields, then advance/submit when clean. */
const proceed = () => {
  if (props.disabled || props.submitting) return
  agreementError.value = props.settings.require_agreement && lastStep.value && !agreement.value
    ? 'Please agree to the terms and conditions to continue.'
    : ''
  stepErrors.value = validateFields(stepFields.value, values)
  if (agreementError.value || Object.keys(stepErrors.value).length) {
    summaryVisible.value = true
    focusFirstError()
    return
  }
  stepErrors.value = {}
  summaryVisible.value = false
  lastStep.value ? submit() : nextStep()
}
const submit = () => emit('submit', { ...values })

/* ---------------- Input hints ---------------- */
const inputType = (type) => ({ email: 'email', phone: 'tel', number: 'number', date: 'date', time: 'time', url: 'url' }[type] || 'text')
const inputMode = (type) => ({ email: 'email', phone: 'tel', number: 'decimal', url: 'url' }[type] || undefined)
const placeholderFor = (field) => field.placeholder || ({ email: 'name@example.com', phone: 'e.g. 012 345 678', url: 'https://' }[field.type] || '')
// Browser autofill speeds up the form; derive the hint from the field type or its (English) label.
const autoCompleteHint = (field) => {
  if (field.type === 'email') return 'email'
  if (field.type === 'phone') return 'tel'
  if (field.type === 'url') return 'url'
  const label = String(field.label || '').toLowerCase()
  if (/full name|^name$|\/name/.test(label)) return 'name'
  if (/organi[sz]ation|institution|company/.test(label)) return 'organization'
  if (/position|job title/.test(label)) return 'organization-title'
  if (/address/.test(label)) return 'street-address'
  return 'off'
}
const multiSelect = (field) => Boolean(field.settings?.multiple) && field.type === 'select'
const isGroup = (field) => ['radio', 'yesno'].includes(field.type) || multiSelect(field) || (field.type === 'checkbox' && field.settings?.multiple)
const fileLabel = (value) => (value instanceof File ? value.name : String(value || '').split(/[\\/]/).pop())
const checkedState = (field) => {
  const value = values[field.id]
  return value === true || value === 'true' || (Array.isArray(value) && value[0] === true)
}
</script>

<template>
  <form ref="formEl" class="fr-form" novalidate :aria-busy="submitting || undefined" @submit.prevent="proceed">
    <template v-if="!hideHeader">
      <header v-if="eventBanner || eventName" class="fr-event">
        <img v-if="eventBanner" class="fr-event-banner" :src="eventBanner" alt="" loading="lazy" decoding="async" />
        <div class="fr-event-copy">
          <h2>{{ eventName || formTitle }}</h2>
          <p v-if="formDescription">{{ formDescription }}</p>
        </div>
      </header>

      <div class="fr-header">
        <h1>{{ formTitle || 'Registration Form' }}</h1>
        <p v-if="formDescription">{{ formDescription }}</p>
      </div>
    </template>

    <!-- Step progress -->
    <ol v-if="hasSteps" class="fr-steps" aria-label="Form progress">
      <li
        v-for="(step, index) in steps"
        :key="step.id"
        :class="{ 'is-active': index === currentStep, 'is-done': index < currentStep }"
        :aria-current="index === currentStep ? 'step' : undefined"
      >
        <span class="fr-step-dot" aria-hidden="true">{{ index < currentStep ? '✓' : index + 1 }}</span>
        <span class="fr-step-name">{{ step.name }}<span class="sr-only">{{ index < currentStep ? ' (completed)' : '' }}</span></span>
      </li>
    </ol>

    <p v-if="hasRequired" class="fr-legend">Fields marked <span aria-hidden="true">*</span><span class="sr-only">with an asterisk</span> are required.</p>

    <div v-if="summaryVisible && errorList.length > 1" class="fr-error-summary" role="alert" tabindex="-1">
      <strong>Please fix {{ errorList.length }} {{ errorList.length === 1 ? 'answer' : 'answers' }} before continuing</strong>
      <ul><li v-for="item in errorList" :key="item.field.id"><a :href="`#${controlId(item.field)}`" @click.prevent="focusField(item.field)">{{ item.message }}</a></li></ul>
    </div>

    <div v-if="!fields.length" class="fr-empty">
      <strong>This form has no fields yet</strong>
      <p>Check back soon — the organizer is still setting it up.</p>
    </div>

    <div v-else-if="hasSteps && !stepFields.length" class="fr-empty">
      <strong>Nothing to fill in on this step</strong>
      <p>Continue to the next step.</p>
    </div>

    <div v-else class="fr-fields">
      <div
        v-for="field in stepFields"
        :key="field.id"
        class="fr-field"
        :data-field="field.id"
        :class="[`fr-field--${field.type}`, `fr-w${field.settings?.width || '100'}`, { 'fr-field--invalid': errorFor(field) }]"
      >
        <template v-if="field.type === 'heading'">
          <h3 class="fr-heading">{{ field.label }}</h3>
          <p v-if="field.description" class="fr-field-help">{{ field.description }}</p>
        </template>

        <!-- Option groups: a fieldset/legend so screen readers announce the question once. -->
        <fieldset v-else-if="isGroup(field)" class="fr-group" :aria-describedby="describedBy(field)" :aria-invalid="Boolean(errorFor(field)) || undefined">
          <legend class="fr-label-text" :class="{ 'sr-only': field.settings?.hide_label }">
            {{ field.label }}
            <em v-if="field.required" class="fr-required" aria-hidden="true">*</em><span v-if="field.required" class="sr-only"> (required)</span>
            <span v-else class="fr-optional">Optional</span>
          </legend>
          <small v-if="field.description" :id="`${controlId(field)}-help`" class="fr-field-help">{{ field.description }}</small>
          <p v-if="multiSelect(field) || (field.type === 'checkbox' && field.settings?.multiple)" class="fr-multi-hint">Select all that apply</p>

          <div v-if="field.type === 'radio'" class="fr-choices">
            <label v-for="(option, index) in field.options || []" :key="option" class="fr-choice">
              <input :id="index === 0 ? controlId(field) : undefined" type="radio" :name="`${uid}-${field.id}`" :value="option" :checked="values[field.id] === option" @change="setValue(field, option)" />
              <span>{{ option }}</span>
            </label>
          </div>
          <div v-else-if="field.type === 'yesno'" class="fr-choices fr-choices--inline">
            <label v-for="(option, index) in ['Yes', 'No']" :key="option" class="fr-choice fr-choice--pill">
              <input :id="index === 0 ? controlId(field) : undefined" type="radio" :name="`${uid}-${field.id}`" :value="option" :checked="values[field.id] === option" @change="setValue(field, option)" />
              <span>{{ option }}</span>
            </label>
          </div>
          <div v-else class="fr-choices fr-choices--grid">
            <label v-for="(option, index) in field.options || []" :key="option" class="fr-choice">
              <input :id="index === 0 ? controlId(field) : undefined" type="checkbox" :checked="(values[field.id] || []).includes(option)" @change="toggleArrayValue(field, option)" />
              <span>{{ option }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Single checkbox (e.g. consent) -->
        <div v-else-if="field.type === 'checkbox'" class="fr-group">
          <small v-if="field.description" :id="`${controlId(field)}-help`" class="fr-field-help">{{ field.description }}</small>
          <label class="fr-choice fr-choice--single">
            <input
              :id="controlId(field)"
              type="checkbox"
              :name="field.settings?.field_name || undefined"
              :checked="checkedState(field)"
              :aria-required="field.required || undefined"
              :aria-invalid="Boolean(errorFor(field)) || undefined"
              :aria-describedby="describedBy(field)"
              @change="setValue(field, $event.target.checked)"
            />
            <span>{{ field.settings?.checkbox_text || field.label }}<em v-if="field.required" class="fr-required" aria-hidden="true"> *</em><span v-if="field.required" class="sr-only"> (required)</span></span>
          </label>
        </div>

        <template v-else>
          <label class="fr-label" :for="controlId(field)">
            <span class="fr-label-text" :class="{ 'sr-only': field.settings?.hide_label }">
              {{ field.label }}
              <em v-if="field.required" class="fr-required" aria-hidden="true">*</em><span v-if="field.required" class="sr-only"> (required)</span>
              <span v-else class="fr-optional">Optional</span>
            </span>
          </label>
          <small v-if="field.description" :id="`${controlId(field)}-help`" class="fr-field-help">{{ field.description }}</small>

          <div v-if="field.type === 'select'" class="fr-select-wrap">
            <select
              :id="controlId(field)"
              :name="field.settings?.field_name || undefined"
              :value="values[field.id] ?? ''"
              :aria-required="field.required || undefined"
              :aria-invalid="Boolean(errorFor(field)) || undefined"
              :aria-describedby="describedBy(field)"
              @change="setValue(field, $event.target.value)"
            >
              <option value="" disabled>{{ field.placeholder || 'Choose an option' }}</option>
              <option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>

          <div v-else-if="field.type === 'country'" class="fr-select-wrap">
            <select :id="controlId(field)" :name="field.settings?.field_name || undefined" autocomplete="country-name" :value="values[field.id] ?? ''" :aria-required="field.required || undefined" :aria-invalid="Boolean(errorFor(field)) || undefined" :aria-describedby="describedBy(field)" @change="setValue(field, $event.target.value)">
              <option value="" disabled>Choose a country</option>
              <option v-for="country in COUNTRIES" :key="country" :value="country">{{ country }}</option>
            </select>
          </div>

          <div v-else-if="field.type === 'file'" class="fr-file">
            <input
              :id="controlId(field)"
              type="file"
              :accept="(field.settings?.allowed_types || []).map((ext) => `.${ext}`).join(',')"
              :aria-required="field.required || undefined"
              :aria-invalid="Boolean(errorFor(field)) || undefined"
              :aria-describedby="[describedBy(field), `${controlId(field)}-limits`].filter(Boolean).join(' ')"
              @change="onFileChange(field, $event)"
            />
            <p v-if="values[field.id]" class="fr-file-name">Selected: {{ fileLabel(values[field.id]) }}</p>
            <p :id="`${controlId(field)}-limits`" class="fr-file-help">
              {{ (field.settings?.allowed_types || ['pdf', 'jpg', 'png']).join(', ').toUpperCase() }}
              · up to {{ field.settings?.max_size_mb || 5 }} MB
            </p>
          </div>

          <textarea
            v-else-if="field.type === 'textarea'"
            :id="controlId(field)"
            rows="4"
            :name="field.settings?.field_name || undefined"
            :value="values[field.id] ?? ''"
            :placeholder="placeholderFor(field)"
            :maxlength="field.settings?.max_length || undefined"
            :aria-required="field.required || undefined"
            :aria-invalid="Boolean(errorFor(field)) || undefined"
            :aria-describedby="describedBy(field)"
            @input="setValue(field, $event.target.value)"
          ></textarea>

          <template v-else-if="field.type === 'autocomplete'">
            <input
              :id="controlId(field)"
              type="text"
              :name="field.settings?.field_name || undefined"
              :value="values[field.id] ?? ''"
              :placeholder="placeholderFor(field)"
              :list="`${uid}-datalist-${field.id}`"
              :aria-required="field.required || undefined"
              :aria-invalid="Boolean(errorFor(field)) || undefined"
              :aria-describedby="describedBy(field)"
              @input="setValue(field, $event.target.value)"
            />
            <datalist :id="`${uid}-datalist-${field.id}`">
              <option v-for="option in field.options || []" :key="option" :value="option" />
            </datalist>
          </template>

          <input
            v-else
            :id="controlId(field)"
            :type="inputType(field.type)"
            :inputmode="inputMode(field.type)"
            :name="field.settings?.field_name || undefined"
            :autocomplete="autoCompleteHint(field)"
            :value="values[field.id] ?? ''"
            :placeholder="placeholderFor(field)"
            :min="field.type === 'number' && field.settings?.min_value != null ? field.settings.min_value : undefined"
            :max="field.type === 'number' && field.settings?.max_value != null ? field.settings.max_value : undefined"
            :maxlength="field.settings?.max_length || undefined"
            :spellcheck="['email', 'url', 'phone'].includes(field.type) ? 'false' : undefined"
            :aria-required="field.required || undefined"
            :aria-invalid="Boolean(errorFor(field)) || undefined"
            :aria-describedby="describedBy(field)"
            @input="setValue(field, $event.target.value)"
          />
        </template>
        <p v-if="errorFor(field)" :id="`${controlId(field)}-error`" class="fr-field-error"><span aria-hidden="true">!</span>{{ errorFor(field) }}</p>
      </div>
    </div>

    <div v-if="settings.require_agreement && lastStep" class="fr-agreement-block">
      <label class="fr-agreement">
        <input v-model="agreement" type="checkbox" :aria-invalid="Boolean(agreementError) || undefined" :aria-describedby="agreementError ? `${uid}-agreement-error` : undefined" />
        <span>I agree to the terms and conditions{{ settings.terms_url ? '' : ' of this event' }}.</span>
      </label>
      <p v-if="agreementError" :id="`${uid}-agreement-error`" class="fr-field-error"><span aria-hidden="true">!</span>{{ agreementError }}</p>
    </div>

    <div class="fr-nav" :class="{ 'fr-nav--single': !hasSteps || currentStep === 0 }">
      <button v-if="hasSteps && currentStep > 0" class="fr-back" type="button" :disabled="disabled || submitting" @click="prevStep">← Back</button>
      <button v-if="!lastStep" class="fr-submit" type="submit" :disabled="disabled || submitting">Continue →</button>
      <button v-else class="fr-submit" type="submit" :disabled="disabled || submitting">
        <span v-if="submitting" class="fr-spinner" aria-hidden="true"></span>{{ submitting ? submittingLabel : submitLabel }}
      </button>
    </div>

    <p v-if="settings.show_privacy_policy" class="fr-privacy">
      Your information will only be used to manage your attendance at this event.
    </p>
  </form>
</template>
