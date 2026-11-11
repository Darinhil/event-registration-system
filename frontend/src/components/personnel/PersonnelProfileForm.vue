<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import ProfileField from './ProfileField.vue'
import { useAuthStore } from '../../stores/auth'
import { cancelRequest, deletePersonnelDocument, getMyRequests, getPersonnelProfile, savePersonnelProfile, submitRequest, uploadPersonnelDocument } from '../../services/staffRequestService'

const auth = useAuthStore()
const router = useRouter()

/* ---------- Record ---------- */
// The name on the sign-in account is locked; HR changes it through a request.
const nameParts = String(auth.user?.name || '').trim().split(/\s+/).filter(Boolean)
const record = { firstName: nameParts[0] || '', surname: nameParts.slice(1).join(' ') }
const isLocked = (key) => Boolean(record[key])
const staffId = auth.user?.id ? `ST-${String(auth.user.id).padStart(5, '0')}` : '—'

const emptyContact = () => ({ name: '', relationship: '', phone: '', email: '', address: '' })
const emptyEducation = () => ({ start_date: '', end_date: '', institution: '', degree: '', major: '', year: '' })
const emptyTraining = () => ({ course_title: '', start_date: '', end_date: '', provider: '' })
const emptyEmployment = () => ({ from: '', to: '', position: '', organisation: '', project_title: '', job_description: '' })
const emptyLanguage = () => ({ language: '', reading: '', writing: '', speaking: '', understanding: '' })
const emptyGeography = () => ({ country: '', province: '' })
const emptyMembership = () => ({ start_date: '', end_date: '', institution: '', role: '', description: '' })
const form = reactive({
  firstName: record.firstName, surname: record.surname, khmerName: '', gender: '', dob: '', birthPlace: '', citizenship: '', idNumber: '',
  phone: auth.user?.phone || '', altPhone: '', email: auth.user?.email || '', altEmail: '', address: '',
  houseNumber: '', streetNumber: '', village: '', commune: '', district: '', province: '', postalCode: '',
  education: [emptyEducation()], trainingCourses: [], expertise: [], experience: [],
  motherTongue: '', otherMotherTongue: '', languages: [], geographicExperience: [], memberships: [],
  maritalStatus: '', spouse: '', children: [], contacts: [emptyContact()], beneficiaries: [],
  documents: {},
  notes: '', declarations: { accurate: false, updates: false, consent: false }, signature: '',
})

const COUNTRY_CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW'
const regionNames = new Intl.DisplayNames(['en'], { type: 'region' })
const countryOptions = COUNTRY_CODES.split(' ').map((code) => regionNames.of(code)).sort((a, b) => a.localeCompare(b))

const basicFields = [
  { key: 'firstName', label: 'First name', required: true, autocomplete: 'given-name' },
  { key: 'surname', label: 'Surname', required: true, autocomplete: 'family-name' },
  { key: 'khmerName', label: 'Name in Khmer', hint: 'ឈ្មោះជាភាសាខ្មែរ', lang: 'km' },
  { key: 'gender', label: 'Gender', required: true, type: 'select', options: ['Male', 'Female', 'Non-binary', 'Prefer not to say'] },
  { key: 'dob', label: 'Date of birth', required: true, type: 'date', autocomplete: 'bday' },
  { key: 'birthPlace', label: 'Place of birth', required: true },
  { key: 'citizenship', label: 'Citizenship (if foreigner)', type: 'select', options: countryOptions, hint: 'Only if you are not a Cambodian citizen' },
  { key: 'idNumber', label: 'National ID or passport number', required: true },
]
const contactFields = [
  { key: 'phone', label: 'Primary phone', required: true, type: 'tel', autocomplete: 'tel' },
  { key: 'altPhone', label: 'Alternate phone', type: 'tel' },
  { key: 'email', label: 'Primary email address', required: true, type: 'email', autocomplete: 'email' },
  { key: 'altEmail', label: 'Alternate email address', type: 'email' },
]
const documentSlots = [
  { key: 'id', label: 'ID card, birth certificate or passport', hint: 'A clear copy of both sides', required: true },
  { key: 'cv', label: 'Employment book or CV', hint: 'Your employment book, if applicable, or your CV', required: true },
  { key: 'certificate', label: 'Certificates', hint: 'Education and training certificates' },
  { key: 'bank', label: 'Other supporting document', hint: 'Visa, work permit, family/residential book, or other relevant document' },
]
const declarationText = 'I confirm that all details and documents submitted in this Personnel Profile are complete, true, and correct to the best of my knowledge. I understand that any false, misleading, or suppressed information may result in immediate disciplinary action or termination of employment in accordance with the organization policy. I undertake to inform administration and HR team immediately of any changes to this information.'

