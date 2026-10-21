<script setup>
import { computed, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const steps = ['Personal information', 'Professional Qualifications', 'Family information', 'Supporting Documents', 'Declarations']
const step = ref(0)
const children = ref([
  { name: 'Dara Penh', birthDate: '2020-02-03', sex: 'Male' },
  { name: 'Srey Penh', birthDate: '2023-11-20', sex: 'Female' },
])
const emergencyContacts = ref([{}])
const beneficiaries = ref([])
const complianceSearch = ref('')
const complianceStatusFilter = ref('all')
const complianceCategoryFilter = ref('all')
const selectedCompliance = ref(null)
const complianceItems = ref([
  { id: 1, title: 'Adult Safeguarding (PSEAH)', category: 'Safeguarding', description: 'I confirm that I have read and understood the Adult Safeguarding (PSEAH) policy.', status: 'Pending', icon: 'shield' },
  { id: 2, title: 'Anti-Fraud', category: 'Financial & Integrity', description: 'I confirm that I have read and understood the Anti-Fraud policy.', status: 'Pending', icon: 'alert' },
  { id: 3, title: 'Child Safeguarding', category: 'Safeguarding', description: 'I confirm that I have read and understood the Child Safeguarding policy.', status: 'Pending', icon: 'shield' },
  { id: 4, title: 'Code of Conduct', category: 'Ethics & Conduct', description: 'I confirm that I have read and understood the Code of Conduct policy.', status: 'Pending', icon: 'drop' },
  { id: 5, title: 'Conflict of Interest', category: 'Ethics & Conduct', description: 'I declare that I have no undisclosed conflict of interest.', status: 'Pending', icon: 'drop' },
])
const contracts = ref([
  { id: 1, contract: 'Full-Time', position: 'Program Officer', department: 'Program', startDate: '01 Apr 2026', endDate: '31 Mar 2027', salary: '$700', status: 'Active', file: '' },
  { id: 2, contract: 'Probationary', position: 'Program Assistant', department: 'Program', startDate: '01 Jan 2026', endDate: '31 Mar 2026', salary: '$450', status: 'Completed', file: '' },
])
const trainingCourses = ref([{ id: 1, title: 'Child Safeguarding', progress: 0, score: '—', due: '21 Oct 2026', status: 'Pending' }])
const selectedCourse = ref(null)
const section = computed(() => typeof route.query.section === 'string' ? route.query.section : 'my-profile')
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
const addChild = () => children.value.push({ name: '', birthDate: '', sex: '' })
const addEmergencyContact = () => emergencyContacts.value.push({})
const addBeneficiary = () => beneficiaries.value.push({})
const exitProfile = () => router.push('/admin')
const completeCompliance = (item) => { item.status = 'Completed'; selectedCompliance.value = null }
</script>

<template>
  <AdminLayout>
    <div class="profile-workspace">
      <div class="profile-page-top"><div><h1>{{ heading }}</h1><p>{{ section === 'compliances' ? 'Manage your required policies and declarations.' : section === 'contract-history' ? 'All contracts on record — probationary, full-time, part-time, and more.' : section === 'training' ? 'Complete assigned courses — each ends with a quiz and certificate.' : 'Complete all five tabs — your progress is saved per tab.' }}</p></div></div>

      <template v-if="section === 'my-profile' || !sectionLabels[section]">
        <ol class="profile-stepper" aria-label="Profile steps">
          <li v-for="(label, index) in steps" :key="label" :class="{ done: index < step, current: index === step }">
            <span class="step-dot">{{ index < step ? 'âœ“' : index + 1 }}</span><span>{{ label }}</span>
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
          <template v-else-if="step === 2">
          <div class="form-section-title"><span>â–£</span><h2>Family Situation</h2></div>
          <div class="form-grid family-grid">
            <label>Marital status<select><option>Married</option><option>Single</option><option>Divorced</option><option>Widowed</option></select></label>
            <label>Spouse / partner name<input placeholder="e.g. Chearly Chea" /></label>
            <label>Number of children (below 18 years old)<input type="number" min="0" :value="children.length" /></label>
          </div>
          <small class="field-hint">Automatically sourced from the children below.</small>

          <div class="repeat-label">Children detail</div>
          <div v-for="(child, index) in children" :key="index" class="child-row">
            <label>Name<input v-model="child.name" placeholder="Child's name" /></label>
            <label>Date of Birth<input v-model="child.birthDate" type="date" /></label>
            <label>Sex<select v-model="child.sex"><option value="">Select</option><option>Male</option><option>Female</option></select></label>
            <span class="child-age">{{ index === 0 ? '6 yrs' : '2 yrs' }}</span>
            <button class="remove-row" type="button" aria-label="Remove child" @click="children.splice(index, 1)">Ã—</button>
          </div>
          <button class="add-row" type="button" @click="addChild">ï¼‹ Add child</button>

          <div class="form-divider"></div>
          <section class="repeat-section">
            <div class="form-section-title"><span>â–£</span><h2>Emergency Contact</h2><span class="info-mark">i</span></div>
            <div v-for="(contact, index) in emergencyContacts" :key="index" class="contact-grid">
              <label>Name <b>*</b><input v-model="contact.name" /></label>
              <label>Relationship <b>*</b><input v-model="contact.relationship" placeholder="e.g. Father, Spouse" /></label>
              <label>Phone <b>*</b><input v-model="contact.phone" type="tel" /></label>
              <label class="wide-field">Email<input v-model="contact.email" type="email" /></label>
              <label class="wide-field">Current Address<input v-model="contact.address" /></label>
            </div>
            <button class="add-row" type="button" @click="addEmergencyContact">ï¼‹ Add emergency contact</button>
          </section>

          <div class="form-divider"></div>
          <section class="repeat-section beneficiaries-section">
            <div class="form-section-title"><span>â–£</span><h2>Beneficiaries</h2><span class="info-mark">i</span><small>Total shares: 0%</small></div>
            <div v-for="(beneficiary, index) in beneficiaries" :key="index" class="beneficiary-row">
              <label>Name<input v-model="beneficiary.name" /></label><label>Relationship<input v-model="beneficiary.relationship" /></label><label>Share (%)<input v-model="beneficiary.share" type="number" min="0" max="100" /></label>
            </div>
            <button class="add-row" type="button" @click="addBeneficiary">ï¼‹ Add beneficiary</button>
          </section>
          </template>
          <div v-else class="profile-step-placeholder"><div class="form-section-title"><span>✓</span><h2>{{ steps[step] }}</h2></div><p>Complete this section of your personnel profile.</p></div>

          <footer class="form-actions"><button type="button" class="home-button">Home</button><span class="step-count">Tab {{ step + 1 }} of 5</span><button type="button" :disabled="step === 0" @click="step = Math.max(0, step - 1)">Previous</button><div class="action-spacer"></div><button type="button">Save</button><button type="button" @click="exitProfile">Exit</button><button class="next-button" type="button" @click="step = Math.min(4, step + 1)">{{ step === 4 ? 'Finish' : 'Next →' }}</button></footer>
        </section>

        <section class="change-requests">
          <div class="form-section-title"><span>â–¤</span><h2>My Change Requests</h2></div>
          <div class="request-table"><div class="request-head"><span>FIELD</span><span>REQUESTED VALUE</span><span>REASON</span><span>STATUS</span></div><div class="request-row"><span>ID / Passport Number</span><span>01002345</span><span>Received my renewed national ID; the number on file was misplaced.</span><span><b>Pending</b></span><button type="button" aria-label="Remove request">Ã—</button></div></div>
        </section>
      </template>
      <section v-else-if="section === 'compliances'" class="compliance-page">
        <section class="compliance-overview" aria-label="Compliance overview">
          <div class="compliance-filters"><label class="compliance-search"><span aria-hidden="true">⌕</span><input v-model="complianceSearch" type="search" placeholder="Search compliance..." aria-label="Search compliance" /></label>
            <select v-model="complianceStatusFilter" aria-label="Filter by status"><option value="all">All statuses</option><option value="completed">Completed</option><option value="pending">Pending</option></select>
            <select v-model="complianceCategoryFilter" aria-label="Filter by category"><option value="all">All categories</option><option v-for="category in complianceCategories" :key="category" :value="category">{{ category }}</option></select>
          </div>
          <div class="compliance-stats"><article class="required"><small>REQUIRED</small><strong>{{ complianceItems.length }}</strong><span>compliance</span></article><article class="completed"><small>COMPLETED</small><strong>{{ completedCompliances }}</strong><span>✓ up to date</span></article><article class="pending"><small>PENDING</small><strong>{{ pendingCompliances }}</strong><span>⚠ action needed</span></article><article class="expired"><small>EXPIRED</small><strong>0</strong><span>—</span></article></div>
          <div class="compliance-progress"><div><strong>Compliance Progress</strong><b>{{ Math.round(completedCompliances / Math.max(complianceItems.length, 1) * 100) }}%</b></div><div class="compliance-progress-track"><i :style="{ width: `${completedCompliances / Math.max(complianceItems.length, 1) * 100}%` }"></i></div><small>{{ completedCompliances }} of {{ complianceItems.length }} completed</small></div>
        </section>
        <h2 class="compliance-list-title">Required Compliance</h2>
        <div v-if="filteredCompliances.length" class="compliance-grid">
          <article v-for="item in filteredCompliances" :key="item.id" class="compliance-card">
            <header><span class="compliance-icon" :class="`icon-${item.icon}`">{{ item.icon === 'shield' ? '♢' : item.icon === 'alert' ? '!' : '◉' }}</span><span class="compliance-status" :class="item.status.toLowerCase()">{{ item.status }}</span></header>
            <strong class="compliance-card-title">{{ item.title }}</strong><small class="compliance-category" :class="`category-${item.icon}`">{{ item.category }}</small><p>{{ item.description }}</p><small class="compliance-assignee">Assigned by HR</small>
            <footer><button type="button" class="compliance-secondary" @click="selectedCompliance = item">View Details</button><button type="button" class="compliance-primary" :disabled="item.status === 'Completed'" @click="completeCompliance(item)">{{ item.status === 'Completed' ? 'Completed' : 'Complete Now →' }}</button></footer>
          </article>
        </div>
        <div v-else class="compliance-empty">No compliance items match your search and filters.</div>
        <div v-if="selectedCompliance" class="compliance-modal-backdrop" @click.self="selectedCompliance = null"><section class="compliance-modal" role="dialog" aria-modal="true" :aria-labelledby="`compliance-title-${selectedCompliance.id}`"><button class="compliance-modal-close" type="button" aria-label="Close" @click="selectedCompliance = null">×</button><span class="compliance-category">{{ selectedCompliance.category }}</span><h2 :id="`compliance-title-${selectedCompliance.id}`">{{ selectedCompliance.title }}</h2><p>{{ selectedCompliance.description }}</p><p class="compliance-modal-note">This requirement was assigned by HR. Review the policy with your HR team if you have questions.</p><footer><button class="compliance-secondary" type="button" @click="selectedCompliance = null">Close</button><button v-if="selectedCompliance.status !== 'Completed'" class="compliance-primary" type="button" @click="completeCompliance(selectedCompliance)">Confirm and Complete</button></footer></section></div>
      </section>
      <section v-else-if="section === 'contract-history'" class="contract-history-page">
        <div class="contract-history-card"><div class="contract-table-wrap"><table class="contract-table"><thead><tr><th>CONTRACT</th><th>POSITION</th><th>START DATE</th><th>END DATE</th><th>SALARY</th><th>STATUS</th><th>FILE</th></tr></thead><tbody><tr v-for="contract in contracts" :key="contract.id"><td><strong>{{ contract.contract }}</strong></td><td><span>{{ contract.position }}</span><small>{{ contract.department }}</small></td><td>{{ contract.startDate }}</td><td>{{ contract.endDate }}</td><td>{{ contract.salary }}</td><td><span class="contract-status" :class="contract.status.toLowerCase()">{{ contract.status }}</span></td><td><a v-if="contract.file" :href="contract.file" target="_blank" rel="noopener">View file</a><span v-else class="contract-no-file">—</span></td></tr></tbody></table></div></div>
      </section>
      <section v-else-if="section === 'training'" class="training-page">
        <div class="training-card"><div class="training-table-wrap"><table class="training-table"><thead><tr><th>TRAINING</th><th>PROGRESS</th><th>SCORE</th><th>DUE</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="course in trainingCourses" :key="course.id"><td><strong>{{ course.title }}</strong></td><td><div class="training-progress-track"><i :style="{ width: `${course.progress}%` }"></i></div><small>{{ course.progress }}%</small></td><td>{{ course.score }}</td><td>{{ course.due }}</td><td><span class="training-status" :class="course.status.toLowerCase().replace(' ', '-')">{{ course.status }}</span></td><td><button class="training-start" type="button" @click="selectedCourse = course">{{ course.status === 'Pending' ? 'Start' : 'Continue' }}</button></td></tr></tbody></table></div></div>
        <div v-if="selectedCourse" class="training-modal-backdrop" @click.self="selectedCourse = null"><section class="training-modal" role="dialog" aria-modal="true" aria-labelledby="training-modal-title"><button class="training-modal-close" type="button" aria-label="Close" @click="selectedCourse = null">×</button><span class="training-modal-kicker">ASSIGNED COURSE</span><h2 id="training-modal-title">{{ selectedCourse.title }}</h2><p>This course includes learning material followed by a short quiz. Complete both to receive your certificate.</p><div class="training-modal-meta"><span>Due {{ selectedCourse.due }}</span><span>Current progress: {{ selectedCourse.progress }}%</span></div><button class="training-start training-modal-action" type="button" @click="selectedCourse.status = 'In Progress'; selectedCourse = null">{{ selectedCourse.status === 'Pending' ? 'Begin Course' : 'Continue Course' }}</button></section></div>
      </section>
      <section v-else class="other-section"><h2>{{ heading }}</h2><p>{{ heading }} information will appear here.</p></section>
    </div>
  </AdminLayout>
</template>

<style scoped>
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
</style>


