<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'
import lleeLogo from '../../assets/LLEE_Cambodia_Inverse_L.png'

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
  <div class="lv claim-form-page">
    <h1 class="claim-page-title">DSA Claim</h1>
    <div class="claim-sheet">
      <header class="claim-header">
        <div class="claim-brand">
          <img class="claim-brand__logo" :src="lleeLogo" alt="Live & Learn Cambodia logo" />
        </div>
        <div class="claim-title-block">
          <div class="claim-title-block__kh">ប្រាក់ឧបត្ថម្ភបេសកកម្ម</div>
          <h1 class="claim-title">MISSION/TRAVEL CLAIM</h1>
        </div>
      </header>

      <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
      <form v-else ref="formEl" class="rq-form claim-form" novalidate @submit.prevent="submit">
        <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>

        <div class="form-grid form-grid--two">
          <div class="field-block">
            <label for="dsa-traveler-name">Name of traveler <span class="lv-req" aria-hidden="true">*</span></label>
            <input id="dsa-traveler-name" v-model="form.traveler_name" type="text" :aria-invalid="errors.traveler_name ? 'true' : undefined" />
            <p v-if="errors.traveler_name" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.traveler_name }}</p>
          </div>

          <div class="field-block">
            <label for="dsa-position">Position <span class="lv-req" aria-hidden="true">*</span></label>
            <input id="dsa-position" v-model="form.position" type="text" :aria-invalid="errors.position ? 'true' : undefined" />
            <p v-if="errors.position" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.position }}</p>
          </div>
        </div>

        <div class="field-block field-block--full field-block--single">
          <label for="dsa-place">Place of activity <span class="lv-req" aria-hidden="true">*</span></label>
          <input id="dsa-place" v-model="form.place_of_activity" type="text" :aria-invalid="errors.place_of_activity ? 'true' : undefined" />
          <p v-if="errors.place_of_activity" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.place_of_activity }}</p>
        </div>

        <div class="field-block field-block--full field-block--single">
          <label for="dsa-purpose">Purpose of mission <span class="lv-req" aria-hidden="true">*</span></label>
          <textarea id="dsa-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
          <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
        </div>

        <div class="section-title">DSA claim (in USD)</div>
        <div class="rate-note">
          <p>1. National Mission: <strong>39.00 USD</strong></p>
          <p>2. Local Mission (2.1. Sub National Governance: <strong>30.00 USD</strong> and 2.2. Staff and Participants include commune and village authorities: <strong>28.00 USD</strong>)</p>
        </div>

        <div class="detail-grid detail-grid--dsa" aria-label="DSA claim detail fields">
          <div class="detail-head">
            <span>Date<br />(DD-MMM-YY)</span>
            <span>Description<br />(Activity and Location)</span>
            <span>Standard<br />rate</span>
            <span>Deducted<br />amount</span>
            <span>Total<br />amount</span>
            <span>Remarks</span>
          </div>

          <div v-for="n in 8" :key="n" class="detail-row">
            <input type="text" :placeholder="n === 1 ? 'DD-MMM-YY' : ''" />
            <input type="text" />
            <input type="text" />
            <input type="text" />
            <input type="text" value="-" />
            <input type="text" />
          </div>

          <div class="detail-row detail-row--total">
            <div class="detail-total-label">TOTAL</div>
            <div></div>
            <div></div>
            <div></div>
            <input type="text" value="-" />
            <div></div>
          </div>
        </div>

        <div class="section-title">Transportation Claim (in USD)</div>

        <div class="detail-grid detail-grid--transport" aria-label="Transportation claim detail fields">
          <div class="detail-head">
            <span>Date<br />(DD-MMM-YY)</span>
            <span>Description<br />(Type of expense, origin, destination)</span>
            <span>Total amount</span>
            <span>Remarks</span>
          </div>

          <div v-for="n in 5" :key="n" class="detail-row">
            <input type="text" :placeholder="n === 1 ? 'DD-MMM-YY' : ''" />
            <input type="text" />
            <input type="text" value="-" />
            <input type="text" />
          </div>

          <div class="detail-row detail-row--total">
            <div class="detail-total-label">TOTAL</div>
            <div></div>
            <input type="text" value="-" />
            <div></div>
          </div>
        </div>

        <div class="remarks-box">
          <label>Remarks: DSA:</label>
          <textarea rows="3" placeholder=""></textarea>
        </div>

        <div class="certified-box">
          <div class="certified-header">Certified Statement</div>
          <p>I certify that the statements made above are true and correct, and that the expense listed have been incurred by me.</p>

          <div class="signature-row">
            <div class="signature-box">
              <span>Requester</span>
              <div class="signature-line"></div>
              <label>Signature:</label>
              <label>Name:</label>
              <input type="text" />
              <label>Position:</label>
              <input type="text" />
              <label>Date</label>
              <input type="text" />
            </div>

            <div class="signature-box">
              <span>Checker</span>
              <div class="signature-line"></div>
              <label>Signature:</label>
              <label>Name:</label>
              <input type="text" />
              <label>Position:</label>
              <input type="text" />
              <label>Date</label>
              <input type="text" />
            </div>

            <div class="signature-box">
              <span>Approver</span>
              <div class="signature-line"></div>
              <label>Signature:</label>
              <label>Name:</label>
              <input type="text" />
              <label>Position:</label>
              <input type="text" />
              <label>Date</label>
              <input type="text" />
            </div>
          </div>
        </div>

        <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="errors" />

        <footer class="lv-form__foot claim-form-footer">
          <span></span>
          <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit claim' }}</button>
        </footer>
      </form>
    </div>
  </div>
