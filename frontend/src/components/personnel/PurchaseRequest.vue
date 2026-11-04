<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'

const blankItem = () => ({ description: '', quantity: 1, unit_cost: '' })
const blank = () => ({ purpose: '', department: '', required_date: '', items: [blankItem()], justification: '' })
const form = reactive(blank())
const request = useStaffRequestForm('purchase')
const { approvers, approverId, attachment, saving, saved, formError, errors, formEl } = request
const lineTotal = (item) => (Number(item.quantity) || 0) * (Number(item.unit_cost) || 0)
const total = computed(() => form.items.reduce((sum, item) => sum + lineTotal(item), 0))

const validate = () => {
  const found = {}
  if (!form.purpose.trim()) found.purpose = 'Describe what this purchase is for.'
  form.items.forEach((item, index) => {
    if (!item.description.trim()) found[`items.${index}.description`] = 'Describe the item.'
    if (!(Number(item.quantity) > 0)) found[`items.${index}.quantity`] = 'Enter a quantity.'
    if (item.unit_cost === '' || Number(item.unit_cost) < 0) found[`items.${index}.unit_cost`] = 'Enter the unit cost.'
  })
  if (!approverId.value) found.approver_id = 'Choose who should approve this request.'
  Object.keys(errors).forEach((key) => delete errors[key])
  Object.assign(errors, found)
  return !Object.keys(found).length
}
const submit = () => { if (validate()) request.submit({ ...form, items: form.items.map((item) => ({ ...item })) }); else request.focusFirstError() }
const startAnother = () => { Object.assign(form, blank()); request.reset() }
</script>

<template>
  <div class="lv">
    <header class="lv-head"><h1>Purchase Request</h1></header>
    <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
    <form v-else ref="formEl" class="lv-card lv-form rq-form rq-form--wide" novalidate @submit.prevent="submit">
      <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>
      <div class="lv-field">
        <label for="pr-purpose" class="lv-label">Purpose <span class="lv-req" aria-hidden="true">*</span></label>
        <textarea id="pr-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
        <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
      </div>
      <div class="lv-grid">
        <div class="lv-field">
          <label for="pr-department" class="lv-label">Department <span class="lv-optional">Optional</span></label>
          <input id="pr-department" v-model="form.department" class="rq-input" type="text" />
        </div>
        <div class="lv-field">
          <label for="pr-date" class="lv-label">Needed by <span class="lv-optional">Optional</span></label>
          <DateInput id="pr-date" v-model="form.required_date" class="rq-input" />
        </div>
      </div>
      <div class="rq-table-wrap">
        <table class="rq-table">
          <thead><tr><th scope="col" class="rq-table__grow">Item</th><th scope="col">Quantity</th><th scope="col">Unit cost ($)</th><th scope="col" class="num">Total</th><th scope="col"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            <tr v-for="(item, index) in form.items" :key="index">
              <td><input v-model="item.description" class="rq-input" type="text" :aria-label="`Item ${index + 1} description`" :aria-invalid="errors[`items.${index}.description`] ? 'true' : undefined" :title="errors[`items.${index}.description`]" /></td>
              <td><input v-model.number="item.quantity" class="rq-input" type="number" min="0" step="1" :aria-label="`Item ${index + 1} quantity`" :aria-invalid="errors[`items.${index}.quantity`] ? 'true' : undefined" :title="errors[`items.${index}.quantity`]" /></td>
              <td><input v-model.number="item.unit_cost" class="rq-input" type="number" min="0" step="0.01" :aria-label="`Item ${index + 1} unit cost`" :aria-invalid="errors[`items.${index}.unit_cost`] ? 'true' : undefined" :title="errors[`items.${index}.unit_cost`]" /></td>
              <td class="num">${{ lineTotal(item).toFixed(2) }}</td>
              <td><button v-if="form.items.length > 1" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" :aria-label="`Remove item ${index + 1}`" @click="form.items.splice(index, 1)"><AppIcon name="trash" :size="15" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button type="button" class="lv-btn lv-btn--ghost rq-add" @click="form.items.push(blankItem())"><AppIcon name="plus" :size="16" />Add item</button>
      <p class="rq-total"><span>Total</span><strong>${{ total.toFixed(2) }}</strong></p>
      <div class="lv-field">
        <label for="pr-justification" class="lv-label">Justification <span class="lv-optional">Optional</span></label>
        <textarea id="pr-justification" v-model="form.justification" rows="2"></textarea>
      </div>
      <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="errors" />
      <footer class="lv-form__foot">
        <span></span>
        <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit request' }}</button>
      </footer>
    </form>
  </div>
</template>
