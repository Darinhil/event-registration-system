<script setup>
import { computed, nextTick, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import ProfileField from './ProfileField.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

/* ---------- Record ---------- */
// The personnel portal has no API yet. The only details "on record" are the ones
// on the sign-in account; those are locked and changed through a request to HR.
const nameParts = String(auth.user?.name || '').trim().split(/\s+/).filter(Boolean)
const record = { firstName: nameParts[0] || '', surname: nameParts.slice(1).join(' ') }
const isLocked = (key) => Boolean(record[key])
const staffId = auth.user?.id ? `ST-${String(auth.user.id).padStart(5, '0')}` : '—'

const emptyContact = () => ({ name: '', relationship: '', phone: '', email: '', address: '' })
const form = reactive({
  firstName: record.firstName, surname: record.surname, khmerName: '', gender: '', dob: '', birthPlace: '', citizenship: '', idNumber: '',
  phone: auth.user?.phone || '', altPhone: '', email: auth.user?.email || '', address: '',
  education: [{ degree: '', institution: '', year: '' }],
  experience: [],
  maritalStatus: '', spouse: '', children: [], contacts: [emptyContact()], beneficiaries: [],
  documents: {},
  declarations: { accurate: false, updates: false, consent: false }, signature: '',
})

const basicFields = [
  { key: 'firstName', label: 'First name', required: true, autocomplete: 'given-name' },
  { key: 'surname', label: 'Surname', required: true, autocomplete: 'family-name' },
  { key: 'khmerName', label: 'Name in Khmer', hint: 'ឈ្មោះជាភាសាខ្មែរ', lang: 'km' },
  { key: 'gender', label: 'Gender', required: true, type: 'select', options: ['Female', 'Male', 'Prefer not to say'] },
  { key: 'dob', label: 'Date of birth', required: true, type: 'date', autocomplete: 'bday' },
  { key: 'birthPlace', label: 'Place of birth', required: true },
  { key: 'citizenship', label: 'Citizenship', hint: 'Only if you are not a Cambodian citizen' },
  { key: 'idNumber', label: 'National ID or passport number', required: true },
]
const contactFields = [
  { key: 'phone', label: 'Primary phone', required: true, type: 'tel', autocomplete: 'tel' },
  { key: 'altPhone', label: 'Alternate phone', type: 'tel' },
  { key: 'email', label: 'Email address', required: true, type: 'email', autocomplete: 'email' },
  { key: 'address', label: 'Current address', autocomplete: 'street-address', wide: true },
]
const documentSlots = [
  { key: 'id', label: 'National ID or passport', hint: 'A clear copy of both sides', required: true },
  { key: 'cv', label: 'Curriculum vitae (CV)', hint: 'Your most recent CV', required: true },
  { key: 'certificate', label: 'Highest qualification', hint: 'Degree or diploma certificate' },
  { key: 'bank', label: 'Bank account details', hint: 'For salary payments' },
]
const declarationItems = [
  { key: 'accurate', text: 'The information in my profile is true and complete to the best of my knowledge.' },
  { key: 'updates', text: 'I will tell HR promptly if any of these details change.' },
  { key: 'consent', text: 'I consent to Live & Learn Cambodia keeping and using this information for employment purposes.' },
]

const steps = [
  { title: 'Personal information', short: 'qualifications', icon: 'user', hint: 'Identity and contact details', description: 'Your legal name, identity and how we can reach you.' },
  { title: 'Professional qualifications', short: 'family', icon: 'graduation', hint: 'Education and experience', description: 'Your education and previous work experience.' },
  { title: 'Family information', short: 'documents', icon: 'users', hint: 'Family, emergency contacts', description: 'Who to contact in an emergency, your dependants and beneficiaries.' },
  { title: 'Supporting documents', short: 'declarations', icon: 'file-text', hint: 'ID, CV and certificates', description: 'Upload copies of your documents. PDF, JPG or PNG, up to 5 MB each.' },
  { title: 'Declarations', icon: 'shield-check', hint: 'Review and confirm', description: 'Check your details, then confirm and sign.' },
]

/* ---------- Validation ---------- */
const PHONE_RE = /^[+]?[0-9][0-9\s().-]{7,24}$/
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const today = new Date().toISOString().slice(0, 10)
const thisYear = new Date().getFullYear()
const filled = (value) => String(value ?? '').trim() !== ''
// Lower-case only the first letter so acronyms survive: "National ID" -> "national ID".
const lower = (text) => text.charAt(0).toLowerCase() + text.slice(1)

const errorsFor = (index) => {
  const e = {}
  const need = (key, value, message) => { if (!filled(value)) e[key] = message }
  if (index === 0) {
    for (const field of [...basicFields, ...contactFields]) if (field.required) need(field.key, form[field.key], field.type === 'select' ? `Choose your ${lower(field.label)}.` : `Enter your ${lower(field.label)}.`)
    if (filled(form.dob) && form.dob > today) e.dob = 'Date of birth can’t be in the future.'
    if (filled(form.phone) && !PHONE_RE.test(form.phone)) e.phone = 'Enter a valid phone number, e.g. 012 345 678.'
    if (filled(form.altPhone) && !PHONE_RE.test(form.altPhone)) e.altPhone = 'Enter a valid phone number, e.g. 012 345 678.'
    if (filled(form.email) && !EMAIL_RE.test(form.email)) e.email = 'Enter a valid email address, e.g. name@example.com.'
  }
  if (index === 1) {
    form.education.forEach((row, i) => {
      need(`education.${i}.degree`, row.degree, 'Enter the qualification.')
      need(`education.${i}.institution`, row.institution, 'Enter the institution.')
      if (filled(row.year) && (Number(row.year) < 1950 || Number(row.year) > thisYear + 6)) e[`education.${i}.year`] = `Enter a year between 1950 and ${thisYear + 6}.`
    })
    form.experience.forEach((row, i) => {
      need(`experience.${i}.organisation`, row.organisation, 'Enter the organisation.')
      need(`experience.${i}.position`, row.position, 'Enter your position.')
      if (filled(row.from) && filled(row.to) && row.to < row.from) e[`experience.${i}.to`] = 'End must be after the start.'
    })
  }
  if (index === 2) {
    if (form.maritalStatus === 'Married') need('spouse', form.spouse, 'Enter your spouse or partner’s name.')
    form.children.forEach((row, i) => {
      need(`children.${i}.name`, row.name, 'Enter the child’s name.')
      if (filled(row.dob) && row.dob > today) e[`children.${i}.dob`] = 'Date can’t be in the future.'
    })
    form.contacts.forEach((row, i) => {
      need(`contacts.${i}.name`, row.name, 'Enter a name.')
      need(`contacts.${i}.relationship`, row.relationship, 'Enter the relationship.')
      need(`contacts.${i}.phone`, row.phone, 'Enter a phone number.')
      if (filled(row.phone) && !PHONE_RE.test(row.phone)) e[`contacts.${i}.phone`] = 'Enter a valid phone number.'
      if (filled(row.email) && !EMAIL_RE.test(row.email)) e[`contacts.${i}.email`] = 'Enter a valid email address.'
    })
    form.beneficiaries.forEach((row, i) => {
      need(`beneficiaries.${i}.name`, row.name, 'Enter a name.')
      need(`beneficiaries.${i}.share`, row.share, 'Enter a share.')
    })
    if (form.beneficiaries.length && shareTotal.value !== 100) e.shares = `Shares must add up to 100% (currently ${shareTotal.value}%).`
  }
  if (index === 3) {
    for (const slot of documentSlots) if (slot.required && !form.documents[slot.key]) e[`doc.${slot.key}`] = `Upload your ${lower(slot.label)}.`
    Object.assign(e, fileErrors)
  }
  if (index === 4) {
    for (const item of declarationItems) if (!form.declarations[item.key]) e[`decl.${item.key}`] = 'Tick this box to confirm.'
    need('signature', form.signature, 'Type your full name to sign.')
  }
  return e
}
const stepErrors = computed(() => steps.map((_, index) => errorsFor(index)))

/* ---------- Steps ---------- */
const step = ref(0)
const attempted = reactive(steps.map(() => false)) // errors show once the person tries to move on
const visited = reactive(steps.map((_, index) => index === 0))
const finished = ref(false)
const formEl = ref(null)
const stepHeading = ref(null)
const current = computed(() => steps[step.value])
const errorCount = (index) => Object.keys(stepErrors.value[index]).length
const isDone = (index) => visited[index] && index !== step.value && errorCount(index) === 0
const hasProblem = (index) => attempted[index] && errorCount(index) > 0
const completedCount = computed(() => steps.filter((_, index) => (visited[index] || attempted[index]) && errorCount(index) === 0 && (attempted[index] || index !== step.value)).length)
const err = (key) => (attempted[step.value] ? stepErrors.value[step.value][key] || '' : '')
const bind = (key, id) => ({ id, 'aria-invalid': err(key) ? 'true' : undefined, 'aria-describedby': err(key) ? `${id}-error` : undefined })

const show = async (index) => {
  flash.value = ''
  visited[step.value] = true
  step.value = index
  visited[index] = true
  await nextTick()
  stepHeading.value?.focus()
}
const focusFirstError = async () => { await nextTick(); formEl.value?.querySelector('[aria-invalid="true"]')?.focus() }
const next = async () => {
  attempted[step.value] = true
  if (errorCount(step.value)) return focusFirstError()
  if (step.value < steps.length - 1) return show(step.value + 1)
  // Finishing checks every section and sends the person to the first one that needs work.
  steps.forEach((_, index) => { attempted[index] = true })
  const firstIncomplete = steps.findIndex((_, index) => errorCount(index) > 0)
  if (firstIncomplete >= 0) { await show(firstIncomplete); return focusFirstError() }
  finished.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
const reviewAgain = () => { finished.value = false; show(0) }

/* ---------- Repeating rows ---------- */
const addRow = async (list, row, focusId) => { form[list].push(row); await nextTick(); document.getElementById(focusId(form[list].length - 1))?.focus() }
const removeRow = (list, index) => form[list].splice(index, 1)
const ageOf = (dob) => {
  if (!dob) return ''
  const birth = new Date(`${dob}T00:00:00`); const now = new Date()
  let years = now.getFullYear() - birth.getFullYear()
  if (now < new Date(now.getFullYear(), birth.getMonth(), birth.getDate())) years -= 1
  return years < 0 ? '' : years < 1 ? 'Under 1 year' : `${years} year${years === 1 ? '' : 's'}`
}
const shareTotal = computed(() => form.beneficiaries.reduce((sum, row) => sum + (Number(row.share) || 0), 0))

/* ---------- Documents ---------- */
const fileErrors = reactive({})
const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png']
const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`)
const pickFile = (key, event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  delete fileErrors[`doc.${key}`]
  const extension = file.name.split('.').pop().toLowerCase()
  if (!ALLOWED.includes(extension)) { fileErrors[`doc.${key}`] = 'Use a PDF, JPG or PNG file.'; return }
  if (file.size > 5 * 1024 * 1024) { fileErrors[`doc.${key}`] = `This file is ${fileSize(file.size)}. The limit is 5 MB.`; return }
  form.documents[key] = { name: file.name, size: file.size }
}
const removeFile = (key) => { delete form.documents[key]; delete fileErrors[`doc.${key}`] }
const uploadedCount = computed(() => Object.keys(form.documents).length)

/* ---------- Change requests for locked fields ---------- */
const changeRequests = ref([])
const draft = reactive({ key: '', value: '', reason: '', tried: false })
const flash = ref('')
const draftErrors = computed(() => ({
  value: !filled(draft.value) ? 'Enter the corrected value.' : draft.value.trim() === form[draft.key] ? 'This is the same as the current value.' : '',
  reason: !filled(draft.reason) ? 'Tell HR why this needs to change.' : '',
}))
const openRequest = async (key) => {
  Object.assign(draft, { key, value: '', reason: '', tried: false })
  flash.value = ''
  await nextTick()
  document.getElementById('pp-request-value')?.focus()
}
const closeRequest = () => { const key = draft.key; draft.key = ''; nextTick(() => document.getElementById(`pp-${key}`)?.focus()) }
const submitRequest = () => {
  draft.tried = true
  if (draftErrors.value.value || draftErrors.value.reason) return
  const field = basicFields.find((item) => item.key === draft.key)
  changeRequests.value.unshift({ id: Date.now(), field: field.label, value: draft.value.trim(), reason: draft.reason.trim(), status: 'Pending', date: new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) })
  flash.value = `Change request for “${field.label}” added below.`
  closeRequest()
}
const withdrawRequest = (id) => { changeRequests.value = changeRequests.value.filter((item) => item.id !== id) }

const reviewItems = computed(() => [
  ['Name', [form.firstName, form.surname].filter(Boolean).join(' ') || '—'],
  ['Staff ID', staffId],
  ['Date of birth', form.dob ? new Date(`${form.dob}T00:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'],
  ['Phone', form.phone || '—'],
  ['Email', form.email || '—'],
  ['Emergency contacts', String(form.contacts.filter((row) => filled(row.name)).length)],
  ['Documents uploaded', `${uploadedCount.value} of ${documentSlots.length}`],
])
</script>

