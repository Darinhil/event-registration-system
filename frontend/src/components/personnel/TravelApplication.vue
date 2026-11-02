<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'

const TRANSPORT = ['Car', 'Motorbike', 'Bus', 'Airplane', 'Other']
const COSTS = [{ key: 'transport', label: 'Transport' }, { key: 'accommodation', label: 'Accommodation' }, { key: 'meals', label: 'Meals' }, { key: 'other', label: 'Other' }]
const blank = () => ({ purpose: '', destination: '', accommodation: '', start_date: '', end_date: '', transport: 'Car', costs: { transport: '', accommodation: '', meals: '', other: '' } })
const form = reactive(blank())
const request = useStaffRequestForm('travel')
const { approvers, approverId, attachment, saving, saved, formError, errors, formEl } = request
const total = computed(() => Object.values(form.costs).reduce((sum, value) => sum + (Number(value) || 0), 0))

const validate = () => {
  const found = {}
  if (!form.purpose.trim()) found.purpose = 'Describe the purpose of this trip.'
  if (!form.destination.trim()) found.destination = 'Enter your destination.'
  if (!form.start_date) found.start_date = 'Choose the first day of the trip.'
  if (!form.end_date) found.end_date = 'Choose the last day of the trip.'
  else if (form.start_date && form.end_date < form.start_date) found.end_date = 'The end date can’t be before the start date.'
  if (!approverId.value) found.approver_id = 'Choose who should approve this request.'
  Object.keys(errors).forEach((key) => delete errors[key])
  Object.assign(errors, found)
  return !Object.keys(found).length
}
const submit = () => { if (validate()) request.submit({ ...form, costs: { ...form.costs } }); else request.focusFirstError() }
const startAnother = () => { Object.assign(form, blank()); request.reset() }
</script>

<template>
  <div class="lv">
    <header class="lv-head"><h1>Travel Application</h1></header>
    <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
    <form v-else ref="formEl" class="lv-card lv-form rq-form" novalidate @submit.prevent="submit">
      <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>
      <div class="lv-field">
        <label for="tr-purpose" class="lv-label">Purpose of travel <span class="lv-req" aria-hidden="true">*</span></label>
        <textarea id="tr-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
        <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
      </div>
      <div class="lv-grid">
        <div class="lv-field">
          <label for="tr-destination" class="lv-label">Destination <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="tr-destination" v-model="form.destination" type="text" class="rq-input" :aria-invalid="errors.destination ? 'true' : undefined" />
          <p v-if="errors.destination" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.destination }}</p>
        </div>
        <div class="lv-field">
          <label for="tr-accommodation" class="lv-label">Accommodation <span class="lv-optional">Optional</span></label>
          <input id="tr-accommodation" v-model="form.accommodation" type="text" class="rq-input" placeholder="Hotel or guesthouse" />
        </div>
        <div class="lv-field">
          <label for="tr-start" class="lv-label">Start date <span class="lv-req" aria-hidden="true">*</span></label>
          <DateInput id="tr-start" v-model="form.start_date" class="rq-input" :aria-invalid="errors.start_date ? 'true' : undefined" />
          <p v-if="errors.start_date" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.start_date }}</p>
        </div>
        <div class="lv-field">
          <label for="tr-end" class="lv-label">End date <span class="lv-req" aria-hidden="true">*</span></label>
          <DateInput id="tr-end" v-model="form.end_date" class="rq-input" :min="form.start_date || undefined" :aria-invalid="errors.end_date ? 'true' : undefined" />
          <p v-if="errors.end_date" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.end_date }}</p>
        </div>
      </div>
      <fieldset class="lv-field">
        <legend class="lv-label">Transportation <span class="lv-req" aria-hidden="true">*</span></legend>
        <div class="rq-chips">
          <label v-for="option in TRANSPORT" :key="option" class="rq-chip" :class="{ 'is-selected': form.transport === option }"><input v-model="form.transport" type="radio" name="tr-transport" :value="option" />{{ option }}</label>
        </div>
      </fieldset>
      <fieldset class="lv-field">
        <legend class="lv-label">Estimated cost</legend>
        <div class="lv-grid rq-costs">
          <label v-for="item in COSTS" :key="item.key" class="lv-field"><span class="lv-hint">{{ item.label }}</span><span class="rq-money"><span>$</span><input v-model.number="form.costs[item.key]" type="number" min="0" step="0.01" placeholder="0.00" /></span></label>
        </div>
        <p class="rq-total"><span>Total</span><strong>${{ total.toFixed(2) }}</strong></p>
      </fieldset>
      <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="errors" />
      <footer class="lv-form__foot">
        <span></span>
        <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit application' }}</button>
      </footer>
    </form>
  </div>
</template>
