<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { RouterLink } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import { getMyRequests } from '../../services/staffRequestService'

const forms = [
  { id: 'travel', title: 'Travel Application', short: 'Travel Applications', description: 'Plan a work trip, estimate costs, and add supporting details.', action: 'Apply for travel', icon: 'plane', section: 'travel-application', tone: 'green' },
  { id: 'fuel', title: 'Fuel Log-sheet', short: 'Fuel Log-sheets', description: 'Record vehicle mileage, fuel usage, and monthly costs.', action: 'Open fuel log', icon: 'fuel', section: 'fuel-log-sheet', tone: 'blue' },
  { id: 'purchase', title: 'Purchase Request', short: 'Purchase Requests', description: 'List requested items, quantities, and estimated costs.', action: 'Request a purchase', icon: 'cart', section: 'purchase-request', tone: 'orange' },
]
const selectedForm = ref('travel')
const router = useRouter()
const requests = ref([])
const loading = ref(true)
const error = ref('')
const filter = ref('all')
const selected = computed(() => forms.find((form) => form.id === selectedForm.value))
const countFor = (id) => requests.value.filter((item) => item.type === id).length
const list = computed(() => filter.value === 'all' ? requests.value : requests.value.filter((item) => item.type === filter.value))
const dateLabel = (value) => value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const label = (value) => value ? `${value[0].toUpperCase()}${value.slice(1)}` : '—'
const openForm = (form) => router.push({ path: '/admin/personnel-profile', query: { section: form.section } })

