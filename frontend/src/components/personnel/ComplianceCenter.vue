<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { completeCompliance, getMyCompliances } from '../../services/complianceService'

const auth = useAuthStore()

/* ---------- Data (always from the API) ---------- */
const items = ref([])
const summary = ref(null)
const loading = ref(true)
const loadError = ref('')
const toast = ref('')

const load = async ({ quiet = false } = {}) => {
  if (!quiet) loading.value = true
  loadError.value = ''
  try {
    const { data } = await getMyCompliances()
    items.value = data.data
    summary.value = data.summary
  } catch (error) {
    // Server faults (5xx) can carry internal details such as SQL in debug mode, so never show their text.
    loadError.value = !error.response ? 'We couldn’t reach the server. Check your connection and try again.'
      : error.response.status >= 500 ? 'Something went wrong on our side. Please try again in a moment, or contact support if it keeps happening.'
      : error.response.data?.message || 'Your compliance items couldn’t be loaded.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---------- Presentation helpers ---------- */
const STATUS = {
  expired: { label: 'Expired', icon: 'alert-circle', tone: 'danger' },
  pending: { label: 'Action needed', icon: 'clock', tone: 'warning' },
  renew: { label: 'Renew soon', icon: 'refresh', tone: 'warning' },
  completed: { label: 'Up to date', icon: 'check-circle', tone: 'success' },
}
const statusOf = (item) => STATUS[item.status === 'completed' && item.renew_soon ? 'renew' : item.status]
const TYPE = {
  acknowledgement: { label: 'Read and acknowledge', icon: 'file-text', action: 'Review and acknowledge' },
  declaration: { label: 'Declaration', icon: 'pen', action: 'Review and declare' },
}
const typeOf = (item) => TYPE[item.requirement_type] || TYPE.acknowledgement
// Colour means status; categories stay neutral with a distinct dot, assigned in alphabetical order so none collide.
const CATEGORY_DOTS = ['blue', 'violet', 'teal', 'rose', 'slate']
const categoryTone = (category) => CATEGORY_DOTS[Math.max(0, categories.value.indexOf(category)) % CATEGORY_DOTS.length]
const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const actionLabel = (item) => (item.status === 'expired' || item.renew_soon ? 'Renew now' : item.status === 'pending' ? typeOf(item).action : 'View record')
const dateLine = (item) => {
  if (item.status === 'expired') return `Expired ${formatDate(item.expires_at)} · renew to stay compliant`
  if (item.status === 'pending') return item.validity_months ? `Renewed every ${item.validity_months} months once completed` : 'One-time requirement'
  return item.expires_at ? `Completed ${formatDate(item.completed_at)} · valid until ${formatDate(item.expires_at)}` : `Completed ${formatDate(item.completed_at)}`
}

/* ---------- Search and filters ---------- */
const search = ref('')
const statusFilter = ref('all')
const categoryFilter = ref('all')
const categories = computed(() => [...new Set(items.value.map((item) => item.category))].sort())
const filtersActive = computed(() => search.value.trim() !== '' || statusFilter.value !== 'all' || categoryFilter.value !== 'all')
const clearFilters = () => { search.value = ''; statusFilter.value = 'all'; categoryFilter.value = 'all' }
const toggleStatus = (value) => { statusFilter.value = statusFilter.value === value ? 'all' : value }
// Things that need attention come first: expired, then outstanding, then renewals, then done.
const rank = (item) => (item.status === 'expired' ? 0 : item.status === 'pending' ? 1 : item.renew_soon ? 2 : 3)
const filtered = computed(() => {
  const query = search.value.trim().toLowerCase()
  return items.value
    .filter((item) => !query || [item.title, item.category, item.description, item.statement, typeOf(item).label].join(' ').toLowerCase().includes(query))
    .filter((item) => statusFilter.value === 'all' || item.status === statusFilter.value)
    .filter((item) => categoryFilter.value === 'all' || item.category === categoryFilter.value)
    .sort((a, b) => rank(a) - rank(b))
})
const attention = computed(() => (summary.value ? summary.value.expired + summary.value.pending + summary.value.renew_soon : 0))

/* ---------- Detail dialog and completion ---------- */
const dialog = ref(null)
const selected = ref(null)
const form = reactive({ acknowledged: false, has_conflict: null, details: '', signature: '' })
const errors = ref({})
const formError = ref('')
const saving = ref(false)
const showForm = computed(() => selected.value?.can_complete)

const open = async (item) => {
  selected.value = item
  Object.assign(form, { acknowledged: false, has_conflict: null, details: '', signature: '' })
  errors.value = {}
  formError.value = ''
  await nextTick()
  dialog.value?.showModal()
}
const close = () => dialog.value?.close()
const onBackdrop = (event) => { if (event.target === dialog.value) close() }

const validate = () => {
  const e = {}
  if (selected.value.requirement_type === 'declaration') {
    if (form.has_conflict === null) e.has_conflict = 'Choose whether you have a conflict of interest to declare.'
    else if (form.has_conflict && !form.details.trim()) e.details = 'Describe the conflict of interest so HR can review it.'
  } else if (!form.acknowledged) e.acknowledged = 'Tick the box to confirm you have read and understood this policy.'
  if (!form.signature.trim()) e.signature = 'Type your full name to sign.'
  errors.value = e
  return Object.keys(e).length === 0
}
const submit = async () => {
  formError.value = ''
  if (!validate()) { await nextTick(); dialog.value?.querySelector('[aria-invalid="true"]')?.focus(); return }
  saving.value = true
  const payload = selected.value.requirement_type === 'declaration'
    ? { has_conflict: form.has_conflict, details: form.has_conflict ? form.details.trim() : null, signature: form.signature.trim() }
    : { acknowledged: true, signature: form.signature.trim() }
  try {
    const { data } = await completeCompliance(selected.value.id, payload)
    const saved = data.data
    close()
    toast.value = `${saved.title} ${saved.requirement_type === 'declaration' ? 'declaration submitted' : 'acknowledged'}.${saved.expires_at ? ` You’re covered until ${formatDate(saved.expires_at)}.` : ''}`
    await load({ quiet: true }) // counts and progress come from what the server saved
  } catch (error) {
    const fieldErrors = error.response?.data?.errors || {}
    errors.value = Object.fromEntries(Object.entries(fieldErrors).filter(([key]) => key !== 'policy').map(([key, value]) => [key, [value].flat()[0]]))
    formError.value = !error.response ? 'Your confirmation wasn’t saved because we couldn’t reach the server. Check your connection and try again.'
      : fieldErrors.policy ? [fieldErrors.policy].flat()[0]
      : Object.keys(errors.value).length ? '' : error.response.status < 500 && error.response.data?.message || 'Your confirmation wasn’t saved. Please try again.'
    if (fieldErrors.policy) await load({ quiet: true })
  } finally {
    saving.value = false
  }
}
const err = (key) => errors.value[key] || ''
// An error disappears as soon as the field it's about changes.
watch(() => ({ ...form }), (now, before) => {
  const changed = Object.keys(now).filter((key) => now[key] !== before[key])
  if (changed.includes('has_conflict')) changed.push('details')
  if (changed.some((key) => errors.value[key])) errors.value = Object.fromEntries(Object.entries(errors.value).filter(([key]) => !changed.includes(key)))
})
</script>

<template>
  <div class="cc">
    <header class="cc-head">
      <div>
        <h1>Compliances</h1>
        <p>Policies and declarations you need to confirm. Each confirmation is saved to your HR record.</p>
      </div>
    </header>

    <p v-if="toast" class="cc-toast" role="status"><AppIcon name="check-circle" :size="18" /><span>{{ toast }}</span><button type="button" class="cc-icon-btn" aria-label="Dismiss" @click="toast = ''"><AppIcon name="x" :size="16" /></button></p>

    <!-- Loading -->
    <div v-if="loading" class="cc-skeleton" aria-busy="true" aria-label="Loading your compliance items">
      <div class="cc-skeleton__summary"><span v-for="n in 4" :key="n"></span></div>
      <div class="cc-grid"><span v-for="n in 3" :key="n" class="cc-skeleton__card"></span></div>
    </div>

    <!-- Load error -->
    <section v-else-if="loadError" class="cc-state cc-state--error" role="alert">
      <AppIcon name="alert-circle" :size="28" />
      <h2>Compliance items didn’t load</h2>
      <p>{{ loadError }}</p>
      <button type="button" class="cc-btn cc-btn--primary" @click="load()"><AppIcon name="refresh" :size="16" />Try again</button>
    </section>

    <!-- Nothing assigned -->
    <section v-else-if="!items.length" class="cc-state">
      <AppIcon name="shield-check" :size="28" />
      <h2>No compliance requirements</h2>
      <p>HR hasn’t assigned any policies or declarations to you.</p>
    </section>

    <template v-else>
      <section class="cc-summary" aria-label="Compliance summary">
        <article class="cc-progress">
          <div class="cc-progress__top"><span>Overall progress</span><strong>{{ summary.percent }}%</strong></div>
          <div class="cc-progress__track" role="progressbar" :aria-valuenow="summary.percent" aria-valuemin="0" aria-valuemax="100" aria-label="Compliance progress"><i :style="{ width: `${summary.percent}%` }"></i></div>
          <p>{{ summary.completed }} of {{ summary.required }} required items up to date</p>
        </article>
        <button v-for="tile in [{ key: 'completed', label: 'Up to date', icon: 'check-circle', tone: 'success' }, { key: 'pending', label: 'Action needed', icon: 'clock', tone: 'warning' }, { key: 'expired', label: 'Expired', icon: 'alert-circle', tone: 'danger' }]" :key="tile.key" type="button" class="cc-stat" :class="[`is-${tile.tone}`, { 'is-selected': statusFilter === tile.key, 'is-zero': !summary[tile.key] }]" :aria-pressed="statusFilter === tile.key" @click="toggleStatus(tile.key)">
          <span class="cc-stat__icon"><AppIcon :name="tile.icon" :size="18" /></span>
          <span class="cc-stat__body"><strong>{{ summary[tile.key] }}</strong><small>{{ tile.label }}</small></span>
        </button>
      </section>

      <p v-if="attention" class="cc-attention"><AppIcon name="info" :size="18" /><span><strong>{{ attention }} {{ attention === 1 ? 'item needs' : 'items need' }} your attention.</strong> They’re listed first.</span></p>
      <p v-else class="cc-attention is-good"><AppIcon name="check-circle" :size="18" /><span><strong>You’re fully compliant.</strong> We’ll show items here again when they’re due for renewal.</span></p>

      <div class="cc-toolbar" role="search">
        <label class="cc-search">
          <span class="sr-only">Search compliance items</span>
          <AppIcon name="search" :size="18" />
          <input v-model="search" type="search" placeholder="Search by policy, category or statement" />
        </label>
        <label class="cc-select"><span class="sr-only">Filter by status</span>
          <select v-model="statusFilter"><option value="all">All statuses</option><option value="pending">Action needed</option><option value="completed">Up to date</option><option value="expired">Expired</option></select>
        </label>
        <label class="cc-select"><span class="sr-only">Filter by category</span>
          <select v-model="categoryFilter"><option value="all">All categories</option><option v-for="category in categories" :key="category" :value="category">{{ category }}</option></select>
        </label>
        <button v-if="filtersActive" type="button" class="cc-btn cc-btn--ghost cc-btn--sm" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
      </div>
      <p class="cc-count" aria-live="polite">Showing {{ filtered.length }} of {{ items.length }}</p>

      <section v-if="filtered.length" class="cc-grid" aria-label="Compliance items">
        <article v-for="item in filtered" :key="item.id" class="cc-card" :class="`is-${statusOf(item).tone}`">
          <header class="cc-card__top">
            <span class="cc-category" :class="`tone-${categoryTone(item.category)}`">{{ item.category }}</span>
            <span class="cc-badge" :class="`is-${statusOf(item).tone}`"><AppIcon :name="statusOf(item).icon" :size="14" />{{ statusOf(item).label }}</span>
          </header>
          <h3>{{ item.title }}</h3>
          <p class="cc-card__desc">{{ item.description }}</p>
          <ul class="cc-card__meta">
            <li><AppIcon :name="typeOf(item).icon" :size="15" />{{ typeOf(item).label }}</li>
            <li><AppIcon name="calendar" :size="15" />{{ dateLine(item) }}</li>
          </ul>
          <footer class="cc-card__foot">
            <button type="button" class="cc-btn" :class="item.can_complete ? 'cc-btn--primary' : 'cc-btn--ghost'" :aria-label="`${actionLabel(item)}: ${item.title}`" @click="open(item)">
              {{ actionLabel(item) }}<AppIcon :name="item.can_complete ? 'arrow-right' : 'eye'" :size="16" />
            </button>
          </footer>
        </article>
      </section>
      <section v-else class="cc-state cc-state--compact">
        <AppIcon name="search" :size="24" />
        <h2>No items match</h2>
        <p>Try a different search or remove a filter.</p>
        <button type="button" class="cc-btn cc-btn--ghost" @click="clearFilters"><AppIcon name="filter-x" :size="16" />Clear filters</button>
      </section>
    </template>

    <dialog ref="dialog" class="cc-dialog" aria-labelledby="cc-dialog-title" @click="onBackdrop" @close="selected = null">
      <form v-if="selected" class="cc-dialog__inner" novalidate @submit.prevent="submit">
        <header class="cc-dialog__head">
          <div>
            <div class="cc-dialog__chips">
              <span class="cc-category" :class="`tone-${categoryTone(selected.category)}`">{{ selected.category }}</span>
              <span class="cc-badge" :class="`is-${statusOf(selected).tone}`"><AppIcon :name="statusOf(selected).icon" :size="14" />{{ statusOf(selected).label }}</span>
            </div>
            <h2 id="cc-dialog-title">{{ selected.title }}</h2>
          </div>
          <button type="button" class="cc-icon-btn" aria-label="Close" @click="close"><AppIcon name="x" :size="20" /></button>
        </header>

        <div class="cc-dialog__body">
          <section>
            <h3>What this covers</h3>
            <p>{{ selected.description }}</p>
          </section>
          <section>
            <h3>Policy document</h3>
            <a v-if="selected.document_url" :href="selected.document_url" target="_blank" rel="noopener" class="cc-doc"><AppIcon name="file-text" :size="18" />Open the {{ selected.title }} policy<AppIcon name="arrow-up-right" :size="15" /></a>
            <p v-else class="cc-muted">HR hasn’t attached the policy document here yet. Ask HR for a copy and read it before you confirm.</p>
          </section>
          <p v-if="selected.validity_months" class="cc-muted"><AppIcon name="refresh" :size="15" /> Valid for {{ selected.validity_months }} months after you confirm, then you’ll be asked to renew it.</p>

          <!-- Existing record -->
          <section v-if="selected.completed_at" class="cc-record" :class="{ 'is-expired': selected.status === 'expired' }">
            <h3>{{ selected.status === 'expired' ? 'Your last confirmation' : 'Your confirmation' }}</h3>
            <dl>
              <div><dt>Signed by</dt><dd>{{ selected.signature }}</dd></div>
              <div><dt>Completed</dt><dd>{{ formatDate(selected.completed_at) }}</dd></div>
              <div v-if="selected.expires_at"><dt>{{ selected.status === 'expired' ? 'Expired' : 'Valid until' }}</dt><dd>{{ formatDate(selected.expires_at) }}</dd></div>
              <div v-if="selected.requirement_type === 'declaration'"><dt>Declared</dt><dd>{{ selected.response?.has_conflict ? 'A conflict of interest' : 'No conflict of interest' }}</dd></div>
            </dl>
            <p v-if="selected.response?.details" class="cc-record__details">{{ selected.response.details }}</p>
          </section>

          <!-- Completion form, only when the item can be completed or renewed -->
          <section v-if="showForm" class="cc-confirm">
            <h3>{{ selected.requirement_type === 'declaration' ? 'Your declaration' : 'Confirm' }}</h3>
            <p v-if="formError" class="cc-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>

            <template v-if="selected.requirement_type === 'declaration'">
              <fieldset class="cc-choice" :aria-describedby="err('has_conflict') ? 'cc-conflict-error' : undefined">
                <legend class="sr-only">Do you have a conflict of interest to declare?</legend>
                <label :class="{ 'is-checked': form.has_conflict === false }"><input v-model="form.has_conflict" type="radio" name="cc-conflict" :value="false" :aria-invalid="err('has_conflict') ? 'true' : undefined" /><span><strong>{{ selected.statement }}</strong></span></label>
                <label :class="{ 'is-checked': form.has_conflict === true }"><input v-model="form.has_conflict" type="radio" name="cc-conflict" :value="true" /><span><strong>I have a conflict of interest to declare.</strong><small>HR will review the details you give below.</small></span></label>
              </fieldset>
              <p v-if="err('has_conflict')" id="cc-conflict-error" class="cc-error"><AppIcon name="alert-circle" :size="14" />{{ err('has_conflict') }}</p>
              <div v-if="form.has_conflict" class="cc-field">
                <label for="cc-details">Describe the conflict <span class="cc-req" aria-hidden="true">*</span></label>
                <textarea id="cc-details" v-model="form.details" rows="3" maxlength="2000" placeholder="Who is involved, and how it relates to your work" :aria-invalid="err('details') ? 'true' : undefined" :aria-describedby="err('details') ? 'cc-details-error' : undefined"></textarea>
                <p v-if="err('details')" id="cc-details-error" class="cc-error"><AppIcon name="alert-circle" :size="14" />{{ err('details') }}</p>
              </div>
            </template>
            <template v-else>
              <label class="cc-check" :class="{ 'has-error': err('acknowledged') }">
                <input id="cc-ack" v-model="form.acknowledged" type="checkbox" :aria-invalid="err('acknowledged') ? 'true' : undefined" :aria-describedby="err('acknowledged') ? 'cc-ack-error' : undefined" />
                <span>{{ selected.statement }}</span>
              </label>
              <p v-if="err('acknowledged')" id="cc-ack-error" class="cc-error"><AppIcon name="alert-circle" :size="14" />{{ err('acknowledged') }}</p>
            </template>

            <div class="cc-field">
              <label for="cc-signature">Signature <span class="cc-req" aria-hidden="true">*</span></label>
              <input id="cc-signature" v-model="form.signature" autocomplete="name" :placeholder="auth.user?.name" :aria-invalid="err('signature') ? 'true' : undefined" :aria-describedby="err('signature') ? 'cc-signature-error' : 'cc-signature-hint'" />
              <p v-if="err('signature')" id="cc-signature-error" class="cc-error"><AppIcon name="alert-circle" :size="14" />{{ err('signature') }}</p>
              <p v-else id="cc-signature-hint" class="cc-hint">Type your full name as it appears on your account: <strong>{{ auth.user?.name }}</strong></p>
            </div>
          </section>
        </div>

        <footer class="cc-dialog__foot">
          <button type="button" class="cc-btn cc-btn--ghost" @click="close">{{ showForm ? 'Cancel' : 'Close' }}</button>
          <button v-if="showForm" type="submit" class="cc-btn cc-btn--primary" :disabled="saving" :aria-busy="saving">
            <AppIcon :name="saving ? 'refresh' : 'check'" :size="16" :class="{ 'cc-spin': saving }" />{{ saving ? 'Saving…' : selected.requirement_type === 'declaration' ? 'Submit declaration' : 'Confirm and sign' }}
          </button>
        </footer>
      </form>
    </dialog>
  </div>
</template>

<style>
.cc { --cc-ink: #17365f; --cc-text: #3d5470; --cc-muted: #6b8099; --cc-line: #dfe7ef; --cc-soft: #f6f9fc; --cc-brand: #0b7f62; --cc-brand-dark: #086a51; --cc-brand-soft: #e8f5f0; --cc-success: #14804a; --cc-success-soft: #e7f6ee; --cc-warning: #a35f00; --cc-warning-soft: #fff4dc; --cc-danger: #c0392b; --cc-danger-soft: #fdf1f0; width: min(100%, 1180px); margin: 0 auto; padding: 24px 0 48px; color: var(--cc-text); font-size: 14px; line-height: 1.5; }
.cc button { margin: 0; } /* older global styles give every button a 28px top margin */
.cc h1, .cc h2, .cc h3 { color: var(--cc-ink); letter-spacing: -.01em; }
.cc-head { margin-bottom: 18px; }
.cc-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.cc-head p { margin: 0; color: var(--cc-muted); }

.cc-toast { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 10px 10px 10px 14px; border: 1px solid #bfe3d6; border-radius: 10px; background: var(--cc-brand-soft); color: var(--cc-brand-dark); font-weight: 600; }
.cc-toast span { flex: 1; }

/* Summary */
.cc-summary { display: grid; grid-template-columns: minmax(260px, 1.6fr) repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.cc-progress, .cc-stat { border: 1px solid var(--cc-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.cc-progress { padding: 16px 18px; }
.cc-progress__top { display: flex; align-items: baseline; justify-content: space-between; color: var(--cc-muted); font-weight: 600; }
.cc-progress__top strong { color: var(--cc-ink); font-size: 1.6rem; line-height: 1.1; }
.cc-progress__track { height: 8px; margin: 10px 0 8px; overflow: hidden; border-radius: 99px; background: #e6edf3; }
.cc-progress__track i { display: block; height: 100%; border-radius: inherit; background: var(--cc-brand); transition: width .4s ease; }
.cc-progress p { margin: 0; color: var(--cc-muted); font-size: .86rem; }
.cc-stat { display: flex; align-items: center; gap: 12px; padding: 14px 16px; color: var(--cc-ink); text-align: left; cursor: pointer; transition: border-color .15s, box-shadow .15s, background-color .15s; }
.cc-stat:hover { border-color: #b9c9d9; transform: none !important; }
.cc-stat__icon { display: grid; width: 38px; height: 38px; flex: none; place-items: center; border-radius: 10px; }
.cc-stat__body { display: grid; }
.cc-stat__body strong { font-size: 1.45rem; line-height: 1.1; }
.cc-stat__body small { color: var(--cc-muted); font-size: .84rem; font-weight: 600; }
.cc-stat.is-success .cc-stat__icon { background: var(--cc-success-soft); color: var(--cc-success); }
.cc-stat.is-warning .cc-stat__icon { background: var(--cc-warning-soft); color: var(--cc-warning); }
.cc-stat.is-danger .cc-stat__icon { background: var(--cc-danger-soft); color: var(--cc-danger); }
.cc-stat.is-zero .cc-stat__icon { background: var(--cc-soft); color: #9aabbd; }
.cc-stat.is-selected { border-color: var(--cc-brand); box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }

.cc-attention { display: flex; align-items: center; gap: 10px; margin: 0 0 18px; padding: 10px 14px; border: 1px solid #f1dfae; border-radius: 10px; background: #fffaeb; color: #7a5a12; font-size: .9rem; }
.cc-attention.is-good { border-color: #bfe3d6; background: var(--cc-brand-soft); color: var(--cc-brand-dark); }
.cc-attention svg { flex: none; }

/* Toolbar */
.cc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.cc-search { position: relative; display: flex; min-width: 240px; flex: 1 1 320px; align-items: center; }
.cc-search svg { position: absolute; left: 13px; color: #8a9bb0; pointer-events: none; }
.cc input, .cc select, .cc textarea { box-sizing: border-box; width: 100%; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--cc-ink); font: inherit; font-size: .93rem; transition: border-color .15s, box-shadow .15s; }
.cc input:not([type="checkbox"]):not([type="radio"]), .cc select { height: 42px; padding: 0 13px; }
.cc-search input { padding-left: 40px !important; }
.cc textarea { padding: 10px 13px; resize: vertical; }
.cc input:focus, .cc select:focus, .cc textarea:focus { border-color: var(--cc-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.cc [aria-invalid="true"]:not([type="radio"]):not([type="checkbox"]) { border-color: var(--cc-danger); }
.cc-select { flex: 0 1 190px; }
.cc-count { margin: 10px 0 12px; color: var(--cc-muted); font-size: .85rem; }

/* Cards */
.cc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 14px; }
.cc-card { position: relative; display: flex; flex-direction: column; padding: 18px 18px 16px; overflow: hidden; border: 1px solid var(--cc-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.cc-card::before { position: absolute; top: 0; left: 0; right: 0; height: 3px; background: transparent; content: ""; }
.cc-card.is-danger { border-color: #f2c9c4; }
.cc-card.is-danger::before { background: var(--cc-danger); }
.cc-card.is-warning::before { background: #e3a008; }
.cc-card.is-success::before { background: var(--cc-success); }
.cc-card__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.cc-card h3 { margin: 0 0 4px; font-size: 1.05rem; font-weight: 700; }
.cc-card__desc { display: -webkit-box; margin: 0 0 12px; overflow: hidden; color: var(--cc-text); font-size: .9rem; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.cc-card__meta { display: grid; gap: 6px; margin: 0 0 16px; padding: 12px 0 0; border-top: 1px solid var(--cc-line); list-style: none; color: var(--cc-muted); font-size: .84rem; }
.cc-card__meta li { display: flex; align-items: flex-start; gap: 8px; }
.cc-card__meta svg { flex: none; margin-top: 2px; }
.cc-card__foot { margin-top: auto; }
.cc-card__foot .cc-btn { width: 100%; }

.cc-category { display: inline-flex; align-items: center; gap: 7px; padding: 3px 10px; border: 1px solid var(--cc-line); border-radius: 99px; background: var(--cc-soft); color: #44586f; font-size: .76rem; font-weight: 600; }
.cc-category::before { width: 7px; height: 7px; border-radius: 50%; background: var(--dot); content: ""; }
.tone-blue { --dot: #3b74c4; }
.tone-violet { --dot: #7c4fd6; }
.tone-teal { --dot: #138a80; }
.tone-rose { --dot: #d14f7a; }
.tone-slate { --dot: #6b7f95; }
.cc-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: .76rem; font-weight: 700; white-space: nowrap; }
.cc-badge.is-success { background: var(--cc-success-soft); color: var(--cc-success); }
.cc-badge.is-warning { background: var(--cc-warning-soft); color: var(--cc-warning); }
.cc-badge.is-danger { background: var(--cc-danger-soft); color: var(--cc-danger); }

/* Buttons */
.cc-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 18px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .92rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
.cc-btn--sm { min-height: 42px; padding: 0 14px; font-size: .88rem; }
.cc-btn--primary { background: var(--cc-brand); color: #fff; }
.cc-btn--primary:hover:not(:disabled) { background: var(--cc-brand-dark); }
.cc-btn--primary:disabled { opacity: .7; cursor: progress; }
.cc-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--cc-ink); }
.cc-btn--ghost:hover { border-color: #aebfd1; background: var(--cc-soft); }
.cc-icon-btn { display: grid; width: 36px; height: 36px; flex: none; place-items: center; padding: 0; border: 0; border-radius: 9px; background: transparent; color: var(--cc-muted); cursor: pointer; }
.cc-icon-btn:hover { background: rgba(23, 54, 95, .07); color: var(--cc-ink); transform: none !important; }
.cc-spin { animation: cc-spin 1s linear infinite; }
@keyframes cc-spin { to { transform: rotate(360deg); } }

/* States */
.cc-state { display: grid; justify-items: center; gap: 6px; padding: 44px 24px; border: 1px dashed #d6e0ea; border-radius: 14px; background: #fff; color: var(--cc-muted); text-align: center; }
.cc-state h2 { margin: 6px 0 0; font-size: 1.1rem; }
.cc-state p { margin: 0 0 8px; }
.cc-state--error { border-style: solid; border-color: #f2c4bf; color: var(--cc-danger); }
.cc-state--compact { padding: 32px 20px; }
.cc-skeleton__summary { display: grid; grid-template-columns: 1.6fr repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
.cc-skeleton__summary span, .cc-skeleton__card { display: block; height: 92px; border-radius: 14px; background: linear-gradient(90deg, #eef2f6 25%, #f6f9fc 50%, #eef2f6 75%); background-size: 200% 100%; animation: cc-shimmer 1.4s ease infinite; }
.cc-skeleton__card { height: 220px; }
@keyframes cc-shimmer { to { background-position: -200% 0; } }

/* Dialog */
.cc-dialog { margin: auto; width: min(100% - 32px, 600px); max-height: min(100% - 32px, 760px); padding: 0; border: 0; border-radius: 16px; color: var(--cc-text); box-shadow: 0 24px 70px rgba(16, 36, 61, .3); }
.cc-dialog::backdrop { background: rgba(17, 36, 59, .5); }
.cc-dialog[open] { display: flex; flex-direction: column; }
.cc-dialog__inner { display: flex; min-height: 0; flex: 1 1 auto; flex-direction: column; } /* header and footer stay put; only the body scrolls */
.cc-dialog__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px 22px 14px; border-bottom: 1px solid var(--cc-line); }
.cc-dialog__chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.cc-dialog__head h2 { margin: 0; font-size: 1.3rem; font-weight: 700; }
.cc-dialog__body { display: grid; min-height: 0; flex: 1 1 auto; gap: 18px; padding: 18px 22px; overflow-y: auto; }
.cc-dialog__body h3 { margin: 0 0 6px; font-size: .95rem; font-weight: 700; }
.cc-dialog__body section > p { margin: 0; }
.cc-muted { display: flex; align-items: flex-start; gap: 6px; margin: 0; color: var(--cc-muted); font-size: .88rem; }
.cc-muted svg { flex: none; margin-top: 3px; }
.cc-doc { display: inline-flex; align-items: center; gap: 8px; color: var(--cc-brand-dark); font-weight: 600; }
.cc-record { padding: 14px 16px; border: 1px solid #bfe3d6; border-radius: 12px; background: #f6fbf9; }
.cc-record.is-expired { border-color: #f2c4bf; background: #fffbfa; }
.cc-record dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 16px; margin: 0; }
.cc-record dt { color: var(--cc-muted); font-size: .8rem; }
.cc-record dd { margin: 0; color: var(--cc-ink); font-weight: 600; }
.cc-record__details { margin: 10px 0 0 !important; padding-top: 10px; border-top: 1px solid var(--cc-line); }
.cc-confirm { display: grid; gap: 12px; padding-top: 16px; border-top: 1px solid var(--cc-line); }
.cc-confirm h3 { margin: 0; }
.cc-check, .cc-choice label { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border: 1px solid var(--cc-line); border-radius: 12px; color: var(--cc-ink); cursor: pointer; }
.cc-check:hover, .cc-choice label:hover { border-color: #aebfd1; }
.cc-check:has(input:checked), .cc-choice label.is-checked { border-color: #9fd6c3; background: #f6fbf9; }
.cc-check.has-error { border-color: #f2c4bf; background: #fffbfa; }
.cc-check input, .cc-choice input { width: 20px; height: 20px; flex: none; margin: 1px 0 0; accent-color: var(--cc-brand); cursor: pointer; }
.cc-choice { display: grid; gap: 8px; margin: 0; padding: 0; border: 0; }
.cc-choice span { display: grid; gap: 2px; }
.cc-choice strong { font-weight: 600; }
.cc-choice small { color: var(--cc-muted); }
.cc-field { display: grid; gap: 6px; }
.cc-field > label { display: block; color: var(--cc-ink); font-size: .88rem; font-weight: 600; }
.cc-req { color: var(--cc-danger); }
.cc-hint { margin: 0; color: var(--cc-muted); font-size: .82rem; }
.cc-error { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--cc-danger); font-size: .82rem; font-weight: 500; }
.cc-alert { display: flex; align-items: flex-start; gap: 8px; margin: 0; padding: 10px 12px; border: 1px solid #f2c4bf; border-radius: 10px; background: var(--cc-danger-soft); color: #9b2c22; font-size: .88rem; font-weight: 600; }
.cc-alert svg { flex: none; margin-top: 1px; }
.cc-dialog__foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--cc-line); background: var(--cc-soft); }

@media (max-width: 980px) {
  .cc-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .cc-progress { grid-column: 1 / -1; }
}
@media (max-width: 640px) {
  .cc { padding-top: 16px; }
  .cc-head h1 { font-size: 1.35rem; }
  .cc-summary { gap: 8px; }
  .cc-stat { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px; }
  .cc-stat__icon { width: 32px; height: 32px; }
  .cc-search { min-width: 0; flex-basis: 100%; }
  .cc-select { flex: 1 1 0; }
  .cc-toolbar > .cc-btn { flex-basis: 100%; }
  .cc-skeleton__summary { grid-template-columns: 1fr 1fr; }
  .cc-dialog { width: 100%; max-width: none; max-height: 92%; margin: auto 0 0; border-radius: 16px 16px 0 0; }
  .cc-dialog__head, .cc-dialog__body, .cc-dialog__foot { padding-inline: 16px; }
  .cc-record dl { grid-template-columns: 1fr; }
  .cc-dialog__foot .cc-btn { flex: 1; }
}
@media (prefers-reduced-motion: reduce) {
  .cc-progress__track i, .cc-skeleton__summary span, .cc-skeleton__card, .cc-spin { transition: none; animation: none; }
}
</style>
