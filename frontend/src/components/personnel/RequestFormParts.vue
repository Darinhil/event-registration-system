<script setup>
import { ref } from 'vue'
import AppIcon from '../AppIcon.vue'

// The approver and attachment fields, shared by every request form.
const approverId = defineModel('approverId', { type: [String, Number], default: '' })
const attachment = defineModel('attachment', { type: Object, default: null })
defineProps({ approvers: { type: Array, default: () => [] }, errors: { type: Object, default: () => ({}) }, withAttachment: { type: Boolean, default: true } })

const MAX_FILE = 5 * 1024 * 1024
const fileInput = ref(null)
const fileError = ref('')
const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`)
const pickFile = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  fileError.value = ''
  if (!file) return
  if (!/\.(pdf|jpe?g|png|webp)$/i.test(file.name)) { fileError.value = 'Attach a PDF or an image (JPG, PNG or WebP).'; return }
  if (file.size > MAX_FILE) { fileError.value = 'The attachment must be 5 MB or smaller.'; return }
  attachment.value = file
}
</script>

<template>
  <div class="lv-field">
    <label for="rq-approver" class="lv-label">Approver <span class="lv-req" aria-hidden="true">*</span></label>
    <select id="rq-approver" v-model="approverId" class="rq-select" :aria-invalid="errors.approver_id ? 'true' : undefined" :aria-describedby="errors.approver_id ? 'rq-approver-error' : undefined">
      <option value="" disabled>Choose your supervisor</option>
      <option v-for="person in approvers" :key="person.id" :value="person.id">{{ person.name }}</option>
    </select>
    <p v-if="errors.approver_id" id="rq-approver-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.approver_id }}</p>
  </div>

  <div v-if="withAttachment" class="lv-field">
    <span id="rq-file-label" class="lv-label">Attachment <span class="lv-optional">Optional</span></span>
    <div class="lv-file" :class="{ 'has-error': fileError || errors.attachment, 'has-file': attachment }">
      <AppIcon :name="attachment ? 'file-text' : 'upload'" :size="20" />
      <div class="lv-file__text">
        <strong>{{ attachment ? attachment.name : 'Supporting document' }}</strong>
        <small>{{ attachment ? fileSize(attachment.size) : 'PDF or image, up to 5 MB' }}</small>
      </div>
      <button v-if="attachment" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" @click="attachment = null">Remove</button>
      <button type="button" class="lv-btn lv-btn--ghost lv-btn--sm" aria-describedby="rq-file-label" @click="fileInput?.click()">{{ attachment ? 'Replace' : 'Choose file' }}</button>
      <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="sr-only" tabindex="-1" aria-hidden="true" @change="pickFile" />
    </div>
    <p v-if="fileError || errors.attachment" class="lv-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ fileError || errors.attachment }}</p>
  </div>
</template>
