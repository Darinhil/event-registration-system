<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import organizationLogo from '../assets/LLEE_Cambodia_Inverse_L.png'
import { createFinanceVoucher, getFinanceVoucher, getFinanceVouchers, updateFinanceVoucher } from '../services/financeVoucherService'

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
})
const voucherTypes = ['Cash Advance', 'Adv. Settlement', 'Direct Payment', 'Journal', 'Receipt']
const paymentModes = ['Cash', 'Non-Cash', 'Bank Transfer', 'Bank Cheque']
const approvalRoles = [
  { key: 'checker', label: 'CHECKER', badge: 'Review' },
  { key: 'verifier', label: 'VERIFIER', badge: 'Verify' },
  { key: 'approver', label: 'APPROVER', badge: 'Approve' },
  { key: 'receiver', label: 'RECEIVER', badge: 'Receive' },
  { key: 'posting-agent', label: 'POSTING AGENT', badge: 'Post' },
]
const approvals = reactive(Object.fromEntries(approvalRoles.map(({ key }) => [key, { name: '', position: '', date: '', signature: '' }])))
const signatureCanvases = new Map()
const activeSignatureKey = ref(null)
const savedVouchers = ref([])
const currentVoucherId = ref(null)
const selectedVoucherId = ref('')
const savingVoucher = ref(false)
const voucherMessage = ref('')
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
const lines = reactive(Array.from({ length: 14 }, createLine))
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
  } catch (error) {
    voucherMessage.value = error.response?.data?.message || 'Could not save voucher. Check your connection and try again.'
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
    for (const key of ['number', 'date', 'advanceNumber', 'name', 'position', 'purpose', 'signatureDate', 'voucherType', 'paymentMode', 'chequeNumber', 'remarks']) {
      voucher[key] = payload[key] ?? ''
    }
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
  })
  lines.splice(0, lines.length, ...Array.from({ length: 14 }, createLine))
  for (const { key } of approvalRoles) {
    Object.assign(approvals[key], { name: '', position: '', date: '', signature: '' })
    clearSignature(key)
  }
  voucherMessage.value = ''
}
onMounted(refreshSavedVouchers)
</script>

<template>
  <section class="finance-page">
    <header class="finance-page-toolbar">
      <div>
        <span class="finance-kicker">FINANCE MANAGEMENT</span>
        <h1>General Voucher</h1>
        <p>Complete the voucher details, then print a copy for approval and posting.</p>
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
        <h2>GENERAL VOUCHER</h2>
        <div class="voucher-reference-fields">
          <label>Voucher No:<input v-model.trim="voucher.number" aria-label="Voucher number" /></label>
          <label>Voucher Date:<input v-model="voucher.date" type="date" aria-label="Voucher date" /></label>
          <label>Adv. No:<input v-model.trim="voucher.advanceNumber" aria-label="Advance number" /></label>
        </div>
      </header>

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
  </section>
</template>

