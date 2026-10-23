<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const saving = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const isHydrated = ref(false)
const snapshot = ref('')
const dialogSection = ref('')
const dialogIndex = ref(-1)
const dialogForm = reactive({})
const emptyFamilyInformation = () => ({
  marital_status: '',
  spouse_partner_name: '',
  children: [],
  emergency_contacts: [],
  beneficiaries: [],
})
const form = reactive(emptyFamilyInformation())
const formSnapshot = () => JSON.stringify(form)
const isDirty = computed(() => isHydrated.value && snapshot.value !== formSnapshot())
const under18Count = computed(() => form.children.filter((child) => {
  if (!child.date_of_birth) return false
  const birthDate = new Date(`${child.date_of_birth}T00:00:00`)
  const cutoff = new Date()
  cutoff.setFullYear(cutoff.getFullYear() - 18)
  return !Number.isNaN(birthDate.getTime()) && birthDate > cutoff
}).length)
const totalBenefitShares = computed(() => form.beneficiaries.reduce((total, beneficiary) => total + (Number(beneficiary.share_percent) || 0), 0))
const hasBeneficiaries = computed(() => form.beneficiaries.some((beneficiary) =>
  Object.values(beneficiary).some((value) => String(value ?? '').trim() !== ''),
))
const beneficiaryShareGuidance = computed(() => {
  if (!hasBeneficiaries.value) return 'Beneficiaries are optional; add them only when applicable.'
  const difference = Math.round((100 - totalBenefitShares.value) * 100) / 100
  if (difference === 0) return 'Shares total 100%; you can continue.'
  if (difference > 0) return `Add ${difference}% across beneficiaries to continue, or remove all beneficiary entries if none apply.`
  return `Reduce beneficiary shares by ${Math.abs(difference)}% to continue, or remove all beneficiary entries if none apply.`
})

const sections = [
  {
    key: 'children',
    title: 'Children detail',
    addLabel: 'Add child',
    fields: [
      { key: 'name', label: 'Full name', type: 'text' },
      { key: 'date_of_birth', label: 'Date of birth', type: 'date' },
      { key: 'sex', label: 'Sex', type: 'select', options: ['Male', 'Female', 'Other', 'Prefer not to say'] },
    ],
  },
  {
    key: 'emergency_contacts',
    title: 'Emergency contact',
    addLabel: 'Add emergency contact',
    fields: [
      { key: 'name', label: 'Name', type: 'text' },
      { key: 'relationship', label: 'Relationship', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'tel' },
      { key: 'email', label: 'Email', type: 'email' },
      { key: 'current_address', label: 'Current Address', type: 'textarea' },
    ],
  },
  {
    key: 'beneficiaries',
    title: 'Beneficiaries',
    addLabel: 'Add beneficiary',
    fields: [
      { key: 'full_name', label: 'Full Name', type: 'text', dataType: 'Text' },
      { key: 'date_of_birth', label: 'Date of Birth', type: 'date', dataType: 'Date' },
      { key: 'identity_number', label: 'Number of birth certificate, ID or passport', type: 'text', dataType: 'Number' },
      { key: 'relationship', label: 'Relationship', type: 'text', dataType: 'Text' },
      { key: 'contact_number', label: 'Contact Number', type: 'tel', dataType: 'Number' },
      { key: 'address', label: 'Address', type: 'textarea', dataType: 'Text' },
      { key: 'share_percent', label: 'Share of Benefits (%)', type: 'number', dataType: 'Percentage' },
    ],
  },
]
const familySituationSection = {
  key: 'family_situation',
  title: 'Family Situation',
  fields: [
    { key: 'marital_status', label: 'Marital status', type: 'select', options: ['Married', 'Single'] },
    { key: 'spouse_partner_name', label: 'Spouse/partner name', type: 'text' },
  ],
}

