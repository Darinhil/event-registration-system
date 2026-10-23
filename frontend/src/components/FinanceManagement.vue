<script setup>
import { computed, reactive } from 'vue'
import organizationLogo from '../assets/LLEE_Cambodia_Inverse_L.png'

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
</script>

<template>
  <section class="finance-page">
    <header class="finance-page-toolbar">
      <div>
        <span class="finance-kicker">FINANCE MANAGEMENT</span>
        <h1>General Voucher</h1>
        <p>Complete the voucher details, then print a copy for approval and posting.</p>
      </div>
      <button type="button" class="finance-print-button" @click="printVoucher">Print voucher</button>
    </header>

    <form class="finance-paper" @submit.prevent>
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
          <label><strong>NAME</strong><input v-model.trim="voucher.name" /></label>
          <label><strong>POSITION</strong><input v-model.trim="voucher.position" /></label>
          <label><strong>PURPOSE</strong><textarea v-model.trim="voucher.purpose" rows="2"></textarea></label>
          <label class="voucher-signature-field"><strong>SIGNATURE</strong><span></span><strong>DATE</strong><input v-model="voucher.signatureDate" type="date" /></label>
        </div>
        <fieldset class="voucher-choice-group voucher-type-group">
          <legend>TYPE OF VOUCHER<br /><small>(Please use X)</small></legend>
          <label v-for="type in voucherTypes" :key="type"><input v-model="voucher.voucherType" type="radio" name="voucher-type" :value="type" />{{ type }}</label>
        </fieldset>
        <fieldset class="voucher-choice-group voucher-payment-group">
          <legend>MODE OF PAYMENT<br /><small>(Please use X)</small></legend>
          <label v-for="mode in paymentModes" :key="mode"><input v-model="voucher.paymentMode" type="radio" name="payment-mode" :value="mode" />{{ mode }}</label>
          <label class="cheque-number">Cheque #: <input v-model.trim="voucher.chequeNumber" /></label>
        </fieldset>
      </section>

      <div class="voucher-table-wrap">
        <table class="voucher-lines">
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
              <th colspan="4" class="total-label">TOTAL AMOUNT</th>
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

      <div class="voucher-line-actions">
        <button type="button" @click="addLine">+ Add line</button>
        <button v-if="lines.length > 1" type="button" class="remove-line-action" @click="removeLine(lines[lines.length - 1].id)">Remove last line</button>
      </div>

      <label class="voucher-remarks"><strong>REMARKS</strong><textarea v-model.trim="voucher.remarks" rows="3"></textarea></label>

      <div class="voucher-approvals-wrap">
        <table class="voucher-approvals">
          <thead>
            <tr><th rowspan="2" class="approval-label-spacer"></th><th>CHECKER</th><th>VERIFIER</th><th>APPROVER</th><th>RECEIVER</th><th>POSTING AGENT</th></tr>
          </thead>
          <tbody>
            <tr><th>SIGNATURE</th><td></td><td></td><td></td><td></td><td></td></tr>
            <tr><th>NAME</th><td><input aria-label="Checker name" /></td><td><input aria-label="Verifier name" /></td><td><input aria-label="Approver name" /></td><td><input aria-label="Receiver name" /></td><td><input aria-label="Posting agent name" /></td></tr>
            <tr><th>POSITION</th><td><input aria-label="Checker position" /></td><td><input aria-label="Verifier position" /></td><td><input aria-label="Approver position" /></td><td><input aria-label="Receiver position" /></td><td><input aria-label="Posting agent position" /></td></tr>
            <tr><th>DATE</th><td><input type="date" aria-label="Checker date" /></td><td><input type="date" aria-label="Verifier date" /></td><td><input type="date" aria-label="Approver date" /></td><td><input type="date" aria-label="Receiver date" /></td><td><input type="date" aria-label="Posting agent date" /></td></tr>
          </tbody>
        </table>
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
}
@media print{
  .finance-page{padding:0}
  .finance-paper{border-radius:0}
  .voucher-parties{overflow:visible}
  .voucher-table-wrap{overflow:visible;border:0;border-radius:0}
  .voucher-approvals-wrap{overflow:visible}
  .voucher-lines,.voucher-approvals{min-width:0}
  .voucher-lines thead th,.voucher-lines tfoot th,.voucher-approvals thead th{background:#fff!important}
}
@media print{
  @page{size:A4 portrait;margin:7mm}
  body *{visibility:hidden!important}
  .finance-page,.finance-page *{visibility:visible!important}
  .finance-page{position:absolute;inset:0;width:100%;max-width:none;margin:0;padding:0;background:#fff}
  .finance-page-toolbar,.voucher-line-actions{display:none!important}
  .finance-paper{max-width:none;margin:0;padding:0;border:0;box-shadow:none;font-size:7pt}
  .voucher-header{padding-bottom:6px}
  .voucher-brand img{max-height:48px}
  .voucher-header h2{font-size:16pt}
  .voucher-parties{min-height:92px}
  .voucher-lines{font-size:6.5pt}
  .voucher-lines tbody tr{height:25px;break-inside:avoid}
  .voucher-lines thead th,.voucher-lines tfoot th{padding:1px}
  .voucher-lines input,.voucher-lines textarea,.voucher-approvals input{min-height:0;padding:1px;font-size:6pt}
  .voucher-remarks textarea{min-height:42px;resize:none}
  .voucher-approvals{font-size:6.5pt;break-inside:avoid}
  .voucher-approvals th,.voucher-approvals td{height:22px;padding:1px 3px}
}
</style>
