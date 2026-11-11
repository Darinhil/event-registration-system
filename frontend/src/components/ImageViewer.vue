<script setup>
import { ref } from 'vue'
import AppIcon from './AppIcon.vue'

defineProps({
  src: { type: String, required: true },
  alt: { type: String, default: '' },
  imgClass: { type: [String, Array, Object], default: '' },
})

// Native <dialog> gives us Esc-to-close, focus trapping and the top layer for free.
const dialog = ref(null)
const open = () => dialog.value?.showModal()
const close = () => dialog.value?.close()
</script>

<template>
  <button type="button" class="iv-trigger" :aria-label="alt ? `View picture: ${alt}` : 'View full picture'" @click="open">
    <img :class="imgClass" :src="src" :alt="alt" />
    <span class="iv-hint" aria-hidden="true"><AppIcon name="search" :size="14" />View</span>
  </button>
  <dialog ref="dialog" class="iv-dialog" aria-label="Picture" @click.self="close">
    <img class="iv-full" :src="src" :alt="alt" />
    <button type="button" class="iv-close" aria-label="Close picture" @click="close"><AppIcon name="x" :size="22" /></button>
  </dialog>
</template>

<style scoped>
.iv-trigger { position: relative; display: block; width: 100%; margin: 0; padding: 0; border: 0; border-radius: 0; background: none; cursor: zoom-in; }
.iv-trigger:hover { transform: none; }
.iv-trigger:focus-visible { outline: 3px solid rgba(11, 127, 98, .45); outline-offset: -3px; }
.iv-hint { position: absolute; right: 10px; bottom: 10px; display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 999px; background: rgba(15, 28, 46, .72); color: #fff; font-size: 12.5px; font-weight: 600; opacity: .9; pointer-events: none; transition: opacity .15s ease; }
.iv-trigger:hover .iv-hint, .iv-trigger:focus-visible .iv-hint { opacity: 1; }

.iv-dialog { width: 100vw; max-width: none; height: 100dvh; max-height: none; margin: 0; padding: 56px 16px 24px; border: 0; background: transparent; }
.iv-dialog[open] { display: grid; place-items: center; }
.iv-dialog::backdrop { background: rgba(10, 18, 30, .86); }
.iv-full { display: block; max-width: min(100%, 1400px); max-height: 100%; border-radius: 8px; object-fit: contain; box-shadow: 0 20px 60px rgba(0, 0, 0, .4); cursor: default; }
.iv-close { position: fixed; top: 12px; right: 12px; display: grid; place-items: center; width: 44px; height: 44px; margin: 0; padding: 0; border: 0; border-radius: 50%; background: rgba(255, 255, 255, .14); color: #fff; cursor: pointer; }
.iv-close:hover { background: rgba(255, 255, 255, .26); transform: none; }
.iv-close:focus-visible { outline: 3px solid rgba(255, 255, 255, .6); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .iv-hint { transition: none; } }
</style>