const setFormValues = (saved) => {
  const value = saved || {}
  const defaults = emptyFamilyInformation()
  Object.assign(form, defaults, value)
  for (const section of sections) {
    form[section.key] = (Array.isArray(value[section.key]) ? value[section.key] : [])
      .map((entry) => ({ ...entry }))
  }
  snapshot.value = formSnapshot()
  isHydrated.value = true
}

onMounted(async () => {
  setFormValues(auth.user?.family_information)
  loading.value = true
  try {
    await auth.fetchMe()
    if (!isDirty.value) setFormValues(auth.user?.family_information)
  } catch {
    errorMessage.value = 'Unable to refresh your saved family information. You can still edit and save your changes.'
  } finally {
    loading.value = false
  }
})

watch(form, () => {
  if (isHydrated.value) {
    errorMessage.value = ''
    successMessage.value = ''
  }
}, { deep: true })

const sectionFor = (key) => key === familySituationSection.key
  ? familySituationSection
  : sections.find((section) => section.key === key)
const openEditor = (key, index = -1) => {
  const section = sectionFor(key)
  dialogSection.value = key
  dialogIndex.value = key === familySituationSection.key ? 0 : index
  Object.keys(dialogForm).forEach((field) => delete dialogForm[field])
  const current = key === familySituationSection.key
    ? { marital_status: form.marital_status, spouse_partner_name: form.spouse_partner_name }
    : index < 0
      ? Object.fromEntries(section.fields.map(({ key: field }) => [field, '']))
      : { ...form[key][index] }
  Object.assign(dialogForm, current)
}

const saveEntry = () => {
  const section = sectionFor(dialogSection.value)
  const entry = Object.fromEntries(section.fields.map(({ key }) => [
    key,
    String(dialogForm[key] ?? '').trim(),
  ]))
  if (section.key === 'beneficiaries' && entry.share_percent !== '') {
    const share = Number(entry.share_percent)
    if (!Number.isFinite(share) || share < 0 || share > 100) {
      errorMessage.value = 'Share of benefits must be between 0 and 100.'
      return
    }
  }
  if (section.key === familySituationSection.key) {
    form.marital_status = entry.marital_status
    form.spouse_partner_name = entry.spouse_partner_name
  } else if (dialogIndex.value < 0) form[section.key].push(entry)
  else form[section.key][dialogIndex.value] = entry
  dialogSection.value = ''
}

const deleteEntry = (key, index) => {
  if (key === familySituationSection.key) {
    if (!window.confirm('Delete the marital status and spouse/partner name?')) return
    form.marital_status = ''
    form.spouse_partner_name = ''
    return
  }
  const entry = form[key][index]
  const name = entry.name || entry.full_name || 'this entry'
  if (!window.confirm(`Delete "${name}"?`)) return
  form[key].splice(index, 1)
}

const saveFamilyInformation = async (complete = false) => {
  errorMessage.value = ''
  successMessage.value = ''
  saving.value = true
  const familyInformation = JSON.parse(formSnapshot())
  for (const key of ['children', 'emergency_contacts', 'beneficiaries']) {
    familyInformation[key] = familyInformation[key].filter((entry) =>
      Object.values(entry).some((value) => String(value ?? '').trim() !== ''),
    )
  }
  try {
    await auth.updateProfile({
      family_information: familyInformation,
      family_information_complete: complete,
    })
    setFormValues(auth.user?.family_information)
    if (complete) {
      await router.push('/me/supporting-documents')
    } else {
      successMessage.value = 'Family information has been saved as a draft.'
    }
  } catch (error) {
    const validationMessage = Object.values(error.response?.data?.errors || {}).flat()[0]
    errorMessage.value = validationMessage || error.response?.data?.message || 'Unable to save your family information. Please try again.'
  } finally {
    saving.value = false
  }
}

const confirmExit = () => !isDirty.value || window.confirm('You have unsaved changes. Leave without saving?')
onBeforeRouteLeave(() => confirmExit())
</script>

