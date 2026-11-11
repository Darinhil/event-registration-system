<script setup>
import { computed, ref } from 'vue'
import AppIcon from './AppIcon.vue'
import { copyText } from '../utils/clipboard'

const props = defineProps({
  location: { type: String, default: '' },
  fallback: { type: String, default: 'Location to be confirmed' },
})

const text = computed(() => String(props.location || '').trim())
const isUrl = computed(() => /^https?:\/\//i.test(text.value))
// A pasted map link opens as-is; a written address opens as a Google Maps search.
const href = computed(() => {
  if (!text.value) return ''
  return isUrl.value ? text.value : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(text.value)}`
})

const copied = ref(false)
let timer
const copy = async () => {
  if (!(await copyText(text.value))) return
  copied.value = true
  clearTimeout(timer)
  timer = setTimeout(() => { copied.value = false }, 1800)
}
</script>

<template>
  <span v-if="!text">{{ fallback }}</span>
  <span v-else class="loc">
    <a class="loc__link" :href="href" target="_blank" rel="noopener noreferrer" :title="isUrl ? 'Open map' : 'Open in Google Maps'">{{ text }}</a>
    <button type="button" class="loc__copy" :aria-label="copied ? 'Location copied' : 'Copy location'" :title="copied ? 'Copied' : 'Copy'" @click="copy">
      <AppIcon :name="copied ? 'check' : 'copy'" :size="14" />
    </button>
  </span>
</template>

<style scoped>
.loc { display: inline-flex; align-items: flex-start; gap: 6px; min-width: 0; }
.loc__link { color: inherit; text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 2px; overflow-wrap: anywhere; }
.loc__link:hover { text-decoration-thickness: 2px; }
.loc__copy { flex: none; display: inline-grid; place-items: center; width: 22px; height: 22px; margin-top: -1px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: inherit; opacity: .7; cursor: pointer; }
.loc__copy:hover, .loc__copy:focus-visible { opacity: 1; background: color-mix(in srgb, currentColor 12%, transparent); }
.loc__copy :deep(svg) { margin: 0; color: inherit; }
</style>
