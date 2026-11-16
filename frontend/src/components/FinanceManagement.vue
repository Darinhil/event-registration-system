<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import organizationLogo from '../assets/LLEE_Cambodia_Inverse_L.png'
import { createFinanceBudget, createFinanceVoucher, getFinanceBudgets, getFinanceVoucher, getFinanceVouchers, transitionFinanceBudget, updateFinanceBudget, updateFinanceVoucher } from '../services/financeVoucherService'

const today = new Date()
const dateStamp = `${today.getFullYear()}${String(today.getMonth() + 1).padStart(2, '0')}${String(today.getDate()).padStart(2, '0')}`
const voucher = reactive({
  number: `GV-${dateStamp}-001`,
  date: `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`,
  advanceNumber: '',
  name: '',
  position: '',
  purpose: '',
  signatureDate: '',
  voucherType: '',
  paymentMode: '',
  chequeNumber: '',
  remarks: '',
  department: 'HR and Operations',
  projectName: 'Project A',
  fiscalYear: today.getFullYear(),
  workflowStatus: 'draft',
})
const voucherTypes = ['Cash Advance', 'Liquidation', 'Reimbursement', 'Third Party Payment', 'Journal', 'Receipt']
const paymentModes = ['Cash', 'Non-Cash', 'Bank Transfer', 'Bank Cheque']
const approvalRoles = [
  { key: 'preparer', label: 'PREPARER', badge: 'Prepare' },
  { key: 'checker', label: 'CHECKER', badge: 'Check' },
  { key: 'verifier', label: 'VERIFIER', badge: 'Verify' },
  { key: 'approver', label: 'APPROVER', badge: 'Approve' },
]
const approvals = reactive(Object.fromEntries(approvalRoles.map(({ key }) => [key, { name: '', position: '', date: '', signature: '' }])))
const signatureCanvases = new Map()
const activeSignatureKey = ref(null)
const savedVouchers = ref([])
const currentVoucherId = ref(null)
const selectedVoucherId = ref('')
const savingVoucher = ref(false)
const voucherMessage = ref('')
const activeFinanceView = ref('budgets')
const financeViews = [
  { id: 'budgets', label: 'Project Budgets' },
  { id: 'reports', label: 'Financial Reports' },
  { id: 'vouchers', label: 'Finance Vouchers' },
]
const departments = ['HR and Operations', 'Activities']
const projectOptions = Array.from({ length: 14 }, (_, index) => `Project ${String.fromCharCode(65 + index)}`)
const budgetStages = ['draft', 'submitted', 'checked', 'reviewed', 'approved']
const budgetStageLabels = { draft: 'Draft', submitted: 'Submitted', checked: 'Checked', reviewed: 'Reviewed', approved: 'Approved', returned: 'Returned' }
const budgets = ref([])
const budgetMessage = ref('')
const savingBudget = ref(false)
const currentBudgetId = ref(null)
const selectedBudgetId = ref('')
const budgetForm = reactive({
  budgetNumber: `PB-${dateStamp}-${String(Date.now()).slice(-3)}`,
  department: 'HR and Operations',
  projectName: 'Project A',
  fiscalYear: today.getFullYear(),
  currency: 'USD',
  purpose: '',
})
const budgetLines = reactive([{ description: '', budgetCode: '', amount: '' }])
const budgetTotal = computed(() => budgetLines.reduce((sum, line) => sum + numericValue(line.amount), 0))
const budgetCanEdit = computed(() => !currentBudgetId.value || ['draft', 'returned'].includes(selectedBudget.value?.workflow_status))
const selectedBudget = computed(() => budgets.value.find((budget) => String(budget.id) === String(currentBudgetId.value)))
const reportRows = computed(() => {
  const groups = new Map()
  const addProject = (department, projectName, fiscalYear = today.getFullYear()) => {
    const key = `${department}|${projectName}|${fiscalYear}`
    if (!groups.has(key)) groups.set(key, { id: key, department, project_name: projectName, fiscal_year: fiscalYear, currency: 'USD', totalBudget: 0, hasApprovedBudget: false })
    return groups.get(key)
  }
  departments.forEach((department) => projectOptions.forEach((projectName) => addProject(department, projectName)))
  budgets.value.filter((budget) => budget.workflow_status === 'approved').forEach((budget) => {
    const details = budget.payload || {}
    const fiscalYear = details.fiscalYear || budget.fiscal_year || today.getFullYear()
    const group = addProject(budget.department, budget.project_name, fiscalYear)
    group.currency = details.currency || 'USD'
    group.hasApprovedBudget = true
    group.totalBudget += (details.lines || []).reduce((sum, line) => sum + numericValue(line.amount), 0)
  })
  savedVouchers.value.filter((saved) => saved.payload?.workflowStatus === 'approved' && saved.payload?.department && saved.payload?.projectName).forEach((saved) => {
    addProject(saved.payload.department, saved.payload.projectName, saved.payload.fiscalYear || today.getFullYear())
  })
  return [...groups.values()].map((group) => {
    const projectBudgets = budgets.value.filter((budget) => budget.department === group.department && budget.project_name === group.project_name && Number(budget.payload?.fiscalYear || budget.fiscal_year || today.getFullYear()) === Number(group.fiscal_year))
    const latestBudget = projectBudgets[0]
    const expenses = savedVouchers.value
      .filter((saved) => saved.payload?.workflowStatus === 'approved' && saved.payload?.department === group.department && saved.payload?.projectName === group.project_name && Number(saved.payload?.fiscalYear || today.getFullYear()) === Number(group.fiscal_year))
      .reduce((sum, saved) => sum + (saved.payload?.lines || []).reduce((lineSum, line) => lineSum + numericValue(line.amount), 0), 0)
    return { ...group, expenses, balance: group.totalBudget - expenses, budgetStatus: group.hasApprovedBudget ? 'approved' : latestBudget?.workflow_status || 'no-budget' }
  })
})
const reportDepartments = computed(() => departments.map((department) => ({
  name: department,
  rows: reportRows.value.filter((row) => row.department === department).sort((a, b) => a.project_name.localeCompare(b.project_name) || a.fiscal_year - b.fiscal_year),
})))
const reportSummary = computed(() => reportRows.value.reduce((summary, row) => ({
  approvedBudget: summary.approvedBudget + row.totalBudget,
  expenses: summary.expenses + row.expenses,
  projects: summary.projects + 1,
}), { approvedBudget: 0, expenses: 0, projects: 0 }))
let nextBudgetLineId = 1
const createBudgetLine = () => ({ id: nextBudgetLineId++, description: '', budgetCode: '', amount: '' })
const addBudgetLine = () => budgetLines.push(createBudgetLine())
const removeBudgetLine = (id) => {
  const index = budgetLines.findIndex((line) => line.id === id)
  if (budgetLines.length > 1 && index !== -1) budgetLines.splice(index, 1)
}
let nextLineId = 1
const createLine = () => ({
  id: nextLineId++,
  description: '',
  fundingSource: '',
  budgetCode: '',
  amount: '',
  liquidatedAmount: '',
  accountCode: '',
  debit: '',
  credit: '',
})
const lines = reactive(Array.from({ length: 5 }, createLine))
const numericValue = (value) => {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : 0
}
const totalAmount = computed(() => lines.reduce((total, line) => total + numericValue(line.amount), 0))
const totalLiquidated = computed(() => lines.reduce((total, line) => total + numericValue(line.liquidatedAmount), 0))
const totalBalance = computed(() => lines.reduce((total, line) => total + numericValue(line.amount) - numericValue(line.liquidatedAmount), 0))
const totalDebit = computed(() => lines.reduce((total, line) => total + numericValue(line.debit), 0))
const totalCredit = computed(() => lines.reduce((total, line) => total + numericValue(line.credit), 0))
const formatAmount = (amount) => amount === 0 ? '-' : amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatCurrency = (amount) => numericValue(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const addLine = () => lines.push(createLine())
const printVoucher = () => window.print()
const removeLine = (lineId) => {
  const index = lines.findIndex(({ id }) => id === lineId)
  if (lines.length > 1 && index !== -1) lines.splice(index, 1)
}
const paintSignature = (canvas, signature) => {
  const bounds = canvas.getBoundingClientRect()
  if (!bounds.width || !bounds.height) return
  canvas.width = Math.round(bounds.width * 2)
  canvas.height = Math.round(bounds.height * 2)
  const context = canvas.getContext('2d')
  context.clearRect(0, 0, canvas.width, canvas.height)
  if (!signature) return
  const image = new Image()
  image.onload = () => context.drawImage(image, 0, 0, canvas.width, canvas.height)
  image.src = signature
}
const setSignatureCanvas = (canvas, key) => {
  if (!canvas) {
    signatureCanvases.delete(key)
    return
  }
  signatureCanvases.set(key, canvas)
  paintSignature(canvas, approvals[key].signature)
}
const signaturePoint = (event, canvas) => {
  const bounds = canvas.getBoundingClientRect()
  return { x: (event.clientX - bounds.left) * canvas.width / bounds.width, y: (event.clientY - bounds.top) * canvas.height / bounds.height }
}
const startSignature = (event, key) => {
  const canvas = event.currentTarget
  const context = canvas.getContext('2d')
  const point = signaturePoint(event, canvas)
  activeSignatureKey.value = key
  canvas.setPointerCapture(event.pointerId)
  context.beginPath()
  context.moveTo(point.x, point.y)
  context.lineWidth = 3
  context.lineCap = 'round'
  context.lineJoin = 'round'
  context.strokeStyle = '#183c56'
}
const drawSignature = (event, key) => {
  if (activeSignatureKey.value !== key) return
  const canvas = event.currentTarget
  const point = signaturePoint(event, canvas)
  const context = canvas.getContext('2d')
  context.lineTo(point.x, point.y)
  context.stroke()
}
const finishSignature = (event, key) => {
  if (activeSignatureKey.value !== key) return
  const canvas = event.currentTarget
  activeSignatureKey.value = null
  approvals[key].signature = canvas.toDataURL('image/png')
}
const clearSignature = (key) => {
  approvals[key].signature = ''
  const canvas = signatureCanvases.get(key)
  if (canvas) canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height)
}
const refreshSavedVouchers = async (quiet = false) => {
  try {
    const { data } = await getFinanceVouchers()
    savedVouchers.value = data.data || []
  } catch {
    if (!quiet) voucherMessage.value = 'Unable to load saved vouchers.'
  }
}
const refreshBudgets = async (quiet = false) => {
  try {
    const { data } = await getFinanceBudgets()
    budgets.value = data.data || []
  } catch {
    if (!quiet) budgetMessage.value = 'Unable to load project budgets.'
  }
}
const startNewBudget = () => {
  currentBudgetId.value = null
  selectedBudgetId.value = ''
  Object.assign(budgetForm, {
    budgetNumber: `PB-${dateStamp}-${String(Date.now()).slice(-3)}`,
    department: 'HR and Operations', projectName: 'Project A', fiscalYear: today.getFullYear(), currency: 'USD', purpose: '',
  })
  budgetLines.splice(0, budgetLines.length, createBudgetLine())
  budgetMessage.value = ''
}
const loadBudget = (id) => {
  const record = budgets.value.find((budget) => String(budget.id) === String(id))
  if (!record) return
  const payload = record.payload || {}
  currentBudgetId.value = record.id
  selectedBudgetId.value = String(record.id)
  Object.assign(budgetForm, {
    budgetNumber: payload.budgetNumber || record.budget_number,
    department: payload.department || record.department,
    projectName: payload.projectName || record.project_name,
    fiscalYear: payload.fiscalYear || record.fiscal_year || today.getFullYear(),
    currency: payload.currency || 'USD',
    purpose: payload.purpose || '',
  })
  budgetLines.splice(0, budgetLines.length, ...(payload.lines || []).map((line) => ({ ...line, id: nextBudgetLineId++ })))
  if (!budgetLines.length) budgetLines.push(createBudgetLine())
  budgetMessage.value = `Loaded ${record.budget_number}.`
}
const saveBudget = async (action = 'save') => {
  if (action === 'submit' && budgetLines.some((line) => !line.description.trim() || line.amount === '')) {
    budgetMessage.value = 'Add a description and amount to every budget line before submitting.'
    return
  }
  savingBudget.value = true
  budgetMessage.value = ''
  const payload = {
    ...budgetForm,
    lines: budgetLines.map(({ description, budgetCode, amount }) => ({ description, budgetCode, amount })),
    action,
  }
  try {
    const response = currentBudgetId.value
      ? await updateFinanceBudget(currentBudgetId.value, payload)
      : await createFinanceBudget(payload)
    const record = response.data.data
    currentBudgetId.value = record.id
    selectedBudgetId.value = String(record.id)
    budgetMessage.value = action === 'submit' ? 'Budget submitted for checking.' : 'Budget saved.'
    await refreshBudgets(true)
  } catch (error) {
    budgetMessage.value = error.response?.data?.message || 'Could not save this budget. Check the required fields and try again.'
  } finally {
    savingBudget.value = false
  }
}
const advanceBudget = async (action) => {
  if (!currentBudgetId.value) return
  budgetMessage.value = ''
  try {
    const { data } = await transitionFinanceBudget(currentBudgetId.value, action)
    const index = budgets.value.findIndex((budget) => budget.id === data.data.id)
    if (index !== -1) budgets.value[index] = data.data
    budgetMessage.value = action === 'return' ? 'Budget returned for changes.' : `Budget ${budgetStageLabels[data.data.workflow_status].toLowerCase()}.`
  } catch (error) {
    budgetMessage.value = error.response?.data?.message || 'That workflow action is not available.'
  }
}
const saveVoucher = async () => {
  savingVoucher.value = true
  voucherMessage.value = ''
  const payload = {
    ...voucher,
    lines: lines.map((line) => ({ ...line })),
    approvals: Object.fromEntries(approvalRoles.map(({ key }) => [key, { ...approvals[key] }])),
  }
  try {
    const response = currentVoucherId.value
      ? await updateFinanceVoucher(currentVoucherId.value, payload)
      : await createFinanceVoucher(payload)
    currentVoucherId.value = response.data.data.id
    selectedVoucherId.value = String(currentVoucherId.value)
    voucherMessage.value = 'Voucher saved.'
    await refreshSavedVouchers(true)
    return true
  } catch (error) {
    voucherMessage.value = error.response?.data?.message || 'Could not save voucher. Check your connection and try again.'
    return false
  } finally {
    savingVoucher.value = false
  }
}
const loadVoucher = async (id) => {
  if (!id) return
  voucherMessage.value = ''
  try {
    const { data } = await getFinanceVoucher(id)
    const record = data.data
    const payload = record.payload
    for (const key of ['number', 'date', 'advanceNumber', 'name', 'position', 'purpose', 'signatureDate', 'voucherType', 'paymentMode', 'chequeNumber', 'remarks', 'department', 'projectName', 'fiscalYear', 'workflowStatus']) {
      voucher[key] = payload[key] ?? ''
    }
    if (!voucher.department) voucher.department = 'HR and Operations'
    if (!voucher.projectName) voucher.projectName = 'Project A'
    if (!voucher.fiscalYear) voucher.fiscalYear = today.getFullYear()
    if (!voucher.workflowStatus) voucher.workflowStatus = 'draft'
    currentVoucherId.value = record.id
    lines.splice(0, lines.length, ...(payload.lines || []).map((line) => ({ ...line, id: nextLineId++ })))
    if (!lines.length) lines.push(createLine())
    for (const { key } of approvalRoles) Object.assign(approvals[key], payload.approvals?.[key] || { name: '', position: '', date: '', signature: '' })
    await nextTick()
    for (const { key } of approvalRoles) {
      const canvas = signatureCanvases.get(key)
      if (canvas) paintSignature(canvas, approvals[key].signature)
    }
    voucherMessage.value = `Loaded ${record.voucher_number}.`
  } catch (error) {
    voucherMessage.value = error.response?.data?.message || 'Could not load this voucher.'
  }
}
const loadSelectedVoucher = () => loadVoucher(selectedVoucherId.value)
const startNewVoucher = () => {
  currentVoucherId.value = null
  selectedVoucherId.value = ''
  Object.assign(voucher, {
    number: `GV-${dateStamp}-${String(Date.now()).slice(-3)}`,
    date: `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`,
    advanceNumber: '', name: '', position: '', purpose: '', signatureDate: '', voucherType: '', paymentMode: '', chequeNumber: '', remarks: '',
    department: 'HR and Operations', projectName: 'Project A', fiscalYear: today.getFullYear(), workflowStatus: 'draft',
  })
  lines.splice(0, lines.length, ...Array.from({ length: 5 }, createLine))
  for (const { key } of approvalRoles) {
    Object.assign(approvals[key], { name: '', position: '', date: '', signature: '' })
    clearSignature(key)
  }
  voucherMessage.value = ''
}
const voucherStages = ['draft', 'prepared', 'checked', 'verified', 'approved']
const voucherStageLabels = { draft: 'Draft', prepared: 'Prepared', checked: 'Checked', verified: 'Verified', approved: 'Approved' }
const advanceVoucher = async () => {
  const currentIndex = voucherStages.indexOf(voucher.workflowStatus)
  if (currentIndex < 0 || currentIndex >= voucherStages.length - 1) return
  const previous = voucher.workflowStatus
  voucher.workflowStatus = voucherStages[currentIndex + 1]
  const saved = await saveVoucher()
  if (!saved) voucher.workflowStatus = previous
}
onMounted(() => { refreshSavedVouchers(); refreshBudgets() })
</script>

