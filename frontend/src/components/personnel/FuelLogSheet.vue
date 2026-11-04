<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const VEHICLES = ['Car-001', 'Car-002', 'Motorbike-001']
const blankRecord = () => ({ date: '', mileage: '', liters: '', cost: '' })
const blank = () => ({ vehicle: VEHICLES[0], month: new Date().getMonth() + 1, year: new Date().getFullYear(), records: [blankRecord()] })
const form = reactive(blank())
const request = useStaffRequestForm('fuel')
const { approvers, approverId, saving, saved, formError, errors, formEl } = request
const sum = (key) => form.records.reduce((total, record) => total + (Number(record[key]) || 0), 0)
const totalLiters = computed(() => sum('liters'))
const totalCost = computed(() => sum('cost'))

const validate = () => {
  const found = {}
  form.records.forEach((record, index) => {
    if (!record.date) found[`records.${index}.date`] = 'Choose a date.'
    if (!(Number(record.liters) > 0)) found[`records.${index}.liters`] = 'Enter the liters.'
    if (record.cost === '' || Number(record.cost) < 0) found[`records.${index}.cost`] = 'Enter the cost.'
  })
  if (!approverId.value) found.approver_id = 'Choose who should approve this request.'
  Object.keys(errors).forEach((key) => delete errors[key])
  Object.assign(errors, found)
  return !Object.keys(found).length
}
const submit = () => { if (validate()) request.submit({ ...form, records: form.records.map((record) => ({ ...record })) }); else request.focusFirstError() }
const startAnother = () => { Object.assign(form, blank()); request.reset() }
</script>

<template>
  <div class="lv">
    <header class="lv-head"><h1>Fuel Log-Sheet</h1></header>
    <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
    <form v-else ref="formEl" class="lv-card lv-form rq-form rq-form--wide" novalidate @submit.prevent="submit">
      <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>
      <div class="lv-grid">
        <div class="lv-field">
          <label for="fl-vehicle" class="lv-label">Vehicle <span class="lv-req" aria-hidden="true">*</span></label>
          <select id="fl-vehicle" v-model="form.vehicle" class="rq-select"><option v-for="vehicle in VEHICLES" :key="vehicle">{{ vehicle }}</option></select>
        </div>
        <div class="lv-field">
          <span class="lv-label">Month <span class="lv-req" aria-hidden="true">*</span></span>
          <span class="rq-month">
            <select v-model="form.month" class="rq-select" aria-label="Month"><option v-for="(month, index) in MONTHS" :key="month" :value="index + 1">{{ month }}</option></select>
            <input v-model.number="form.year" class="rq-input" type="number" min="2000" max="2100" aria-label="Year" />
          </span>
        </div>
      </div>
      <div class="rq-table-wrap">
        <table class="rq-table">
          <thead><tr><th scope="col">Date</th><th scope="col">Mileage (km)</th><th scope="col">Liters</th><th scope="col">Cost ($)</th><th scope="col"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            <tr v-for="(record, index) in form.records" :key="index">
              <td><DateInput v-model="record.date" class="rq-input" :aria-label="`Record ${index + 1} date`" :aria-invalid="errors[`records.${index}.date`] ? 'true' : undefined" :title="errors[`records.${index}.date`]" /></td>
              <td><input v-model.number="record.mileage" class="rq-input" type="number" min="0" step="1" :aria-label="`Record ${index + 1} mileage`" /></td>
              <td><input v-model.number="record.liters" class="rq-input" type="number" min="0" step="0.01" :aria-label="`Record ${index + 1} liters`" :aria-invalid="errors[`records.${index}.liters`] ? 'true' : undefined" :title="errors[`records.${index}.liters`]" /></td>
              <td><input v-model.number="record.cost" class="rq-input" type="number" min="0" step="0.01" :aria-label="`Record ${index + 1} cost`" :aria-invalid="errors[`records.${index}.cost`] ? 'true' : undefined" :title="errors[`records.${index}.cost`]" /></td>
              <td><button v-if="form.records.length > 1" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" :aria-label="`Remove record ${index + 1}`" @click="form.records.splice(index, 1)"><AppIcon name="trash" :size="15" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button type="button" class="lv-btn lv-btn--ghost rq-add" @click="form.records.push(blankRecord())"><AppIcon name="plus" :size="16" />Add record</button>
      <p class="rq-total"><span>Total</span><strong>{{ totalLiters.toFixed(2) }} L · ${{ totalCost.toFixed(2) }}</strong></p>
      <RequestFormParts v-model:approver-id="approverId" :approvers="approvers" :errors="errors" :with-attachment="false" />
      <footer class="lv-form__foot">
        <span></span>
        <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit log-sheet' }}</button>
      </footer>
    </form>
  </div>
</template>
