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
const dialogSection = ref('')
const dialogIndex = ref(-1)
const dialogForm = reactive({})

const defaults = () => ({
  education_history: [],
  training_courses: [],
  expertise: [],
  employment_history: [],
  mother_tongue: '',
  other_mother_tongue: '',
  languages: [],
  geographic_experience: [],
  memberships: [],
})

const form = reactive(defaults())
const snapshot = ref('')
const formSnapshot = () => JSON.stringify(form)
const isDirty = computed(() => isHydrated.value && snapshot.value !== formSnapshot())
const staffId = computed(() => auth.user?.id ? `STF-${String(auth.user.id).padStart(6, '0')}` : 'Generated when saved')
const proficiencyLevels = ['Fluent', 'Good', 'Fair', 'Basic']
const motherTongues = ['Khmer', 'English', 'Chinese', 'Vietnamese', 'Other']

const sections = [
  {
    key: 'education_history',
    title: 'Education History',
    addLabel: 'Add education background',
    columns: [
      { key: 'start_date', label: 'Start Date', type: 'date' },
      { key: 'end_date', label: 'End Date', type: 'date' },
      { key: 'institution', label: 'Educational Institution', type: 'text' },
      { key: 'certificate', label: 'Certificate / Diploma', type: 'text' },
      { key: 'major', label: 'Major', type: 'text' },
    ],
  },
  {
    key: 'training_courses',
    title: 'Training Courses',
    addLabel: 'Add training course',
    columns: [
      { key: 'course_title', label: 'Course title', type: 'text' },
      { key: 'start_date', label: 'From', type: 'date' },
      { key: 'end_date', label: 'To', type: 'date' },
      { key: 'provider', label: 'Training Provider', type: 'text' },
    ],
  },
  {
    key: 'expertise',
    title: 'Expertise',
    addLabel: 'Add expertise',
    columns: [{ key: 'value', label: 'Expertise', type: 'text' }],
  },
  {
    key: 'employment_history',
    title: 'Employment History',
    addLabel: 'Add work experience',
    columns: [
      { key: 'start_date', label: 'Start date', type: 'date' },
      { key: 'end_date', label: 'End date', type: 'date' },
      { key: 'position', label: 'Position', type: 'text' },
      { key: 'employer', label: 'Employer', type: 'text' },
      { key: 'project_title', label: 'Project title', type: 'text' },
      { key: 'job_description', label: 'Job description', type: 'textarea' },
    ],
  },
  {
    key: 'languages',
    title: 'Foreign / Second Language Proficiency',
    addLabel: 'Add language',
    columns: [
      { key: 'language', label: 'Language', type: 'text' },
      { key: 'reading', label: 'Reading', type: 'select' },
      { key: 'writing', label: 'Writing', type: 'select' },
      { key: 'speaking', label: 'Speaking', type: 'select' },
      { key: 'understanding', label: 'Understanding', type: 'select' },
    ],
  },
  {
    key: 'geographic_experience',
    title: 'Geographic Experience',
    addLabel: 'Add geography experience',
    columns: [
      { key: 'country', label: 'Country', type: 'text' },
      { key: 'province', label: 'Province', type: 'text' },
    ],
  },
  {
    key: 'memberships',
    title: 'Membership, Association or Affiliation',
    addLabel: 'Add membership',
    columns: [
      { key: 'start_date', label: 'From', type: 'date' },
      { key: 'end_date', label: 'To', type: 'date' },
      { key: 'institution', label: 'Institution', type: 'text' },
      { key: 'role', label: 'Role / Position', type: 'text' },
      { key: 'description', label: 'Description of Work', type: 'textarea' },
    ],
  },
]

const setFormValues = (user) => {
  Object.assign(form, defaults(), user?.professional_qualifications || {})
  form.education_history = [...(user?.professional_qualifications?.education_history || [])]
  form.training_courses = [...(user?.professional_qualifications?.training_courses || [])]
  form.expertise = [...(user?.professional_qualifications?.expertise || [])]
  form.employment_history = [...(user?.professional_qualifications?.employment_history || [])]
  form.languages = [...(user?.professional_qualifications?.languages || [])]
  form.geographic_experience = [...(user?.professional_qualifications?.geographic_experience || [])]
  form.memberships = [...(user?.professional_qualifications?.memberships || [])]
  snapshot.value = formSnapshot()
  isHydrated.value = true
}

