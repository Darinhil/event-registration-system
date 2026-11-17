<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import PurchaseRequest from './PurchaseRequest.vue'
import { createProcurementDocument, getProcurementDocuments, transitionProcurementDocument } from '../../services/procurementService'
import { getMyRequests } from '../../services/staffRequestService'

const tabs = [
  { id: 'requests', label: 'Purchase Requests', icon: 'cart' },
  { id: 'orders', label: 'Purchase Orders', icon: 'file-text' },
  { id: 'receipts', label: 'Goods Received Notes', icon: 'inbox' },
]
const activeTab = ref('requests')
const documents = ref([])
const requests = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const showForm = ref(false)
const today = () => new Date().toISOString().slice(0, 10)
const blankItem = () => ({ description: '', quantity: 1, unit_cost: '', received_quantity: '' })
const blankContact = () => ({ name: '', address: '', telephone: '', fax: '', email: '' })
const blankForm = (type) => ({
  type,
  document_number: `${type === 'purchase_order' ? 'PO' : 'GRN'}-${Date.now().toString().slice(-8)}`,
  document_date: today(),
  supplier: '',
  supplier_representative: '',
  supplier_position: '',
  purchase_order_id: '',
  tax: '',
  notes: '',
  comments: '',
  authorized_by_name: '',
  authorized_by_title: '',
  ordered_by_name: '',
  ordered_by_date: today(),
  issued_to: blankContact(),
  delivered_by: blankContact(),
  received_according_to_po: 'yes',
  received_according_to_po_comment: '',
  received_in_good_condition: 'yes',
  received_in_good_condition_comment: '',
  checked_by_name: '',
  checked_by_date: today(),
  received_by_name: '',
  received_by_date: today(),
  items: [blankItem()],
})
const form = reactive(blankForm('purchase_order'))
const visibleDocuments = computed(() => documents.value.filter((doc) => activeTab.value === 'orders' ? doc.type === 'purchase_order' : doc.type === 'goods_received_note'))
const issuedOrders = computed(() => documents.value.filter((doc) => doc.type === 'purchase_order' && doc.status === 'issued'))
const purchaseRequests = computed(() => requests.value.filter((item) => item.type === 'purchase'))
const selectedOrder = computed(() => documents.value.find((doc) => String(doc.id) === String(form.purchase_order_id)))
const statusLabel = (status) => ({ pending: 'Awaiting approval', approved: 'Approved', rejected: 'Declined', draft: 'Draft', issued: 'Issued', received: 'Received' }[status] || status)
const money = (value) => `$${(Number(value) || 0).toFixed(2)}`
const lineTotal = (item) => (Number(item.quantity) || 0) * (Number(item.unit_cost) || 0)
const subtotal = computed(() => form.items.reduce((sum, item) => sum + lineTotal(item), 0))
const tax = computed(() => Number(form.tax) || 0)
const netTotal = computed(() => subtotal.value + tax.value)
const documentNet = (doc) => (doc.payload?.items || []).reduce((sum, item) => sum + lineTotal(item), 0) + (Number(doc.payload?.tax) || 0)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const [docs, reqs] = await Promise.all([getProcurementDocuments(), getMyRequests()])
    documents.value = docs.data.data
    requests.value = reqs.data.data
  } catch (e) { error.value = e.response?.data?.message || 'Could not load procurement records.' }
  finally { loading.value = false }
}
onMounted(load)

