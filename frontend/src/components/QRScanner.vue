<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Html5Qrcode } from 'html5-qrcode'

const emit = defineEmits(['scan', 'error'])
const scannerId = `qr-reader-${Math.random().toString(36).slice(2)}`
const cameraStatus = ref('Looking for a camera...')
const cameraError = ref('')
const imageError = ref('')
const starting = ref(false)
const manualCode = ref('')
let reader
let hasScanned = false
let disposed = false

const freshReader = async () => {
  if (reader) {
    try {
      if (reader.isScanning) await reader.stop()
      reader.clear()
    } catch {
      // Reset the scanner below if a previous camera attempt failed partway through.
    }
  }
  reader = new Html5Qrcode(scannerId)
  return reader
}

const stopCamera = async () => {
  if (!reader) return
  try {
    if (reader.isScanning) await reader.stop()
    reader.clear()
  } catch {
    // The camera may already be closed by the browser or component lifecycle.
  }
}

const cameraErrorMessage = (error) => {
  const hostname = window.location.hostname
  const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1'
  if (!window.isSecureContext && !isLocalhost) {
    return 'Camera access requires HTTPS on this address. Use an HTTPS site or localhost, or enter the attendee code below.'
  }
  if (error?.name === 'NotAllowedError' || error?.name === 'PermissionDeniedError' || error?.name === 'SecurityError') {
    return 'The browser blocked camera access. Click the site icon beside the address, open Site settings, set Camera to Allow, then refresh. You can also scan a QR image below.'
  }
  if (error?.name === 'NotFoundError' || error?.name === 'DevicesNotFoundError') {
    return 'No camera was found on this device. Connect a camera or enter the attendee code below.'
  }
  if (error?.name === 'NotReadableError' || error?.name === 'TrackStartError' || error?.name === 'AbortError') {
    return 'The camera could not start. Close other apps using it, then choose Retry camera.'
  }
  const detail = error?.message ? ` Browser detail: ${error.message}` : ''
  return `Unable to start a camera. Check permissions and try again.${detail}`
}

const onDecoded = async (decodedText) => {
  if (hasScanned || disposed) return
  hasScanned = true
  cameraError.value = ''
  cameraStatus.value = 'QR found. Loading attendee...'
  emit('scan', decodedText)
  await stopCamera()
}

const startCamera = async () => {
  if (starting.value || disposed) return
  starting.value = true
  cameraError.value = ''
  cameraStatus.value = 'Looking for a camera...'
  hasScanned = false

  const hostname = window.location.hostname
  const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1'
  if (!window.isSecureContext && !isLocalhost) {
    cameraError.value = cameraErrorMessage()
    cameraStatus.value = ''
    emit('error', cameraError.value)
    starting.value = false
    return
  }
  if (!navigator.mediaDevices?.getUserMedia) {
    cameraError.value = 'This browser cannot access a camera. Enter the attendee code below instead.'
    cameraStatus.value = ''
    emit('error', cameraError.value)
    starting.value = false
    return
  }

  try {
    const cameras = await Html5Qrcode.getCameras()
    if (disposed) return
    if (!cameras.length) throw new DOMException('No camera devices were found.', 'NotFoundError')

    const preferred = cameras.find(({ label }) => /back|rear|environment/i.test(label))
    const orderedCameras = [preferred, ...cameras.filter(({ id }) => id !== preferred?.id)].filter(Boolean)
    let lastError

    for (const camera of orderedCameras) {
      try {
        await freshReader()
        await reader.start(
          camera.id,
          {
            fps: 10,
            qrbox: (width, height) => {
              const size = Math.max(1, Math.floor(Math.min(width, height, 300)))
              return { width: size, height: size }
            },
          },
          onDecoded,
          () => {},
        )
        if (!disposed) cameraStatus.value = `Camera ready${camera.label ? `: ${camera.label}` : ''}. Point it at the attendee QR pass.`
        else await stopCamera()
        return
      } catch (error) {
        lastError = error
      }
    }
    throw lastError || new Error('No camera could be started.')
  } catch (error) {
    if (!disposed) {
      cameraError.value = cameraErrorMessage(error)
      cameraStatus.value = ''
      emit('error', cameraError.value)
    }
  } finally {
    starting.value = false
  }
}

const submitManualCode = () => {
  const value = manualCode.value.trim()
  if (!value) return
  emit('scan', value)
  manualCode.value = ''
}

const scanImage = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  imageError.value = ''
  cameraStatus.value = 'Reading QR image...'
  try {
    if (!reader) await freshReader()
    const decodedText = await reader.scanFile(file, false)
    await onDecoded(decodedText)
  } catch {
    cameraStatus.value = ''
    imageError.value = 'No QR code found in that image. Choose a clear, uncropped image and try again.'
  } finally {
    event.target.value = ''
  }
}

onMounted(startCamera)
onBeforeUnmount(async () => {
  disposed = true
  await stopCamera()
})
</script>

