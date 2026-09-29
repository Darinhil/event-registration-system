<script setup>
import { Html5Qrcode } from 'html5-qrcode'
import { onBeforeUnmount, onMounted, ref } from 'vue'

const emit = defineEmits(['scan'])
const SCANNER_ID = 'qr-scanner-region'

const cameraReady = ref(false)
const cameraError = ref('')
const manualToken = ref('')
let scanner = null

const startCamera = async () => {
  cameraError.value = ''
  try {
    scanner = new Html5Qrcode(SCANNER_ID, { verbose: false })
    await scanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 230, height: 230 } },
      (decodedText) => emit('scan', decodedText),
      () => {},
    )
    cameraReady.value = true
  } catch {
    cameraError.value = 'Camera unavailable. Enter the code below your QR instead.'
  }
}

const stopCamera = () => {
  if (!scanner) return
  scanner.stop().then(() => scanner?.clear()).catch(() => {})
  scanner = null
  cameraReady.value = false
}

const submitManual = () => {
  const value = manualToken.value.trim()
  if (value) emit('scan', value)
  manualToken.value = ''
}

onMounted(startCamera)
onBeforeUnmount(stopCamera)
</script>

<template>
  <div class="scanner-shell">
    <div :id="SCANNER_ID" class="scanner-video"></div>
    <p v-if="cameraReady" class="scanner-hint">Point your camera at the QR code</p>
    <p v-if="cameraError" class="scanner-error">{{ cameraError }}</p>
    <form class="scanner-manual" @submit.prevent="submitManual">
      <input v-model="manualToken" type="text" placeholder="…or paste the QR code / token" />
      <button type="submit">Use code</button>
    </form>
  </div>
</template>

<style scoped>
.scanner-shell { display: grid; gap: 12px; }
.scanner-video { overflow: hidden; min-height: 240px; border: 1px solid #d7e2ee; border-radius: 12px; background: #0d1b2a; }
.scanner-video video { display: block; width: 100%; border-radius: inherit; }
.scanner-hint { margin: 0; color: #8295aa; font-size: 0.75rem; text-align: center; }
.scanner-error { margin: 0; color: #a33a2a; font-size: 0.75rem; text-align: center; }
.scanner-manual { display: flex; gap: 8px; }
.scanner-manual input { flex: 1; min-width: 0; border: 1px solid #b9b8ae; border-radius: 8px; padding: 11px 13px; background: #fffdf8; font: inherit; }
.scanner-manual button { margin: 0; border-radius: 8px; padding: 11px 16px; background: #182522; color: #f4f1e9; font-size: 0.8rem; cursor: pointer; }
</style>