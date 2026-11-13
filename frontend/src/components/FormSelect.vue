<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import AppIcon from './AppIcon.vue'

// Browsers don't let pages style the open list of a native <select>, so this draws its own.
defineOptions({ inheritAttrs: false })
const props = defineProps({
  id: { type: String, required: true },
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Choose an option' },
})
const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const list = ref(null)
const open = ref(false)
const listStyle = ref({})
const active = ref(-1)
const listId = computed(() => `${props.id}-list`)
const optionId = (index) => `${props.id}-opt-${index}`
const LIST_HEIGHT = 280
const GAP = 6
const MIN_LIST_WIDTH = 260

const onOutside = (event) => { if (!root.value?.contains(event.target)) closeList() }
// The list is fixed to the screen (body is a scroll container and would clip it), so it's moved along with the page.
const onScroll = (event) => {
  if (list.value?.contains(event.target)) return
  const box = root.value.getBoundingClientRect()
  if (box.bottom < 0 || box.top > window.innerHeight) closeList()
  else placeList()
}
const scrollToActive = () => nextTick(() => document.getElementById(optionId(active.value))?.scrollIntoView({ block: 'nearest' }))
const placeList = () => {
  const box = root.value.getBoundingClientRect()
  const below = window.innerHeight - box.bottom - GAP * 2
  const above = box.top - GAP * 2
  const up = below < LIST_HEIGHT && above > below
  // Narrow fields still get a readable list, kept inside the screen on phones.
  const width = Math.min(Math.max(box.width, MIN_LIST_WIDTH), window.innerWidth - GAP * 2)
  const left = Math.min(Math.max(box.left, GAP), window.innerWidth - width - GAP)
  listStyle.value = {
    left: `${left}px`,
    width: `${width}px`,
    maxHeight: `${Math.min(LIST_HEIGHT, up ? above : below)}px`,
    ...(up ? { bottom: `${window.innerHeight - box.top + GAP}px` } : { top: `${box.bottom + GAP}px` }),
  }
}
const removeListeners = () => {
  document.removeEventListener('pointerdown', onOutside)
  window.removeEventListener('scroll', onScroll, true)
  window.removeEventListener('resize', closeList)
}
const openList = () => {
  placeList()
  active.value = Math.max(props.options.indexOf(props.modelValue), 0)
  open.value = true
  document.addEventListener('pointerdown', onOutside)
  window.addEventListener('scroll', onScroll, true)
  window.addEventListener('resize', closeList)
  scrollToActive()
}
function closeList() {
  open.value = false
  removeListeners()
}
onBeforeUnmount(removeListeners)

const choose = (option) => { emit('update:modelValue', option); closeList() }
const moveTo = (index) => { active.value = Math.min(Math.max(index, 0), props.options.length - 1); scrollToActive() }

// Typing a few letters jumps to the option that starts with them.
let typed = ''
let typedTimer
const typeAhead = (key) => {
  clearTimeout(typedTimer)
  typed += key.toLowerCase()
  typedTimer = setTimeout(() => { typed = '' }, 600)
  const match = props.options.findIndex((option) => String(option).toLowerCase().startsWith(typed))
  if (match >= 0) moveTo(match)
}

const onKeydown = (event) => {
  const { key } = event
  if (!open.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(key)) { event.preventDefault(); openList() }
    return
  }
  if (key === 'Tab') { closeList(); return }
  if (key === 'Escape') closeList()
  else if (key === 'ArrowDown') moveTo(active.value + 1)
  else if (key === 'ArrowUp') moveTo(active.value - 1)
  else if (key === 'Home') moveTo(0)
  else if (key === 'End') moveTo(props.options.length - 1)
  else if (key === 'Enter' || (key === ' ' && !typed)) { if (props.options[active.value] !== undefined) choose(props.options[active.value]) }
  else if (key.length === 1) typeAhead(key)
  else return
  event.preventDefault()
}
</script>