<template>
  <div class="pp">
    <header class="pp-head">
      <div>
        <h1>Personnel Profile</h1>
        <p>Keep your HR record up to date. Fields marked <span class="pp-req">*</span> are required.</p>
      </div>
      <div class="pp-progress" role="group" aria-label="Profile progress">
        <div class="pp-progress__text"><strong>{{ finished ? steps.length : completedCount }} of {{ steps.length }}</strong> sections complete</div>
        <div class="pp-progress__track"><i :style="{ width: `${((finished ? steps.length : completedCount) / steps.length) * 100}%` }"></i></div>
      </div>
    </header>

    <!-- No backend yet: say so plainly rather than letting staff think details were saved. -->
    <p class="pp-note" role="note"><AppIcon name="info" :size="18" /><span><strong>Preview.</strong> The personnel portal isn’t connected yet. Nothing you enter here is saved or sent to HR.</span></p>

    <section v-if="finished" class="pp-card pp-done" aria-labelledby="pp-done-title">
      <span class="pp-done__icon"><AppIcon name="check" :size="30" /></span>
      <h2 id="pp-done-title">Your profile is complete</h2>
      <p>All five sections are filled in. In the live portal this is where your profile would go to HR for review — this preview doesn’t save or send it.</p>
      <div class="pp-done__actions">
        <button type="button" class="pp-btn pp-btn--ghost" @click="reviewAgain"><AppIcon name="pen" :size="16" />Review my answers</button>
        <RouterLink to="/admin" class="pp-btn pp-btn--primary">Go to dashboard<AppIcon name="arrow-right" :size="16" /></RouterLink>
      </div>
    </section>

    <div v-else class="pp-layout">
      <nav class="pp-steps" aria-label="Profile sections">
        <ol>
          <li v-for="(item, index) in steps" :key="item.title">
            <button type="button" class="pp-steps__item" :class="{ 'is-current': index === step, 'is-done': isDone(index), 'is-problem': hasProblem(index) && index !== step }" :aria-current="index === step ? 'step' : undefined" @click="show(index)">
              <span class="pp-steps__dot">
                <AppIcon v-if="hasProblem(index) && index !== step" name="alert-circle" :size="15" />
                <AppIcon v-else-if="isDone(index)" name="check" :size="15" />
                <template v-else>{{ index + 1 }}</template>
              </span>
              <span class="pp-steps__text"><strong>{{ item.title }}</strong><small>{{ hasProblem(index) && index !== step ? 'Needs attention' : item.hint }}</small></span>
            </button>
          </li>
        </ol>
      </nav>

      <form ref="formEl" class="pp-card pp-form" novalidate @submit.prevent="next">
        <header class="pp-form__head">
          <span class="pp-form__icon"><AppIcon :name="current.icon" :size="20" /></span>
          <div>
            <p class="pp-eyebrow">Step {{ step + 1 }} of {{ steps.length }}</p>
            <h2 ref="stepHeading" tabindex="-1">{{ current.title }}</h2>
            <p>{{ current.description }}</p>
          </div>
        </header>

        <p v-if="attempted[step] && errorCount(step)" class="pp-alert" role="alert"><AppIcon name="alert-circle" :size="18" />Please fix {{ errorCount(step) === 1 ? 'the field' : `the ${errorCount(step)} fields` }} marked below.</p>
        <p v-if="flash && step === 0" class="pp-alert pp-alert--success" role="status"><AppIcon name="check-circle" :size="18" />{{ flash }}</p>

        <!-- 1. Personal information -->
        <template v-if="step === 0">
          <fieldset class="pp-section">
            <legend>Basic information</legend>
            <div class="pp-grid">
              <ProfileField label="Staff ID" field-id="pp-staff-id" hint="Assigned automatically">
                <div class="pp-input-wrap"><input id="pp-staff-id" :value="staffId" readonly class="is-locked" /><AppIcon name="lock" :size="16" /></div>
              </ProfileField>
              <template v-for="field in basicFields" :key="field.key">
                <ProfileField :label="field.label" :field-id="`pp-${field.key}`" :required="field.required" :error="err(field.key)" :hint="isLocked(field.key) ? 'On record — request a change to update it' : field.hint">
                  <template v-if="isLocked(field.key)" #action>
                    <button type="button" class="pp-link" :aria-expanded="draft.key === field.key" aria-controls="pp-request-panel" @click="draft.key === field.key ? closeRequest() : openRequest(field.key)">Request change</button>
                  </template>
                  <div v-if="isLocked(field.key)" class="pp-input-wrap"><input v-bind="bind(field.key, `pp-${field.key}`)" :value="form[field.key]" readonly class="is-locked" /><AppIcon name="lock" :size="16" /></div>
                  <select v-else-if="field.type === 'select'" v-bind="bind(field.key, `pp-${field.key}`)" v-model="form[field.key]"><option value="" disabled>Select…</option><option v-for="option in field.options" :key="option">{{ option }}</option></select>
                  <input v-else v-bind="bind(field.key, `pp-${field.key}`)" v-model.trim="form[field.key]" :type="field.type || 'text'" :autocomplete="field.autocomplete || 'off'" :lang="field.lang" :max="field.type === 'date' ? today : undefined" />
                </ProfileField>
                <div v-if="draft.key === field.key" id="pp-request-panel" class="pp-request-panel" role="region" :aria-label="`Request a change to ${field.label}`">
                  <p class="pp-request-panel__title">Request a change to <strong>{{ lower(field.label) }}</strong></p>
                  <p class="pp-request-panel__current">Currently on record: <strong>{{ form[field.key] }}</strong></p>
                  <div class="pp-grid">
                    <ProfileField label="Correct value" field-id="pp-request-value" required :error="draft.tried ? draftErrors.value : ''">
                      <input id="pp-request-value" v-model="draft.value" :aria-invalid="draft.tried && draftErrors.value ? 'true' : undefined" @keydown.enter.prevent="submitRequest" />
                    </ProfileField>
                    <ProfileField label="Reason" field-id="pp-request-reason" required :error="draft.tried ? draftErrors.reason : ''">
                      <input id="pp-request-reason" v-model="draft.reason" placeholder="e.g. Spelling on my ID card" :aria-invalid="draft.tried && draftErrors.reason ? 'true' : undefined" @keydown.enter.prevent="submitRequest" />
                    </ProfileField>
                  </div>
                  <div class="pp-request-panel__actions">
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" @click="closeRequest">Cancel</button>
                    <button type="button" class="pp-btn pp-btn--primary pp-btn--sm" @click="submitRequest"><AppIcon name="send" :size="15" />Send request</button>
                  </div>
                </div>
              </template>
            </div>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Contact information</legend>
            <div class="pp-grid">
              <ProfileField v-for="field in contactFields" :key="field.key" :label="field.label" :field-id="`pp-${field.key}`" :required="field.required" :error="err(field.key)" :wide="field.wide">
                <input v-bind="bind(field.key, `pp-${field.key}`)" v-model.trim="form[field.key]" :type="field.type || 'text'" :autocomplete="field.autocomplete || 'off'" :inputmode="field.type === 'tel' ? 'tel' : undefined" />
              </ProfileField>
            </div>
          </fieldset>
        </template>

        <!-- 2. Professional qualifications -->
        <template v-else-if="step === 1">
          <fieldset class="pp-section">
            <legend>Education</legend>
            <p class="pp-section__help">Start with your highest qualification.</p>
            <div v-for="(row, i) in form.education" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Qualification {{ i + 1 }}</span><button v-if="form.education.length > 1" type="button" class="pp-icon-btn" :aria-label="`Remove qualification ${i + 1}`" @click="removeRow('education', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid pp-grid--3">
                <ProfileField label="Qualification" :field-id="`pp-education-${i}-degree`" required :error="err(`education.${i}.degree`)"><input v-bind="bind(`education.${i}.degree`, `pp-education-${i}-degree`)" v-model.trim="row.degree" placeholder="e.g. Bachelor of Social Work" /></ProfileField>
                <ProfileField label="Institution" :field-id="`pp-education-${i}-institution`" required :error="err(`education.${i}.institution`)"><input v-bind="bind(`education.${i}.institution`, `pp-education-${i}-institution`)" v-model.trim="row.institution" placeholder="e.g. Royal University of Phnom Penh" /></ProfileField>
                <ProfileField label="Year completed" :field-id="`pp-education-${i}-year`" :error="err(`education.${i}.year`)"><input v-bind="bind(`education.${i}.year`, `pp-education-${i}-year`)" v-model="row.year" type="number" inputmode="numeric" min="1950" :max="thisYear + 6" placeholder="YYYY" /></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('education', { degree: '', institution: '', year: '' }, (i) => `pp-education-${i}-degree`)"><AppIcon name="plus" :size="16" />Add qualification</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Work experience <span class="pp-optional">Optional</span></legend>
            <p v-if="!form.experience.length" class="pp-empty">No previous roles added yet.</p>
            <div v-for="(row, i) in form.experience" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Role {{ i + 1 }}</span><button type="button" class="pp-icon-btn" :aria-label="`Remove role ${i + 1}`" @click="removeRow('experience', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid">
                <ProfileField label="Organisation" :field-id="`pp-experience-${i}-organisation`" required :error="err(`experience.${i}.organisation`)"><input v-bind="bind(`experience.${i}.organisation`, `pp-experience-${i}-organisation`)" v-model.trim="row.organisation" /></ProfileField>
                <ProfileField label="Position" :field-id="`pp-experience-${i}-position`" required :error="err(`experience.${i}.position`)"><input v-bind="bind(`experience.${i}.position`, `pp-experience-${i}-position`)" v-model.trim="row.position" /></ProfileField>
                <ProfileField label="From" :field-id="`pp-experience-${i}-from`"><input :id="`pp-experience-${i}-from`" v-model="row.from" type="month" /></ProfileField>
                <ProfileField label="To" :field-id="`pp-experience-${i}-to`" :error="err(`experience.${i}.to`)" hint="Leave empty if this is your current role"><input v-bind="bind(`experience.${i}.to`, `pp-experience-${i}-to`)" v-model="row.to" type="month" /></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('experience', { organisation: '', position: '', from: '', to: '' }, (i) => `pp-experience-${i}-organisation`)"><AppIcon name="plus" :size="16" />Add role</button>
          </fieldset>
        </template>

        <!-- 3. Family information -->
        <template v-else-if="step === 2">
          <fieldset class="pp-section">
            <legend>Family situation</legend>
            <div class="pp-grid">
              <ProfileField label="Marital status" field-id="pp-marital"><select id="pp-marital" v-model="form.maritalStatus"><option value="">Prefer not to say</option><option v-for="option in ['Single', 'Married', 'Divorced', 'Widowed']" :key="option">{{ option }}</option></select></ProfileField>
              <ProfileField v-if="form.maritalStatus === 'Married'" label="Spouse or partner’s name" field-id="pp-spouse" required :error="err('spouse')"><input v-bind="bind('spouse', 'pp-spouse')" v-model.trim="form.spouse" /></ProfileField>
            </div>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Children under 18 <span class="pp-count">{{ form.children.length }}</span></legend>
            <p v-if="!form.children.length" class="pp-empty">No children added.</p>
            <div v-for="(row, i) in form.children" :key="i" class="pp-row pp-row--inline">
              <div class="pp-grid pp-grid--child">
                <ProfileField label="Name" :field-id="`pp-children-${i}-name`" required :error="err(`children.${i}.name`)"><input v-bind="bind(`children.${i}.name`, `pp-children-${i}-name`)" v-model.trim="row.name" /></ProfileField>
                <ProfileField label="Date of birth" :field-id="`pp-children-${i}-dob`" :error="err(`children.${i}.dob`)" :hint="ageOf(row.dob)"><input v-bind="bind(`children.${i}.dob`, `pp-children-${i}-dob`)" v-model="row.dob" type="date" :max="today" /></ProfileField>
                <ProfileField label="Sex" :field-id="`pp-children-${i}-sex`"><select :id="`pp-children-${i}-sex`" v-model="row.sex"><option value="">Select…</option><option>Female</option><option>Male</option></select></ProfileField>
              </div>
              <button type="button" class="pp-icon-btn pp-row__remove" :aria-label="`Remove child ${i + 1}`" @click="removeRow('children', i)"><AppIcon name="trash" :size="16" /></button>
            </div>
            <button type="button" class="pp-add" @click="addRow('children', { name: '', dob: '', sex: '' }, (i) => `pp-children-${i}-name`)"><AppIcon name="plus" :size="16" />Add child</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Emergency contacts</legend>
            <p class="pp-section__help">At least one person we can call if something happens to you at work.</p>
            <div v-for="(row, i) in form.contacts" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Contact {{ i + 1 }}</span><button v-if="form.contacts.length > 1" type="button" class="pp-icon-btn" :aria-label="`Remove contact ${i + 1}`" @click="removeRow('contacts', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid pp-grid--3">
                <ProfileField label="Name" :field-id="`pp-contacts-${i}-name`" required :error="err(`contacts.${i}.name`)"><input v-bind="bind(`contacts.${i}.name`, `pp-contacts-${i}-name`)" v-model.trim="row.name" /></ProfileField>
                <ProfileField label="Relationship" :field-id="`pp-contacts-${i}-relationship`" required :error="err(`contacts.${i}.relationship`)"><input v-bind="bind(`contacts.${i}.relationship`, `pp-contacts-${i}-relationship`)" v-model.trim="row.relationship" placeholder="e.g. Mother, Spouse" /></ProfileField>
                <ProfileField label="Phone" :field-id="`pp-contacts-${i}-phone`" required :error="err(`contacts.${i}.phone`)"><input v-bind="bind(`contacts.${i}.phone`, `pp-contacts-${i}-phone`)" v-model.trim="row.phone" type="tel" inputmode="tel" /></ProfileField>
                <ProfileField label="Email" :field-id="`pp-contacts-${i}-email`" :error="err(`contacts.${i}.email`)"><input v-bind="bind(`contacts.${i}.email`, `pp-contacts-${i}-email`)" v-model.trim="row.email" type="email" /></ProfileField>
                <ProfileField label="Address" :field-id="`pp-contacts-${i}-address`" wide><input :id="`pp-contacts-${i}-address`" v-model.trim="row.address" /></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('contacts', emptyContact(), (i) => `pp-contacts-${i}-name`)"><AppIcon name="plus" :size="16" />Add emergency contact</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Beneficiaries <span class="pp-optional">Optional</span></legend>
            <p class="pp-section__help">People who would receive your entitlements. Shares must add up to 100%.</p>
            <p v-if="!form.beneficiaries.length" class="pp-empty">No beneficiaries added.</p>
            <div v-for="(row, i) in form.beneficiaries" :key="i" class="pp-row pp-row--inline">
              <div class="pp-grid pp-grid--beneficiary">
                <ProfileField label="Name" :field-id="`pp-beneficiaries-${i}-name`" required :error="err(`beneficiaries.${i}.name`)"><input v-bind="bind(`beneficiaries.${i}.name`, `pp-beneficiaries-${i}-name`)" v-model.trim="row.name" /></ProfileField>
                <ProfileField label="Relationship" :field-id="`pp-beneficiaries-${i}-relationship`"><input :id="`pp-beneficiaries-${i}-relationship`" v-model.trim="row.relationship" /></ProfileField>
                <ProfileField label="Share (%)" :field-id="`pp-beneficiaries-${i}-share`" required :error="err(`beneficiaries.${i}.share`)"><input v-bind="bind(`beneficiaries.${i}.share`, `pp-beneficiaries-${i}-share`)" v-model="row.share" type="number" inputmode="numeric" min="1" max="100" /></ProfileField>
              </div>
              <button type="button" class="pp-icon-btn pp-row__remove" :aria-label="`Remove beneficiary ${i + 1}`" @click="removeRow('beneficiaries', i)"><AppIcon name="trash" :size="16" /></button>
            </div>
            <p v-if="form.beneficiaries.length" class="pp-total" :class="{ 'is-off': shareTotal !== 100, 'has-error': err('shares') }"><span>Total shares</span><strong>{{ shareTotal }}%</strong></p>
            <p v-if="err('shares')" class="pp-field__error"><AppIcon name="alert-circle" :size="14" />{{ err('shares') }}</p>
            <button type="button" class="pp-add" @click="addRow('beneficiaries', { name: '', relationship: '', share: form.beneficiaries.length ? '' : 100 }, (i) => `pp-beneficiaries-${i}-name`)"><AppIcon name="plus" :size="16" />Add beneficiary</button>
          </fieldset>
        </template>

        <!-- 4. Supporting documents -->
        <template v-else-if="step === 3">
          <ul class="pp-docs">
            <li v-for="slot in documentSlots" :key="slot.key" class="pp-doc" :class="{ 'is-uploaded': form.documents[slot.key], 'has-error': err(`doc.${slot.key}`) || fileErrors[`doc.${slot.key}`] }">
              <span class="pp-doc__icon"><AppIcon :name="form.documents[slot.key] ? 'check' : 'file-text'" :size="18" /></span>
              <div class="pp-doc__body">
                <strong>{{ slot.label }}<span v-if="slot.required" class="pp-req" aria-hidden="true"> *</span><span v-else class="pp-optional">Optional</span></strong>
                <small v-if="form.documents[slot.key]">{{ form.documents[slot.key].name }} · {{ fileSize(form.documents[slot.key].size) }}</small>
                <small v-else>{{ slot.hint }}</small>
                <p v-if="fileErrors[`doc.${slot.key}`] || err(`doc.${slot.key}`)" :id="`pp-doc-${slot.key}-error`" class="pp-field__error"><AppIcon name="alert-circle" :size="14" />{{ fileErrors[`doc.${slot.key}`] || err(`doc.${slot.key}`) }}</p>
              </div>
              <div class="pp-doc__actions">
                <button v-if="form.documents[slot.key]" type="button" class="pp-icon-btn" :aria-label="`Remove ${slot.label}`" @click="removeFile(slot.key)"><AppIcon name="trash" :size="16" /></button>
                <label class="pp-btn pp-btn--ghost pp-btn--sm pp-upload">
                  <AppIcon name="upload" :size="15" />{{ form.documents[slot.key] ? 'Replace' : 'Upload' }}
                  <input :id="`pp-doc-${slot.key}`" type="file" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" :aria-label="`Upload ${slot.label}`" :aria-invalid="err(`doc.${slot.key}`) ? 'true' : undefined" :aria-describedby="err(`doc.${slot.key}`) ? `pp-doc-${slot.key}-error` : undefined" @change="pickFile(slot.key, $event)" />
                </label>
              </div>
            </li>
          </ul>
        </template>

        <!-- 5. Declarations -->
        <template v-else>
          <section class="pp-section" aria-labelledby="pp-review-title">
            <h3 id="pp-review-title" class="pp-section__title">Your details</h3>
            <dl class="pp-review">
              <div v-for="[label, value] in reviewItems" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div>
            </dl>
            <button type="button" class="pp-link" @click="show(0)">Edit personal information</button>
          </section>
          <fieldset class="pp-section">
            <legend>Declarations</legend>
            <label v-for="item in declarationItems" :key="item.key" class="pp-check" :class="{ 'has-error': err(`decl.${item.key}`) }">
              <input :id="`pp-decl-${item.key}`" v-model="form.declarations[item.key]" type="checkbox" :aria-invalid="err(`decl.${item.key}`) ? 'true' : undefined" />
              <span>{{ item.text }}<span class="pp-req" aria-hidden="true"> *</span></span>
            </label>
            <div class="pp-grid pp-sign">
              <ProfileField label="Signature" field-id="pp-signature" required :error="err('signature')" hint="Type your full name"><input v-bind="bind('signature', 'pp-signature')" v-model.trim="form.signature" autocomplete="name" /></ProfileField>
              <ProfileField label="Date" field-id="pp-sign-date"><input id="pp-sign-date" :value="new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })" readonly class="is-locked" /></ProfileField>
            </div>
          </fieldset>
        </template>

        <footer class="pp-form__foot">
          <button v-if="step > 0" type="button" class="pp-btn pp-btn--ghost" @click="show(step - 1)"><AppIcon name="arrow-left" :size="16" />Back</button>
          <span class="pp-spacer"></span>
          <button type="submit" class="pp-btn pp-btn--primary">{{ step === steps.length - 1 ? 'Finish profile' : `Continue to ${current.short}` }}<AppIcon :name="step === steps.length - 1 ? 'check' : 'arrow-right'" :size="16" /></button>
        </footer>
      </form>
    </div>

    <section v-if="changeRequests.length" class="pp-card pp-requests" aria-labelledby="pp-requests-title">
      <h2 id="pp-requests-title">My change requests</h2>
      <ul>
        <li v-for="item in changeRequests" :key="item.id">
          <div class="pp-requests__main"><strong>{{ item.field }}</strong><span>→ {{ item.value }}</span><small>{{ item.reason }} · {{ item.date }}</small></div>
          <span class="pp-badge">{{ item.status }}</span>
          <button type="button" class="pp-link pp-link--muted" @click="withdrawRequest(item.id)">Withdraw</button>
        </li>
      </ul>
    </section>
  </div>
