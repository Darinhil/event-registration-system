<script setup>
import AppIcon from '../AppIcon.vue'

// Label, required mark, hint and error for one form control. The control itself
// goes in the default slot and should use `fieldId` as its id.
defineProps({
  label: { type: String, required: true },
  fieldId: { type: String, required: true },
  required: Boolean,
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  wide: Boolean,
})
</script>

<template>
  <div class="pp-field" :class="{ 'has-error': error, 'pp-field--wide': wide }">
    <div class="pp-field__label">
      <label :for="fieldId">{{ label }}<span v-if="required" class="pp-req" aria-hidden="true"> *</span></label>
      <slot name="action" />
    </div>
    <slot />
    <p v-if="error" :id="`${fieldId}-error`" class="pp-field__error"><AppIcon name="alert-circle" :size="14" />{{ error }}</p>
    <p v-else-if="hint" :id="`${fieldId}-hint`" class="pp-field__hint">{{ hint }}</p>
  </div>
</template>
