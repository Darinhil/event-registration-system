<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import { createProcurementDocument, getProcurementDocuments, transitionProcurementDocument } from '../../services/procurementService'

const tabs = [
  { id: 'inventory_record', label: 'Inventory Record', icon: 'list', prefix: 'INV' },
  { id: 'stationary_record', label: 'Stationary Record', icon: 'file-text', prefix: 'STN' },
  { id: 'disposal_record', label: 'Disposal Record', icon: 'trash', prefix: 'DSP' },
  { id: 'asset_custodian', label: 'Asset Custodian', icon: 'user-check', prefix: 'CUS' },
  { id: 'asset_transfer', label: 'Asset Transfer', icon: 'arrow-right', prefix: 'TRF' },
]
const stationeryKinds = [
  { id: 'transfer', label: 'Stationery transfer list' },
  { id: 'detailed_list', label: 'Furniture & stationery list' },
  { id: 'summary', label: 'Summary of furniture & stationery' },
]
const disposalMethods = ['Scrapped', 'Sold', 'Gifted']
const assetUsePurpose = 'All asset shall be used only for LLC business and with regard to;\n• any manufacturer instructions provided\n• the purpose for which the equipment is intended\n• safety of the operator\n• economical use\n• environmental impact'
const stationeryRemark = 'All stationery is transferred to the field office for the purpose of the project and office use. After receiving the stationery, the Junior Project Coordinator should check, verify, and count it, then update the LLC stationery list.'
const activeTab = ref(tabs[0].id)
const documents = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const showForm = ref(false)
const today = () => new Date().toISOString().slice(0, 10)
const blankItem = (type, stationeryKind = 'transfer') => {
  if (type === 'inventory_record' || (type === 'stationary_record' && stationeryKind === 'detailed_list')) return { category: '', asset_code: '', description: '', purchase_date: '', supplier: '', price: '', funding_percent: '', funding_source: '', location: '', user: '', ref_voucher: '', condition: '', remarks: '' }
  if (type === 'stationary_record' && stationeryKind === 'summary') return { item_name: '', quantity: '', remarks: '' }
  if (type === 'stationary_record') return { item_name: '', unit: '', remarks: '' }
  if (type === 'disposal_record') return { asset_code: '', location: '', description: '', purchase_date: '', original_cost: '', disposal_value: '' }
  if (type === 'asset_transfer') return { asset_code: '', description: '', quantity: 1, from_location: '', to_location: '', from_custodian: '', to_custodian: '', condition: '', remarks: '' }
  if (type === 'asset_custodian') return { asset_code: '', item_name: '', quantity: 1, remarks: '' }
  return { category: '', asset_code: '', description: '', purchase_date: '', supplier: '', price: '', funding_percent: '', funding_source: '', location: '', user: '', ref_voucher: '', condition: '', remarks: '' }
}
const blankForm = (type, stationeryKind = 'transfer') => ({
  type,
  document_number: `${tabs.find((t) => t.id === type)?.prefix || 'AST'}-${Date.now().toString().slice(-8)}`,
  document_date: today(),
  stationery_kind: type === 'stationary_record' ? stationeryKind : 'transfer',
  remarks: '',
  organization_name: 'Live & Learn Cambodia (LLC)',
  period: '',
  office_location: '',
  category: '',
  location: '',
  department: '',
  condition: '',
  custodian: '',
  custodian_position: '',
  from_location: '',
  to_location: '',
  from_custodian: '',
  to_custodian: '',
  reason: '',
  method: '',
  methods: [],
  approved_by: '',
  approved_by_position: '',
  approved_by_date: '',
  prepared_by: '',
  prepared_by_position: '',
  prepared_by_date: '',
  checked_by_name: '',
  checked_by_position: '',
  checked_by_date: '',
  employee_name: '',
  employee_code: '',
  custodian_by: '',
  received_by_name: '',
  received_by_position: '',
  received_by_date: '',
  acknowledged_by: '',
  acknowledged_position: '',
  acknowledged_date: '',
  items: [blankItem(type, stationeryKind)],
})
const form = reactive(blankForm(activeTab.value))
const currentTab = computed(() => tabs.find((t) => t.id === activeTab.value))
const numberLabel = computed(() => (form.type === 'disposal_record' ? 'Disposal number' : form.type === 'asset_custodian' ? 'Asset custodian no.' : 'Record number'))
const dateLabel = computed(() => (form.type === 'asset_custodian' ? 'Custodian date' : 'Date'))
const stationeryTitle = computed(() => `List transfer Stationery to ${form.to_location || 'field office'}`)
const visibleDocuments = computed(() => documents.value.filter((doc) => doc.type === activeTab.value))
const statusLabel = (status) => ({ draft: 'Draft', issued: 'Recorded', received: 'Received' }[status] || status)

