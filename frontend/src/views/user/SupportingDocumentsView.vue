<script setup>
import { reactive, ref } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'

const initialDocuments = [
  {
    id: 1,
    title: 'ID card / passport',
    description: 'Clear copy of applicant identification document.',
    uploadDate: '2026-10-20',
    remark: 'Front and back page included',
    fileName: 'id-passport.pdf',
  },
  {
    id: 2,
    title: 'Family record book',
    description: 'Marriage certification and family registration record.',
    uploadDate: '2026-10-21',
    remark: 'Valid for review',
    fileName: 'family-record-book.pdf',
  },
]

const documents = ref(initialDocuments)
const notes = ref('')
const openForm = ref(false)
const editingId = ref(null)

const emptyForm = () => ({
  title: '',
  description: '',
  uploadDate: '',
  remark: '',
  fileName: '',
})

const form = reactive(emptyForm())

const resetForm = () => {
  Object.assign(form, emptyForm())
  editingId.value = null
  openForm.value = false
}

const openAddForm = () => {
  resetForm()
  openForm.value = true
}

const openEditForm = (document) => {
  Object.assign(form, {
    title: document.title,
    description: document.description,
    uploadDate: document.uploadDate,
    remark: document.remark,
    fileName: document.fileName,
  })
  editingId.value = document.id
  openForm.value = true
}

const handleFileChange = (event) => {
  const file = event.target.files?.[0]
  form.fileName = file ? file.name : ''
}

const saveDocument = () => {
  if (!form.title.trim()) return

  const payload = {
    title: form.title.trim(),
    description: form.description.trim(),
    uploadDate: form.uploadDate,
    remark: form.remark.trim(),
    fileName: form.fileName || 'No file selected',
  }

  if (editingId.value !== null) {
    const index = documents.value.findIndex((doc) => doc.id === editingId.value)
    if (index >= 0) {
      documents.value[index] = { ...documents.value[index], ...payload }
    }
  } else {
    documents.value.unshift({
      id: Date.now(),
      ...payload,
    })
  }

  resetForm()
}

const removeDocument = (documentId) => {
  documents.value = documents.value.filter((doc) => doc.id !== documentId)
}
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

      <div class="supporting-documents-shell">
        <nav class="onboarding-steps" aria-label="Onboarding steps">
          <RouterLink class="onboarding-step is-complete" to="/me/personal-information"><span>✓</span> Personal Information</RouterLink>
          <RouterLink class="onboarding-step is-complete" to="/me/professional-qualifications"><span>✓</span> Professional Qualifications</RouterLink>
          <RouterLink class="onboarding-step is-complete" to="/me/family-information"><span>✓</span> Family Information</RouterLink>
          <span class="onboarding-step is-active" aria-current="step"><span>4</span> Supporting Documents</span>
          <span class="onboarding-step is-upcoming"><span>5</span> Declaration</span>
        </nav>

        <header class="supporting-documents-heading">
          <div>
            <p class="supporting-documents-kicker">STEP 4 OF 5 <span>NEW HIRE PROFILE</span></p>
            <h1>Supporting Documents</h1>
            <p>Please upload the required documents to complete your onboarding file.</p>
          </div>
          <div class="supporting-documents-progress">
            <div><span>Overall Completion</span><strong>80%</strong></div>
            <span><i></i></span>
            <small>Next: Declaration</small>
          </div>
        </header>

        <section class="supporting-documents-panel">
          <div class="supporting-documents-panel-header">
            <div>
              <h2>Document List</h2>
              <p>Accepted formats: PDF, JPG, PNG</p>
            </div>
            <button type="button" class="supporting-documents-add" @click="openAddForm">+ Add document</button>
          </div>

          <div v-if="openForm" class="supporting-documents-form-card">
            <div class="supporting-documents-form-grid">
              <label>
                <span>Document title</span>
                <input v-model="form.title" type="text" placeholder="Enter document title" />
              </label>

              <label>
                <span>Uploading Date</span>
                <input v-model="form.uploadDate" type="date" />
              </label>

              <label class="supporting-documents-field-full">
                <span>Description</span>
                <textarea v-model="form.description" rows="3" placeholder="Brief description of the document"></textarea>
              </label>

              <label class="supporting-documents-field-full">
                <span>Remark</span>
                <textarea v-model="form.remark" rows="2" placeholder="Optional remarks"></textarea>
              </label>

              <label class="supporting-documents-field-full">
                <span>Attachment</span>
                <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="handleFileChange" />
                <small v-if="form.fileName">Selected file: {{ form.fileName }}</small>
              </label>
            </div>

            <div class="supporting-documents-form-actions">
              <button type="button" class="supporting-documents-btn secondary" @click="resetForm">Cancel</button>
              <button type="button" class="supporting-documents-btn primary" @click="saveDocument">{{ editingId !== null ? 'Update' : 'Save document' }}</button>
            </div>
          </div>

          <div class="supporting-documents-table-wrap">
            <table class="supporting-documents-table">
              <thead>
                <tr>
                  <th>Document title</th>
                  <th>Description</th>
                  <th>Uploading Date</th>
                  <th>Remark</th>
                  <th>Update</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="document in documents" :key="document.id">
                  <td>{{ document.title }}</td>
                  <td>{{ document.description || '—' }}</td>
                  <td>{{ document.uploadDate || '—' }}</td>
                  <td>{{ document.remark || '—' }}</td>
                  <td>
                    <div class="supporting-documents-row-actions">
                      <button type="button" class="supporting-documents-link" @click="openEditForm(document)">Edit</button>
                      <button type="button" class="supporting-documents-link danger" @click="removeDocument(document.id)">Delete</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="documents.length === 0">
                  <td colspan="5" class="supporting-documents-empty">No documents added yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="supporting-documents-notes">
          <div class="supporting-documents-notes-header">
            <h3>Your notes to the organization</h3>
          </div>
          <textarea v-model="notes" rows="7" placeholder="(Long Text)"></textarea>
        </section>

        <footer class="supporting-documents-actions">
          <RouterLink class="supporting-documents-button is-quiet" to="/">Home</RouterLink>
          <div>
            <RouterLink class="supporting-documents-button is-quiet" to="/me/family-information">Previous</RouterLink>
            <button type="button" class="supporting-documents-button is-primary">Next</button>
            <button type="button" class="supporting-documents-button is-quiet">Save</button>
            <button type="button" class="supporting-documents-button is-quiet">Exit</button>
          </div>
        </footer>
      </div>
    </div>
  </UserLayout>