onMounted(async () => {
  try {
    const { data } = await getMyRequests()
    requests.value = data.data.filter((item) => forms.some((form) => form.id === item.type))
  } catch {
    error.value = 'Your recent requests could not be loaded. Check your connection and try again.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <main class="or" aria-labelledby="or-title">
    <header class="or-head"><div><h1 id="or-title">Other Requests</h1><p>Start a travel, fuel, or purchase request and review recent activity.</p></div></header>

    <section class="or-metrics" aria-label="Other request summary">
      <article v-for="form in forms" :key="form.id" class="or-metric" :class="`is-${form.tone}`">
        <span class="or-icon"><AppIcon :name="form.icon" :size="18" /></span><small>{{ form.short }}</small><strong>{{ loading ? '—' : countFor(form.id) }}<span> total</span></strong><p>{{ countFor(form.id) ? 'Requests on file' : 'No requests submitted yet' }}</p>
      </article>
    </section>

    <section class="or-panel" aria-labelledby="or-access-title">
      <div class="or-panel-head"><div><h2 id="or-access-title">Direct Access Workstation</h2><p>Choose a task to open its request form.</p></div><span class="or-quick-label">Quick launch</span></div>
      <div class="or-cards">
        <RouterLink v-for="form in forms" :key="form.id" :to="{ path: '/admin/personnel-profile', query: { section: form.section } }" class="or-card" :class="`is-${form.tone}`">
          <span class="or-card-icon"><AppIcon :name="form.icon" :size="17" /></span><strong>{{ form.title }}</strong><span>{{ form.description }}</span><small>Ready to start</small><b>{{ form.action }}</b>
        </RouterLink>
      </div>
    </section>

    <section class="or-panel or-workstation" aria-labelledby="or-form-title">
      <div class="or-panel-head"><div><h2 id="or-form-title">Direct Form Access Workstation</h2><p>Choose a form below, then open it to fill in the details.</p></div><RouterLink class="or-full-link" :to="{ path: '/admin/personnel-profile', query: { section: selected.section } }">Full page view</RouterLink></div>
      <div class="or-tabs" role="tablist" aria-label="Other request forms">
        <button v-for="form in forms" :key="form.id" type="button" role="tab" :aria-selected="selectedForm === form.id" :class="{ 'is-active': selectedForm === form.id }" @click="openForm(form)">{{ form.title }}</button>
      </div>
      <div class="or-selected-form"><div><strong>{{ selected.title }}</strong><p>{{ selected.description }}</p></div><RouterLink class="or-submit" :to="{ path: '/admin/personnel-profile', query: { section: selected.section } }">Open {{ selected.title }} form</RouterLink></div>
      <p class="or-note"><strong>Request workflow:</strong> Complete the form and submit it to your approver. Submitted requests and their status will appear in the activity ledger below.</p>
    </section>

    <section class="or-panel or-ledger" aria-labelledby="or-ledger-title">
      <div class="or-panel-head"><div><h2 id="or-ledger-title">Recent Activity &amp; Requests</h2><p>Review submitted requests and their approval status.</p></div><div class="or-filters" aria-label="Filter requests"><button v-for="form in [{ id: 'all', title: 'All activity' }, ...forms.map(({ id, title }) => ({ id, title }))]" :key="form.id" type="button" :class="{ 'is-active': filter === form.id }" @click="filter = form.id">{{ form.title }}</button></div></div>
      <p v-if="error" class="or-empty" role="alert">{{ error }}</p>
      <div v-else class="or-table-wrap"><table><thead><tr><th>Request ID</th><th>Module</th><th>Request / Description</th><th>Date</th><th>Approver</th><th>Status</th></tr></thead><tbody>
        <tr v-if="loading"><td colspan="6" class="or-empty">Loading recent activity…</td></tr>
        <tr v-else-if="!list.length"><td colspan="6" class="or-empty">No recent activity for this filter.</td></tr>
        <tr v-for="item in list" :key="item.id"><td>{{ item.id }}</td><td>{{ item.type_label }}</td><td>{{ item.summary }}</td><td>{{ dateLabel(item.submitted_at) }}</td><td>{{ item.approver || '—' }}</td><td><span class="or-status" :class="`is-${item.status}`">{{ label(item.status) }}</span></td></tr>
      </tbody></table></div>
    </section>
  </main>
</template>

<style scoped>
.or { display:grid; width:100%; max-width:none; gap:12px; margin:0; color:#15314a; }
.or-head h1 { margin:0; color:#0d3155; font-size:30px; line-height:1.2; }.or-head p,.or-panel-head p { margin:4px 0 0; color:#72849b; font-size:13px; }
.or-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
.or-metric,.or-panel { min-width:0; border:1px solid #d9e3ef; border-radius:9px; background:#fff; box-shadow:0 3px 10px #1d41620c; }
.or-metric { --tone:#16a77f; --tint:#e6f7f2; display:grid; align-content:start; gap:7px; min-height:138px; padding:16px 18px; border-top:3px solid var(--tone); background:linear-gradient(110deg,#fff 0%,var(--tint) 100%); }
.or-metric.is-blue { --tone:#4b87ff; --tint:#e9f0ff; }.or-metric.is-orange { --tone:#f4a51c; --tint:#fff1d9; }
.or-icon,.or-card-icon { display:grid; width:36px; height:36px; place-items:center; border-radius:10px; color:var(--tone); background:var(--tint); }.or-metric small { color:#68809a; font-size:13px; }.or-metric strong { color:#122b47; font-size:28px; line-height:1; }.or-metric strong span { font-size:12px; font-weight:500; }.or-metric p { margin:0; color:#8798aa; font-size:11px; }
.or-panel { padding:18px 20px; }.or-panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }.or-panel-head h2 { margin:0; color:#14365b; font-size:21px; }.or-quick-label,.or-empty { color:#8293a5; font-size:12px; }.or-full-link { padding:7px 10px; border:1px solid #d6e4f1; border-radius:5px; color:#316ca7; text-decoration:none; font-size:12px; }
.or-cards { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }.or-card { --tone:#16a77f; --tint:#e6f7f2; display:grid; justify-items:start; gap:8px; min-width:0; padding:14px; border:1px solid #dce5ef; border-radius:8px; color:#244360; text-decoration:none; }.or-card.is-blue { --tone:#4b87ff; --tint:#e9f0ff; }.or-card.is-orange { --tone:#f4a51c; --tint:#fff1d9; }.or-card:hover,.or-card:focus-visible { border-color:var(--tone); box-shadow:0 0 0 2px var(--tint); }.or-card strong { font-size:15px; }.or-card > span:not(.or-card-icon) { min-height:42px; color:#778ba1; font-size:12px; }.or-card small { color:#8494a6; font-size:11px; }.or-card b { width:100%; padding:10px 12px; border-radius:5px; color:#fff; background:#078476; text-align:center; font-size:13px; }
.or-workstation { padding-top:22px; }.or-workstation .or-panel-head { margin-bottom:54px; }.or-workstation .or-panel-head h2 { font-size:22px; }.or-workstation .or-panel-head p { margin-top:6px; font-size:15px; }
.or-tabs { display:flex; gap:28px; border-bottom:1px solid #e0e7ef; }.or-tabs button { padding:12px 5px; border:0; border-bottom:2px solid transparent; color:#657b92; background:transparent; font-size:14px; cursor:pointer; }.or-tabs button.is-active { border-color:#078476; color:#08776d; font-weight:700; }.or-selected-form { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 2px; }.or-selected-form strong { color:#25415e; font-size:14px; }.or-selected-form p { margin:4px 0 0; color:#76899d; font-size:12px; }.or-submit { padding:10px 14px; border-radius:5px; color:#fff; background:#078476; text-decoration:none; font-size:12px; white-space:nowrap; }.or-note { margin:0; padding:10px 12px; border:1px solid #f1d28b; border-radius:5px; color:#80621c; background:#fff9eb; font-size:11px; }
.or-filters { display:flex; flex-wrap:wrap; gap:5px; }.or-filters button { padding:6px 9px; border:1px solid #dce5ee; border-radius:4px; color:#687f95; background:#fff; font-size:10px; cursor:pointer; }.or-filters button.is-active { color:#08786e; border-color:#a8d9cf; background:#edf9f5; }
.or-table-wrap { overflow-x:auto; }table { width:100%; border-collapse:collapse; font-size:12px; }th { padding:9px; color:#536d86; background:#f4f7fb; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; }td { padding:10px 9px; border-bottom:1px solid #edf1f5; color:#344f69; }.or-empty { padding:18px; color:#687f95; text-align:center; font-size:12px; }.or-status { padding:4px 8px; border-radius:99px; color:#8b6820; background:#fff5d8; font-size:10px; font-weight:600; }.or-status.is-approved { color:#08785c; background:#e2f5ed; }.or-status.is-rejected { color:#ae3c43; background:#fdebed; }.or-status.is-cancelled { color:#6d7d8e; background:#eef1f4; }
.or button:focus-visible,.or a:focus-visible { outline:3px solid #8ab4f8; outline-offset:2px; }
/* Match the larger, easy-to-read type used on Time Management. */
.or { gap:16px; }
.or-head h1 { font-size:32px; }
.or-head p,.or-panel-head p { font-size:15px; }
.or-metric { min-height:148px; padding:18px 20px; gap:8px; }
.or-metric small { font-size:15px; }
.or-metric strong { font-size:31px; }
.or-metric strong span { font-size:14px; }
.or-metric p { font-size:12px; }
.or-panel { padding:18px 20px; }
.or-panel-head h2,.or-workstation .or-panel-head h2 { font-size:22px; }
.or-quick-label { font-size:13px; }
.or-cards { gap:14px; }
.or-card { padding:16px; gap:9px; }
.or-card strong { font-size:16px; }
.or-card > span:not(.or-card-icon) { min-height:44px; font-size:14px; }
.or-card small { font-size:12px; }
.or-card b { padding:11px 13px; font-size:14px; }
.or-workstation .or-panel-head p { font-size:15px; }
.or-tabs button { font-size:15px; }
.or-selected-form strong { font-size:16px; }
.or-selected-form p { font-size:14px; }
.or-submit { padding:11px 15px; font-size:14px; }
.or-note { font-size:13px; }
.or-filters button { padding:7px 10px; font-size:12px; }
.or-table-wrap table { font-size:13px; }
.or-table-wrap th { font-size:11px; }
.or-empty { font-size:13px; }
.or-status { font-size:11px; }
@media(max-width:700px) { .or-metrics,.or-cards { grid-template-columns:1fr; }.or-panel-head { align-items:flex-start; flex-wrap:wrap; }.or-workstation .or-panel-head { margin-bottom:20px; }.or-tabs { gap:12px; overflow-x:auto; }.or-tabs button { flex:none; font-size:12px; }.or-filters { width:100%; }.or-selected-form { align-items:flex-start; flex-direction:column; }.or-submit { width:100%; box-sizing:border-box; text-align:center; } }
</style>