<template>
  <UserLayout onboarding>
    <div class="personal-info-page">
      <header class="onboarding-topbar">
        <RouterLink class="onboarding-brand" to="/" aria-label="StaffHub home">
          <span class="onboarding-brand-mark">S</span>
          <span><strong>StaffHub</strong><small>ONBOARDING PORTAL</small></span>
        </RouterLink>
        <div class="onboarding-breadcrumb"><span>Workforce</span><span aria-hidden="true">›</span><strong>New Hire Onboarding</strong></div>
        <div class="onboarding-top-actions">
          <span class="onboarding-status"><span></span> IN PROGRESS</span>
          <span class="onboarding-support">ⓘ <span>Support &amp; Help</span></span>
          <RouterLink class="onboarding-avatar" to="/profile" aria-label="Account profile">U</RouterLink>
        </div>
      </header>

      <div class="family-information-shell">
        <nav class="onboarding-steps" aria-label="Onboarding steps">
          <RouterLink class="onboarding-step is-complete" to="/me/personal-information"><span>✓</span> Personal Information</RouterLink>
          <RouterLink class="onboarding-step is-complete" to="/me/professional-qualifications"><span>✓</span> Professional Qualifications</RouterLink>
          <span class="onboarding-step is-active" aria-current="step"><span>3</span> Family Information</span>
          <span class="onboarding-step is-upcoming"><span>4</span> Supporting Documents</span>
          <span class="onboarding-step is-upcoming"><span>5</span> Declaration</span>
        </nav>

        <header class="family-information-heading">
          <div>
            <p class="qualifications-kicker">STEP 3 OF 5 <span>NEW HIRE PROFILE</span></p>
            <h1>Family Information</h1>
            <p>Provide your family situation, emergency contact and beneficiary information.</p>
          </div>
          <div class="onboarding-completion">
            <div><span>Overall Completion</span><strong>60%</strong></div>
            <span class="onboarding-progress"><i></i></span>
            <small>Next: Supporting Documents</small>
          </div>
        </header>

        <div v-if="loading" class="personal-info-loading" role="status">Refreshing your saved family information…</div>
        <div v-if="errorMessage" class="personal-info-alert is-error" role="alert">{{ errorMessage }}</div>
        <div v-if="successMessage" class="personal-info-alert is-success" role="status">{{ successMessage }}</div>

        <form class="family-information-form" @submit.prevent="saveFamilyInformation(true)">
          <section class="family-information-card">
            <header class="family-card-heading"><span aria-hidden="true">♧</span><div><h2>Family Situation</h2><p>Share your current marital status and dependent details.</p></div></header>
            <div class="family-table-wrap">
              <table class="family-table">
                <thead><tr><th>Marital status</th><th>Spouse/partner name</th><th>Number of children (below 18 years old)</th><th>Update</th></tr></thead>
                <tbody>
                  <tr>
                    <td>{{ form.marital_status || '—' }}</td>
                    <td>{{ form.spouse_partner_name || '—' }}</td>
                    <td>{{ under18Count }}</td>
                    <td class="family-row-actions"><button type="button" @click="openEditor(familySituationSection.key)">Edit</button><span aria-hidden="true">|</span><button type="button" class="is-delete" @click="deleteEntry(familySituationSection.key)">Delete</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-for="section in sections" :key="section.key" class="family-information-card">
            <header class="family-card-heading">
              <span aria-hidden="true">▦</span>
              <div><h2>{{ section.title }}</h2><p v-if="section.key === 'beneficiaries'" aria-live="polite">Total shares: {{ totalBenefitShares }}%. {{ beneficiaryShareGuidance }}</p></div>
              <span class="family-entry-count">{{ form[section.key].length }} {{ form[section.key].length === 1 ? 'entry' : 'entries' }}</span>
            </header>
            <div class="family-table-wrap">
              <table class="family-table">
                <thead>
                  <tr v-if="section.key === 'beneficiaries'"><th>Description</th><th>Data Type</th><th>Value</th><th>Update</th></tr>
                  <tr v-else><th v-for="field in section.fields" :key="field.key">{{ field.label }}</th><th>Update</th></tr>
                </thead>
                <tbody>
                  <template v-for="(entry, index) in form[section.key]" :key="index">
                    <tr v-if="section.key === 'beneficiaries'" v-for="field in section.fields" :key="`${index}-${field.key}`">
                      <td>{{ field.label }}</td><td>{{ field.dataType }}</td>
                      <td>{{ entry[field.key] || '—' }}{{ field.key === 'share_percent' && entry[field.key] !== '' ? '%' : '' }}</td>
                      <td v-if="field === section.fields[0]" :rowspan="section.fields.length" class="family-row-actions">
                        <button type="button" @click="openEditor(section.key, index)">Edit</button><span aria-hidden="true">|</span><button type="button" class="is-delete" @click="deleteEntry(section.key, index)">Delete</button>
                      </td>
                    </tr>
                    <tr v-else>
                      <td v-for="field in section.fields" :key="field.key">{{ entry[field.key] || '—' }}</td>
                      <td class="family-row-actions"><button type="button" @click="openEditor(section.key, index)">Edit</button><span aria-hidden="true">|</span><button type="button" class="is-delete" @click="deleteEntry(section.key, index)">Delete</button></td>
                    </tr>
                  </template>
                  <tr v-if="form[section.key].length === 0"><td :colspan="section.key === 'beneficiaries' ? 4 : section.fields.length + 1" class="family-empty">No information added yet.</td></tr>
                </tbody>
              </table>
            </div>
            <button class="family-add-button" type="button" @click="openEditor(section.key)"><span aria-hidden="true">+</span> {{ section.addLabel }}</button>
          </section>

          <footer class="family-information-actions">
            <RouterLink class="qualification-button is-quiet" to="/">⌂ Home</RouterLink>
            <div class="family-action-group">
              <RouterLink class="qualification-button is-quiet" to="/me/professional-qualifications">← Previous</RouterLink>
              <button class="qualification-button is-draft" type="button" :disabled="saving" @click="saveFamilyInformation(false)">{{ saving ? 'Saving…' : '▣ Save' }}</button>
              <button class="qualification-button is-next" type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Next →' }}</button>
              <RouterLink class="qualification-button is-quiet" to="/me">Exit</RouterLink>
            </div>
          </footer>
        </form>
      </div>

      <footer class="onboarding-footer">
        <span><strong>StaffHub Enterprise</strong> · © 2025 StaffHub Human Resources Portal. All rights reserved.</span>
        <nav aria-label="Footer links"><span>Support Desk</span><span>Security &amp; Compliance</span><span>Privacy Notice</span></nav>
      </footer>

      <div v-if="dialogSection" class="family-modal-backdrop" @click.self="dialogSection = ''">
        <section class="family-modal" role="dialog" aria-modal="true" :aria-labelledby="`edit-${dialogSection}-title`">
          <header><div><small>{{ dialogIndex < 0 ? 'ADD ENTRY' : 'EDIT ENTRY' }}</small><h2 :id="`edit-${dialogSection}-title`">{{ dialogIndex < 0 ? sectionFor(dialogSection).addLabel : sectionFor(dialogSection).title }}</h2></div><button type="button" aria-label="Close" @click="dialogSection = ''">×</button></header>
          <form @submit.prevent="saveEntry">
            <label v-for="field in sectionFor(dialogSection).fields" :key="field.key" class="family-modal-field">
              {{ field.label }}
              <select v-if="field.type === 'select'" v-model="dialogForm[field.key]"><option value="">Select status</option><option v-for="option in field.options" :key="option">{{ option }}</option></select>
              <textarea v-else-if="field.type === 'textarea'" v-model="dialogForm[field.key]" rows="3"></textarea>
              <input v-else v-model="dialogForm[field.key]" :type="field.type" :min="field.type === 'number' ? 0 : undefined" :max="field.type === 'number' ? 100 : undefined" :step="field.type === 'number' ? '0.01' : undefined" :maxlength="field.type === 'number' || field.type === 'date' ? undefined : 500" />
            </label>
            <footer><button type="button" class="qualification-button is-quiet" @click="dialogSection = ''">Cancel</button><button type="submit" class="qualification-button is-next">Update</button></footer>
          </form>
        </section>
      </div>
    </div>
  </UserLayout>
