<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { createContract, deleteContract, downloadContractFile, getMyContracts, getStaffAccounts, getStaffContracts, updateContract } from '../../services/contractService'

const auth = useAuthStore()
// Master Admins act as HR: they can view anyone's contracts and manage them.
const canManage = computed(() => auth.isAdmin)

const TYPES = { probationary: 'Probationary', full_time: 'Full-time', part_time: 'Part-time', fixed_term: 'Fixed-term', consultancy: 'Consultancy', internship: 'Internship' }
const STATUS = {
  active: { label: 'Active', icon: 'check-circle', tone: 'success' },
  ending: { label: 'Ending soon', icon: 'clock', tone: 'warning' },
  upcoming: { label: 'Upcoming', icon: 'calendar', tone: 'info' },
  ended: { label: 'Ended', icon: 'circle-dot', tone: 'muted' },
}
const statusOf = (contract) => STATUS[contract.ending_soon ? 'ending' : contract.status]

/* ---------- Loading ---------- */
const contracts = ref([])
const staff = ref([])
const viewingId = ref(auth.user?.id ?? null)
const loading = ref(true)
const loadError = ref('')
const toast = ref('')
const describe = (error, fallback) => (!error.response ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500 ? 'Something went wrong on our side. Please try again in a moment.'
  : error.response.data?.message || fallback)

const load = async ({ quiet = false } = {}) => {
  if (!quiet) loading.value = true
  loadError.value = ''
  try {
    const own = !canManage.value || !viewingId.value || viewingId.value === auth.user?.id
    const { data } = own ? await getMyContracts() : await getStaffContracts(viewingId.value)
    contracts.value = data.data
  } catch (error) {
    loadError.value = describe(error, 'Contracts couldn’t be loaded.')
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  load()
  if (canManage.value) {
    try { staff.value = (await getStaffAccounts()).data.data.slice().sort((a, b) => a.name.localeCompare(b.name)) } catch { staff.value = [] }
  }
})
watch(viewingId, () => { toast.value = ''; load() })
const viewingName = computed(() => (viewingId.value === auth.user?.id ? 'you' : staff.value.find((person) => person.id === viewingId.value)?.name || 'this person'))