<template>
  <section class="finance-page">
    <header class="finance-workspace-heading">
      <div><span class="finance-kicker">FINANCE WORKSPACE</span><h1>Finance Management</h1><p>Plan project budgets, track spending, and process finance vouchers.</p></div>
    </header>
    <nav class="finance-view-nav" aria-label="Finance sections">
      <button v-for="view in financeViews" :key="view.id" type="button" :class="{ 'is-active': activeFinanceView === view.id }" :aria-current="activeFinanceView === view.id ? 'page' : undefined" @click="activeFinanceView = view.id">{{ view.label }}</button>
    </nav>

    <template v-if="activeFinanceView === 'budgets'">
      <div class="finance-budget-layout">
        <aside class="finance-budget-list finance-card">
          <div class="finance-card-heading"><div><h2>Project budgets</h2><p>Drafts and items in the approval process</p></div><button type="button" class="finance-new-button" @click="startNewBudget">New budget</button></div>
          <label class="finance-budget-open">Open a saved budget
            <select v-model="selectedBudgetId" @change="loadBudget(selectedBudgetId)"><option value="">Select a budget</option><option v-for="budget in budgets" :key="budget.id" :value="String(budget.id)">{{ budget.budget_number }} · {{ budget.project_name }}</option></select>
          </label>
          <div v-if="budgets.length" class="finance-budget-records">
            <button v-for="budget in budgets" :key="budget.id" type="button" class="finance-budget-record" :class="{ 'is-selected': String(currentBudgetId) === String(budget.id) }" @click="loadBudget(budget.id)">
              <span><strong>{{ budget.project_name }}</strong><small>{{ budget.department }} · {{ budget.budget_number }}</small></span><span class="finance-status" :class="`status-${budget.workflow_status}`">{{ budgetStageLabels[budget.workflow_status] || budget.workflow_status }}</span>
            </button>
          </div>
          <p v-else class="finance-empty">No budgets saved yet. Start a project budget to begin.</p>
        </aside>

        <form class="finance-budget-form finance-card" @submit.prevent="saveBudget('save')">
          <header class="finance-card-heading"><div><span class="finance-card-kicker">PROJECT BUDGET</span><h2>{{ currentBudgetId ? budgetForm.budgetNumber : 'Create a project budget' }}</h2><p>Create, submit, and track a budget through review and approval.</p></div><span v-if="selectedBudget" class="finance-status" :class="`status-${selectedBudget.workflow_status}`">{{ budgetStageLabels[selectedBudget.workflow_status] || selectedBudget.workflow_status }}</span></header>
          <div class="finance-budget-fields">
            <label>Budget number<input v-model.trim="budgetForm.budgetNumber" required :disabled="!budgetCanEdit" /></label>
            <label>Department / structure<select v-model="budgetForm.department" :disabled="!budgetCanEdit"><option v-for="department in departments" :key="department">{{ department }}</option></select></label>
            <label>Project<select v-model="budgetForm.projectName" :disabled="!budgetCanEdit"><option v-for="project in projectOptions" :key="project">{{ project }}</option></select></label>
            <label>Fiscal year<input v-model.number="budgetForm.fiscalYear" type="number" min="2000" max="2200" :disabled="!budgetCanEdit" /></label>
            <label class="is-wide">Purpose / notes<textarea v-model.trim="budgetForm.purpose" rows="2" placeholder="What will this budget support?" :disabled="!budgetCanEdit"></textarea></label>
          </div>
          <div class="finance-budget-lines-heading"><div><h3>Budget lines</h3><p>Enter the planned cost for each activity or expense.</p></div><button v-if="budgetCanEdit" type="button" class="finance-line-add" @click="addBudgetLine">+ Add line</button></div>
          <div class="finance-budget-line-list">
            <div v-for="(line, index) in budgetLines" :key="line.id" class="finance-budget-line">
              <span class="finance-budget-line-number">{{ index + 1 }}</span>
              <label>Description<input v-model.trim="line.description" placeholder="Expense or activity" :disabled="!budgetCanEdit" /></label>
              <label>Budget code<input v-model.trim="line.budgetCode" placeholder="Optional" :disabled="!budgetCanEdit" /></label>
              <label>Amount (USD)<input v-model="line.amount" type="number" min="0" step="0.01" placeholder="0.00" :disabled="!budgetCanEdit" /></label>
              <button v-if="budgetCanEdit && budgetLines.length > 1" type="button" class="finance-line-remove" :aria-label="`Remove budget line ${index + 1}`" @click="removeBudgetLine(line.id)">×</button>
            </div>
          </div>
          <div class="finance-budget-total"><span>Total requested</span><strong>USD {{ formatCurrency(budgetTotal) }}</strong></div>
          <div v-if="currentBudgetId" class="finance-budget-workflow" aria-label="Budget approval progress">
            <div v-for="(stage, index) in budgetStages.slice(1)" :key="stage" :class="{ 'is-complete': budgetStages.indexOf(selectedBudget?.workflow_status) >= index + 1 }"><i>{{ index + 1 }}</i><span>{{ budgetStageLabels[stage] }}</span></div>
          </div>
          <p v-if="budgetMessage" class="finance-feedback" role="status">{{ budgetMessage }}</p>
          <footer class="finance-budget-actions">
            <template v-if="budgetCanEdit"><button type="submit" class="finance-action-secondary" :disabled="savingBudget">{{ savingBudget ? 'Saving…' : 'Save draft' }}</button><button type="button" class="finance-action-primary" :disabled="savingBudget" @click="saveBudget('submit')">Submit for checking <span>→</span></button></template>
            <template v-else-if="selectedBudget?.workflow_status === 'submitted'"><button type="button" class="finance-action-secondary" @click="advanceBudget('return')">Return for changes</button><button type="button" class="finance-action-primary" @click="advanceBudget('check')">Mark checked <span>→</span></button></template>
            <template v-else-if="selectedBudget?.workflow_status === 'checked'"><button type="button" class="finance-action-secondary" @click="advanceBudget('return')">Return for changes</button><button type="button" class="finance-action-primary" @click="advanceBudget('review')">Complete review <span>→</span></button></template>
            <template v-else-if="selectedBudget?.workflow_status === 'reviewed'"><button type="button" class="finance-action-secondary" @click="advanceBudget('return')">Return for changes</button><button type="button" class="finance-action-primary" @click="advanceBudget('approve')">Approve budget <span>→</span></button></template>
            <span v-else-if="selectedBudget?.workflow_status === 'approved'" class="finance-approved-note">Budget approved</span>
          </footer>
        </form>
      </div>
    </template>

    <template v-else-if="activeFinanceView === 'reports'">
      <section class="finance-card finance-report-card">
        <header class="finance-card-heading"><div><span class="finance-card-kicker">FINANCIAL REPORTS</span><h2>Budgets vs. expenses</h2><p>Track all projects. Approved budgets and vouchers update the totals as they move through review.</p></div></header>
        <div class="finance-report-summary">
          <article><span>Projects tracked</span><strong>{{ reportSummary.projects }}</strong></article>
          <article><span>Approved budgets</span><strong>USD {{ formatCurrency(reportSummary.approvedBudget) }}</strong></article>
          <article><span>Approved expenses</span><strong>USD {{ formatCurrency(reportSummary.expenses) }}</strong></article>
        </div>
        <section v-for="group in reportDepartments" :key="group.name" class="finance-report-group" :aria-label="`${group.name} project budget report`">
          <header><h3>{{ group.name }}</h3><span>{{ group.rows.length }} projects</span></header>
          <div class="finance-report-table-wrap"><table class="finance-report-table"><thead><tr><th>Project</th><th>Fiscal year</th><th>Approved budget</th><th>Expenses</th><th>Remaining</th><th>Status</th></tr></thead><tbody><tr v-for="row in group.rows" :key="row.id"><td>{{ row.project_name }}</td><td>{{ row.fiscal_year }}</td><td>{{ row.hasApprovedBudget ? `${row.currency} ${formatCurrency(row.totalBudget)}` : '—' }}</td><td>{{ row.currency }} {{ formatCurrency(row.expenses) }}</td><td :class="{ 'is-over-budget': row.hasApprovedBudget && row.balance < 0 }">{{ row.hasApprovedBudget ? `${row.currency} ${formatCurrency(row.balance)}` : '—' }}</td><td><span class="finance-status" :class="`status-${row.budgetStatus}`">{{ row.budgetStatus === 'no-budget' ? 'No budget yet' : budgetStageLabels[row.budgetStatus] || row.budgetStatus }}</span></td></tr></tbody></table></div>
        </section>
      </section>
    </template>

    <template v-else>
    <header class="finance-page-toolbar">
      <div>
        <h1>Finance Vouchers</h1>
        <p>Prepare, check, verify, and approve finance vouchers.</p>
      </div>
      <div class="finance-toolbar-actions">
        <select v-model="selectedVoucherId" aria-label="Load a saved voucher" @change="loadSelectedVoucher">
          <option value="">{{ savedVouchers.length ? 'Open saved voucher…' : 'No saved vouchers' }}</option>
          <option v-for="saved in savedVouchers" :key="saved.id" :value="String(saved.id)">{{ saved.voucher_number }} · {{ saved.payee_name || 'Untitled' }}</option>
        </select>
        <button type="button" class="finance-new-button" @click="startNewVoucher">New</button>
        <button type="button" class="finance-save-button" :disabled="savingVoucher" @click="saveVoucher">{{ savingVoucher ? 'Saving…' : 'Save voucher' }}</button>
        <button type="button" class="finance-print-button" @click="printVoucher">Print</button>
      </div>
    </header>
    <p v-if="voucherMessage" class="voucher-message" role="status" aria-live="polite">{{ voucherMessage }}</p>

    <form class="finance-paper" @submit.prevent="saveVoucher">
      <header class="voucher-header">
        <div class="voucher-brand">
          <img :src="organizationLogo" alt="Live &amp; Learn Cambodia Organization" />
        </div>
        <h2>{{ voucher.voucherType || 'FINANCE VOUCHER' }}</h2>
        <div class="voucher-reference-fields">
          <label>Voucher No:<input v-model.trim="voucher.number" aria-label="Voucher number" /></label>
          <label>Voucher Date:<input v-model="voucher.date" type="date" aria-label="Voucher date" /></label>
          <label>Adv. No:<input v-model.trim="voucher.advanceNumber" aria-label="Advance number" /></label>
        </div>
      </header>

      <section class="voucher-project-fields" aria-label="Project budget assignment">
        <label>Structure<select v-model="voucher.department"><option v-for="department in departments" :key="department">{{ department }}</option></select></label>
        <label>Project<select v-model="voucher.projectName"><option v-for="project in projectOptions" :key="project">{{ project }}</option></select></label>
        <label>Fiscal year<input v-model.number="voucher.fiscalYear" type="number" min="2000" max="2200" /></label>
      </section>

      <section class="voucher-parties" aria-label="Voucher details">
        <div class="voucher-person-fields">
          <label><strong>Payee / Officer Name</strong><input v-model.trim="voucher.name" placeholder="Full name of staff or vendor" /></label>
          <label><strong>Designation / Position</strong><input v-model.trim="voucher.position" placeholder="e.g. Project Field Coordinator" /></label>
          <label><strong>Operational Purpose</strong><textarea v-model.trim="voucher.purpose" rows="2" placeholder="Brief summary of payment or settlement"></textarea></label>
          <label class="voucher-signature-field"><strong>SIGNATURE</strong><span></span><strong>DATE</strong><input v-model="voucher.signatureDate" type="date" /></label>
        </div>
        <fieldset class="voucher-choice-group voucher-type-group">
          <legend>TYPE OF VOUCHER<br /><small>(Please use X)</small></legend>
          <label v-for="type in voucherTypes" :key="type"><input v-model="voucher.voucherType" type="radio" name="voucher-type" :value="type" />{{ type }}</label>
        </fieldset>
        <fieldset class="voucher-choice-group voucher-payment-group">
          <legend>MODE OF PAYMENT<br /><small>(Please use X)</small></legend>
          <label v-for="mode in paymentModes" :key="mode"><input v-model="voucher.paymentMode" type="radio" name="payment-mode" :value="mode" />{{ mode }}</label>
          <label class="cheque-number"><span>Cheque # / Tx Ref</span><input v-model.trim="voucher.chequeNumber" placeholder="e.g. CQ-99210" /></label>
        </fieldset>
      </section>

      <section class="voucher-expenses" aria-label="Expenditure and settlement items">
        <header class="voucher-expenses-heading">
          <div><strong>Expenditure &amp; Settlement Items</strong><span>Provide line item description, grant codes, and allocated values</span></div>
          <div class="voucher-line-actions">
            <button type="button" @click="addLine">+ Add line</button>
            <button v-if="lines.length > 1" type="button" class="remove-line-action" @click="removeLine(lines[lines.length - 1].id)">Remove Last Line</button>
          </div>
        </header>
        <div class="voucher-table-wrap">
        <table class="voucher-lines">
          <colgroup><col class="col-no" /><col class="col-description" /><col class="col-funding" /><col class="col-budget" /><col class="col-amount" /><col class="col-liquidated" /><col class="col-balance" /><col class="col-coa" /><col class="col-debit" /><col class="col-credit" /></colgroup>
          <thead>
            <tr>
              <th rowspan="2" class="line-number">No.</th>
              <th rowspan="2" class="line-description">Description</th>
              <th rowspan="2">Funding<br />Source</th>
              <th rowspan="2">Budget<br />Code</th>
              <th rowspan="2">Amount</th>
              <th rowspan="2">Liquidated<br />Amount</th>
              <th rowspan="2">Balance</th>
              <th colspan="3" class="finance-only-heading">FINANCE ONLY</th>
            </tr>
            <tr>
              <th>CoA</th>
              <th>Debit (Dr)</th>
              <th>Credit (Cr)</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(line, index) in lines" :key="line.id">
              <td class="line-number">{{ index + 1 }}</td>
              <td><textarea v-model.trim="line.description" :aria-label="`Line ${index + 1} description`" rows="1"></textarea></td>
              <td><input v-model.trim="line.fundingSource" :aria-label="`Line ${index + 1} funding source`" /></td>
              <td><input v-model.trim="line.budgetCode" :aria-label="`Line ${index + 1} budget code`" /></td>
              <td><input v-model="line.amount" :aria-label="`Line ${index + 1} amount`" type="number" min="0" step="0.01" /></td>
              <td><input v-model="line.liquidatedAmount" :aria-label="`Line ${index + 1} liquidated amount`" type="number" min="0" step="0.01" /></td>
              <td class="calculated-amount">{{ formatAmount(numericValue(line.amount) - numericValue(line.liquidatedAmount)) }}</td>
              <td><input v-model.trim="line.accountCode" :aria-label="`Line ${index + 1} chart of accounts`" /></td>
              <td><input v-model="line.debit" :aria-label="`Line ${index + 1} debit`" type="number" min="0" step="0.01" /></td>
              <td><input v-model="line.credit" :aria-label="`Line ${index + 1} credit`" type="number" min="0" step="0.01" /></td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="total-label">TOTAL SUMMARY (USD):</th>
              <th>{{ formatAmount(totalAmount) }}</th>
              <th>{{ formatAmount(totalLiquidated) }}</th>
              <th>{{ formatAmount(totalBalance) }}</th>
              <th></th>
              <th>{{ formatAmount(totalDebit) }}</th>
              <th>{{ formatAmount(totalCredit) }}</th>
            </tr>
          </tfoot>
        </table>
        </div>
      </section>

      <label class="voucher-remarks"><strong>REMARKS</strong><textarea v-model.trim="voucher.remarks" rows="3"></textarea></label>

      <section class="voucher-workflow-strip" aria-label="Voucher workflow">
        <div><strong>Approval workflow</strong><span>Prepare → Check → Verify → Approve</span></div>
        <ol><li v-for="(stage, index) in voucherStages.slice(1)" :key="stage" :class="{ 'is-complete': voucherStages.indexOf(voucher.workflowStatus) >= index + 1 }"><i>{{ index + 1 }}</i>{{ voucherStageLabels[stage] }}</li></ol>
        <button v-if="voucher.workflowStatus !== 'approved'" type="button" class="finance-action-primary" :disabled="savingVoucher" @click="advanceVoucher">{{ voucher.workflowStatus === 'draft' ? 'Prepare voucher' : `Mark ${voucherStageLabels[voucherStages[voucherStages.indexOf(voucher.workflowStatus) + 1]] || 'approved'}` }} <span>→</span></button>
        <span v-else class="finance-approved-note">Voucher approved</span>
      </section>

      <div class="voucher-approvals-wrap">
        <header class="approval-section-heading"><strong>AUTHORIZATION &amp; SIGN-OFF WORKFLOW</strong><span>Electronic verification sequence from submission to financial posting</span></header>
        <div class="voucher-approval-cards">
          <section v-for="role in approvalRoles" :key="role.key" class="voucher-approval-card">
            <header><strong>{{ role.label }}</strong><span>{{ role.badge }}</span></header>
            <div class="approval-signature"><canvas :ref="(element) => setSignatureCanvas(element, role.key)" :aria-label="`${role.label} signature pad`" @pointerdown="startSignature($event, role.key)" @pointermove="drawSignature($event, role.key)" @pointerup="finishSignature($event, role.key)" @pointercancel="finishSignature($event, role.key)"></canvas><span v-if="!approvals[role.key].signature">Draw signature here</span></div>
            <button v-if="approvals[role.key].signature" class="signature-clear" type="button" @click="clearSignature(role.key)">Clear signature</button>
            <label><span>Name</span><input v-model.trim="approvals[role.key].name" :aria-label="`${role.label} name`" /></label>
            <label><span>Position</span><input v-model.trim="approvals[role.key].position" :aria-label="`${role.label} position`" /></label>
            <label><span>Date</span><input v-model.trim="approvals[role.key].date" type="text" placeholder="DD / MM / YYYY" :aria-label="`${role.label} date`" /></label>
          </section>
        </div>
      </div>
    </form>
    </template>
  </section>