<style scoped>
.finance-page{max-width:1120px;margin:0 auto;padding:18px 14px 52px;color:#17202c}
.finance-page-toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 16px}
.finance-kicker{color:#648079;font-size:10px;font-weight:750;letter-spacing:.14em}
.finance-page-toolbar h1{margin:4px 0;color:#1b3044;font-size:25px}
.finance-page-toolbar p{margin:0;color:#718092;font-size:13px}
.finance-print-button,.voucher-line-actions button{min-height:38px;padding:0 15px;border:1px solid #176e64;border-radius:7px;background:#167568;color:#fff;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.finance-paper{max-width:900px;margin:0 auto;padding:18px;background:#fff;border:1px solid #d7dde2;box-shadow:0 8px 30px #1e334012;font-family:Arial,Helvetica,sans-serif;font-size:10px}
.voucher-header{display:grid;grid-template-columns:1fr 1fr 1fr;align-items:start;gap:10px;padding:0 2px 10px}
.voucher-brand{display:flex;align-items:center;padding-top:4px}
.voucher-brand img{display:block;width:min(100%,220px);max-height:60px;object-fit:contain;object-position:left center}
.voucher-header h2{margin:3px 0;text-align:center;font-size:19px;white-space:nowrap}
.voucher-reference-fields{display:grid;gap:4px}
.voucher-reference-fields label{display:grid;grid-template-columns:auto minmax(65px,1fr);align-items:center;gap:6px;font-size:8px;font-weight:700}
.voucher-reference-fields input{min-width:0;height:18px;padding:0 2px;border:0;border-bottom:1px solid #222;border-radius:0;font:inherit}
.voucher-parties{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(120px,.85fr) minmax(120px,.9fr);min-height:105px;border:2px solid #111}
.voucher-person-fields{display:grid;grid-template-rows:repeat(3,minmax(20px,1fr)) 28px;border-right:2px solid #111}
.voucher-person-fields>label{display:grid;grid-template-columns:76px minmax(0,1fr);align-items:stretch;border-bottom:1px solid #999}
.voucher-person-fields>label:last-child{border-bottom:0}
.voucher-person-fields label>strong{display:flex;align-items:center;padding:2px 4px;border-right:1px solid #222;font-size:8px}
.voucher-person-fields input,.voucher-person-fields textarea{width:100%;min-width:0;resize:none;border:0;border-radius:0;padding:3px;font:inherit}
.voucher-person-fields .voucher-signature-field{grid-template-columns:76px minmax(25px,1fr) 30px 75px}
.voucher-signature-field span{border-right:1px solid #999}
.voucher-choice-group{display:grid;grid-template-columns:34px minmax(0,1fr);align-content:start;gap:3px 5px;margin:0;padding:4px 5px;border:0;border-right:2px solid #111}
.voucher-choice-group legend{float:left;grid-row:1 / span 5;align-self:center;padding:0 3px;writing-mode:vertical-rl;transform:rotate(180deg);text-align:center;font-size:8px;font-weight:800}
.voucher-choice-group legend small{font-size:7px;font-weight:400}
.voucher-choice-group label{display:flex;align-items:center;gap:5px;min-height:14px;font-size:8px}
.voucher-choice-group input[type=radio]{display:grid;width:13px;height:13px;margin:0;appearance:none;place-content:center;border:1px solid #222;border-radius:1px;background:#fff}
.voucher-choice-group input[type=radio]::after{content:"X";font-size:9px;font-weight:800;opacity:0}
.voucher-choice-group input[type=radio]:checked::after{opacity:1}
.voucher-payment-group{border-right:0}
.voucher-payment-group legend{grid-row:1 / span 5}
.voucher-payment-group .cheque-number{grid-column:2;display:flex;gap:4px;white-space:nowrap}
.cheque-number input{width:80px;min-width:0;border:0;border-bottom:1px solid #444;border-radius:0}
.voucher-table-wrap{overflow-x:auto}
.voucher-lines,.voucher-approvals{width:100%;border-collapse:collapse;table-layout:fixed}
.voucher-lines{margin-top:3px;font-size:8px}
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
.voucher-line-actions button{min-height:27px;padding:0 10px;font-size:9px}
.voucher-line-actions .remove-line-action{border-color:#b7c1ca;background:#fff;color:#52616f}
.voucher-remarks{display:grid;grid-template-rows:16px minmax(45px,auto);border:2px solid #111}
.voucher-remarks strong{padding:2px 5px}
.voucher-remarks textarea{width:100%;min-height:45px;resize:vertical;padding:4px;border:0;border-top:1px solid #888;border-radius:0;font:inherit}
.voucher-approvals{margin-top:3px;font-size:8px}
.voucher-approvals th,.voucher-approvals td{height:23px;padding:2px 4px}
.voucher-approvals thead th{text-align:center}
.voucher-approvals thead th:not(.approval-label-spacer){height:18px}
.voucher-approvals .approval-label-spacer,.voucher-approvals tbody th{width:9%;text-align:left}
.voucher-approvals input{min-height:19px}
@media(max-width:680px){.finance-page{padding:12px 7px 35px}.finance-page-toolbar{align-items:flex-start;flex-direction:column}.finance-page-toolbar h1{font-size:22px}.finance-paper{padding:10px}.voucher-header{grid-template-columns:1fr 1fr}.voucher-header h2{grid-column:1/-1;grid-row:1;text-align:right;font-size:16px}.voucher-brand{grid-row:2}.voucher-reference-fields{grid-row:2}.voucher-parties{grid-template-columns:minmax(0,1.2fr) minmax(100px,.8fr) minmax(110px,.9fr)}.voucher-person-fields>label{grid-template-columns:57px minmax(0,1fr)}.voucher-person-fields .voucher-signature-field{grid-template-columns:57px minmax(12px,1fr) 26px 62px}.voucher-choice-group{grid-template-columns:25px minmax(0,1fr);gap:2px}.voucher-choice-group legend{font-size:7px}.voucher-choice-group label{font-size:7px}.voucher-lines{min-width:620px}.voucher-approvals{font-size:7px}}
.finance-page{max-width:1360px;padding:28px clamp(16px,3vw,40px) 56px;color:#172a3a}
.finance-page-toolbar{align-items:center;margin-bottom:22px}
.finance-page-toolbar h1{margin:6px 0 5px;color:#172a3a;font-size:clamp(27px,3vw,34px);font-weight:750;letter-spacing:-.035em}
.finance-page-toolbar p{color:#65758a;font-size:14px}
.finance-kicker{display:inline-flex;align-items:center;gap:8px;color:#167568;font-size:11px;letter-spacing:.13em}
.finance-kicker::before{width:8px;height:8px;border-radius:50%;background:#20a58d;content:""}
.finance-print-button,.voucher-line-actions button{min-height:42px;padding:0 18px;border-color:#12695e;border-radius:9px;background:#147568;box-shadow:0 3px 8px #16756824;font-size:13px;transition:background .18s ease,transform .18s ease,box-shadow .18s ease}
.finance-print-button:hover,.voucher-line-actions button:hover{transform:translateY(-1px);background:#105f55;box-shadow:0 6px 14px #16756832}
.finance-paper{max-width:1180px;padding:clamp(18px,2.4vw,32px);border:1px solid #dce5eb;border-radius:16px;box-shadow:0 16px 42px #19344a12;font-size:11px}
.voucher-header{align-items:center;gap:18px;padding:0 4px 18px}
.voucher-brand img{width:min(100%,250px);max-height:76px}
.voucher-header h2{color:#1b2d3f;font-size:clamp(20px,2.3vw,27px);font-weight:700;letter-spacing:.025em}
.voucher-reference-fields{gap:7px}
.voucher-reference-fields label{grid-template-columns:90px minmax(80px,1fr);gap:8px;color:#32475b;font-size:10px}
.voucher-reference-fields input{height:25px;border-color:#9baab7;font-size:11px}
.voucher-parties{grid-template-columns:minmax(0,1.45fr) minmax(190px,.88fr) minmax(190px,.9fr);min-height:148px;border-color:#253747;border-radius:7px;overflow:hidden}
.voucher-person-fields{grid-template-rows:repeat(3,minmax(32px,1fr)) 38px;border-color:#253747}
.voucher-person-fields>label{border-color:#d7e0e7}
.voucher-person-fields label>strong{padding:5px 9px;border-color:#d7e0e7;background:#f7f9fb;color:#34495d;font-size:9px;letter-spacing:.045em}
.voucher-person-fields input,.voucher-person-fields textarea{padding:6px 8px;color:#172a3a;font:inherit}
.voucher-choice-group{grid-template-columns:42px minmax(0,1fr);align-content:center;gap:5px 8px;padding:10px;border-color:#d7e0e7}
.voucher-choice-group legend{font-size:9px;line-height:1.35}
.voucher-choice-group legend small{font-size:8px}
.voucher-choice-group label{min-height:24px;gap:8px;color:#34495d;font-size:10px}
.voucher-choice-group input[type=radio]{width:18px;height:18px;border-color:#9aabb8;border-radius:4px}
.voucher-choice-group input[type=radio]:checked{border-color:#167568;background:#e4f5f0;color:#12695e}
.voucher-choice-group input[type=radio]::after{font-size:11px}
.voucher-payment-group .cheque-number{margin-top:4px}
.cheque-number input{height:22px;padding:2px 4px;border-color:#9aabb8}
.voucher-table-wrap{margin-top:8px;border:1px solid #d9e2e9;border-radius:8px}
.voucher-lines,.voucher-approvals{color:#263b4d}
.voucher-lines{min-width:900px;margin:0;font-size:10px}
.voucher-lines th,.voucher-lines td,.voucher-approvals th,.voucher-approvals td{border-color:#cbd6df}
.voucher-lines thead th{height:42px;padding:7px 5px;background:#f1f6fa;color:#345d83;font-size:10px;line-height:1.3}
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
.voucher-line-actions button{min-height:34px;padding:0 13px;font-size:11px}
.voucher-line-actions .remove-line-action{box-shadow:none}
.voucher-line-actions .remove-line-action:hover{border-color:#aab8c4;background:#f5f8fa;color:#34495d}
.voucher-remarks{grid-template-rows:28px minmax(62px,auto);border-color:#536678;border-radius:6px;overflow:hidden}
.voucher-remarks strong{display:flex;align-items:center;padding:4px 9px;background:#f1f6fa;color:#34495d;font-size:9px;letter-spacing:.05em}
.voucher-remarks textarea{min-height:62px;padding:8px;border-color:#d7e0e7;color:#172a3a}
.voucher-approvals{margin-top:10px;font-size:9px}
.voucher-approvals th,.voucher-approvals td{height:32px;padding:5px 7px}
.voucher-approvals thead th{background:#f1f6fa;color:#345d83}
.voucher-approvals .approval-label-spacer,.voucher-approvals tbody th{color:#34495d}
.voucher-approvals input{min-height:27px;padding:4px;color:#172a3a}
.voucher-approvals tbody tr:last-child input{height:28px;padding:4px 6px;border:1px solid #d3dee7;border-radius:6px;background:#f8fafc;color:#30485d;font-size:9px;letter-spacing:.015em}
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
  .voucher-reference-fields label{grid-template-columns:1fr;gap:1px;font-size:9px}
  .voucher-reference-fields input{height:22px}
  .voucher-parties{grid-template-columns:1fr}
  .voucher-person-fields{grid-column:auto;grid-template-rows:repeat(3,minmax(36px,1fr)) 38px}
  .voucher-person-fields>label{grid-template-columns:70px minmax(0,1fr)}
  .voucher-person-fields .voucher-signature-field{grid-template-columns:70px minmax(20px,1fr) 32px 86px}
  .voucher-choice-group{grid-template-columns:38px minmax(0,1fr);min-height:0;border-right:0;border-bottom:1px solid #d7e0e7}
  .voucher-choice-group label{font-size:10px}
  .voucher-choice-group legend{font-size:9px}
  .voucher-payment-group{border-bottom:0}
  .voucher-lines{min-width:900px}
  .voucher-approvals{min-width:620px}
  .voucher-approvals-wrap{overflow-x:auto}
  .finance-page{padding:18px 10px 34px;background:transparent}
  .finance-page-toolbar{gap:10px;margin-bottom:16px}
  .finance-page-toolbar p{max-width:34ch;line-height:1.5}
  .finance-paper{padding:16px 12px;border:1px solid #d5e0e8;border-radius:14px}
  .voucher-table-wrap{margin:10px -2px 0;border-radius:9px}
  .voucher-lines thead th{height:46px;padding:8px 6px;font-size:11px}
  .voucher-lines tbody tr{height:44px}
  .voucher-lines input,.voucher-lines textarea{min-height:38px;padding:6px}
  .voucher-lines .line-number{font-size:11px}
  .voucher-approvals-wrap{padding-bottom:5px}
  .voucher-approvals{font-size:10px}
  .voucher-approvals th,.voucher-approvals td{height:38px;padding:6px 7px}
  .voucher-approvals tbody tr:last-child input{height:32px;font-size:10px}
}
.finance-page-toolbar h1{font-size:clamp(30px,3.2vw,38px);letter-spacing:-.04em}
.finance-page-toolbar p{font-size:14px;line-height:1.5}
.finance-paper{font-size:12px}
.voucher-header h2{font-size:clamp(22px,2.5vw,28px)}
.voucher-reference-fields label{font-size:11px}
.voucher-reference-fields input{height:29px;font-size:12px}
.voucher-person-fields label>strong{font-size:10px}
.voucher-person-fields input,.voucher-person-fields textarea{font-size:12px}
.voucher-choice-group label{font-size:11px}
.voucher-choice-group legend{font-size:10px}
.voucher-lines{font-size:11px}
.voucher-lines thead th{height:48px;padding:8px 6px;font-size:11px}
.voucher-lines tbody tr{height:44px}
.voucher-lines input,.voucher-lines textarea{min-height:38px;padding:6px;font-size:11px}
.voucher-lines .line-number{font-size:11px}
.voucher-lines tfoot th{height:38px;font-size:11px}
.voucher-line-actions button{min-height:38px;font-size:12px}
.voucher-remarks strong{font-size:10px}
.voucher-approvals{font-size:10px}
.voucher-approvals th,.voucher-approvals td{height:38px}
.voucher-approvals tbody tr:last-child input{height:32px;font-size:10px}
.finance-page{max-width:1440px;padding:24px clamp(12px,2vw,28px) 48px;background:transparent;color:#26364a}
.finance-page-toolbar{margin-bottom:16px}
.finance-page-toolbar h1{font-size:28px}
.finance-page-toolbar p{font-size:12px}
.finance-paper{max-width:none;padding:18px;border:1px solid #e4ebf1;border-radius:14px;box-shadow:0 8px 28px #18334a0b}
.voucher-header{grid-template-columns:minmax(0,1fr) 260px;gap:20px;align-items:center;padding:0 4px 16px;border-bottom:1px solid #edf1f5}
.voucher-header h2{display:none}
.voucher-brand img{width:min(100%,290px);max-height:72px}
.voucher-reference-fields{gap:7px;padding:10px;border:1px solid #e5ecf3;border-radius:9px;background:#f8fafc}
.voucher-reference-fields::before{content:"DOCUMENT TYPE\A GENERAL VOUCHER";white-space:pre-line;text-align:right;color:#29374a;font-size:11px;font-weight:750;line-height:1.6}
.voucher-reference-fields label{grid-template-columns:78px minmax(0,1fr);font-size:10px;font-weight:500;color:#6b7c91}
.voucher-reference-fields input{height:24px;padding:0 6px;border:1px solid #dbe4ed;border-radius:5px;background:#fff;text-align:right;font-size:10px}
.voucher-parties{grid-template-columns:minmax(1.25fr,1.5fr) minmax(180px,1fr) minmax(180px,1fr);gap:12px;min-height:0;margin:14px 0;padding:14px;border:1px solid #e7edf3;border-radius:10px;background:#fff}
.voucher-person-fields{grid-template-rows:repeat(3,minmax(0,auto));gap:9px;padding-right:12px;border:0;border-right:1px solid #edf1f5}
.voucher-person-fields>label{display:flex;flex-direction:column;gap:4px;border:0}
.voucher-person-fields label>strong{padding:0;border:0;background:transparent;color:#6d8095;font-size:9px;letter-spacing:.04em}
.voucher-person-fields input,.voucher-person-fields textarea{min-height:34px;padding:7px 9px;border:1px solid #dbe4ed;border-radius:6px;background:#fff;font-size:11px}
.voucher-person-fields .voucher-signature-field{display:none}
.voucher-choice-group{display:flex;flex-direction:column;gap:6px;min-width:0;padding:0 12px;border:0;border-right:1px solid #edf1f5}
.voucher-choice-group legend{float:none;grid-row:auto;align-self:auto;padding:0 0 2px;writing-mode:horizontal-tb;transform:none;text-align:left;color:#6d8095;font-size:9px;letter-spacing:.06em}
.voucher-choice-group legend br,.voucher-choice-group legend small{display:none}
.voucher-choice-group label{min-height:31px;padding:5px 8px;border:1px solid #e5ebf1;border-radius:7px;background:#fff;font-size:10px}
.voucher-choice-group label:has(input:checked){border-color:#74d6b2;background:#effcf6;color:#176c53}
.voucher-choice-group input[type=radio]{flex:0 0 14px;width:14px;height:14px;border:1px solid #c6d3df;border-radius:50%;background:#fff}
.voucher-choice-group input[type=radio]::after{content:"";width:6px;height:6px;border-radius:50%;background:#13946b}
.voucher-payment-group{padding-right:0}
.voucher-payment-group .cheque-number{display:flex;flex-direction:column;align-items:stretch;gap:4px;margin-top:1px;font-size:9px;color:#708197}
.cheque-number input{box-sizing:border-box;width:100%;height:30px;padding:5px 8px;border:1px solid #dbe4ed;border-radius:6px}
.voucher-table-wrap{margin-top:0;border:0;border-radius:8px;box-shadow:none}
.voucher-lines{min-width:860px;font-size:10px}
.voucher-lines thead th{height:36px;padding:7px 5px;background:#f4f7fa;color:#5b6d82;font-size:9px;text-transform:uppercase;letter-spacing:.035em}
.voucher-lines thead tr:first-child th{border-top:0}
.voucher-lines tbody tr{height:40px}
.voucher-lines tbody td{padding:2px 4px}
.voucher-lines input,.voucher-lines textarea{min-height:29px;padding:4px 6px;border:1px solid #e1e8ef;border-radius:4px;background:#fff;font-size:9px}
.voucher-lines input:focus-visible,.voucher-lines textarea:focus-visible{outline:2px solid #56c89b;outline-offset:0;border-color:#56c89b}
.voucher-lines .calculated-amount{padding:0 6px;background:#f8fafc;color:#32495e}
.voucher-lines tfoot th{height:36px;padding:5px;background:#f2f5f8;color:#596c80;font-size:9px}
.voucher-line-actions{justify-content:flex-end;margin:9px 0 12px}
.voucher-line-actions button{min-height:31px;padding:0 11px;border-radius:6px;font-size:10px}
.voucher-line-actions .remove-line-action{border-color:#f0d8dc;background:#fff7f8;color:#b24d5c}
.voucher-remarks{grid-template-rows:24px minmax(54px,auto);margin-top:12px;border:1px solid #e5ebf1;border-radius:9px}
.voucher-remarks strong{padding:5px 9px;background:#f8fafc;color:#64768b;font-size:9px}
.voucher-remarks textarea{min-height:54px;padding:9px;border-top:1px solid #edf1f5;font-size:10px}
.voucher-approvals-wrap{margin-top:14px;padding:14px;border:1px solid #e6edf3;border-radius:10px;background:#fff}
.approval-section-heading{display:grid;gap:3px;margin-bottom:12px}
.approval-section-heading strong{color:#35495d;font-size:10px;letter-spacing:.04em}
.approval-section-heading span{color:#91a0b0;font-size:9px}
.voucher-approval-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.voucher-approval-card{display:grid;gap:7px;min-width:0;padding:9px;border:1px solid #e6edf3;border-radius:8px;background:#fbfcfd}
.voucher-approval-card>header{display:flex;align-items:center;justify-content:space-between;gap:5px;color:#34465a;font-size:9px}
.voucher-approval-card>header span{padding:3px 5px;border-radius:4px;background:#eef2f6;color:#738398;font-size:8px}
.approval-signature{display:grid;min-height:42px;place-items:center;border:1px dashed #d8e2eb;border-radius:5px;background:#fff;color:#a1adbb;font-size:9px;font-style:italic}
.voucher-approval-card>label{display:grid;gap:3px;color:#8190a1;font-size:8px}
.voucher-approval-card input{box-sizing:border-box;width:100%;min-width:0;height:29px;padding:5px 6px;border:1px solid #dbe4ed;border-radius:5px;background:#fff;color:#33485c;font:inherit;font-size:9px}
.voucher-approval-card input:focus-visible{outline:2px solid #56c89b;outline-offset:0;border-color:#56c89b}
.approval-signature{position:relative;overflow:hidden;min-height:48px;cursor:crosshair}
.approval-signature canvas{grid-area:1/1;width:100%;height:48px;touch-action:none}
.approval-signature span{grid-area:1/1;z-index:1;pointer-events:none}
.signature-clear{justify-self:end;padding:2px 4px;border:0;background:transparent;color:#71839a;font:inherit;font-size:8px;cursor:pointer}
.signature-clear:hover{color:#b34d5c;text-decoration:underline}
.voucher-expenses{margin-top:14px;padding:0 0 5px;border:1px solid #e7edf3;border-radius:12px;background:#fff;overflow:hidden}
.voucher-expenses-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 16px;border-bottom:1px solid #e9eef3;background:#fbfcfd}
.voucher-expenses-heading>div:first-child{display:grid;gap:3px}
.voucher-expenses-heading strong{color:#26384c;font-size:13px;font-weight:700}
.voucher-expenses-heading strong::before{content:"▦";margin-right:7px;color:#149477}
.voucher-expenses-heading span{color:#8594a6;font-size:10px}
.voucher-expenses .voucher-line-actions{flex:0 0 auto;margin:0}
.voucher-expenses .voucher-line-actions button{min-height:30px;padding:0 11px;border:1px solid #b8f0d6;border-radius:7px;background:#f0fdf7;color:#07805d;font-size:10px;box-shadow:none}
.voucher-expenses .voucher-line-actions button:hover{background:#e1faef}
.voucher-expenses .voucher-line-actions .remove-line-action{border-color:#ffd8dc;background:#fff5f6;color:#dc4b5c}
.voucher-expenses .voucher-line-actions .remove-line-action:hover{background:#ffeaec}
.voucher-table-wrap{padding:0 0 2px}
.voucher-lines{width:100%;min-width:1000px;table-layout:fixed;border-collapse:collapse;font-size:10px}
.voucher-lines col.col-no{width:5%}.voucher-lines col.col-description{width:26%}.voucher-lines col.col-funding{width:10%}.voucher-lines col.col-budget{width:9%}.voucher-lines col.col-amount{width:11%}.voucher-lines col.col-liquidated{width:12%}.voucher-lines col.col-balance{width:10%}.voucher-lines col.col-coa{width:7%}.voucher-lines col.col-debit{width:5%}.voucher-lines col.col-credit{width:5%}
.voucher-lines thead th{height:36px;padding:8px 5px;border-top:0;border-bottom:1px solid #e5ebf1;background:#f6f8fa;color:#53657a;font-size:9px;letter-spacing:.04em}
.voucher-lines thead th:first-child{border-left:0}.voucher-lines thead th:last-child{border-right:0}
.voucher-lines tbody td{height:43px;padding:5px 7px;border-color:#e7edf2}
.voucher-lines tbody td:first-child,.voucher-lines tfoot th:first-child{border-left:0}
.voucher-lines tbody td:last-child,.voucher-lines tfoot th:last-child{border-right:0}
.voucher-lines tbody tr:nth-child(even){background:#fff}
.voucher-lines tbody tr:nth-child(odd){background:#fbfcfd}
.voucher-lines tbody td.line-number{color:#5b7087;font-size:10px}
.voucher-lines input,.voucher-lines textarea{min-height:28px;padding:5px 7px;border:1px solid #e1e8ef;border-radius:4px;background:#fff;color:#35495e;font-size:9px}
.voucher-lines input[type=number]{text-align:right;font-variant-numeric:tabular-nums}
.voucher-lines .calculated-amount{background:#f8fafc;color:#33485d;font-size:9px;font-variant-numeric:tabular-nums}
.voucher-lines tbody td:nth-child(8){border-left:1px solid #e7edf2}
.voucher-lines tfoot th{height:36px;padding:7px;border-top:2px solid #dce5ed;background:#f2f5f8;color:#586b80;font-size:9px}
.voucher-lines tfoot .total-label{text-align:right;font-weight:500;letter-spacing:.025em}
.voucher-lines tfoot th:nth-child(5){background:#eaf8f2;color:#13734f}
.voucher-lines tfoot th:nth-child(6){background:#f4f6f9;color:#314459}
.voucher-lines tfoot th:nth-child(7){background:#eaf8f2;color:#13734f}
.voucher-lines tfoot th:nth-child(8){color:#8997a8;font-size:8px;font-weight:500}
.voucher-parties{grid-template-columns:minmax(1.3fr,1.55fr) minmax(190px,1fr) minmax(190px,1fr);gap:0;padding:16px 14px;border:0;border-radius:0;box-shadow:none}
.voucher-person-fields{display:grid;align-content:start;gap:8px;padding:0 20px 0 0;border-right:1px solid #edf1f5}
.voucher-person-fields::before{content:"♙  REQUESTOR / BENEFICIARY INFORMATION";grid-column:1/-1;margin:0 0 2px;color:#8395aa;font-size:9px;font-weight:700;letter-spacing:.045em}
.voucher-person-fields>label{gap:4px}
.voucher-person-fields label>strong{color:#40566f;font-size:9px;font-weight:500;letter-spacing:0}
.voucher-person-fields input,.voucher-person-fields textarea{min-height:29px;padding:5px 9px;border-color:#dce5ed;border-radius:6px;background:#fff;color:#344a61;font-size:10px}
.voucher-person-fields input::placeholder,.voucher-person-fields textarea::placeholder,.cheque-number input::placeholder{color:#9aa8b7;opacity:1}
.voucher-choice-group{align-content:start;gap:6px;padding:0 20px;border-right:1px solid #edf1f5}
.voucher-choice-group legend{padding-bottom:2px;color:#8294aa;font-size:9px;font-weight:700;letter-spacing:.045em}
.voucher-choice-group legend::before{margin-right:6px;color:#159476}
.voucher-type-group legend::before{content:"☷"}
.voucher-payment-group legend::before{content:"▤"}
.voucher-choice-group label{min-height:28px;padding:4px 8px;border-radius:7px;color:#394e65;font-size:9px}
.voucher-choice-group input[type=radio]{flex-basis:13px;width:13px;height:13px}
.voucher-choice-group input[type=radio]{box-sizing:border-box;flex:0 0 12px;width:12px;height:12px;min-width:12px;padding:0;border-width:1px;border-radius:50%}
.voucher-choice-group input[type=radio]::after{width:4px;height:4px}
.voucher-choice-group input[type=radio]::after{width:5px;height:5px}
.voucher-payment-group{padding-right:0;border-right:0}
.voucher-payment-group .cheque-number{gap:4px;margin-top:2px;color:#7b8da2;font-size:9px}
.cheque-number input{height:28px;padding:5px 8px;border-color:#dce5ed;border-radius:5px;background:#fff;color:#344a61;font-size:10px}
.finance-toolbar-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:nowrap;gap:7px;min-width:0}
.finance-toolbar-actions select,.finance-toolbar-actions button{box-sizing:border-box;align-self:center;height:44px;min-height:44px;margin:0;line-height:1}
.finance-toolbar-actions select,.finance-new-button,.finance-save-button{flex:0 0 auto;padding:0 10px;border:1px solid #d7e2eb;border-radius:7px;background:#fff;color:#40566d;font:inherit;font-size:11px}
.finance-toolbar-actions select{flex:1 1 180px;width:180px;min-width:90px;max-width:230px}
.finance-new-button{cursor:pointer}
.finance-save-button{border-color:#147568;background:#147568;color:#fff;font-weight:700;cursor:pointer}
.finance-save-button:hover:not(:disabled){background:#105f55}
.finance-save-button:disabled{opacity:.65;cursor:wait}
.voucher-message{margin:-7px 0 12px;color:#147568;font-size:12px}
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
  .voucher-reference-fields label{font-size:10px}
  .voucher-person-fields input,.voucher-person-fields textarea{font-size:11px}
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
  .voucher-approval-card:last-child{grid-column:1/-1}
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
