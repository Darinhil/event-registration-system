<script setup>
import { reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { useStaffRequestForm } from '../../utils/staffRequestForm'
import lleeLogo from '../../assets/LLEE_Cambodia_Inverse_L.png'

const TRANSPORT = ['Office Car', 'Boat/Taxi', 'Airplane', 'Office Motorcycles', 'Public Transportation', 'Other']
const blankStaffMember = () => ({ full_name: '', position: '', destination: '', departure_date: '', return_date: '', remarks: '' })
const blank = () => ({
  purpose: '',
  project_name: '',
  staff_members: Array.from({ length: 3 }, blankStaffMember),
  transportation_methods: [],
  transportation_other: '',
  requester_name: '',
  request_date: '',
  approver_name: '',
  approval_date: '',
})
const form = reactive(blank())
const request = useStaffRequestForm('travel')
const { approvers, approverId, attachment, saving, saved, formError, errors, formEl } = request

const validate = () => {
  const found = {}
  if (!form.purpose.trim()) found.purpose = 'Describe the purpose of this trip.'
  const firstStaffMember = form.staff_members[0]
  if (!firstStaffMember.full_name.trim()) found['staff_members[0].full_name'] = 'Enter the first traveling staff member’s name.'
  if (!firstStaffMember.destination.trim()) found['staff_members[0].destination'] = 'Enter the destination.'
  if (!firstStaffMember.departure_date) found['staff_members[0].departure_date'] = 'Choose the departure date.'
  if (!firstStaffMember.return_date) found['staff_members[0].return_date'] = 'Choose the return date.'
  else if (firstStaffMember.departure_date && firstStaffMember.return_date < firstStaffMember.departure_date) {
    found['staff_members[0].return_date'] = 'The return date can’t be before the departure date.'
  }
  if (!form.transportation_methods.length) found.transportation_methods = 'Select at least one transportation method.'
  if (!approverId.value) found.approver_id = 'Choose who should approve this request.'
  Object.keys(errors).forEach((key) => delete errors[key])
  Object.assign(errors, found)
  return !Object.keys(found).length
}
const submit = () => { if (validate()) request.submit({ ...form, staff_members: form.staff_members.map((member) => ({ ...member })), transportation_methods: [...form.transportation_methods] }); else request.focusFirstError() }
const startAnother = () => { Object.assign(form, blank()); request.reset() }
</script>

<template>
  <div class="lv travel-page">
    <h1 class="travel-page-title">Travel Application</h1>
    <div class="travel-sheet">
      <header class="travel-header">
        <img class="travel-logo" :src="lleeLogo" alt="Live & Learn Cambodia logo" />
        <div class="travel-title-block">
          <h2 class="travel-title">TRAVEL APPLICATION FORM</h2>
          <p>(STAFF TRAVEL REQUEST TEMPLATE)</p>
        </div>
      </header>
      <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />
      <form v-else ref="formEl" class="lv-card lv-form rq-form travel-form" novalidate @submit.prevent="submit">
        <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>

        <div class="travel-field">
          <label for="tr-purpose" class="lv-label">Purpose(s) of the trip <span class="lv-req" aria-hidden="true">*</span></label>
          <textarea id="tr-purpose" v-model="form.purpose" rows="2" :aria-invalid="errors.purpose ? 'true' : undefined"></textarea>
          <p v-if="errors.purpose" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.purpose }}</p>
        </div>

        <div class="travel-field">
          <label for="tr-project" class="lv-label">Project name</label>
          <input id="tr-project" v-model="form.project_name" type="text" />
        </div>

        <section class="travel-staff-section" aria-labelledby="tr-staff-title">
          <h3 id="tr-staff-title" class="travel-section-title">Traveling staff member(s)</h3>
          <div class="travel-staff-grid">
            <div class="travel-staff-head">
              <span>No.</span>
              <span>Full name</span>
              <span>Position</span>
              <span>Destination</span>
              <span>Departure</span>
              <span>Return</span>
              <span>Remarks</span>
            </div>
            <div v-for="(member, index) in form.staff_members" :key="index" class="travel-staff-row">
              <span class="travel-row-number">{{ index + 1 }}</span>
              <div>
                <input v-model="member.full_name" type="text" :aria-label="`Staff member ${index + 1} full name`" :aria-invalid="errors[`staff_members[${index}].full_name`] ? 'true' : undefined" />
                <p v-if="errors[`staff_members[${index}].full_name`]" class="lv-error">{{ errors[`staff_members[${index}].full_name`] }}</p>
              </div>
              <input v-model="member.position" type="text" :aria-label="`Staff member ${index + 1} position`" />
              <div>
                <input v-model="member.destination" type="text" :aria-label="`Staff member ${index + 1} destination`" :aria-invalid="errors[`staff_members[${index}].destination`] ? 'true' : undefined" />
                <p v-if="errors[`staff_members[${index}].destination`]" class="lv-error">{{ errors[`staff_members[${index}].destination`] }}</p>
              </div>
              <div>
                <DateInput v-model="member.departure_date" :aria-label="`Staff member ${index + 1} departure date`" :aria-invalid="errors[`staff_members[${index}].departure_date`] ? 'true' : undefined" />
                <p v-if="errors[`staff_members[${index}].departure_date`]" class="lv-error">{{ errors[`staff_members[${index}].departure_date`] }}</p>
              </div>
              <div>
                <DateInput v-model="member.return_date" :aria-label="`Staff member ${index + 1} return date`" :min="member.departure_date || undefined" :aria-invalid="errors[`staff_members[${index}].return_date`] ? 'true' : undefined" />
                <p v-if="errors[`staff_members[${index}].return_date`]" class="lv-error">{{ errors[`staff_members[${index}].return_date`] }}</p>
              </div>
              <input v-model="member.remarks" type="text" :aria-label="`Staff member ${index + 1} remarks`" />
            </div>
          </div>
        </section>

        <fieldset class="travel-fieldset">
          <legend class="travel-section-title">Mean of transportation <span class="lv-req" aria-hidden="true">*</span></legend>
          <div class="travel-transport-options">
            <label v-for="option in TRANSPORT" :key="option" class="travel-transport-option">
              <input v-model="form.transportation_methods" type="checkbox" :value="option" />
              <span>{{ option }}</span>
              <input v-if="option === 'Other' && form.transportation_methods.includes('Other')" v-model="form.transportation_other" type="text" aria-label="Other transportation method" />
            </label>
          </div>
          <p v-if="errors.transportation_methods" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ errors.transportation_methods }}</p>
        </fieldset>

        <div class="travel-notes">
          <strong>Remarks:</strong>
          <ul>
            <li>Please provide supporting documents, if any.</li>
            <li>This form has to be submitted by the traveling staff and approved by the direct supervisor before the trip commencement.</li>
          </ul>
        </div>

        <section class="travel-signatures" aria-label="Requester and supervisor information">
          <div class="travel-signature">
            <h3>Requested by <small>(Traveling staff)</small></h3>
            <label for="tr-requester-name">Name</label>
            <input id="tr-requester-name" v-model="form.requester_name" type="text" />
            <label for="tr-request-date">Date</label>
            <DateInput id="tr-request-date" v-model="form.request_date" />
          </div>
          <div class="travel-signature">
            <h3>Approved by <small>(Direct supervisor)</small></h3>
            <label for="tr-approver-name">Name</label>
            <input id="tr-approver-name" v-model="form.approver_name" type="text" />
            <label for="tr-approval-date">Date</label>
            <DateInput id="tr-approval-date" v-model="form.approval_date" />
          </div>
        </section>

        <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="errors" />
        <footer class="lv-form__foot">
          <span></span>
          <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit application' }}</button>
        </footer>
      </form>
    </div>
  </div>
