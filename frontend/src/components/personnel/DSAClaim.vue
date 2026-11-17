<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'

const blank = () => ({ purpose: '', destination: '', start_date: '', end_date: '', days: '', rate: '' })
const form = reactive(blank())
const request = useStaffRequestForm('dsa_claim')
const { approvers, approverId, attachment, saving, saved, formError, errors, formEl } = request
const total = computed(() => (Number(form.days) || 0) * (Number(form.rate) || 0))

const validate = () => {
  const found = {}
  if (!form.purpose.trim()) found.purpose = 'Describe the purpose of this claim.'
  if (!form.destination.trim()) found.destination = 'Enter your destination.'
  if (!form.start_date) found.start_date = 'Choose the start date.'
  if (!form.end_date) found.end_date = 'Choose the end date.'
  else if (form.start_date && form.end_date < form.start_date) found.end_date = 'The end date can’t be before the start date.'
  if (!form.days) found.days = 'Enter the number of days.'
  else if (Number(form.days) <= 0) found.days = 'Enter a valid number of days.'
  if (!form.rate) found.rate = 'Enter the daily rate.'
  else if (Number(form.rate) < 0) found.rate = 'Enter a valid rate.'
  if (!approverId.value) found.approver_id = 'Choose who should approve this request.'
  Object.keys(errors).forEach((key) => delete errors[key])
  Object.assign(errors, found)
  return !Object.keys(found).length
}
const submit = () => { if (validate()) request.submit({ ...form, total: total.value }); else request.focusFirstError() }
const startAnother = () => { Object.assign(form, blank()); request.reset() }
</script>

<template>
  <div class="lv">
    <header class="lv-head"><h1>DSA Claim</h1></header>
    <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
    <form v-else ref="formEl" class="lv-card lv-form rq-form" novalidate @submit.prevent="submit">
      <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>
      <div class="lv-field">
        <label for="dsa-purpose" class="lv-label">Purpose <span class="lv-req" aria-hidden="true">*</span></label>
        <textarea id="dsa-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
        <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
      </div>
      <div class="lv-grid">
        <div class="lv-field">
          <label for="dsa-destination" class="lv-label">Destination <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="dsa-destination" v-model="form.destination" type="text" class="rq-input" :aria-invalid="errors.destination ? 'true' : undefined" />
          <p v-if="errors.destination" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.destination }}</p>
        </div>
        <div class="lv-field">
          <label for="dsa-start" class="lv-label">Start date <span class="lv-req" aria-hidden="true">*</span></label>
          <DateInput id="dsa-start" v-model="form.start_date" class="rq-input" :aria-invalid="errors.start_date ? 'true' : undefined" />
          <p v-if="errors.start_date" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.start_date }}</p>
        </div>
        <div class="lv-field">
          <label for="dsa-end" class="lv-label">End date <span class="lv-req" aria-hidden="true">*</span></label>
          <DateInput id="dsa-end" v-model="form.end_date" class="rq-input" :min="form.start_date || undefined" :aria-invalid="errors.end_date ? 'true' : undefined" />
          <p v-if="errors.end_date" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.end_date }}</p>
        </div>
        <div class="lv-field">
          <label for="dsa-days" class="lv-label">Number of days <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="dsa-days" v-model.number="form.days" type="number" min="1" step="1" class="rq-input" :aria-invalid="errors.days ? 'true' : undefined" />
          <p v-if="errors.days" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.days }}</p>
        </div>
        <div class="lv-field">
          <label for="dsa-rate" class="lv-label">Daily rate ($) <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="dsa-rate" v-model.number="form.rate" type="number" min="0" step="0.01" class="rq-input" :aria-invalid="errors.rate ? 'true' : undefined" />
          <p v-if="errors.rate" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.rate }}</p>
        </div>
      </div>
      <p class="rq-total"><span>Estimated total</span><strong>${{ total.toFixed(2) }}</strong></p>
      <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="errors" />
      <footer class="lv-form__foot">
        <span></span>
        <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit claim' }}</button>
      </footer>
    </form>
  </div>
</template>