const load = async () => {
  loading.value = true
  error.value = ''
  try { documents.value = (await getProcurementDocuments()).data.data }
  catch (e) { error.value = e.response?.data?.message || 'Could not load asset records.' }
  finally { loading.value = false }
}
onMounted(load)

const openForm = (type) => { Object.assign(form, blankForm(type)); error.value = ''; notice.value = ''; showForm.value = true }
const setStationeryKind = (kind) => { Object.assign(form, blankForm('stationary_record', kind)); error.value = ''; notice.value = '' }
const addItem = () => form.items.push(blankItem(form.type, form.stationery_kind))
const removeItem = (index) => { if (form.items.length > 1) form.items.splice(index, 1) }
const num = (value) => (value === '' || value === null || value === undefined ? null : Number(value))

const save = async () => {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    let payload = { remarks: form.remarks }
    if (form.type === 'inventory_record' || (form.type === 'stationary_record' && form.stationery_kind === 'detailed_list')) {
      payload = {
        ...(form.type === 'stationary_record' ? { kind: 'detailed_list' } : {}),
        organization_name: form.organization_name,
        period: form.period,
        office_location: form.office_location,
        prepared_by: form.prepared_by,
        prepared_by_position: form.prepared_by_position,
        prepared_by_date: form.prepared_by_date || null,
        checked_by_name: form.checked_by_name,
        checked_by_position: form.checked_by_position,
        checked_by_date: form.checked_by_date || null,
        approved_by: form.approved_by,
        approved_by_position: form.approved_by_position,
        approved_by_date: form.approved_by_date || null,
        remarks: form.remarks,
        items: form.items.map((item) => ({
          category: item.category,
          asset_code: item.asset_code,
          description: item.description || '',
          purchase_date: item.purchase_date || null,
          supplier: item.supplier,
          price: num(item.price),
          funding_percent: item.funding_percent,
          funding_source: item.funding_source,
          location: item.location,
          user: item.user,
          ref_voucher: item.ref_voucher,
          condition: item.condition,
          remarks: item.remarks,
        })),
      }
    } else if (form.type === 'stationary_record' && form.stationery_kind === 'summary') {
      payload = {
        kind: 'summary',
        organization_name: form.organization_name,
        period: form.period,
        office_location: form.office_location,
        prepared_by: form.prepared_by,
        prepared_by_position: form.prepared_by_position,
        prepared_by_date: form.prepared_by_date || null,
        approved_by: form.approved_by,
        approved_by_position: form.approved_by_position,
        approved_by_date: form.approved_by_date || null,
        remarks: form.remarks,
        items: form.items.map((item) => ({ item_name: item.item_name, quantity: num(item.quantity), remarks: item.remarks })),
      }
    } else if (form.type === 'stationary_record') {
      payload = {
        kind: 'transfer',
        to_location: form.to_location,
        purpose: stationeryRemark,
        prepared_by: form.prepared_by,
        prepared_by_position: form.prepared_by_position,
        prepared_by_date: form.prepared_by_date || null,
        received_by_name: form.received_by_name,
        received_by_position: form.received_by_position,
        received_by_date: form.received_by_date || null,
        remarks: form.remarks,
        items: form.items.map((item) => ({ item_name: item.item_name, description: item.item_name || '', unit: item.unit, remarks: item.remarks })),
      }
    } else if (form.type === 'disposal_record') {
      payload = {
        method: form.methods.join(', '),
        methods: form.methods,
        reason: form.reason,
        prepared_by: form.prepared_by,
        prepared_by_position: form.prepared_by_position,
        prepared_by_date: form.prepared_by_date || null,
        checked_by_name: form.checked_by_name,
        checked_by_position: form.checked_by_position,
        checked_by_date: form.checked_by_date || null,
        approved_by: form.approved_by,
        approved_by_position: form.approved_by_position,
        approved_by_date: form.approved_by_date || null,
        remarks: form.remarks,
        items: form.items.map((item) => ({ asset_code: item.asset_code, location: item.location, description: item.description, purchase_date: item.purchase_date || null, original_cost: num(item.original_cost), disposal_value: num(item.disposal_value) })),
      }
    } else if (form.type === 'asset_custodian') {
      payload = {
        employee_name: form.employee_name,
        employee_code: form.employee_code,
        custodian: form.employee_name,
        custodian_by: form.custodian_by,
        department: form.department,
        office: form.location,
        purpose: assetUsePurpose,
        acknowledged_by: form.acknowledged_by,
        acknowledged_position: form.acknowledged_position,
        acknowledged_date: form.acknowledged_date || null,
        prepared_by: form.prepared_by,
        prepared_by_position: form.prepared_by_position,
        prepared_by_date: form.prepared_by_date || null,
        checked_by_name: form.checked_by_name,
        checked_by_position: form.checked_by_position,
        checked_by_date: form.checked_by_date || null,
        approved_by: form.approved_by,
        approved_by_position: form.approved_by_position,
        approved_by_date: form.approved_by_date || null,
        remarks: form.remarks,
        items: form.items.map((item) => ({ asset_code: item.asset_code, item_name: item.item_name, quantity: Number(item.quantity || 0) || null, remarks: item.remarks })),
      }
    } else if (form.type === 'asset_transfer') {
      payload = { from_location: form.from_location, to_location: form.to_location, from_custodian: form.from_custodian, to_custodian: form.to_custodian, reason: form.reason, approved_by: form.approved_by, prepared_by: form.prepared_by, remarks: form.remarks, items: form.items.map((item) => ({ asset_code: item.asset_code, description: item.description, quantity: Number(item.quantity || 0) || null, from_location: item.from_location || form.from_location, to_location: item.to_location || form.to_location, from_custodian: item.from_custodian || form.from_custodian, to_custodian: item.to_custodian || form.to_custodian, condition: item.condition, remarks: item.remarks })) }
    }
    await createProcurementDocument({ type: form.type, document_number: form.document_number, document_date: form.document_date || null, payload })
    showForm.value = false; notice.value = 'Record saved as a draft.'; await load()
  } catch (e) { error.value = Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'Could not save this record.' }
  finally { saving.value = false }
}
const advance = async (doc) => {
  error.value = ''; notice.value = ''
  try { await transitionProcurementDocument(doc.id, 'issued'); notice.value = 'Record marked as recorded.'; await load() }
  catch (e) { error.value = e.response?.data?.message || 'Could not update record status.' }
}
const docSummary = (doc) => {
  const p = doc.payload || {}
  if (doc.type === 'inventory_record') return [p.period, p.office_location, p.organization_name].filter(Boolean).join(' · ') || 'Inventory details'
  if (doc.type === 'stationary_record') {
    if (p.kind === 'detailed_list') return [p.period, p.office_location, p.organization_name].filter(Boolean).join(' · ') || 'Furniture & stationery list'
    if (p.kind === 'summary') return [p.period, p.office_location].filter(Boolean).join(' · ') || 'Stationery summary'
    return [p.to_location || p.location, p.received_by_name].filter(Boolean).join(' · ') || 'Stationery transfer details'
  }
  if (doc.type === 'disposal_record') return [p.method, p.reason].filter(Boolean).join(' · ') || 'Disposal details'
  if (doc.type === 'asset_custodian') return [p.employee_name || p.custodian, p.department, p.office || p.location].filter(Boolean).join(' · ') || 'Custodian details'
  if (doc.type === 'asset_transfer') return [p.from_location || p.from_custodian, p.to_location || p.to_custodian].filter(Boolean).join(' → ') || 'Transfer details'
  return 'Record details'
}
const itemSummary = (doc) => (doc.payload?.items || []).map((item) => {
  const name = item.description || item.item_name || item.asset_code || 'Item'
  return item.quantity !== null && item.quantity !== undefined && item.quantity !== '' ? `${name} × ${item.quantity}` : name
}).join(' · ')
</script>