<template>
  <div ref="root" class="fs" :class="{ 'is-open': open }">
    <button
      v-bind="$attrs"
      :id="id"
      type="button"
      class="fs__trigger"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="listId"
      :aria-activedescendant="open && active >= 0 ? optionId(active) : undefined"
      @click="open ? closeList() : openList()"
      @keydown="onKeydown"
      @blur="closeList"
    >
      <span class="fs__value" :class="{ 'is-placeholder': !modelValue }">{{ modelValue || placeholder }}</span>
      <AppIcon class="fs__chevron" name="chevron-down" :size="18" />
    </button>
    <ul v-show="open" :id="listId" ref="list" class="fs__list" :style="listStyle" role="listbox" @mousedown.prevent>
      <li
        v-for="(option, index) in options"
        :id="optionId(index)"
        :key="option"
        class="fs__option"
        :class="{ 'is-active': index === active, 'is-selected': option === modelValue }"
        role="option"
        :aria-selected="option === modelValue"
        @mousemove="active = index"
        @click="choose(option)"
      >
        <span>{{ option }}</span>
        <AppIcon v-if="option === modelValue" name="check" :size="17" />
      </li>
      <li v-if="!options.length" class="fs__empty">No options available</li>
    </ul>
  </div>
</template>

<style scoped>
.fs { position: relative; width: 100%; min-width: 0; }
.fs.is-open { z-index: 30; }
.fs button.fs__trigger { display: flex; width: 100%; min-width: 0; max-width: 100%; min-height: 46px; height: auto; white-space: normal; align-items: center; justify-content: space-between; gap: 10px; margin: 0; padding: 10px 12px 10px 13px; border: 1px solid var(--fr-border, #cfd9e2); border-radius: var(--fr-radius, 10px); background: #fff; box-shadow: none; color: var(--fr-ink, #0f1c2e); font: inherit; font-size: 16px; font-weight: 400; line-height: 1.4; text-align: left; cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; }
.fs button.fs__trigger:hover { border-color: #aebdca; background: #fff; color: var(--fr-ink, #0f1c2e); transform: none; }
.fs button.fs__trigger:focus, .fs.is-open button.fs__trigger { border-color: var(--fr-primary, #0b7f62); box-shadow: 0 0 0 3px rgba(11, 127, 98, .18); outline: 0; }
.fs button.fs__trigger[aria-invalid="true"] { border-color: var(--fr-danger, #b42318); background: #fffbfa; }
.fs button.fs__trigger[aria-invalid="true"]:focus { box-shadow: 0 0 0 3px rgba(180, 35, 24, .16); }
/* Long answers (e.g. "Khmer/English" pairs) wrap instead of being cut off on small screens. */
.fs__value { flex: 1; min-width: 0; line-height: 1.5; overflow-wrap: anywhere; }
.fs__value.is-placeholder { color: #94a3b8; }
.fs__chevron { flex: none; color: var(--fr-muted, #5f7286); transition: transform .18s ease, color .18s ease; }
.fs.is-open .fs__chevron { color: var(--fr-primary, #0b7f62); transform: rotate(180deg); }
.fs__list { position: fixed; z-index: 1000; margin: 0; padding: 6px; overflow-y: auto; border: 1px solid var(--fr-line, #e6ecf1); border-radius: 12px; background: #fff; box-shadow: 0 14px 34px rgba(16, 36, 61, .16); list-style: none; }
.fs__option { display: flex; min-height: 44px; align-items: center; justify-content: space-between; gap: 10px; margin: 0; padding: 9px 10px; border-radius: 8px; color: var(--fr-ink-2, #33475c); font-size: 15px; line-height: 1.5; overflow-wrap: anywhere; cursor: pointer; }
.fs__option > span { min-width: 0; }
.fs__option.is-active { background: #f1f5f8; color: var(--fr-ink, #0f1c2e); }
.fs__option.is-selected { background: var(--fr-primary-wash, #e8f7f2); color: var(--fr-primary-hover, #086a51); font-weight: 600; }
.fs__option svg { flex: none; color: var(--fr-primary, #0b7f62); }
.fs__empty { padding: 10px; color: var(--fr-muted, #5f7286); font-size: 14px; }
@media (prefers-reduced-motion: reduce) { .fs__chevron { transition: none; } }
</style>