</template>

<style scoped>
:global(.shell.onboarding-shell > main){padding:0}
.supporting-documents-shell{width:min(100% - 36px,1200px);margin:0 auto;padding-bottom:32px}
.onboarding-steps{display:flex;flex-wrap:wrap;align-items:stretch;margin:8px 0 16px;border-bottom:1px solid #dfe6f2;background:#fff;box-shadow:0 1px 0 rgba(17,24,39,.02)}
.onboarding-step{display:flex;flex:1 1 145px;align-items:center;gap:6px;min-height:34px;padding:9px 12px;border-bottom:2px solid transparent;color:#6e7d97;font-size:9px;text-decoration:none}
.onboarding-step>span{display:grid;width:14px;height:14px;flex:none;place-items:center;border-radius:50%;background:#e7edf7;color:#687994;font-size:8px;font-weight:700}
.onboarding-step.is-active{border-color:#2949d0;color:#243fb1}
.onboarding-step.is-active>span{background:#dfe6ff;color:#304bc4}
.onboarding-step.is-complete{color:#2949c7;text-decoration:none}
.onboarding-step.is-complete>span{background:#2949c7;color:#fff}
.supporting-documents-kicker{display:flex;width:fit-content;align-items:center;gap:7px;margin:0 0 5px;padding:3px 6px;border-radius:4px;background:#304bc9;color:#fff;font-size:8px;font-weight:800}
.supporting-documents-kicker span{color:#56698b;background:#fff;border-radius:4px;padding:2px 5px}
.supporting-documents-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:80px;margin:0 0 16px;padding:18px 20px;border:1px solid #e7ecf5;border-radius:14px;background:linear-gradient(180deg,#ffffff 0%,#f9fbff 100%)}
.supporting-documents-heading h1{margin:0;color:#152945;font-size:26px;letter-spacing:-.04em}
.supporting-documents-heading>div:first-child>p:last-child{margin:8px 0 0;color:#6e7c95;font-size:12px}
.supporting-documents-progress{width:220px;flex:none}
.supporting-documents-progress>div{display:flex;justify-content:space-between;color:#687993;font-size:10px}
.supporting-documents-progress strong{color:#304bc9;font-size:13px}
.supporting-documents-progress>span{display:block;height:6px;margin-top:8px;overflow:hidden;border-radius:99px;background:#e8edf7}
.supporting-documents-progress i{display:block;width:80%;height:100%;border-radius:inherit;background:linear-gradient(90deg,#304bc9,#5d7af1)}
.supporting-documents-progress small{display:block;margin-top:5px;color:#71809b;font-size:9px}
.supporting-documents-panel,.supporting-documents-notes{padding:18px;border:1px solid #e7ecf5;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.03)}
.supporting-documents-panel-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px}
.supporting-documents-panel-header h2{margin:0;color:#1a2c45;font-size:18px}
.supporting-documents-panel-header p{margin:6px 0 0;color:#6f7f99;font-size:12px}
.supporting-documents-add{border:none;border-radius:10px;background:#e9eefc;color:#2b4bc9;font-weight:700;padding:10px 14px;cursor:pointer}
.supporting-documents-form-card{margin-bottom:18px;padding:18px;border:1px solid #e7ecf5;border-radius:12px;background:#f7faff}
.supporting-documents-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.supporting-documents-field-full{grid-column:1 / -1}
.supporting-documents-form-grid label{display:flex;flex-direction:column;gap:8px;color:#344863;font-size:12px;font-weight:600}
.supporting-documents-form-grid input,.supporting-documents-form-grid textarea,.supporting-documents-notes textarea{width:100%;border:1px solid #dfe7f3;border-radius:10px;background:#fff;padding:12px 14px;font:inherit;color:#1b2d4d;box-sizing:border-box;resize:vertical}
.supporting-documents-form-grid input:focus,.supporting-documents-form-grid textarea:focus,.supporting-documents-notes textarea:focus{border-color:#2b4bc9;outline:none;box-shadow:0 0 0 3px rgba(43,75,201,.12)}
.supporting-documents-form-grid small{color:#68809d;font-weight:500}
.supporting-documents-form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}
.supporting-documents-btn{padding:10px 18px;border-radius:10px;border:1px solid transparent;cursor:pointer;font-weight:700}
.supporting-documents-btn.primary{background:#2b4bc9;color:#fff}
.supporting-documents-btn.secondary{background:#fff;border-color:#dfe7f3;color:#2e3f5c}
.supporting-documents-table-wrap{overflow:auto;border:1px solid #edf1f7;border-radius:12px}
.supporting-documents-table{width:100%;border-collapse:collapse;min-width:760px;background:#fff}
.supporting-documents-table th,.supporting-documents-table td{padding:14px 16px;border-bottom:1px solid #edf1f7;text-align:left;vertical-align:top;color:#334763;font-size:12px}
.supporting-documents-table th{background:#f5f7fb;color:#53657a;font-size:10px;letter-spacing:.08em;text-transform:uppercase}
.supporting-documents-row-actions{display:flex;gap:10px;flex-wrap:wrap}
.supporting-documents-link{padding:0;border:none;background:none;color:#2d52d2;font-weight:700;cursor:pointer}
.supporting-documents-link.danger{color:#c44949}
.supporting-documents-empty{padding:22px 16px;text-align:center;color:#70829a}
.supporting-documents-notes{margin-top:20px}
.supporting-documents-notes-header h3{margin:0 0 12px;color:#1a2c45;font-size:18px}
.supporting-documents-notes textarea{min-height:170px}
.supporting-documents-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:20px}
.supporting-documents-actions>div{display:flex;gap:8px;flex-wrap:wrap}
.supporting-documents-button{display:inline-flex;min-height:38px;align-items:center;justify-content:center;padding:0 16px;border:1px solid #dfe5ef;border-radius:9px;background:#fff;color:#455671;font:inherit;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;cursor:pointer}
.supporting-documents-button.is-primary{border-color:#2f49c4;background:#2f49c4;color:#fff}
.supporting-documents-button.is-quiet{background:#f5f7fb}
@media(max-width:760px){
  .supporting-documents-shell{width:calc(100% - 20px)}
  .supporting-documents-heading{flex-direction:column;align-items:flex-start}.supporting-documents-progress{width:100%}.supporting-documents-form-grid{grid-template-columns:1fr}
  .supporting-documents-panel-header,.supporting-documents-actions{flex-direction:column;align-items:flex-start}
  .supporting-documents-actions>div{width:100%}.supporting-documents-button{flex:1 1 auto;min-width:0}
}
</style>