</template>

<style scoped>
.travel-page {
  width: 100%;
}

.travel-page-title {
  margin: 0 0 14px;
  color: #17365f;
  font-family: inherit;
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.travel-sheet {
  overflow: hidden;
  border: 1px solid #dfe7ee;
  border-radius: 18px;
  background: #ffffff;
  box-shadow: 0 8px 24px rgba(15, 32, 48, 0.04);
}

.travel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px;
  border-bottom: 1px solid #d9e0e8;
  background: #ffffff;
}

.travel-logo {
  display: block;
  width: min(20vw, 260px);
  max-width: 100%;
  height: auto;
  object-fit: contain;
}

.travel-title {
  margin: 0;
  color: #1d2d3d;
  font-size: clamp(1.1rem, 1.6vw, 1.8rem);
  font-weight: 900;
  letter-spacing: 0.06em;
  text-align: right;
}

.travel-title-block {
  text-align: right;
}

.travel-title-block p {
  margin: 2px 0 0;
  color: #1d2d3d;
  font-size: 0.78rem;
  text-align: right;
}

.travel-form {
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

.travel-form > .travel-field,
.travel-form > .travel-staff-section,
.travel-form > .travel-fieldset,
.travel-form > .travel-notes,
.travel-form > .travel-signatures,
.travel-form > fieldset,
.travel-form > .lv-alert,
.travel-form > .lv-form__foot {
  padding: 14px 16px;
  border-bottom: 1px solid #dfe7ee;
  background: #f9fbfc;
}

.travel-field {
  display: grid;
  gap: 8px;
}

.travel-field .lv-label,
.travel-fieldset .travel-section-title {
  color: #17365f;
  font-size: 0.9rem;
  font-weight: 700;
}

.travel-staff-section {
  min-width: 0;
  overflow-x: auto;
  background: #f9fbfc;
}

.travel-section-title {
  margin: 0 0 12px;
  color: #17365f;
  font-size: 0.9rem;
  font-weight: 800;
  text-transform: uppercase;
}

.travel-staff-grid {
  min-width: 850px;
  border: 1px solid #dfe7ee;
  border-radius: 8px;
  overflow: hidden;
}

.travel-staff-head,
.travel-staff-row {
  display: grid;
  grid-template-columns: 0.45fr 1.5fr 1fr 1.35fr 1.2fr 1.2fr 1.2fr;
}

.travel-staff-head {
  background: #f0f4f7;
  color: #17365f;
  font-size: 0.72rem;
  font-weight: 800;
  text-align: center;
  text-transform: uppercase;
}

.travel-staff-head span,
.travel-staff-row > * {
  min-width: 0;
  padding: 8px;
  border-right: 1px solid #dfe7ee;
  border-bottom: 1px solid #dfe7ee;
}

.travel-staff-head span:last-child,
.travel-staff-row > *:last-child {
  border-right: 0;
}

.travel-staff-row:last-child > * {
  border-bottom: 0;
}

.travel-staff-row {
  min-height: 76px;
}

.travel-row-number {
  display: grid;
  place-items: center;
  color: #647b89;
}

.travel-staff-row input,
.travel-staff-row :deep(input) {
  width: 100%;
  min-width: 0;
  min-height: 38px;
  padding: 6px;
  border: 1px solid #ccd6df;
  border-radius: 6px;
  background: #ffffff;
  font: inherit;
}

.travel-staff-row .lv-error {
  margin: 4px 0 0;
  font-size: 0.68rem;
}

.travel-fieldset {
  min-width: 0;
  margin: 0;
  border-left: 0;
  border-right: 0;
  background: #f9fbfc;
}

.travel-transport-options {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 16px;
}

.travel-transport-option {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  color: #1d2d3d;
  font-size: 0.85rem;
}

.travel-transport-option input[type="checkbox"] {
  width: 16px;
  height: 16px;
  accent-color: #0c8c77;
}

.travel-transport-option input[type="text"] {
  flex: 1;
  min-width: 80px;
}

.travel-notes {
  color: #1d2d3d;
  font-size: 0.82rem;
}

.travel-notes ul {
  margin: 6px 0 0;
  padding-left: 20px;
}

.travel-signatures {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  background: #f9fbfc;
}

.travel-signature {
  display: grid;
  grid-template-columns: 1fr 1.4fr;
  align-items: center;
  gap: 8px 12px;
  padding: 12px;
  border: 1px solid #cfd9e2;
  border-radius: 8px;
  background: #ffffff;
}

.travel-signature h3 {
  grid-column: 1 / -1;
  margin: 0 0 4px;
  color: #17365f;
  font-size: 0.85rem;
  text-transform: uppercase;
}

.travel-signature small {
  display: block;
  font-size: 0.68rem;
  font-weight: 500;
  text-transform: none;
}

.travel-signature label {
  color: #647b89;
  font-size: 0.78rem;
}

.travel-signature input,
.travel-signature :deep(input) {
  width: 100%;
  min-width: 0;
}

.travel-form input:not([type="radio"]):not([type="checkbox"]),
.travel-form textarea,
.travel-form select {
  border-color: #ccd6df;
  border-radius: 8px;
  background: #ffffff;
  font-size: 1rem;
}

.travel-form .lv-form__foot {
  background: #f9fbfc;
}

@media (max-width: 760px) {
  .travel-header {
    align-items: flex-start;
  }

  .travel-logo {
    width: min(34vw, 200px);
  }

  .travel-transport-options,
  .travel-signatures {
    grid-template-columns: 1fr;
  }
}
</style>
