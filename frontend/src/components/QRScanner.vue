<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Html5Qrcode } from 'html5-qrcode'

const emit = defineEmits(['scan', 'error'])
const scanner = ref(null)
const scannerId = `qr-reader-${Math.random().toString(36).slice(2)}`
let reader
let hasScanned = false

const stopCamera = async () => {
	if (!reader) return
	try {
		if (reader.isScanning) await reader.stop()
		reader.clear()
	} catch {
		// The camera may already be closed by the browser or component lifecycle.
	}
}

onMounted(async () => {
	reader = new Html5Qrcode(scannerId)
	try {
		if (!window.isSecureContext && !['localhost', '127.0.0.1'].includes(window.location.hostname)) {
			throw new Error('Camera access requires HTTPS or localhost.')
		}
		await reader.start(
			{ facingMode: 'environment' },
			{ fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1 },
			async (decodedText) => {
				if (hasScanned) return
				hasScanned = true
				emit('scan', decodedText)
				await stopCamera()
			},
			() => {},
		)
	} catch (error) {
		const reason = error instanceof Error && error.message.includes('HTTPS')
			? 'Camera access requires HTTPS (or localhost). Open the site using an HTTPS URL, then allow camera permission.'
			: 'Camera access is unavailable. Allow camera permission, close other apps using the camera, or enter the attendee code below.'
		emit('error', reason)
	}
})

onBeforeUnmount(stopCamera)
</script>

<template>
	<div class="scanner">
		<div :id="scannerId" ref="scanner" class="camera-reader"></div>
		<p class="scanner-hint">Point the camera at the attendee QR pass.</p>
		<input placeholder="Paste QR payload instead" @change="emit('scan', $event.target.value)" />
	</div>
</template>
