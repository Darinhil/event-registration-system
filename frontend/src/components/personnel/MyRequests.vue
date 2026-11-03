<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import { getMyOvertime } from '../../services/overtimeService'
import { cancelRequest, decideRequest, downloadRequestAttachment, getMyRequests, getRequestApprovals } from '../../services/staffRequestService'
import { saveBlob } from '../../utils/staffRequestForm'

const loading = ref(true)
const loadError = ref('')
const mine = ref([])
const approvals = ref([])
const canApprove = ref(false)
const tab = ref('mine')
const typeFilter = ref('all')
const statusFilter = ref('all')
const selected = ref(null)
const busy = ref(false)
const actionError = ref('')
const rejecting = ref(false)
const note = ref('')

const hours = (minutes) => `${(minutes / 60).toFixed(minutes % 60 ? 2 : 0)} ${minutes === 60 ? 'hour' : 'hours'}`
// Overtime has its own page and API; it's listed here so everything can be followed in one place.
const fromOvertime = (item) => ({ key: `overtime-${item.id}`, id: item.id, type: 'overtime', type_label: 'Overtime', summary: `${hours(item.minutes)} on ${day(item.work_date)}`, status: item.status, submitted_at: item.submitted_at, approver: item.supervisor, review_note: item.review_note, reviewed_by: item.reviewed_by, details: { reason: item.reason } })
const day = (iso) => (iso ? new Date(iso.length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [requests, overtime] = await Promise.all([getMyRequests(), getMyOvertime()])
    canApprove.value = requests.data.can_approve
    mine.value = [...requests.data.data.map((item) => ({ ...item, key: `request-${item.id}` })), ...overtime.data.data.map(fromOvertime)]
      .sort((a, b) => new Date(b.submitted_at) - new Date(a.submitted_at))
    approvals.value = canApprove.value ? (await getRequestApprovals()).data.data.map((item) => ({ ...item, key: `request-${item.id}` })) : []
  } catch {
    loadError.value = 'We couldn’t load your requests. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const list = computed(() => (tab.value === 'mine' ? mine.value : approvals.value))
const types = computed(() => [...new Set(list.value.map((item) => item.type_label))])
const statuses = computed(() => [...new Set(list.value.map((item) => item.status))])
const rows = computed(() => list.value.filter((item) => (typeFilter.value === 'all' || item.type_label === typeFilter.value) && (statusFilter.value === 'all' || item.status === statusFilter.value)))
const waiting = computed(() => approvals.value.filter((item) => item.can_decide).length)
const label = (status) => status.charAt(0).toUpperCase() + status.slice(1)
const switchTab = (value) => { tab.value = value; typeFilter.value = 'all'; statusFilter.value = 'all' }

const open = (item) => { selected.value = item; rejecting.value = false; note.value = ''; actionError.value = '' }
const close = () => { if (!busy.value) selected.value = null }
const run = async (action) => {
  busy.value = true
  actionError.value = ''
  try {
    await action()
    selected.value = null
    await load()
  } catch (error) {
    actionError.value = Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || 'That didn’t work. Check your connection and try again.'
  } finally {
    busy.value = false
  }
}
const cancel = () => run(() => cancelRequest(selected.value.id))
const approve = () => run(() => decideRequest(selected.value.id, 'approved'))
const reject = () => run(() => decideRequest(selected.value.id, 'rejected', note.value))
const download = async () => {
  try { saveBlob((await downloadRequestAttachment(selected.value.id)).data, selected.value.attachment_name) } catch { actionError.value = 'We couldn’t download the attachment.' }
}

// The details a person typed, as label/value rows for the review dialog.
const LABELS = { leave_type: 'Leave type', start_date: 'Start date', end_date: 'End date', working_days: 'Working days', reason: 'Reason', purpose: 'Purpose', destination: 'Destination', accommodation: 'Accommodation', transport: 'Transportation', vehicle: 'Vehicle', department: 'Department', required_date: 'Needed by', justification: 'Justification', field: 'Field', value: 'New value' }
const detailRows = computed(() => Object.entries(selected.value?.details || {})
  .filter(([key, value]) => LABELS[key] && value !== null && value !== '')
  .map(([key, value]) => [LABELS[key], /_date$/.test(key) ? day(value) : key === 'leave_type' ? label(String(value)).replaceAll('_', ' ') : value]))
const lines = computed(() => {
  const details = selected.value?.details || {}
  if (details.records) return details.records.map((row) => `${day(row.date)} — ${row.liters} L, $${row.cost}${row.mileage ? `, ${row.mileage} km` : ''}`)
  if (details.items) return details.items.map((row) => `${row.quantity} × ${row.description} at $${row.unit_cost}`)
  if (details.costs) return Object.entries(details.costs).filter(([, value]) => value).map(([key, value]) => `${label(key)}: $${value}`)
  return []
})
</script>

<template>
  <div class="lv">
    <header class="lv-head mr-head">
      <h1>My Requests</h1>
      <div v-if="canApprove" class="mr-tabs" role="group" aria-label="Which requests to show">
        <button type="button" :class="{ 'is-active': tab === 'mine' }" :aria-pressed="tab === 'mine'" @click="switchTab('mine')">My requests</button>
        <button type="button" :class="{ 'is-active': tab === 'approvals' }" :aria-pressed="tab === 'approvals'" @click="switchTab('approvals')">To approve<span v-if="waiting" class="mr-count">{{ waiting }}</span></button>
      </div>
    </header>

    <div class="lv-card mr-card">
      <div v-if="loading" class="mr-state" role="status">Loading requests…</div>
      <div v-else-if="loadError" class="mr-state" role="alert"><p>{{ loadError }}</p><button type="button" class="lv-btn lv-btn--ghost" @click="load"><AppIcon name="refresh" :size="16" />Try again</button></div>
      <template v-else>
        <div v-if="list.length" class="mr-filters">
          <label><span class="sr-only">Filter by type</span><select v-model="typeFilter" class="rq-select"><option value="all">All types</option><option v-for="type in types" :key="type">{{ type }}</option></select></label>
          <label><span class="sr-only">Filter by status</span><select v-model="statusFilter" class="rq-select"><option value="all">All statuses</option><option v-for="status in statuses" :key="status" :value="status">{{ label(status) }}</option></select></label>
        </div>
        <div v-if="rows.length" class="rq-table-wrap">
          <table class="rq-table mr-table">
            <thead><tr><th v-if="tab === 'approvals'" scope="col">Staff</th><th scope="col">Type</th><th scope="col" class="rq-table__grow">Details</th><th scope="col">Submitted</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
              <tr v-for="item in rows" :key="item.key">
                <td v-if="tab === 'approvals'">{{ item.staff }}</td>
                <td><strong>{{ item.type_label }}</strong></td>
                <td>{{ item.summary }}</td>
                <td class="mr-nowrap">{{ day(item.submitted_at) }}</td>
                <td><span class="mr-status" :class="`is-${item.status}`">{{ label(item.status) }}</span></td>
                <td class="num">
                  <RouterLink v-if="item.type === 'overtime'" class="lv-btn lv-btn--ghost lv-btn--sm" :to="{ query: { section: 'overtime-request' } }">Open</RouterLink>
                  <button v-else type="button" class="lv-btn lv-btn--ghost lv-btn--sm" @click="open(item)">{{ item.can_decide ? 'Review' : 'View' }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="mr-state">
          <AppIcon name="inbox" :size="26" />
          <p>{{ list.length ? 'No requests match these filters.' : tab === 'mine' ? 'You haven’t submitted any requests yet.' : 'Nothing is waiting for your approval.' }}</p>
        </div>
      </template>
    </div>

    <div v-if="selected" class="mr-backdrop" @click.self="close" @keydown.esc="close">
      <section class="mr-dialog" role="dialog" aria-modal="true" aria-labelledby="mr-title">
        <header>
          <h2 id="mr-title">{{ selected.type_label }} request</h2>
          <button type="button" class="lv-btn lv-btn--ghost lv-btn--sm" aria-label="Close" @click="close"><AppIcon name="x" :size="16" /></button>
        </header>
        <dl class="lv-summary mr-summary">
          <div v-if="selected.staff"><dt>Staff</dt><dd>{{ selected.staff }}</dd></div>
          <div><dt>Status</dt><dd>{{ label(selected.status) }}</dd></div>
          <div><dt>Submitted</dt><dd>{{ day(selected.submitted_at) }}</dd></div>
          <div v-if="selected.approver"><dt>Approver</dt><dd>{{ selected.approver }}</dd></div>
          <div v-for="[name, value] in detailRows" :key="name"><dt>{{ name }}</dt><dd>{{ value }}</dd></div>
          <div v-if="selected.reviewed_by"><dt>Decided by</dt><dd>{{ selected.reviewed_by }}</dd></div>
          <div v-if="selected.review_note"><dt>Note</dt><dd>{{ selected.review_note }}</dd></div>
        </dl>
        <ul v-if="lines.length" class="mr-lines"><li v-for="line in lines" :key="line">{{ line }}</li></ul>
        <button v-if="selected.has_attachment" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" @click="download"><AppIcon name="download" :size="15" />{{ selected.attachment_name }}</button>
        <div v-if="rejecting" class="lv-field">
          <label for="mr-note" class="lv-label">Reason for rejecting <span class="lv-req" aria-hidden="true">*</span></label>
          <textarea id="mr-note" v-model="note" rows="2"></textarea>
        </div>
        <p v-if="actionError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ actionError }}</p>
        <footer v-if="selected.can_cancel || selected.can_decide">
          <button v-if="selected.can_cancel" type="button" class="lv-btn lv-btn--ghost" :disabled="busy" @click="cancel">{{ busy ? 'Cancelling…' : 'Cancel this request' }}</button>
          <template v-if="selected.can_decide">
            <button v-if="!rejecting" type="button" class="lv-btn lv-btn--ghost" :disabled="busy" @click="rejecting = true">Reject</button>
            <button v-else type="button" class="lv-btn lv-btn--ghost" :disabled="busy" @click="reject">{{ busy ? 'Saving…' : 'Confirm rejection' }}</button>
            <button v-if="!rejecting" type="button" class="lv-btn lv-btn--primary" :disabled="busy" @click="approve">{{ busy ? 'Saving…' : 'Approve' }}</button>
          </template>
        </footer>
      </section>
    </div>
  </div>
</template>

<style scoped>
.mr-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.mr-head h1 { margin: 0; }
.mr-tabs { display: inline-flex; gap: 4px; padding: 4px; border-radius: 12px; background: #eef2f6; }
.mr-tabs button { display: inline-flex; min-height: 36px; align-items: center; gap: 6px; margin: 0; padding: 0 14px; border: 0; border-radius: 9px; background: transparent; color: var(--lv-text); font: inherit; font-size: .88rem; font-weight: 600; cursor: pointer; }
.mr-tabs button.is-active { background: #fff; color: var(--lv-ink); box-shadow: 0 1px 3px rgba(23, 54, 95, .12); }
.mr-count { display: inline-grid; min-width: 20px; height: 20px; padding: 0 5px; place-items: center; border-radius: 99px; background: var(--lv-brand); color: #fff; font-size: .72rem; }
.mr-card { padding: 18px 20px; }
.mr-filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.mr-filters .rq-select { width: auto; min-width: 160px; }
.mr-state { display: grid; justify-items: center; gap: 10px; padding: 40px 16px; color: var(--lv-muted); text-align: center; }
.mr-state p { margin: 0; }
.mr-nowrap { white-space: nowrap; }
.mr-table td strong { color: var(--lv-ink); white-space: nowrap; }
.mr-status { display: inline-flex; padding: 3px 10px; border-radius: 99px; background: #fff4dc; color: #8a5300; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.mr-status.is-approved { background: #e8f5f0; color: #086a51; }
.mr-status.is-rejected { background: #fdf1f0; color: #9b2c22; }
.mr-status.is-cancelled { background: #eef2f6; color: #4e6175; }
.mr-backdrop { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; padding: 20px; background: rgba(17, 36, 59, .45); }
.mr-dialog { display: grid; gap: 14px; width: min(100%, 520px); max-height: calc(100vh - 40px); overflow-y: auto; padding: 22px; border-radius: 14px; background: #fff; box-shadow: 0 24px 80px rgba(16, 36, 61, .25); }
.mr-dialog header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mr-dialog h2 { margin: 0; font-size: 1.15rem; }
.mr-dialog footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
.mr-summary { width: 100%; margin: 0; }
.mr-lines { margin: 0; padding-left: 18px; color: var(--lv-text); font-size: .9rem; }
</style>