</template>

<style scoped>
:global(.shell.onboarding-shell > main){padding:0}
.family-information-shell{width:min(100% - 36px,1200px);margin:0 auto}
.onboarding-steps{display:flex;flex-wrap:wrap;align-items:stretch;margin:8px 0 16px;border-bottom:1px solid #dfe6f2;background:#fff}
.onboarding-step{display:flex;flex:1 1 145px;align-items:center;gap:6px;min-height:34px;padding:9px 12px;border-bottom:2px solid transparent;color:#6e7d97;font-size:9px;text-decoration:none}
.onboarding-step>span{display:grid;width:14px;height:14px;flex:none;place-items:center;border-radius:50%;background:#e7edf7;color:#687994;font-size:8px;font-weight:700}
.onboarding-step.is-active{border-color:#2949d0;color:#243fb1}
.onboarding-step.is-active>span{background:#dfe6ff;color:#304bc4}
.onboarding-step.is-complete{color:#2949c7;text-decoration:none}
.onboarding-step.is-complete>span{background:#2949c7;color:#fff}
.qualifications-kicker{display:flex;width:fit-content;align-items:center;gap:7px;margin:0 0 5px;padding:3px 6px;border-radius:4px;background:#304bc9;color:#fff;font-size:8px;font-weight:800}
.qualifications-kicker span{color:#56698b;background:#fff}
.family-information-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:80px;margin:0 0 12px;padding:8px 12px;border-radius:8px;background:#fff}
.family-information-heading h1{margin:0;color:#152945;font-size:21px;letter-spacing:-.03em}
.family-information-heading>div:first-child>p:last-child{margin:5px 0 0;color:#6e7c95;font-size:10px}
.family-information-heading .onboarding-completion{width:200px;flex:none}
.family-information-heading .onboarding-completion>div{display:flex;justify-content:space-between;color:#687993;font-size:9px}
.family-information-heading .onboarding-completion strong{color:#304bc9;font-size:12px}
.family-information-heading .onboarding-completion small{display:block;margin-top:4px;color:#71809b;font-size:8px}
.family-information-heading .onboarding-progress{display:block;height:5px;margin-top:6px;overflow:hidden;border-radius:99px;background:#e8edf7}
.family-information-heading .onboarding-progress i{display:block;width:60%;height:100%;border-radius:inherit;background:#304bc9}
.family-information-form{display:grid;gap:10px;padding-bottom:8px}
.family-information-card{min-width:0;padding:0 12px 10px;border:1px solid #e7ecf5;border-radius:7px;background:#fff}
.family-card-heading{display:flex;min-height:40px;align-items:center;gap:9px;margin:0 -12px 10px;padding:7px 12px;border-radius:7px 7px 0 0;background:#edf2ff}
.family-card-heading>span:first-child{display:grid;width:23px;height:23px;flex:none;place-items:center;border-radius:6px;background:#fff;color:#3854be;font-size:13px;font-weight:700}
.family-card-heading>div{min-width:0}
.family-card-heading h2{margin:0;color:#1c3152;font-size:12px;font-weight:750}
.family-card-heading p{margin:3px 0 0;color:#71809b;font-size:9px}
.family-entry-count{margin-left:auto;color:#70809a;font-size:9px;white-space:nowrap}
.family-situation-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.family-field,.family-modal-field{display:grid;gap:5px;color:#53657d;font-size:10px;font-weight:650}
.family-field input,.family-field select,.family-modal-field input,.family-modal-field select,.family-modal-field textarea{box-sizing:border-box;width:100%;min-height:35px;padding:7px 9px;border:1px solid #dbe3ef;border-radius:6px;background:#fff;color:#263e59;font:inherit;font-weight:400}
.family-field input[readonly]{background:#f6f8fc;color:#687a93}
.family-table-wrap{overflow-x:auto}
.family-table{width:100%;border-collapse:collapse;font-size:10px}
.family-table th,.family-table td{min-width:100px;padding:8px 7px;border-bottom:1px solid #edf1f6;text-align:left;vertical-align:top}
.family-table th{background:#f8faff;color:#60718c;font-size:9px;font-weight:750;white-space:nowrap}
.family-table th:last-child,.family-table td:last-child{min-width:95px}
.family-table .family-empty{padding:15px;color:#8291a5;text-align:center}
.family-row-actions{white-space:nowrap}
.family-row-actions button{padding:0;border:0;background:none;color:#3155bc;font:inherit;font-size:9px;font-weight:650;cursor:pointer}
.family-row-actions .is-delete{color:#b13d3d}
.family-row-actions span{padding:0 5px;color:#a2adbd}
.family-add-button{display:flex;min-height:32px;align-items:center;gap:7px;margin-top:8px;padding:0 10px;border:1px dashed #bdc9e0;border-radius:6px;background:#fff;color:#3155bc;font:inherit;font-size:10px;font-weight:700;cursor:pointer}
.family-add-button:hover{border-color:#3155bc;background:#f8faff}
.family-add-button span{font-size:16px;line-height:1}
.family-information-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0 4px}
.family-action-group{display:flex;align-items:center;gap:7px;flex-wrap:wrap;justify-content:flex-end}
.qualification-button{display:inline-flex;min-height:34px;align-items:center;justify-content:center;gap:6px;padding:0 11px;border:1px solid #dfe5ef;border-radius:5px;background:#fff;color:#455671;font:inherit;font-size:10px;font-weight:700;text-decoration:none;white-space:nowrap;cursor:pointer}
.qualification-button.is-draft{background:#f7f9ff}
.qualification-button.is-next{border-color:#2f49c4;background:#2f49c4;color:#fff}
.qualification-button:disabled{opacity:.6;cursor:not-allowed}
.family-modal-backdrop{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:16px;background:rgba(15,27,48,.48)}
.family-modal{width:min(100%,620px);max-height:min(90vh,760px);overflow:auto;border-radius:12px;background:#fff;box-shadow:0 20px 60px rgba(11,24,44,.25)}
.family-modal>header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #edf1f6}
.family-modal>header small{color:#5269b9;font-size:9px;font-weight:800;letter-spacing:.08em}
.family-modal>header h2{margin:4px 0 0;color:#1b2f4b;font-size:16px}
.family-modal>header>button{width:30px;height:30px;border:0;border-radius:7px;background:#f2f4f8;color:#52627a;font-size:20px;cursor:pointer}
.family-modal form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:16px 18px}
.family-modal-field:has(textarea){grid-column:1/-1}
.family-modal-field textarea{resize:vertical}
.family-modal form>footer{display:flex;grid-column:1/-1;justify-content:flex-end;gap:8px;padding-top:6px}
.family-information-actions .qualification-button{min-height:34px;padding:0 11px}
@media(max-width:700px){.family-information-shell{width:calc(100% - 20px)}.family-information-heading{align-items:flex-start;flex-direction:column}.family-information-heading .onboarding-completion{width:100%}.family-situation-fields{grid-template-columns:1fr}.family-modal form{grid-template-columns:1fr}.family-modal-field:has(textarea){grid-column:auto}.family-modal form>footer{grid-column:auto}.family-information-actions{align-items:stretch;flex-direction:column}.family-action-group{justify-content:flex-start}}
</style>
