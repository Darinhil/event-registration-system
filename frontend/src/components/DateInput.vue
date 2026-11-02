<script setup>
import { ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'

// Browsers pick the display format of <input type="date"> from their own locale, so
// dd/mm/yyyy needs a text field. The model stays yyyy-mm-dd, same as a native date input.
defineOptions({ inheritAttrs: false })
const props = defineProps({ modelValue: { type: String, default: '' }, min: { type: String, default: undefined }, max: { type: String, default: undefined } })
const emit = defineEmits(['update:modelValue'])

const pad = (value) => String(value).padStart(2, '0')
const toDisplay = (iso) => { const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return match ? `${match[3]}/${match[2]}/${match[1]}` : '' }
const toIso = (text) => {
  const match = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(text) || /^(\d{2})(\d{2})(\d{4})$/.exec(text)
  if (!match) return ''
  const [day, month, year] = match.slice(1).map(Number)
  const date = new Date(Date.UTC(year, month - 1, day))
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return ''
  return `${match[3]}-${pad(month)}-${pad(day)}`
}

const text = ref(toDisplay(props.modelValue))
const picker = ref(null)
watch(() => props.modelValue, (value) => { text.value = toDisplay(value) })

// Runs on blur and Enter. Text that isn't a real date goes back to the last valid value.
const commit = () => {
  const typed = text.value.trim()
  const iso = typed ? toIso(typed) : ''
  if (typed && !iso) { text.value = toDisplay(props.modelValue); return }
  text.value = toDisplay(iso)
  if (iso !== (props.modelValue || '')) emit('update:modelValue', iso)
}
const openPicker = () => { try { picker.value.showPicker() } catch { picker.value.focus() } }
</script>

<template>
  <span class="date-input">
    <input v-bind="$attrs" v-model="text" class="date-input__text" type="text" placeholder="dd/mm/yyyy" maxlength="10" autocomplete="off" @change="commit" />
    <button type="button" class="date-input__button" aria-label="Choose date from calendar" @click="openPicker"><AppIcon name="calendar" :size="17" /></button>
    <input ref="picker" class="date-input__picker" type="date" tabindex="-1" aria-hidden="true" :value="modelValue" :min="min" :max="max" @change="emit('update:modelValue', $event.target.value)" />
  </span>
</template>

<style scoped>
.date-input { position: relative; display: block; width: 100%; }
.date-input input.date-input__text { padding-right: 42px; }
.date-input button.date-input__button { position: absolute; top: 50%; right: 6px; display: grid; place-items: center; width: 32px; height: 32px; min-height: 0; margin: 0; padding: 0; border: 0; border-radius: 8px; background: transparent; box-shadow: none; color: inherit; cursor: pointer; transform: translateY(-50%); }
.date-input button.date-input__button:hover { background: rgba(15, 23, 42, .06); }
.date-input input.date-input__picker { position: absolute; bottom: 0; left: 0; width: 100%; height: 1px; min-height: 0; margin: 0; padding: 0; border: 0; opacity: 0; pointer-events: none; }
</style>