const openForm = (type) => {
  Object.assign(form, blankForm(type))
  error.value = ''
  notice.value = ''
  showForm.value = true
}
const addItem = () => form.items.push(blankItem())
const removeItem = (index) => { if (form.items.length > 1) form.items.splice(index, 1) }
const applyOrder = () => {
  const order = selectedOrder.value
  if (!order) return
  form.supplier = order.payload?.supplier || ''
  form.items = (order.payload?.items || []).map((item) => ({
    description: item.description || '',
    quantity: Number(item.quantity) || 0,
    unit_cost: item.unit_cost === null || item.unit_cost === undefined ? '' : item.unit_cost,
    received_quantity: Number(item.quantity) || 0,
  }))
}
const save = async () => {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const isOrder = form.type === 'purchase_order'
    const items = form.items.map((item) => ({
      description: item.description.trim(),
      quantity: Number(item.quantity),
      unit_cost: item.unit_cost === '' ? null : Number(item.unit_cost),
      ...(isOrder ? {} : { received_quantity: item.received_quantity === '' ? null : Number(item.received_quantity) }),
    }))
    const payload = isOrder
      ? {
          supplier: form.supplier,
          supplier_representative: form.supplier_representative,
          supplier_position: form.supplier_position,
          issued_to: { ...form.issued_to },
          authorized_by: { name: form.authorized_by_name, title: form.authorized_by_title },
          ordered_by: { name: form.ordered_by_name, date: form.ordered_by_date || null },
          tax: form.tax === '' ? null : Number(form.tax),
          notes: form.notes,
          items,
        }
      : {
          purchase_order_id: Number(form.purchase_order_id),
          comments: form.comments,
          delivered_by: { ...form.delivered_by },
          received_according_to_po: form.received_according_to_po,
          received_according_to_po_comment: form.received_according_to_po_comment,
          received_in_good_condition: form.received_in_good_condition,
          received_in_good_condition_comment: form.received_in_good_condition_comment,
          checked_by: { name: form.checked_by_name, date: form.checked_by_date || null },
          received_by: { name: form.received_by_name, date: form.received_by_date || null },
          notes: form.notes,
          items,
        }
    await createProcurementDocument({ type: form.type, document_number: form.document_number, document_date: form.document_date || null, payload })
    showForm.value = false; notice.value = 'Document saved as a draft.'; await load()
  } catch (e) { error.value = Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'Could not save this document.' }
  finally { saving.value = false }
}
const advance = async (doc) => {
  error.value = ''; notice.value = ''
  try { await transitionProcurementDocument(doc.id, doc.type === 'purchase_order' ? 'issued' : 'received'); notice.value = 'Document status updated.'; await load() }
  catch (e) { error.value = e.response?.data?.message || 'Could not update document status.' }
}
</script>