onMounted(async () => {
  setFormValues(auth.user)
  loading.value = true
  try {
    await auth.fetchMe()
    if (!isDirty.value) setFormValues(auth.user)
  } catch {
    errorMessage.value = 'Unable to refresh your saved qualifications. You can still edit and save your changes.'
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

const sectionFor = (key) => sections.find((section) => section.key === key)
const defaultEntry = (key) => Object.fromEntries(sectionFor(key).columns.map(({ key: field }) => [field, '']))

const openEditor = (key, index = -1) => {
  dialogSection.value = key
  dialogIndex.value = index
  const current = index < 0
    ? defaultEntry(key)
    : key === 'expertise'
      ? { value: form.expertise[index] }
      : { ...form[key][index] }
  Object.keys(dialogForm).forEach((field) => delete dialogForm[field])
  Object.assign(dialogForm, current)
}

const saveEntry = () => {
  const key = dialogSection.value
  if (key === 'expertise') {
    const value = dialogForm.value?.trim()
    if (!value) {
      errorMessage.value = 'Enter an expertise before adding it.'
      return
    }
    if (dialogIndex.value < 0) form.expertise.push(value)
    else form.expertise[dialogIndex.value] = value
  } else {
    const entry = Object.fromEntries(sectionFor(key).columns.map(({ key: field }) => [field, String(dialogForm[field] || '').trim()]))
    if (dialogIndex.value < 0) form[key].push(entry)
    else form[key][dialogIndex.value] = entry
  }
  dialogSection.value = ''
}

const deleteEntry = (key, index) => {
  const entry = key === 'expertise' ? form.expertise[index] : form[key][index]
  const label = key === 'expertise'
    ? entry
    : entry.institution || entry.course_title || entry.position || entry.language || entry.country || sectionFor(key).title.toLowerCase()
  if (!window.confirm(`Delete "${label}"?`)) return
  form[key].splice(index, 1)
}

const saveQualifications = async (complete = false) => {
  errorMessage.value = ''
  successMessage.value = ''
  saving.value = true
  try {
    await auth.updateProfile({
      professional_qualifications: JSON.parse(formSnapshot()),
      professional_qualifications_complete: complete,
    })
    snapshot.value = formSnapshot()
    successMessage.value = complete
      ? 'Professional qualifications have been saved.'
      : 'Professional qualifications have been saved as a draft.'
    if (complete) await router.push('/me/family-information')
  } catch (error) {
    const validationMessage = Object.values(error.response?.data?.errors || {}).flat()[0]
    errorMessage.value = validationMessage || error.response?.data?.message || 'Unable to save your qualifications. Please try again.'
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

      <div class="qualifications-shell">
        <nav class="onboarding-steps" aria-label="Onboarding steps">
          <RouterLink class="onboarding-step is-complete" to="/me/personal-information"><span>✓</span> Personal Information</RouterLink>
          <span class="onboarding-step is-active" aria-current="step"><span>2</span> Professional Qualifications</span>
          <span class="onboarding-step is-upcoming"><span>3</span> Family Information</span>
          <span class="onboarding-step is-upcoming"><span>4</span> Supporting Documents</span>
          <span class="onboarding-step is-upcoming"><span>5</span> Declaration</span>
        </nav>

        <header class="qualifications-heading">
          <div>
            <p class="qualifications-kicker">STEP 2 OF 5 <span>NEW HIRE PROFILE</span></p>
            <h1>Professional Qualifications</h1>
            <p>Add your education, work experience, training and language skills to complete your employee profile.</p>
          </div>
          <div class="onboarding-completion">
            <div><span>Overall Completion</span><strong>40%</strong></div>
            <span class="onboarding-progress"><i></i></span>
            <small>Next: Family Information</small>
          </div>
        </header>

        <div v-if="loading" class="personal-info-loading" role="status">Refreshing your saved qualifications…</div>
        <div v-if="errorMessage" class="personal-info-alert is-error" role="alert">{{ errorMessage }}</div>
        <div v-if="successMessage" class="personal-info-alert is-success" role="status">{{ successMessage }}</div>

        <form class="qualifications-form" @submit.prevent="saveQualifications(true)">
          <section v-for="section in sections.slice(0, 4)" :key="section.key" class="qualification-card">
            <header class="qualification-card-heading">
              <span class="qualification-icon" aria-hidden="true">▦</span>
              <h2>{{ section.title }}</h2>
              <span class="qualification-count">{{ form[section.key].length }} {{ form[section.key].length === 1 ? 'entry' : 'entries' }}</span>
            </header>

            <div class="qualification-table-wrap">
              <table class="qualification-table">
                <thead>
                  <tr>
                    <th v-for="column in section.columns" :key="column.key">{{ column.label }}</th>
                    <th class="qualification-update-heading">Update</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(entry, index) in form[section.key]" :key="index">
                    <td v-for="column in section.columns" :key="column.key">
                      {{ section.key === 'expertise' ? entry : entry[column.key] || '—' }}
                    </td>
                    <td class="qualification-row-actions">
                      <button type="button" @click="openEditor(section.key, index)">Edit</button>
                      <span aria-hidden="true">|</span>
                      <button type="button" class="is-delete" @click="deleteEntry(section.key, index)">Delete</button>
                    </td>
                  </tr>
                  <tr v-if="form[section.key].length === 0">
                    <td :colspan="section.columns.length + 1" class="qualification-empty">No information added yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <button class="qualification-add-button" type="button" @click="openEditor(section.key)">
              <span aria-hidden="true">+</span> {{ section.addLabel }}
            </button>
          </section>

          <section class="qualification-card mother-tongue-card">
            <header class="qualification-card-heading">
              <span class="qualification-icon" aria-hidden="true">文</span>
              <div><h2>Mother tongue language</h2><p>Select your primary language.</p></div>
            </header>
            <label class="qualification-field">
              Language <span>*</span>
              <select v-model="form.mother_tongue" required>
                <option value="">Select language</option>
                <option v-for="language in motherTongues" :key="language" :value="language">{{ language }}</option>
              </select>
            </label>
            <label v-if="form.mother_tongue === 'Other'" class="qualification-field other-language-field">
              Specify language <span>*</span>
              <input v-model.trim="form.other_mother_tongue" maxlength="100" required />
            </label>
          </section>

          <section v-for="section in sections.slice(4)" :key="section.key" class="qualification-card">
            <header class="qualification-card-heading">
              <span class="qualification-icon" aria-hidden="true">▦</span>
              <h2>{{ section.title }}</h2>
              <span class="qualification-count">{{ form[section.key].length }} {{ form[section.key].length === 1 ? 'entry' : 'entries' }}</span>
            </header>
            <div class="qualification-table-wrap">
              <table class="qualification-table">
                <thead><tr><th v-for="column in section.columns" :key="column.key">{{ column.label }}</th><th class="qualification-update-heading">Update</th></tr></thead>
                <tbody>
                  <tr v-for="(entry, index) in form[section.key]" :key="index">
                    <td v-for="column in section.columns" :key="column.key">{{ entry[column.key] || '—' }}</td>
                    <td class="qualification-row-actions"><button type="button" @click="openEditor(section.key, index)">Edit</button><span aria-hidden="true">|</span><button type="button" class="is-delete" @click="deleteEntry(section.key, index)">Delete</button></td>
                  </tr>
                  <tr v-if="form[section.key].length === 0"><td :colspan="section.columns.length + 1" class="qualification-empty">No information added yet.</td></tr>
                </tbody>
              </table>
            </div>
            <button class="qualification-add-button" type="button" @click="openEditor(section.key)"><span aria-hidden="true">+</span> {{ section.addLabel }}</button>
          </section>

          <footer class="qualifications-actions">
            <RouterLink class="qualification-button is-quiet" to="/">⌂ Home</RouterLink>
            <div class="qualification-action-group">
              <RouterLink class="qualification-button is-quiet" to="/me/personal-information">← Previous</RouterLink>
              <button class="qualification-button is-draft" type="button" :disabled="saving" @click="saveQualifications(false)">
                {{ saving ? 'Saving…' : '▣ Save' }}
              </button>
              <button class="qualification-button is-next" type="submit" :disabled="saving">
                {{ saving ? 'Saving…' : 'Next: Family Information →' }}
              </button>
              <RouterLink class="qualification-button is-quiet" to="/me">Exit</RouterLink>
            </div>
          </footer>
        </form>
      </div>

      <footer class="onboarding-footer">
        <span><strong>StaffHub Enterprise</strong> · © 2025 StaffHub Human Resources Portal. All rights reserved.</span>
        <nav aria-label="Footer links"><span>Support Desk</span><span>Security &amp; Compliance</span><span>Privacy Notice</span></nav>
      </footer>

      <div v-if="dialogSection" class="qualification-modal-backdrop" @click.self="dialogSection = ''">
        <section class="qualification-modal" role="dialog" aria-modal="true" :aria-labelledby="`edit-${dialogSection}-title`">
          <header><div><small>{{ dialogIndex < 0 ? 'ADD ENTRY' : 'EDIT ENTRY' }}</small><h2 :id="`edit-${dialogSection}-title`">{{ dialogIndex < 0 ? sectionFor(dialogSection).addLabel : sectionFor(dialogSection).title }}</h2></div><button type="button" aria-label="Close" @click="dialogSection = ''">×</button></header>
          <form @submit.prevent="saveEntry">
            <label v-for="field in sectionFor(dialogSection).columns" :key="field.key" class="qualification-field">
              {{ field.label }}
              <select v-if="field.type === 'select'" v-model="dialogForm[field.key]">
                <option value="">Select level</option>
                <option v-for="level in proficiencyLevels" :key="level" :value="level">{{ level }}</option>
              </select>
              <textarea v-else-if="field.type === 'textarea'" v-model="dialogForm[field.key]" rows="3"></textarea>
              <input v-else v-model="dialogForm[field.key]" :type="field.type" :maxlength="field.type === 'date' ? undefined : 2000" />
            </label>
            <footer><button type="button" class="qualification-button is-quiet" @click="dialogSection = ''">Cancel</button><button type="submit" class="qualification-button is-next">Update</button></footer>
          </form>
        </section>
      </div>
    </div>
  </UserLayout>
</template>

<style scoped>
:global(.shell.onboarding-shell > main) { padding: 0; }
.qualifications-shell { width: min(100% - 36px, 1200px); margin: 0 auto; }
.onboarding-step.is-complete { color: #2949c7; text-decoration: none; }
.onboarding-step.is-complete > span { background: #2949c7; color: #fff; }
.qualifications-heading { display: flex; align-items: center; justify-content: space-between; gap: 20px; min-height: 80px; margin: 0 0 12px; padding: 8px 12px; border-radius: 8px; background: #fff; }
.qualifications-kicker { display: flex; align-items: center; gap: 7px; margin: 0 0 5px; color: #fff; font-size: 8px; font-weight: 800; }
.qualifications-kicker::first-line { color: #fff; }
.qualifications-kicker { width: fit-content; padding: 3px 6px; border-radius: 4px; background: #304bc9; }
.qualifications-kicker span { color: #56698b; background: #fff; }
.qualifications-heading h1 { margin: 0; color: #152945; font-size: 21px; letter-spacing: -.03em; }
.qualifications-heading > div:first-child > p:last-child { margin: 5px 0 0; color: #6e7c95; font-size: 10px; }
.qualifications-form { display: grid; gap: 10px; padding-bottom: 8px; }
.qualification-card { min-width: 0; padding: 0 12px 10px; border: 1px solid #e7ecf5; border-radius: 7px; background: #fff; }
.qualification-card-heading { display: flex; min-height: 35px; align-items: center; gap: 8px; margin: 0 -12px 7px; padding: 6px 11px; border-radius: 7px 7px 0 0; background: #edf2ff; }
.qualification-icon { display: grid; width: 20px; height: 20px; flex: 0 0 20px; place-items: center; border-radius: 4px; background: #dce6ff; color: #2948ce; font-size: 10px; }
.qualification-card-heading h2 { margin: 0; color: #1c3152; font-size: 10px; font-weight: 750; }
.qualification-card-heading p { margin: 3px 0 0; color: #71809b; font-size: 8px; }
.qualification-count { margin-left: auto; color: #75839a; font-size: 8px; }
.qualification-table-wrap { overflow-x: auto; }
.qualification-table { width: 100%; border-collapse: collapse; color: #455571; font-size: 8px; text-align: left; }
.qualification-table th { padding: 6px 7px; border-bottom: 1px solid #e9edf5; color: #75839b; font-size: 7px; font-weight: 750; white-space: nowrap; }
.qualification-table td { max-width: 220px; padding: 7px; border-bottom: 1px solid #f0f2f7; overflow-wrap: anywhere; }
.qualification-table tr:last-child td { border-bottom: 0; }
.qualification-update-heading { text-align: right; }
.qualification-row-actions { text-align: right; white-space: nowrap; }
.qualification-row-actions button { padding: 0 3px; border: 0; background: none; color: #3150c2; font: inherit; cursor: pointer; }
.qualification-row-actions button.is-delete { color: #bd4a50; }
.qualification-empty { color: #97a2b5; text-align: center; }
.qualification-add-button { display: inline-flex; min-height: 27px; align-items: center; gap: 6px; margin-top: 6px; padding: 0 9px; border: 1px dashed #bbc8e3; border-radius: 4px; background: #fff; color: #334d8a; font: inherit; font-size: 8px; font-weight: 650; cursor: pointer; }
.qualification-add-button > span { font-size: 14px; line-height: 1; }
.mother-tongue-card { padding-bottom: 12px; }
.qualification-field { display: grid; gap: 5px; color: #354766; font-size: 9px; font-weight: 650; }
.qualification-field > span { color: #d84d54; }
.qualification-field input, .qualification-field select, .qualification-field textarea { box-sizing: border-box; width: 100%; min-width: 0; min-height: 31px; padding: 6px 9px; border: 1px solid #dfe5ef; border-radius: 5px; background: #fff; color: #344762; font: inherit; font-size: 10px; }
.qualification-field textarea { resize: vertical; }
.mother-tongue-card > .qualification-field { max-width: 390px; }
.mother-tongue-card > .other-language-field { margin-top: 10px; }
.qualifications-actions { position: fixed; z-index: 5; right: 0; bottom: 0; left: 0; display: flex; min-height: 48px; align-items: center; justify-content: space-between; gap: 10px; padding: 6px max(18px, calc((100vw - 1200px) / 2)); border-top: 1px solid #e5eaf3; background: rgba(255,255,255,.97); box-shadow: 0 -5px 14px rgba(22, 43, 80, .06); }
.qualification-action-group { display: flex; align-items: center; gap: 7px; }
.qualification-button { display: inline-flex; min-height: 30px; align-items: center; justify-content: center; gap: 6px; padding: 0 10px; border: 1px solid #dfe5ef; border-radius: 4px; background: #fff; color: #455671; font: inherit; font-size: 8px; font-weight: 700; text-decoration: none; white-space: nowrap; cursor: pointer; }
.qualification-button.is-draft { background: #f7f9ff; }
.qualification-button.is-next { border-color: #2f49c4; background: #2f49c4; color: #fff; }
.qualification-button:disabled { opacity: .6; cursor: not-allowed; }
.qualification-modal-backdrop { position: fixed; z-index: 10; inset: 0; display: grid; place-items: center; padding: 18px; background: rgba(17, 31, 55, .4); }
.qualification-modal { width: min(100%, 560px); max-height: min(90vh, 760px); overflow-y: auto; padding: 16px; border: 1px solid #e5eaf3; border-radius: 9px; background: #fff; box-shadow: 0 18px 60px rgba(17, 31, 55, .2); }
.qualification-modal > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 15px; }
.qualification-modal > header small { color: #5168c8; font-size: 8px; font-weight: 800; letter-spacing: .08em; }
.qualification-modal h2 { margin: 4px 0 0; color: #1c3152; font-size: 17px; }
.qualification-modal > header button { width: 28px; height: 28px; border: 0; border-radius: 5px; background: #f1f4fa; color: #53627b; font-size: 19px; cursor: pointer; }
.qualification-modal form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.qualification-modal .qualification-field:has(textarea) { grid-column: 1 / -1; }
.qualification-modal form > footer { display: flex; justify-content: flex-end; gap: 8px; grid-column: 1 / -1; margin-top: 4px; }
.onboarding-footer { padding-top: 8px; padding-bottom: 8px; }
@media (max-width: 700px) {
  .qualifications-shell { width: calc(100% - 20px); }
  .qualifications-heading { align-items: center; }
  .qualification-table { min-width: 620px; }
  .qualifications-actions { flex-direction: row; align-items: center; padding-inline: 10px; }
  .qualification-action-group { display: flex; align-items: center; }
  .qualification-button { padding-inline: 7px; font-size: 7px; }
  .onboarding-footer { padding-inline: 10px; }
}
@media (max-width: 440px) {
  .qualifications-heading { align-items: flex-start; flex-direction: column; }
  .qualifications-heading .onboarding-completion { width: 100%; }
  .qualifications-actions { gap: 4px; padding-inline: 6px; }
  .qualification-action-group { gap: 4px; }
  .qualification-button { min-height: 28px; padding-inline: 5px; font-size: 6px; }
  .qualification-modal form { grid-template-columns: 1fr; }
  .qualification-modal .qualification-field:has(textarea) { grid-column: auto; }
  .onboarding-footer nav { flex-wrap: wrap; }
}
</style>
