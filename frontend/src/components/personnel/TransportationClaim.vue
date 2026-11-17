<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'

const TRANSPORT = ['Car', 'Motorbike', 'Bus', 'Airplane', 'Other']
const blank = () => ({ purpose: '', destination: '', start_date: '', end_date: '', transport: 'Car', distance: '', fuel_cost: '', tolls: '', parking: '', other: '' })
const form = reactive(blank())
const request = useStaffRequestForm('transportation_claim')
const { approvers, approverId, attachment, saving, saved, formError, errors, formEl } = request
const total = computed(() => [Number(form.fuel_cost) || 0, Number(form.tolls) || 0, Number(form.parking) || 0, Number(form.other) || 0].reduce((sum, value) => sum + value, 0))

const validate = () => {
  const found = {}
  if (!form.purpose.trim()) found.purpose = 'Describe the purpose of this claim.'
  if (!form.destination.trim()) found.destination = 'Enter your destination.'
  if (!form.start_date) found.start_date = 'Choose the start date.'
  if (!form.end_date) found.end_date = 'Choose the end date.'
  else if (form.start_date && form.end_date < form.start_date) found.end_date = 'The end date can not be before the start date.'
  if (!form.distance) found.distance = 'Enter the distance traveled.'
  else if (Number(form.distance) <= 0) found.distance = 'Enter a valid distance.'
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
    <header class="lv-head"><h1>Transportation Claim</h1></header>
    <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
    <form v-else ref="formEl" class="lv-card lv-form rq-form" novalidate @submit.prevent="submit">
      <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>
      <div class="lv-field">
        <label for="tc-purpose" class="lv-label">Purpose <span class="lv-req" aria-hidden="true">*</span></label>
        <textarea id="tc-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
        <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
      </div>
      <div class="lv-grid">
        <div class="lv-field">
          <label for="tc-destination" class="lv-label">Destination <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="tc-destination" v-model="form.destination" type="text" class="rq-input" :aria-invalid="errors.destination ? 'true' : undefined" />
          <p v-if="errors.destination" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.destination }}</p>
        </div>
        <div class="lv-field">
          <label for="tc-start" class="lv-label">Start date <span class="lv-req" aria-hidden="true">*</span></label>
          <DateInput id="tc-start" v-model="form.start_date" class="rq-input" :aria-invalid="errors.start_date ? 'true' : undefined" />
          <p v-if="errors.start_date" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.start_date }}</p>
        </div>
        <div class="lv-field">
          <label for="tc-end" class="lv-label">End date <span class="lv-req" aria-hidden="true">*</span></label>
          <DateInput id="tc-end" v-model="form.end_date" class="rq-input" :min="form.start_date || undefined" :aria-invalid="errors.end_date ? 'true' : undefined" />
          <p v-if="errors.end_date" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.end_date }}</p>
        </div>
        <div class="lv-field">
          <label for="tc-distance" class="lv-label">Distance (km) <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="tc-distance" v-model.number="form.distance" type="number" min="0" step="0.1" class="rq-input" :aria-invalid="errors.distance ? 'true' : undefined" />
          <p v-if="errors.distance" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.distance }}</p>
        </div>
      </div>
      <fieldset class="lv-field">
        <legend class="lv-label">Transportation method <span class="lv-req" aria-hidden="true">*</span></legend>
        <div class="rq-chips">
          <label v-for="option in TRANSPORT" :key="option" class="rq-chip" :class="{ 'is-selected': form.transport === option }">
            <input v-model="form.transport" type="radio" name="tc-transport" :value="option" />{{ option }}
          </label>
        </div>
      </fieldset>
      <fieldset class="lv-field">
        <legend class="lv-label">Expenses</legend>
        <div class="lv-grid rq-costs">
          <label class="lv-field"><span class="lv-hint">Fuel</span><span class="rq-money"><span>$</span><input v-model.number="form.fuel_cost" type="number" min="0" step="0.01" placeholder="0.00" /></span></label>
          <label class="lv-field"><span class="lv-hint">Tolls</span><span class="rq-money"><span>$</span><input v-model.number="form.tolls" type="number" min="0" step="0.01" placeholder="0.00" /></span></label>
          <label class="lv-field"><span class="lv-hint">Parking</span><span class="rq-money"><span>$</span><input v-model.number="form.parking" type="number" min="0" step="0.01" placeholder="0.00" /></span></label>
          <label class="lv-field"><span class="lv-hint">Other</span><span class="rq-money"><span>$</span><input v-model.number="form.other" type="number" min="0" step="0.01" placeholder="0.00" /></span></label>
        </div>
        <p class="rq-total"><span>Total</span><strong>${{ total.toFixed(2) }}</strong></p>
      </fieldset>
      <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="errors" />
      <footer class="lv-form__foot">
        <span></span>
        <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting...' : 'Submit claim' }}</button>
      </footer>
    </form>
  </div>
</template>