<template>
  <main class="procurement">
    <header class="procurement__header"><div><p class="procurement__eyebrow"><span></span> PROCUREMENT WORKSPACE</p><h1>Procurement Management</h1><p class="procurement__intro">Request what you need, place orders, and record deliveries in one place.</p></div><div class="procurement__flow"><span>Request</span><i></i><span>Order</span><i></i><span>Receive</span></div></header>
    <nav class="procurement__tabs" aria-label="Procurement sections"><button v-for="tab in tabs" :key="tab.id" type="button" :class="{ 'is-active': activeTab === tab.id }" @click="activeTab = tab.id; showForm = false"><AppIcon :name="tab.icon" :size="18" />{{ tab.label }}</button></nav>
    <p v-if="error" class="procurement__alert" role="alert">{{ error }}</p><p v-if="notice" class="procurement__notice" role="status">{{ notice }}</p>
    <section v-if="activeTab === 'requests'" class="procurement__panel"><header class="procurement__panel-head"><div><h2>Purchase Requests</h2><p>Submit a request for supervisor approval and track its progress.</p></div><span class="procurement__count">{{ purchaseRequests.length }} requests</span></header><PurchaseRequest @submitted="load" /><div class="procurement__request-list"><h3>Recent requests</h3><p v-if="loading" class="procurement__empty">Loading requests…</p><p v-else-if="!purchaseRequests.length" class="procurement__empty">Your submitted purchase requests will appear here.</p><article v-for="item in purchaseRequests" :key="item.id" class="procurement__request-row"><span class="procurement__doc-icon"><AppIcon name="cart" :size="18" /></span><div><strong>{{ item.summary || item.details?.purpose || 'Purchase request' }}</strong><small>{{ new Date(item.submitted_at).toLocaleDateString() }} · {{ item.approver || 'Supervisor review' }}</small></div><span class="procurement__status" :class="`is-${item.status}`">{{ statusLabel(item.status) }}</span></article></div></section>
    <section v-else class="procurement__panel">
      <header class="procurement__panel-head"><div><h2>{{ activeTab === 'orders' ? 'Purchase Orders' : 'Goods Received Notes' }}</h2><p>{{ activeTab === 'orders' ? 'Create and issue supplier orders.' : 'Record items received against an issued purchase order.' }}</p></div><button type="button" class="procurement__primary" @click="openForm(activeTab === 'orders' ? 'purchase_order' : 'goods_received_note')"><AppIcon name="plus" :size="17" />New {{ activeTab === 'orders' ? 'Purchase Order' : 'Received Note' }}</button></header>

      <form v-if="showForm" class="procurement__form" @submit.prevent="save">
        <div class="procurement__form-title"><div><h3>{{ form.type === 'purchase_order' ? 'New Purchase Order' : 'New Goods Received Note' }}</h3><p>Saved documents start as drafts.</p></div><button type="button" class="procurement__close" aria-label="Close form" @click="showForm = false">×</button></div>

        <div class="procurement__fields">
          <label>{{ form.type === 'purchase_order' ? 'P.O. number' : 'G.R.N number' }}<input v-model.trim="form.document_number" required maxlength="80" /></label>
          <label>Date<input v-model="form.document_date" type="date" /></label>
          <label v-if="form.type === 'goods_received_note'">P.O. number<select v-model="form.purchase_order_id" required @change="applyOrder"><option value="" disabled>Select an issued order</option><option v-for="order in issuedOrders" :key="order.id" :value="String(order.id)">{{ order.document_number }} · {{ order.payload?.supplier || 'Supplier' }}</option></select></label>
        </div>

        <template v-if="form.type === 'purchase_order'">
          <h4 class="procurement__subhead">Supplier</h4>
          <div class="procurement__fields">
            <label>Supplier name<input v-model.trim="form.supplier" maxlength="255" placeholder="Supplier name" /></label>
            <label>Representative<input v-model.trim="form.supplier_representative" maxlength="255" placeholder="Full name" /></label>
            <label>Position<input v-model.trim="form.supplier_position" maxlength="255" placeholder="Position" /></label>
          </div>
          <h4 class="procurement__subhead">Authorized by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.authorized_by_name" maxlength="255" /></label>
            <label>Title<input v-model.trim="form.authorized_by_title" maxlength="255" /></label>
          </div>
          <h4 class="procurement__subhead">Issued to</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.issued_to.name" maxlength="255" /></label>
            <label>Telephone<input v-model.trim="form.issued_to.telephone" maxlength="50" /></label>
            <label>Email<input v-model.trim="form.issued_to.email" type="email" maxlength="255" /></label>
            <label class="procurement__wide">Address<textarea v-model.trim="form.issued_to.address" rows="2" maxlength="500"></textarea></label>
            <label>Fax<input v-model.trim="form.issued_to.fax" maxlength="50" /></label>
          </div>
        </template>

        <template v-else>
          <div class="procurement__fields">
            <label class="procurement__wide">Comments<textarea v-model.trim="form.comments" rows="2" maxlength="2000"></textarea></label>
          </div>
          <h4 class="procurement__subhead">Delivered by</h4>
          <div class="procurement__fields">
            <label>Name<input v-model.trim="form.delivered_by.name" maxlength="255" /></label>
            <label>Telephone<input v-model.trim="form.delivered_by.telephone" maxlength="50" /></label>
            <label>Email<input v-model.trim="form.delivered_by.email" type="email" maxlength="255" /></label>
            <label class="procurement__wide">Address<textarea v-model.trim="form.delivered_by.address" rows="2" maxlength="500"></textarea></label>
            <label>Fax<input v-model.trim="form.delivered_by.fax" maxlength="50" /></label>
          </div>
        </template>

        <h4 class="procurement__subhead">Items</h4>
        <div class="rq-table-wrap">
          <table class="rq-table">
            <thead>
              <tr>
                <th scope="col" class="rq-table__grow">Description</th>
                <th scope="col">{{ form.type === 'purchase_order' ? 'Quantity' : 'Order qty' }}</th>
                <th v-if="form.type === 'purchase_order'" scope="col">Unit price ($)</th>
                <th v-if="form.type === 'purchase_order'" scope="col" class="num">Total price</th>
                <th v-if="form.type === 'goods_received_note'" scope="col">Delivered qty</th>
                <th scope="col"><span class="sr-only">Remove</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, index) in form.items" :key="index">
                <td><input v-model="item.description" class="rq-input" type="text" required maxlength="500" :aria-label="`Item ${index + 1} description`" /></td>
                <td><input v-model.number="item.quantity" class="rq-input" type="number" min="0.01" step="any" required :aria-label="`Item ${index + 1} quantity`" /></td>
                <td v-if="form.type === 'purchase_order'"><input v-model.number="item.unit_cost" class="rq-input" type="number" min="0" step="0.01" :aria-label="`Item ${index + 1} unit price`" /></td>
                <td v-if="form.type === 'purchase_order'" class="num">{{ money(lineTotal(item)) }}</td>
                <td v-if="form.type === 'goods_received_note'"><input v-model.number="item.received_quantity" class="rq-input" type="number" min="0" step="any" :aria-label="`Item ${index + 1} delivered quantity`" /></td>
                <td><button v-if="form.items.length > 1" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" :aria-label="`Remove item ${index + 1}`" @click="removeItem(index)"><AppIcon name="trash" :size="15" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <button type="button" class="lv-btn lv-btn--ghost rq-add" @click="addItem"><AppIcon name="plus" :size="16" />Add item</button>

        <template v-if="form.type === 'purchase_order'">
          <div class="procurement__fields">
            <label>Tax (USD)<input v-model.number="form.tax" type="number" min="0" step="0.01" /></label>
          </div>
          <div class="procurement__totals">
            <p><span>Total</span><strong>{{ money(subtotal) }}</strong></p>
            <p><span>Tax</span><strong>{{ money(tax) }}</strong></p>
            <p><span>Net amount</span><strong>{{ money(netTotal) }}</strong></p>
          </div>
          <div class="procurement__fields">
            <label class="procurement__wide">Notes<textarea v-model.trim="form.notes" rows="2" maxlength="5000"></textarea></label>
          </div>
          <h4 class="procurement__subhead">Ordered by</h4>
          <div class="procurement__fields">
            <label>Employee name<input v-model.trim="form.ordered_by_name" maxlength="255" /></label>
            <label>Date<input v-model="form.ordered_by_date" type="date" /></label>
          </div>
        </template>

        <template v-else>
          <h4 class="procurement__subhead">Receiving check</h4>
          <div class="procurement__fields">
            <div class="procurement__field procurement__wide">
              <span>Goods/services received according to Purchase Order</span>
              <div class="procurement__choices">
                <label class="procurement__choice"><input v-model="form.received_according_to_po" type="radio" value="yes" />Yes</label>
                <label class="procurement__choice"><input v-model="form.received_according_to_po" type="radio" value="no" />No</label>
              </div>
            </div>
            <label class="procurement__wide">Comment, if any<textarea v-model.trim="form.received_according_to_po_comment" rows="2" maxlength="2000"></textarea></label>
            <div class="procurement__field procurement__wide">
              <span>Goods/services received in good quality or condition</span>
              <div class="procurement__choices">
                <label class="procurement__choice"><input v-model="form.received_in_good_condition" type="radio" value="yes" />Yes</label>
                <label class="procurement__choice"><input v-model="form.received_in_good_condition" type="radio" value="no" />No</label>
              </div>
            </div>
            <label class="procurement__wide">Comment, if any<textarea v-model.trim="form.received_in_good_condition_comment" rows="2" maxlength="2000"></textarea></label>
          </div>
          <div class="procurement__fields">
            <label class="procurement__wide">Notes<textarea v-model.trim="form.notes" rows="2" maxlength="5000"></textarea></label>
          </div>
          <h4 class="procurement__subhead">Checked by</h4>
          <div class="procurement__fields">
            <label>Employee name<input v-model.trim="form.checked_by_name" maxlength="255" /></label>
            <label>Date<input v-model="form.checked_by_date" type="date" /></label>
          </div>
          <h4 class="procurement__subhead">Received by</h4>
          <div class="procurement__fields">
            <label>Employee name<input v-model.trim="form.received_by_name" maxlength="255" /></label>
            <label>Date<input v-model="form.received_by_date" type="date" /></label>
          </div>
        </template>

        <footer><button type="button" class="procurement__secondary" @click="showForm = false">Cancel</button><button class="procurement__primary" type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save draft' }}</button></footer>
      </form>

      <div v-if="loading" class="procurement__empty">Loading procurement records…</div><div v-else-if="!visibleDocuments.length" class="procurement__empty procurement__empty--card"><span class="procurement__empty-icon"><AppIcon :name="activeTab === 'orders' ? 'file-text' : 'inbox'" :size="22" /></span><strong>No {{ activeTab === 'orders' ? 'purchase orders' : 'goods received notes' }} yet</strong><p>Create a document to start tracking procurement here.</p><button class="procurement__secondary" type="button" @click="openForm(activeTab === 'orders' ? 'purchase_order' : 'goods_received_note')">Create first document</button></div><div v-else class="procurement__documents"><article v-for="doc in visibleDocuments" :key="doc.id" class="procurement__document"><span class="procurement__doc-icon"><AppIcon :name="doc.type === 'purchase_order' ? 'file-text' : 'inbox'" :size="19" /></span><div class="procurement__document-main"><div class="procurement__document-title"><strong>{{ doc.document_number }}</strong><span class="procurement__status" :class="`is-${doc.status}`">{{ statusLabel(doc.status) }}</span></div><p>{{ doc.type === 'purchase_order' ? (doc.payload?.supplier || 'Supplier not specified') : `Purchase order ${doc.payload?.purchase_order_number || '—'}` }} · {{ doc.document_date || 'No date' }}</p><small>{{ doc.payload?.items?.map((item) => `${item.description} × ${item.quantity}${doc.type === 'goods_received_note' && item.received_quantity !== null && item.received_quantity !== undefined ? ` (delivered ${item.received_quantity})` : ''}`).join(' · ') }}</small></div><div v-if="doc.type === 'purchase_order' && documentNet(doc)" class="procurement__amount">{{ money(documentNet(doc)) }}</div><button v-if="doc.status === 'draft'" class="procurement__secondary" type="button" @click="advance(doc)">{{ doc.type === 'purchase_order' ? 'Issue order' : 'Confirm receipt' }}</button></article></div>
    </section>
  </main>
