<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import api from '../../services/api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const steps = ['Personal information', 'Professional Qualifications', 'Family information', 'Supporting Documents', 'Declarations']
const step = ref(0)
const qualificationsSaving = ref(false)
const qualificationsMessage = ref('')
const qualificationsError = ref('')
const familySaving = ref(false)
const familyMessage = ref('')
const familyError = ref('')
const proficiencyLevels = ['Fluent', 'Good', 'Fair', 'Basic']
const motherTongues = ['Khmer', 'English', 'Chinese', 'Vietnamese', 'Other']
const qualificationSections = [
  { key: 'education_history', title: 'Education History', addLabel: 'Add education background', fields: [{ key: 'start_date', label: 'Start Date', type: 'date' }, { key: 'end_date', label: 'End Date', type: 'date' }, { key: 'institution', label: 'Educational Institution' }, { key: 'certificate', label: 'Certificate / Diploma' }, { key: 'major', label: 'Major' }] },
  { key: 'training_courses', title: 'Training Courses', addLabel: 'Add training course', fields: [{ key: 'course_title', label: 'Course title' }, { key: 'start_date', label: 'From', type: 'date' }, { key: 'end_date', label: 'To', type: 'date' }, { key: 'provider', label: 'Training Provider' }] },
  { key: 'expertise', title: 'Expertise', addLabel: 'Add expertise', fields: [{ key: 'value', label: 'Expertise' }] },
  { key: 'employment_history', title: 'Employment History', addLabel: 'Add work experience', fields: [{ key: 'start_date', label: 'Start date', type: 'date' }, { key: 'end_date', label: 'End date', type: 'date' }, { key: 'position', label: 'Position' }, { key: 'employer', label: 'Employer' }, { key: 'project_title', label: 'Project title' }, { key: 'job_description', label: 'Job description', type: 'textarea' }] },
  { key: 'languages', title: 'Foreign / Second Language Proficiency', addLabel: 'Add language', fields: [{ key: 'language', label: 'Language' }, { key: 'reading', label: 'Reading', type: 'proficiency' }, { key: 'writing', label: 'Writing', type: 'proficiency' }, { key: 'speaking', label: 'Speaking', type: 'proficiency' }, { key: 'understanding', label: 'Understanding', type: 'proficiency' }] },
  { key: 'geographic_experience', title: 'Geographic Experience', addLabel: 'Add geography experience', fields: [{ key: 'country', label: 'Country' }, { key: 'province', label: 'Province' }] },
  { key: 'memberships', title: 'Membership, Association or Affiliation', addLabel: 'Add membership', fields: [{ key: 'start_date', label: 'From', type: 'date' }, { key: 'end_date', label: 'To', type: 'date' }, { key: 'institution', label: 'Institution' }, { key: 'role', label: 'Role / Position' }, { key: 'description', label: 'Description of Work', type: 'textarea' }] },
]
const filteredTrainingStaff = computed(() => {
  const query = trainingStaffSearch.value.trim().toLowerCase()
  return trainingStaff.value.filter((staff) => !query
    || `${staff.name} ${staff.email || ''} ${staff.role}`.toLowerCase().includes(query))
})
const operationalStaffCount = computed(() => trainingStaff.value.filter(({ role }) => !['admin', 'event_admin'].includes(role)).length)
const staffAccountManagementPath = '/admin/admin-accounts'
const emptyQualifications = () => ({
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
const loadContracts = async () => {
  contractsLoading.value = true
  contractsError.value = ''
  try {
    const { data } = await api.get('/admin/staff-contracts')
    contracts.value = data.data
  } catch (error) {
    contractsError.value = error.response?.data?.message || 'Unable to load contract history. Please try again.'
  } finally {
    contractsLoading.value = false
  }
}
const openTrainingAssignment = (course) => {
  trainingAssignmentCourse.value = course
  trainingAssignmentIds.value = [...course.staff_ids]
  trainingStaffSearch.value = ''
  trainingError.value = ''
}
const saveTrainingAssignments = async () => {
  if (!trainingAssignmentCourse.value) return
  trainingAssignmentSaving.value = true
  trainingError.value = ''
  try {
    await api.patch(`/admin/training/courses/${trainingAssignmentCourse.value.id}/assignments`, {
      staff_ids: trainingAssignmentIds.value,
    })
    trainingAssignmentCourse.value = null
    await loadTrainingData()
  } catch (error) {
    trainingError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to assign this course. Please try again.'
  } finally {
    trainingAssignmentSaving.value = false
  }
}
const openContractForm = () => {
  Object.assign(contractForm, {
    contract_type: '',
    position: '',
    department: '',
    start_date: '',
    end_date: '',
    salary: '',
    status: 'Active',
  })
  contractsError.value = ''
  contractDialogOpen.value = true
}
const saveContract = async () => {
  contractsSaving.value = true
  contractsError.value = ''
  try {
    await api.post('/admin/staff-contracts', contractForm)
    contractDialogOpen.value = false
    await loadContracts()
  } catch (error) {
    contractsError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to save this contract. Please try again.'
  } finally {
    contractsSaving.value = false
  }
}
const deleteContract = async (contract) => {
  if (!window.confirm(`Delete the ${contract.contract_type} record for ${contract.position}?`)) return
  contractsError.value = ''
  try {
    await api.delete(`/admin/staff-contracts/${contract.id}`)
    contracts.value = contracts.value.filter((record) => record.id !== contract.id)
  } catch (error) {
    contractsError.value = error.response?.data?.message || 'Unable to delete this contract. Please try again.'
  }
}
const professionalQualifications = ref(emptyQualifications())
const setQualifications = (saved = {}) => {
  saved = saved || {}
  const defaults = emptyQualifications()
  professionalQualifications.value = {
    ...defaults,
    ...saved,
    ...Object.fromEntries(qualificationSections.filter((section) => section.key !== 'expertise').map(({ key }) => [key, (Array.isArray(saved[key]) ? saved[key] : []).map((entry) => ({ ...entry }))])),
    expertise: (Array.isArray(saved.expertise) ? saved.expertise : []).map((value) => ({ value })),
  }
}
const addQualification = (section) => professionalQualifications.value[section.key].push(Object.fromEntries(section.fields.map(({ key }) => [key, ''])))
const saveQualifications = async () => {
  qualificationsSaving.value = true
  qualificationsError.value = ''
  qualificationsMessage.value = ''
  const qualifications = {
    ...professionalQualifications.value,
    expertise: professionalQualifications.value.expertise.map(({ value }) => value),
  }
  try {
    await auth.updateProfile({ professional_qualifications: qualifications, professional_qualifications_complete: false })
    qualificationsMessage.value = 'Professional qualifications saved.'
  } catch (error) {
    qualificationsError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to save professional qualifications. Please try again.'
  } finally {
    qualificationsSaving.value = false
  }
}
onMounted(async () => {
  setQualifications(auth.user?.professional_qualifications)
  setFamilyInformation(auth.user?.family_information)
  setComplianceAcknowledgements(auth.user?.compliance_acknowledgements)
  if (section.value === 'compliances') await loadCompliancePolicies()
  await loadContracts()
  try {
    await auth.fetchMe()
    setQualifications(auth.user?.professional_qualifications)
    setFamilyInformation(auth.user?.family_information)
    setComplianceAcknowledgements(auth.user?.compliance_acknowledgements)
    if (section.value === 'compliances') applyComplianceAcknowledgements()
  } catch {
    qualificationsError.value = 'Unable to load saved qualifications. You can still edit and save a draft.'
    familyError.value = 'Unable to load saved family information. You can still edit and save a draft.'
  }
  if (section.value === 'training') await loadTrainingData()
})
const setComplianceAcknowledgements = (saved = {}) => {
  complianceAcknowledgements.value = { ...(saved || {}) }
  applyComplianceAcknowledgements()
}
const applyComplianceAcknowledgements = () => {
  for (const item of complianceItems.value) item.status = complianceAcknowledgements.value[item.key] ? 'Completed' : 'Pending'
}
const loadCompliancePolicies = async () => {
  try {
    const { data } = await api.get('/admin/compliance-policies')
    complianceItems.value = data.data.map((policy) => ({
      ...policy,
      status: complianceAcknowledgements.value[policy.key] ? 'Completed' : 'Pending',
    }))
  } catch (error) {
    complianceError.value = error.response?.data?.message || 'Unable to load compliance policies. Please try again.'
  }
}
const openCompliancePolicyEditor = (policy = null) => {
  complianceError.value = ''
  compliancePolicyEditor.value = policy || {}
  Object.assign(compliancePolicyForm, {
    title: policy?.title || '',
    category: policy?.category || '',
    description: policy?.description || '',
    icon: policy?.icon || 'shield',
  })
}
const saveCompliancePolicy = async () => {
  compliancePolicySaving.value = true
  complianceError.value = ''
  try {
    if (compliancePolicyEditor.value) {
      await api.put(`/admin/compliance-policies/${compliancePolicyEditor.value.id}`, compliancePolicyForm)
    } else {
      await api.post('/admin/compliance-policies', compliancePolicyForm)
    }
    compliancePolicyEditor.value = null
    await auth.fetchMe()
    setComplianceAcknowledgements(auth.user?.compliance_acknowledgements)
    await loadCompliancePolicies()
  } catch (error) {
    complianceError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to save this compliance policy. Please try again.'
  } finally {
    compliancePolicySaving.value = false
  }
}
const deleteCompliancePolicy = async (policy) => {
  if (!window.confirm(`Delete "${policy.title}" for all staff? Their acknowledgement for this policy will also be removed.`)) return
  complianceError.value = ''
  try {
    await api.delete(`/admin/compliance-policies/${policy.id}`)
    complianceItems.value = complianceItems.value.filter((item) => item.id !== policy.id)
    delete complianceAcknowledgements.value[policy.key]
    await auth.fetchMe()
    setComplianceAcknowledgements(auth.user?.compliance_acknowledgements)
  } catch (error) {
    complianceError.value = error.response?.data?.message || 'Unable to delete this compliance policy. Please try again.'
  }
}
const emptyFamilyInformation = () => ({
  marital_status: '',
  spouse_partner_name: '',
  children: [],
  emergency_contacts: [],
  beneficiaries: [],
})
const familyInformation = ref(emptyFamilyInformation())
const setFamilyInformation = (saved = {}) => {
  const value = saved || {}
  familyInformation.value = {
    ...emptyFamilyInformation(),
    ...value,
    children: (Array.isArray(value.children) ? value.children : []).map((entry) => ({ ...entry })),
    emergency_contacts: (Array.isArray(value.emergency_contacts) ? value.emergency_contacts : []).map((entry) => ({ ...entry })),
    beneficiaries: (Array.isArray(value.beneficiaries) ? value.beneficiaries : []).map((entry) => ({ ...entry })),
  }
}
const countChildrenUnder18 = computed(() => familyInformation.value.children.filter((child) => {
  if (!child.date_of_birth) return false
  const birthDate = new Date(`${child.date_of_birth}T00:00:00`)
  const cutoff = new Date()
  cutoff.setFullYear(cutoff.getFullYear() - 18)
  return !Number.isNaN(birthDate.getTime()) && birthDate > cutoff
}).length)
const totalBeneficiaryShares = computed(() => familyInformation.value.beneficiaries.reduce((total, entry) => total + (Number(entry.share_percent) || 0), 0))
const saveFamilyInformation = async (complete = false) => {
  familySaving.value = true
  familyError.value = ''
  familyMessage.value = ''
  try {
    await auth.updateProfile({
      family_information: familyInformation.value,
      family_information_complete: complete,
    })
    familyMessage.value = 'Family information saved.'
    return true
  } catch (error) {
    familyError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to save family information. Please try again.'
    return false
  } finally {
    familySaving.value = false
  }
}
const addChild = () => familyInformation.value.children.push({ name: '', date_of_birth: '', sex: '' })
const addEmergencyContact = () => familyInformation.value.emergency_contacts.push({ name: '', relationship: '', phone: '', email: '', current_address: '' })
const addBeneficiary = () => familyInformation.value.beneficiaries.push({ full_name: '', date_of_birth: '', identity_number: '', relationship: '', contact_number: '', address: '', share_percent: '' })
const complianceSearch = ref('')
const complianceStatusFilter = ref('all')
const complianceCategoryFilter = ref('all')
const selectedCompliance = ref(null)
const complianceSavingKeys = ref({})
let complianceSaveQueue = Promise.resolve()
const complianceError = ref('')
const complianceAcknowledgements = ref({ ...(auth.user?.compliance_acknowledgements || {}) })
const compliancePolicyEditor = ref(null)
const compliancePolicySaving = ref(false)
const canManageCompliance = computed(() => ['admin', 'event_admin', 'manager'].includes(auth.user?.role))
const canManageTraining = computed(() => ['admin', 'event_admin'].includes(auth.user?.role))
const completedTrainingCount = computed(() => trainingCourses.value.filter(({ status }) => status === 'Completed').length)
const inProgressTrainingCount = computed(() => trainingCourses.value.filter(({ status }) => status === 'In Progress').length)
const pendingTrainingCount = computed(() => trainingCourses.value.filter(({ status }) => status === 'Pending').length)
const trainingCompletionRate = computed(() => trainingCourses.value.length
  ? Math.round(completedTrainingCount.value / trainingCourses.value.length * 100)
  : 0)
const suggestedTrainingTopics = [
  'Child Safeguarding',
  'Adult Safeguarding (PSEAH)',
  'Staff Safeguarding',
  'Conflict of Interest',
  'Anti-Corruption and Anti-Money Laundering',
  'Anti-Fraud',
  'GEDSI Policy',
  'Environmental Safeguarding Policy',
]
const loadTrainingData = async () => {
  trainingLoading.value = true
  trainingError.value = ''
  try {
    const responses = await Promise.all([
      api.get('/training/courses'),
      ...(canManageTraining.value ? [api.get('/admin/training/courses'), api.get('/admin/training/staff')] : []),
    ])
    trainingCourses.value = responses[0].data.data
    if (canManageTraining.value) {
      adminTrainingCourses.value = responses[1].data.data
      trainingStaff.value = responses[2].data.data
    }
  } catch (error) {
    trainingError.value = error.response?.data?.message || 'Unable to load training courses. Please try again.'
  } finally {
    trainingLoading.value = false
  }
}
const trainingDraftForTopic = (topic) => {
  const isAdultSafeguarding = topic === 'Adult Safeguarding (PSEAH)'
  return {
    title: topic,
    category: topic,
    description: isAdultSafeguarding
      ? 'A practical introduction to preventing and responding to sexual exploitation, abuse, and harassment.'
      : `Starter course for ${topic}. Review and adapt this material to match the current organisational policy before assigning it.`,
    content: isAdultSafeguarding
      ? `PURPOSE
This starter course introduces core adult safeguarding and PSEAH awareness. It is a learning aid, not a replacement for your organisation's safeguarding policy, reporting procedure, or applicable law.

RECOGNISE AND PREVENT HARM
Sexual exploitation, abuse, and harassment can involve misuse of power, trust, or vulnerability. Maintain professional boundaries, treat people with dignity, and never use a position of authority to seek sexual contact, favors, or personal benefit. A person's apparent agreement does not make abuse of power acceptable.

RESPOND TO A CONCERN OR DISCLOSURE
Listen calmly and respectfully. Take the concern seriously, avoid leading questions, and do not investigate or promise an outcome. Explain that information may need to be shared with the designated safeguarding focal point to help keep people safe. Record the person's own words and relevant facts as soon as possible, following organisational procedure.

REPORT AND PROTECT PRIVACY
Report concerns promptly through your organisation's designated safeguarding or complaints channel. If someone faces immediate danger, follow local emergency procedures. Share information only with people who need it to respond; do not discuss the case informally or confront the person alleged to have caused harm.

NEXT STEP
Before handling a real case, review the current organisational PSEAH policy and confirm the safeguarding focal point and reporting channels with your manager.`
      : `PURPOSE
This is a starter learning outline for ${topic}. Before assigning this course, add the approved organisational policy, local reporting channels, and any role-specific requirements.

KEY EXPECTATIONS
Read and follow the current organisational ${topic} policy. Treat colleagues and community members respectfully, act with integrity, and raise questions if a situation is unclear. Do not make promises or take action beyond your role or training.

WHEN A CONCERN ARISES
Take concerns seriously. Record relevant facts accurately and report them promptly through the organisation's approved channel or designated focal point. Protect privacy and share information only with people who need it to respond. Follow the current policy and your manager's guidance.

BEFORE THIS COURSE IS ASSIGNED
Replace this starter outline with the approved policy material and verify all reporting details. This draft is not a substitute for official organisational policy or applicable law.`,
    due_date: '',
    pass_percent: 80,
    staff_ids: [],
    quiz: isAdultSafeguarding
      ? [
          { question: 'A colleague shares a safeguarding concern. What is the best first response?', options: ['Listen calmly, take it seriously, and explain the reporting process', 'Investigate the allegation yourself', 'Promise to keep it completely secret'], answer: 0 },
          { question: 'How should you share information about a concern?', options: ['Only through appropriate channels with people who need it to respond', 'With colleagues so everyone is aware', 'On a personal social media account without names'], answer: 0 },
          { question: 'Where should staff confirm the organisation’s specific reporting procedure?', options: ['The current organisational policy and designated safeguarding focal point', 'An unofficial online post', 'A course quiz only'], answer: 0 },
        ]
      : [
          { question: `Where should staff check the official requirements for ${topic}?`, options: ['The current organisational policy and approved guidance', 'An unofficial online post', 'A colleague’s guess'], answer: 0 },
          { question: 'What should you do if you are unsure how to handle a concern?', options: ['Use the approved reporting channel or ask the designated focal point', 'Ignore it until someone else raises it', 'Share it informally with everyone'], answer: 0 },
        ],
  }
}
const openTrainingEditor = (course = null) => {
  if (!course || typeof course !== 'object' || !('id' in course)) course = null
  trainingError.value = ''
  trainingEditorId.value = course?.id || null
  Object.assign(trainingForm, course ? {
    ...course,
    staff_ids: Array.isArray(course.staff_ids) ? [...course.staff_ids] : [],
    quiz: (Array.isArray(course.quiz) ? course.quiz : []).map((question) => ({ ...question, options: [...question.options] })),
  } : trainingDraftForTopic(trainingTopics[0]))
  trainingCategorySelection.value = trainingTopics.includes(trainingForm.category) ? trainingForm.category : 'Other'
  trainingEditor.value = true
}
const createSuggestedTraining = (topic) => {
  openTrainingEditor()
  Object.assign(trainingForm, trainingDraftForTopic(topic))
  trainingCategorySelection.value = topic
}
const changeTrainingCategory = () => {
  trainingForm.category = trainingCategorySelection.value === 'Other' ? '' : trainingCategorySelection.value
}
const addTrainingQuestion = () => trainingForm.quiz.push({ question: '', options: ['', ''], answer: 0 })
const removeTrainingQuestion = (index) => {
  if (trainingForm.quiz.length > 1) trainingForm.quiz.splice(index, 1)
}
const addTrainingOption = (question) => {
  if (question.options.length < 6) question.options.push('')
}
const removeTrainingOption = (question, index) => {
  if (question.options.length > 2) {
    question.options.splice(index, 1)
    if (question.answer >= question.options.length) question.answer = 0
  }
}
const retryTrainingQuiz = () => {
  trainingAnswers.value = []
  trainingError.value = ''
  trainingStage.value = 'quiz'
}
const printTrainingCertificate = () => window.print()
const saveTrainingCourse = async () => {
  trainingSaving.value = true
  trainingError.value = ''
  try {
    const endpoint = trainingEditorId.value
      ? `/admin/training/courses/${trainingEditorId.value}`
      : '/admin/training/courses'
    const { data } = trainingEditorId.value
      ? await api.put(endpoint, trainingForm)
      : await api.post(endpoint, trainingForm)
    if (trainingEditorId.value) {
      adminTrainingCourses.value = adminTrainingCourses.value.map((course) => course.id === data.data.id ? data.data : course)
    } else {
      adminTrainingCourses.value.unshift(data.data)
    }
    trainingEditor.value = false
    await loadTrainingData()
  } catch (error) {
    trainingError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to save this training course. Please try again.'
  } finally {
    trainingSaving.value = false
  }
}
const deleteTrainingCourse = async (course) => {
  if (!window.confirm(`Delete the "${course.title}" course and its assignments?`)) return
  trainingError.value = ''
  try {
    await api.delete(`/admin/training/courses/${course.id}`)
    await loadTrainingData()
  } catch (error) {
    trainingError.value = error.response?.data?.message || 'Unable to delete this training course. Please try again.'
  }
}
const openAssignedCourse = async (assignment) => {
  trainingError.value = ''
  trainingResult.value = null
  trainingAnswers.value = []
  try {
    const { data } = await api.post(`/training/assignments/${assignment.id}/start`)
    selectedCourse.value = data.data
    trainingStage.value = data.data.status === 'Completed' ? 'certificate' : 'content'
  } catch (error) {
    trainingError.value = error.response?.data?.message || 'Unable to open this course. Please try again.'
  }
}
const submitTrainingQuiz = async () => {
  if (!selectedCourse.value || selectedCourse.value.course.quiz.some((_, index) => !Number.isInteger(trainingAnswers.value[index]))) {
    trainingError.value = 'Please answer every question before submitting.'
    return
  }
  trainingSaving.value = true
  trainingError.value = ''
  try {
    const { data } = await api.post(`/training/assignments/${selectedCourse.value.id}/quiz`, { answers: trainingAnswers.value })
    trainingResult.value = data
    selectedCourse.value = data.data
    trainingStage.value = data.passed ? 'certificate' : 'result'
    await loadTrainingData()
  } catch (error) {
    trainingError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to submit your quiz. Please try again.'
  } finally {
    trainingSaving.value = false
  }
}
const compliancePolicyForm = reactive({ title: '', category: '', description: '', icon: 'shield' })
const complianceItems = ref([
  { id: 1, key: 'adult_safeguarding_pseah', title: 'Adult Safeguarding (PSEAH)', category: 'Safeguarding', description: 'I confirm that I have read and understood the Adult Safeguarding (PSEAH) policy.', status: 'Pending', icon: 'shield' },
  { id: 2, key: 'anti_fraud', title: 'Anti-Fraud', category: 'Financial & Integrity', description: 'I confirm that I have read and understood the Anti-Fraud policy.', status: 'Pending', icon: 'alert' },
  { id: 3, key: 'child_safeguarding', title: 'Child Safeguarding', category: 'Safeguarding', description: 'I confirm that I have read and understood the Child Safeguarding policy.', status: 'Pending', icon: 'shield' },
  { id: 4, key: 'staff_code_of_conduct', title: 'Staff Code of Conduct', category: 'Ethics & Conduct', description: 'I confirm that I have read and understood the Staff Code of Conduct.', status: 'Pending', icon: 'drop' },
  { id: 5, key: 'conflict_of_interest_declaration', title: 'Conflict of Interest Declaration', category: 'Ethics & Conduct', description: 'I declare that I have disclosed any actual, potential, or perceived conflicts of interest.', status: 'Pending', icon: 'drop' },
  { id: 6, key: 'data_protection', title: 'Data Protection and Confidentiality', category: 'Data & Privacy', description: 'I confirm that I will protect personal and confidential information in line with organizational policies.', status: 'Pending', icon: 'shield' },
])
const contractTypes = [
  'Probationary',
  'Full-Time Contract',
  'Part-Time Contract',
  'Intermittent Contract',
  'Volunteering Contract',
  'Internship Contract',
]
const contracts = ref([])
const contractsLoading = ref(false)
const contractsSaving = ref(false)
const contractsError = ref('')
const contractDialogOpen = ref(false)
const contractForm = reactive({
  contract_type: '',
  position: '',
  department: '',
  start_date: '',
  end_date: '',
  salary: '',
  status: 'Active',
})
const trainingCourses = ref([])
const adminTrainingCourses = ref([])
const trainingStaff = ref([])
const trainingLoading = ref(false)
const trainingSaving = ref(false)
const trainingError = ref('')
const trainingEditor = ref(false)
const trainingEditorId = ref(null)
const trainingAssignmentCourse = ref(null)
const trainingAssignmentIds = ref([])
const trainingStaffSearch = ref('')
const trainingAssignmentSaving = ref(false)
const selectedCourse = ref(null)
const trainingStage = ref('content')
const trainingAnswers = ref([])
const trainingResult = ref(null)
const trainingTopics = [
  'Child Safeguarding',
  'Adult Safeguarding (PSEAH)',
  'Staff Safeguarding',
  'Conflict of Interest',
  'Anti-Corruption and Anti-Money Laundering',
  'Anti-Fraud',
  'GEDSI Policy',
  'Environmental Safeguarding Policy',
]
const trainingForm = reactive({
  title: '',
  category: 'Child Safeguarding',
  description: '',
  content: '',
  due_date: '',
  pass_percent: 80,
  staff_ids: [],
  quiz: [{ question: '', options: ['', ''], answer: 0 }],
})
const trainingCategorySelection = ref(trainingTopics[0])
const section = computed(() => typeof route.query.section === 'string' ? route.query.section : 'my-profile')
watch(section, (value) => {
  if (value === 'training') void loadTrainingData()
})
const sectionLabels = {
  compliances: 'Compliances', 'contract-history': 'Contract History', training: 'Staff Online Training',
  'time-management': 'Time Management', 'leave-application': 'Leave Application',
  'overtime-request': 'Overtime Request', 'monthly-timesheet': 'Monthly Timesheet', 'other-requests': 'Other Requests',
  'finance-management': 'Finance Management', 'my-requests': 'My Requests',
}
const heading = computed(() => sectionLabels[section.value] || 'Personnel Profile')
const complianceCategories = computed(() => [...new Set(complianceItems.value.map((item) => item.category))])
const filteredCompliances = computed(() => complianceItems.value.filter((item) => {
  const query = complianceSearch.value.trim().toLowerCase()
  return (!query || `${item.title} ${item.category} ${item.description}`.toLowerCase().includes(query))
    && (complianceStatusFilter.value === 'all' || item.status.toLowerCase() === complianceStatusFilter.value)
    && (complianceCategoryFilter.value === 'all' || item.category === complianceCategoryFilter.value)
}))
const completedCompliances = computed(() => complianceItems.value.filter((item) => item.status === 'Completed').length)
const pendingCompliances = computed(() => complianceItems.value.filter((item) => item.status === 'Pending').length)
const exitProfile = () => router.push('/admin')
const completeCompliance = async (item) => {
  if (complianceSavingKeys.value[item.key] || item.status === 'Completed') return
  complianceSavingKeys.value = { ...complianceSavingKeys.value, [item.key]: true }
  complianceError.value = ''
  const saveRequest = complianceSaveQueue.then(async () => {
    await auth.updateProfile({
      compliance_acknowledgements: { [item.key]: true },
    })
    setComplianceAcknowledgements(auth.user?.compliance_acknowledgements)
    if (selectedCompliance.value?.id === item.id) selectedCompliance.value = null
  })
  complianceSaveQueue = saveRequest.catch(() => {})
  try {
    await saveRequest
  } catch (error) {
    complianceError.value = Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.response?.data?.message
      || 'Unable to save this compliance acknowledgement. Please try again.'
  } finally {
    const { [item.key]: _, ...remaining } = complianceSavingKeys.value
    complianceSavingKeys.value = remaining
  }
}
</script>

<template>
  <AdminLayout>
    <div class="profile-workspace">
      <div v-if="section !== 'training'" class="profile-page-top"><div><h1>{{ heading }}</h1><p>{{ section === 'compliances' ? 'Manage your required policies and declarations.' : section === 'contract-history' ? 'All contracts on record — probationary, full-time, part-time, and more.' : 'Complete all five tabs — your progress is saved per tab.' }}</p></div></div>

      <template v-if="section === 'my-profile' || !sectionLabels[section]">
        <ol class="profile-stepper" aria-label="Profile steps">
          <li v-for="(label, index) in steps" :key="label" :class="{ done: index < step, current: index === step }">
            <span class="step-dot">{{ index < step ? '✓' : index + 1 }}</span><span>{{ label }}</span>
          </li>
        </ol>

        <section class="profile-form-card">
          <template v-if="step === 0">
          <div class="form-section-title"><span>♙</span><h2>Basic Information</h2><span class="profile-initials">BP</span></div>
          <div class="form-grid basic-grid">
            <label>Staff ID<input value="ST-00123" disabled /><small>Auto generated by the system</small></label>
            <label>First Name <b>*</b><input value="Borey" /><button class="request-change" type="button">Request change</button></label>
            <label>Surname <b>*</b><input value="Penh" /><button class="request-change" type="button">Request change</button></label>
            <label>Name in Khmer <span class="khmer-hint">(ឈ្មោះជាភាសាខ្មែរ)</span><input value="បុរី ពេញ" /><button class="request-change" type="button">Request change</button></label>
            <label>Gender <b>*</b><select><option>Male</option><option>Female</option></select><button class="request-change" type="button">Request change</button></label>
            <label>Date of Birth <b>*</b><input value="15/08/1992" /><button class="request-change" type="button">Request change</button></label>
            <label>Place of Birth <b>*</b><input value="Battambang Province, Cambodia" /><button class="request-change" type="button">Request change</button></label>
            <label>Citizenship (if foreigner)<input value="Khmer" /><button class="request-change" type="button">Request change</button></label>
            <label class="full-field">ID / Passport Number <b>*</b><input value="010892415" /></label>
          </div>
          <div class="form-divider"></div>
          <div class="form-section-title"><span>☎</span><h2>Contact Information</h2></div>
          <div class="form-grid basic-grid">
            <label>Primary phone number <b>*</b><input type="tel" /></label>
            <label>Alternate phone number<input type="tel" /></label>
            <label>Email address <b>*</b><input type="email" /></label>
            <label class="full-field">Current address<input /></label>
          </div>
          </template>
          <template v-else-if="step === 1">
          <div class="form-section-title"><span>✓</span><h2>Professional Qualifications</h2></div>
          <p class="qualification-intro">Complete this section of your personnel profile.</p>
          <p v-if="qualificationsError" class="qualification-notice is-error" role="alert">{{ qualificationsError }}</p>
          <p v-if="qualificationsMessage" class="qualification-notice is-success" role="status">{{ qualificationsMessage }}</p>

          <section v-for="section in qualificationSections.slice(0, 4)" :key="section.key" class="profile-qualification-section">
            <div class="form-section-title"><span>▦</span><h3>{{ section.title }}</h3></div>
            <div class="profile-qualification-table-wrap">
              <table class="profile-qualification-table">
                <thead><tr><th v-for="field in section.fields" :key="field.key">{{ field.label }}</th><th>Update</th></tr></thead>
                <tbody>
                  <tr v-for="(entry, index) in professionalQualifications[section.key]" :key="index">
                    <td v-for="field in section.fields" :key="field.key">
                      <textarea v-if="field.type === 'textarea'" v-model="entry[field.key]" rows="2"></textarea>
                      <input v-else v-model="entry[field.key]" :type="field.type || 'text'" />
                    </td>
                    <td><button type="button" class="qualification-delete" @click="professionalQualifications[section.key].splice(index, 1)">Delete</button></td>
                  </tr>
                  <tr v-if="professionalQualifications[section.key].length === 0"><td :colspan="section.fields.length + 1" class="qualification-empty">No information added yet.</td></tr>
                </tbody>
              </table>
            </div>
            <button type="button" class="add-row" @click="addQualification(section)">+ {{ section.addLabel }}</button>
          </section>

          <section class="profile-qualification-section">
            <div class="form-section-title"><span>文</span><h3>Mother tongue language</h3></div>
            <label class="mother-tongue-field">Language
              <select v-model="professionalQualifications.mother_tongue">
                <option value="">Select language</option>
                <option v-for="language in motherTongues" :key="language" :value="language">{{ language }}</option>
              </select>
            </label>
            <label v-if="professionalQualifications.mother_tongue === 'Other'" class="mother-tongue-field">Specify language
              <input v-model.trim="professionalQualifications.other_mother_tongue" maxlength="100" />
            </label>
          </section>

          <section v-for="section in qualificationSections.slice(4)" :key="section.key" class="profile-qualification-section">
            <div class="form-section-title"><span>▦</span><h3>{{ section.title }}</h3></div>
            <div class="profile-qualification-table-wrap">
              <table class="profile-qualification-table">
                <thead><tr><th v-for="field in section.fields" :key="field.key">{{ field.label }}</th><th>Update</th></tr></thead>
                <tbody>
                  <tr v-for="(entry, index) in professionalQualifications[section.key]" :key="index">
                    <td v-for="field in section.fields" :key="field.key">
                      <select v-if="field.type === 'proficiency'" v-model="entry[field.key]"><option value="">Select</option><option v-for="level in proficiencyLevels" :key="level">{{ level }}</option></select>
                      <textarea v-else-if="field.type === 'textarea'" v-model="entry[field.key]" rows="2"></textarea>
                      <input v-else v-model="entry[field.key]" :type="field.type || 'text'" />
                    </td>
                    <td><button type="button" class="qualification-delete" @click="professionalQualifications[section.key].splice(index, 1)">Delete</button></td>
                  </tr>
                  <tr v-if="professionalQualifications[section.key].length === 0"><td :colspan="section.fields.length + 1" class="qualification-empty">No information added yet.</td></tr>
                </tbody>
              </table>
            </div>
            <button type="button" class="add-row" @click="addQualification(section)">+ {{ section.addLabel }}</button>
          </section>
          </template>
          <template v-else-if="step === 2">
          <div class="form-section-title"><span>▣</span><h2>Family Information</h2></div>
          <p v-if="familyError" class="qualification-notice is-error" role="alert">{{ familyError }}</p>
          <p v-if="familyMessage" class="qualification-notice is-success" role="status">{{ familyMessage }}</p>
          <div class="form-section-title"><span>▣</span><h3>Family Situation</h3></div>
          <div class="form-grid family-grid">
            <label>Marital status<select v-model="familyInformation.marital_status"><option value="">Select status</option><option>Married</option><option>Single</option><option>Divorced</option><option>Widowed</option><option>Partnered</option></select></label>
            <label>Spouse / partner name<input v-model.trim="familyInformation.spouse_partner_name" maxlength="200" /></label>
            <label>Number of children (below 18 years old)<input type="number" min="0" :value="countChildrenUnder18" readonly /></label>
          </div>
          <small class="field-hint">Automatically sourced from the children below.</small>

          <div class="repeat-label">Children detail</div>
          <div v-for="(child, index) in familyInformation.children" :key="index" class="child-row">
            <label>Name<input v-model="child.name" placeholder="Child's name" /></label>
            <label>Date of Birth<input v-model="child.date_of_birth" type="date" /></label>
            <label>Sex<select v-model="child.sex"><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option><option>Prefer not to say</option></select></label>
            <button class="remove-row" type="button" :aria-label="`Remove ${child.name || 'child'}`" @click="familyInformation.children.splice(index, 1)">Delete</button>
          </div>
          <button class="add-row" type="button" @click="addChild">+ Add child</button>

          <div class="form-divider"></div>
          <section class="repeat-section">
            <div class="form-section-title"><span>▣</span><h2>Emergency Contact</h2><span class="info-mark">i</span></div>
            <div v-for="(contact, index) in familyInformation.emergency_contacts" :key="index" class="contact-grid">
              <label>Name <b>*</b><input v-model="contact.name" /></label>
              <label>Relationship <b>*</b><input v-model="contact.relationship" placeholder="e.g. Father, Spouse" /></label>
              <label>Phone <b>*</b><input v-model="contact.phone" type="tel" /></label>
              <label class="wide-field">Email<input v-model="contact.email" type="email" /></label>
              <label class="wide-field">Current Address<input v-model="contact.current_address" /></label>
              <button class="remove-row" type="button" :aria-label="`Remove ${contact.name || 'emergency contact'}`" @click="familyInformation.emergency_contacts.splice(index, 1)">Delete contact</button>
            </div>
            <button class="add-row" type="button" @click="addEmergencyContact">+ Add emergency contact</button>
          </section>

          <div class="form-divider"></div>
          <section class="repeat-section beneficiaries-section">
            <div class="form-section-title"><span>▣</span><h2>Beneficiaries</h2><span class="info-mark">i</span><small>Total shares: {{ totalBeneficiaryShares }}%</small></div>
            <div v-for="(beneficiary, index) in familyInformation.beneficiaries" :key="index" class="beneficiary-row">
              <label>Full Name<input v-model="beneficiary.full_name" /></label>
              <label>Date of Birth<input v-model="beneficiary.date_of_birth" type="date" /></label>
              <label>Birth certificate, ID or passport number<input v-model="beneficiary.identity_number" /></label>
              <label>Relationship<input v-model="beneficiary.relationship" /></label>
              <label>Contact Number<input v-model="beneficiary.contact_number" type="tel" /></label>
              <label>Address<input v-model="beneficiary.address" /></label>
              <label>Share of Benefits (%)<input v-model="beneficiary.share_percent" type="number" min="0" max="100" step="0.01" /></label>
              <button class="remove-row" type="button" :aria-label="`Remove ${beneficiary.full_name || 'beneficiary'}`" @click="familyInformation.beneficiaries.splice(index, 1)">Delete beneficiary</button>
            </div>
            <button class="add-row" type="button" @click="addBeneficiary">+ Add beneficiary</button>
          </section>
          </template>
          <div v-else class="profile-step-placeholder"><div class="form-section-title"><span>✓</span><h2>{{ steps[step] }}</h2></div><p>Complete this section of your personnel profile.</p></div>

          <footer class="form-actions"><button type="button" class="home-button">Home</button><span class="step-count">Tab {{ step + 1 }} of 5</span><button type="button" :disabled="step === 0" @click="step = Math.max(0, step - 1)">Previous</button><div class="action-spacer"></div><button type="button" :disabled="qualificationsSaving || familySaving" @click="step === 1 ? saveQualifications() : step === 2 ? saveFamilyInformation(false) : undefined">{{ qualificationsSaving || familySaving ? 'Saving…' : 'Save' }}</button><button type="button" @click="exitProfile">Exit</button><button class="next-button" type="button" :disabled="familySaving" @click="step === 2 ? saveFamilyInformation(true).then((saved) => { if (saved) step = Math.min(4, step + 1) }) : step = Math.min(4, step + 1)">{{ step === 4 ? 'Finish' : 'Next →' }}</button></footer>
        </section>

        <section class="change-requests">
          <div class="form-section-title"><span>✎</span><h2>My Change Requests</h2></div>
          <div class="request-table"><div class="request-head"><span>FIELD</span><span>REQUESTED VALUE</span><span>REASON</span><span>STATUS</span></div><div class="request-row"><span>ID / Passport Number</span><span>01002345</span><span>Received my renewed national ID; the number on file was misplaced.</span><span><b>Pending</b></span><button type="button" aria-label="Remove request">×</button></div></div>
        </section>
      </template>
      <section v-else-if="section === 'compliances'" class="compliance-page">
        <p v-if="complianceError" class="compliance-save-error" role="alert">{{ complianceError }}</p>
        <section class="compliance-overview" aria-label="Compliance overview">
          <div class="compliance-filters"><label class="compliance-search"><span aria-hidden="true">⌕</span><input v-model="complianceSearch" type="search" placeholder="Search compliance..." aria-label="Search compliance" /></label>
            <select v-model="complianceStatusFilter" aria-label="Filter by status"><option value="all">All statuses</option><option value="completed">Completed</option><option value="pending">Pending</option></select>
            <select v-model="complianceCategoryFilter" aria-label="Filter by category"><option value="all">All categories</option><option v-for="category in complianceCategories" :key="category" :value="category">{{ category }}</option></select>
          </div>
          <div class="compliance-stats"><article class="required"><small>REQUIRED</small><strong>{{ complianceItems.length }}</strong><span>compliance</span></article><article class="completed"><small>COMPLETED</small><strong>{{ completedCompliances }}</strong><span>✓ up to date</span></article><article class="pending"><small>PENDING</small><strong>{{ pendingCompliances }}</strong><span>⚠ action needed</span></article><article class="expired"><small>EXPIRED</small><strong>0</strong><span>—</span></article></div>
          <div class="compliance-progress"><div><strong>Compliance Progress</strong><b>{{ Math.round(completedCompliances / Math.max(complianceItems.length, 1) * 100) }}%</b></div><div class="compliance-progress-track"><i :style="{ width: `${completedCompliances / Math.max(complianceItems.length, 1) * 100}%` }"></i></div><small>{{ completedCompliances }} of {{ complianceItems.length }} completed</small></div>
        </section>
        <div class="compliance-list-toolbar"><h2 class="compliance-list-title">Required Compliance</h2><button v-if="canManageCompliance" type="button" class="compliance-manage-button" @click="openCompliancePolicyEditor()">+ Add policy</button></div>
        <div v-if="filteredCompliances.length" class="compliance-grid">
          <article v-for="item in filteredCompliances" :key="item.id" class="compliance-card">
            <header><span class="compliance-icon" :class="`icon-${item.icon}`">{{ item.icon === 'shield' ? '♢' : item.icon === 'alert' ? '!' : '◉' }}</span><span class="compliance-status" :class="item.status.toLowerCase()">{{ item.status }}</span></header>
            <strong class="compliance-card-title">{{ item.title }}</strong><small class="compliance-category" :class="`category-${item.icon}`">{{ item.category }}</small><p>{{ item.description }}</p><small class="compliance-assignee">Assigned by HR</small>
            <footer><button type="button" class="compliance-secondary" @click="selectedCompliance = item">View Details</button><button type="button" class="compliance-primary" :disabled="item.status === 'Completed' || complianceSavingKeys[item.key]" @click="completeCompliance(item)">{{ item.status === 'Completed' ? 'Completed' : complianceSavingKeys[item.key] ? 'Saving…' : 'Complete Now →' }}</button></footer>
            <div v-if="canManageCompliance" class="compliance-policy-actions"><button type="button" @click="openCompliancePolicyEditor(item)">Edit policy</button><button type="button" @click="deleteCompliancePolicy(item)">Delete policy</button></div>
          </article>
        </div>
        <div v-else class="compliance-empty">No compliance items match your search and filters.</div>
        <div v-if="selectedCompliance" class="compliance-modal-backdrop" @click.self="selectedCompliance = null"><section class="compliance-modal" role="dialog" aria-modal="true" :aria-labelledby="`compliance-title-${selectedCompliance.id}`"><button class="compliance-modal-close" type="button" aria-label="Close" @click="selectedCompliance = null">×</button><span class="compliance-category">{{ selectedCompliance.category }}</span><h2 :id="`compliance-title-${selectedCompliance.id}`">{{ selectedCompliance.title }}</h2><p>{{ selectedCompliance.description }}</p><p class="compliance-modal-note">This requirement was assigned by HR. Review the policy with your HR team if you have questions.</p><footer><button class="compliance-secondary" type="button" @click="selectedCompliance = null">Close</button><button v-if="selectedCompliance.status !== 'Completed'" class="compliance-primary" type="button" :disabled="complianceSavingKeys[selectedCompliance.key]" @click="completeCompliance(selectedCompliance)">{{ complianceSavingKeys[selectedCompliance.key] ? 'Saving…' : 'Confirm and Complete' }}</button></footer></section></div>
        <div v-if="compliancePolicyEditor" class="compliance-modal-backdrop" @click.self="compliancePolicyEditor = null"><section class="compliance-modal compliance-policy-editor" role="dialog" aria-modal="true" aria-labelledby="compliance-editor-title"><button class="compliance-modal-close" type="button" aria-label="Close" @click="compliancePolicyEditor = null">×</button><span class="compliance-category">HR POLICY MANAGEMENT</span><h2 id="compliance-editor-title">{{ compliancePolicyEditor.id ? 'Edit compliance policy' : 'Add compliance policy' }}</h2><form @submit.prevent="saveCompliancePolicy"><label>Policy title<input v-model.trim="compliancePolicyForm.title" maxlength="150" required /></label><label>Category<input v-model.trim="compliancePolicyForm.category" maxlength="100" required /></label><label>Policy description<textarea v-model.trim="compliancePolicyForm.description" maxlength="2000" rows="4" required></textarea></label><label>Icon<select v-model="compliancePolicyForm.icon"><option value="shield">Safeguarding / policy</option><option value="alert">Alert / integrity</option><option value="drop">Conduct / declaration</option></select></label><footer><button type="button" class="compliance-secondary" @click="compliancePolicyEditor = null">Cancel</button><button type="submit" class="compliance-primary" :disabled="compliancePolicySaving">{{ compliancePolicySaving ? 'Saving…' : 'Save policy' }}</button></footer></form></section></div>
      </section>
      <section v-else-if="section === 'contract-history'" class="contract-history-page">
        <p v-if="contractsError" class="contract-error" role="alert">{{ contractsError }}</p>
        <div class="contract-history-toolbar"><p>View and maintain your recorded contract history.</p><button type="button" class="contract-add-button" @click="openContractForm">+ Add contract</button></div>
        <div class="contract-history-card"><div class="contract-table-wrap"><table class="contract-table"><thead><tr><th>CONTRACT</th><th>POSITION</th><th>DEPARTMENT</th><th>START DATE</th><th>END DATE</th><th>SALARY</th><th>STATUS</th><th>UPDATE</th></tr></thead><tbody>
          <tr v-if="contractsLoading"><td colspan="8" class="contract-empty">Loading contract history…</td></tr>
          <tr v-else-if="contracts.length === 0"><td colspan="8" class="contract-empty">No contract history has been recorded yet.</td></tr>
          <tr v-for="contract in contracts" :key="contract.id"><td><strong>{{ contract.contract_type }}</strong></td><td>{{ contract.position }}</td><td>{{ contract.department || '—' }}</td><td>{{ contract.start_date?.slice(0, 10) }}</td><td>{{ contract.end_date?.slice(0, 10) || '—' }}</td><td>{{ contract.salary === null ? '—' : `$${contract.salary}` }}</td><td><span class="contract-status" :class="contract.status.toLowerCase()">{{ contract.status }}</span></td><td><button type="button" class="contract-delete-button" @click="deleteContract(contract)">Delete</button></td></tr>
        </tbody></table></div></div>
        <div v-if="contractDialogOpen" class="contract-modal-backdrop" @click.self="contractDialogOpen = false">
          <section class="contract-modal" role="dialog" aria-modal="true" aria-labelledby="contract-modal-title">
            <header><div><small>CONTRACT HISTORY</small><h2 id="contract-modal-title">Add contract</h2></div><button type="button" aria-label="Close" @click="contractDialogOpen = false">×</button></header>
            <form @submit.prevent="saveContract">
              <label>Contract type<select v-model="contractForm.contract_type" required><option value="" disabled>Select contract type</option><option v-for="type in contractTypes" :key="type">{{ type }}</option></select></label>
              <label>Position<input v-model.trim="contractForm.position" required maxlength="150" /></label>
              <label>Department<input v-model.trim="contractForm.department" maxlength="150" /></label>
              <label>Start date<input v-model="contractForm.start_date" type="date" required /></label>
              <label>End date<input v-model="contractForm.end_date" type="date" /></label>
              <label>Salary<input v-model="contractForm.salary" type="number" min="0" step="0.01" /></label>
              <label>Status<select v-model="contractForm.status" required><option>Active</option><option>Completed</option><option>Terminated</option></select></label>
              <p v-if="contractsError" class="contract-error" role="alert">{{ contractsError }}</p>
              <footer><button type="button" class="contract-cancel-button" @click="contractDialogOpen = false">Cancel</button><button type="submit" class="contract-add-button" :disabled="contractsSaving">{{ contractsSaving ? 'Saving…' : 'Save contract' }}</button></footer>
            </form>
          </section>
        </div>
      </section>
      <section v-else-if="section === 'training'" class="training-page">
        <header class="training-page-header">
          <div class="training-heading-copy">
            <span class="training-modal-kicker">LEARNING &amp; DEVELOPMENT</span>
            <h1>Staff Online Training</h1>
            <p>Assigned courses, practical learning, and certificates — all in one place.</p>
          </div>
          <button v-if="canManageTraining" type="button" class="training-create-button" @click="openTrainingEditor()">
            <span aria-hidden="true">+</span> Create course
          </button>
        </header>
        <p v-if="trainingError" class="training-error" role="alert">{{ trainingError }}</p>
        <section v-if="trainingCourses.length" class="training-summary" aria-label="Training progress summary">
          <article class="training-summary-card"><span class="training-summary-icon total" aria-hidden="true">◎</span><div><small>ASSIGNED COURSES</small><strong>{{ trainingCourses.length }}</strong></div><span class="training-summary-note">Ready to learn</span></article>
          <article class="training-summary-card"><span class="training-summary-icon done" aria-hidden="true">✓</span><div><small>COMPLETED</small><strong>{{ completedTrainingCount }}</strong></div><span class="training-summary-note">{{ trainingCompletionRate }}% completion</span></article>
          <article class="training-summary-card"><span class="training-summary-icon active" aria-hidden="true">↗</span><div><small>IN PROGRESS</small><strong>{{ inProgressTrainingCount }}</strong></div><span class="training-summary-note">Keep it going</span></article>
          <article class="training-summary-card"><span class="training-summary-icon pending" aria-hidden="true">◷</span><div><small>NOT STARTED</small><strong>{{ pendingTrainingCount }}</strong></div><span class="training-summary-note">Start when ready</span></article>
        </section>
        <section v-if="canManageTraining" class="training-card training-management">
          <header class="training-card-header">
            <div class="training-card-heading"><span class="training-section-icon" aria-hidden="true">▤</span><div><h2>Manage courses</h2><p>Create, assign, and track staff learning.</p></div></div>
            <span class="training-course-count">{{ adminTrainingCourses.length }} {{ adminTrainingCourses.length === 1 ? 'course' : 'courses' }}</span>
          </header>
          <div v-if="trainingLoading" class="training-loading"><span class="training-spinner"></span>Loading courses…</div>
          <div v-else-if="adminTrainingCourses.length === 0" class="training-manager-empty">
            <div class="training-empty-message"><span class="training-empty-icon" aria-hidden="true">▤</span><div><strong>Start your staff learning library</strong><p>Create a course, add reading material and a quiz, then assign it to selected staff.</p></div></div>
            <div class="training-topic-suggestions"><span>START WITH A TOPIC</span><div><button v-for="topic in suggestedTrainingTopics" :key="topic" type="button" @click="createSuggestedTraining(topic)">{{ topic }}<span aria-hidden="true">＋</span></button></div></div>
          </div>
          <div v-else class="training-table-wrap"><table class="training-table training-management-table"><thead><tr><th>COURSE</th><th>TOPIC</th><th>ASSIGNED</th><th>DUE DATE</th><th>PASS SCORE</th><th>ACTIONS</th></tr></thead><tbody><tr v-for="course in adminTrainingCourses" :key="course.id"><td><strong>{{ course.title }}</strong><small class="training-topic">{{ course.description }}</small></td><td><span class="training-category-pill">{{ course.category }}</span></td><td><span class="training-staff-count">{{ course.assignments.length }}</span> staff</td><td>{{ course.due_date || 'No deadline' }}</td><td><span class="training-pass-score">{{ course.pass_percent }}%</span></td><td class="training-actions"><div class="training-action-buttons"><button type="button" class="training-assign-button" @click="openTrainingAssignment(course)">Assign staff</button><button type="button" class="training-secondary" @click="openTrainingEditor(course)">Edit</button><button type="button" class="training-danger" @click="deleteTrainingCourse(course)">Delete</button></div></td></tr></tbody></table></div>
        </section>
        <aside v-if="canManageTraining && !trainingLoading && operationalStaffCount === 0" class="training-staff-setup" role="status">
          <span class="training-setup-icon" aria-hidden="true">i</span>
          <div><strong>No staff accounts are available to assign yet</strong><p>Create an account with a staff role (Manager or Officer) first. Then return here and use <b>Assign staff</b> on the course.</p></div>
          <a :href="staffAccountManagementPath">Open Admin Accounts <span aria-hidden="true">→</span></a>
        </aside>
        <section class="training-card">
          <header class="training-card-header"><div class="training-card-heading"><span class="training-section-icon learner" aria-hidden="true">▣</span><div><h2>My learning</h2><p>Courses assigned to you and your results.</p></div></div><span v-if="trainingCourses.length" class="training-course-count">{{ trainingCourses.length }} assigned</span></header>
          <div v-if="trainingLoading" class="training-loading"><span class="training-spinner"></span>Loading your courses…</div>
          <div v-else-if="trainingCourses.length === 0" class="training-learner-empty"><div class="training-empty-symbol" aria-hidden="true">✓</div><div><strong>You’re all caught up</strong><p>No courses have been assigned to you yet. Check back here when a new course is available.</p></div><span class="training-learner-empty-note">Your assigned courses will appear here</span></div>
          <div v-else class="training-table-wrap"><table class="training-table"><thead><tr><th>COURSE</th><th>YOUR PROGRESS</th><th>SCORE</th><th>DUE DATE</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="assignment in trainingCourses" :key="assignment.id"><td><strong>{{ assignment.course.title }}</strong><small class="training-topic">{{ assignment.course.category }}</small></td><td><div class="training-progress-cell"><div class="training-progress-track"><i :style="{ width: `${assignment.progress}%` }"></i></div><small>{{ assignment.progress }}%</small></div></td><td>{{ assignment.score === null ? '—' : `${assignment.score}%` }}</td><td>{{ assignment.course.due_date || 'No deadline' }}</td><td><span class="training-status" :class="assignment.status.toLowerCase().replace(' ', '-')">{{ assignment.status }}</span></td><td><button :class="['training-start', { 'is-completed': assignment.status === 'Completed' }]" type="button" @click="openAssignedCourse(assignment)">{{ assignment.status === 'Pending' ? 'Start course' : assignment.status === 'Completed' ? 'View certificate' : 'Continue' }}</button></td></tr></tbody></table></div>
        </section>
        <div v-if="trainingAssignmentCourse" class="training-modal-backdrop" @click.self="trainingAssignmentCourse = null">
          <section class="training-modal training-assignment-modal" role="dialog" aria-modal="true" aria-labelledby="training-assignment-title">
            <button class="training-modal-close" type="button" aria-label="Close" @click="trainingAssignmentCourse = null">×</button>
            <span class="training-modal-kicker">STAFF ASSIGNMENT</span>
            <h2 id="training-assignment-title">Assign course to staff</h2>
            <p class="training-assignment-course">{{ trainingAssignmentCourse.title }}</p>
            <label class="training-staff-search">Search staff accounts
              <input v-model.trim="trainingStaffSearch" type="search" placeholder="Search by name, email, or role" />
            </label>
            <div v-if="trainingStaff.length" class="training-assignment-list">
              <label v-for="staff in filteredTrainingStaff" :key="staff.id" class="training-assignment-person" :class="{ 'is-selected': trainingAssignmentIds.includes(staff.id) }">
                <input v-model="trainingAssignmentIds" type="checkbox" :value="staff.id" />
                <span class="training-person-avatar">{{ staff.name?.trim()?.charAt(0)?.toUpperCase() || '?' }}</span>
                <span class="training-person-details"><strong>{{ staff.name }}</strong><small>{{ staff.email || 'No email address' }}</small></span>
                <span class="training-role-pill">{{ staff.role.replaceAll('_', ' ') }}</span>
              </label>
              <p v-if="filteredTrainingStaff.length === 0" class="training-assignment-no-results">No staff accounts match that search.</p>
            </div>
            <div v-else class="training-no-staff">
              <span class="training-empty-icon" aria-hidden="true">♙</span>
              <strong>No staff accounts available</strong>
              <p>Add an account with a Manager or Officer role before assigning this course.</p>
              <a :href="staffAccountManagementPath">Open Admin Accounts <span aria-hidden="true">→</span></a>
            </div>
            <p class="training-assignment-selected">{{ trainingAssignmentIds.length }} staff selected</p>
            <div v-if="trainingError" class="training-error" role="alert">{{ trainingError }}</div>
            <footer><button type="button" class="training-secondary" @click="trainingAssignmentCourse = null">Cancel</button><button type="button" class="training-start" :disabled="trainingAssignmentSaving || !trainingStaff.length" @click="saveTrainingAssignments">{{ trainingAssignmentSaving ? 'Saving assignments…' : 'Save assignments' }}</button></footer>
          </section>
        </div>
        <div v-if="trainingEditor" class="training-modal-backdrop" @click.self="trainingEditor = false">
          <section class="training-modal training-editor-modal" role="dialog" aria-modal="true" aria-labelledby="training-editor-title">
            <button class="training-modal-close" type="button" aria-label="Close" @click="trainingEditor = false">×</button>
            <span class="training-modal-kicker">COURSE DESIGN</span>
            <h2 id="training-editor-title">{{ trainingEditorId ? 'Edit training course' : 'Create training course' }}</h2>
            <form class="training-form" @submit.prevent="saveTrainingCourse">
              <div class="training-form-grid">
                <label>Course title
                  <input v-model.trim="trainingForm.title" list="training-topic-options" maxlength="180" required placeholder="e.g. Child Safeguarding" />
                  <datalist id="training-topic-options"><option v-for="topic in trainingTopics" :key="topic" :value="topic" /></datalist>
                </label>
                <label>Category
                  <select v-model="trainingCategorySelection" required @change="changeTrainingCategory">
                    <option v-for="topic in trainingTopics" :key="topic" :value="topic">{{ topic }}</option>
                    <option value="Other">Other</option>
                  </select>
                </label>
                <label v-if="trainingCategorySelection === 'Other'">Custom category
                  <input v-model.trim="trainingForm.category" maxlength="120" required placeholder="Enter a category" />
                </label>
                <label class="training-form-wide">Short description
                  <textarea v-model.trim="trainingForm.description" maxlength="1000" rows="2" required></textarea>
                </label>
                <label class="training-form-wide">Learning material
                  <textarea v-model.trim="trainingForm.content" maxlength="30000" rows="7" required placeholder="Write the course content staff should read…"></textarea>
                </label>
                <label>Due date<input v-model="trainingForm.due_date" type="date" /></label>
                <label>Passing score (%)<input v-model.number="trainingForm.pass_percent" type="number" min="1" max="100" required /></label>
              </div>
              <fieldset class="training-staff-picker">
                <legend>Assign to staff accounts <span>(optional — you can assign staff later)</span></legend>
                <div v-if="trainingStaff.length" class="training-staff-list">
                  <label v-for="staff in trainingStaff" :key="staff.id">
                    <input v-model="trainingForm.staff_ids" type="checkbox" :value="staff.id" />
                    <span><strong>{{ staff.name }}</strong><small>{{ staff.email }} · {{ staff.role }}</small></span>
                  </label>
                </div>
                <p v-else>No staff accounts available yet. You can save this course and assign staff later.</p>
              </fieldset>
              <div class="training-quiz-builder">
                <div class="training-quiz-heading"><h3>Quiz questions</h3><button type="button" class="training-secondary" @click="addTrainingQuestion">+ Add question</button></div>
                <fieldset v-for="(question, questionIndex) in trainingForm.quiz" :key="questionIndex" class="training-question">
                  <legend>Question {{ questionIndex + 1 }}</legend>
                  <button v-if="trainingForm.quiz.length > 1" type="button" class="training-remove-question" @click="removeTrainingQuestion(questionIndex)">Remove</button>
                  <label>Question text<input v-model.trim="question.question" maxlength="1000" required /></label>
                  <div v-for="(option, optionIndex) in question.options" :key="optionIndex" class="training-option-row">
                    <label>Option {{ optionIndex + 1 }}<input v-model.trim="question.options[optionIndex]" maxlength="300" required /></label>
                    <button v-if="question.options.length > 2" type="button" class="training-remove-question" @click="removeTrainingOption(question, optionIndex)">Remove</button>
                  </div>
                  <button v-if="question.options.length < 6" type="button" class="training-secondary" @click="addTrainingOption(question)">+ Add option</button>
                  <label>Correct answer<select v-model.number="question.answer" required><option v-for="(_, optionIndex) in question.options" :key="optionIndex" :value="optionIndex">Option {{ optionIndex + 1 }}</option></select></label>
                </fieldset>
              </div>
              <p v-if="trainingError" class="training-error" role="alert">{{ trainingError }}</p>
              <footer>
                <button type="button" class="training-secondary" @click="trainingEditor = false">Cancel</button>
                <button type="submit" class="training-start" :disabled="trainingSaving">{{ trainingSaving ? 'Saving…' : trainingEditorId ? 'Save changes' : trainingForm.staff_ids.length ? 'Create and assign' : 'Save course draft' }}</button>
              </footer>
            </form>
          </section>
        </div>
        <div v-if="selectedCourse" class="training-modal-backdrop" @click.self="selectedCourse = null"><section class="training-modal training-learner-modal" role="dialog" aria-modal="true" aria-labelledby="training-modal-title"><button class="training-modal-close" type="button" aria-label="Close" @click="selectedCourse = null">×</button><span class="training-modal-kicker">{{ selectedCourse.course.category }}</span><h2 id="training-modal-title">{{ selectedCourse.course.title }}</h2><template v-if="trainingStage === 'content'"><p>{{ selectedCourse.course.description }}</p><div class="training-learning-content">{{ selectedCourse.course.content }}</div><div class="training-modal-meta"><span>Due {{ selectedCourse.course.due_date || 'No due date' }}</span><span>Pass mark: {{ selectedCourse.course.pass_percent }}%</span></div><button class="training-start training-modal-action" type="button" @click="trainingStage = 'quiz'">I have read this — take quiz</button></template><form v-else-if="trainingStage === 'quiz'" class="training-live-quiz" @submit.prevent="submitTrainingQuiz"><p>Answer every question. You need {{ selectedCourse.course.pass_percent }}% or higher to earn your certificate.</p><fieldset v-for="(question, questionIndex) in selectedCourse.course.quiz" :key="questionIndex"><legend>{{ questionIndex + 1 }}. {{ question.question }}</legend><label v-for="(option, optionIndex) in question.options" :key="optionIndex"><input v-model="trainingAnswers[questionIndex]" type="radio" :name="`training-question-${questionIndex}`" :value="optionIndex" />{{ option }}</label></fieldset><div v-if="trainingError" class="training-error" role="alert">{{ trainingError }}</div><footer><button type="button" class="training-secondary" @click="trainingStage = 'content'">Back to material</button><button type="submit" class="training-start" :disabled="trainingSaving">{{ trainingSaving ? 'Submitting…' : 'Submit quiz' }}</button></footer></form><div v-else-if="trainingStage === 'result'" class="training-result"><strong>Score: {{ trainingResult?.score }}%</strong><p>You did not reach the passing score of {{ selectedCourse.course.pass_percent }}%. Review the material and try again.</p><button class="training-start" type="button" @click="retryTrainingQuiz">Try again</button></div><div v-else class="training-certificate"><span class="training-modal-kicker">CERTIFICATE OF COMPLETION</span><h2>Certificate</h2><p>This certifies that</p><strong class="training-certificate-name">{{ auth.user?.name }}</strong><p>has successfully completed</p><strong>{{ selectedCourse.course.title }}</strong><p>{{ selectedCourse.course.category }}</p><div class="training-certificate-score">Final score: {{ selectedCourse.score }}% · Completed {{ selectedCourse.completed_at ? new Date(selectedCourse.completed_at).toLocaleDateString() : 'today' }}</div><button class="training-start training-certificate-print" type="button" @click="printTrainingCertificate">Print / Save certificate</button></div></section></div>
      </section>
      <section v-else class="other-section"><h2>{{ heading }}</h2><p>{{ heading }} information will appear here.</p></section>
    </div>
  </AdminLayout>
</template>

<style scoped>
.compliance-save-error{margin:0 0 12px;padding:10px 12px;border:1px solid #f2caca;border-radius:6px;background:#fff4f4;color:#a93232}
.contract-error{margin:0 0 12px;padding:10px 12px;border:1px solid #f2caca;border-radius:6px;background:#fff4f4;color:#a93232}
.contract-history-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px}.contract-history-toolbar p{margin:0;color:#718198;font-size:11px}
.contract-add-button,.contract-cancel-button{min-height:34px;padding:0 12px;border:1px solid #2f49c4;border-radius:5px;background:#2f49c4;color:#fff;font:inherit;font-size:10px;font-weight:700;cursor:pointer}.contract-add-button:disabled{opacity:.6;cursor:wait}.contract-cancel-button{border-color:#dfe5ef;background:#fff;color:#455671}
.contract-delete-button{padding:0;border:0;background:none;color:#a93232;font:inherit;font-size:9px;font-weight:650;cursor:pointer}
.contract-empty{padding:24px!important;color:#8190a3!important;text-align:center}
.contract-modal-backdrop{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:16px;background:rgba(15,27,48,.48)}
.contract-modal{width:min(100%,560px);max-height:90vh;overflow:auto;border-radius:10px;background:#fff;box-shadow:0 20px 60px rgba(11,24,44,.25)}
.contract-modal>header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #edf1f6}.contract-modal>header small{color:#5269b9;font-size:9px;font-weight:800;letter-spacing:.08em}.contract-modal>header h2{margin:4px 0 0;color:#1b2f4b;font-size:16px}.contract-modal>header>button{width:30px;height:30px;border:0;border-radius:7px;background:#f2f4f8;color:#52627a;font-size:20px;cursor:pointer}
.contract-modal form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:16px 18px}.contract-modal label{display:grid;gap:5px;color:#53657d;font-size:10px;font-weight:650}.contract-modal input,.contract-modal select{box-sizing:border-box;width:100%;min-height:35px;padding:7px 9px;border:1px solid #dbe3ef;border-radius:6px;background:#fff;color:#263e59;font:inherit;font-weight:400}.contract-modal form>.contract-error,.contract-modal form>footer{grid-column:1/-1}.contract-modal form>footer{display:flex;justify-content:flex-end;gap:8px}
.profile-workspace{width:min(100%,1120px);margin:0 auto;padding:20px 0 42px;color:#172b4d;font-size:12px}.profile-page-top{margin-bottom:14px}.profile-page-top h1{margin:0 0 4px;font-size:21px}.profile-page-top p{margin:0;color:#8291a5;font-size:11px}.profile-stepper{display:flex;justify-content:space-between;gap:12px;margin:0 0 12px;padding:12px 18px;list-style:none;border:1px solid #e1e8ef;border-radius:10px;background:#fff}.profile-stepper li{display:flex;align-items:center;gap:8px;color:#7b8da1;font-size:10px}.step-dot{display:grid;place-items:center;width:20px;height:20px;flex:none;border-radius:50%;background:#f1f5f9;color:#8392a4;font-weight:700}.profile-stepper .done .step-dot{background:#dcf7e9;color:#178255}.profile-stepper .current{color:#0e766e}.profile-stepper .current .step-dot{background:#0e766e;color:white}.profile-form-card,.change-requests{padding:16px;border:1px solid #e1e8ef;border-radius:10px;background:#fff}.form-section-title{display:flex;align-items:center;gap:7px;margin-bottom:12px}.form-section-title>span:first-child{display:grid;place-items:center;width:18px;height:18px;border-radius:5px;background:#e8f4f1;color:#248b78}.form-section-title h2{margin:0;font-size:13px}.info-mark{display:grid;place-items:center;width:14px;height:14px;border:1px solid #8cbdb2;border-radius:50%;color:#168575;font-size:9px}.form-grid,.contact-grid,.beneficiary-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 12px}.family-grid{grid-template-columns:repeat(2,minmax(0,1fr));max-width:700px}.form-grid label,.child-row label,.contact-grid label,.beneficiary-row label{display:grid;gap:5px;font-size:10px;font-weight:650}.form-grid input,.form-grid select,.child-row input,.child-row select,.contact-grid input,.beneficiary-row input{width:100%;height:31px;padding:0 9px;border:1px solid #d9e2eb;border-radius:5px;background:#fff;color:#34475c;font:inherit;font-weight:400}.field-hint{display:block;margin-top:5px;color:#a0adbb;font-size:9px}.repeat-label{margin:16px 0 6px;color:#95a2b2;font-size:9px}.child-row{display:grid;grid-template-columns:1fr 1fr 1fr 28px 18px;align-items:end;gap:8px;margin-bottom:7px;padding:9px;border:1px solid #e5ebf0;border-radius:7px;background:#f9fbfd}.child-age{padding-bottom:8px;color:#91a0b2;font-size:9px}.remove-row{align-self:center;border:0;background:transparent;color:#9aa7b5;font-size:16px;cursor:pointer}.add-row{width:100%;min-height:29px;margin-top:4px;border:1px dashed #cbd6e1;border-radius:5px;background:#fff;color:#586e85;font:inherit;font-size:10px;cursor:pointer}.add-row:hover{border-color:#11907e;color:#087969}.form-divider{height:1px;margin:17px 0;border:0;background:#edf1f4}.contact-grid{grid-template-columns:repeat(6,minmax(0,1fr));padding:0 7px 10px;border:1px solid #e7edf2;border-radius:7px;background:#fafbfd}.contact-grid>label{grid-column:span 2;margin-top:8px}.contact-grid>label.wide-field{grid-column:span 3}.contact-grid b{color:#d14d4d}.repeat-section>.add-row{margin-top:6px}.beneficiaries-section .form-section-title small{margin-left:auto;color:#8a99aa;font-size:9px}.beneficiary-row{grid-template-columns:2fr 1fr 1fr;margin-bottom:7px}.form-actions{display:flex;align-items:center;gap:8px;margin-top:14px}.form-actions button{min-height:29px;padding:0 12px;border:1px solid #d9e2eb;border-radius:5px;background:white;color:#34475c;font:inherit;font-size:10px;cursor:pointer}.form-actions .next-button{border-color:#08796d;background:#08796d;color:white}.home-button{margin-right:2px}.step-count{color:#a0acb8;font-size:9px}.action-spacer{flex:1}.change-requests{margin-top:12px;padding:14px 16px}.change-requests .form-section-title{margin-bottom:8px}.request-table{overflow:hidden;border:1px solid #e0e7ed;border-radius:5px}.request-head,.request-row{display:grid;grid-template-columns:1.2fr 1fr 3fr .8fr 20px;align-items:center;gap:8px;padding:8px;font-size:9px}.request-head{background:#f8fafc;color:#8191a3;font-size:8px}.request-row{border-top:1px solid #e7edf2;color:#53677c}.request-row b{padding:3px 6px;border-radius:99px;background:#fff3cc;color:#ad7900;font-size:8px}.request-row button{border:0;background:none;color:#97a4b2;cursor:pointer}.other-section{padding:28px;border:1px solid #e2e8f0;border-radius:9px;background:#fff}.other-section h2{margin:0 0 8px}.other-section p{color:#64748b}@media(max-width:700px){.profile-workspace{padding:16px}.profile-stepper{overflow:auto;justify-content:flex-start}.profile-stepper li{min-width:115px}.family-grid{grid-template-columns:1fr}.child-row{grid-template-columns:1fr 1fr}.child-age{display:none}.contact-grid{grid-template-columns:1fr 1fr}.contact-grid>label,.contact-grid>label.wide-field{grid-column:span 1}.form-actions{flex-wrap:wrap}.action-spacer{flex-basis:100%}.request-table{overflow-x:auto}.request-head,.request-row{min-width:650px}}
:global(.admin-app-shell:has(.profile-workspace)){font-family:Roboto,'Noto Sans Khmer',Arial,sans-serif;-webkit-font-smoothing:antialiased}:global(.admin-app-shell:has(.profile-workspace) button),:global(.admin-app-shell:has(.profile-workspace) input),:global(.admin-app-shell:has(.profile-workspace) select){font-family:inherit}.profile-workspace{font-family:Roboto,'Noto Sans Khmer',Arial,sans-serif;-webkit-font-smoothing:antialiased}.profile-workspace input,.profile-workspace select,.profile-workspace button{font-family:inherit}
/* Profile layout tuned to the supplied Personnel Portal reference. */
.profile-workspace{width:min(100%,800px);margin:0;padding:8px 0 42px;color:#172b4d;font-size:12px}.profile-page-top{margin-bottom:14px}.profile-page-top h1{margin:0 0 4px;font-size:17px;font-weight:700;letter-spacing:-.2px}.profile-page-top p{margin:0;color:#8291a5;font-size:11px;font-weight:400}.profile-stepper{justify-content:flex-start;gap:10px;padding:9px 12px;margin-bottom:12px}.profile-stepper li{flex:1;min-height:32px;font-size:9px}.profile-stepper .current{padding:4px 8px;border-radius:6px;background:#e7f5f2;font-weight:700}.profile-form-card{padding:16px;border-radius:11px}.form-section-title{position:relative;min-height:26px;margin-bottom:12px}.form-section-title h2{font-size:12px}.profile-initials{display:grid;place-items:center;width:42px;height:42px;margin-left:auto;border-radius:50%;background:#e7f5f2;color:#08796d;font-size:14px;font-weight:700}.basic-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}.basic-grid label{position:relative;gap:5px;padding-bottom:9px;font-size:9px}.basic-grid label>b{color:#d14d4d}.basic-grid input,.basic-grid select{height:30px;border-radius:7px;background:#fff}.basic-grid input:disabled{background:#f8fafc}.basic-grid label>small{color:#99a7b6;font-size:8px;font-weight:400}.basic-grid .request-change{position:absolute;right:3px;bottom:0;padding:0;border:0;background:transparent;color:#148579;font-size:8px;font-weight:650;cursor:pointer}.basic-grid .full-field{grid-column:1/-1}.profile-step-placeholder{min-height:220px}.profile-step-placeholder p{color:#8291a5}.form-actions{gap:7px;padding-top:3px;border-top:1px solid #edf1f4}.form-actions button{font-size:9px}.form-actions button:disabled{opacity:.45;cursor:default}.form-actions .next-button{background:#0e766e;border-color:#0e766e}
/* Refined profile form spacing and field alignment. */
.profile-workspace{width:min(100%,1180px);margin:0 auto;padding-top:12px}.profile-form-card{padding:24px;border-color:#dfe7ef;border-radius:14px;box-shadow:0 8px 24px rgba(19,48,78,.045)}.form-section-title h2{font-size:16px;font-weight:700;letter-spacing:-.2px}.form-section-title>span:first-child{width:26px;height:26px;border-radius:8px}.basic-grid{gap:16px 18px}.basic-grid label{display:block;position:relative;padding-bottom:17px;font-size:11px;font-weight:600;line-height:1.45}.basic-grid label>b{display:inline;color:#d14d4d;font-size:11px}.basic-grid input,.basic-grid select{display:block;box-sizing:border-box;height:46px;margin-top:9px;padding:0 13px;border-color:#d5e0eb;border-radius:9px;background:#fff;color:#263e59;font-size:11px;transition:border-color .16s,box-shadow .16s}.basic-grid input:focus,.basic-grid select:focus{border-color:#53a89b;outline:0;box-shadow:0 0 0 3px rgba(8,121,109,.11)}.basic-grid input:disabled{background:#f7f9fb;color:#8291a5}.basic-grid label>small{display:block;margin-top:7px;color:#8b9bad;font-size:10px}.basic-grid .request-change{right:2px;bottom:0;font-size:10px;font-weight:700}.basic-grid .full-field{grid-column:1/-1}.profile-initials{width:54px;height:54px;font-size:17px}.profile-step-placeholder{min-height:220px}
@media(max-width:700px){.profile-workspace{width:100%;padding-top:14px}.profile-form-card{padding:16px}.form-section-title h2{font-size:14px}.basic-grid{grid-template-columns:1fr;gap:10px}.basic-grid .full-field{grid-column:auto}}@media(max-width:700px){.profile-workspace{width:100%;padding-top:14px}.profile-stepper{overflow:auto;justify-content:flex-start}.profile-stepper li{min-width:120px}.basic-grid{grid-template-columns:1fr}.basic-grid .full-field{grid-column:auto}}
.compliance-overview{padding:16px 18px;border:1px solid #e4eaf0;border-radius:12px;background:#fff;box-shadow:0 4px 16px rgba(22,48,78,.035)}.compliance-filters{display:grid;grid-template-columns:minmax(220px,1fr) 150px 170px;gap:9px}.compliance-filters select,.compliance-search{height:36px;border:1px solid #dfe6ee;border-radius:8px;background:#fff;color:#34475c;font:inherit;font-size:11px}.compliance-filters select{padding:0 10px}.compliance-search{display:flex;align-items:center;gap:8px;padding:0 10px}.compliance-search span{color:#91a0b2;font-size:17px}.compliance-search input{width:100%;border:0;outline:0;color:inherit;font:inherit}.compliance-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-top:12px}.compliance-stats article{display:flex;min-height:72px;flex-direction:column;justify-content:center;padding:9px 11px;border:1px solid #e6ebf0;border-left:3px solid #dce4ed;border-radius:9px}.compliance-stats article small{color:#8795a5;font-size:8px;font-weight:700;letter-spacing:.05em}.compliance-stats article strong{margin:4px 0 1px;color:#172b4d;font-size:17px}.compliance-stats article span{color:#8997a7;font-size:8px}.compliance-stats .completed{border-left-color:#159447}.compliance-stats .completed span{color:#16834a}.compliance-stats .pending{border-left-color:#d97706}.compliance-stats .pending span{color:#a65c0b}.compliance-stats .expired{border-left-color:#dc3c3c}.compliance-progress{margin-top:14px}.compliance-progress>div:first-child{display:flex;justify-content:space-between;color:#34475c;font-size:10px}.compliance-progress>div:first-child b{color:#08796d}.compliance-progress-track{height:7px;margin:7px 0 5px;overflow:hidden;border-radius:99px;background:#edf1f5}.compliance-progress-track i{display:block;height:100%;border-radius:inherit;background:#0b806f;transition:width .2s}.compliance-progress>small{color:#8c99a8;font-size:9px}.compliance-list-title{margin:16px 0 9px;color:#172b4d;font-size:13px}.compliance-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.compliance-card{display:flex;min-height:180px;flex-direction:column;padding:13px;border:1px solid #e5eaf0;border-radius:11px;background:#fff;box-shadow:0 3px 10px rgba(24,43,68,.035)}.compliance-card>header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}.compliance-icon{display:grid;width:30px;height:30px;place-items:center;border-radius:8px;background:#e9f6f3;color:#168578;font-size:15px;font-weight:700}.compliance-icon.icon-alert{background:#fff3e2;color:#d27619}.compliance-icon.icon-drop{background:#f2eaff;color:#9361e8}.compliance-status{padding:4px 9px;border-radius:99px;background:#fff2c2;color:#ad7400;font-size:8px;font-weight:700}.compliance-status.completed{background:#e3f6e9;color:#197640}.compliance-card-title{color:#18314d;font-size:11px;font-weight:700}.compliance-category{display:block;margin-top:5px;color:#168578;font-size:8px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.compliance-category.category-alert{color:#c36c17}.compliance-category.category-drop{color:#8551cf}.compliance-card p{margin:8px 0;color:#596b7e;font-size:9px;line-height:1.5}.compliance-assignee{margin-top:auto;color:#98a4b2;font-size:8px}.compliance-card footer,.compliance-modal footer{display:flex;justify-content:space-between;gap:8px;margin-top:10px}.compliance-card footer button,.compliance-modal footer button{min-height:29px;padding:0 10px;border:1px solid #e0e7ee;border-radius:6px;background:#fff;color:#31485f;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.compliance-card footer .compliance-primary,.compliance-modal footer .compliance-primary{border-color:#08796d;background:#08796d;color:#fff}.compliance-primary:disabled{border-color:#c9dfd8!important;background:#e4f2ee!important;color:#58897e!important;cursor:default}.compliance-empty{padding:34px;border:1px dashed #dce4ec;border-radius:10px;background:#fff;color:#8291a5;text-align:center}.compliance-modal-backdrop{position:fixed;z-index:50;inset:0;display:grid;place-items:center;padding:18px;background:#11243b77}.compliance-modal{position:relative;width:min(100%,460px);padding:24px;border:1px solid #e2e8ef;border-radius:14px;background:#fff;box-shadow:0 24px 80px #10243d40}.compliance-modal h2{margin:8px 0;color:#172b4d;font-size:18px}.compliance-modal p{color:#5d6e80;font-size:12px;line-height:1.6}.compliance-modal .compliance-modal-note{padding:10px;border-radius:7px;background:#f5f8fb;color:#8291a5;font-size:10px}.compliance-modal-close{position:absolute;top:10px;right:12px;border:0;background:none;color:#77889a;font-size:22px;cursor:pointer}
@media(max-width:850px){.compliance-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.compliance-filters{grid-template-columns:minmax(160px,1fr) 130px 150px}}
@media(max-width:600px){.compliance-grid{grid-template-columns:1fr}.compliance-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.compliance-filters{grid-template-columns:1fr 1fr}.compliance-search{grid-column:1/-1}}
.contract-history-card{padding:16px 18px;border:1px solid #e4eaf0;border-radius:13px;background:#fff;box-shadow:0 4px 16px rgba(22,48,78,.04)}.contract-table-wrap{overflow-x:auto;border:1px solid #e7ecf1;border-radius:10px}.contract-table{width:100%;border-collapse:collapse;text-align:left;font-size:10px}.contract-table th{padding:11px 12px;background:#f8fafc;color:#8391a1;font-size:8px;font-weight:700;letter-spacing:.045em;white-space:nowrap}.contract-table td{padding:12px;border-top:1px solid #edf0f4;color:#34475c;white-space:nowrap}.contract-table td:first-child{color:#172b4d}.contract-table td:first-child strong{font-size:10px}.contract-table td small{display:block;margin-top:4px;color:#95a1af;font-size:8px}.contract-status{display:inline-flex;padding:4px 9px;border-radius:99px;background:#e3f8e9;color:#178146;font-size:8px;font-weight:700}.contract-status.completed{background:#e8f7ed;color:#237946}.contract-no-file{color:#99a5b3}.contract-table a{color:#08796d;text-decoration:none;font-weight:600}
@media(max-width:700px){.contract-history-card{padding:10px}.contract-table{min-width:760px}}
.training-card{padding:16px 18px;border:1px solid #e4eaf0;border-radius:13px;background:#fff;box-shadow:0 4px 16px rgba(22,48,78,.04)}.training-table-wrap{overflow-x:auto;border:1px solid #e7ecf1;border-radius:10px}.training-table{width:100%;border-collapse:collapse;text-align:left;font-size:10px}.training-table th{padding:11px 12px;background:#f8fafc;color:#8391a1;font-size:8px;font-weight:700;letter-spacing:.05em;white-space:nowrap}.training-table td{padding:10px 12px;border-top:1px solid #edf0f4;color:#34475c;white-space:nowrap}.training-table td:first-child{color:#172b4d}.training-table td:first-child strong{font-size:10px}.training-table td:nth-child(2){min-width:170px}.training-progress-track{height:6px;margin-bottom:5px;overflow:hidden;border-radius:99px;background:#edf1f5}.training-progress-track i{display:block;height:100%;border-radius:inherit;background:#0b806f;transition:width .2s}.training-table td:nth-child(2) small{color:#8997a7;font-size:8px}.training-status{display:inline-flex;padding:4px 9px;border-radius:99px;background:#fff2c2;color:#ad7400;font-size:8px;font-weight:700}.training-status.in-progress{background:#e8f1ff;color:#3269b0}.training-status.completed{background:#e3f8e9;color:#178146}.training-start{min-height:28px;padding:0 12px;border:1px solid #08796d;border-radius:6px;background:#08796d;color:#fff;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.training-modal-backdrop{position:fixed;z-index:50;inset:0;display:grid;place-items:center;padding:18px;background:#11243b77}.training-modal{position:relative;width:min(100%,440px);padding:24px;border:1px solid #e2e8ef;border-radius:14px;background:#fff;box-shadow:0 24px 80px #10243d40}.training-modal-kicker{color:#168578;font-size:9px;font-weight:800;letter-spacing:.08em}.training-modal h2{margin:8px 0;color:#172b4d;font-size:19px}.training-modal p{color:#5d6e80;font-size:12px;line-height:1.6}.training-modal-meta{display:flex;justify-content:space-between;gap:8px;margin:16px 0;padding:10px;border-radius:8px;background:#f5f8fb;color:#728296;font-size:10px}.training-modal-action{min-height:36px;padding:0 15px}.training-modal-close{position:absolute;top:10px;right:12px;border:0;background:none;color:#77889a;font-size:22px;cursor:pointer}
@media(max-width:700px){.training-card{padding:10px}.training-table{min-width:720px}}
.training-page{display:grid;gap:16px}.training-page-header,.training-card-header,.training-management>header{display:flex;align-items:center;justify-content:space-between;gap:14px}.training-page-header h2{margin:5px 0;color:#172b4d;font-size:22px}.training-page-header p,.training-card-header p,.training-management>header p{margin:5px 0 0;color:#718198;font-size:11px}.training-card-header,.training-management>header{margin-bottom:12px}.training-card-header h3,.training-management>header h3{margin:0;color:#172b4d;font-size:14px}.training-error{margin:0;padding:10px 12px;border:1px solid #f2caca;border-radius:7px;background:#fff4f4;color:#a93232;font-size:11px}.training-empty{padding:20px!important;color:#718198!important;text-align:center}.training-actions{display:flex;gap:6px}.training-secondary,.training-danger{min-height:28px;padding:0 10px;border:1px solid #d7e0eb;border-radius:6px;background:#fff;color:#3155bc;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.training-danger{border-color:#f0cccc;color:#a93232}.training-start:disabled{opacity:.55;cursor:not-allowed}.training-topic{display:block;margin-top:4px;color:#718198;font-size:9px}.training-editor-modal,.training-learner-modal{width:min(100%,760px);max-height:calc(100vh - 36px);overflow:auto}.training-form{display:grid;gap:16px;margin-top:14px}.training-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.training-form label,.training-question>label,.training-option-row>label{display:grid;gap:5px;color:#53657d;font-size:10px;font-weight:650}.training-form input,.training-form textarea,.training-form select,.training-question input,.training-question select{box-sizing:border-box;width:100%;min-height:36px;padding:7px 9px;border:1px solid #dbe3ef;border-radius:6px;background:#fff;color:#263e59;font:inherit;font-weight:400}.training-form textarea{resize:vertical}.training-form-wide{grid-column:1/-1}.training-staff-picker,.training-question,.training-live-quiz fieldset{min-width:0;padding:12px;border:1px solid #e2e8f0;border-radius:9px}.training-staff-picker legend,.training-question legend,.training-live-quiz legend{padding:0 5px;color:#263e59;font-size:10px;font-weight:700}.training-staff-picker legend span{color:#8291a5;font-weight:400}.training-staff-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px;max-height:180px;overflow:auto}.training-staff-list label{display:flex;align-items:flex-start;gap:8px;padding:8px;border-radius:6px;background:#f8fafc}.training-staff-list input{margin:2px 0 0;accent-color:#08796d}.training-staff-list span{display:grid;gap:3px}.training-staff-list strong{color:#263e59;font-size:10px}.training-staff-list small{color:#8291a5;font-size:9px}.training-quiz-builder{display:grid;gap:10px}.training-quiz-heading{display:flex;align-items:center;justify-content:space-between}.training-quiz-heading h3{margin:0;color:#172b4d;font-size:13px}.training-question{position:relative;display:grid;gap:10px;padding-top:16px}.training-remove-question{justify-self:start;padding:3px 6px;border:0;background:none;color:#a93232;font:inherit;font-size:9px;cursor:pointer}.training-option-row{display:flex;align-items:flex-end;gap:8px}.training-option-row>label{flex:1}.training-question select{max-width:240px}.training-form footer,.training-live-quiz footer{display:flex;justify-content:flex-end;gap:8px}.training-learning-content{max-height:38vh;overflow:auto;padding:14px;border:1px solid #e3eaf1;border-radius:9px;background:#f8fafc;color:#34475c;font-size:12px;line-height:1.7;white-space:pre-wrap}.training-live-quiz{display:grid;gap:12px;margin-top:12px}.training-live-quiz fieldset{display:grid;gap:8px}.training-live-quiz fieldset label{display:flex;align-items:flex-start;gap:8px;color:#34475c;font-size:11px}.training-live-quiz input{margin-top:2px;accent-color:#08796d}.training-result{padding:16px;border-radius:9px;background:#fff8e7;color:#765510}.training-result>strong{font-size:18px}.training-certificate{margin-top:12px;padding:28px;border:6px double #c8a957;border-radius:10px;background:linear-gradient(135deg,#fffdf6,#fff);text-align:center}.training-certificate h2{margin:16px 0;color:#193b55;font-size:28px}.training-certificate-name{display:block;margin:12px 0;color:#193b55;font-family:Georgia,serif;font-size:22px}.training-certificate-score{margin:20px 0;color:#627489;font-size:10px}.training-certificate-print{margin-top:8px}@media(max-width:700px){.training-page-header{align-items:flex-start;flex-direction:column}.training-form-grid,.training-staff-list{grid-template-columns:1fr}.training-editor-modal,.training-learner-modal{padding:20px}.training-form-wide{grid-column:auto}.training-option-row{align-items:stretch}.training-certificate{padding:20px 12px}}
@media print{ :global(body *){visibility:hidden!important}.training-certificate,.training-certificate *{visibility:visible!important}.training-certificate{position:fixed;inset:8% 5%;display:grid;place-content:center;box-sizing:border-box;border:8px double #c8a957;background:#fff;box-shadow:none}.training-certificate-print{display:none!important}}
.training-page{gap:18px;padding-bottom:20px}
.training-page-header{position:relative;overflow:hidden;min-height:118px;box-sizing:border-box;padding:23px 26px;border:1px solid #dce8e8;border-radius:16px;background:radial-gradient(ellipse at 88% 0%,rgba(40,163,144,.15),transparent 34%),linear-gradient(115deg,#f5fbfa 0%,#fff 68%);box-shadow:0 8px 24px rgba(22,48,78,.045)}
.training-page-header:after{position:absolute;right:42px;bottom:-95px;width:190px;height:190px;border:1px solid rgba(8,121,109,.10);border-radius:50%;content:""}
.training-heading-copy{position:relative;z-index:1;max-width:620px}
.training-page-header h2{margin:7px 0;color:#162d49;font-size:25px;font-weight:750;letter-spacing:-.035em}
.training-page-header p{max-width:520px;margin:6px 0 0;color:#65788d;font-size:12px;line-height:1.6}
.training-create-button{position:relative;z-index:1;display:inline-flex;min-height:40px;align-items:center;gap:8px;padding:0 16px;border:1px solid #08796d;border-radius:8px;background:#08796d;box-shadow:0 4px 10px rgba(8,121,109,.16);color:#fff;font:inherit;font-size:11px;font-weight:700;cursor:pointer;transition:background .15s,transform .15s,box-shadow .15s}
.training-create-button:hover{transform:translateY(-1px);background:#06685e;box-shadow:0 7px 15px rgba(8,121,109,.2)}
.training-create-button span{font-size:19px;font-weight:400;line-height:1}
.training-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.training-summary-card{display:flex;min-height:80px;align-items:center;gap:11px;padding:13px 15px;border:1px solid #e6ebf1;border-radius:12px;background:#fff;box-shadow:0 4px 14px rgba(22,48,78,.035)}
.training-summary-icon{display:grid;width:38px;height:38px;flex:none;place-items:center;border-radius:11px;background:#eef3ff;color:#536fc7;font-size:20px;font-weight:700}
.training-summary-icon.done{background:#eaf7ef;color:#20804a}.training-summary-icon.active{background:#e8f5f4;color:#08796d}.training-summary-icon.pending{background:#fff5e4;color:#b17916}
.training-summary-card>div{display:grid;gap:3px;min-width:0}
.training-summary-card small{color:#8090a2;font-size:8px;font-weight:750;letter-spacing:.075em;white-space:nowrap}
.training-summary-card strong{color:#1a304b;font-size:21px;line-height:1.05}
.training-summary-note{margin-left:auto;color:#8b99a9;font-size:9px;text-align:right}
.training-card{padding:18px 20px;border-color:#e3e9f0;border-radius:14px;box-shadow:0 6px 20px rgba(22,48,78,.045)}
.training-card-header,.training-management>header{min-height:42px}
.training-card-heading{display:flex;align-items:center;gap:11px}
.training-section-icon{display:grid;width:36px;height:36px;flex:none;place-items:center;border:1px solid #dcebe8;border-radius:10px;background:#f0f8f6;color:#08796d;font-size:17px}
.training-section-icon.learner{border-color:#e2e8fa;background:#f2f4fd;color:#5a67b2}
.training-card-header h3,.training-management>header h3{font-size:14px;font-weight:750;letter-spacing:-.01em}
.training-card-header p,.training-management>header p{font-size:10px}
.training-course-count{padding:6px 10px;border:1px solid #e5eaf0;border-radius:99px;background:#f8fafc;color:#617389;font-size:9px;font-weight:700;white-space:nowrap}
.training-table-wrap{border-color:#e8edf2;border-radius:10px}
.training-table{font-size:10px}
.training-table th{padding:12px 14px;background:#f7f9fb;color:#73849a;font-size:8px;letter-spacing:.075em}
.training-table td{padding:13px 14px;border-color:#edf1f5;color:#53667b;font-size:10px;vertical-align:middle}
.training-table tbody tr{transition:background .12s}
.training-table tbody tr:hover{background:#fbfdfd}
.training-table td:first-child{min-width:180px;color:#203550}
.training-table td:first-child strong{display:block;font-size:10px;font-weight:700}
.training-topic{max-width:285px;overflow:hidden;color:#8694a5;font-size:9px;text-overflow:ellipsis}
.training-category-pill{display:inline-block;max-width:190px;padding:5px 8px;overflow:hidden;border:1px solid #e5ebe9;border-radius:99px;background:#f4f8f7;color:#4f6d68;font-size:9px;text-overflow:ellipsis;vertical-align:middle;white-space:nowrap}
.training-staff-count{display:inline-grid;min-width:23px;height:23px;place-items:center;margin-right:3px;border-radius:7px;background:#eff4fb;color:#3f5d81;font-size:9px;font-weight:750}
.training-pass-score{color:#426b5e;font-weight:750}
.training-progress-cell{display:flex;min-width:125px;align-items:center;gap:8px}
.training-progress-cell .training-progress-track{flex:1;margin:0}
.training-progress-cell small{width:29px;color:#788a9c;font-size:9px;text-align:right}
.training-progress-track{height:7px;background:#e9eef2}
.training-progress-track i{background:linear-gradient(90deg,#078475,#31a58d)}
.training-status{padding:5px 9px;font-size:8px;letter-spacing:.01em}
.training-status.pending{background:#fff4df;color:#a66b08}
.training-status.in-progress{background:#eaf1ff;color:#4169ad}
.training-status.completed{background:#e8f6ec;color:#227b48}
.training-start{min-height:31px;padding:0 11px;border-color:#08796d;border-radius:7px;font-size:9px;transition:background .14s,border-color .14s,transform .14s}
.training-start:hover:not(:disabled){transform:translateY(-1px);border-color:#06685e;background:#06685e}
.training-start.is-completed{border-color:#d5e9dc;background:#f0f8f2;color:#28784a}
.training-start.is-completed:hover:not(:disabled){border-color:#b8dcc4;background:#e7f4eb}
.training-secondary,.training-danger{min-height:30px;padding:0 10px;border-radius:6px;font-size:9px;transition:background .14s,border-color .14s}
.training-secondary:hover{border-color:#c2d2e2;background:#f6f9fc}
.training-danger:hover{border-color:#e3aaaa;background:#fff5f5}
.training-management-table .training-actions{display:table-cell;white-space:nowrap}
.training-empty{height:132px;padding:20px!important}
.training-empty strong,.training-empty small{display:block}
.training-empty strong{margin:7px 0 4px;color:#344961;font-size:11px}
.training-empty small{margin-bottom:11px;color:#8291a3;font-size:9px}
.training-empty-icon{display:block;color:#9aacc0;font-size:22px}
.training-empty-icon.learner{color:#8c9ac8}
.training-management .training-empty .training-secondary{margin:0 auto}
.training-error{padding:11px 13px;border-radius:8px;font-size:10px}
.training-modal-backdrop{backdrop-filter:blur(3px)}
.training-modal{padding:26px;border-color:#e4eaf0;border-radius:16px;box-shadow:0 26px 80px rgba(16,36,61,.24)}
.training-modal h2{font-size:21px;letter-spacing:-.02em}
.training-form{gap:18px}
.training-form label,.training-question>label,.training-option-row>label{color:#42566e;font-size:10px}
.training-form input,.training-form textarea,.training-form select,.training-question input,.training-question select{min-height:39px;border-color:#d6e0eb;border-radius:7px;font-size:11px;transition:border-color .15s,box-shadow .15s}
.training-form input:focus,.training-form textarea:focus,.training-form select:focus,.training-question input:focus,.training-question select:focus{border-color:#1b9384;outline:0;box-shadow:0 0 0 3px rgba(27,147,132,.12)}
.training-staff-picker,.training-question,.training-live-quiz fieldset{border-color:#e4eaf0;border-radius:10px}
.training-staff-list label{border:1px solid transparent;transition:border-color .12s,background .12s}
.training-staff-list label:hover{border-color:#dce8e5;background:#f4faf8}
.training-learning-content{max-height:42vh;padding:18px;border-color:#e4eaf0;border-radius:10px;background:#f8fafc;font-size:12px;line-height:1.8}
.training-live-quiz fieldset{padding:15px}
.training-live-quiz fieldset label{padding:7px 8px;border-radius:6px}
.training-live-quiz fieldset label:hover{background:#f7fafb}
.training-certificate{padding:34px;border-radius:12px;background:radial-gradient(ellipse at top,#fff9e9,#fff 62%)}
@media(max-width:850px){.training-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.training-summary-note{display:none}.training-table td:first-child{min-width:150px}}
@media(max-width:700px){.training-page{gap:12px}.training-page-header{min-height:0;align-items:flex-start;gap:14px;padding:18px}.training-page-header h2{font-size:21px}.training-page-header p{font-size:11px}.training-create-button{flex:none;min-height:36px;padding:0 11px;font-size:10px}.training-summary{gap:8px}.training-summary-card{min-height:68px;padding:10px;gap:8px}.training-summary-icon{width:32px;height:32px;border-radius:9px;font-size:17px}.training-summary-card small{font-size:7px}.training-summary-card strong{font-size:18px}.training-card{padding:13px}.training-card-header,.training-management>header{align-items:flex-start}.training-section-icon{width:32px;height:32px}.training-course-count{font-size:8px}.training-table{min-width:760px}.training-management .training-table{min-width:900px}.training-editor-modal,.training-learner-modal{padding:20px}.training-empty{white-space:normal!important}.training-modal-meta{flex-direction:column}.training-certificate{padding:22px 14px}}
@media(max-width:380px){.training-page-header{flex-direction:column}.training-summary-card{gap:6px;padding:8px}.training-summary-icon{width:28px;height:28px}.training-summary-card small{font-size:6px}.training-card-heading{gap:7px}}
.qualification-intro{margin:-4px 0 14px;color:#8291a5;font-size:11px}.qualification-notice{padding:9px 11px;border-radius:7px;font-size:11px}.qualification-notice.is-error{background:#fff1f0;color:#a3312b}.qualification-notice.is-success{background:#eaf7ee;color:#247444}.profile-qualification-section{margin-top:16px;padding-top:12px;border-top:1px solid #edf1f4}.profile-qualification-section .form-section-title{margin-bottom:8px}.profile-qualification-section .form-section-title h3{margin:0;font-size:12px}.profile-qualification-table-wrap{overflow-x:auto}.profile-qualification-table{width:100%;border-collapse:collapse;font-size:10px}.profile-qualification-table th,.profile-qualification-table td{min-width:105px;padding:7px;border-bottom:1px solid #edf1f4;text-align:left;vertical-align:top}.profile-qualification-table th{color:#708197;font-size:9px;font-weight:700;white-space:nowrap}.profile-qualification-table th:last-child,.profile-qualification-table td:last-child{min-width:55px}.profile-qualification-table input,.profile-qualification-table select,.profile-qualification-table textarea,.mother-tongue-field input,.mother-tongue-field select{box-sizing:border-box;width:100%;min-width:90px;min-height:31px;padding:6px 8px;border:1px solid #d5e0eb;border-radius:6px;background:#fff;color:#263e59;font:inherit}.profile-qualification-table textarea{min-width:140px;resize:vertical}.profile-qualification-table .qualification-empty{padding:14px;color:#8291a5;text-align:center}.qualification-delete{padding:4px 6px;border:0;background:transparent;color:#b43f3f;font-size:10px;cursor:pointer}.mother-tongue-field{display:grid;max-width:360px;gap:6px;margin:10px 0;font-size:10px;font-weight:650}.mother-tongue-field input,.mother-tongue-field select{height:36px}@media(max-width:700px){.profile-qualification-table th,.profile-qualification-table td{padding:6px}}
@media(max-width:700px){.contract-history-toolbar{align-items:flex-start;flex-direction:column}.contract-modal form{grid-template-columns:1fr}.contract-modal form>.contract-error,.contract-modal form>footer{grid-column:auto}}
.compliance-list-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px}.compliance-list-toolbar .compliance-list-title{margin:16px 0 9px}.compliance-manage-button{min-height:30px;padding:0 10px;border:1px solid #08796d;border-radius:6px;background:#08796d;color:#fff;font:inherit;font-size:9px;font-weight:700;cursor:pointer}.compliance-policy-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}.compliance-policy-actions button{min-height:28px;padding:0 9px;border:1px solid #d6e0ec;border-radius:5px;background:#fff;color:#3155bc;font:inherit;font-size:9px;font-weight:650;cursor:pointer}.compliance-policy-actions button:hover{background:#f3f6fb;border-color:#3155bc}.compliance-policy-actions button:last-child{border-color:#f0cccc;background:#fff;color:#a93232}.compliance-policy-actions button:last-child:hover{border-color:#a93232;background:#fff4f4}.compliance-policy-editor form{display:grid;gap:10px;margin-top:14px}.compliance-policy-editor form label{display:grid;gap:5px;color:#53657d;font-size:10px;font-weight:650}.compliance-policy-editor form input,.compliance-policy-editor form select,.compliance-policy-editor form textarea{box-sizing:border-box;width:100%;min-height:35px;padding:7px 9px;border:1px solid #dbe3ef;border-radius:6px;background:#fff;color:#263e59;font:inherit;font-weight:400}.compliance-policy-editor form textarea{resize:vertical}.compliance-policy-editor form footer{display:flex;justify-content:flex-end;gap:8px;margin-top:4px}.compliance-policy-editor .compliance-primary:disabled{opacity:.6}
/* Keep compliance filters readable against browser and site-wide form defaults. */
.compliance-page{display:grid;gap:14px}
.compliance-overview{padding:18px;border-color:#e2e8f0;border-radius:14px;box-shadow:0 8px 24px rgba(24,43,68,.045)}
.compliance-filters{grid-template-columns:minmax(220px,1fr) minmax(140px,170px) minmax(150px,190px);gap:10px}
.compliance-search{box-sizing:border-box;min-width:0;height:42px;padding:0 13px;border-color:#d8e1ec;border-radius:9px;background:#fff;transition:border-color .16s,box-shadow .16s}
.compliance-search:focus-within{border-color:#178578;box-shadow:0 0 0 3px rgba(23,133,120,.12)}
.compliance-search span{flex:none;color:#71839a;font-size:18px}
.compliance-search input{box-sizing:border-box;min-width:0;height:100%;padding:0;background:#fff!important;color:#263e59;font-size:12px;appearance:none}
.compliance-search input::placeholder{color:#8a99ad;opacity:1}
.compliance-filters select{box-sizing:border-box;height:42px;border-color:#d8e1ec;border-radius:9px;background-color:#fff;color:#34475c;font-size:11px}
.compliance-filters select:focus{border-color:#178578;outline:0;box-shadow:0 0 0 3px rgba(23,133,120,.12)}
.compliance-stats{gap:11px;margin-top:16px}
.compliance-stats article{min-height:82px;padding:12px 14px;border-color:#e8edf3;border-left-width:3px;border-radius:10px;background:#fbfcfe}
.compliance-progress{margin-top:18px}
.compliance-progress-track{height:8px}
.compliance-list-toolbar{margin-top:2px}
.compliance-list-toolbar .compliance-list-title{margin:0;color:#172b4d;font-size:15px}
.compliance-grid{gap:14px}
.compliance-card{min-height:205px;padding:16px;border-color:#e4eaf1;border-radius:13px;box-shadow:0 5px 18px rgba(24,43,68,.045);transition:transform .16s,border-color .16s,box-shadow .16s}
.compliance-card:hover{transform:translateY(-2px);border-color:#cbd8e6;box-shadow:0 10px 24px rgba(24,43,68,.08)}
.compliance-card>header{margin-bottom:12px}
.compliance-icon{width:36px;height:36px;border-radius:10px}
.compliance-card-title{font-size:12px;line-height:1.45}
.compliance-card p{font-size:10px;line-height:1.6}
.compliance-assignee{margin-top:auto}
.compliance-card footer{margin-top:14px}
.compliance-card footer button,.compliance-modal footer button{min-height:34px;padding:0 12px;border-radius:7px;font-size:10px;transition:background .15s,border-color .15s,transform .15s}
.compliance-card footer button:hover:not(:disabled),.compliance-modal footer button:hover:not(:disabled){transform:translateY(-1px)}
.compliance-card footer .compliance-secondary,.compliance-modal footer .compliance-secondary{border-color:#d9e2ec;background:#fff;color:#40556d}
.compliance-card footer .compliance-secondary:hover,.compliance-modal footer .compliance-secondary:hover{border-color:#9cacc0;background:#f8fafc}
.compliance-policy-actions{justify-content:flex-start;padding-top:10px;border-top:1px solid #edf1f5}
.compliance-policy-actions button{min-height:30px;padding:0 10px;border-radius:6px;font-size:10px}
.compliance-empty{padding:42px 20px;border-radius:12px}
.compliance-modal{border-radius:16px;box-shadow:0 24px 80px rgba(16,36,61,.22)}
.compliance-policy-editor form input,.compliance-policy-editor form select,.compliance-policy-editor form textarea{min-height:40px;border-radius:8px;font-size:11px}
.compliance-policy-editor form input:focus,.compliance-policy-editor form select:focus,.compliance-policy-editor form textarea:focus{border-color:#178578;outline:0;box-shadow:0 0 0 3px rgba(23,133,120,.12)}
@media(max-width:700px){.compliance-overview{padding:13px}.compliance-filters{grid-template-columns:1fr 1fr}.compliance-search{grid-column:1/-1}.compliance-card{min-height:0}.compliance-list-toolbar{align-items:center}.compliance-policy-actions{justify-content:flex-start}}
.profile-workspace:has(.training-page){padding-top:20px}
.training-page{gap:16px}
.training-page-header{min-height:96px;align-items:center;padding:20px 24px;border:1px solid #dfe8ea;border-radius:12px;background:linear-gradient(110deg,#f0f8f7,#fff 74%);box-shadow:none}
.training-page-header:after{display:none}
.training-page-header h1{margin:6px 0 3px;color:#19334e;font-size:22px;font-weight:730;letter-spacing:-.025em}
.training-page-header p{margin:0;color:#718399;font-size:11px}
.training-modal-kicker{color:#178578;font-size:8px;letter-spacing:.1em}
.training-create-button{min-height:38px;border-radius:7px;box-shadow:none;font-size:10px}
.training-summary{gap:10px}
.training-summary-card{min-height:68px;padding:10px 13px;border-radius:10px;box-shadow:none}
.training-summary-icon{width:32px;height:32px;border-radius:9px;font-size:17px}
.training-card{padding:16px 18px;border:1px solid #e4eaf0;border-radius:12px;box-shadow:none}
.training-card-header{min-height:40px;margin-bottom:13px}
.training-card-heading{gap:9px}
.training-section-icon{width:32px;height:32px;border-radius:8px}
.training-card-header h2{margin:0;color:#1c3550;font-size:13px;font-weight:720}
.training-card-header p{margin-top:4px;color:#8291a3;font-size:10px}
.training-course-count{padding:5px 9px}
.training-manager-empty{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:24px;align-items:center;padding:16px 18px;border:1px solid #e9eef2;border-radius:9px;background:#fbfcfd}
.training-empty-message{display:flex;align-items:center;gap:13px}
.training-empty-icon{display:grid;width:40px;height:40px;flex:none;place-items:center;border:1px solid #dcebe8;border-radius:11px;background:#f0f8f6;color:#31887c;font-size:20px}
.training-empty-message strong,.training-learner-empty strong{display:block;color:#243a53;font-size:12px;font-weight:700}
.training-empty-message p,.training-learner-empty p{margin:5px 0 0;color:#7c8da1;font-size:10px;line-height:1.6}
.training-topic-suggestions{padding-left:20px;border-left:1px solid #e5ebef}
.training-topic-suggestions>span{color:#8493a4;font-size:8px;font-weight:750;letter-spacing:.08em}
.training-topic-suggestions>div{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.training-topic-suggestions button{display:inline-flex;min-height:26px;align-items:center;gap:6px;padding:0 8px;border:1px solid #dfe8e8;border-radius:99px;background:#fff;color:#49645f;font:inherit;font-size:8px;cursor:pointer;transition:border-color .12s,background .12s,color .12s}
.training-topic-suggestions button:hover{border-color:#a8cbc3;background:#f1f8f6;color:#176f64}
.training-topic-suggestions button span{color:#168578;font-size:12px}
.training-learner-empty{display:flex;min-height:75px;align-items:center;gap:12px;padding:14px 16px;border:1px dashed #dce5eb;border-radius:9px;background:#fafcfd}
.training-empty-symbol{display:grid;width:36px;height:36px;flex:none;place-items:center;border-radius:50%;background:#eff7f2;color:#4e9970;font-size:17px;font-weight:700}
.training-learner-empty-note{margin-left:auto;color:#8a99a9;font-size:9px;text-align:right}
.training-loading{display:flex;min-height:74px;align-items:center;justify-content:center;gap:9px;color:#76889a;font-size:10px}
.training-spinner{width:15px;height:15px;border:2px solid #d9e6e5;border-top-color:#168578;border-radius:50%;animation:training-spin .7s linear infinite}
@keyframes training-spin{to{transform:rotate(360deg)}}
.training-management .training-table-wrap{border-radius:8px}
.training-management-table{width:100%;table-layout:auto}
.training-management-table th,.training-management-table td{white-space:nowrap}
.training-management-table td:first-child{width:31%;white-space:normal}
.training-management-table td:nth-child(2){min-width:170px}
.training-management-table td:nth-child(3),.training-management-table td:nth-child(4),.training-management-table td:nth-child(5){width:1%}
.training-management-table .training-topic{max-width:270px}
.training-action-buttons{display:flex;align-items:center;gap:7px}
.training-table td{padding:11px 12px}
.training-table th{padding:10px 12px}
.training-table td:first-child{min-width:150px}
.training-topic{max-width:235px}
.training-status{font-size:8px}
.training-start{min-height:29px}
.training-error{border-radius:8px}
@media(max-width:760px){.training-manager-empty{grid-template-columns:1fr;gap:14px}.training-topic-suggestions{padding:12px 0 0;border-top:1px solid #e5ebef;border-left:0}.training-learner-empty{align-items:flex-start;flex-wrap:wrap}.training-learner-empty-note{width:100%;margin-left:48px;text-align:left}}
@media(max-width:560px){.profile-workspace:has(.training-page){padding-top:12px}.training-page{gap:12px}.training-page-header{align-items:flex-start;flex-direction:column;padding:16px}.training-create-button{align-self:flex-start}.training-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.training-card{padding:13px}.training-card-header{align-items:flex-start}.training-topic-suggestions>div{gap:5px}.training-topic-suggestions button{font-size:8px}}
.training-assign-button{min-height:30px;padding:0 9px;border:1px solid #c9e2dc;border-radius:6px;background:#f2f9f7;color:#167568;font:inherit;font-size:9px;font-weight:700;white-space:nowrap;cursor:pointer;transition:background .14s,border-color .14s}
.training-assign-button:hover{border-color:#9bc9be;background:#e7f5f1}
.training-staff-setup{display:flex;align-items:center;gap:12px;padding:13px 15px;border:1px solid #e9dcb9;border-radius:9px;background:#fffaf0}
.training-setup-icon{display:grid;width:27px;height:27px;flex:none;place-items:center;border-radius:50%;background:#fff0cf;color:#9c6b16;font-size:13px;font-weight:750}
.training-staff-setup>div{flex:1}.training-staff-setup strong{color:#5b4a27;font-size:10px}.training-staff-setup p{margin:4px 0 0;color:#7e7055;font-size:9px;line-height:1.5}
.training-staff-setup>a,.training-no-staff>a{color:#08796d;font-size:9px;font-weight:750;text-decoration:none;white-space:nowrap}.training-staff-setup>a:hover,.training-no-staff>a:hover{text-decoration:underline}
.training-assignment-modal{width:min(100%,540px)}
.training-assignment-course{margin:0 0 14px;padding:9px 11px;border:1px solid #e5eceb;border-radius:7px;background:#f6faf9;color:#385b55;font-size:10px;font-weight:700}
.training-staff-search{display:grid;gap:5px;margin-bottom:10px;color:#52667e;font-size:10px;font-weight:650}
.training-staff-search input{box-sizing:border-box;width:100%;min-height:37px;padding:8px 10px;border:1px solid #d9e2ec;border-radius:7px;background:#fff;color:#263e59;font:inherit;font-weight:400}
.training-staff-search input:focus{border-color:#168578;outline:0;box-shadow:0 0 0 3px rgba(22,133,120,.12)}
.training-assignment-list{max-height:270px;overflow:auto;border:1px solid #e5eaf0;border-radius:8px}
.training-assignment-person{display:grid;grid-template-columns:18px 32px minmax(0,1fr) auto;min-height:58px;align-items:center;gap:10px;padding:7px 12px;border-bottom:1px solid #edf1f4;background:#fff;cursor:pointer;transition:background .12s,border-color .12s}
.training-assignment-person:last-of-type{border-bottom:0}.training-assignment-person:hover{background:#f7fbfa}
.training-assignment-person.is-selected{background:#eff8f5}
.training-assignment-modal .training-assignment-person>input[type="checkbox"]{display:grid;box-sizing:border-box;width:18px!important;min-width:18px!important;height:18px!important;min-height:18px!important;margin:0!important;padding:0!important;place-content:center;appearance:none;border:1.5px solid #b8c6d3;border-radius:5px;background:#fff;cursor:pointer}
.training-assignment-modal .training-assignment-person>input[type="checkbox"]:checked{border-color:#08796d;background:#08796d}
.training-assignment-modal .training-assignment-person>input[type="checkbox"]:checked:after{width:8px;height:4px;transform:rotate(-45deg) translate(1px,-1px);border-bottom:2px solid #fff;border-left:2px solid #fff;content:""}
.training-assignment-modal .training-assignment-person>input[type="checkbox"]:focus-visible{outline:3px solid rgba(8,121,109,.2);outline-offset:2px}
.training-person-avatar{display:grid;box-sizing:border-box;width:32px;height:32px;place-items:center;border-radius:50%;background:#edf3f8;color:#4b6886;font-size:11px;font-weight:750}
.training-person-details{display:grid;flex:1;gap:3px;min-width:0}.training-person-details strong{overflow:hidden;color:#2d425b;font-size:10px;text-overflow:ellipsis;white-space:nowrap}.training-person-details small{overflow:hidden;color:#8493a4;font-size:9px;text-overflow:ellipsis;white-space:nowrap}
.training-role-pill{padding:4px 7px;border-radius:99px;background:#f1f4f8;color:#6e7e91;font-size:8px;text-transform:capitalize;white-space:nowrap}
.training-assignment-no-results{padding:15px;color:#8190a1;font-size:10px;text-align:center}
.training-no-staff{display:grid;justify-items:center;gap:7px;padding:25px 12px;border:1px dashed #dce5eb;border-radius:8px;background:#fafcfd;text-align:center}
.training-no-staff strong{color:#344961;font-size:11px}.training-no-staff p{max-width:300px;margin:0;color:#8291a3;font-size:9px;line-height:1.5}
.training-assignment-selected{margin:10px 0 0;color:#6b7e92;font-size:9px}
.training-assignment-modal>footer{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
@media(max-width:560px){.training-staff-setup{align-items:flex-start;flex-wrap:wrap}.training-staff-setup>a{margin-left:39px}.training-role-pill{display:none}.training-assignment-modal{padding:20px}}
</style>