/* ---------- Formatting ---------- */
const toDate = (iso) => (iso ? new Date(`${iso}T00:00:00`) : null)
const formatDate = (iso) => (iso ? toDate(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const period = (contract) => `${formatDate(contract.start_date)} – ${contract.end_date ? formatDate(contract.end_date) : 'Open-ended'}`
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`
const spanText = (days) => {
  const months = Math.round(days / 30.44)
  if (months < 1) return plural(Math.max(days, 1), 'day')
  if (months % 12 === 0) return plural(months / 12, 'year')
  return plural(months, 'month')
}
const duration = (contract) => (contract.end_date ? spanText(Math.round((toDate(contract.end_date) - toDate(contract.start_date)) / 864e5) + 1) : 'Open-ended')
const today = () => { const now = new Date(); return new Date(now.getFullYear(), now.getMonth(), now.getDate()) }
const money = (contract) => {
  if (contract.salary === null || contract.salary === undefined || contract.salary === '') return 'Not recorded'
  const amount = Number(contract.salary)
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: contract.currency, maximumFractionDigits: Number.isInteger(amount) ? 0 : 2 }).format(amount)
}

/* ---------- The contract that applies now (or next) ---------- */
const current = computed(() => contracts.value.find((contract) => contract.status === 'active') || [...contracts.value].reverse().find((contract) => contract.status === 'upcoming') || null)
const currentProgress = computed(() => {
  const contract = current.value
  if (!contract) return null
  if (contract.status === 'upcoming') return { label: `Starts in ${spanText(Math.round((toDate(contract.start_date) - today()) / 864e5))}`, percent: 0 }
  if (!contract.end_date) return { label: `Started ${spanText(Math.round((today() - toDate(contract.start_date)) / 864e5) + 1)} ago`, percent: null }
  const total = toDate(contract.end_date) - toDate(contract.start_date)
  const elapsed = today() - toDate(contract.start_date)
  const daysLeft = Math.round((toDate(contract.end_date) - today()) / 864e5)
  return { label: daysLeft <= 0 ? 'Ends today' : `${spanText(daysLeft)} left`, percent: Math.min(100, Math.max(0, Math.round((elapsed / total) * 100))) }
})

/* ---------- Download ---------- */
const downloading = ref(null)
const download = async (contract) => {
  downloading.value = contract.id
  try {
    const response = await downloadContractFile(contract.id)
    const url = URL.createObjectURL(response.data)
    const link = Object.assign(document.createElement('a'), { href: url, download: contract.file_name || `contract-${contract.id}.pdf` })
    document.body.appendChild(link); link.click(); link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (error) {
    // Errors arrive as a Blob because of responseType: 'blob'; read the JSON message out of it.
    let message = ''
    try { message = JSON.parse(await error.response?.data?.text?.()).message } catch { /* not JSON */ }
    toast.value = ''
    loadError.value = ''
    alertMessage.value = error.response && error.response.status < 500 && message ? message : describe(error, 'The file couldn’t be downloaded.')
  } finally {
    downloading.value = null
  }
}
const alertMessage = ref('')

/* ---------- Add / edit (HR) ---------- */
const formDialog = ref(null)
const editing = ref(null)
const form = reactive({ contract_type: '', position: '', department: '', start_date: '', end_date: '', salary: '', currency: 'USD', file: null, remove_file: false })
const errors = ref({})
const formError = ref('')
const saving = ref(false)
const fileInput = ref(null)

const openForm = async (contract = null) => {
  editing.value = contract
  Object.assign(form, contract
    ? { contract_type: contract.contract_type, position: contract.position, department: contract.department || '', start_date: contract.start_date, end_date: contract.end_date || '', salary: contract.salary ?? '', currency: contract.currency, file: null, remove_file: false }
    : { contract_type: '', position: '', department: current.value?.department || '', start_date: '', end_date: '', salary: '', currency: 'USD', file: null, remove_file: false })
  errors.value = {}; formError.value = ''
  await nextTick()
  formDialog.value?.showModal()
}
const closeForm = () => formDialog.value?.close()
const pickFile = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (!/\.pdf$/i.test(file.name)) { errors.value = { ...errors.value, file: 'Upload the signed contract as a PDF.' }; return }
  if (file.size > 10 * 1024 * 1024) { errors.value = { ...errors.value, file: 'The file can be at most 10 MB.' }; return }
  const { file: _, ...rest } = errors.value
  errors.value = rest
  form.file = file
  form.remove_file = false
}
const validate = () => {
  const e = {}
  if (!form.contract_type) e.contract_type = 'Choose the contract type.'
  if (!form.position.trim()) e.position = 'Enter the position.'
  if (!form.start_date) e.start_date = 'Enter the start date.'
  if (form.start_date && form.end_date && form.end_date < form.start_date) e.end_date = 'The end date can’t be before the start date.'
  if (form.salary !== '' && (Number.isNaN(Number(form.salary)) || Number(form.salary) < 0)) e.salary = 'Enter a salary of 0 or more.'
  errors.value = e
  return !Object.keys(e).length
}
const submit = async () => {
  formError.value = ''
  if (!validate()) { await nextTick(); formDialog.value?.querySelector('[aria-invalid="true"]')?.focus(); return }
  const body = new FormData()
  if (!editing.value) body.append('user_id', viewingId.value)
  for (const key of ['contract_type', 'position', 'department', 'start_date', 'end_date', 'salary', 'currency']) body.append(key, String(form[key] ?? '').trim())
  if (form.file) body.append('file', form.file)
  if (form.remove_file) body.append('remove_file', '1')
  saving.value = true
  try {
    await (editing.value ? updateContract(editing.value.id, body) : createContract(body))
    const label = TYPES[form.contract_type]
    closeForm()
    alertMessage.value = ''
    const message = editing.value ? `${label} contract updated.` : `${label} contract added for ${viewingName.value === 'you' ? 'you' : viewingName.value}.`
    await load({ quiet: true }) // confirm only once the list shows what the server saved
    toast.value = message
  } catch (error) {
    const fieldErrors = error.response?.data?.errors || {}
    errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([key, value]) => [key, [value].flat()[0]]))
    formError.value = Object.keys(errors.value).length ? '' : describe(error, 'The contract wasn’t saved. Please try again.')
    await nextTick(); formDialog.value?.querySelector('[aria-invalid="true"]')?.focus()
  } finally {
    saving.value = false
  }
}
// Errors clear as soon as their field changes.
watch(() => ({ ...form }), (now, before) => {
  const changed = Object.keys(now).filter((key) => now[key] !== before[key])
  if (changed.some((key) => errors.value[key])) errors.value = Object.fromEntries(Object.entries(errors.value).filter(([key]) => !changed.includes(key)))
})
const err = (key) => errors.value[key] || ''
const bind = (key) => ({ id: `ch-${key}`, 'aria-invalid': err(key) ? 'true' : undefined, 'aria-describedby': err(key) ? `ch-${key}-error` : undefined })

/* ---------- Delete (HR) ---------- */
const deleteDialog = ref(null)
const deleting = ref(null)
const deleteBusy = ref(false)
const deleteError = ref('')
const askDelete = async (contract) => { deleting.value = contract; deleteError.value = ''; await nextTick(); deleteDialog.value?.showModal() }
const confirmDelete = async () => {
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await deleteContract(deleting.value.id)
    deleteDialog.value?.close()
    alertMessage.value = ''
    const message = `${deleting.value.type_label} contract deleted.`
    await load({ quiet: true })
    toast.value = message
  } catch (error) {
    deleteError.value = describe(error, 'The contract wasn’t deleted. Please try again.')
  } finally {
    deleteBusy.value = false
  }
}
const onBackdrop = (event, dialog) => { if (event.target === dialog) dialog.close() }
</script>

<template>
  <div class="ch">
    <header class="ch-head">
      <div>
        <h1>Contract History</h1>
        <p>{{ canManage ? 'Employment contracts on record. Choose a staff member to view or manage theirs.' : 'Your employment contracts on record, with signed copies to download.' }}</p>
      </div>
      <div v-if="canManage" class="ch-head__actions">
        <label class="ch-staff">
          <span>Staff member</span>
          <select v-model="viewingId" :disabled="loading">
            <option :value="auth.user?.id">Me ({{ auth.user?.name }})</option>
            <option v-for="person in staff.filter((item) => item.id !== auth.user?.id)" :key="person.id" :value="person.id">{{ person.name }}</option>
          </select>
        </label>
        <button type="button" class="ch-btn ch-btn--primary" :disabled="loading || !!loadError" @click="openForm()"><AppIcon name="plus" :size="16" />Add contract</button>
      </div>
    </header>

    <p v-if="toast" class="ch-toast" role="status"><AppIcon name="check-circle" :size="18" /><span>{{ toast }}</span><button type="button" class="ch-icon-btn" aria-label="Dismiss" @click="toast = ''"><AppIcon name="x" :size="16" /></button></p>
    <p v-if="alertMessage" class="ch-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ alertMessage }}</span><button type="button" class="ch-icon-btn" aria-label="Dismiss" @click="alertMessage = ''"><AppIcon name="x" :size="16" /></button></p>

    <div v-if="loading" class="ch-skeleton" aria-busy="true" aria-label="Loading contracts"><span class="ch-skeleton__hero"></span><span v-for="n in 3" :key="n" class="ch-skeleton__row"></span></div>

    <section v-else-if="loadError" class="ch-state ch-state--error" role="alert">
      <AppIcon name="alert-circle" :size="28" />
      <h2>Contracts didn’t load</h2>
      <p>{{ loadError }}</p>
      <button type="button" class="ch-btn ch-btn--primary" @click="load()"><AppIcon name="refresh" :size="16" />Try again</button>
    </section>

    <section v-else-if="!contracts.length" class="ch-state">
      <AppIcon name="file-text" :size="28" />
      <h2>No contracts on record</h2>
      <p v-if="canManage">{{ viewingName === 'you' ? 'You don’t' : `${viewingName} doesn’t` }} have any contracts yet. Add the first one with its signed copy.</p>
      <p v-else>HR adds your contracts here once they’re signed. Contact HR if you think one is missing.</p>
      <button v-if="canManage" type="button" class="ch-btn ch-btn--primary" @click="openForm()"><AppIcon name="plus" :size="16" />Add contract</button>
    </section>

    <template v-else>
      <!-- Current (or next) contract -->
      <section v-if="current" class="ch-current" :aria-label="current.status === 'upcoming' ? 'Next contract' : 'Current contract'">
        <div class="ch-current__main">
          <p class="ch-eyebrow">{{ current.status === 'upcoming' ? 'Next contract' : 'Current contract' }}</p>
          <h2>{{ current.position }}</h2>
          <p class="ch-current__sub">{{ current.type_label }}<template v-if="current.department"> · {{ current.department }}</template></p>
          <span class="ch-badge" :class="`is-${statusOf(current).tone}`"><AppIcon :name="statusOf(current).icon" :size="14" />{{ statusOf(current).label }}</span>
        </div>
        <dl class="ch-facts">
          <div><dt>Period</dt><dd>{{ period(current) }}</dd></div>
          <div><dt>Duration</dt><dd>{{ duration(current) }}</dd></div>
          <div><dt>Salary</dt><dd>{{ money(current) }}</dd></div>
          <div class="ch-facts__progress">
            <dt>{{ current.status === 'upcoming' ? 'Starts' : 'Time remaining' }}</dt>
            <dd>{{ currentProgress.label }}</dd>
            <div v-if="currentProgress.percent !== null" class="ch-track" role="progressbar" :aria-valuenow="currentProgress.percent" aria-valuemin="0" aria-valuemax="100" aria-label="Contract time elapsed"><i :style="{ width: `${currentProgress.percent}%` }"></i></div>
          </div>
        </dl>
        <div class="ch-current__actions">
          <button v-if="current.has_file" type="button" class="ch-btn ch-btn--ghost" :disabled="downloading === current.id" @click="download(current)"><AppIcon :name="downloading === current.id ? 'refresh' : 'download'" :size="16" :class="{ 'ch-spin': downloading === current.id }" />Download signed copy</button>
          <span v-else class="ch-nofile"><AppIcon name="info" :size="15" />No signed copy uploaded yet</span>
        </div>
      </section>
      <p v-if="current?.ending_soon" class="ch-notice"><AppIcon name="clock" :size="18" /><span><strong>This contract ends on {{ formatDate(current.end_date) }}.</strong> {{ canManage ? 'Add the renewal or next contract when it’s signed.' : 'Talk to HR about renewal if you haven’t already.' }}</span></p>

      <section class="ch-card" aria-labelledby="ch-all-title">
        <header class="ch-card__head"><h2 id="ch-all-title">All contracts</h2><span class="ch-count">{{ plural(contracts.length, 'contract') }}</span></header>
        <table class="ch-table">
          <thead><tr><th scope="col">Contract</th><th scope="col">Period</th><th scope="col">Salary</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            <tr v-for="contract in contracts" :key="contract.id" :class="{ 'is-current': contract.id === current?.id }">
              <td data-label="Contract"><div class="ch-cell"><strong>{{ contract.position }}</strong><small>{{ contract.type_label }}<template v-if="contract.department"> · {{ contract.department }}</template></small></div></td>
              <td data-label="Period"><div class="ch-cell"><span>{{ period(contract) }}</span><small>{{ duration(contract) }}</small></div></td>
              <td data-label="Salary"><div class="ch-cell ch-num">{{ money(contract) }}</div></td>
              <td data-label="Status"><div class="ch-cell"><span class="ch-badge" :class="`is-${statusOf(contract).tone}`"><AppIcon :name="statusOf(contract).icon" :size="14" />{{ statusOf(contract).label }}</span></div></td>
              <td class="ch-actions-cell"><div class="ch-actions">
                <button v-if="contract.has_file" type="button" class="ch-btn ch-btn--ghost ch-btn--sm" :disabled="downloading === contract.id" :aria-label="`Download signed ${contract.type_label} contract, ${period(contract)}`" @click="download(contract)"><AppIcon :name="downloading === contract.id ? 'refresh' : 'download'" :size="15" :class="{ 'ch-spin': downloading === contract.id }" />PDF</button>
                <span v-else class="ch-nofile ch-nofile--sm" title="No signed copy uploaded">No file</span>
                <template v-if="canManage">
                  <button type="button" class="ch-icon-btn" :aria-label="`Edit ${contract.type_label} contract, ${period(contract)}`" title="Edit" @click="openForm(contract)"><AppIcon name="pen" :size="16" /></button>
                  <button type="button" class="ch-icon-btn ch-icon-btn--danger" :aria-label="`Delete ${contract.type_label} contract, ${period(contract)}`" title="Delete" @click="askDelete(contract)"><AppIcon name="trash" :size="16" /></button>
                </template>
              </div></td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- Add / edit -->
    <dialog ref="formDialog" class="ch-dialog" aria-labelledby="ch-form-title" @click="onBackdrop($event, formDialog)">
      <form class="ch-dialog__inner" novalidate @submit.prevent="submit">
        <header class="ch-dialog__head">
          <div><h2 id="ch-form-title">{{ editing ? 'Edit contract' : 'Add contract' }}</h2><p>For {{ viewingName === 'you' ? auth.user?.name : viewingName }}</p></div>
          <button type="button" class="ch-icon-btn" aria-label="Close" @click="closeForm"><AppIcon name="x" :size="20" /></button>
        </header>
        <div class="ch-dialog__body">
          <p v-if="formError" class="ch-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ formError }}</span></p>
          <div class="ch-grid">
            <div class="ch-field"><label for="ch-contract_type">Contract type <span class="ch-req" aria-hidden="true">*</span></label>
              <select v-bind="bind('contract_type')" v-model="form.contract_type"><option value="" disabled>Select…</option><option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option></select>
              <p v-if="err('contract_type')" id="ch-contract_type-error" class="ch-error"><AppIcon name="alert-circle" :size="14" />{{ err('contract_type') }}</p></div>
            <div class="ch-field"><label for="ch-position">Position <span class="ch-req" aria-hidden="true">*</span></label>
              <input v-bind="bind('position')" v-model="form.position" maxlength="150" placeholder="e.g. Program Officer" />
              <p v-if="err('position')" id="ch-position-error" class="ch-error"><AppIcon name="alert-circle" :size="14" />{{ err('position') }}</p></div>
            <div class="ch-field ch-field--wide"><label for="ch-department">Department</label>
              <input v-bind="bind('department')" v-model="form.department" maxlength="100" placeholder="e.g. Program" /></div>
            <div class="ch-field"><label for="ch-start_date">Start date <span class="ch-req" aria-hidden="true">*</span></label>
              <input v-bind="bind('start_date')" v-model="form.start_date" type="date" />
              <p v-if="err('start_date')" id="ch-start_date-error" class="ch-error"><AppIcon name="alert-circle" :size="14" />{{ err('start_date') }}</p></div>
            <div class="ch-field"><label for="ch-end_date">End date</label>
              <input v-bind="bind('end_date')" v-model="form.end_date" type="date" :min="form.start_date || undefined" />
              <p v-if="err('end_date')" id="ch-end_date-error" class="ch-error"><AppIcon name="alert-circle" :size="14" />{{ err('end_date') }}</p>
              <p v-else class="ch-hint">Leave empty for an open-ended contract</p></div>
            <div class="ch-field"><label for="ch-salary">Salary</label>
              <input v-bind="bind('salary')" v-model="form.salary" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0.00" />
              <p v-if="err('salary')" id="ch-salary-error" class="ch-error"><AppIcon name="alert-circle" :size="14" />{{ err('salary') }}</p></div>
            <div class="ch-field"><label for="ch-currency">Currency</label>
              <select v-bind="bind('currency')" v-model="form.currency"><option value="USD">USD ($)</option><option value="KHR">KHR (៛)</option></select></div>
            <div class="ch-field ch-field--wide">
              <span class="ch-label" id="ch-file-label">Signed contract (PDF, up to 10 MB)</span>
              <div class="ch-file" :class="{ 'has-error': err('file') }">
                <AppIcon name="file-text" :size="20" />
                <span class="ch-file__name">{{ form.file ? form.file.name : editing?.has_file && !form.remove_file ? editing.file_name : 'No file chosen' }}</span>
                <button v-if="(form.file || editing?.has_file) && !form.remove_file" type="button" class="ch-btn ch-btn--ghost ch-btn--sm" @click="form.file ? (form.file = null) : (form.remove_file = true)">Remove</button>
                <button v-if="form.remove_file" type="button" class="ch-btn ch-btn--ghost ch-btn--sm" @click="form.remove_file = false">Undo</button>
                <button type="button" class="ch-btn ch-btn--ghost ch-btn--sm" aria-describedby="ch-file-label" @click="fileInput?.click()"><AppIcon name="upload" :size="15" />{{ form.file || editing?.has_file ? 'Replace' : 'Choose PDF' }}</button>
                <input ref="fileInput" type="file" accept="application/pdf,.pdf" class="sr-only" tabindex="-1" aria-hidden="true" @change="pickFile" />
              </div>
              <p v-if="err('file')" id="ch-file-error" class="ch-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ err('file') }}</p>
              <p v-else-if="form.remove_file" class="ch-hint">The current file will be deleted when you save.</p>
            </div>
          </div>
        </div>
        <footer class="ch-dialog__foot">
          <button type="button" class="ch-btn ch-btn--ghost" @click="closeForm">Cancel</button>
          <button type="submit" class="ch-btn ch-btn--primary" :disabled="saving" :aria-busy="saving"><AppIcon :name="saving ? 'refresh' : 'save'" :size="16" :class="{ 'ch-spin': saving }" />{{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add contract' }}</button>
        </footer>
      </form>
    </dialog>

    <!-- Delete confirmation -->
    <dialog ref="deleteDialog" class="ch-dialog ch-dialog--sm" aria-labelledby="ch-delete-title" @click="onBackdrop($event, deleteDialog)">
      <div v-if="deleting" class="ch-dialog__inner">
        <div class="ch-dialog__body">
          <h2 id="ch-delete-title">Delete this contract?</h2>
          <p>The <strong>{{ deleting.type_label.toLowerCase() }}</strong> contract for <strong>{{ deleting.position }}</strong> ({{ period(deleting) }}){{ deleting.has_file ? ' and its signed copy' : '' }} will be removed permanently.</p>
          <p v-if="deleteError" class="ch-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ deleteError }}</span></p>
        </div>
        <footer class="ch-dialog__foot">
          <button type="button" class="ch-btn ch-btn--ghost" autofocus @click="deleteDialog?.close()">Cancel</button>
          <button type="button" class="ch-btn ch-btn--danger" :disabled="deleteBusy" @click="confirmDelete"><AppIcon name="trash" :size="16" />{{ deleteBusy ? 'Deleting…' : 'Delete contract' }}</button>
        </footer>
      </div>
    </dialog>
  </div>
</template>

<style>
.ch { --ch-ink: #17365f; --ch-text: #3d5470; --ch-muted: #6b8099; --ch-line: #dfe7ef; --ch-soft: #f6f9fc; --ch-brand: #0b7f62; --ch-brand-dark: #086a51; --ch-brand-soft: #e8f5f0; --ch-success: #14804a; --ch-success-soft: #e7f6ee; --ch-warning: #a35f00; --ch-warning-soft: #fff4dc; --ch-info: #2b5ea8; --ch-info-soft: #e9f1fd; --ch-danger: #c0392b; --ch-danger-soft: #fdf1f0; width: min(100%, 1180px); margin: 0 auto; padding: 24px 0 48px; color: var(--ch-text); font-size: 14px; line-height: 1.5; }
.ch button { margin: 0; } /* older global styles give every button a top margin and large padding */
.ch h1, .ch h2, .ch h3 { color: var(--ch-ink); letter-spacing: -.01em; }
.ch-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.ch-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.ch-head p { margin: 0; color: var(--ch-muted); }
.ch-head__actions { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; }
.ch-staff { display: grid; gap: 4px; color: var(--ch-muted); font-size: .8rem; font-weight: 600; }
.ch-staff select { min-width: 220px; }

.ch input:not([type="file"]), .ch select { box-sizing: border-box; width: 100%; height: 42px; padding: 0 13px; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--ch-ink); font: inherit; font-size: .93rem; transition: border-color .15s, box-shadow .15s; }
.ch input:focus, .ch select:focus { border-color: var(--ch-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.ch [aria-invalid="true"] { border-color: var(--ch-danger) !important; }

.ch-toast, .ch-alert, .ch-notice { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 10px 10px 10px 14px; border: 1px solid; border-radius: 10px; font-size: .9rem; }
.ch-toast { border-color: #bfe3d6; background: var(--ch-brand-soft); color: var(--ch-brand-dark); font-weight: 600; }
.ch-alert { border-color: #f2c4bf; background: var(--ch-danger-soft); color: #9b2c22; font-weight: 600; }
.ch-notice { border-color: #f1dfae; background: #fffaeb; color: #7a5a12; }
.ch-toast span, .ch-alert span, .ch-notice span { flex: 1; }
.ch-toast svg, .ch-alert svg, .ch-notice svg { flex: none; }

/* Current contract */
.ch-current { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(0, 2fr) auto; align-items: center; gap: 24px; margin-bottom: 16px; padding: 22px 24px; border: 1px solid var(--ch-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.ch-eyebrow { margin: 0 0 4px; color: var(--ch-brand); font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.ch-current h2 { margin: 0; font-size: 1.3rem; font-weight: 700; }
.ch-current__sub { margin: 2px 0 10px; color: var(--ch-muted); }
.ch-facts { display: grid; grid-template-columns: repeat(3, max-content) minmax(140px, 1fr); gap: 16px 32px; margin: 0; padding: 0 0 0 24px; border-left: 1px solid var(--ch-line); }
.ch-facts dt { color: var(--ch-muted); font-size: .8rem; font-weight: 600; }
.ch-facts dd { margin: 2px 0 0; color: var(--ch-ink); font-weight: 600; }
.ch-track { height: 6px; margin-top: 8px; overflow: hidden; border-radius: 99px; background: #e6edf3; }
.ch-track i { display: block; height: 100%; border-radius: inherit; background: var(--ch-brand); }
.ch-nofile { display: inline-flex; align-items: center; gap: 6px; color: var(--ch-muted); font-size: .86rem; }
.ch-nofile--sm { padding: 0 6px; font-size: .82rem; }

/* All contracts */
.ch-card { overflow: hidden; border: 1px solid var(--ch-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.ch-card__head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--ch-line); }
.ch-card__head h2 { margin: 0; font-size: 1.05rem; font-weight: 700; }
.ch-count { color: var(--ch-muted); font-size: .85rem; }
.ch-table { width: 100%; border-collapse: collapse; text-align: left; }
.ch-table th { padding: 10px 20px; background: var(--ch-soft); color: var(--ch-muted); font-size: .76rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
.ch-table td { padding: 14px 20px; border-top: 1px solid var(--ch-line); color: var(--ch-ink); font-size: .92rem; vertical-align: middle; white-space: normal; }
.ch-cell > strong, .ch-cell > span:not(.ch-badge) { display: block; font-size: .92rem; font-weight: 600; }
.ch-cell small { display: block; color: var(--ch-muted); font-size: .82rem; }
.ch-actions-cell { width: 1%; white-space: nowrap; }
.ch-table tr.is-current td { background: #fbfdfc; }
.ch-table tr.is-current td:first-child { box-shadow: inset 3px 0 0 var(--ch-brand); }
.ch-num { font-size: .92rem; font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }
.ch-actions { display: flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap; }

.ch-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.ch-badge.is-success { background: var(--ch-success-soft); color: var(--ch-success); }
.ch-badge.is-warning { background: var(--ch-warning-soft); color: var(--ch-warning); }
.ch-badge.is-info { background: var(--ch-info-soft); color: var(--ch-info); }
.ch-badge.is-muted { background: #eef2f6; color: #4f6278; }

/* Buttons */
.ch-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 18px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .92rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
.ch-btn svg { flex: none; }
.ch-btn--sm { min-height: 36px; padding: 0 12px; font-size: .86rem; }
.ch-btn--primary { background: var(--ch-brand); color: #fff; }
.ch-btn--primary:hover:not(:disabled) { background: var(--ch-brand-dark); }
.ch-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--ch-ink); }
.ch-btn--ghost:hover:not(:disabled) { border-color: #aebfd1; background: var(--ch-soft); }
.ch-btn--danger { background: var(--ch-danger); color: #fff; }
.ch-btn--danger:hover:not(:disabled) { background: #a33024; }
.ch-btn:disabled { opacity: .6; cursor: not-allowed; }
.ch-icon-btn { display: grid; width: 36px; height: 36px; flex: none; place-items: center; padding: 0; border: 0; border-radius: 9px; background: transparent; color: var(--ch-muted); cursor: pointer; }
.ch-icon-btn:hover { background: rgba(23, 54, 95, .07); color: var(--ch-ink); transform: none !important; }
.ch-icon-btn--danger:hover { background: var(--ch-danger-soft); color: var(--ch-danger); }
.ch-spin { animation: ch-spin 1s linear infinite; }
@keyframes ch-spin { to { transform: rotate(360deg); } }

/* States */
.ch-state { display: grid; justify-items: center; gap: 6px; padding: 44px 24px; border: 1px dashed #d6e0ea; border-radius: 14px; background: #fff; color: var(--ch-muted); text-align: center; }
.ch-state h2 { margin: 6px 0 0; font-size: 1.1rem; }
.ch-state p { max-width: 460px; margin: 0 0 8px; }
.ch-state--error { border-style: solid; border-color: #f2c4bf; color: var(--ch-danger); }
.ch-skeleton { display: grid; gap: 12px; }
.ch-skeleton span { display: block; height: 56px; border-radius: 12px; background: linear-gradient(90deg, #eef2f6 25%, #f6f9fc 50%, #eef2f6 75%); background-size: 200% 100%; animation: ch-shimmer 1.4s ease infinite; }
.ch-skeleton .ch-skeleton__hero { height: 120px; border-radius: 14px; }
@keyframes ch-shimmer { to { background-position: -200% 0; } }

/* Dialogs */
.ch-dialog { width: min(100% - 32px, 620px); max-height: min(100% - 32px, 760px); margin: auto; padding: 0; border: 0; border-radius: 16px; color: var(--ch-text); box-shadow: 0 24px 70px rgba(16, 36, 61, .3); }
.ch-dialog--sm { width: min(100% - 32px, 440px); }
.ch-dialog[open] { display: flex; flex-direction: column; }
.ch-dialog::backdrop { background: rgba(17, 36, 59, .5); }
.ch-dialog__inner { display: flex; min-height: 0; flex: 1 1 auto; flex-direction: column; }
.ch-dialog__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px 22px 14px; border-bottom: 1px solid var(--ch-line); }
.ch-dialog__head h2, .ch-dialog__body h2 { margin: 0; font-size: 1.25rem; font-weight: 700; }
.ch-dialog__head p { margin: 2px 0 0; color: var(--ch-muted); }
.ch-dialog__body { min-height: 0; flex: 1 1 auto; padding: 18px 22px; overflow-y: auto; }
.ch-dialog__body > p { margin: 10px 0 0; }
.ch-dialog__body > .ch-alert { margin: 0 0 14px; }
.ch-dialog__foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--ch-line); background: var(--ch-soft); }
.ch-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 18px; }
.ch-field { display: grid; align-content: start; gap: 6px; min-width: 0; }
.ch-field--wide { grid-column: 1 / -1; }
.ch-field > label, .ch-label { display: block; color: var(--ch-ink); font-size: .88rem; font-weight: 600; }
.ch-req { color: var(--ch-danger); }
.ch-hint { margin: 0; color: var(--ch-muted); font-size: .8rem; }
.ch-error { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--ch-danger); font-size: .8rem; font-weight: 500; }
.ch-file { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 12px; border: 1px dashed #c3d1df; border-radius: 10px; background: var(--ch-soft); color: var(--ch-muted); }
.ch-file.has-error { border-color: var(--ch-danger); }
.ch-file__name { min-width: 0; flex: 1; overflow: hidden; color: var(--ch-ink); font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }

@media (max-width: 1100px) {
  .ch-current { grid-template-columns: 1fr; gap: 16px; }
  .ch-facts { grid-template-columns: repeat(4, minmax(0, 1fr)); padding: 16px 0 0; border-top: 1px solid var(--ch-line); border-left: 0; }
}
/* Phones: each table row becomes a card with labelled values. */
@media (max-width: 760px) {
  .ch { padding-top: 16px; }
  .ch-head h1 { font-size: 1.35rem; }
  .ch-head__actions, .ch-staff { width: 100%; }
  .ch-head__actions .ch-btn { flex: 1; }
  .ch-staff select { min-width: 0; }
  .ch-current { padding: 18px; }
  .ch-facts { grid-template-columns: 1fr 1fr; }
  .ch-current__actions .ch-btn { width: 100%; }
  .ch-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  .ch-table, .ch-table tbody, .ch-table tr, .ch-table td { display: block; }
  .ch-table tr { padding: 14px 16px; border-top: 1px solid var(--ch-line); }
  .ch-table tr.is-current { box-shadow: inset 3px 0 0 var(--ch-brand); }
  .ch-table tr.is-current td:first-child { box-shadow: none; }
  .ch-table td { display: grid; grid-template-columns: 5.5rem minmax(0, 1fr); align-items: start; gap: 12px; width: auto; padding: 5px 0; border: 0; background: transparent !important; }
  .ch-table td::before { padding-top: 1px; color: var(--ch-muted); font-size: .8rem; font-weight: 600; content: attr(data-label); }
  .ch-table td[data-label="Contract"] { display: block; padding-bottom: 8px; }
  .ch-table td[data-label="Contract"]::before, .ch-table td.ch-actions-cell::before { content: none; }
  .ch-table td.ch-actions-cell { display: block; padding-top: 8px; }
  .ch-cell { min-width: 0; }
  .ch-actions { justify-content: flex-end; }
  .ch-grid { grid-template-columns: 1fr; }
  .ch-dialog { width: 100%; max-width: none; max-height: 92%; margin: auto 0 0; border-radius: 16px 16px 0 0; }
  .ch-dialog--sm { margin: auto 16px; width: auto; border-radius: 16px; }
  .ch-dialog__head, .ch-dialog__body, .ch-dialog__foot { padding-inline: 16px; }
  .ch-dialog__foot .ch-btn { flex: 1; }
}
@media (prefers-reduced-motion: reduce) { .ch-skeleton span, .ch-spin { animation: none; } }
</style>
