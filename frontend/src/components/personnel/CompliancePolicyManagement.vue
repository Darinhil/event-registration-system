<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import { createCompliancePolicy, deleteCompliancePolicy, getCompliancePolicies, updateCompliancePolicy } from '../../services/complianceService'

const policies = ref([])
const loading = ref(true)
const loadError = ref('')
const toast = ref('')
const formDialog = ref(null)
const detailsDialog = ref(null)
const deleteDialog = ref(null)
const editing = ref(null)
const selected = ref(null)
const deleting = ref(null)
const saving = ref(false)
const deletingBusy = ref(false)
const requestError = ref('')
const fieldErrors = ref({})
const deleteError = ref('')
const form = reactive({
  title: '',
  category: '',
  description: '',
  requirement_type: 'acknowledgement',
  statement: '',
  document_url: '',
  validity_months: '',
  is_active: true,
  sort_order: 0,
})

const describeError = (error, fallback) => (!error.response
  ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500
    ? 'Something went wrong on our side. Please try again in a moment.'
    : error.response.data?.message || fallback)

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    policies.value = (await getCompliancePolicies()).data.data
  } catch (error) {
    loadError.value = describeError(error, 'Compliance policies couldn’t be loaded.')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const categoryIcon = (category) => {
  const value = category.toLowerCase()
  if (value.includes('financial') || value.includes('integrity')) return 'alert-circle'
  if (value.includes('ethic')) return 'check-circle'
  if (value.includes('privacy') || value.includes('data')) return 'lock'
  return 'shield-check'
}
const categoryTone = (category) => {
  const value = category.toLowerCase()
  if (value.includes('financial') || value.includes('integrity')) return 'amber'
  if (value.includes('ethic')) return 'violet'
  if (value.includes('privacy') || value.includes('data')) return 'blue'
  return 'teal'
}

const resetForm = () => {
  Object.assign(form, {
    title: '',
    category: '',
    description: '',
    requirement_type: 'acknowledgement',
    statement: '',
    document_url: '',
    validity_months: '',
    is_active: true,
    sort_order: policies.value.length,
  })
  requestError.value = ''
  fieldErrors.value = {}
}
const openCreate = async () => {
  editing.value = null
  resetForm()
  await nextTick()
  formDialog.value?.showModal()
  formDialog.value?.querySelector('input')?.focus()
}
const openEdit = async (policy) => {
  editing.value = policy
  Object.assign(form, {
    title: policy.title,
    category: policy.category,
    description: policy.description,
    requirement_type: policy.requirement_type,
    statement: policy.statement,
    document_url: policy.document_url || '',
    validity_months: policy.validity_months ?? '',
    is_active: policy.is_active,
    sort_order: policy.sort_order,
  })
  requestError.value = ''
  fieldErrors.value = {}
  await nextTick()
  formDialog.value?.showModal()
  formDialog.value?.querySelector('input')?.focus()
}
const save = async () => {
  requestError.value = ''
  fieldErrors.value = {}
  saving.value = true
  const payload = {
    ...form,
    document_url: form.document_url.trim() || null,
    validity_months: form.validity_months === '' ? null : Number(form.validity_months),
    sort_order: Number(form.sort_order),
  }
  try {
    const response = editing.value
      ? await updateCompliancePolicy(editing.value.id, payload)
      : await createCompliancePolicy(payload)
    formDialog.value?.close()
    toast.value = `${response.data.data.title} ${editing.value ? 'updated' : 'added'}.`
    await load()
  } catch (error) {
    fieldErrors.value = error.response?.data?.errors || {}
    requestError.value = Object.keys(fieldErrors.value).length
      ? ''
      : describeError(error, 'The policy couldn’t be saved.')
  } finally {
    saving.value = false
  }
}
const fieldError = (key) => fieldErrors.value[key]?.[0] || ''
const showDetails = async (policy) => {
  selected.value = policy
  await nextTick()
  detailsDialog.value?.showModal()
}
const askDelete = async (policy) => {
  deleting.value = policy
  deleteError.value = ''
  await nextTick()
  deleteDialog.value?.showModal()
}
const confirmDelete = async () => {
  if (!deleting.value) return
  deletingBusy.value = true
  deleteError.value = ''
  try {
    await deleteCompliancePolicy(deleting.value.id)
    deleteDialog.value?.close()
    toast.value = `${deleting.value.title} deleted.`
    await load()
  } catch (error) {
    deleteError.value = describeError(error, 'The policy couldn’t be deleted.')
  } finally {
    deletingBusy.value = false
  }
}
const onBackdrop = (event, dialog) => {
  if (event.target === dialog) dialog.close()
}
const closeDialog = (dialog) => dialog?.close()
</script>

<template>
  <main class="cpm">
    <header class="cpm-head">
      <div>
        <h1>Required Compliance</h1>
        <p>Policies and declarations assigned to staff, with response history.</p>
      </div>
      <button type="button" class="cpm-btn cpm-btn--primary" @click="openCreate"><AppIcon name="plus" :size="16" />Add policy</button>
    </header>

    <p v-if="toast" class="cpm-toast" role="status">
      <AppIcon name="check-circle" :size="17" /><span>{{ toast }}</span>
      <button type="button" aria-label="Dismiss" @click="toast = ''"><AppIcon name="x" :size="15" /></button>
    </p>

    <section v-if="loading" class="cpm-grid" aria-busy="true" aria-label="Loading policies">
      <div v-for="index in 3" :key="index" class="cpm-skeleton"></div>
    </section>
    <section v-else-if="loadError" class="cpm-state" role="alert">
      <AppIcon name="alert-circle" :size="26" />
      <p>{{ loadError }}</p>
      <button type="button" class="cpm-btn" @click="load"><AppIcon name="refresh" :size="15" />Try again</button>
    </section>
    <section v-else-if="!policies.length" class="cpm-state">
      <AppIcon name="shield-check" :size="28" />
      <h2>No compliance policies yet</h2>
      <p>Add the organization’s first required policy.</p>
      <button type="button" class="cpm-btn cpm-btn--primary" @click="openCreate"><AppIcon name="plus" :size="16" />Add policy</button>
    </section>
    <section v-else class="cpm-grid" aria-label="Required compliance policies">
      <article v-for="policy in policies" :key="policy.id" class="cpm-card">
        <div class="cpm-card__top">
          <span class="cpm-icon" :class="`tone-${categoryTone(policy.category)}`"><AppIcon :name="categoryIcon(policy.category)" :size="18" /></span>
          <span class="cpm-status" :class="{ 'is-inactive': !policy.is_active }">{{ policy.is_active ? 'Active' : 'Inactive' }}</span>
        </div>
        <h2>{{ policy.title }}</h2>
        <p class="cpm-category" :class="`tone-${categoryTone(policy.category)}`">{{ policy.category }}</p>
        <p class="cpm-description">{{ policy.statement }}</p>
        <p class="cpm-assigned">Assigned by HR<span v-if="policy.records_count"> · {{ policy.records_count }} response{{ policy.records_count === 1 ? '' : 's' }}</span></p>
        <div class="cpm-card__actions">
          <button type="button" class="cpm-btn" @click="showDetails(policy)">View Details</button>
          <span class="cpm-completion" :class="{ 'is-inactive': !policy.is_active }">{{ policy.is_active ? 'Required' : 'Inactive' }}</span>
        </div>
        <footer class="cpm-card__manage">
          <button type="button" class="cpm-btn cpm-btn--small" @click="openEdit(policy)">Edit policy</button>
          <button type="button" class="cpm-btn cpm-btn--small cpm-btn--danger" @click="askDelete(policy)">Delete policy</button>
        </footer>
      </article>
    </section>

    <dialog ref="formDialog" class="cpm-dialog" aria-labelledby="cpm-form-title" @click="onBackdrop($event, formDialog)">
      <form class="cpm-dialog__inner" novalidate @submit.prevent="save">
        <header class="cpm-dialog__head">
          <div>
            <p class="cpm-eyebrow">Compliance policy</p>
            <h2 id="cpm-form-title">{{ editing ? 'Edit policy' : 'Add policy' }}</h2>
          </div>
          <button type="button" class="cpm-close" aria-label="Close" @click="closeDialog(formDialog)"><AppIcon name="x" :size="19" /></button>
        </header>
        <div class="cpm-dialog__body">
          <p v-if="requestError" class="cpm-error-box" role="alert">{{ requestError }}</p>
          <label class="cpm-field">Policy title
            <input v-model.trim="form.title" required maxlength="150" :aria-invalid="!!fieldError('title')" />
            <small v-if="fieldError('title')" class="cpm-field-error">{{ fieldError('title') }}</small>
          </label>
          <label class="cpm-field">Category
            <input v-model.trim="form.category" required maxlength="80" placeholder="e.g. Safeguarding" :aria-invalid="!!fieldError('category')" />
            <small v-if="fieldError('category')" class="cpm-field-error">{{ fieldError('category') }}</small>
          </label>
          <label class="cpm-field">Description
            <textarea v-model.trim="form.description" required rows="3" maxlength="5000" :aria-invalid="!!fieldError('description')"></textarea>
            <small v-if="fieldError('description')" class="cpm-field-error">{{ fieldError('description') }}</small>
          </label>
          <label class="cpm-field">Requirement type
            <select v-model="form.requirement_type" :aria-invalid="!!fieldError('requirement_type')">
              <option value="acknowledgement">Read and acknowledge</option>
              <option value="declaration">Declaration</option>
            </select>
            <small v-if="fieldError('requirement_type')" class="cpm-field-error">{{ fieldError('requirement_type') }}</small>
          </label>
          <label class="cpm-field">Confirmation statement
            <textarea v-model.trim="form.statement" required rows="3" maxlength="5000" :aria-invalid="!!fieldError('statement')"></textarea>
            <small v-if="fieldError('statement')" class="cpm-field-error">{{ fieldError('statement') }}</small>
          </label>
          <label class="cpm-field">Policy document URL
            <input v-model.trim="form.document_url" type="url" maxlength="500" placeholder="https://…" :aria-invalid="!!fieldError('document_url')" />
            <small v-if="fieldError('document_url')" class="cpm-field-error">{{ fieldError('document_url') }}</small>
          </label>
          <div class="cpm-fields-row">
            <label class="cpm-field">Validity (months)
              <input v-model="form.validity_months" type="number" min="1" max="65535" placeholder="No expiry" :aria-invalid="!!fieldError('validity_months')" />
              <small v-if="fieldError('validity_months')" class="cpm-field-error">{{ fieldError('validity_months') }}</small>
            </label>
            <label class="cpm-field">Display order
              <input v-model="form.sort_order" type="number" min="0" max="65535" :aria-invalid="!!fieldError('sort_order')" />
              <small v-if="fieldError('sort_order')" class="cpm-field-error">{{ fieldError('sort_order') }}</small>
            </label>
          </div>
          <label class="cpm-toggle"><input v-model="form.is_active" type="checkbox" /><span>Assign this policy to staff</span></label>
        </div>
        <footer class="cpm-dialog__foot">
          <button type="button" class="cpm-btn" @click="closeDialog(formDialog)">Cancel</button>
          <button type="submit" class="cpm-btn cpm-btn--primary" :disabled="saving"><AppIcon :name="saving ? 'refresh' : 'save'" :size="15" />{{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add policy' }}</button>
        </footer>
      </form>
    </dialog>

    <dialog ref="detailsDialog" class="cpm-dialog cpm-dialog--small" aria-labelledby="cpm-details-title" @click="onBackdrop($event, detailsDialog)">
      <div v-if="selected" class="cpm-dialog__inner">
        <header class="cpm-dialog__head">
          <div><p class="cpm-eyebrow">{{ selected.category }}</p><h2 id="cpm-details-title">{{ selected.title }}</h2></div>
          <button type="button" class="cpm-close" aria-label="Close" @click="closeDialog(detailsDialog)"><AppIcon name="x" :size="19" /></button>
        </header>
        <div class="cpm-dialog__body">
          <section><h3>What this covers</h3><p>{{ selected.description }}</p></section>
          <section><h3>Confirmation statement</h3><p>{{ selected.statement }}</p></section>
          <p><strong>Requirement:</strong> {{ selected.requirement_type === 'declaration' ? 'Declaration' : 'Read and acknowledge' }}</p>
          <p><strong>Validity:</strong> {{ selected.validity_months ? `${selected.validity_months} months` : 'No expiry' }}</p>
          <a v-if="selected.document_url" class="cpm-doc" :href="selected.document_url" target="_blank" rel="noopener"><AppIcon name="file-text" :size="17" />Open policy document</a>
        </div>
        <footer class="cpm-dialog__foot"><button type="button" class="cpm-btn cpm-btn--primary" @click="closeDialog(detailsDialog)">Close</button></footer>
      </div>
    </dialog>

    <dialog ref="deleteDialog" class="cpm-dialog cpm-dialog--small" aria-labelledby="cpm-delete-title" @click="onBackdrop($event, deleteDialog)">
      <div v-if="deleting" class="cpm-dialog__inner">
        <header class="cpm-dialog__head"><div><p class="cpm-eyebrow">Permanent action</p><h2 id="cpm-delete-title">Delete this policy?</h2></div></header>
        <div class="cpm-dialog__body">
          <p><strong>{{ deleting.title }}</strong> will be removed. Policies with staff responses cannot be deleted; deactivate them to preserve their history.</p>
          <p v-if="deleteError" class="cpm-error-box" role="alert">{{ deleteError }}</p>
        </div>
        <footer class="cpm-dialog__foot">
          <button type="button" class="cpm-btn" @click="closeDialog(deleteDialog)">Cancel</button>
          <button type="button" class="cpm-btn cpm-btn--danger" :disabled="deletingBusy" @click="confirmDelete"><AppIcon name="trash" :size="15" />{{ deletingBusy ? 'Deleting…' : 'Delete policy' }}</button>
        </footer>
      </div>
    </dialog>
  </main>
</template>

<style>
.cpm { --cpm-ink: #17365f; --cpm-text: #3d5470; --cpm-muted: #8293a7; --cpm-line: #e1e8ef; --cpm-brand: #087f70; width: min(100%, 1240px); margin: 0 auto; padding: 4px 0 42px; color: var(--cpm-text); font-size: 13px; line-height: 1.5; }
.cpm button { margin: 0; }
.cpm-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 0 0 18px; }
.cpm-head h1 { margin: 0 0 4px; color: var(--cpm-ink); font-size: 1.6rem; font-weight: 700; }
.cpm-head p { margin: 0; color: #8293a7; }
.cpm-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.cpm-card { display: flex; min-height: 348px; flex-direction: column; padding: 17px 17px 15px; border: 1px solid var(--cpm-line); border-radius: 15px; background: #fff; box-shadow: 0 5px 16px rgba(23, 54, 95, .045); }
.cpm-card__top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.cpm-icon { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 12px; }
.cpm-icon.tone-teal { background: #e8f6f4; color: #158b85; }
.cpm-icon.tone-amber { background: #fff3df; color: #d18412; }
.cpm-icon.tone-violet { background: #f3ebff; color: #8852d0; }
.cpm-icon.tone-blue { background: #e9f1ff; color: #4678c2; }
.cpm-status { padding: 4px 10px; border-radius: 20px; background: #e8f6ed; color: #388859; font-size: 10px; font-weight: 700; }
.cpm-status.is-inactive { background: #f1f3f5; color: #6d7b8b; }
.cpm-card h2 { margin: 0 0 4px; color: var(--cpm-ink); font-size: 14px; font-weight: 700; }
.cpm-category { margin: 0 0 9px; color: #168a83; font-size: 9px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.cpm-category.tone-amber { color: #c57d1b; }
.cpm-category.tone-violet { color: #8756c6; }
.cpm-category.tone-blue { color: #5077b4; }
.cpm-description { display: -webkit-box; min-height: 42px; margin: 0 0 8px; overflow: hidden; color: #60738b; font-size: 11px; line-height: 1.55; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.cpm-assigned { min-height: 16px; margin: 0 0 13px; color: #9aa8b8; font-size: 9px; }
.cpm-card__actions { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; padding: 12px 0 9px; border-bottom: 1px solid #edf1f4; }
.cpm-completion { display: inline-flex; min-height: 34px; align-items: center; padding: 0 12px; border: 1px solid #e1f0e9; border-radius: 8px; background: #eff8f4; color: #8daf9e; font-size: 11px; font-weight: 600; }
.cpm-completion.is-inactive { border-color: #e5e8ec; background: #f6f7f8; color: #8995a2; }
.cpm-card__manage { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 11px; }
.cpm-btn { display: inline-flex; min-height: 36px; align-items: center; justify-content: center; gap: 7px; padding: 0 12px; border: 1px solid #dfe6ee; border-radius: 8px; background: #fff; color: var(--cpm-ink); font: inherit; font-size: 11px; font-weight: 600; cursor: pointer; }
.cpm-btn:hover { background: #f7f9fb; border-color: #bdcbd9; }
.cpm-btn--primary { border-color: var(--cpm-brand); background: var(--cpm-brand); color: #fff; }
.cpm-btn--primary:hover { border-color: #06695d; background: #06695d; }
.cpm-btn--small { min-height: 32px; padding: 0 10px; color: #406cae; }
.cpm-btn--danger { border-color: #f0d7d5; color: #bd4d46; }
.cpm-btn--danger:hover { border-color: #e8b8b4; background: #fff8f7; }
.cpm-btn:disabled { opacity: .65; cursor: progress; }
.cpm-toast { display: flex; align-items: center; gap: 9px; margin: -14px 0 16px; padding: 10px 12px; border: 1px solid #bfe3d6; border-radius: 9px; background: #e8f6f1; color: #086a51; font-weight: 600; }
.cpm-toast span { flex: 1; }
.cpm-toast button, .cpm-close { display: grid; width: 32px; height: 32px; place-items: center; border: 0; border-radius: 7px; background: transparent; color: inherit; cursor: pointer; }
.cpm-state { display: grid; justify-items: center; gap: 10px; padding: 44px 20px; border: 1px dashed var(--cpm-line); border-radius: 14px; background: #fff; text-align: center; }
.cpm-state h2, .cpm-state p { margin: 0; }
.cpm-state h2 { color: var(--cpm-ink); font-size: 17px; }
.cpm-state p { max-width: 460px; }
.cpm-skeleton { min-height: 348px; border-radius: 15px; background: linear-gradient(90deg, #f1f4f7 25%, #f8fafb 37%, #f1f4f7 63%); background-size: 400% 100%; animation: cpm-shimmer 1.4s ease infinite; }
@keyframes cpm-shimmer { to { background-position: -100% 0; } }
.cpm-dialog { width: min(620px, calc(100vw - 28px)); max-height: min(88vh, 800px); padding: 0; overflow: auto; border: 1px solid var(--cpm-line); border-radius: 15px; background: #fff; color: var(--cpm-text); box-shadow: 0 20px 70px rgba(15, 35, 60, .24); }
.cpm-dialog::backdrop { background: rgba(15, 30, 48, .46); backdrop-filter: blur(2px); }
.cpm-dialog--small { width: min(500px, calc(100vw - 28px)); }
.cpm-dialog__inner { display: flex; flex-direction: column; }
.cpm-dialog__head { position: sticky; top: 0; z-index: 1; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 19px 22px; border-bottom: 1px solid var(--cpm-line); background: #fff; }
.cpm-dialog__head h2 { margin: 0; color: var(--cpm-ink); font-size: 20px; }
.cpm-eyebrow { margin: 0 0 3px; color: var(--cpm-brand); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.cpm-dialog__body { display: grid; gap: 14px; padding: 20px 22px; }
.cpm-dialog__body section h3 { margin: 0 0 4px; color: var(--cpm-ink); font-size: 13px; }
.cpm-dialog__body section p, .cpm-dialog__body > p { margin: 0; }
.cpm-dialog__foot { position: sticky; bottom: 0; display: flex; justify-content: flex-end; gap: 9px; padding: 13px 22px; border-top: 1px solid var(--cpm-line); background: #f8fafb; }
.cpm-field { display: grid; gap: 5px; color: var(--cpm-ink); font-size: 12px; font-weight: 600; }
.cpm-field input, .cpm-field select, .cpm-field textarea { box-sizing: border-box; width: 100%; min-height: 38px; padding: 8px 10px; border: 1px solid #ced9e4; border-radius: 8px; background: #fff; color: var(--cpm-ink); font: inherit; font-size: 13px; }
.cpm-field textarea { resize: vertical; }
.cpm-field input:focus, .cpm-field select:focus, .cpm-field textarea:focus { border-color: var(--cpm-brand); outline: 0; box-shadow: 0 0 0 3px rgba(8, 127, 112, .12); }
.cpm-field [aria-invalid="true"] { border-color: #c0392b; }
.cpm-field-error { color: #b3322a; font-size: 11px; font-weight: 500; }
.cpm-fields-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cpm-toggle { display: flex; align-items: center; gap: 8px; color: var(--cpm-ink); font-size: 12px; font-weight: 600; }
.cpm-toggle input { width: 16px; height: 16px; accent-color: var(--cpm-brand); }
.cpm-error-box { margin: 0; padding: 10px 12px; border: 1px solid #f0c7c3; border-radius: 8px; background: #fff7f6; color: #a5342e; font-size: 12px; }
.cpm-doc { display: inline-flex; align-items: center; gap: 8px; color: var(--cpm-brand); font-weight: 600; }
@media (max-width: 900px) { .cpm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 600px) {
  .cpm { padding-top: 14px; }
  .cpm-head h1 { font-size: 1.35rem; }
  .cpm-grid { grid-template-columns: 1fr; }
  .cpm-card { min-height: 320px; }
  .cpm-fields-row { grid-template-columns: 1fr; }
  .cpm-dialog__head, .cpm-dialog__body, .cpm-dialog__foot { padding-inline: 16px; }
}
@media (prefers-reduced-motion: reduce) { .cpm-skeleton { animation: none; } }
</style>
