<script setup>
/**
 * FormRenderer — renders a registration form exactly as attendees see it.
 * Shared by the builder's preview mode and the public registration flow.
 * Emits `submit` with a { fieldId: value } map; never validates by itself.
 *
 * Supports two modes:
 *  - `fields` only  → single-page form (backwards compatible)
 *  - `fields` + `steps` → multi-step form; renderStep controls which step shows
 */
import { computed, reactive, watch } from 'vue'
import { TYPE_META, COUNTRIES } from '../utils/formFieldTypes'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  steps: { type: Array, default: () => [] },
  activeStep: { type: Number, default: 0 },
  formTitle: { type: String, default: 'Event Registration Form' },
  formDescription: { type: String, default: '' },
  eventBanner: { type: String, default: '' },
  eventName: { type: String, default: '' },
  settings: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['submit', 'step-change'])

/* ---------------- Step logic ---------------- */
const hasSteps = computed(() => props.steps.length > 1)
const currentStep = computed(() => Math.min(Math.max(props.activeStep, 0), props.steps.length - 1))
const stepFields = computed(() =>
  hasSteps.value ? props.fields.filter((field) => Number(field.settings?.step ?? 0) === currentStep.value) : props.fields
)
const stepProgress = computed(() => (hasSteps.value ? `${currentStep.value + 1} of ${props.steps.length}` : ''))
const nextStep = () => emit('step-change', Math.min(currentStep.value + 1, props.steps.length - 1))
const prevStep = () => emit('step-change', Math.max(currentStep.value - 1, 0))
const lastStep = computed(() => !hasSteps.value || currentStep.value === props.steps.length - 1)

/* ---------------- Values ---------------- */
const seedValue = (field) => {
  const s = field.settings || {}
  if (field.type === 'checkbox') {
    if (s.multiple && Array.isArray(s.default_value)) return [...s.default_value]
    if (!s.multiple && s.default_checked) return [true]
    return []
  }
  if (field.type === 'yesno') return s.default_checked ? 'Yes' : ''
  return s.default_value ?? ''
}
const initValues = () => {
  for (const field of props.fields) if (values[field.id] === undefined) values[field.id] = seedValue(field)
}
const values = reactive({})
watch(() => props.fields, initValues, { immediate: true })

const setValue = (field, value) => { values[field.id] = value }
const toggleArrayValue = (field, option) => {
  const current = Array.isArray(values[field.id]) ? [...values[field.id]] : []
  const index = current.indexOf(option)
  if (index >= 0) current.splice(index, 1)
  else current.push(option)
  values[field.id] = current
}
const onFileChange = (field, event) => { values[field.id] = event.target.files?.[0]?.name || '' }
const submit = () => emit('submit', { ...values })
const inputType = (type) => ({ email: 'email', phone: 'tel', number: 'number', date: 'date', time: 'time', url: 'url' }[type] || 'text')
const placeholderFor = (field) => field.placeholder || `Enter ${field.label.toLowerCase()}`
const autoCompleteHint = (field) => ({
  email: 'email', phone: 'tel', url: 'url',
  name: 'name', text: 'off',
}[field.type] || 'off')
const multiSelect = (field) => Boolean(field.settings?.multiple) && field.type === 'select'
</script>