</template>

<style scoped>
.claim-form-page {
  width: 100%;
}

.claim-page-title {
  margin: 0 0 14px;
  color: #17365f;
  font-family: inherit;
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.claim-sheet {
  border: 1px solid #dfe7ee;
  border-radius: 18px;
  background: #ffffff;
  box-shadow: 0 8px 24px rgba(15, 32, 48, 0.04);
  overflow: hidden;
}

.claim-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 18px 16px;
  border-bottom: 1px solid #d9e0e8;
  background: #ffffff;
}

.claim-brand {
  display: flex;
  align-items: center;
  min-width: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
}

.claim-brand__logo {
  display: block;
  width: min(20vw, 260px);
  max-width: 100%;
  height: auto;
  object-fit: contain;
}

.claim-title-block {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: center;
  flex: 1;
  text-align: right;
}

.claim-title-block__kh {
  font-family: "Noto Sans Khmer", "Khmer OS", "Khmer Mondulkiri", serif;
  font-size: clamp(1.2rem, 2vw, 2.4rem);
  line-height: 1.05;
  letter-spacing: 0.02em;
  font-weight: 900;
  color: #111111;
  margin-bottom: 6px;
}

.claim-title {
  margin: 0;
  color: #1d2d3d;
  font-size: clamp(0.85rem, 1.1vw, 1.4rem);
  font-weight: 900;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.claim-form {
  width: 100%;
  max-width: none;
  padding: 0;
  border: 0;
  background: #f9fbfc;
  box-shadow: none;
}

.form-grid {
  display: grid;
  gap: 0;
}

.form-grid--two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field-block {
  display: grid;
  gap: 6px;
  padding: 12px 14px 14px;
  border-right: 1px solid #dfe7ee;
  border-bottom: 1px solid #dfe7ee;
  background: #f9fbfc;
}

.field-block:last-child {
  border-right: 0;
}

.field-block--full {
  border-right: 0;
}

.field-block--single {
  border-top: 0;
}

.field-block label {
  font-size: 0.8rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #1d2d3d;
}

.field-block input,
.field-block textarea,
.detail-row input,
.remarks-box textarea,
.signature-box input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #ccd6df;
  border-radius: 8px;
  background: rgba(255,255,255,.7);
  color: #1d2d3d;
  font: inherit;
  font-size: 1rem;
  min-height: 42px;
  padding: 10px 12px;
}