</template>

<style scoped>
.finance-page{max-width:1120px;margin:0 auto;padding:18px 14px 52px;color:#17202c}
.finance-page-toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 16px}
.finance-kicker{color:#648079;font-size:12px;font-weight:750;letter-spacing:.14em}
.finance-page-toolbar h1{margin:4px 0;color:#1b3044;font-size:25px}
.finance-page-toolbar p{margin:0;color:#718092;font-size:13px}
.finance-print-button,.voucher-line-actions button{min-height:38px;padding:0 15px;border:1px solid #176e64;border-radius:7px;background:#167568;color:#fff;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.finance-paper{max-width:900px;margin:0 auto;padding:18px;background:#fff;border:1px solid #d7dde2;box-shadow:0 8px 30px #1e334012;font-family:Arial,Helvetica,sans-serif;font-size:12px}
.voucher-header{display:grid;grid-template-columns:1fr 1fr 1fr;align-items:start;gap:10px;padding:0 2px 10px}
.voucher-brand{display:flex;align-items:center;padding-top:4px}
.voucher-brand img{display:block;width:min(100%,220px);max-height:60px;object-fit:contain;object-position:left center}
.voucher-header h2{margin:3px 0;text-align:center;font-size:19px;white-space:nowrap}
.voucher-reference-fields{display:grid;gap:4px}
.voucher-reference-fields label{display:grid;grid-template-columns:auto minmax(65px,1fr);align-items:center;gap:6px;font-size:12px;font-weight:700}
.voucher-reference-fields input{min-width:0;height:18px;padding:0 2px;border:0;border-bottom:1px solid #222;border-radius:0;font:inherit}
.voucher-parties{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(120px,.85fr) minmax(120px,.9fr);min-height:105px;border:2px solid #111}
.voucher-person-fields{display:grid;grid-template-rows:repeat(3,minmax(20px,1fr)) 28px;border-right:2px solid #111}
.voucher-person-fields>label{display:grid;grid-template-columns:76px minmax(0,1fr);align-items:stretch;border-bottom:1px solid #999}
.voucher-person-fields>label:last-child{border-bottom:0}
.voucher-person-fields label>strong{display:flex;align-items:center;padding:2px 4px;border-right:1px solid #222;font-size:12px}
.voucher-person-fields input,.voucher-person-fields textarea{width:100%;min-width:0;resize:none;border:0;border-radius:0;padding:3px;font:inherit}
.voucher-person-fields .voucher-signature-field{grid-template-columns:76px minmax(25px,1fr) 30px 75px}
.voucher-signature-field span{border-right:1px solid #999}
.voucher-choice-group{display:grid;grid-template-columns:34px minmax(0,1fr);align-content:start;gap:3px 5px;margin:0;padding:4px 5px;border:0;border-right:2px solid #111}
.voucher-choice-group legend{float:left;grid-row:1 / span 5;align-self:center;padding:0 3px;writing-mode:vertical-rl;transform:rotate(180deg);text-align:center;font-size:12px;font-weight:800}
.voucher-choice-group legend small{font-size:12px;font-weight:400}
.voucher-choice-group label{display:flex;align-items:center;gap:5px;min-height:14px;font-size:12px}
.voucher-choice-group input[type=radio]{display:grid;width:13px;height:13px;margin:0;appearance:none;place-content:center;border:1px solid #222;border-radius:1px;background:#fff}
.voucher-choice-group input[type=radio]::after{content:"X";font-size:12px;font-weight:800;opacity:0}
.voucher-choice-group input[type=radio]:checked::after{opacity:1}
.voucher-payment-group{border-right:0}
.voucher-payment-group legend{grid-row:1 / span 5}
.voucher-payment-group .cheque-number{grid-column:2;display:flex;gap:4px;white-space:nowrap}
.cheque-number input{width:80px;min-width:0;border:0;border-bottom:1px solid #444;border-radius:0}
.voucher-table-wrap{overflow-x:auto}
.voucher-lines,.voucher-approvals{width:100%;border-collapse:collapse;table-layout:fixed}
.voucher-lines{margin-top:3px;font-size:12px}
.voucher-lines th,.voucher-lines td,.voucher-approvals th,.voucher-approvals td{border:1px solid #222}
.voucher-lines thead th{height:19px;padding:2px 3px;text-align:center;font-weight:700}
.voucher-lines thead tr:first-child th{border-top:2px solid #111}
.voucher-lines .line-number{width:24px}
.voucher-lines .line-description{width:25%}
.voucher-lines th:nth-child(3){width:8%}
.voucher-lines th:nth-child(4){width:7%}
.voucher-lines th:nth-child(5){width:9%}
.voucher-lines th:nth-child(6){width:10%}
.voucher-lines th:nth-child(7){width:8%}
.voucher-lines th:nth-child(8){width:8%}
.voucher-lines th:nth-child(9),.voucher-lines th:nth-child(10){width:8%}
.voucher-lines .finance-only-heading{border-left:2px solid #111}
.voucher-lines tbody tr{height:28px}
.voucher-lines tbody td{padding:0 2px}
.voucher-lines tbody td:nth-child(8){border-left:2px solid #111}
.voucher-lines input,.voucher-lines textarea,.voucher-approvals input{display:block;box-sizing:border-box;width:100%;height:100%;min-height:22px;padding:2px;border:0;border-radius:0;outline-offset:-2px;background:transparent;color:#111;font:inherit}
.voucher-lines textarea{resize:none}
.voucher-lines input[type=number]{appearance:textfield;text-align:right}
.voucher-lines input[type=number]::-webkit-inner-spin-button,.voucher-lines input[type=number]::-webkit-outer-spin-button{margin:0;appearance:none}
.voucher-lines .calculated-amount{text-align:right;white-space:nowrap}
.voucher-lines tfoot th{height:19px;padding:2px 3px;border-top:2px solid #111;text-align:right;white-space:nowrap}
.voucher-lines tfoot .total-label{border-right:0}
.voucher-line-actions{display:flex;gap:7px;margin:7px 0}
.voucher-line-actions button{min-height:27px;padding:0 10px;font-size:12px}
.voucher-line-actions .remove-line-action{border-color:#b7c1ca;background:#fff;color:#52616f}
.voucher-remarks{display:grid;grid-template-rows:16px minmax(45px,auto);border:2px solid #111}
.voucher-remarks strong{padding:2px 5px}
.voucher-remarks textarea{width:100%;min-height:45px;resize:vertical;padding:4px;border:0;border-top:1px solid #888;border-radius:0;font:inherit}
.voucher-approvals{margin-top:3px;font-size:12px}
.voucher-approvals th,.voucher-approvals td{height:23px;padding:2px 4px}
.voucher-approvals thead th{text-align:center}
.voucher-approvals thead th:not(.approval-label-spacer){height:18px}
.voucher-approvals .approval-label-spacer,.voucher-approvals tbody th{width:9%;text-align:left}
.voucher-approvals input{min-height:19px}
@media(max-width:680px){.finance-page{padding:12px 7px 35px}.finance-page-toolbar{align-items:flex-start;flex-direction:column}.finance-page-toolbar h1{font-size:22px}.finance-paper{padding:10px}.voucher-header{grid-template-columns:1fr 1fr}.voucher-header h2{grid-column:1/-1;grid-row:1;text-align:right;font-size:16px}.voucher-brand{grid-row:2}.voucher-reference-fields{grid-row:2}.voucher-parties{grid-template-columns:minmax(0,1.2fr) minmax(100px,.8fr) minmax(110px,.9fr)}.voucher-person-fields>label{grid-template-columns:57px minmax(0,1fr)}.voucher-person-fields .voucher-signature-field{grid-template-columns:57px minmax(12px,1fr) 26px 62px}.voucher-choice-group{grid-template-columns:25px minmax(0,1fr);gap:2px}.voucher-choice-group legend{font-size:12px}.voucher-choice-group label{font-size:12px}.voucher-lines{min-width:620px}.voucher-approvals{font-size:12px}}
.finance-page{max-width:1360px;padding:28px clamp(16px,3vw,40px) 56px;color:#172a3a}
.finance-workspace-heading{margin:0 0 20px}
.finance-workspace-heading h1{margin:6px 0;color:#172a3a;font-size:clamp(28px,3vw,36px);font-weight:760;letter-spacing:-.04em}
.finance-workspace-heading p{margin:0;color:#65758a;font-size:15px}
.finance-view-nav{display:flex;gap:6px;margin:0 0 22px;padding:5px;border:1px solid #e2eaf1;border-radius:12px;background:#f2f6fa}
.finance-view-nav button{min-height:42px;padding:0 18px;border:0;border-radius:8px;background:transparent;color:#5a6c80;font:inherit;font-size:14px;font-weight:650;cursor:pointer}
.finance-view-nav button:hover{background:#e9eff5;color:#29435d}
.finance-view-nav button.is-active{background:#fff;color:#12695e;box-shadow:0 2px 7px #233d5212}
.finance-card{min-width:0;padding:22px;border:1px solid #e0e8ef;border-radius:14px;background:#fff;box-shadow:0 5px 18px #19344a09}
.finance-budget-layout{display:grid;grid-template-columns:minmax(270px,.72fr) minmax(0,1.65fr);align-items:start;gap:18px}
.finance-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin:0 0 20px;padding:0 0 16px;border-bottom:1px solid #edf1f5}
.finance-card-heading h2{margin:4px 0 5px;color:#1b3044;font-size:21px;letter-spacing:-.02em}
.finance-card-heading p{margin:0;color:#708094;font-size:13px;line-height:1.5}
.finance-card-kicker{color:#167568;font-size:11px;font-weight:750;letter-spacing:.12em}
.finance-card-heading .finance-new-button{min-height:38px;padding:0 12px;border:1px solid #cbd9e5;border-radius:8px;background:#fff;color:#2d4963;font:inherit;font-size:13px;font-weight:650;cursor:pointer;white-space:nowrap}
.finance-budget-open,.finance-budget-fields label,.finance-budget-line label{display:grid;gap:7px;color:#40566e;font-size:13px;font-weight:650}
.finance-budget-open{margin:0 0 14px}
.finance-budget-open select,.finance-budget-fields input,.finance-budget-fields select,.finance-budget-fields textarea,.finance-budget-line input,.voucher-project-fields input,.voucher-project-fields select{box-sizing:border-box;width:100%;min-width:0;min-height:42px;padding:9px 11px;border:1px solid #dce5ed;border-radius:8px;background:#fbfcfe;color:#243c53;font:inherit;font-size:14px}
.finance-budget-fields textarea{resize:vertical}
.finance-budget-fields input:focus,.finance-budget-fields select:focus,.finance-budget-fields textarea:focus,.finance-budget-line input:focus,.finance-budget-open select:focus,.voucher-project-fields input:focus,.voucher-project-fields select:focus{outline:3px solid #14756820;border-color:#168675}
.finance-budget-records{display:grid;gap:7px;max-height:540px;overflow:auto}
.finance-budget-record{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:11px;border:1px solid #e8edf2;border-radius:9px;background:#fff;color:inherit;text-align:left;cursor:pointer}
.finance-budget-record:hover,.finance-budget-record.is-selected{border-color:#a6d8c9;background:#f6fbf9}
.finance-budget-record>span:first-child{display:grid;gap:4px;min-width:0}
.finance-budget-record strong{overflow:hidden;color:#243c53;font-size:13px;text-overflow:ellipsis;white-space:nowrap}
.finance-budget-record small{overflow:hidden;color:#748398;font-size:11px;text-overflow:ellipsis;white-space:nowrap}
.finance-status{display:inline-flex;flex:none;align-items:center;padding:5px 8px;border-radius:99px;background:#edf1f5;color:#526579;font-size:11px;font-weight:700}
.status-submitted{background:#fff6df;color:#896300}.status-checked{background:#eaf3ff;color:#285d9b}.status-reviewed{background:#f1eaff;color:#6743a0}.status-approved{background:#e7f7ef;color:#14734f}.status-returned{background:#fff0f0;color:#b44242}.status-no-budget{background:#edf1f5;color:#65768a}
.finance-empty{margin:0;padding:18px;border:1px dashed #d7e1e9;border-radius:10px;color:#718197;font-size:13px;line-height:1.5}
.finance-budget-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
.finance-budget-fields .is-wide{grid-column:1/-1}
.finance-budget-lines-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:22px 0 12px}
.finance-budget-lines-heading h3{margin:0 0 4px;color:#29425a;font-size:16px}
.finance-budget-lines-heading p{margin:0;color:#748398;font-size:12px}
.finance-line-add,.finance-action-secondary,.finance-action-primary{display:inline-flex;min-height:40px;align-items:center;justify-content:center;gap:8px;padding:0 14px;border:1px solid #d2dee8;border-radius:8px;background:#fff;color:#36516b;font:inherit;font-size:13px;font-weight:650;cursor:pointer}
.finance-line-add{min-height:36px;color:#147568}
.finance-action-primary{border-color:#147568;background:#147568;color:#fff;box-shadow:0 3px 9px #14756824}
.finance-action-primary:hover:not(:disabled){background:#105f55}
.finance-action-primary:disabled{opacity:.6;cursor:wait}
.finance-budget-line-list{display:grid;gap:9px}
.finance-budget-line{display:grid;grid-template-columns:30px minmax(0,1.6fr) minmax(100px,.8fr) minmax(120px,.7fr) 34px;align-items:end;gap:10px;padding:12px;border:1px solid #e5ebf1;border-radius:9px;background:#fafbfd}
.finance-budget-line-number{align-self:center;color:#8190a2;font-size:13px;font-weight:700;text-align:center}
.finance-line-remove{width:32px;height:38px;border:1px solid #f0d8d8;border-radius:7px;background:#fff;color:#b44242;font-size:20px;cursor:pointer}
.finance-budget-total{display:flex;align-items:center;justify-content:space-between;margin:14px 0 0;padding:14px 16px;border-radius:9px;background:#f1f7f5;color:#476257;font-size:14px}
.finance-budget-total strong{color:#12695e;font-size:16px}
.finance-budget-workflow{display:flex;justify-content:space-between;gap:8px;margin:22px 0 14px}
.finance-budget-workflow>div{display:grid;justify-items:center;gap:7px;flex:1;color:#8592a2;font-size:11px;font-weight:650;text-align:center}
.finance-budget-workflow i{display:grid;width:28px;height:28px;place-items:center;border:1px solid #dce5ed;border-radius:50%;background:#fff;font-style:normal}
.finance-budget-workflow .is-complete{color:#147568}.finance-budget-workflow .is-complete i{border-color:#147568;background:#eaf7f2;color:#147568}
.finance-budget-actions{display:flex;justify-content:flex-end;gap:9px;margin:18px 0 0;padding-top:14px;border-top:1px solid #edf1f5}
.finance-feedback{margin:12px 0 0;color:#147568;font-size:13px}
.finance-approved-note{align-self:center;color:#14734f;font-size:14px;font-weight:700}
.finance-report-card{padding:24px}
.finance-report-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 22px}
.finance-report-summary article{display:grid;gap:7px;padding:15px;border:1px solid #e5ecf2;border-radius:10px;background:#f8fafc}
.finance-report-summary span{color:#718197;font-size:12px;font-weight:600}
.finance-report-summary strong{color:#243c53;font-size:18px;font-variant-numeric:tabular-nums}
.finance-report-group{margin-top:18px}
.finance-report-group>header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 9px}
.finance-report-group>header h3{margin:0;color:#29425a;font-size:16px}
.finance-report-group>header span{color:#7a8a9c;font-size:12px}
.finance-report-table-wrap{overflow:auto}
.finance-report-table{width:100%;border-collapse:collapse;font-size:14px}
.finance-report-table th,.finance-report-table td{padding:13px 12px;border-bottom:1px solid #e9eef3;text-align:left;white-space:nowrap}
.finance-report-table th{background:#f7f9fb;color:#617287;font-size:12px;font-weight:700}
.finance-report-table td{color:#344b63}.finance-report-table td:nth-child(n+4){font-variant-numeric:tabular-nums;text-align:right}
.finance-report-table .is-over-budget{color:#b44242;font-weight:700}
.voucher-project-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0 0 12px;padding:14px;border:1px solid #e2eaf1;border-radius:10px;background:#f8fafc}
.voucher-project-fields label{display:grid;gap:6px;color:#465b70;font-size:12px;font-weight:700}
.voucher-workflow-strip{display:grid;grid-template-columns:minmax(190px,.8fr) minmax(0,2fr) auto;align-items:center;gap:16px;margin-top:14px;padding:15px;border:1px solid #e1e9ef;border-radius:10px;background:#f8fafc}
.voucher-workflow-strip>div:first-child{display:grid;gap:4px}.voucher-workflow-strip>div:first-child strong{color:#30485f;font-size:13px}.voucher-workflow-strip>div:first-child span{color:#748398;font-size:11px}
.voucher-workflow-strip ol{display:flex;justify-content:space-between;gap:6px;margin:0;padding:0;list-style:none}
.voucher-workflow-strip li{display:grid;justify-items:center;gap:5px;flex:1;color:#8290a1;font-size:10px;font-weight:650}
.voucher-workflow-strip li i{display:grid;width:25px;height:25px;place-items:center;border:1px solid #dce5ed;border-radius:50%;background:#fff;font-style:normal}
.voucher-workflow-strip li.is-complete{color:#147568}.voucher-workflow-strip li.is-complete i{border-color:#147568;background:#eaf7f2;color:#147568}
.finance-page-toolbar{align-items:center;margin-bottom:22px}
.finance-page-toolbar h1{margin:6px 0 5px;color:#172a3a;font-size:clamp(27px,3vw,34px);font-weight:750;letter-spacing:-.035em}
.finance-page-toolbar p{color:#65758a;font-size:14px}
.finance-kicker{display:inline-flex;align-items:center;gap:8px;color:#167568;font-size:12px;letter-spacing:.13em}
.finance-kicker::before{width:8px;height:8px;border-radius:50%;background:#20a58d;content:""}
.finance-print-button,.voucher-line-actions button{min-height:42px;padding:0 18px;border-color:#12695e;border-radius:9px;background:#147568;box-shadow:0 3px 8px #16756824;font-size:13px;transition:background .18s ease,transform .18s ease,box-shadow .18s ease}
.finance-print-button:hover,.voucher-line-actions button:hover{transform:translateY(-1px);background:#105f55;box-shadow:0 6px 14px #16756832}
.finance-paper{max-width:1180px;padding:clamp(18px,2.4vw,32px);border:1px solid #dce5eb;border-radius:16px;box-shadow:0 16px 42px #19344a12;font-size:12px}
.voucher-header{align-items:center;gap:18px;padding:0 4px 18px}
.voucher-brand img{width:min(100%,250px);max-height:76px}
.voucher-header h2{color:#1b2d3f;font-size:clamp(20px,2.3vw,27px);font-weight:700;letter-spacing:.025em}
.voucher-reference-fields{gap:7px}
.voucher-reference-fields label{grid-template-columns:90px minmax(80px,1fr);gap:8px;color:#32475b;font-size:12px}
.voucher-reference-fields input{height:25px;border-color:#9baab7;font-size:12px}
.voucher-parties{grid-template-columns:minmax(0,1.45fr) minmax(190px,.88fr) minmax(190px,.9fr);min-height:148px;border-color:#253747;border-radius:7px;overflow:hidden}
.voucher-person-fields{grid-template-rows:repeat(3,minmax(32px,1fr)) 38px;border-color:#253747}
.voucher-person-fields>label{border-color:#d7e0e7}
.voucher-person-fields label>strong{padding:5px 9px;border-color:#d7e0e7;background:#f7f9fb;color:#34495d;font-size:12px;letter-spacing:.045em}
.voucher-person-fields input,.voucher-person-fields textarea{padding:6px 8px;color:#172a3a;font:inherit}
.voucher-choice-group{grid-template-columns:42px minmax(0,1fr);align-content:center;gap:5px 8px;padding:10px;border-color:#d7e0e7}
.voucher-choice-group legend{font-size:12px;line-height:1.35}
.voucher-choice-group legend small{font-size:12px}
.voucher-choice-group label{min-height:24px;gap:8px;color:#34495d;font-size:12px}
.voucher-choice-group input[type=radio]{width:18px;height:18px;border-color:#9aabb8;border-radius:4px}
.voucher-choice-group input[type=radio]:checked{border-color:#167568;background:#e4f5f0;color:#12695e}
.voucher-choice-group input[type=radio]::after{font-size:12px}
.voucher-payment-group .cheque-number{margin-top:4px}
.cheque-number input{height:22px;padding:2px 4px;border-color:#9aabb8}
.voucher-table-wrap{margin-top:8px;border:1px solid #d9e2e9;border-radius:8px}
.voucher-lines,.voucher-approvals{color:#263b4d}
.voucher-lines{min-width:900px;margin:0;font-size:12px}
.voucher-lines th,.voucher-lines td,.voucher-approvals th,.voucher-approvals td{border-color:#cbd6df}
.voucher-lines thead th{height:42px;padding:7px 5px;background:#f1f6fa;color:#345d83;font-size:12px;line-height:1.3}
.voucher-lines thead tr:first-child th{border-top:2px solid #263b4d}
.voucher-lines .line-number{width:34px}
.voucher-lines tbody tr{height:39px}
.voucher-lines tbody td{padding:0 4px}
.voucher-lines tbody td:nth-child(8){border-left:2px solid #263b4d}
.voucher-lines input,.voucher-lines textarea{min-height:34px;padding:5px;color:#1c3042}
.voucher-lines input:focus-visible,.voucher-lines textarea:focus-visible,.voucher-approvals input:focus-visible,.voucher-reference-fields input:focus-visible,.voucher-person-fields input:focus-visible,.voucher-person-fields textarea:focus-visible,.voucher-remarks textarea:focus-visible,.cheque-number input:focus-visible{outline:2px solid #168273;outline-offset:-2px}
.voucher-lines .calculated-amount{padding:0 7px;color:#345d83;font-variant-numeric:tabular-nums}
.voucher-lines tfoot th{height:34px;padding:5px;background:#f7f9fb;font-variant-numeric:tabular-nums}
.voucher-lines tfoot .total-label{color:#253c50}
.voucher-line-actions{gap:9px;margin:12px 0}
.voucher-line-actions button{min-height:34px;padding:0 13px;font-size:12px}
.voucher-line-actions .remove-line-action{box-shadow:none}
.voucher-line-actions .remove-line-action:hover{border-color:#aab8c4;background:#f5f8fa;color:#34495d}
.voucher-remarks{grid-template-rows:28px minmax(62px,auto);border-color:#536678;border-radius:6px;overflow:hidden}
.voucher-remarks strong{display:flex;align-items:center;padding:4px 9px;background:#f1f6fa;color:#34495d;font-size:12px;letter-spacing:.05em}
.voucher-remarks textarea{min-height:62px;padding:8px;border-color:#d7e0e7;color:#172a3a}
.voucher-approvals{margin-top:10px;font-size:12px}
.voucher-approvals th,.voucher-approvals td{height:32px;padding:5px 7px}
.voucher-approvals thead th{background:#f1f6fa;color:#345d83}
.voucher-approvals .approval-label-spacer,.voucher-approvals tbody th{color:#34495d}
.voucher-approvals input{min-height:27px;padding:4px;color:#172a3a}
.voucher-approvals tbody tr:last-child input{height:28px;padding:4px 6px;border:1px solid #d3dee7;border-radius:6px;background:#f8fafc;color:#30485d;font-size:12px;letter-spacing:.015em}
.voucher-approvals tbody tr:last-child input::placeholder{color:#8494a3;opacity:1}
.voucher-approvals tbody tr:last-child input:hover{border-color:#9aabb8;background:#fff}
.voucher-approvals tbody tr:last-child input:focus-visible{outline:2px solid #168273;outline-offset:1px;border-color:#168273;background:#fff}
.finance-page{background:transparent;border-radius:0}
.finance-paper{box-shadow:0 18px 48px #19344a14,0 2px 8px #19344a08}
.voucher-header{border-bottom:1px solid #e4ebf0;margin-bottom:14px}
.voucher-table-wrap{box-shadow:0 2px 8px #19344a08}
.voucher-lines thead th{background:linear-gradient(180deg,#eaf2f8,#f4f8fb);color:#284f70}
.voucher-lines tbody tr:nth-child(even){background:#fbfdfe}
.voucher-lines tbody tr:focus-within{background:#eff8f6}
.voucher-lines tfoot th{background:#eaf2f5;color:#223e54}
.voucher-approvals tbody tr:last-child input{min-width:0}
@media(max-width:900px){
  .finance-page{padding:22px 16px 42px}
  .voucher-parties{grid-template-columns:1fr 1fr}
  .voucher-person-fields{grid-column:1/-1;border-right:0;border-bottom:2px solid #253747}
  .voucher-choice-group{min-height:138px}
  .voucher-type-group{border-right:1px solid #d7e0e7}
}
@media(max-width:680px){
  .finance-page{padding:16px 10px 32px}
  .finance-page-toolbar{align-items:stretch;gap:14px}
  .finance-page-toolbar h1{font-size:25px}
  .finance-print-button{align-self:flex-start}
  .finance-paper{padding:16px 12px;border-radius:12px}
  .voucher-header{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:9px;padding-bottom:13px}
  .voucher-header h2{grid-column:1/-1;grid-row:1;text-align:left;font-size:20px}
  .voucher-brand{grid-row:2}
  .voucher-brand img{max-height:48px}
  .voucher-reference-fields{grid-row:2}
  .voucher-reference-fields label{grid-template-columns:1fr;gap:1px;font-size:12px}
  .voucher-reference-fields input{height:22px}
  .voucher-parties{grid-template-columns:1fr}
  .voucher-person-fields{grid-column:auto;grid-template-rows:repeat(3,minmax(36px,1fr)) 38px}
  .voucher-person-fields>label{grid-template-columns:70px minmax(0,1fr)}
  .voucher-person-fields .voucher-signature-field{grid-template-columns:70px minmax(20px,1fr) 32px 86px}
  .voucher-choice-group{grid-template-columns:38px minmax(0,1fr);min-height:0;border-right:0;border-bottom:1px solid #d7e0e7}
  .voucher-choice-group label{font-size:12px}
  .voucher-choice-group legend{font-size:12px}
  .voucher-payment-group{border-bottom:0}
  .voucher-lines{min-width:900px}
  .voucher-approvals{min-width:620px}
  .voucher-approvals-wrap{overflow-x:auto}
  .finance-page{padding:18px 10px 34px;background:transparent}
  .finance-page-toolbar{gap:10px;margin-bottom:16px}
  .finance-page-toolbar p{max-width:34ch;line-height:1.5}
  .finance-paper{padding:16px 12px;border:1px solid #d5e0e8;border-radius:14px}
  .voucher-table-wrap{margin:10px -2px 0;border-radius:9px}
  .voucher-lines thead th{height:46px;padding:8px 6px;font-size:12px}
  .voucher-lines tbody tr{height:44px}
  .voucher-lines input,.voucher-lines textarea{min-height:38px;padding:6px}
  .voucher-lines .line-number{font-size:12px}
  .voucher-approvals-wrap{padding-bottom:5px}
  .voucher-approvals{font-size:12px}
  .voucher-approvals th,.voucher-approvals td{height:38px;padding:6px 7px}
  .voucher-approvals tbody tr:last-child input{height:32px;font-size:12px}
}
.finance-page-toolbar h1{font-size:clamp(30px,3.2vw,38px);letter-spacing:-.04em}
.finance-page-toolbar p{font-size:14px;line-height:1.5}
.finance-paper{font-size:12px}
.voucher-header h2{font-size:clamp(22px,2.5vw,28px)}
.voucher-reference-fields label{font-size:12px}
.voucher-reference-fields input{height:29px;font-size:12px}
.voucher-person-fields label>strong{font-size:12px}
.voucher-person-fields input,.voucher-person-fields textarea{font-size:12px}
.voucher-choice-group label{font-size:12px}
.voucher-choice-group legend{font-size:12px}
.voucher-lines{font-size:12px}
.voucher-lines thead th{height:48px;padding:8px 6px;font-size:12px}
.voucher-lines tbody tr{height:44px}
.voucher-lines input,.voucher-lines textarea{min-height:38px;padding:6px;font-size:12px}
.voucher-lines .line-number{font-size:12px}
.voucher-lines tfoot th{height:38px;font-size:12px}
.voucher-line-actions button{min-height:38px;font-size:12px}
.voucher-remarks strong{font-size:12px}
.voucher-approvals{font-size:12px}
.voucher-approvals th,.voucher-approvals td{height:38px}
.voucher-approvals tbody tr:last-child input{height:32px;font-size:12px}
.finance-page{max-width:1440px;padding:24px clamp(12px,2vw,28px) 48px;background:transparent;color:#26364a}
.finance-page-toolbar{margin-bottom:16px}
.finance-page-toolbar h1{font-size:28px}
.finance-page-toolbar p{font-size:12px}
.finance-paper{max-width:none;padding:18px;border:1px solid #e4ebf1;border-radius:14px;box-shadow:0 8px 28px #18334a0b}
.voucher-header{grid-template-columns:minmax(0,1fr) 260px;gap:20px;align-items:center;padding:0 4px 16px;border-bottom:1px solid #edf1f5}
.voucher-header h2{display:none}
.voucher-brand img{width:min(100%,290px);max-height:72px}
.voucher-reference-fields{gap:7px;padding:10px;border:1px solid #e5ecf3;border-radius:9px;background:#f8fafc}
.voucher-reference-fields::before{content:"FINANCE VOUCHER";white-space:pre-line;text-align:right;color:#29374a;font-size:12px;font-weight:750;line-height:1.6}
.voucher-reference-fields label{grid-template-columns:78px minmax(0,1fr);font-size:12px;font-weight:500;color:#6b7c91}
.voucher-reference-fields input{height:24px;padding:0 6px;border:1px solid #dbe4ed;border-radius:5px;background:#fff;text-align:right;font-size:12px}
.voucher-parties{grid-template-columns:minmax(1.25fr,1.5fr) minmax(180px,1fr) minmax(180px,1fr);gap:12px;min-height:0;margin:14px 0;padding:14px;border:1px solid #e7edf3;border-radius:10px;background:#fff}
.voucher-person-fields{grid-template-rows:repeat(3,minmax(0,auto));gap:9px;padding-right:12px;border:0;border-right:1px solid #edf1f5}
.voucher-person-fields>label{display:flex;flex-direction:column;gap:4px;border:0}
.voucher-person-fields label>strong{padding:0;border:0;background:transparent;color:#6d8095;font-size:12px;letter-spacing:.04em}
.voucher-person-fields input,.voucher-person-fields textarea{min-height:34px;padding:7px 9px;border:1px solid #dbe4ed;border-radius:6px;background:#fff;font-size:12px}
.voucher-person-fields .voucher-signature-field{display:none}
.voucher-choice-group{display:flex;flex-direction:column;gap:6px;min-width:0;padding:0 12px;border:0;border-right:1px solid #edf1f5}
.voucher-choice-group legend{float:none;grid-row:auto;align-self:auto;padding:0 0 2px;writing-mode:horizontal-tb;transform:none;text-align:left;color:#6d8095;font-size:12px;letter-spacing:.06em}
.voucher-choice-group legend br,.voucher-choice-group legend small{display:none}
.voucher-choice-group label{min-height:31px;padding:5px 8px;border:1px solid #e5ebf1;border-radius:7px;background:#fff;font-size:12px}
.voucher-choice-group label:has(input:checked){border-color:#74d6b2;background:#effcf6;color:#176c53}
.voucher-choice-group input[type=radio]{flex:0 0 14px;width:14px;height:14px;border:1px solid #c6d3df;border-radius:50%;background:#fff}
.voucher-choice-group input[type=radio]::after{content:"";width:6px;height:6px;border-radius:50%;background:#13946b}
.voucher-payment-group{padding-right:0}
.voucher-payment-group .cheque-number{display:flex;flex-direction:column;align-items:stretch;gap:4px;margin-top:1px;font-size:12px;color:#708197}
.cheque-number input{box-sizing:border-box;width:100%;height:30px;padding:5px 8px;border:1px solid #dbe4ed;border-radius:6px}
.voucher-table-wrap{margin-top:0;border:0;border-radius:8px;box-shadow:none}
.voucher-lines{min-width:860px;font-size:12px}
.voucher-lines thead th{height:36px;padding:7px 5px;background:#f4f7fa;color:#5b6d82;font-size:12px;text-transform:uppercase;letter-spacing:.035em}
.voucher-lines thead tr:first-child th{border-top:0}
.voucher-lines tbody tr{height:40px}
.voucher-lines tbody td{padding:2px 4px}
.voucher-lines input,.voucher-lines textarea{min-height:29px;padding:4px 6px;border:1px solid #e1e8ef;border-radius:4px;background:#fff;font-size:12px}
.voucher-lines input:focus-visible,.voucher-lines textarea:focus-visible{outline:2px solid #56c89b;outline-offset:0;border-color:#56c89b}
.voucher-lines .calculated-amount{padding:0 6px;background:#f8fafc;color:#32495e}
.voucher-lines tfoot th{height:36px;padding:5px;background:#f2f5f8;color:#596c80;font-size:12px}
.voucher-line-actions{justify-content:flex-end;margin:9px 0 12px}
.voucher-line-actions button{min-height:31px;padding:0 11px;border-radius:6px;font-size:12px}
.voucher-line-actions .remove-line-action{border-color:#f0d8dc;background:#fff7f8;color:#b24d5c}
.voucher-remarks{grid-template-rows:24px minmax(54px,auto);margin-top:12px;border:1px solid #e5ebf1;border-radius:9px}
.voucher-remarks strong{padding:5px 9px;background:#f8fafc;color:#64768b;font-size:12px}
.voucher-remarks textarea{min-height:54px;padding:9px;border-top:1px solid #edf1f5;font-size:12px}
.voucher-approvals-wrap{margin-top:14px;padding:14px;border:1px solid #e6edf3;border-radius:10px;background:#fff}
.approval-section-heading{display:grid;gap:3px;margin-bottom:12px}
.approval-section-heading strong{color:#35495d;font-size:12px;letter-spacing:.04em}
.approval-section-heading span{color:#91a0b0;font-size:12px}
.voucher-approval-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.voucher-approval-card{display:grid;gap:7px;min-width:0;padding:9px;border:1px solid #e6edf3;border-radius:8px;background:#fbfcfd}
.voucher-approval-card>header{display:flex;align-items:center;justify-content:space-between;gap:5px;color:#34465a;font-size:12px}
.voucher-approval-card>header span{padding:3px 5px;border-radius:4px;background:#eef2f6;color:#738398;font-size:12px}
.approval-signature{display:grid;min-height:42px;place-items:center;border:1px dashed #d8e2eb;border-radius:5px;background:#fff;color:#a1adbb;font-size:12px;font-style:italic}
.voucher-approval-card>label{display:grid;gap:3px;color:#8190a1;font-size:12px}
.voucher-approval-card input{box-sizing:border-box;width:100%;min-width:0;height:29px;padding:5px 6px;border:1px solid #dbe4ed;border-radius:5px;background:#fff;color:#33485c;font:inherit;font-size:12px}
.voucher-approval-card input:focus-visible{outline:2px solid #56c89b;outline-offset:0;border-color:#56c89b}
.approval-signature{position:relative;overflow:hidden;min-height:58px;border:1px solid #dce5ed;border-radius:7px;background:#fff;cursor:crosshair;transition:border-color .16s ease,box-shadow .16s ease}
.approval-signature:hover{border-color:#a7cbbb;box-shadow:0 2px 8px #1c6b5210}
.approval-signature:focus-within{border-color:#50b98f;box-shadow:0 0 0 3px #50b98f1a}
.approval-signature::after{position:absolute;right:12px;bottom:14px;left:12px;border-bottom:1px solid #e8edf2;content:"";pointer-events:none}
.approval-signature canvas{grid-area:1/1;z-index:1;width:100%;height:58px;touch-action:none}
.approval-signature span{grid-area:1/1;z-index:2;align-self:center;color:#9aa7b5;font-size:12px;font-style:normal;pointer-events:none}
.signature-clear{justify-self:end;padding:2px 4px;border:0;background:transparent;color:#71839a;font:inherit;font-size:12px;cursor:pointer}
.signature-clear:hover{color:#b34d5c;text-decoration:underline}
.voucher-expenses{margin-top:14px;padding:0 0 5px;border:1px solid #e7edf3;border-radius:12px;background:#fff;overflow:hidden}
.voucher-expenses-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 16px;border-bottom:1px solid #e9eef3;background:#fbfcfd}
.voucher-expenses-heading>div:first-child{display:grid;gap:3px}
.voucher-expenses-heading strong{color:#26384c;font-size:13px;font-weight:700}
.voucher-expenses-heading strong::before{content:"▦";margin-right:7px;color:#149477}
.voucher-expenses-heading span{color:#8594a6;font-size:12px}
.voucher-expenses .voucher-line-actions{flex:0 0 auto;margin:0}
.voucher-expenses .voucher-line-actions button{min-height:30px;padding:0 11px;border:1px solid #b8f0d6;border-radius:7px;background:#f0fdf7;color:#07805d;font-size:12px;box-shadow:none}
.voucher-expenses .voucher-line-actions button:hover{background:#e1faef}
.voucher-expenses .voucher-line-actions .remove-line-action{border-color:#ffd8dc;background:#fff5f6;color:#dc4b5c}
.voucher-expenses .voucher-line-actions .remove-line-action:hover{background:#ffeaec}
.voucher-table-wrap{padding:0 0 2px}
.voucher-lines{width:100%;min-width:1000px;table-layout:fixed;border-collapse:collapse;font-size:12px}
.voucher-lines col.col-no{width:5%}.voucher-lines col.col-description{width:26%}.voucher-lines col.col-funding{width:10%}.voucher-lines col.col-budget{width:9%}.voucher-lines col.col-amount{width:11%}.voucher-lines col.col-liquidated{width:12%}.voucher-lines col.col-balance{width:10%}.voucher-lines col.col-coa{width:7%}.voucher-lines col.col-debit{width:5%}.voucher-lines col.col-credit{width:5%}
.voucher-lines thead th{height:36px;padding:8px 5px;border-top:0;border-bottom:1px solid #e5ebf1;background:#f6f8fa;color:#53657a;font-size:12px;letter-spacing:.04em}
.voucher-lines thead th:first-child{border-left:0}.voucher-lines thead th:last-child{border-right:0}
.voucher-lines tbody td{height:43px;padding:5px 7px;border-color:#e7edf2}
.voucher-lines tbody td:first-child,.voucher-lines tfoot th:first-child{border-left:0}
.voucher-lines tbody td:last-child,.voucher-lines tfoot th:last-child{border-right:0}
.voucher-lines tbody tr:nth-child(even){background:#fff}
.voucher-lines tbody tr:nth-child(odd){background:#fbfcfd}
.voucher-lines tbody td.line-number{color:#5b7087;font-size:12px}
.voucher-lines input,.voucher-lines textarea{min-height:28px;padding:5px 7px;border:1px solid #e1e8ef;border-radius:4px;background:#fff;color:#35495e;font-size:12px}
.voucher-lines input[type=number]{text-align:right;font-variant-numeric:tabular-nums}
.voucher-lines .calculated-amount{background:#f8fafc;color:#33485d;font-size:12px;font-variant-numeric:tabular-nums}
.voucher-lines tbody td:nth-child(8){border-left:1px solid #e7edf2}
.voucher-lines tfoot th{height:36px;padding:7px;border-top:2px solid #dce5ed;background:#f2f5f8;color:#586b80;font-size:12px}
.voucher-lines tfoot .total-label{text-align:right;font-weight:500;letter-spacing:.025em}
.voucher-lines tfoot th:nth-child(5){background:#eaf8f2;color:#13734f}
.voucher-lines tfoot th:nth-child(6){background:#f4f6f9;color:#314459}
.voucher-lines tfoot th:nth-child(7){background:#eaf8f2;color:#13734f}
.voucher-lines tfoot th:nth-child(8){color:#8997a8;font-size:12px;font-weight:500}
.voucher-parties{grid-template-columns:minmax(1.3fr,1.55fr) minmax(190px,1fr) minmax(190px,1fr);gap:0;padding:16px 14px;border:0;border-radius:0;box-shadow:none}
.voucher-person-fields{display:grid;align-content:start;gap:8px;padding:0 20px 0 0;border-right:1px solid #edf1f5}
.voucher-person-fields::before{content:"♙  REQUESTOR / BENEFICIARY INFORMATION";grid-column:1/-1;margin:0 0 2px;color:#8395aa;font-size:12px;font-weight:700;letter-spacing:.045em}
.voucher-person-fields>label{gap:4px}
.voucher-person-fields label>strong{color:#40566f;font-size:12px;font-weight:500;letter-spacing:0}
.voucher-person-fields input,.voucher-person-fields textarea{min-height:29px;padding:5px 9px;border-color:#dce5ed;border-radius:6px;background:#fff;color:#344a61;font-size:12px}
.voucher-person-fields input::placeholder,.voucher-person-fields textarea::placeholder,.cheque-number input::placeholder{color:#9aa8b7;opacity:1}
.voucher-choice-group{align-content:start;gap:6px;padding:0 20px;border-right:1px solid #edf1f5}
.voucher-choice-group legend{padding-bottom:2px;color:#8294aa;font-size:12px;font-weight:700;letter-spacing:.045em}
.voucher-choice-group legend::before{margin-right:6px;color:#159476}
.voucher-type-group legend::before{content:"☷"}
.voucher-payment-group legend::before{content:"▤"}
.voucher-choice-group label{min-height:28px;padding:4px 8px;border-radius:7px;color:#394e65;font-size:12px}
.voucher-choice-group input[type=radio]{flex-basis:13px;width:13px;height:13px}
.voucher-choice-group input[type=radio]{box-sizing:border-box;flex:0 0 12px;width:12px;height:12px;min-width:12px;padding:0;border-width:1px;border-radius:50%}
.voucher-choice-group input[type=radio]::after{width:4px;height:4px}
.voucher-choice-group input[type=radio]::after{width:5px;height:5px}
.voucher-payment-group{padding-right:0;border-right:0}
.voucher-payment-group .cheque-number{gap:4px;margin-top:2px;color:#7b8da2;font-size:12px}
.cheque-number input{height:28px;padding:5px 8px;border-color:#dce5ed;border-radius:5px;background:#fff;color:#344a61;font-size:12px}
.finance-toolbar-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:nowrap;gap:7px;min-width:0}
.finance-toolbar-actions select,.finance-toolbar-actions button{box-sizing:border-box;align-self:center;height:44px;min-height:44px;margin:0;line-height:1}
.finance-toolbar-actions select,.finance-new-button,.finance-save-button{flex:0 0 auto;padding:0 10px;border:1px solid #d7e2eb;border-radius:7px;background:#fff;color:#40566d;font:inherit;font-size:12px}
.finance-toolbar-actions select{flex:1 1 180px;width:180px;min-width:90px;max-width:230px}
.finance-new-button{cursor:pointer}
.finance-save-button{border-color:#147568;background:#147568;color:#fff;font-weight:700;cursor:pointer}
.finance-save-button:hover:not(:disabled){background:#105f55}
.finance-save-button:disabled{opacity:.65;cursor:wait}
.voucher-message{margin:-7px 0 12px;color:#147568;font-size:12px}
@media(max-width:900px){
  .finance-budget-layout{grid-template-columns:1fr}
  .finance-budget-records{max-height:250px}
  .voucher-workflow-strip{grid-template-columns:1fr auto}
  .voucher-workflow-strip ol{grid-column:1/-1;grid-row:2}
}
@media(max-width:680px){
  .finance-workspace-heading{margin-bottom:16px}
  .finance-workspace-heading h1{font-size:28px}
  .finance-workspace-heading p{font-size:13px}
  .finance-view-nav{overflow-x:auto;margin-bottom:16px}
  .finance-view-nav button{flex:none;padding:0 13px;font-size:13px}
  .finance-card{padding:16px;border-radius:11px}
  .finance-card-heading{gap:8px;margin-bottom:15px;padding-bottom:13px}
  .finance-card-heading h2{font-size:18px}
  .finance-card-heading p{font-size:12px}
  .finance-card-heading .finance-new-button{font-size:12px}
  .finance-budget-fields{grid-template-columns:1fr;gap:12px}
  .finance-budget-fields .is-wide{grid-column:auto}
  .finance-budget-line{grid-template-columns:24px minmax(0,1fr);gap:9px}
  .finance-budget-line label{grid-column:2}
  .finance-line-remove{grid-column:2;justify-self:end}
  .finance-budget-lines-heading{align-items:flex-end}
  .finance-budget-lines-heading p{max-width:25ch;line-height:1.4}
  .finance-budget-workflow{flex-wrap:wrap}
  .finance-budget-workflow>div{min-width:58px}
  .finance-budget-actions{flex-wrap:wrap}
  .finance-budget-actions>*{flex:1}
  .finance-budget-total{gap:10px;padding:12px;font-size:13px}
  .finance-budget-total strong{font-size:14px;white-space:nowrap}
  .finance-report-card{padding:16px}
  .finance-report-summary{grid-template-columns:1fr;gap:8px}
  .finance-report-summary article{padding:12px}
  .finance-report-table{font-size:12px}
  .finance-report-table th,.finance-report-table td{padding:10px}
  .voucher-project-fields{grid-template-columns:1fr;padding:12px}
  .voucher-workflow-strip{grid-template-columns:1fr;gap:12px;padding:12px}
  .voucher-workflow-strip ol{grid-column:auto;grid-row:auto}
  .voucher-workflow-strip>.finance-action-primary,.voucher-workflow-strip>.finance-approved-note{justify-self:stretch;text-align:center}
}
@media(max-width:680px){
  .finance-page-toolbar{align-items:flex-start}
  .finance-toolbar-actions{justify-content:flex-start}
  .finance-toolbar-actions select{max-width:none}
  .finance-toolbar-actions button{flex:0 0 auto;padding-right:8px;padding-left:8px}
  .voucher-expenses-heading{align-items:flex-start;flex-direction:column;padding:12px}
  .voucher-expenses .voucher-line-actions{align-self:flex-end}
  .voucher-lines{min-width:1000px}
}
@media(max-width:680px){
  .finance-page-toolbar h1{font-size:27px}
  .voucher-header h2{font-size:20px}
  .voucher-reference-fields label{font-size:12px}
  .voucher-person-fields input,.voucher-person-fields textarea{font-size:12px}
  .finance-page{padding:12px 8px 28px}
  .finance-paper{padding:11px;border-radius:10px}
  .voucher-header{grid-template-columns:1fr;gap:10px}
  .voucher-brand img{max-height:54px}
  .voucher-reference-fields{max-width:none}
  .voucher-parties{grid-template-columns:1fr;gap:12px;padding:11px}
  .voucher-person-fields{padding:0 0 11px;border-right:0;border-bottom:1px solid #edf1f5}
  .voucher-choice-group{padding:0 0 11px;border-right:0;border-bottom:1px solid #edf1f5}
  .voucher-payment-group{padding-bottom:0;border-bottom:0}
  .voucher-approval-cards{grid-template-columns:repeat(2,minmax(0,1fr))}
  .voucher-approval-card:last-child{grid-column:auto}
  .voucher-approvals-wrap{padding:11px}
  .voucher-parties{gap:12px}
  .voucher-person-fields{padding:0 0 12px;border-right:0;border-bottom:1px solid #edf1f5}
  .voucher-choice-group{padding:0 0 12px;border-right:0;border-bottom:1px solid #edf1f5}
  .voucher-payment-group{padding-bottom:0;border-bottom:0}
}
@media print{
  .finance-page{padding:0}
  .finance-paper{border-radius:0}
  .voucher-parties{overflow:visible}
  .voucher-table-wrap{overflow:visible;border:0;border-radius:0}
  .voucher-approvals-wrap{overflow:visible}
  .voucher-lines{min-width:0}
  .voucher-lines thead th,.voucher-lines tfoot th{background:#fff!important}
}
@media print{
  @page{size:A4 portrait;margin:7mm}
  body *{visibility:hidden!important}
  .finance-page,.finance-page *{visibility:visible!important}
  .finance-page{position:absolute;inset:0;width:100%;max-width:none;margin:0;padding:0;background:#fff}
  .finance-page-toolbar,.finance-toolbar-actions,.voucher-message,.voucher-line-actions{display:none!important}
  .finance-paper{max-width:none;margin:0;padding:0;border:0;box-shadow:none;font-size:7pt}
  .voucher-header{padding-bottom:6px}
  .voucher-brand img{max-height:48px}
  .voucher-header h2{font-size:16pt}
  .voucher-parties{min-height:92px}
  .voucher-lines{font-size:6.5pt}
  .voucher-lines tbody tr{height:25px;break-inside:avoid}
  .voucher-lines thead th,.voucher-lines tfoot th{padding:1px}
  .voucher-lines input,.voucher-lines textarea,.voucher-approval-card input{min-height:0;height:auto;padding:1px;font-size:6pt}
  .voucher-remarks textarea{min-height:42px;resize:none}
  .voucher-approval-cards{grid-template-columns:repeat(5,minmax(0,1fr));gap:3px}
  .voucher-approval-card{padding:3px;break-inside:avoid}
}
</style>