/* ---------- Saving ---------- */
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const saveError = ref('')
const savedAt = ref(null)
const submittedAt = ref(null)
const extraDocumentSlots = ref([])
// Everything but the locked name and the files, which are saved on their own.
const payload = () => {
  const { firstName: _first, surname: _last, documents: _documents, ...rest } = form
  rest.declarations = { ...rest.declarations, updates: rest.declarations.accurate, consent: rest.declarations.accurate }
  rest.extraDocuments = extraDocumentSlots.value.map(({ key, title, description, remark }) => ({ key, title, description, remark }))
  return JSON.parse(JSON.stringify(rest))
}
const apply = (data) => {
  submittedAt.value = data.submitted_at
  form.documents = { ...data.documents }
  for (const slot of documentSlots) {
    const saved = data.documents?.[slot.key]
    slot.title = saved?.title || slot.label
    slot.description = saved?.description || slot.hint
    slot.remark = saved?.remark || ''
    slot.uploaded_at = saved?.uploaded_at || ''
  }
  const saved = data.profile || {}
  if (Array.isArray(saved.education)) {
    form.education = saved.education.map((row) => ({
      start_date: row.start_date || '',
      end_date: row.end_date || '',
      institution: row.institution || '',
      degree: row.degree || '',
      major: row.major || '',
      year: row.year || '',
    }))
    if (!form.education.length) form.education = [emptyEducation()]
  }
  if (Array.isArray(saved.experience)) {
    form.experience = saved.experience.map((row) => ({
      from: row.from ? (row.from.length === 7 ? `${row.from}-01` : row.from) : '',
      to: row.to ? (row.to.length === 7 ? `${row.to}-01` : row.to) : '',
      position: row.position || '',
      organisation: row.organisation || '',
      project_title: row.project_title || '',
      job_description: row.job_description || '',
    }))
  }
  const savedSlots = (saved.extraDocuments || []).map((slot) => ({ ...slot, label: slot.title, required: false }))
  const existingSlots = new Map([...savedSlots, ...extraDocumentSlots.value].map((slot) => [slot.key, slot]))
  const uploadedSlots = Object.entries(data.documents || {})
    .filter(([key]) => !documentSlots.some((slot) => slot.key === key))
    .map(([key, document]) => {
      const slot = existingSlots.get(key) || { key, required: false }
      return Object.assign(slot, {
        label: document.title || document.name || 'Supporting document',
        title: document.title || slot.title || document.name || '',
        description: document.description || slot.description || '',
        remark: document.remark || slot.remark || '',
        uploaded_at: document.uploaded_at || '',
      })
    })
  extraDocumentSlots.value = [...existingSlots.values()]
  for (const slot of uploadedSlots) if (!extraDocumentSlots.value.includes(slot)) extraDocumentSlots.value.push(slot)
}
const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [{ data: profile }, { data: requests }] = await Promise.all([getPersonnelProfile(), getMyRequests()])
    if (profile.data.profile) Object.assign(form, profile.data.profile)
    apply(profile.data)
    finished.value = Boolean(profile.data.submitted_at)
    changeRequests.value = requests.data.filter((item) => item.type === 'profile_change' && item.status !== 'cancelled')
  } catch {
    loadError.value = 'We couldn’t load your profile. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}
onMounted(load)
const save = async (submit = false) => {
  saving.value = true
  saveError.value = ''
  try {
    const { data } = await savePersonnelProfile(payload(), submit)
    submittedAt.value = data.data.submitted_at
    savedAt.value = new Date()
    return true
  } catch (error) {
    saveError.value = Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || 'Your changes weren’t saved. Check your connection and try again.'
    return false
  } finally {
    saving.value = false
  }
}
const saveDraft = async () => {
  if (await save()) flash.value = 'Draft saved.'
}

const steps = [
  { section: 'personal', title: 'Personal Information', short: 'Professional Qualifications', icon: 'user', hint: 'Identity and contact details', description: 'Your legal name, identity and how we can reach you.' },
  { section: 'qualifications', title: 'Professional Qualifications', short: 'Family Information', icon: 'graduation', hint: 'Education, training and experience', description: 'Your education, training, expertise, language and work history.' },
  { section: 'family', title: 'Family Information', short: 'Supporting Documents', icon: 'users', hint: 'Family, emergency contacts', description: 'Your family situation, emergency contacts and beneficiaries.' },
  { section: 'documents', title: 'Supporting Documents', short: 'Declaration', icon: 'file-text', hint: 'Attachments and notes', description: 'Attach relevant records. PDF, JPG or PNG, up to 5 MB each.' },
  { section: 'declarations', title: 'Declaration', icon: 'shield-check', hint: 'Review and confirm', description: 'Review your profile, then confirm and submit it to HR.' },
]

/* ---------- Validation ---------- */
const PHONE_RE = /^[+]?[0-9][0-9\s().-]{7,24}$/
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const today = new Date().toISOString().slice(0, 10)
const filled = (value) => String(value ?? '').trim() !== ''
// Lower-case only the first letter so acronyms survive: "National ID" -> "national ID".
const lower = (text) => text.charAt(0).toLowerCase() + text.slice(1)

const errorsFor = (index) => {
  const e = {}
  const section = steps[index].section
  const need = (key, value, message) => { if (!filled(value)) e[key] = message }
  if (section === 'personal') {
    for (const field of [...basicFields, ...contactFields]) if (field.required) need(field.key, form[field.key], field.type === 'select' ? `Choose your ${lower(field.label)}.` : `Enter your ${lower(field.label)}.`)
    if (filled(form.dob) && form.dob > today) e.dob = 'Date of birth can’t be in the future.'
    if (filled(form.phone) && !PHONE_RE.test(form.phone)) e.phone = 'Enter a valid phone number, e.g. 012 345 678.'
    if (filled(form.altPhone) && !PHONE_RE.test(form.altPhone)) e.altPhone = 'Enter a valid phone number, e.g. 012 345 678.'
    if (filled(form.email) && !EMAIL_RE.test(form.email)) e.email = 'Enter a valid email address, e.g. name@example.com.'
    if (filled(form.altEmail) && !EMAIL_RE.test(form.altEmail)) e.altEmail = 'Enter a valid email address.'
    for (const key of ['houseNumber', 'streetNumber', 'district', 'province']) need(key, form[key], `Enter the ${lower(key.replace(/[A-Z]/g, (letter) => ` ${letter}`))}.`)
  }
  if (section === 'documents') {
    for (const slot of [...documentSlots, ...extraDocumentSlots.value]) {
      if (slot.required && !form.documents[slot.key]) e[`doc.${slot.key}`] = `Upload your ${lower(slot.label)}.`
      if (extraDocumentSlots.value.includes(slot) && !filled(slot.title)) e[`doc-title.${slot.key}`] = 'Enter a document title.'
    }
    Object.assign(e, fileErrors)
  }
  if (section === 'qualifications') {
    form.education.forEach((row, i) => {
      need(`education.${i}.degree`, row.degree, 'Enter the qualification.')
      need(`education.${i}.institution`, row.institution, 'Enter the institution.')
      need(`education.${i}.start_date`, row.start_date, 'Enter the start date.')
      need(`education.${i}.end_date`, row.end_date, 'Enter the end date.')
      need(`education.${i}.major`, row.major, 'Enter the major.')
      if (row.start_date && row.end_date && row.end_date < row.start_date) e[`education.${i}.end_date`] = 'End date must be after the start date.'
    })
    form.trainingCourses.forEach((row, i) => {
      need(`trainingCourses.${i}.course_title`, row.course_title, 'Enter the course title.')
      need(`trainingCourses.${i}.start_date`, row.start_date, 'Enter the start date.')
      need(`trainingCourses.${i}.end_date`, row.end_date, 'Enter the end date.')
      need(`trainingCourses.${i}.provider`, row.provider, 'Enter the training provider.')
      if (row.start_date && row.end_date && row.end_date < row.start_date) e[`trainingCourses.${i}.end_date`] = 'End date must be after the start date.'
    })
    form.expertise.forEach((item, i) => need(`expertise.${i}`, item, 'Enter an area of expertise.'))
    form.experience.forEach((row, i) => {
      need(`experience.${i}.organisation`, row.organisation, 'Enter the organisation.')
      need(`experience.${i}.position`, row.position, 'Enter your position.')
      need(`experience.${i}.from`, row.from, 'Enter the start date.')
      if (filled(row.from) && filled(row.to) && row.to < row.from) e[`experience.${i}.to`] = 'End must be after the start.'
    })
    need('motherTongue', form.motherTongue, 'Choose your mother tongue.')
    if (form.motherTongue === 'Other') need('otherMotherTongue', form.otherMotherTongue, 'Enter your mother tongue language.')
    form.languages.forEach((row, i) => {
      for (const key of ['language', 'reading', 'writing', 'speaking', 'understanding']) need(`languages.${i}.${key}`, row[key], `Enter ${key === 'language' ? 'a language' : `${key} proficiency`}.`)
    })
    form.geographicExperience.forEach((row, i) => {
      need(`geographicExperience.${i}.country`, row.country, 'Choose a country.')
      need(`geographicExperience.${i}.province`, row.province, 'Enter a province or region.')
    })
    form.memberships.forEach((row, i) => {
      need(`memberships.${i}.start_date`, row.start_date, 'Enter the start date.')
      need(`memberships.${i}.institution`, row.institution, 'Enter the institution.')
      need(`memberships.${i}.role`, row.role, 'Enter the role or position.')
      if (row.start_date && row.end_date && row.end_date < row.start_date) e[`memberships.${i}.end_date`] = 'End date must be after the start date.'
    })
  }
  if (section === 'family') {
    need('maritalStatus', form.maritalStatus, 'Choose your marital status.')
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
  if (section === 'declarations') {
    if (!form.declarations.accurate) e['decl.accurate'] = 'Confirm the declaration before submitting.'
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
  if (step.value < steps.length - 1) {
    if (await save()) show(step.value + 1)
    return
  }
  // Finishing checks every section and sends the person to the first one that needs work.
  steps.forEach((_, index) => { attempted[index] = true })
  const firstIncomplete = steps.findIndex((_, index) => errorCount(index) > 0)
  if (firstIncomplete >= 0) { await show(firstIncomplete); return focusFirstError() }
  if (await save(true)) finished.value = true
}
const reviewAgain = () => { finished.value = false; show(0) }

/* ---------- Repeating rows ---------- */
const addRow = async (list, row, focusId) => { form[list].push(row); await nextTick(); document.getElementById(focusId(form[list].length - 1))?.focus() }
const addExpertise = () => form.expertise.push('')
const removeRow = (list, index) => form[list].splice(index, 1)
const ageOf = (dob) => {
  if (!dob) return ''
  const birth = new Date(`${dob}T00:00:00`); const now = new Date()
  let years = now.getFullYear() - birth.getFullYear()
  if (now < new Date(now.getFullYear(), birth.getMonth(), birth.getDate())) years -= 1
  return years < 0 ? '' : years < 1 ? 'Under 1 year' : `${years} year${years === 1 ? '' : 's'}`
}
const shareTotal = computed(() => form.beneficiaries.reduce((sum, row) => sum + (Number(row.share) || 0), 0))
const childCount = computed(() => form.children.filter((row) => row.name || row.dob || row.sex).length)

/* ---------- Documents ---------- */
const fileErrors = reactive({})
const uploading = ref('')
const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png']
const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`)
const addDocument = () => {
  extraDocumentSlots.value.push({ key: `attachment-${crypto.randomUUID()}`, title: '', description: '', remark: '', uploaded_at: '', required: false })
}
const pickFile = (slot, event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  delete fileErrors[`doc.${slot.key}`]
  const extension = file.name.split('.').pop().toLowerCase()
  if (!ALLOWED.includes(extension)) { fileErrors[`doc.${slot.key}`] = 'Use a PDF, JPG or PNG file.'; return }
  if (file.size > 5 * 1024 * 1024) { fileErrors[`doc.${slot.key}`] = `This file is ${fileSize(file.size)}. The limit is 5 MB.`; return }
  if (!filled(slot.title || slot.label)) { fileErrors[`doc.${slot.key}`] = 'Enter a document title before uploading.'; return }
  uploading.value = slot.key
  uploadPersonnelDocument(slot.key, file, { title: slot.title || slot.label, description: slot.description, remark: slot.remark })
    .then(({ data }) => apply(data.data))
    .catch((error) => { fileErrors[`doc.${slot.key}`] = Object.values(error.response?.data?.errors || {}).flat()[0] || 'The upload didn’t finish. Try again.' })
    .finally(() => { uploading.value = '' })
}
const removeFile = async (key) => {
  delete fileErrors[`doc.${key}`]
  try {
    const { data } = await deletePersonnelDocument(key)
    apply(data.data)
    return true
  } catch {
    fileErrors[`doc.${key}`] = 'We couldn’t remove this file. Try again.'
    return false
  }
}
const removeDocument = async (slot) => {
  if (form.documents[slot.key] && !(await removeFile(slot.key))) return
  if (slot.key.startsWith('attachment-')) extraDocumentSlots.value = extraDocumentSlots.value.filter((item) => item.key !== slot.key)
}
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
const sendChangeRequest = async () => {
  draft.tried = true
  if (draftErrors.value.value || draftErrors.value.reason) return
  const field = basicFields.find((item) => item.key === draft.key)
  try {
    const { data } = await submitRequest({ type: 'profile_change', details: { field: field.label, value: draft.value.trim(), reason: draft.reason.trim() } })
    changeRequests.value.unshift(data.data)
    flash.value = `Change request for “${field.label}” sent to HR.`
    closeRequest()
  } catch (error) {
    flash.value = ''
    saveError.value = error.response?.data?.message || 'Your request wasn’t sent. Check your connection and try again.'
  }
}
const withdrawRequest = async (id) => {
  try {
    await cancelRequest(id)
    changeRequests.value = changeRequests.value.filter((item) => item.id !== id)
  } catch (error) {
    saveError.value = Object.values(error.response?.data?.errors || {}).flat()[0] || 'We couldn’t withdraw this request.'
  }
}

const reviewItems = computed(() => [
  ['Name', [form.firstName, form.surname].filter(Boolean).join(' ') || '—'],
  ['Staff ID', staffId],
  ['Date of birth', form.dob ? new Date(`${form.dob}T00:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'],
  ['Gender', form.gender || '—'],
  ['Place of birth', form.birthPlace || '—'],
  ['Citizenship', form.citizenship || '—'],
  ['ID/Passport number', form.idNumber || '—'],
  ['Primary phone', form.phone || '—'],
  ['Alternate phone', form.altPhone || '—'],
  ['Primary email', form.email || '—'],
  ['Alternate email', form.altEmail || '—'],
  ['Current address', [form.houseNumber, form.streetNumber, form.village, form.commune, form.district, form.province, form.postalCode].filter(Boolean).join(', ') || '—'],
  ['Education history', String(form.education.filter((row) => row.institution || row.degree || row.major).length)],
  ['Training courses', String(form.trainingCourses.filter((row) => row.course_title).length)],
  ['Expertise', String(form.expertise.filter(filled).length)],
  ['Employment history', String(form.experience.filter((row) => row.position || row.organisation).length)],
  ['Mother tongue', form.motherTongue === 'Other' ? form.otherMotherTongue : form.motherTongue || '—'],
  ['Other languages', String(form.languages.filter((row) => row.language).length)],
  ['Geographic experience', String(form.geographicExperience.filter((row) => row.country).length)],
  ['Memberships', String(form.memberships.filter((row) => row.institution).length)],
  ['Emergency contacts', String(form.contacts.filter((row) => filled(row.name)).length)],
  ['Documents uploaded', `${uploadedCount.value} of ${documentSlots.length + extraDocumentSlots.value.length}`],
])
const fmtDate = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const previewOpen = ref(false)
const preview = () => { previewOpen.value = true }
const printPreview = async () => {
  previewOpen.value = true
  await nextTick()
  window.print()
}
</script>

<template>
  <div class="pp">
    <header class="pp-head">
      <div>
        <h1>Personnel Profile</h1>
        <p v-if="savedAt && !finished">Saved at {{ savedAt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) }}</p>
      </div>
      <div class="pp-progress" role="group" aria-label="Profile progress">
        <div class="pp-progress__text"><strong>{{ finished ? steps.length : completedCount }} of {{ steps.length }}</strong> sections complete</div>
        <div class="pp-progress__track"><i :style="{ width: `${((finished ? steps.length : completedCount) / steps.length) * 100}%` }"></i></div>
      </div>
    </header>

    <p v-if="saveError" class="pp-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ saveError }}</p>

    <div v-if="loading" class="pp-card pp-done" role="status">Loading your profile…</div>
    <section v-else-if="loadError" class="pp-card pp-done" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" class="pp-btn pp-btn--ghost" @click="load"><AppIcon name="refresh" :size="16" />Try again</button>
    </section>

    <section v-else-if="finished" class="pp-card pp-done" aria-labelledby="pp-done-title">
      <span class="pp-done__icon"><AppIcon name="check" :size="30" /></span>
      <h2 id="pp-done-title">Your profile was submitted to HR</h2>
      <p v-if="submittedAt">Submitted on {{ fmtDate(submittedAt) }}. If you change anything, submit it again.</p>
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
        <p v-if="flash" class="pp-alert pp-alert--success" role="status"><AppIcon name="check-circle" :size="18" />{{ flash }}</p>

        <!-- 1. Personal information -->
        <template v-if="current.section === 'personal'">
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
                      <input id="pp-request-value" v-model="draft.value" :aria-invalid="draft.tried && draftErrors.value ? 'true' : undefined" @keydown.enter.prevent="sendChangeRequest" />
                    </ProfileField>
                    <ProfileField label="Reason" field-id="pp-request-reason" required :error="draft.tried ? draftErrors.reason : ''">
                      <input id="pp-request-reason" v-model="draft.reason" placeholder="e.g. Spelling on my ID card" :aria-invalid="draft.tried && draftErrors.reason ? 'true' : undefined" @keydown.enter.prevent="sendChangeRequest" />
                    </ProfileField>
                  </div>
                  <div class="pp-request-panel__actions">
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" @click="closeRequest">Cancel</button>
                    <button type="button" class="pp-btn pp-btn--primary pp-btn--sm" @click="sendChangeRequest"><AppIcon name="send" :size="15" />Send request</button>
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

          <fieldset class="pp-section">
            <legend>Current address</legend>
            <div class="pp-grid">
              <ProfileField label="House number" field-id="pp-house-number" required :error="err('houseNumber')"><input v-bind="bind('houseNumber', 'pp-house-number')" v-model.trim="form.houseNumber" inputmode="numeric" /></ProfileField>
              <ProfileField label="Street number" field-id="pp-street-number" required :error="err('streetNumber')"><input v-bind="bind('streetNumber', 'pp-street-number')" v-model.trim="form.streetNumber" /></ProfileField>
              <ProfileField label="Village" field-id="pp-village"><input id="pp-village" v-model.trim="form.village" /></ProfileField>
              <ProfileField label="Commune" field-id="pp-commune"><input id="pp-commune" v-model.trim="form.commune" /></ProfileField>
              <ProfileField label="District" field-id="pp-district" required :error="err('district')"><input v-bind="bind('district', 'pp-district')" v-model.trim="form.district" /></ProfileField>
              <ProfileField label="Province" field-id="pp-province" required :error="err('province')"><input v-bind="bind('province', 'pp-province')" v-model.trim="form.province" /></ProfileField>
              <ProfileField label="Postal code" field-id="pp-postal-code"><input id="pp-postal-code" v-model.trim="form.postalCode" inputmode="numeric" /></ProfileField>
            </div>
          </fieldset>
        </template>

        <!-- 4. Supporting documents -->
        <template v-else-if="current.section === 'documents'">
          <p class="pp-section__help">PDF, JPG or PNG — e.g. ID card, passport, visa, work permit or certificates.</p>
          <ul class="pp-docs">
            <li v-for="slot in [...documentSlots, ...extraDocumentSlots]" :key="slot.key" class="pp-doc" :class="{ 'is-uploaded': form.documents[slot.key], 'has-error': err(`doc.${slot.key}`) || fileErrors[`doc.${slot.key}`] }">
              <span class="pp-doc__icon"><AppIcon :name="form.documents[slot.key] ? 'check' : 'file-text'" :size="18" /></span>
              <div class="pp-doc__body">
                <div class="pp-grid">
                  <ProfileField label="Document title" :field-id="`pp-doc-title-${slot.key}`" :required="slot.required || slot.key.startsWith('attachment-')" :error="err(`doc-title.${slot.key}`)">
                    <input :id="`pp-doc-title-${slot.key}`" v-model.trim="slot.title" :placeholder="slot.label || 'Document title'" />
                  </ProfileField>
                  <ProfileField label="Description" :field-id="`pp-doc-description-${slot.key}`"><input :id="`pp-doc-description-${slot.key}`" v-model.trim="slot.description" :placeholder="slot.hint || 'Describe the document'" /></ProfileField>
                  <ProfileField label="Remark" :field-id="`pp-doc-remark-${slot.key}`"><input :id="`pp-doc-remark-${slot.key}`" v-model.trim="slot.remark" /></ProfileField>
                </div>
                <small v-if="form.documents[slot.key]">Uploaded {{ form.documents[slot.key].uploaded_at ? fmtDate(form.documents[slot.key].uploaded_at) : 'date unavailable' }} · {{ form.documents[slot.key].name }}<template v-if="form.documents[slot.key].size"> · {{ fileSize(form.documents[slot.key].size) }}</template></small>
                <small v-else>{{ slot.hint || 'Attach a PDF, JPG or PNG file.' }}</small>
                <p v-if="fileErrors[`doc.${slot.key}`] || err(`doc.${slot.key}`)" :id="`pp-doc-${slot.key}-error`" class="pp-field__error"><AppIcon name="alert-circle" :size="14" />{{ fileErrors[`doc.${slot.key}`] || err(`doc.${slot.key}`) }}</p>
              </div>
              <div class="pp-doc__actions">
                <button v-if="form.documents[slot.key] || slot.key.startsWith('attachment-')" type="button" class="pp-icon-btn" :aria-label="`Remove ${slot.title || slot.label || 'document'}`" @click="removeDocument(slot)"><AppIcon name="trash" :size="16" /></button>
                <label class="pp-btn pp-btn--ghost pp-btn--sm pp-upload">
                  <AppIcon name="upload" :size="15" />{{ uploading === slot.key ? 'Uploading…' : form.documents[slot.key] ? 'Replace' : 'Upload' }}
                  <input :id="`pp-doc-${slot.key}`" type="file" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" :aria-label="`Upload ${slot.title || slot.label || 'document'}`" :aria-invalid="err(`doc.${slot.key}`) ? 'true' : undefined" :aria-describedby="err(`doc.${slot.key}`) ? `pp-doc-${slot.key}-error` : undefined" @change="pickFile(slot, $event)" />
                </label>
              </div>
            </li>
          </ul>
          <button type="button" class="pp-add" @click="addDocument"><AppIcon name="plus" :size="16" />Add document</button>
          <fieldset class="pp-section">
            <legend>Your notes to the organization <span class="pp-optional">Optional</span></legend>
            <ProfileField label="Notes" field-id="pp-notes"><textarea id="pp-notes" v-model.trim="form.notes" rows="5" maxlength="5000" placeholder="Add any information you would like HR to know." /></ProfileField>
          </fieldset>
        </template>

        <!-- 2. Professional qualifications -->
        <template v-else-if="current.section === 'qualifications'">
          <fieldset class="pp-section">
            <legend>Education history</legend>
            <div v-for="(row, i) in form.education" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Education {{ i + 1 }}</span><button v-if="form.education.length > 1" type="button" class="pp-icon-btn" :aria-label="`Remove education ${i + 1}`" @click="removeRow('education', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid">
                <ProfileField label="Start date" :field-id="`pp-education-${i}-start`" required :error="err(`education.${i}.start_date`)"><input v-bind="bind(`education.${i}.start_date`, `pp-education-${i}-start`)" v-model="row.start_date" type="date" /></ProfileField>
                <ProfileField label="End date" :field-id="`pp-education-${i}-end`" required :error="err(`education.${i}.end_date`)"><input v-bind="bind(`education.${i}.end_date`, `pp-education-${i}-end`)" v-model="row.end_date" type="date" /></ProfileField>
                <ProfileField label="Educational institution" :field-id="`pp-education-${i}-institution`" required :error="err(`education.${i}.institution`)"><input v-bind="bind(`education.${i}.institution`, `pp-education-${i}-institution`)" v-model.trim="row.institution" /></ProfileField>
                <ProfileField label="Certificate/Diploma" :field-id="`pp-education-${i}-degree`" required :error="err(`education.${i}.degree`)"><input v-bind="bind(`education.${i}.degree`, `pp-education-${i}-degree`)" v-model.trim="row.degree" /></ProfileField>
                <ProfileField label="Major" :field-id="`pp-education-${i}-major`" required :error="err(`education.${i}.major`)"><input v-bind="bind(`education.${i}.major`, `pp-education-${i}-major`)" v-model.trim="row.major" /></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('education', emptyEducation(), (i) => `pp-education-${i}-start`)"><AppIcon name="plus" :size="16" />Add education background</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Training courses <span class="pp-optional">Optional</span></legend>
            <p v-if="!form.trainingCourses.length" class="pp-empty">No training courses added.</p>
            <div v-for="(row, i) in form.trainingCourses" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Course {{ i + 1 }}</span><button type="button" class="pp-icon-btn" :aria-label="`Remove training course ${i + 1}`" @click="removeRow('trainingCourses', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid">
                <ProfileField label="Course title" :field-id="`pp-training-${i}-title`" required :error="err(`trainingCourses.${i}.course_title`)"><input v-bind="bind(`trainingCourses.${i}.course_title`, `pp-training-${i}-title`)" v-model.trim="row.course_title" /></ProfileField>
                <ProfileField label="Training provider" :field-id="`pp-training-${i}-provider`" required :error="err(`trainingCourses.${i}.provider`)"><input v-bind="bind(`trainingCourses.${i}.provider`, `pp-training-${i}-provider`)" v-model.trim="row.provider" /></ProfileField>
                <ProfileField label="Start date" :field-id="`pp-training-${i}-start`" required :error="err(`trainingCourses.${i}.start_date`)"><input v-bind="bind(`trainingCourses.${i}.start_date`, `pp-training-${i}-start`)" v-model="row.start_date" type="date" /></ProfileField>
                <ProfileField label="End date" :field-id="`pp-training-${i}-end`" required :error="err(`trainingCourses.${i}.end_date`)"><input v-bind="bind(`trainingCourses.${i}.end_date`, `pp-training-${i}-end`)" v-model="row.end_date" type="date" /></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('trainingCourses', emptyTraining(), (i) => `pp-training-${i}-title`)"><AppIcon name="plus" :size="16" />Add training course</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Expertise <span class="pp-optional">Optional</span></legend>
            <div v-for="(item, i) in form.expertise" :key="i" class="pp-row pp-grid pp-grid--inline">
              <ProfileField :label="`Expertise ${i + 1}`" :field-id="`pp-expertise-${i}`" :error="err(`expertise.${i}`)"><input v-bind="bind(`expertise.${i}`, `pp-expertise-${i}`)" v-model.trim="form.expertise[i]" /></ProfileField>
              <button type="button" class="pp-icon-btn" :aria-label="`Remove expertise ${i + 1}`" @click="removeRow('expertise', i)"><AppIcon name="trash" :size="16" /></button>
            </div>
            <button type="button" class="pp-add" @click="addExpertise"><AppIcon name="plus" :size="16" />Add expertise</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Employment history <span class="pp-optional">Optional</span></legend>
            <p v-if="!form.experience.length" class="pp-empty">No previous roles added.</p>
            <div v-for="(row, i) in form.experience" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Position {{ i + 1 }}</span><button type="button" class="pp-icon-btn" :aria-label="`Remove employment history ${i + 1}`" @click="removeRow('experience', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid">
                <ProfileField label="Start date" :field-id="`pp-experience-${i}-from`" required :error="err(`experience.${i}.from`)"><input v-bind="bind(`experience.${i}.from`, `pp-experience-${i}-from`)" v-model="row.from" type="date" /></ProfileField>
                <ProfileField label="End date" :field-id="`pp-experience-${i}-to`" :error="err(`experience.${i}.to`)" hint="Leave empty for a current role"><input v-bind="bind(`experience.${i}.to`, `pp-experience-${i}-to`)" v-model="row.to" type="date" /></ProfileField>
                <ProfileField label="Position" :field-id="`pp-experience-${i}-position`" required :error="err(`experience.${i}.position`)"><input v-bind="bind(`experience.${i}.position`, `pp-experience-${i}-position`)" v-model.trim="row.position" /></ProfileField>
                <ProfileField label="Employer" :field-id="`pp-experience-${i}-organisation`" required :error="err(`experience.${i}.organisation`)"><input v-bind="bind(`experience.${i}.organisation`, `pp-experience-${i}-organisation`)" v-model.trim="row.organisation" /></ProfileField>
                <ProfileField label="Project title" :field-id="`pp-experience-${i}-project`"><input :id="`pp-experience-${i}-project`" v-model.trim="row.project_title" /></ProfileField>
                <ProfileField label="Job description" :field-id="`pp-experience-${i}-description`" wide><textarea :id="`pp-experience-${i}-description`" v-model.trim="row.job_description" rows="3"></textarea></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('experience', emptyEmployment(), (i) => `pp-experience-${i}-position`)"><AppIcon name="plus" :size="16" />Add work experience</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Mother tongue language</legend>
            <ProfileField label="Language" field-id="pp-mother-tongue" required :error="err('motherTongue')">
              <select v-bind="bind('motherTongue', 'pp-mother-tongue')" v-model="form.motherTongue"><option value="">Select…</option><option>Khmer</option><option>English</option><option>Chinese</option><option>Vietnamese</option><option>Other</option></select>
            </ProfileField>
            <ProfileField v-if="form.motherTongue === 'Other'" label="Specify language" field-id="pp-other-mother-tongue" required :error="err('otherMotherTongue')"><input v-bind="bind('otherMotherTongue', 'pp-other-mother-tongue')" v-model.trim="form.otherMotherTongue" /></ProfileField>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Foreign/second language proficiency <span class="pp-optional">Optional</span></legend>
            <div v-for="(row, i) in form.languages" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Language {{ i + 1 }}</span><button type="button" class="pp-icon-btn" :aria-label="`Remove language ${i + 1}`" @click="removeRow('languages', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid">
                <ProfileField label="Language" :field-id="`pp-language-${i}`" required :error="err(`languages.${i}.language`)"><input v-bind="bind(`languages.${i}.language`, `pp-language-${i}`)" v-model.trim="row.language" /></ProfileField>
                <ProfileField v-for="skill in ['reading', 'writing', 'speaking', 'understanding']" :key="skill" :label="skill.charAt(0).toUpperCase() + skill.slice(1)" :field-id="`pp-language-${i}-${skill}`" required :error="err(`languages.${i}.${skill}`)">
                  <select v-bind="bind(`languages.${i}.${skill}`, `pp-language-${i}-${skill}`)" v-model="row[skill]"><option value="">Select…</option><option>Fluent</option><option>Good</option><option>Fair</option><option>Basic</option></select>
                </ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('languages', emptyLanguage(), (i) => `pp-language-${i}`)"><AppIcon name="plus" :size="16" />Add language</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Geographic experience <span class="pp-optional">Optional</span></legend>
            <div v-for="(row, i) in form.geographicExperience" :key="i" class="pp-row">
              <div class="pp-grid">
                <ProfileField label="Country" :field-id="`pp-geography-${i}-country`" required :error="err(`geographicExperience.${i}.country`)"><select v-bind="bind(`geographicExperience.${i}.country`, `pp-geography-${i}-country`)" v-model="row.country"><option value="">Select…</option><option v-for="country in countryOptions" :key="country">{{ country }}</option></select></ProfileField>
                <ProfileField label="Province/Region" :field-id="`pp-geography-${i}-province`" required :error="err(`geographicExperience.${i}.province`)"><input v-bind="bind(`geographicExperience.${i}.province`, `pp-geography-${i}-province`)" v-model.trim="row.province" /></ProfileField>
                <button type="button" class="pp-icon-btn" :aria-label="`Remove geographic experience ${i + 1}`" @click="removeRow('geographicExperience', i)"><AppIcon name="trash" :size="16" /></button>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('geographicExperience', emptyGeography(), (i) => `pp-geography-${i}-country`)"><AppIcon name="plus" :size="16" />Add geography experience</button>
          </fieldset>

          <fieldset class="pp-section">
            <legend>Membership, association or affiliation <span class="pp-optional">Optional</span></legend>
            <div v-for="(row, i) in form.memberships" :key="i" class="pp-row">
              <div class="pp-row__head"><span>Membership {{ i + 1 }}</span><button type="button" class="pp-icon-btn" :aria-label="`Remove membership ${i + 1}`" @click="removeRow('memberships', i)"><AppIcon name="trash" :size="16" /></button></div>
              <div class="pp-grid">
                <ProfileField label="Start date" :field-id="`pp-membership-${i}-start`" required :error="err(`memberships.${i}.start_date`)"><input v-bind="bind(`memberships.${i}.start_date`, `pp-membership-${i}-start`)" v-model="row.start_date" type="date" /></ProfileField>
                <ProfileField label="End date" :field-id="`pp-membership-${i}-end`" :error="err(`memberships.${i}.end_date`)" hint="Leave empty if current"><input v-bind="bind(`memberships.${i}.end_date`, `pp-membership-${i}-end`)" v-model="row.end_date" type="date" /></ProfileField>
                <ProfileField label="Institution" :field-id="`pp-membership-${i}-institution`" required :error="err(`memberships.${i}.institution`)"><input v-bind="bind(`memberships.${i}.institution`, `pp-membership-${i}-institution`)" v-model.trim="row.institution" /></ProfileField>
                <ProfileField label="Role/Position" :field-id="`pp-membership-${i}-role`" required :error="err(`memberships.${i}.role`)"><input v-bind="bind(`memberships.${i}.role`, `pp-membership-${i}-role`)" v-model.trim="row.role" /></ProfileField>
                <ProfileField label="Description of work" :field-id="`pp-membership-${i}-description`" wide><textarea :id="`pp-membership-${i}-description`" v-model.trim="row.description" rows="3"></textarea></ProfileField>
              </div>
            </div>
            <button type="button" class="pp-add" @click="addRow('memberships', emptyMembership(), (i) => `pp-membership-${i}-start`)"><AppIcon name="plus" :size="16" />Add membership, association or affiliation</button>
          </fieldset>
        </template>

        <!-- 3. Family information -->
        <template v-else-if="current.section === 'family'">
          <fieldset class="pp-section">
            <legend>Family situation</legend>
            <div class="pp-grid">
              <ProfileField label="Marital status" field-id="pp-marital" required :error="err('maritalStatus')"><select v-bind="bind('maritalStatus', 'pp-marital')" v-model="form.maritalStatus"><option value="">Select…</option><option>Married</option><option>Single</option></select></ProfileField>
              <ProfileField v-if="form.maritalStatus === 'Married'" label="Spouse or partner’s name" field-id="pp-spouse" required :error="err('spouse')"><input v-bind="bind('spouse', 'pp-spouse')" v-model.trim="form.spouse" /></ProfileField>
              <ProfileField label="Number of children under 18" field-id="pp-child-count"><input id="pp-child-count" :value="childCount" readonly class="is-locked" /></ProfileField>
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
                <ProfileField label="Full name" :field-id="`pp-beneficiaries-${i}-name`" required :error="err(`beneficiaries.${i}.name`)"><input v-bind="bind(`beneficiaries.${i}.name`, `pp-beneficiaries-${i}-name`)" v-model.trim="row.name" /></ProfileField>
                <ProfileField label="Date of birth" :field-id="`pp-beneficiaries-${i}-dob`"><input :id="`pp-beneficiaries-${i}-dob`" v-model="row.dob" type="date" :max="today" /></ProfileField>
                <ProfileField label="Birth certificate, ID or passport number" :field-id="`pp-beneficiaries-${i}-id`"><input :id="`pp-beneficiaries-${i}-id`" v-model.trim="row.id_number" /></ProfileField>
                <ProfileField label="Relationship" :field-id="`pp-beneficiaries-${i}-relationship`"><input :id="`pp-beneficiaries-${i}-relationship`" v-model.trim="row.relationship" /></ProfileField>
                <ProfileField label="Contact number" :field-id="`pp-beneficiaries-${i}-phone`"><input :id="`pp-beneficiaries-${i}-phone`" v-model.trim="row.phone" type="tel" inputmode="tel" /></ProfileField>
                <ProfileField label="Address" :field-id="`pp-beneficiaries-${i}-address`"><input :id="`pp-beneficiaries-${i}-address`" v-model.trim="row.address" /></ProfileField>
                <ProfileField label="Share (%)" :field-id="`pp-beneficiaries-${i}-share`" required :error="err(`beneficiaries.${i}.share`)"><input v-bind="bind(`beneficiaries.${i}.share`, `pp-beneficiaries-${i}-share`)" v-model="row.share" type="number" inputmode="numeric" min="1" max="100" /></ProfileField>
              </div>
              <button type="button" class="pp-icon-btn pp-row__remove" :aria-label="`Remove beneficiary ${i + 1}`" @click="removeRow('beneficiaries', i)"><AppIcon name="trash" :size="16" /></button>
            </div>
            <p v-if="form.beneficiaries.length" class="pp-total" :class="{ 'is-off': shareTotal !== 100, 'has-error': err('shares') }"><span>Total shares</span><strong>{{ shareTotal }}%</strong></p>
            <p v-if="err('shares')" class="pp-field__error"><AppIcon name="alert-circle" :size="14" />{{ err('shares') }}</p>
            <button type="button" class="pp-add" @click="addRow('beneficiaries', { name: '', dob: '', id_number: '', relationship: '', phone: '', address: '', share: form.beneficiaries.length ? '' : 100 }, (i) => `pp-beneficiaries-${i}-name`)"><AppIcon name="plus" :size="16" />Add beneficiary</button>
          </fieldset>
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
            <legend>Declaration</legend>
            <p class="pp-declaration">{{ declarationText }}</p>
            <label class="pp-check" :class="{ 'has-error': err('decl.accurate') }">
              <input id="pp-decl-confirm" v-model="form.declarations.accurate" type="checkbox" :aria-invalid="err('decl.accurate') ? 'true' : undefined" />
              <span>I confirm the declaration above.<span class="pp-req" aria-hidden="true"> *</span></span>
            </label>
            <div class="pp-grid pp-sign">
              <ProfileField label="Signature" field-id="pp-signature" required :error="err('signature')" hint="Type your full name"><input v-bind="bind('signature', 'pp-signature')" v-model.trim="form.signature" autocomplete="name" /></ProfileField>
              <ProfileField label="Date" field-id="pp-sign-date"><input id="pp-sign-date" :value="new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })" readonly class="is-locked" /></ProfileField>
            </div>
          </fieldset>
          <section v-if="previewOpen" class="pp-section pp-preview" aria-labelledby="pp-preview-title">
            <div class="pp-row__head"><h3 id="pp-preview-title" class="pp-section__title">Profile preview</h3><button type="button" class="pp-icon-btn" aria-label="Close preview" @click="previewOpen = false"><AppIcon name="x" :size="16" /></button></div>
            <dl class="pp-review"><div v-for="[label, value] in reviewItems" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div></dl>
            <p>{{ declarationText }}</p>
            <p><strong>Signature:</strong> {{ form.signature || '—' }} · <strong>Date:</strong> {{ new Date().toLocaleDateString('en-GB') }}</p>
          </section>
          <div class="pp-declaration-actions">
            <button type="button" class="pp-btn pp-btn--ghost" @click="preview"><AppIcon name="eye" :size="16" />Preview</button>
            <button type="button" class="pp-btn pp-btn--ghost" @click="printPreview"><AppIcon name="printer" :size="16" />Print</button>
          </div>
        </template>

        <footer class="pp-form__foot">
          <RouterLink to="/admin" class="pp-btn pp-btn--ghost pp-exit">Exit</RouterLink>
          <button v-if="step > 0" type="button" class="pp-btn pp-btn--ghost" @click="show(step - 1)"><AppIcon name="arrow-left" :size="16" />Previous</button>
          <span class="pp-spacer"></span>
          <button type="button" class="pp-btn pp-btn--ghost" :disabled="saving" @click="saveDraft"><AppIcon name="save" :size="16" />{{ saving ? 'Saving…' : 'Save' }}</button>
          <button type="submit" class="pp-btn pp-btn--primary" :disabled="saving">{{ saving ? 'Saving…' : step === steps.length - 1 ? 'Submit' : 'Next' }}<AppIcon :name="step === steps.length - 1 ? 'check' : 'arrow-right'" :size="16" /></button>
        </footer>
      </form>
    </div>

    <section v-if="changeRequests.length" class="pp-card pp-requests" aria-labelledby="pp-requests-title">
      <h2 id="pp-requests-title">My change requests</h2>
      <ul>
        <li v-for="item in changeRequests" :key="item.id">
          <div class="pp-requests__main"><strong>{{ item.details.field }}</strong><span>→ {{ item.details.value }}</span><small>{{ item.details.reason }} · {{ fmtDate(item.submitted_at) }}</small></div>
          <span class="pp-badge">{{ item.status }}</span>
          <button v-if="item.can_cancel" type="button" class="pp-link pp-link--muted" @click="withdrawRequest(item.id)">Withdraw</button>
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
.pp-home { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 8px; color: var(--pp-brand-dark); font-size: .88rem; font-weight: 600; text-decoration: none; }
.pp-home:hover { text-decoration: underline; }
.pp-progress { width: 240px; flex: none; }
.pp-progress__text { margin-bottom: 6px; color: var(--pp-muted); font-size: .85rem; text-align: right; }
.pp-progress__text strong { color: var(--pp-ink); }
.pp-progress__track { height: 8px; overflow: hidden; border-radius: 99px; background: #e6edf3; }
.pp-progress__track i { display: block; height: 100%; border-radius: inherit; background: var(--pp-brand); transition: width .3s ease; }


/* Layout */
.pp-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
.pp-layout > * { min-width: 0; } /* lets the step strip scroll instead of widening the page on small screens */
.pp-card { border: 1px solid var(--pp-line); border-radius: 14px; background: #fff; box-shadow: 0 6px 20px rgba(23, 54, 95, .05); }

/* Step navigation */
.pp-steps li { min-width: 0; }
.pp-steps ol { display: grid; grid-template-columns: 1fr 1.3fr 1.05fr 1.15fr .85fr; gap: 4px; margin: 0; padding: 8px; list-style: none; border: 1px solid var(--pp-line); border-radius: 16px; background: #fff; box-shadow: 0 4px 16px rgba(23, 54, 95, .035); }
.pp-steps li { min-width: 0; }
.pp-steps__item { display: flex; box-sizing: border-box; width: 100%; min-height: 68px; align-items: center; gap: 8px; padding: 8px; border: 1px solid transparent; border-radius: 12px; background: transparent; color: var(--pp-text); text-align: left; cursor: pointer; transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease; }
.pp-steps__item:hover { background: var(--pp-soft); transform: none !important; }
.pp-steps__item:focus-visible { outline: 3px solid rgba(11, 127, 98, .25); outline-offset: 2px; }
.pp-steps__dot { display: grid; width: 34px; height: 34px; flex: none; place-items: center; border: 1.5px solid #cdd9e5; border-radius: 50%; background: #fff; color: var(--pp-muted); font-size: .9rem; font-weight: 700; }
.pp-steps__text { display: grid; min-width: 0; gap: 2px; }
.pp-steps__text strong { overflow: hidden; color: var(--pp-ink); font-size: .84rem; font-weight: 600; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.pp-steps__text small { overflow: hidden; color: var(--pp-muted); font-size: .8rem; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
.pp-steps__item.is-current { border-color: #dcefe8; background: var(--pp-brand-soft); box-shadow: inset 0 0 0 1px rgba(11, 127, 98, .025); }
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
.pp input:not([type="checkbox"]):not([type="file"]), .pp select, .pp textarea { box-sizing: border-box; width: 100%; min-height: 44px; padding: 10px 13px; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--pp-ink); font: inherit; font-size: .95rem; transition: border-color .15s, box-shadow .15s; }
.pp input:not([type="checkbox"]):not([type="file"]), .pp select { height: 44px; padding-top: 0; padding-bottom: 0; }
.pp textarea { resize: vertical; }
.pp input::placeholder { color: #9aabbd; }
.pp input:hover:not([readonly]), .pp select:hover { border-color: #aebfd1; }
.pp input:focus, .pp select:focus, .pp textarea:focus { border-color: var(--pp-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.pp input[aria-invalid="true"], .pp select[aria-invalid="true"], .pp textarea[aria-invalid="true"] { border-color: var(--pp-danger); background: #fffdfc; }
.pp input[aria-invalid="true"]:focus, .pp textarea[aria-invalid="true"]:focus { box-shadow: 0 0 0 3px rgba(192, 57, 43, .14); }
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
.pp-declaration { margin: 0 0 16px; padding: 16px; border: 1px solid var(--pp-line); border-radius: 12px; background: var(--pp-soft); color: var(--pp-text); line-height: 1.75; }
.pp-declaration-actions { display: flex; justify-content: flex-end; gap: 8px; margin: -12px 0 22px; }
.pp-preview { padding: 18px; border: 1px solid var(--pp-line); border-radius: 12px; background: #fff; }
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
.pp-exit { text-decoration: none; }
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

/* Keep all five steps on one line without horizontal scrolling. */
@media (max-width: 1080px) {
  .pp-steps__item { gap: 6px; padding: 6px; }
  .pp-steps__dot { width: 30px; height: 30px; }
  .pp-steps__text strong { font-size: .76rem; }
  .pp-steps__text small { display: none; }
}
@media (max-width: 760px) {
  .pp { padding-top: 16px; }
  .pp-head { flex-direction: column; align-items: stretch; gap: 14px; }
  .pp-head h1 { font-size: 1.35rem; }
  .pp-progress { width: 100%; }
  .pp-progress__text { text-align: left; }
  .pp-steps__item { min-height: 48px; justify-content: center; gap: 4px; padding: 4px; }
  .pp-steps__item.is-current { min-height: 48px; }
  .pp-steps__text { display: none; }
  .pp-steps__item.is-current .pp-steps__text { display: grid; }
  .pp-steps__text strong { font-size: .66rem; }
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
@media print {
  body * { visibility: hidden !important; }
  .pp-preview, .pp-preview * { visibility: visible !important; }
  .pp-preview { position: absolute; inset: 0 auto auto 0; display: block !important; width: 100%; border: 0; }
}
</style>