<template>
  <form class="fr-form" @submit.prevent="submit">
    <header v-if="eventBanner || eventName" class="fr-event">
      <img v-if="eventBanner" class="fr-event-banner" :src="eventBanner" alt="" />
      <div class="fr-event-copy">
        <h2>{{ eventName || formTitle }}</h2>
        <p v-if="formDescription">{{ formDescription }}</p>
      </div>
    </header>

    <div class="fr-header">
      <h1>{{ formTitle || 'Registration Form' }}</h1>
      <p v-if="formDescription">{{ formDescription }}</p>
    </div>

    <!-- Step progress -->
    <ol v-if="hasSteps" class="fr-steps" aria-label="Form progress">
      <li
        v-for="(step, index) in steps"
        :key="step.id"
        :class="{ 'is-active': index === currentStep, 'is-done': index < currentStep }"
        :aria-current="index === currentStep ? 'step' : undefined"
      >
        <span class="fr-step-dot">{{ index < currentStep ? '✓' : index + 1 }}</span>
        <span class="fr-step-name">{{ step.name }}</span>
      </li>
    </ol>

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
        :class="[`fr-field--${field.type}`, `fr-w${field.settings?.width || '100'}`]"
      >
        <template v-if="field.type === 'heading'">
          <h3 class="fr-heading">{{ field.label }}</h3>
          <p v-if="field.description" class="fr-field-help">{{ field.description }}</p>
        </template>

        <label v-else class="fr-label">
          <span v-if="!field.settings?.hide_label" class="fr-label-text">
            {{ field.label }}
            <em v-if="field.required" class="fr-required" title="Required">*</em>
          </span>
          <small v-if="field.description && !field.settings?.hide_label" class="fr-field-help">{{ field.description }}</small>

          <!-- Single-select dropdown -->
          <div v-if="field.type === 'select' && !multiSelect(field)" class="fr-select-wrap">
            <select
              :name="field.settings?.field_name || undefined"
              :value="values[field.id] ?? ''"
              @change="setValue(field, $event.target.value)"
            >
              <option value="" disabled>{{ field.placeholder || `Select ${field.label.toLowerCase()}` }}</option>
              <option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>

          <!-- Multi-select dropdown -->
          <template v-else-if="field.type === 'select' && multiSelect(field)">
            <p v-if="!field.settings?.hide_label" class="fr-multi-hint">Select all that apply</p>
            <div class="fr-choices fr-choices--grid">
              <label v-for="option in field.options || []" :key="option" class="fr-choice">
                <input
                  type="checkbox"
                  :name="field.settings?.field_name || undefined"
                  :checked="(values[field.id] || []).includes(option)"
                  @change="toggleArrayValue(field, option)"
                />
                <span>{{ option }}</span>
              </label>
            </div>
          </template>

          <select v-else-if="field.type === 'country'" :name="field.settings?.field_name || undefined" :value="values[field.id] ?? ''" @change="setValue(field, $event.target.value)">
            <option value="" disabled>Select country</option>
            <option v-for="country in COUNTRIES" :key="country" :value="country">{{ country }}</option>
          </select>

          <div v-else-if="field.type === 'radio'" class="fr-choices" role="radiogroup" :aria-label="field.label">
            <label v-for="option in field.options || []" :key="option" class="fr-choice">
              <input type="radio" :name="`fr-${field.id}`" :value="option" :checked="values[field.id] === option" @change="setValue(field, option)" />
              <span>{{ option }}</span>
            </label>
          </div>

          <div v-else-if="field.type === 'checkbox'" class="fr-choices" :class="{ 'fr-choices--grid': field.settings?.multiple }">
            <template v-if="field.settings?.multiple">
              <label v-for="option in field.options || []" :key="option" class="fr-choice">
                <input type="checkbox" :checked="(values[field.id] || []).includes(option)" @change="toggleArrayValue(field, option)" />
                <span>{{ option }}</span>
              </label>
            </template>
            <template v-else>
              <label class="fr-choice fr-choice--single">
                <input type="checkbox" :name="field.settings?.field_name || undefined" :checked="Boolean(values[field.id]?.length)" @change="setValue(field, $event.target.checked ? [true] : [])" />
                <span>{{ field.settings?.checkbox_text || field.label }}</span>
              </label>
            </template>
          </div>

          <div v-else-if="field.type === 'yesno'" class="fr-choices fr-choices--inline">
            <label v-for="option in ['Yes', 'No']" :key="option" class="fr-choice fr-choice--pill">
              <input type="radio" :name="`fr-${field.id}`" :value="option" :checked="values[field.id] === option" @change="setValue(field, option)" />
              <span>{{ option }}</span>
            </label>
          </div>

          <div v-else-if="field.type === 'file'" class="fr-file">
            <input type="file" :accept="(field.settings?.allowed_types || []).map((ext) => `.${ext}`).join(',')" @change="onFileChange(field, $event)" />
            <p class="fr-file-help">
              {{ (field.settings?.allowed_types || ['pdf', 'jpg', 'png']).join(', ').toUpperCase() }}
              · up to {{ field.settings?.max_size_mb || 5 }} MB
            </p>
          </div>

          <textarea
            v-else-if="field.type === 'textarea'"
            rows="4"
            :name="field.settings?.field_name || undefined"
            :value="values[field.id] ?? ''"
            :placeholder="placeholderFor(field)"
            :maxlength="field.settings?.max_length || undefined"
            @input="setValue(field, $event.target.value)"
          ></textarea>

          <input
            v-else-if="field.type === 'autocomplete'"
            type="text"
            :name="field.settings?.field_name || undefined"
            :value="values[field.id] ?? ''"
            :placeholder="placeholderFor(field)"
            :list="`fr-datalist-${field.id}`"
            @input="setValue(field, $event.target.value)"
          />
          <datalist v-if="field.type === 'autocomplete'" :id="`fr-datalist-${field.id}`">
            <option v-for="option in field.options || []" :key="option" :value="option" />
          </datalist>

          <input
            v-else
            :type="inputType(field.type)"
            :name="field.settings?.field_name || undefined"
            :autocomplete="autoCompleteHint(field)"
            :value="values[field.id] ?? ''"
            :placeholder="placeholderFor(field)"
            :min="field.type === 'number' && field.settings?.min_value != null ? field.settings.min_value : undefined"
            :max="field.type === 'number' && field.settings?.max_value != null ? field.settings.max_value : undefined"
            :maxlength="field.settings?.max_length || undefined"
            @input="setValue(field, $event.target.value)"
          />
        </label>
      </div>
    </div>

    <label v-if="settings.require_agreement" class="fr-agreement">
      <input type="checkbox" required />
      <span>I agree to the terms and conditions{{ settings.terms_url ? '' : ' of this event' }}.</span>
    </label>

    <div v-if="hasSteps" class="fr-nav">
      <button v-if="currentStep > 0" class="fr-back" type="button" @click="prevStep">← Back</button>
      <button v-if="!lastStep" class="fr-submit" type="button" @click="nextStep">Continue →</button>
      <button v-else class="fr-submit" type="submit">Submit registration</button>
    </div>
    <button v-else class="fr-submit" type="submit">Submit registration</button>

    <p v-if="settings.show_privacy_policy" class="fr-privacy">
      Your information will only be used to manage your attendance at this event.
    </p>
  </form>
</template>