<template>
  <section class="scanner-card" aria-label="Attendee QR scanner">
    <div class="camera-frame">
      <div :id="scannerId" class="camera-reader"></div>
      <div class="camera-corners" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
      <div v-if="cameraError" class="camera-paused" role="status">
        <span class="camera-paused-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M14 10.5 19 7v10l-5-3.5v2.1a1.4 1.4 0 0 1-1.4 1.4H5.4A1.4 1.4 0 0 1 4 15.6V8.4A1.4 1.4 0 0 1 5.4 7h7.2A1.4 1.4 0 0 1 14 8.4z"/><path d="m3 3 18 18"/></svg>
        </span>
        <strong>CAMERA FEED PAUSED</strong>
      </div>
    </div>


    <button v-if="cameraError" class="scanner-retry" type="button" :disabled="starting" @click="startCamera">
      <span aria-hidden="true">&#8635;</span> {{ starting ? 'Starting camera...' : 'Retry camera' }}
    </button>
    <details v-if="cameraError" class="camera-help">
      <summary>Enable live camera</summary>
      <p>Open this site's permissions from the address bar, set Camera to Allow, refresh the page, then retry. Camera access on a local network address may require HTTPS.</p>
    </details>

    <p v-else class="scanner-hint" role="status">{{ cameraStatus }}</p>

    <div class="scanner-image-row">
      <input :id="`${scannerId}-image`" class="scanner-image-input" type="file" accept="image/*" @change="scanImage" />
      <label class="scanner-image-button" :for="`${scannerId}-image`">
        <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="2" y="2.5" width="12" height="11" rx="1.5"/><circle cx="6" cy="6" r="1.2"/><path d="m3 11 3-3 2 2 2-2 3 3"/></svg>
        Scan QR from image
      </label>
      <small v-if="imageError" class="scanner-image-error" role="alert">{{ imageError }}</small>
    </div>

    <div class="scanner-divider"><span></span><small>Or enter the attendee code</small><span></span></div>
    <form class="scanner-code-form" @submit.prevent="submitManualCode">
      <input v-model="manualCode" placeholder="Registration code or QR token" aria-label="Registration code or QR token" />
      <button type="submit" :disabled="!manualCode.trim()">Check In</button>
    </form>
  </section>
</template>

<style scoped>
.scanner-card { display: grid; gap: 10px; width: 100%; box-sizing: border-box; padding: 14px; border: 1px solid #e1e8f0; border-radius: 16px; background: #fff; box-shadow: 0 8px 22px rgba(20, 48, 78, .06); }
.camera-frame { position: relative; height: 210px; overflow: hidden; border: 1px solid #d5deea; border-radius: 12px; background: #0f172a; }
.camera-reader { width: 100%; height: 100%; min-height: 0 !important; background: #0f172a; }
:deep(.camera-reader video) { display: block; width: 100% !important; height: 100% !important; min-height: 0 !important; object-fit: cover; }
:deep(.camera-reader #qr-reader__scan_region) { min-height: 0 !important; }
:deep(.camera-reader img) { display: none; }
.camera-corners { position: absolute; inset: 12px; display: grid; grid-template: 1fr 1fr / 1fr 1fr; pointer-events: none; }
.camera-corners i { width: 15px; height: 15px; border-color: #16b8f4; border-style: solid; }
.camera-corners i:nth-child(1) { align-self: start; justify-self: start; border-width: 2px 0 0 2px; border-radius: 3px 0 0; }
.camera-corners i:nth-child(2) { justify-self: end; border-width: 2px 2px 0 0; border-radius: 0 3px 0 0; }
.camera-corners i:nth-child(3) { align-self: end; border-width: 0 0 2px 2px; border-radius: 0 0 0 3px; }
.camera-corners i:nth-child(4) { align-self: end; justify-self: end; border-width: 0 2px 2px 0; border-radius: 0 0 3px; }
.camera-paused { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 9px; background: rgba(15, 23, 42, .9); color: #aab6c9; pointer-events: none; }
.camera-paused-icon { display: grid; place-items: center; width: 38px; height: 38px; border: 1px solid rgba(148, 163, 184, .2); border-radius: 50%; background: #1e293b; }
.camera-paused-icon svg { width: 18px; fill: none; stroke: #aab6c9; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.camera-paused strong { font-size: .58rem; letter-spacing: .08em; }
.scanner-retry { display: flex; align-items: center; justify-content: center; gap: 6px; justify-self: center; min-width: 128px; padding: 8px 12px; border: 0; border-radius: 8px; background: #111827; color: #fff; font: inherit; font-size: .67rem; font-weight: 700; cursor: pointer; }
.scanner-retry:disabled { opacity: .65; cursor: wait; }
.camera-help { color: #526b89; font-size: .58rem; line-height: 1.45; text-align: center; }
.camera-help summary { width: fit-content; margin: 0 auto; color: #245da6; cursor: pointer; font-weight: 600; }
.camera-help p { margin: 6px 2px 0; }
.scanner-hint { margin: 0; color: #70829a; font-size: .62rem; line-height: 1.4; text-align: center; }
.scanner-image-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
.scanner-image-error { flex-basis: 100%; color: #ad3440; font-size: .58rem; text-align: center; }
.scanner-image-input { display: none; }
.scanner-image-button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 5px 10px; border: 1px solid #dce5ef; border-radius: 7px; background: #fff; color: #526b89; font-size: .61rem; cursor: pointer; }
.scanner-image-button svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 1.4; }
.scanner-divider { display: flex; align-items: center; gap: 8px; margin-top: 1px; }
.scanner-divider span { height: 1px; flex: 1; background: #edf1f6; }
.scanner-divider small { color: #526b89; font-size: .58rem; }
.scanner-code-form { display: flex; gap: 6px; min-width: 0; }
.scanner-code-form input { min-width: 0; flex: 1; height: 34px; box-sizing: border-box; padding: 0 10px; border: 1px solid #dce5ef; border-radius: 8px; background: #fff; color: #304762; font: inherit; font-size: .62rem; }
.scanner-code-form input::placeholder { color: #8b9bb0; }
.scanner-code-form button { flex: 0 0 auto; padding: 0 12px; border: 0; border-radius: 7px; background: #111827; color: #fff; font: inherit; font-size: .62rem; font-weight: 700; cursor: pointer; }
.scanner-code-form button:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width: 540px) { .scanner-card { padding: 10px; } .camera-frame { height: 190px; } }
</style>
