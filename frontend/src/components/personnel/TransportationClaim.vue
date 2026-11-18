<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'
import lleeLogo from '../../assets/LLEE_Cambodia_Inverse_L.png'

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
  <div class="lv tc-page">
    <h1 class="tc-page-title">Transportation Claims</h1>
    <div class="tc-sheet">
      <header class="tc-header">
        <img class="tc-logo" :src="lleeLogo" alt="Live & Learn Cambodia logo" />
      </header>
      <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
      <form v-else ref="formEl" class="lv-form rq-form tc-form" novalidate @submit.prevent="submit">
      <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>
      <div class="lv-field">
        <label for="tc-purpose" class="lv-label">Purpose <span class="lv-req" aria-hidden="true">*</span></label>
        <textarea id="tc-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
        <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
      </div>
      <div class="tc-grid">
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
  </div>
</template>

<style scoped>
.tc-page {
  width: 100%;
}

.tc-page-title {
  margin: 0 0 14px;
  color: #17365f;
  font-family: inherit;
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.tc-sheet {
  overflow: hidden;
  border: 1px solid #dfe7ee;
  border-radius: 18px;
  background: #ffffff;
  box-shadow: 0 8px 24px rgba(15, 32, 48, 0.04);
}

.tc-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px;
  border-bottom: 1px solid #d9e0e8;
  background: #ffffff;
}

.tc-logo {
  display: block;
  width: min(20vw, 260px);
  max-width: 100%;
  height: auto;
  object-fit: contain;
}

.tc-form {
  display: grid;
  width: 100%;
  max-width: none;
  gap: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: #f9fbfc;
  box-shadow: none;
}

.tc-form > .lv-field,
.tc-form > .tc-grid,
.tc-form > fieldset,
.tc-form > .lv-alert,
.tc-form > :deep(.request-form-parts),
.tc-form > .lv-form__foot {
  padding: 14px 16px;
  border-bottom: 1px solid #dfe7ee;
}

.tc-form > .lv-field {
  background: #f9fbfc;
}

.tc-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px 16px;
  background: #f9fbfc;
}

.tc-grid .lv-field {
  min-width: 0;
}

.tc-form input:not([type="radio"]),
.tc-form textarea,
.tc-form select {
  border-color: #ccd6df;
  border-radius: 8px;
  background: #ffffff;
  font-size: 1rem;
}

.tc-form fieldset {
  min-width: 0;
  margin: 0;
  border: 0;
  background: #f9fbfc;
}

.tc-form .rq-costs {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.tc-form .rq-total {
  margin-bottom: 0;
}

.tc-form .lv-form__foot {
  background: #f9fbfc;
}

@media (max-width: 760px) {
  .tc-header {
    align-items: flex-start;
  }

  .tc-logo {
    width: min(34vw, 200px);
  }

  .tc-grid,
  .tc-form .rq-costs {
    grid-template-columns: 1fr;
  }
}
</style>