</template>

<style scoped>
.procurement{--ink:#102e52;--muted:#657b98;--line:#dce6f2;--green:#087d67;color:var(--ink);padding:8px 0 38px}.procurement__header{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin:0 0 22px}.procurement__eyebrow{display:flex;align-items:center;gap:9px;color:var(--green);font-size:12px;font-weight:800;letter-spacing:.13em;margin:0 0 8px}.procurement__eyebrow span{width:8px;height:8px;border-radius:50%;background:#21ae91}.procurement h1{font-size:clamp(28px,2.6vw,36px);letter-spacing:-.035em;margin:0}.procurement__intro,.procurement__panel-head p{color:var(--muted);margin:6px 0 0;font-size:14px}.procurement__flow{display:flex;align-items:center;gap:9px;padding:11px 15px;border:1px solid var(--line);border-radius:12px;background:#fff;color:#55718f;font-size:12px;font-weight:700}.procurement__flow i{width:20px;height:1px;background:#b8c9dc}.procurement__tabs{display:inline-flex;align-items:center;gap:3px;width:max-content;max-width:100%;min-height:50px;box-sizing:border-box;padding:5px;border:1px solid var(--line);border-radius:12px;background:#f1f5f9;margin:0 0 16px;overflow-x:auto}.procurement__tabs button{box-sizing:border-box;flex:none;min-height:38px;margin:0!important;border:0;background:transparent;color:#526b88;padding:0 14px;border-radius:8px;font-family:inherit;font-size:13px;font-weight:600;line-height:1;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;white-space:nowrap}.procurement__tabs button.is-active{color:#102e52;background:#fff;box-shadow:0 1px 5px #193b5718}.procurement__panel{border:1px solid var(--line);border-radius:15px;background:#fff;padding:20px;box-shadow:0 5px 16px #193b5707}.procurement__panel-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding-bottom:15px;border-bottom:1px solid #eaf0f7;margin-bottom:16px}.procurement__panel-head h2{font-size:20px;margin:0}.procurement__count{background:#eff8f5;color:#08745f;border-radius:30px;padding:7px 11px;font-size:12px;font-weight:700;white-space:nowrap}.procurement__alert,.procurement__notice{margin:0 0 14px;padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600}.procurement__alert{color:#8a3434;background:#fff3f3;border:1px solid #efd1d1}.procurement__notice{color:#0c5f4f;background:#eefaf5;border:1px solid #cfe8dc}.procurement__primary,.procurement__secondary,.procurement__close{font:inherit}.procurement__primary{display:inline-flex;align-items:center;gap:8px;min-height:39px;padding:0 14px;border:0;border-radius:10px;background:#087d67;color:#fff;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 6px 14px #087d6722}.procurement__primary:disabled{opacity:.6;cursor:not-allowed;box-shadow:none}.procurement__secondary{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:36px;padding:0 13px;border:1px solid #cfdae7;border-radius:10px;background:#fff;color:#3f5a79;font-size:13px;font-weight:700;cursor:pointer}.procurement__close{width:34px;height:34px;border:1px solid #cfdae7;border-radius:10px;background:#fff;color:#4f6884;font-size:20px;line-height:1;cursor:pointer}.procurement__form{display:grid;gap:14px;padding:16px;margin:0 0 16px;border:1px solid #e4ebf3;border-radius:12px;background:#fbfcfe}.procurement__form-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.procurement__form-title h3{margin:0;font-size:17px}.procurement__form-title p{margin:4px 0 0;color:var(--muted);font-size:13px}.procurement__fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.procurement__fields label,.procurement__field{display:grid;gap:6px;color:#284765;font-size:12px;font-weight:700}.procurement__fields input,.procurement__fields select,.procurement__fields textarea{width:100%;box-sizing:border-box;min-height:41px;padding:9px 12px;border:1px solid #cfdced;border-radius:9px;background:#fff;color:var(--ink);font:inherit;font-size:14px;font-weight:500}.procurement__fields textarea{min-height:auto;resize:vertical}.procurement__wide{grid-column:1/-1}.procurement__subhead{margin:4px 0 0;font-size:13px;color:#234766}.procurement__choices{display:flex;gap:14px;flex-wrap:wrap;color:#3d5673;font-weight:600}.procurement__choice{display:inline-flex;align-items:center;gap:7px}.procurement__choice input{width:auto;min-height:auto}.procurement__totals{display:grid;gap:6px;max-width:320px;margin-left:auto}.procurement__totals p{display:flex;align-items:center;justify-content:space-between;margin:0;color:#3f5a79}.procurement__totals strong{color:var(--ink)}.procurement__documents,.procurement__request-list{display:grid;gap:10px}.procurement__request-list{margin-top:18px}.procurement__request-list h3{margin:0 0 2px;font-size:15px}.procurement__document,.procurement__request-row{display:flex;align-items:center;gap:12px;padding:13px 14px;border:1px solid #e4ebf3;border-radius:12px;background:#fff}.procurement__doc-icon{width:38px;height:38px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:#eef5f2;color:#0c6b58;flex:none}.procurement__document-main{min-width:0;flex:1}.procurement__document-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.procurement__document-main p,.procurement__document-main small,.procurement__request-row small{display:block;margin:3px 0 0;color:var(--muted)}.procurement__document-main small,.procurement__request-row small{font-size:12px}.procurement__status{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#eef2f7;color:#516a86;white-space:nowrap}.procurement__status.is-approved,.procurement__status.is-issued,.procurement__status.is-received{background:#e8f7f1;color:#0c6b58}.procurement__status.is-pending,.procurement__status.is-draft{background:#fff4dd;color:#8a6113}.procurement__status.is-rejected{background:#fdeeee;color:#9b3f3f}.procurement__amount{font-weight:800;color:#123c63;white-space:nowrap}.procurement__empty{color:var(--muted);font-size:14px}.procurement__empty--card{display:grid;justify-items:start;gap:8px;padding:18px;border:1px dashed #cedae8;border-radius:12px;background:#fbfcfe}.procurement__empty-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eef5f2;color:#0c6b58}.procurement form footer{display:flex;justify-content:flex-end;gap:10px;padding-top:4px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.procurement :deep(.lv){width:100%;max-width:none;margin:0;padding:0;font-size:14px}.procurement :deep(.lv-head){display:none}.procurement :deep(.lv-card){border:0;border-radius:0;background:transparent;box-shadow:none}.procurement :deep(.lv-form){width:100%;max-width:none;gap:15px;padding:3px 0}.procurement :deep(.lv-form textarea){min-height:70px}.procurement :deep(.rq-table-wrap){max-width:100%;overflow-x:auto;border:1px solid #e4ebf3;border-radius:9px}.procurement :deep(.rq-table){min-width:620px}.procurement :deep(.rq-total){margin-top:0}.procurement :deep(.lv-form__foot){padding-top:12px;border-top:1px solid #edf1f6}
@media(max-width:760px){.procurement__header{align-items:flex-start;flex-direction:column}.procurement__tabs{overflow:auto}.procurement__panel{padding:16px}.procurement__fields{grid-template-columns:repeat(2,minmax(0,1fr))}.procurement__document{align-items:flex-start;flex-wrap:wrap}.procurement__document-main{min-width:calc(100% - 60px)}}@media(max-width:480px){.procurement__fields{grid-template-columns:1fr}.procurement__panel-head{align-items:flex-start;flex-direction:column}.procurement__request-row{align-items:flex-start;flex-wrap:wrap}.procurement__flow{width:max-content}}
</style>
