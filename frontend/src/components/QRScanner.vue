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
	} catch {
		emit('error', 'Camera access is unavailable. Allow camera permission or enter the attendee code below.')
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