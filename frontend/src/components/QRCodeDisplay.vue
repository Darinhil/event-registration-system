<script setup>
import { nextTick, ref, watch } from 'vue'
import QRCodeVue from 'qrcode.vue'
import { addVerifiedLogo, loadQrLogo } from '../utils/qrLogo'

const props = defineProps({
  value: { type: String, required: true },
  size: { type: Number, default: 220 },
})

const root = ref(null)
// The QR stays hidden until the logo has been added and the result decoded back to `value`
// (or the plain QR restored), so a code that might not scan is never shown.
const checking = ref(true)

let run = 0
const decorate = async () => {
  const current = ++run
  checking.value = true
  try {
    await loadQrLogo()
    await nextTick()
    const canvas = root.value?.querySelector('canvas')
    if (canvas && current === run && await addVerifiedLogo(canvas, props.value)) canvas.dataset.logo = '1'
  } catch {
    // Logo or decoder failed to load: the plain QR (level H) is shown as drawn.
  }
  if (current === run) checking.value = false
}
watch(() => [props.value, props.size], decorate, { immediate: true })
</script>

<template>
  <div ref="root" class="qr" :class="{ 'qr--checking': checking }" :aria-busy="checking">
    <QRCodeVue :key="`${value}|${size}`" :value="value" :size="size" level="H" />
  </div>
</template>

<style scoped>
.qr--checking :deep(canvas) { visibility: hidden; }
</style>
