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
const dragOver = ref(false)
const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`)

const pickFile = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  fileError.value = ''
  dragOver.value = false
  if (!file) return
  if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) { fileError.value = 'Attach a PDF or an image (JPG or PNG).'; return }
  if (file.size > MAX_FILE) { fileError.value = 'The attachment must be 5 MB or smaller.'; return }
  attachment.value = file
}

const onDragOver = (event) => { event.preventDefault(); dragOver.value = true }
const onDragLeave = (event) => { event.preventDefault(); dragOver.value = false }
const onDrop = (event) => {
  event.preventDefault()
  dragOver.value = false
  const file = event.dataTransfer.files?.[0]
  if (!file) return
  fileError.value = ''
  if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) { fileError.value = 'Attach a PDF or an image (JPG or PNG).'; return }
  if (file.size > MAX_FILE) { fileError.value = 'The attachment must be 5 MB or smaller.'; return }
  attachment.value = file
}
</script>

<template>
  <div class="rq-parts">

    <div class="lv-field rq-part rq-part--approver">
      <label for="rq-approver" class="lv-label rq-part__label">Approver <span class="lv-req" aria-hidden="true">*</span></label>
      <div class="rq-select-wrap">
        <select id="rq-approver" v-model="approverId" class="rq-select" :aria-invalid="errors.approver_id ? 'true' : undefined" :aria-describedby="errors.approver_id ? 'rq-approver-error' : undefined">
          <option value="" disabled>Choose your supervisor</option>
          <option v-for="person in approvers" :key="person.id" :value="person.id">{{ person.name }}</option>
        </select>
        <AppIcon name="chevron-down" size="14" class="rq-select-arrow" />
      </div>
      <p v-if="errors.approver_id" id="rq-approver-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.approver_id }}</p>
    </div>

    <div v-if="withAttachment" class="lv-field rq-part rq-part--attachment">
      <label for="rq-attachment" class="lv-label rq-part__label">Attachment <span class="lv-optional">Optional</span></label>
      <div class="lv-file rq-file-box" :class="{ 'has-error': fileError || errors.attachment, 'has-file': attachment, 'is-drag': dragOver }" @dragover.prevent="onDragOver" @dragleave.prevent="onDragLeave" @drop.prevent="onDrop">
        <AppIcon :name="attachment ? 'file-text' : 'upload'" :size="22" />
        <div class="lv-file__text">
          <div class="lv-file__title">{{ attachment ? attachment.name : 'Upload supporting documents' }}</div>
          <small>{{ attachment ? fileSize(attachment.size) : 'Drag and drop your file here or browse' }}</small>
        </div>
        <button v-if="attachment" type="button" class="lv-btn lv-btn--ghost lv-btn--sm rq-file-remove" @click="attachment = null" aria-label="Remove file">Remove</button>
        <button type="button" class="lv-btn lv-btn--ghost lv-btn--sm rq-file-choose" aria-describedby="rq-attachment" @click="fileInput?.click()"><AppIcon name="upload" :size="15" />{{ attachment ? 'Replace' : 'Choose file' }}</button>
        <input ref="fileInput" id="rq-attachment" type="file" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" tabindex="-1" aria-hidden="true" @change="pickFile" />
      </div>
      <p v-if="fileError || errors.attachment" class="lv-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ fileError || errors.attachment }}</p>
    </div>

  </div>
</template>

<style scoped>
.rq-parts { display: grid; gap: 14px; }

.rq-part__label { display: flex; align-items: center; gap: 6px; margin: 0 0 8px; color: #293e53; font-size: .95rem; font-weight: 600; }
.lv-req { display: inline; color: #b42318; }
.lv-optional { margin-left: 6px; padding: 1px 7px; border-radius: 99px; background: #e8eef3; color: #5b6d7f; font-size: .72rem; font-weight: 600; }

.rq-select-wrap { position: relative; display: flex; width: 100%; }
.rq-select { box-sizing: border-box; width: 100%; min-height: 46px; padding: 0 42px 0 14px; border: 1px solid #cbd7e2; border-radius: 8px; background: #fff; color: #314b63; font: inherit; font-size: .92rem; appearance: none; cursor: pointer; transition: border-color .16s, box-shadow .16s, background-color .16s; }
.rq-select:focus { border-color: #0b8067; outline: 0; box-shadow: 0 0 0 3px #0b80671c; background: #fff; }
.rq-select[aria-invalid="true"] { border-color: #bf3d39 !important; box-shadow: 0 0 0 2px #bf3d3914; }
.rq-select option { font-size: .92rem; padding: 8px 12px; }
.rq-select-arrow { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #6a7f92; pointer-events: none; flex: none; }

.rq-file-box { display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: 12px; padding: 18px 16px; border: 2px dashed #cbd8e4; border-radius: 10px; background: #f8fafc; color: #53677a; transition: border-color .16s, background-color .16s, box-shadow .16s; cursor: pointer; }
.rq-file-box.is-drag { border-style: solid; border-color: #0b8067; background: #eef7f4; box-shadow: 0 0 0 3px #0b80671c; }
.rq-file-box.has-file { border-style: solid; border-color: #9acbbb; background: #f2faf7; }
.rq-file-box.has-error { border-color: #bf3d39; background: #fff8f7; }
.rq-file-box > svg:first-child { flex: none; color: #087f66; }
.rq-file-box.has-file > svg:first-child { color: #2e8b57; }
.rq-file__text { display: grid; min-width: 0; gap: 3px; }
.lv-file__title { overflow: hidden; color: #314b63; font-size: .9rem; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.rq-file-box.has-file .lv-file__title { color: #087f66; }
.lv-file__text small { color: #6a7f92; font-size: .74rem; }
.rq-file-box > .lv-btn--ghost:not(.rq-file-choose) { grid-column: 3; justify-self: end; padding: 5px 10px; border: 1px solid #d4dfe8; border-radius: 6px; background: #fff; color: #52677b; font: inherit; font-size: .76rem; cursor: pointer; transition: background-color .15s, border-color .15s; }
.rq-file-box > .lv-btn--ghost:not(.rq-file-choose):hover { color: #a12622; text-decoration: underline; border-color: #a12622; }
.rq-file-choose { grid-column: 4; display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid #d3dee8; border-radius: 6px; background: #fff; color: #35516a; font: inherit; font-size: .82rem; font-weight: 600; box-shadow: 0 1px 2px #17365f0b; cursor: pointer; transition: background-color .15s, border-color .15s, box-shadow .15s; }
.rq-file-choose:hover { border-color: #8ea9bf; background: #f1f6fa; box-shadow: 0 2px 5px #17365f12; }
.rq-file-choose:focus-visible { outline: 3px solid #8bcdb7; outline-offset: 2px; }

.lv-error { margin: 6px 0 0; font-size: .86rem; color: #a12622; display: flex; align-items: center; gap: 6px; }
</style>