<template>
  <main class="procurement">
    <header class="procurement__header"><div><p class="procurement__eyebrow"><span></span> ASSET MANAGEMENT</p><h1>Asset Management</h1><p class="procurement__intro">Inventory, stationary, disposal, custodian, and transfer records for account, admin, or logistics use.</p></div></header>

    <nav class="procurement__tabs" aria-label="Asset management sections"><button v-for="tab in tabs" :key="tab.id" type="button" :class="{ 'is-active': activeTab === tab.id }" @click="activeTab = tab.id; showForm = false"><AppIcon :name="tab.icon" :size="18" />{{ tab.label }}</button></nav>

    <p v-if="error" class="procurement__alert" role="alert">{{ error }}</p><p v-if="notice" class="procurement__notice" role="status">{{ notice }}</p>

    <section class="procurement__panel">
      <header class="procurement__panel-head"><div><h2>{{ currentTab?.label }}</h2><p>Create, review, and record asset management information.</p></div><button type="button" class="procurement__primary" @click="openForm(activeTab)"><AppIcon name="plus" :size="17" />New {{ currentTab?.label }}</button></header>

      <form v-if="showForm" class="procurement__form" @submit.prevent="save">
        <div class="procurement__form-title"><div><h3>New {{ currentTab?.label }}</h3><p>Saved records start as drafts.</p></div><button type="button" class="procurement__close" aria-label="Close form" @click="showForm = false">×</button></div>

        <div class="procurement__fields">
          <label>{{ numberLabel }}<input v-model.trim="form.document_number" required maxlength="80" /></label>
          <label>{{ dateLabel }}<input v-model="form.document_date" type="date" /></label>
        </div>

        <template v-if="form.type === 'inventory_record'">
          <h4 class="procurement__subhead">Inventory list</h4>
          <div class="procurement__fields">
            <label class="procurement__wide">Name of organization<input v-model.trim="form.organization_name" maxlength="255" /></label>
            <label>Period<input v-model.trim="form.period" maxlength="255" placeholder="e.g. Jan to June 2026" /></label>
            <label>Office location<input v-model.trim="form.office_location" maxlength="255" placeholder="e.g. Phnom Penh" /></label>
          </div>
        </template>

        <template v-else-if="form.type === 'stationary_record'">
          <h4 class="procurement__subhead">Stationery record type</h4>
          <div class="procurement__fields">
            <label class="procurement__wide">Form
              <select :value="form.stationery_kind" @change="setStationeryKind($event.target.value)">
                <option v-for="kind in stationeryKinds" :key="kind.id" :value="kind.id">{{ kind.label }}</option>
              </select>
            </label>
          </div>

          <template v-if="form.stationery_kind === 'transfer'">
            <h4 class="procurement__subhead">Transfer details</h4>
            <div class="procurement__fields">
              <label>Transfer stationery to<input v-model.trim="form.to_location" maxlength="255" placeholder="Field office in SRP" /></label>
            </div>
            <p class="procurement__doctitle">{{ stationeryTitle }}</p>
          </template>
          <template v-else-if="form.stationery_kind === 'detailed_list'">
            <h4 class="procurement__subhead">Furniture and Stationery List</h4>
            <div class="procurement__fields">
              <label class="procurement__wide">Name of organization<input v-model.trim="form.organization_name" maxlength="255" /></label>
              <label>Period<input v-model.trim="form.period" maxlength="255" placeholder="e.g. Jan to June 2026" /></label>
              <label>Office location<input v-model.trim="form.office_location" maxlength="255" placeholder="e.g. Phnom Penh" /></label>
            </div>
          </template>
          <template v-else-if="form.stationery_kind === 'summary'">
            <h4 class="procurement__subhead">Summary of Furniture and Stationery List</h4>
            <div class="procurement__fields">
              <label class="procurement__wide">Name of organization<input v-model.trim="form.organization_name" maxlength="255" /></label>
              <label>Period<input v-model.trim="form.period" maxlength="255" placeholder="e.g. January to March 2024" /></label>
              <label>Office location<input v-model.trim="form.office_location" maxlength="255" placeholder="e.g. Phnom Penh" /></label>
            </div>
          </template>
        </template>

        <template v-else-if="form.type === 'disposal_record'">
          <h4 class="procurement__subhead">Method of disposal</h4>
          <div class="procurement__checks" role="group" aria-label="Method of disposal">
            <label v-for="option in disposalMethods" :key="option"><input v-model="form.methods" type="checkbox" :value="option" />{{ option }}</label>
          </div>
          <p class="procurement__hint">Noted: if the asset being sold or gifted then please complete part 3 of the disposal of Non IT equipment, equipment, and furniture form.</p>
        </template>

        <template v-else-if="form.type === 'asset_custodian'">
          <h4 class="procurement__subhead">Employee details</h4>
          <div class="procurement__fields">
            <label>Name of employee<input v-model.trim="form.employee_name" maxlength="255" /></label>
            <label>Employee code<input v-model.trim="form.employee_code" maxlength="100" /></label>
            <label>Department<input v-model.trim="form.department" maxlength="255" /></label>
            <label>Office<input v-model.trim="form.location" maxlength="255" /></label>
            <label>Custodian by<input v-model.trim="form.custodian_by" maxlength="255" /></label>
          </div>
          <h4 class="procurement__subhead">1. Purpose of asset use</h4>
          <p class="procurement__policy">{{ assetUsePurpose }}</p>
          <h4 class="procurement__subhead">2. Asset transfer to staff</h4>
        </template>

        <template v-else-if="form.type === 'asset_transfer'">
          <h4 class="procurement__subhead">Transfer details</h4>
          <div class="procurement__fields">
            <label>From custodian<input v-model.trim="form.from_custodian" maxlength="255" /></label>
            <label>To custodian<input v-model.trim="form.to_custodian" maxlength="255" /></label>
            <label>From location<input v-model.trim="form.from_location" maxlength="255" /></label>
            <label>To location<input v-model.trim="form.to_location" maxlength="255" /></label>
            <label class="procurement__wide">Reason<input v-model.trim="form.reason" maxlength="2000" /></label>
            <label>Approved by<input v-model.trim="form.approved_by" maxlength="255" /></label>
            <label>Prepared by<input v-model.trim="form.prepared_by" maxlength="255" /></label>
          </div>
        </template>

        <h4 class="procurement__subhead">Records</h4>
        <div class="rq-table-wrap">
          <table class="rq-table" :class="{ 'rq-table--inventory': form.type === 'inventory_record' || (form.type === 'stationary_record' && form.stationery_kind === 'detailed_list'), 'rq-table--disposal': form.type === 'disposal_record', 'rq-table--custodian': form.type === 'asset_custodian', 'rq-table--summary': form.type === 'stationary_record' && form.stationery_kind === 'summary' }">
            <thead>
              <tr v-if="form.type === 'inventory_record' || (form.type === 'stationary_record' && form.stationery_kind === 'detailed_list')">
                <th scope="col">Category</th><th scope="col">Asset code</th><th scope="col" class="rq-table__grow">Asset description, model, serial number</th><th scope="col">Purchase date</th><th scope="col">Supplier</th><th scope="col">Price</th><th scope="col">%</th><th scope="col">Funding source</th><th scope="col">Location</th><th scope="col">User</th><th scope="col">Ref. voucher</th><th scope="col">Condition</th><th scope="col">Remarks</th><th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
              <tr v-else-if="form.type === 'stationary_record' && form.stationery_kind === 'summary'">
                <th scope="col">No.</th><th scope="col" class="rq-table__grow">Asset name</th><th scope="col">Quantity</th><th scope="col">Remark</th><th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
              <tr v-else-if="form.type === 'stationary_record'">
                <th scope="col">No</th><th scope="col" class="rq-table__grow">Stationery name</th><th scope="col">Unit</th><th scope="col">Remark</th><th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
              <tr v-else-if="form.type === 'disposal_record'">
                <th scope="col">Asset ref #</th><th scope="col">Location</th><th scope="col" class="rq-table__grow">Description</th><th scope="col">Purchase date</th><th scope="col">Original cost</th><th scope="col">Disposal value</th><th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
              <tr v-else-if="form.type === 'asset_custodian'">
                <th scope="col">Asset code</th><th scope="col" class="rq-table__grow">Asset name</th><th scope="col">QTY</th><th scope="col">Remark</th><th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
              <tr v-else-if="form.type === 'asset_transfer'">
                <th scope="col">Asset code</th><th scope="col" class="rq-table__grow">Description</th><th scope="col">Qty</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Condition</th><th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, index) in form.items" :key="index">
                <template v-if="form.type === 'inventory_record' || (form.type === 'stationary_record' && form.stationery_kind === 'detailed_list')">
                  <td><input v-model="item.category" class="rq-input" type="text" maxlength="255" placeholder="e.g. F&S" :aria-label="`Record ${index + 1} category`" /></td>
                  <td><input v-model="item.asset_code" class="rq-input" type="text" maxlength="100" :aria-label="`Record ${index + 1} asset code`" /></td>
                  <td><textarea v-model="item.description" class="rq-input rq-input--area" rows="2" maxlength="1000" :aria-label="`Record ${index + 1} asset description, model, serial number`"></textarea></td>
                  <td><input v-model="item.purchase_date" class="rq-input" type="date" :aria-label="`Record ${index + 1} purchase date`" /></td>
                  <td><input v-model="item.supplier" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} supplier`" /></td>
                  <td><input v-model.number="item.price" class="rq-input" type="number" min="0" step="any" :aria-label="`Record ${index + 1} price`" /></td>
                  <td><textarea v-model="item.funding_percent" class="rq-input rq-input--area" rows="2" maxlength="255" placeholder="100%" :aria-label="`Record ${index + 1} funding percent`"></textarea></td>
                  <td><textarea v-model="item.funding_source" class="rq-input rq-input--area" rows="2" maxlength="255" :aria-label="`Record ${index + 1} funding source`"></textarea></td>
                  <td><input v-model="item.location" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} location`" /></td>
                  <td><input v-model="item.user" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} user`" /></td>
                  <td><input v-model="item.ref_voucher" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} reference voucher`" /></td>
                  <td><input v-model="item.condition" class="rq-input" type="text" maxlength="100" :aria-label="`Record ${index + 1} condition`" /></td>
                  <td><input v-model="item.remarks" class="rq-input" type="text" maxlength="1000" :aria-label="`Record ${index + 1} remarks`" /></td>
                </template>
                <template v-else-if="form.type === 'stationary_record' && form.stationery_kind === 'summary'">
                  <td class="rq-table__num">{{ index + 1 }}</td>
                  <td><input v-model="item.item_name" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} asset name`" /></td>
                  <td><input v-model.number="item.quantity" class="rq-input" type="number" min="0" step="any" :aria-label="`Record ${index + 1} quantity`" /></td>
                  <td><input v-model="item.remarks" class="rq-input" type="text" maxlength="1000" :aria-label="`Record ${index + 1} remark`" /></td>
                </template>
                <template v-else-if="form.type === 'stationary_record'">
                  <td class="rq-table__num">{{ index + 1 }}</td>
                  <td><input v-model="item.item_name" class="rq-input" type="text" maxlength="255" placeholder="Stationery name" :aria-label="`Record ${index + 1} stationery name`" /></td>
                  <td><input v-model="item.unit" class="rq-input" type="text" maxlength="50" placeholder="1 Set" :aria-label="`Record ${index + 1} unit`" /></td>
                  <td><input v-model="item.remarks" class="rq-input" type="text" maxlength="1000" placeholder="New" :aria-label="`Record ${index + 1} remark`" /></td>
                </template>
                <template v-else-if="form.type === 'disposal_record'">
                  <td><input v-model="item.asset_code" class="rq-input" type="text" maxlength="100" :aria-label="`Record ${index + 1} asset ref`" /></td>
                  <td><input v-model="item.location" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} location`" /></td>
                  <td><input v-model="item.description" class="rq-input" type="text" maxlength="500" :aria-label="`Record ${index + 1} description`" /></td>
                  <td><input v-model="item.purchase_date" class="rq-input" type="date" :aria-label="`Record ${index + 1} purchase date`" /></td>
                  <td><input v-model.number="item.original_cost" class="rq-input" type="number" min="0" step="any" :aria-label="`Record ${index + 1} original cost`" /></td>
                  <td><input v-model.number="item.disposal_value" class="rq-input" type="number" min="0" step="any" :aria-label="`Record ${index + 1} disposal value`" /></td>
                </template>
                <template v-else-if="form.type === 'asset_custodian'">
                  <td><input v-model="item.asset_code" class="rq-input" type="text" maxlength="100" :aria-label="`Record ${index + 1} asset code`" /></td>
                  <td><input v-model="item.item_name" class="rq-input" type="text" maxlength="500" :aria-label="`Record ${index + 1} asset name`" /></td>
                  <td><input v-model.number="item.quantity" class="rq-input" type="number" min="0" step="any" :aria-label="`Record ${index + 1} quantity`" /></td>
                  <td><input v-model="item.remarks" class="rq-input" type="text" maxlength="1000" :aria-label="`Record ${index + 1} remark`" /></td>
                </template>
                <template v-else-if="form.type === 'asset_transfer'">
                  <td><input v-model="item.asset_code" class="rq-input" type="text" maxlength="100" :aria-label="`Record ${index + 1} asset code`" /></td>
                  <td><input v-model="item.description" class="rq-input" type="text" maxlength="500" :aria-label="`Record ${index + 1} description`" /></td>
                  <td><input v-model.number="item.quantity" class="rq-input" type="number" min="0" step="any" :aria-label="`Record ${index + 1} quantity`" /></td>
                  <td><input v-model="item.from_location" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} from location`" :placeholder="form.from_location || 'From'" /></td>
                  <td><input v-model="item.to_location" class="rq-input" type="text" maxlength="255" :aria-label="`Record ${index + 1} to location`" :placeholder="form.to_location || 'To'" /></td>
                  <td><input v-model="item.condition" class="rq-input" type="text" maxlength="100" :aria-label="`Record ${index + 1} condition`" /></td>
                </template>
                <td><button v-if="form.items.length > 1" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" :aria-label="`Remove item ${index + 1}`" @click="removeItem(index)"><AppIcon name="trash" :size="15" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <button type="button" class="lv-btn lv-btn--ghost rq-add" @click="addItem"><AppIcon name="plus" :size="16" />Add record</button>

        <template v-if="form.type === 'disposal_record'">
          <h4 class="procurement__subhead">Reason of disposal</h4>
          <div class="procurement__fields">
            <label class="procurement__wide">Reason<textarea v-model.trim="form.reason" rows="3" maxlength="2000"></textarea></label>
          </div>

        </template>

        <template v-if="form.type === 'inventory_record' || form.type === 'disposal_record' || form.type === 'asset_custodian'">
          <h4 class="procurement__subhead">Prepared by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.prepared_by" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.prepared_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.prepared_by_date" type="date" /></label>
          </div>

          <h4 class="procurement__subhead">Checked by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.checked_by_name" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.checked_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.checked_by_date" type="date" /></label>
          </div>

          <h4 class="procurement__subhead">Approved by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.approved_by" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.approved_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.approved_by_date" type="date" /></label>
          </div>
        </template>

        <template v-if="form.type === 'stationary_record' && form.stationery_kind === 'detailed_list'">
          <h4 class="procurement__subhead">Prepared by / Counted by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.prepared_by" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.prepared_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.prepared_by_date" type="date" /></label>
          </div>

          <h4 class="procurement__subhead">Counted by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.checked_by_name" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.checked_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.checked_by_date" type="date" /></label>
          </div>

          <h4 class="procurement__subhead">Approved by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.approved_by" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.approved_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.approved_by_date" type="date" /></label>
          </div>
        </template>

        <template v-if="form.type === 'stationary_record' && form.stationery_kind === 'summary'">
          <h4 class="procurement__subhead">Prepared by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.prepared_by" maxlength="255" /></label>
            <label>Date<input v-model="form.prepared_by_date" type="date" /></label>
          </div>

          <h4 class="procurement__subhead">Approved by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.approved_by" maxlength="255" /></label>
            <label>Date<input v-model="form.approved_by_date" type="date" /></label>
          </div>
        </template>

        <template v-if="form.type === 'stationary_record' && form.stationery_kind === 'transfer'">
          <h4 class="procurement__subhead">Remark</h4>
          <p class="procurement__policy">{{ stationeryRemark }}</p>

          <h4 class="procurement__subhead">Prepared by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.prepared_by" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.prepared_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.prepared_by_date" type="date" /></label>
          </div>

          <h4 class="procurement__subhead">Received by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.received_by_name" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.received_by_position" maxlength="255" /></label>
            <label>Date<input v-model="form.received_by_date" type="date" /></label>
          </div>
        </template>

        <template v-if="form.type === 'asset_custodian'">
          <h4 class="procurement__subhead">Acknowledgement and declaration by employee</h4>
          <p class="procurement__policy">I, Ms/Mr {{ form.employee_name || '………………………' }} hereby acknowledge that I have received the above mentioned assets. I understand that this asset belong to Live & Learn Cambodia and is under my possession for carrying out my office/field work. I hereby assure that I will take care of the asset of the organization to the best possible extend.</p>

          <h4 class="procurement__subhead">Sign of employee</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.acknowledged_by" maxlength="255" /></label>
            <label>Position<input v-model.trim="form.acknowledged_position" maxlength="255" /></label>
            <label>Date<input v-model="form.acknowledged_date" type="date" /></label>
          </div>
        </template>

        <div class="procurement__fields">
          <label class="procurement__wide">Remarks<textarea v-model.trim="form.remarks" rows="2" maxlength="5000"></textarea></label>
        </div>

        <footer><button type="button" class="procurement__secondary" @click="showForm = false">Cancel</button><button class="procurement__primary" type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save draft' }}</button></footer>
      </form>

      <div v-if="loading" class="procurement__empty">Loading records…</div>
      <div v-else-if="!visibleDocuments.length" class="procurement__empty procurement__empty--card"><span class="procurement__empty-icon"><AppIcon :name="currentTab?.icon || 'list'" :size="22" /></span><strong>No {{ currentTab?.label.toLowerCase() }}s yet</strong><p>Create a record to start tracking here.</p><button class="procurement__secondary" type="button" @click="openForm(activeTab)">Create first record</button></div>
      <div v-else class="procurement__documents">
        <article v-for="doc in visibleDocuments" :key="doc.id" class="procurement__document">
          <span class="procurement__doc-icon"><AppIcon :name="currentTab?.icon || 'list'" :size="19" /></span>
          <div class="procurement__document-main">
            <div class="procurement__document-title"><strong>{{ doc.document_number }}</strong><span class="procurement__status" :class="`is-${doc.status}`">{{ statusLabel(doc.status) }}</span></div>
            <p>{{ docSummary(doc) }} · {{ doc.document_date || 'No date' }}</p>
            <small>{{ itemSummary(doc) }}</small>
          </div>
          <button v-if="doc.status === 'draft'" class="procurement__secondary" type="button" @click="advance(doc)">Mark recorded</button>
        </article>
      </div>
    </section>
  </main>
</template>

<style scoped>
.procurement{--ink:#102e52;--muted:#657b98;--line:#dce6f2;--green:#087d67;color:var(--ink);padding:8px 0 38px}.procurement__header{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin:0 0 22px}.procurement__eyebrow{display:flex;align-items:center;gap:9px;color:var(--green);font-size:12px;font-weight:800;letter-spacing:.13em;margin:0 0 8px}.procurement__eyebrow span{width:8px;height:8px;border-radius:50%;background:#21ae91}.procurement h1{font-size:clamp(28px,2.6vw,36px);letter-spacing:-.035em;margin:0}.procurement__intro,.procurement__panel-head p{color:var(--muted);margin:6px 0 0;font-size:14px}.procurement__tabs{display:inline-flex;align-items:center;gap:3px;width:max-content;max-width:100%;min-height:50px;box-sizing:border-box;padding:5px;border:1px solid var(--line);border-radius:12px;background:#f1f5f9;margin:0 0 16px;overflow-x:auto}.procurement__tabs button{box-sizing:border-box;flex:none;min-height:38px;margin:0!important;border:0;background:transparent;color:#526b88;padding:0 14px;border-radius:8px;font-family:inherit;font-size:13px;font-weight:600;line-height:1;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;white-space:nowrap}.procurement__tabs button.is-active{color:#102e52;background:#fff;box-shadow:0 1px 5px #193b5718}.procurement__panel{border:1px solid var(--line);border-radius:15px;background:#fff;padding:20px;box-shadow:0 5px 16px #193b5707}.procurement__panel-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding-bottom:15px;border-bottom:1px solid #eaf0f7;margin-bottom:16px}.procurement__panel-head h2{font-size:20px;margin:0}.procurement__alert,.procurement__notice{margin:0 0 14px;padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600}.procurement__alert{color:#8a3434;background:#fff3f3;border:1px solid #efd1d1}.procurement__notice{color:#0c5f4f;background:#eefaf5;border:1px solid #cfe8dc}.procurement__primary,.procurement__secondary,.procurement__close{font:inherit}.procurement__primary{display:inline-flex;align-items:center;gap:8px;min-height:39px;padding:0 14px;border:0;border-radius:10px;background:#087d67;color:#fff;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 6px 14px #087d6722}.procurement__primary:disabled{opacity:.6;cursor:not-allowed;box-shadow:none}.procurement__secondary{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:36px;padding:0 13px;border:1px solid #cfdae7;border-radius:10px;background:#fff;color:#3f5a79;font-size:13px;font-weight:700;cursor:pointer}.procurement__close{width:34px;height:34px;border:1px solid #cfdae7;border-radius:10px;background:#fff;color:#4f6884;font-size:20px;line-height:1;cursor:pointer}.procurement__form{display:grid;gap:14px;padding:16px;margin:0 0 16px;border:1px solid #e4ebf3;border-radius:12px;background:#fbfcfe}.procurement__form-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.procurement__form-title h3{margin:0;font-size:17px}.procurement__form-title p{margin:4px 0 0;color:var(--muted);font-size:13px}.procurement__fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.procurement__fields label{display:grid;gap:6px;color:#284765;font-size:12px;font-weight:700}.procurement__fields input,.procurement__fields select,.procurement__fields textarea{width:100%;box-sizing:border-box;min-height:41px;padding:9px 12px;border:1px solid #cfdced;border-radius:9px;background:#fff;color:var(--ink);font:inherit;font-size:14px;font-weight:500}.procurement__fields textarea{min-height:auto;resize:vertical}.procurement__wide{grid-column:1/-1}.procurement__subhead{margin:4px 0 0;font-size:13px;color:#234766}.procurement__checks{display:flex;flex-wrap:wrap;gap:10px 26px;padding:2px 0 4px}.procurement__checks label{display:flex;align-items:center;gap:8px;color:#284765;font-size:13px;font-weight:600;cursor:pointer}.procurement__checks input{width:16px;height:16px;margin:0;accent-color:#087d67}.procurement__hint{margin:0 0 14px;max-width:900px;padding:10px 12px;border-left:3px solid #cfe0f0;border-radius:6px;background:#f6f9fc;color:var(--muted);font-size:12px;line-height:1.5}.procurement__policy{margin:0 0 14px;max-width:900px;padding:12px 14px;border:1px solid #e4ebf3;border-radius:9px;background:#f8fbfe;color:#33506f;font-size:13px;line-height:1.6;white-space:pre-line}.procurement__doctitle{margin:6px 0 10px;text-align:center;font-size:15px;font-weight:800;color:#1d2b3a}.procurement__documents{display:grid;gap:10px}.procurement__document{display:flex;align-items:center;gap:12px;padding:13px 14px;border:1px solid #e4ebf3;border-radius:12px;background:#fff}.procurement__doc-icon{width:38px;height:38px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:#eef5f2;color:#0c6b58;flex:none}.procurement__document-main{min-width:0;flex:1}.procurement__document-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.procurement__document-main p,.procurement__document-main small{display:block;margin:3px 0 0;color:var(--muted)}.procurement__document-main small{font-size:12px}.procurement__status{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#eef2f7;color:#516a86;white-space:nowrap}.procurement__status.is-issued,.procurement__status.is-received{background:#e8f7f1;color:#0c6b58}.procurement__status.is-draft{background:#fff4dd;color:#8a6113}.procurement__empty{color:var(--muted);font-size:14px}.procurement__empty--card{display:grid;justify-items:start;gap:8px;padding:18px;border:1px dashed #cedae8;border-radius:12px;background:#fbfcfe}.procurement__empty-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eef5f2;color:#0c6b58}.procurement form footer{display:flex;justify-content:flex-end;gap:10px;padding-top:4px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.procurement :deep(.rq-table-wrap){max-width:100%;overflow-x:auto;border:1px solid #e4ebf3;border-radius:9px}.procurement :deep(.rq-table){min-width:620px}.procurement :deep(.rq-table--disposal){min-width:880px}.procurement :deep(.rq-table--custodian){min-width:720px}.procurement :deep(.rq-table--inventory){min-width:1780px}.procurement :deep(.rq-table--summary){min-width:560px}.procurement :deep(.rq-input--area){min-height:38px;resize:vertical}.procurement :deep(.rq-table__num){width:52px;text-align:center;color:#5b7189;font-weight:700}
@media(max-width:760px){.procurement__header{align-items:flex-start;flex-direction:column}.procurement__tabs{overflow:auto}.procurement__panel{padding:16px}.procurement__fields{grid-template-columns:repeat(2,minmax(0,1fr))}.procurement__document{align-items:flex-start;flex-wrap:wrap}.procurement__document-main{min-width:calc(100% - 60px)}}@media(max-width:480px){.procurement__fields{grid-template-columns:1fr}.procurement__panel-head{align-items:flex-start;flex-direction:column}}
</style>