.field-block textarea,
.remarks-box textarea {
  resize: vertical;
  min-height: 72px;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px 16px 14px;
  border-bottom: 1px solid #dfe7ee;
  font-size: 0.9rem;
  font-weight: 900;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1d2d3d;
  background: #f9fbfc;
}

.section-title::before {
  content: "";
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 2px;
  background: #0c8c77;
}

.rate-note {
  padding: 14px 16px;
  border-bottom: 1px solid #dfe7ee;
  font-size: 0.8rem;
  line-height: 1.6;
  color: #1d2d3d;
  background: #f9fbfc;
}

.rate-note p {
  margin: 0;
}

.detail-grid {
  width: 100%;
  border-bottom: 1px solid #dfe7ee;
  background: #f9fbfc;
}

.detail-head,
.detail-row {
  display: grid;
  grid-template-columns: 1.05fr 2.1fr 1fr 1fr 1.05fr 1fr;
}

.detail-head {
  background: #f9fbfc;
  border-bottom: 1px solid #dfe7ee;
}

.detail-head span {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 68px;
  padding: 8px 8px;
  border-right: 1px solid #dfe7ee;
  text-align: center;
  font-size: 0.75rem;
  font-weight: 900;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #1d2d3d;
}

.detail-head span:last-child {
  border-right: 0;
}

.detail-row {
  border-bottom: 1px solid #dfe7ee;
}

.detail-row:last-child {
  border-bottom: 0;
}

.detail-row > * {
  min-height: 42px;
  border-right: 1px solid #dfe7ee;
}

.detail-row > *:last-child {
  border-right: 0;
}

.detail-row input {
  border: 0;
  border-radius: 0;
  min-height: 48px;
  background: transparent;
  padding: 8px 10px;
  font-size: 0.95rem;
}

.detail-row--total {
  display: grid;
  align-items: stretch;
  background: #f9fbfc;
}

.detail-total-label {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: 900;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1d2d3d;
}

.detail-grid--transport .detail-head,
.detail-grid--transport .detail-row {
  grid-template-columns: 1.05fr 2.6fr 1.05fr 1fr;
}

.remarks-box {
  display: grid;
  gap: 8px;
  padding: 14px 16px;
  border-bottom: 1px solid #dfe7ee;
  background: #f9fbfc;
}

.remarks-box label {
  font-size: 0.8rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #1d2d3d;
}

.certified-box {
  padding: 12px 14px 14px;
  border-bottom: 1px solid #dfe7ee;
  background: #f9fbfc;
}

.certified-header {
  margin-bottom: 10px;
  font-size: 0.8rem;
  font-weight: 900;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1d2d3d;
}

.certified-box p {
  margin: 0 0 18px;
  color: #1d2d3d;
  font-size: 0.72rem;
  line-height: 1.6;
}

.signature-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.signature-box {
  display: grid;
  gap: 6px;
  padding: 10px 12px 12px;
  border: 1px solid #cfd9e2;
  border-radius: 10px;
  min-height: 220px;
  background: #f9fbfc;
}

.signature-box > span {
  display: block;
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1d2d3d;
}

.signature-line {
  min-height: 38px;
  border-bottom: 1px solid #cfd9e2;
  margin-bottom: 8px;
}

.signature-box label {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #1d2d3d;
}

.signature-box input {
  min-height: 30px;
  margin-bottom: 4px;
}

.claim-form-footer {
  padding: 12px 18px 18px;
}

.lv-alert,
.lv-error {
  margin: 0;
}

@media (max-width: 820px) {
  .claim-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .form-grid--two,
  .signature-row {
    grid-template-columns: 1fr;
  }

  .detail-head {
    display: none;
  }

  .detail-grid .detail-row {
    grid-template-columns: 1fr 1fr;
  }

  .detail-row > * {
    border-bottom: 1px solid #dfe7ee;
  }

  .detail-total-label {
    grid-column: 1 / -1;
    min-height: 36px;
  }
}
</style>