</template>

<style>
.pp { --pp-ink: #17365f; --pp-text: #3d5470; --pp-muted: #6b8099; --pp-line: #dfe7ef; --pp-soft: #f6f9fc; --pp-brand: #0b7f62; --pp-brand-dark: #086a51; --pp-brand-soft: #e8f5f0; --pp-danger: #c0392b; --pp-danger-soft: #fdf1f0; width: min(100%, 1180px); margin: 0 auto; padding: 24px 0 48px; color: var(--pp-text); font-size: 14px; line-height: 1.5; }
.pp h1, .pp h2, .pp h3 { color: var(--pp-ink); letter-spacing: -.01em; }
.pp-req { color: var(--pp-danger); }
/* Older global stylesheets give every button a 28px top margin and every label a grid layout. */
.pp button { margin: 0; }
.pp .pp-field__label label { display: inline; }

/* Header + progress */
.pp-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 16px; }
.pp-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.pp-head p { margin: 0; color: var(--pp-muted); }
.pp-progress { width: 240px; flex: none; }
.pp-progress__text { margin-bottom: 6px; color: var(--pp-muted); font-size: .85rem; text-align: right; }
.pp-progress__text strong { color: var(--pp-ink); }
.pp-progress__track { height: 8px; overflow: hidden; border-radius: 99px; background: #e6edf3; }
.pp-progress__track i { display: block; height: 100%; border-radius: inherit; background: var(--pp-brand); transition: width .3s ease; }

.pp-note { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 20px; padding: 11px 14px; border: 1px solid #f1dfae; border-radius: 10px; background: #fffaeb; color: #7a5a12; font-size: .9rem; }
.pp-note svg { flex: none; margin-top: 2px; }

/* Layout */
.pp-layout { display: grid; grid-template-columns: 260px minmax(0, 1fr); align-items: start; gap: 24px; }
.pp-layout > * { min-width: 0; } /* lets the step strip scroll instead of widening the page on small screens */
.pp-card { border: 1px solid var(--pp-line); border-radius: 14px; background: #fff; box-shadow: 0 6px 20px rgba(23, 54, 95, .05); }

/* Step navigation */
.pp-steps { position: sticky; top: 96px; }
.pp-steps ol { display: grid; gap: 4px; margin: 0; padding: 8px; list-style: none; border: 1px solid var(--pp-line); border-radius: 14px; background: #fff; }
.pp-steps__item { display: flex; width: 100%; align-items: center; gap: 12px; padding: 10px 12px; border: 0; border-radius: 10px; background: transparent; color: var(--pp-text); text-align: left; cursor: pointer; }
.pp-steps__item:hover { background: var(--pp-soft); transform: none !important; }
.pp-steps__dot { display: grid; width: 30px; height: 30px; flex: none; place-items: center; border: 1.5px solid #cdd9e5; border-radius: 50%; background: #fff; color: var(--pp-muted); font-size: .85rem; font-weight: 700; }
.pp-steps__text { display: grid; min-width: 0; }
.pp-steps__text strong { color: var(--pp-ink); font-size: .9rem; font-weight: 600; }
.pp-steps__text small { overflow: hidden; color: var(--pp-muted); font-size: .78rem; text-overflow: ellipsis; white-space: nowrap; }
.pp-steps__item.is-current { background: var(--pp-brand-soft); }
.pp-steps__item.is-current .pp-steps__dot { border-color: var(--pp-brand); background: var(--pp-brand); color: #fff; }
.pp-steps__item.is-current strong { color: var(--pp-brand-dark); font-weight: 700; }
.pp-steps__item.is-done .pp-steps__dot { border-color: #bfe3d6; background: var(--pp-brand-soft); color: var(--pp-brand); }
.pp-steps__item.is-problem .pp-steps__dot { border-color: #f2c4bf; background: var(--pp-danger-soft); color: var(--pp-danger); }
.pp-steps__item.is-problem small { color: var(--pp-danger); }

/* Form card */
.pp-form { padding: 28px 32px; }
.pp-form__head { display: flex; gap: 14px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--pp-line); }
.pp-form__icon { display: grid; width: 44px; height: 44px; flex: none; place-items: center; border-radius: 12px; background: var(--pp-brand-soft); color: var(--pp-brand); }
.pp-eyebrow { margin: 0 0 2px; color: var(--pp-brand); font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.pp-form__head h2 { margin: 0 0 2px; font-size: 1.3rem; font-weight: 700; outline: 0; }
.pp-form__head p:last-child { margin: 0; color: var(--pp-muted); }

.pp-alert { display: flex; align-items: center; gap: 9px; margin: 0 0 20px; padding: 11px 14px; border: 1px solid #f2c4bf; border-radius: 10px; background: var(--pp-danger-soft); color: #9b2c22; font-size: .9rem; font-weight: 600; }
.pp-alert--success { border-color: #bfe3d6; background: var(--pp-brand-soft); color: var(--pp-brand-dark); }

.pp-section { min-width: 0; margin: 0 0 28px; padding: 0; border: 0; }
.pp-section legend, .pp-section__title { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; padding: 0; color: var(--pp-ink); font-size: 1rem; font-weight: 700; }
.pp-section__help { margin: -8px 0 14px; color: var(--pp-muted); font-size: .88rem; }
.pp-optional { padding: 2px 8px; border-radius: 99px; background: #eef2f6; color: var(--pp-muted); font-size: .72rem; font-weight: 600; }
.pp-count { min-width: 22px; padding: 1px 7px; border-radius: 99px; background: var(--pp-brand-soft); color: var(--pp-brand); font-size: .78rem; text-align: center; }
.pp-empty { margin: 0 0 10px; padding: 14px; border: 1px dashed #d6e0ea; border-radius: 10px; color: var(--pp-muted); font-size: .9rem; text-align: center; }

/* Fields */
.pp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 20px; }
.pp-grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.pp-grid--child { grid-template-columns: 2fr 1.4fr 1fr; }
.pp-grid--beneficiary { grid-template-columns: 2fr 1.5fr 1fr; }
.pp-field { display: flex; min-width: 0; flex-direction: column; gap: 6px; }
.pp-field--wide { grid-column: 1 / -1; }
.pp-field__label { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.pp-field__label label { color: var(--pp-ink); font-size: .88rem; font-weight: 600; }
.pp-field__hint { margin: 0; color: var(--pp-muted); font-size: .8rem; }
.pp-field__error { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--pp-danger); font-size: .8rem; font-weight: 500; }
.pp-field__error svg { flex: none; }
.pp input:not([type="checkbox"]):not([type="file"]), .pp select { box-sizing: border-box; width: 100%; height: 44px; padding: 0 13px; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--pp-ink); font: inherit; font-size: .95rem; transition: border-color .15s, box-shadow .15s; }
.pp input::placeholder { color: #9aabbd; }
.pp input:hover:not([readonly]), .pp select:hover { border-color: #aebfd1; }
.pp input:focus, .pp select:focus { border-color: var(--pp-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.pp input[aria-invalid="true"], .pp select[aria-invalid="true"] { border-color: var(--pp-danger); background: #fffdfc; }
.pp input[aria-invalid="true"]:focus { box-shadow: 0 0 0 3px rgba(192, 57, 43, .14); }
.pp input.is-locked { border-color: var(--pp-line); background: var(--pp-soft); color: var(--pp-text); cursor: default; }
.pp-input-wrap { position: relative; }
.pp-input-wrap input { padding-right: 40px !important; }
.pp-input-wrap svg { position: absolute; top: 50%; right: 13px; color: #9aabbd; transform: translateY(-50%); pointer-events: none; }

/* Change-request panel */
.pp-request-panel { grid-column: 1 / -1; padding: 18px; border: 1px solid #bfe3d6; border-radius: 12px; background: #f6fbf9; }
.pp-request-panel__title { margin: 0 0 2px; color: var(--pp-ink); font-weight: 600; }
.pp-request-panel__current { margin: 0 0 14px; color: var(--pp-muted); font-size: .88rem; }
.pp-request-panel__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }

/* Repeating rows */
.pp-row { margin-bottom: 12px; padding: 16px; border: 1px solid var(--pp-line); border-radius: 12px; background: var(--pp-soft); }
.pp-row__head { display: flex; align-items: center; justify-content: space-between; min-height: 30px; margin: -4px 0 10px; color: var(--pp-muted); font-size: .8rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
.pp-row--inline { display: flex; align-items: flex-start; gap: 10px; }
.pp-row--inline > .pp-grid { flex: 1; }
.pp-row__remove { margin-top: 28px; }
.pp-row input:not([type="checkbox"]), .pp-row select { background: #fff; }
.pp-add { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; padding: 0 14px; border: 1px dashed #b9c9d9; border-radius: 10px; background: #fff; color: var(--pp-brand-dark); font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }
.pp-add:hover { border-color: var(--pp-brand); background: var(--pp-brand-soft); }
.pp-total { display: flex; align-items: center; justify-content: space-between; margin: 0 0 8px; padding: 10px 14px; border-radius: 10px; background: var(--pp-brand-soft); color: var(--pp-brand-dark); font-size: .9rem; }
.pp-total.is-off { background: #fff6e6; color: #8a5a00; }
.pp-total.has-error { background: var(--pp-danger-soft); color: var(--pp-danger); }

/* Documents */
.pp-docs { display: grid; gap: 10px; margin: 0 0 28px; padding: 0; list-style: none; }
.pp-doc { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1px solid var(--pp-line); border-radius: 12px; background: #fff; }
.pp-doc.is-uploaded { border-color: #bfe3d6; background: #f6fbf9; }
.pp-doc.has-error { border-color: #f2c4bf; background: #fffbfa; }
.pp-doc__icon { display: grid; width: 40px; height: 40px; flex: none; place-items: center; border-radius: 10px; background: var(--pp-soft); color: var(--pp-muted); }
.pp-doc.is-uploaded .pp-doc__icon { background: var(--pp-brand-soft); color: var(--pp-brand); }
.pp-doc__body { display: grid; min-width: 0; flex: 1; gap: 2px; }
.pp-doc__body strong { display: flex; align-items: center; gap: 8px; color: var(--pp-ink); font-size: .93rem; }
.pp-doc__body small { overflow: hidden; color: var(--pp-muted); font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
.pp-doc__actions { display: flex; align-items: center; gap: 6px; flex: none; }
.pp-upload { position: relative; cursor: pointer; }
.pp-upload:focus-within { outline: 3px solid rgba(11, 127, 98, .22); outline-offset: 2px; }

/* Declarations */
.pp-review { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 24px; margin: 0 0 10px; padding: 6px 18px; border: 1px solid var(--pp-line); border-radius: 12px; background: var(--pp-soft); }
.pp-review > div { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--pp-line); }
.pp-review > div:nth-last-child(-n + 1) { border-bottom: 0; }
.pp-review dt { color: var(--pp-muted); font-size: .88rem; }
.pp-review dd { min-width: 0; margin: 0; overflow: hidden; color: var(--pp-ink); font-weight: 600; text-align: right; text-overflow: ellipsis; white-space: nowrap; }
.pp-check { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 10px; padding: 14px 16px; border: 1px solid var(--pp-line); border-radius: 12px; color: var(--pp-ink); cursor: pointer; }
.pp-check:hover { border-color: #aebfd1; }
.pp-check:has(input:checked) { border-color: #bfe3d6; background: #f6fbf9; }
.pp-check.has-error { border-color: #f2c4bf; background: #fffbfa; }
.pp-check input { width: 20px; height: 20px; flex: none; margin: 1px 0 0; accent-color: var(--pp-brand); cursor: pointer; }
.pp-sign { margin-top: 18px; }

/* Buttons */
.pp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 20px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .93rem; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; }
.pp-btn--sm { min-height: 36px; padding: 0 14px; font-size: .86rem; }
.pp-btn--primary { background: var(--pp-brand); color: #fff; box-shadow: 0 6px 16px rgba(11, 127, 98, .2); }
.pp-btn--primary:hover { background: var(--pp-brand-dark); }
.pp-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--pp-ink); }
.pp-btn--ghost:hover { border-color: #aebfd1; background: var(--pp-soft); }
.pp-icon-btn { display: grid; width: 34px; height: 34px; flex: none; place-items: center; padding: 0; border: 1px solid transparent; border-radius: 9px; background: transparent; color: var(--pp-muted); cursor: pointer; }
.pp-icon-btn:hover { border-color: #f2c4bf; background: var(--pp-danger-soft); color: var(--pp-danger); transform: none !important; }
.pp-link { padding: 0; border: 0; background: none; color: var(--pp-brand); font: inherit; font-size: .84rem; font-weight: 600; cursor: pointer; }
.pp-link:hover { color: var(--pp-brand-dark); text-decoration: underline; transform: none !important; }
.pp-link--muted { color: var(--pp-muted); }
.pp-form__foot { position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; gap: 10px; margin: 8px -32px -28px; padding: 16px 32px; border-top: 1px solid var(--pp-line); border-radius: 0 0 14px 14px; background: rgba(255, 255, 255, .96); backdrop-filter: blur(6px); }
.pp-spacer { flex: 1; }

/* Finished */
.pp-done { max-width: 620px; margin: 24px auto 0; padding: 40px 32px; text-align: center; }
.pp-done__icon { display: inline-grid; width: 64px; height: 64px; place-items: center; margin-bottom: 14px; border-radius: 50%; background: var(--pp-brand-soft); color: var(--pp-brand); }
.pp-done h2 { margin: 0 0 8px; font-size: 1.4rem; }
.pp-done p { margin: 0 auto 24px; max-width: 460px; color: var(--pp-muted); }
.pp-done__actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }

/* Change requests */
.pp-requests { margin-top: 24px; padding: 22px 26px; }
.pp-requests h2 { margin: 0 0 12px; font-size: 1.05rem; }
.pp-requests ul { margin: 0; padding: 0; list-style: none; }
.pp-requests li { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-top: 1px solid var(--pp-line); }
.pp-requests__main { display: flex; min-width: 0; flex: 1; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
.pp-requests__main strong { color: var(--pp-ink); }
.pp-requests__main small { flex-basis: 100%; color: var(--pp-muted); }
.pp-badge { padding: 3px 10px; border-radius: 99px; background: #fff3d6; color: #8a5a00; font-size: .78rem; font-weight: 700; }

/* Tablet: steps become a horizontal strip above the form */
@media (max-width: 1080px) {
  .pp-layout { grid-template-columns: 1fr; gap: 16px; }
  .pp-steps { position: static; }
  .pp-steps ol { display: flex; overflow-x: auto; scrollbar-width: none; }
  .pp-steps li { flex: 1 0 auto; }
  .pp-steps__item { padding: 8px 12px; }
  .pp-steps__text small { display: none; }
}
@media (max-width: 760px) {
  .pp { padding-top: 16px; }
  .pp-head { flex-direction: column; align-items: stretch; gap: 14px; }
  .pp-head h1 { font-size: 1.35rem; }
  .pp-progress { width: 100%; }
  .pp-progress__text { text-align: left; }
  .pp-steps__text { display: none; }
  .pp-steps__item.is-current .pp-steps__text { display: grid; }
  .pp-steps li { flex: 0 0 auto; }
  .pp-steps li:has(.is-current) { flex: 1 0 auto; }
  .pp-form { padding: 20px 16px; }
  .pp-form__head { margin-bottom: 18px; padding-bottom: 16px; }
  .pp-form__icon { display: none; }
  .pp-grid, .pp-grid--3, .pp-grid--child, .pp-grid--beneficiary, .pp-review { grid-template-columns: 1fr; }
  .pp-row { padding: 14px; }
  .pp-row--inline { flex-direction: column; gap: 0; }
  .pp-row--inline > .pp-grid { width: 100%; }
  .pp-row__remove { align-self: flex-end; margin-top: 8px; }
  .pp-review > div:nth-last-child(-n + 1) { border-bottom: 0; }
  .pp-doc { flex-wrap: wrap; }
  .pp-doc__actions { width: 100%; justify-content: flex-end; }
  .pp-form__foot { margin: 8px -16px -20px; padding: 12px 16px; }
  .pp-form__foot .pp-btn--primary { flex: 1; }
  .pp-requests { padding: 18px 16px; }
  .pp-requests li { flex-wrap: wrap; }
}
</style>
