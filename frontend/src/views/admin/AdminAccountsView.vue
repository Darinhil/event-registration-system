<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { downloadPersonnelDocument, getStaffPersonnelProfile } from '../../services/staffRequestService'
import { saveBlob } from '../../utils/staffRequestForm'
import api from '../../services/api'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Access levels as the backend enforces them (Gate "manage-admin-accounts" + Event::visibleTo):
// only Master Admin sees everything; every other role is limited to the events it created.
const OWN_EVENTS = 'Manages only the events they create — registration forms, attendees, check-in and reports.'
const LEVEL_NOTE = 'Same access as Event Admin. The level is saved on the account for your records.'
const ROLES = [
  { value: 'admin', label: 'Master Admin', full: true, description: 'Full access to every event, all admin accounts and system settings.' },
  { value: 'event_admin', label: 'Event Admin', description: OWN_EVENTS },
  { value: 'manager', label: 'Manager', description: LEVEL_NOTE },
  { value: 'officer_3', label: 'Officer Level 3', description: LEVEL_NOTE },
  { value: 'officer_2', label: 'Officer Level 2', description: LEVEL_NOTE },
  { value: 'officer_1', label: 'Officer Level 1', description: LEVEL_NOTE },
  { value: 'officer_0', label: 'Officer Level 0', description: LEVEL_NOTE },
]
const roleOf = (value) => ROLES.find((role) => role.value === value)
const roleLabel = (value) => roleOf(value)?.label || value
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const SEARCH_THRESHOLD = 6

const accounts = ref([])
const loading = ref(true)
const loadError = ref('')
const meId = computed(() => auth.user?.id)

/* ---------- Notifications ---------- */
const toast = ref(null)
let toastTimer
const notify = (type, text) => {
  toast.value = { type, text }
  clearTimeout(toastTimer)
  if (type === 'success') toastTimer = setTimeout(() => (toast.value = null), 6000)
}
onBeforeUnmount(() => clearTimeout(toastTimer))

/* ---------- Load ---------- */
// Silent loads re-sync after a change; the list is already updated locally, so their failures stay quiet.
let loadTicket = 0
const load = async ({ silent = false } = {}) => {
  const ticket = ++loadTicket
  if (!silent) { loading.value = true; loadError.value = '' }
  try {
    const rows = (await api.get('/admin/admin-accounts')).data.data || []
    if (ticket === loadTicket) accounts.value = rows
  } catch (e) {
    if (!silent && ticket === loadTicket) loadError.value = e.response?.data?.message || 'We couldn’t load the team list. Check your connection and try again.'
  } finally {
    if (ticket === loadTicket) loading.value = false
  }
}

/* ---------- Create form ---------- */
const blank = () => ({ name: '', email: '', password: '', role: 'event_admin' })
const form = reactive(blank())
const errors = reactive({ name: '', email: '', password: '', role: '' })
const touched = reactive({ name: false, email: false, password: false })
const formError = ref('')
const creating = ref(false)
const showPassword = ref(false)
const fieldRefs = reactive({})
const selectedRole = computed(() => roleOf(form.role))
const passwordOk = computed(() => form.password.length >= 8)

const rules = {
  name: (v) => (!v.trim() ? 'Enter the staff member’s full name.' : v.trim().length > 100 ? 'Use 100 characters or fewer.' : ''),
  email: (v) => (!v.trim() ? 'Enter an email address.' : !EMAIL_RE.test(v.trim()) ? 'Enter a valid email, like name@company.com.' : ''),
  password: (v) => (!v ? 'Create a password.' : v.length < 8 ? 'Use at least 8 characters.' : ''),
  role: (v) => (roleOf(v) ? '' : 'Choose a role.'),
}
const validate = (key) => (errors[key] = rules[key](form[key]))
const onBlur = (key) => { touched[key] = true; validate(key) }
const onInput = (key) => { if (touched[key] || errors[key]) validate(key); formError.value = '' }
const focusFirstError = async () => {
  await nextTick()
  const key = ['name', 'email', 'password', 'role'].find((k) => errors[k])
  fieldRefs[key]?.focus()
}

const submit = async () => {
  if (creating.value) return
  formError.value = ''
  Object.keys(touched).forEach((k) => (touched[k] = true))
  Object.keys(rules).forEach(validate)
  if (Object.values(errors).some(Boolean)) return focusFirstError()
  // Granting full access is a sensitive step: review it in a dialog first.
  if (form.role === 'admin') return openDialog({ type: 'grant' })
  await create()
}

const create = async ({ focus = true } = {}) => {
  if (creating.value) return false
  creating.value = true
  formError.value = ''
  const payload = { name: form.name.trim(), email: form.email.trim(), password: form.password, role: form.role }
  try {
    const { data } = await api.post('/admin/admin-accounts', payload)
    Object.assign(form, blank())
    Object.keys(touched).forEach((k) => (touched[k] = false))
    Object.keys(errors).forEach((k) => (errors[k] = ''))
    showPassword.value = false
    if (data.data?.id) accounts.value = [data.data, ...accounts.value.filter((a) => a.id !== data.data.id)]
    highlight(data.data?.id)
    notify('success', `${data.data?.name || payload.name} was added as ${roleLabel(payload.role)}. They can sign in with ${payload.email}.`)
    load({ silent: true })
    return true
  } catch (e) {
    const fieldErrors = e.response?.data?.errors || {}
    let mapped = false
    for (const key of Object.keys(errors)) {
      if (fieldErrors[key]?.[0]) {
        errors[key] = key === 'email' && /taken/i.test(fieldErrors[key][0]) ? 'An account with this email already exists.' : fieldErrors[key][0]
        mapped = true
      }
    }
    if (mapped && focus) focusFirstError()
    else formError.value = e.response ? (e.response.data?.message || 'The account couldn’t be created.') : 'We couldn’t reach the server. Your details are still here — try again.'
    return false
  } finally {
    creating.value = false
  }
}

/* ---------- Team list ---------- */
const query = ref('')
const roleFilter = ref('all')
const showTools = computed(() => accounts.value.length >= SEARCH_THRESHOLD)
const roleOptions = computed(() => ROLES.filter((role) => accounts.value.some((a) => a.role === role.value)))
const masterCount = computed(() => accounts.value.filter((a) => a.role === 'admin').length)
const visibleAccounts = computed(() => {
  const term = query.value.trim().toLowerCase()
  return accounts.value.filter((a) =>
    (roleFilter.value === 'all' || a.role === roleFilter.value) &&
    (!term || `${a.name} ${a.email}`.toLowerCase().includes(term)))
})
const clearFilters = () => { query.value = ''; roleFilter.value = 'all' }
const initials = (name = '') => name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('') || '?'
const dateFmt = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
const addedOn = (value) => { const d = value ? new Date(value) : null; return d && !Number.isNaN(d.getTime()) ? dateFmt.format(d) : '' }
const highlightId = ref(null)
let highlightTimer
const highlight = (id) => { highlightId.value = id; clearTimeout(highlightTimer); highlightTimer = setTimeout(() => (highlightId.value = null), 4000) }
onBeforeUnmount(() => clearTimeout(highlightTimer))

/* ---------- Dialogs (grant review, change role, edit, HR profile, delete) ---------- */
const dialogEl = ref(null)
const dialog = ref(null)
const dialogBusy = ref(false)
const dialogError = ref('')
const nextRole = ref('')
const editForm = reactive({ name: '', password: '' })
const staffProfile = ref(null)
const staffProfileLoading = ref(false)
let returnFocus = null

const openDialog = async (state, trigger) => {
  returnFocus = trigger || document.activeElement
  dialog.value = state
  dialogError.value = ''
  nextRole.value = state.account?.role || ''
  editForm.name = state.account?.name || ''
  editForm.password = ''
  staffProfile.value = null
  await nextTick()
  dialogEl.value?.showModal()
  if (state.type === 'profile') loadStaffProfile(state.account)
}
const loadStaffProfile = async (account) => {
  staffProfileLoading.value = true
  try {
    staffProfile.value = (await getStaffPersonnelProfile(account.id)).data.data
  } catch {
    dialogError.value = 'We couldn’t load this profile.'
  } finally {
    staffProfileLoading.value = false
  }
}
const profileRows = computed(() => {
  const p = staffProfile.value?.profile
  if (!p) return []
  const list = (rows, line) => (rows || []).map(line).filter(Boolean).join('; ')
  return [
    ['Name in Khmer', p.khmerName], ['Gender', p.gender], ['Date of birth', p.dob], ['Place of birth', p.birthPlace], ['Citizenship', p.citizenship], ['ID or passport', p.idNumber],
    ['Phone', [p.phone, p.altPhone].filter(Boolean).join(', ')], ['Email', p.email], ['Address', p.address],
    ['Education', list(p.education, (row) => [row.degree, row.institution, row.year].filter(Boolean).join(', '))],
    ['Experience', list(p.experience, (row) => [row.position, row.organisation].filter(Boolean).join(', '))],
    ['Marital status', p.maritalStatus], ['Spouse or partner', p.spouse],
    ['Children', list(p.children, (row) => [row.name, row.dob].filter(Boolean).join(', '))],
    ['Emergency contacts', list(p.contacts, (row) => [row.name, row.relationship, row.phone].filter(Boolean).join(', '))],
    ['Beneficiaries', list(p.beneficiaries, (row) => (row.name ? `${row.name} (${row.share}%)` : ''))],
  ].filter(([, value]) => value)
})
const DOCUMENT_LABELS = { id: 'National ID or passport', cv: 'CV', certificate: 'Highest qualification', bank: 'Bank details' }
const saveDocument = async (slot, document) => {
  try { saveBlob((await downloadPersonnelDocument(staffProfile.value.user_id, slot)).data, document.name) } catch { dialogError.value = 'We couldn’t download this file.' }
}
const closeDialog = () => { if (!dialogBusy.value) dialogEl.value?.close() }
const onDialogClosed = () => {
  dialog.value = null
  const target = returnFocus
  returnFocus = null
  if (target?.isConnected) target.focus()
}
const onDialogCancel = (event) => { if (dialogBusy.value) event.preventDefault() }

const roleChangeWarning = computed(() => {
  const from = dialog.value?.account?.role
  if (!from || nextRole.value === from) return ''
  if (nextRole.value === 'admin') return 'This gives full access to every event, all admin accounts and system settings.'
  if (from === 'admin') return 'They will lose access to other people’s events, admin accounts and settings.'
  return ''
})

const confirmDialog = async () => {
  if (dialogBusy.value || !dialog.value) return
  const { type, account } = dialog.value
  dialogBusy.value = true
  dialogError.value = ''
  try {
    if (type === 'grant') {
      const ok = await create({ focus: false })
      dialogBusy.value = false
      // Problems are reported on the form itself, so close and send focus to the field to fix.
      if (!ok) returnFocus = null
      dialogEl.value?.close()
      if (!ok) focusFirstError()
      return
    }
    if (type === 'edit') {
      const body = { name: editForm.name.trim(), ...(editForm.password ? { password: editForm.password } : {}) }
      const { data } = await api.put(`/admin/admin-accounts/${account.id}`, body)
      accounts.value = accounts.value.map((a) => (a.id === account.id ? { ...a, ...data.data } : a))
      notify('success', editForm.password ? `${data.data.name}’s account was updated and they were signed out.` : `${data.data.name}’s account was updated.`)
      highlight(account.id)
    } else if (type === 'role') {
      const { data } = await api.put(`/admin/admin-accounts/${account.id}`, { role: nextRole.value })
      accounts.value = accounts.value.map((a) => (a.id === account.id ? { ...a, ...data.data } : a))
      notify('success', `${account.name} is now ${roleLabel(data.data?.role || nextRole.value)}.`)
      highlight(account.id)
    } else {
      await api.delete(`/admin/admin-accounts/${account.id}`)
      accounts.value = accounts.value.filter((a) => a.id !== account.id)
      notify('success', `${account.name}’s account was deleted.`)
    }
    dialogBusy.value = false
    dialogEl.value?.close()
    load({ silent: true })
  } catch (e) {
    dialogBusy.value = false
    if (e.response?.status === 404) {
      dialogEl.value?.close()
      notify('error', `${account.name}’s account no longer exists. The list has been refreshed.`)
      accounts.value = accounts.value.filter((a) => a.id !== account.id)
      load({ silent: true })
      return
    }
    dialogError.value = Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'We couldn’t reach the server. Please try again.'
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <section class="aa-page" aria-labelledby="aa-title">
      <div class="aa-live" role="status" aria-live="polite">
        <Transition name="aa-fade">
          <div v-if="toast" class="aa-toast" :class="`aa-toast--${toast.type}`">
            <AppIcon :name="toast.type === 'success' ? 'check-circle' : 'alert-circle'" :size="18" />
            <span>{{ toast.text }}</span>
            <button type="button" class="aa-icon-btn" aria-label="Dismiss message" @click="toast = null"><AppIcon name="x" :size="16" /></button>
          </div>
        </Transition>
      </div>

      <div class="aa-document">
        <header class="aa-document-header">
          <div class="aa-brand-wrap">
            <div class="aa-brand-mark" aria-hidden="true">
              <span class="aa-leaf aa-leaf-one"></span>
              <span class="aa-leaf aa-leaf-two"></span>
              <span class="aa-leaf aa-leaf-three"></span>
            </div>
            <div class="aa-brand-copy">
              <div class="aa-brand-kh">លីវ &amp; លើណ Cambodia</div>
              <div class="aa-brand-en">LIVE &amp; LEARN CAMBODIA ORG.</div>
            </div>
          </div>

          <aside class="aa-voucher-box">
            <div class="aa-voucher-head">FINANCE VOUCHER</div>
            <div class="aa-voucher-row">
              <label>Voucher No:</label>
              <input type="text" value="GV-20261117-001" aria-label="Voucher number" />
            </div>
            <div class="aa-voucher-row">
              <label>Voucher Date:</label>
              <input type="text" value="17/11/2026" aria-label="Voucher date" />
            </div>
            <div class="aa-voucher-row">
              <label>Adv. No:</label>
              <input type="text" value="" aria-label="Advance number" placeholder="" />
            </div>
          </aside>
        </header>

        <form class="aa-form-sheet" novalidate @submit.prevent="submit">
          <div class="aa-meta-row">
            <div class="aa-meta-field">
              <label>Structure</label>
              <div class="aa-select aa-select--filled">
                <select aria-label="Structure">
                  <option>HR and Operations</option>
                </select>
                <AppIcon class="aa-select-chevron" name="chevron-down" :size="16" />
              </div>
            </div>
            <div class="aa-meta-field">
              <label>Project</label>
              <div class="aa-select aa-select--filled">
                <select aria-label="Project">
                  <option>Project A</option>
                </select>
                <AppIcon class="aa-select-chevron" name="chevron-down" :size="16" />
              </div>
            </div>
            <div class="aa-meta-field">
              <label>Fiscal year</label>
              <div class="aa-select aa-select--filled">
                <select aria-label="Fiscal year">
                  <option>2026</option>
                </select>
                <AppIcon class="aa-select-chevron" name="chevron-down" :size="16" />
              </div>
            </div>
          </div>

          <div class="aa-two-col">
            <section class="aa-panel">
              <h3>Request / Beneficiary Information</h3>

              <div class="aa-field aa-field--stack" :class="{ 'has-error': errors.name }">
                <label for="aa-name">Payee / Officer Name <span class="aa-req" aria-hidden="true">*</span></label>
                <input id="aa-name" :ref="(el) => (fieldRefs.name = el)" v-model="form.name" type="text" maxlength="100" autocomplete="off" placeholder="Full name of staff or vendor" required :aria-invalid="!!errors.name" :aria-describedby="errors.name ? 'aa-name-err' : undefined" @blur="onBlur('name')" @input="onInput('name')" />
                <p v-if="errors.name" id="aa-name-err" class="aa-err">{{ errors.name }}</p>
              </div>

              <div class="aa-field aa-field--stack" :class="{ 'has-error': errors.email }">
                <label for="aa-email">Work email <span class="aa-req" aria-hidden="true">*</span></label>
                <input id="aa-email" :ref="(el) => (fieldRefs.email = el)" v-model="form.email" type="email" inputmode="email" autocomplete="off" spellcheck="false" placeholder="name@company.com" required :aria-invalid="!!errors.email" :aria-describedby="errors.email ? 'aa-email-err aa-email-hint' : 'aa-email-hint'" @blur="onBlur('email')" @input="onInput('email')" />
                <p v-if="errors.email" id="aa-email-err" class="aa-err">{{ errors.email }}</p>
                <p id="aa-email-hint" class="aa-hint">They’ll use this email to sign in.</p>
              </div>

              <div class="aa-field aa-field--stack">
                <label for="aa-role">Role</label>
                <div class="aa-select aa-select--filled">
                  <select id="aa-role" :ref="(el) => (fieldRefs.role = el)" v-model="form.role" aria-describedby="aa-role-desc" @change="onInput('role')">
                    <option v-for="role in ROLES" :key="role.value" :value="role.value">{{ role.label }}</option>
                  </select>
                  <AppIcon class="aa-select-chevron" name="chevron-down" :size="16" />
                </div>
                <p id="aa-role-desc" class="aa-role-note">{{ selectedRole?.description }}</p>
              </div>

              <div class="aa-field aa-field--stack" :class="{ 'has-error': errors.password }">
                <label for="aa-password">Temporary password <span class="aa-req" aria-hidden="true">*</span></label>
                <div class="aa-password">
                  <input id="aa-password" :ref="(el) => (fieldRefs.password = el)" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required :aria-invalid="!!errors.password" :aria-describedby="errors.password ? 'aa-password-err aa-password-rule' : 'aa-password-rule'" @blur="onBlur('password')" @input="onInput('password')" />
                  <button type="button" class="aa-icon-btn aa-password-toggle" :aria-pressed="showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" :title="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword"><AppIcon :name="showPassword ? 'eye-off' : 'eye'" :size="18" /></button>
                </div>
                <p v-if="errors.password" id="aa-password-err" class="aa-err">{{ errors.password }}</p>
                <p id="aa-password-rule" class="aa-rule" :class="{ 'is-met': passwordOk }">
                  <AppIcon :name="passwordOk ? 'check-circle' : 'circle-dot'" :size="14" />At least 8 characters<span class="sr-only">{{ passwordOk ? ' — met' : '' }}</span>
                </p>
              </div>
            </section>

            <section class="aa-panel">
              <h3>Type of Voucher</h3>
              <div class="aa-option-list">
                <label class="aa-option"><input v-model="form.role" type="radio" value="admin" /><span>Master Admin</span></label>
                <label class="aa-option"><input v-model="form.role" type="radio" value="event_admin" /><span>Event Admin</span></label>
                <label class="aa-option"><input v-model="form.role" type="radio" value="manager" /><span>Manager</span></label>
                <label class="aa-option"><input v-model="form.role" type="radio" value="officer_3" /><span>Officer Level 3</span></label>
                <label class="aa-option"><input v-model="form.role" type="radio" value="officer_2" /><span>Officer Level 2</span></label>
                <label class="aa-option"><input v-model="form.role" type="radio" value="officer_1" /><span>Officer Level 1</span></label>
              </div>
            </section>

            <section class="aa-panel">
              <h3>Mode of Payment</h3>
              <div class="aa-option-list aa-option-list--compact">
                <label class="aa-option"><input type="radio" name="payment-mode" value="cash" checked /><span>Cash</span></label>
                <label class="aa-option"><input type="radio" name="payment-mode" value="bank-transfer" /><span>Bank Transfer</span></label>
                <label class="aa-option"><input type="radio" name="payment-mode" value="bank-cheque" /><span>Bank Cheque</span></label>
              </div>

              <div class="aa-field aa-field--stack aa-field--small">
                <label for="aa-payment-ref">Cheque # / Tx Ref</label>
                <input id="aa-payment-ref" type="text" value="e.g. CG-99210" placeholder="e.g. CG-99210" />
              </div>
            </section>
          </div>

          <div class="aa-panel aa-panel--bottom">
            <h3>Expenditure &amp; Settlement Items</h3>
            <div class="aa-panel-actions">
              <button type="button" class="aa-btn aa-btn--ghost aa-btn--success"><AppIcon name="plus" :size="15" />Add line</button>
              <button type="button" class="aa-btn aa-btn--ghost aa-btn--danger"><AppIcon name="trash" :size="15" />Remove last line</button>
            </div>
            <p class="aa-inline-hint">Provide line item description, grant codes, and allocated values.</p>
          </div>

          <div v-if="formError" class="aa-form-error aa-form-error--sheet" role="alert"><AppIcon name="alert-circle" :size="16" />{{ formError }}</div>

          <div class="aa-submit-row">
            <button class="aa-btn aa-btn--primary aa-submit" type="submit" :disabled="creating" :aria-busy="creating">
              <span v-if="creating" class="aa-spinner" aria-hidden="true"></span>
              <AppIcon v-else name="user-plus" :size="17" />
              {{ creating ? 'Creating account…' : 'Create account' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Confirmation dialog -->
      <dialog ref="dialogEl" class="aa-dialog" aria-labelledby="aa-dialog-title" aria-describedby="aa-dialog-desc" @close="onDialogClosed" @cancel="onDialogCancel">
        <form v-if="dialog" method="dialog" class="aa-dialog-body" @submit.prevent="confirmDialog">
          <template v-if="dialog.type === 'grant'">
            <span class="aa-dialog-icon is-warn" aria-hidden="true"><AppIcon name="shield-check" :size="22" /></span>
            <h2 id="aa-dialog-title">Create a Master Admin?</h2>
            <p id="aa-dialog-desc"><strong>{{ form.name.trim() }}</strong> ({{ form.email.trim() }}) will get full access to every event, all admin accounts and system settings.</p>
          </template>

          <template v-else-if="dialog.type === 'role'">
            <span class="aa-dialog-icon" aria-hidden="true"><AppIcon name="pen" :size="20" /></span>
            <h2 id="aa-dialog-title">Change role</h2>
            <p id="aa-dialog-desc"><strong>{{ dialog.account.name }}</strong> · {{ dialog.account.email }}<br />Current role: {{ roleLabel(dialog.account.role) }}</p>
            <div class="aa-field">
              <label for="aa-next-role">New role</label>
              <div class="aa-select">
                <select id="aa-next-role" v-model="nextRole" aria-describedby="aa-next-role-desc">
                  <optgroup label="Full access"><option value="admin">Master Admin</option></optgroup>
                  <optgroup label="Own events only"><option v-for="role in ROLES.filter((r) => !r.full)" :key="role.value" :value="role.value">{{ role.label }}</option></optgroup>
                </select>
                <AppIcon class="aa-select-chevron" name="chevron-down" :size="16" />
              </div>
              <p id="aa-next-role-desc" class="aa-hint">{{ roleOf(nextRole)?.description }}</p>
            </div>
            <p v-if="roleChangeWarning" class="aa-callout"><AppIcon name="alert-circle" :size="16" />{{ roleChangeWarning }}</p>
          </template>

          <template v-else-if="dialog.type === 'edit'">
            <h2 id="aa-dialog-title">Edit account</h2>
            <p id="aa-dialog-desc">{{ dialog.account.email }}</p>
            <div class="aa-field">
              <label for="aa-edit-name">Full name</label>
              <input id="aa-edit-name" v-model="editForm.name" type="text" maxlength="100" required />
            </div>
            <div class="aa-field">
              <label for="aa-edit-password">New password</label>
              <input id="aa-edit-password" v-model="editForm.password" type="password" minlength="8" autocomplete="new-password" aria-describedby="aa-edit-password-hint" />
              <p id="aa-edit-password-hint" class="aa-hint">Leave empty to keep the current one. Changing it signs them out everywhere.</p>
            </div>
          </template>

          <template v-else-if="dialog.type === 'profile'">
            <h2 id="aa-dialog-title">{{ dialog.account.name }}</h2>
            <p id="aa-dialog-desc">{{ staffProfileLoading ? 'Loading…' : !staffProfile?.profile ? 'No personnel profile yet.' : staffProfile.submitted_at ? `Submitted ${addedOn(staffProfile.submitted_at)}` : 'Draft — not submitted yet' }}</p>
            <dl v-if="profileRows.length" class="aa-profile">
              <div v-for="[label, value] in profileRows" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div>
            </dl>
            <div v-if="staffProfile && Object.keys(staffProfile.documents).length" class="aa-profile-docs">
              <button v-for="(document, slot) in staffProfile.documents" :key="slot" type="button" class="aa-btn aa-btn--ghost" @click="saveDocument(slot, document)"><AppIcon name="download" :size="15" />{{ DOCUMENT_LABELS[slot] || slot }}</button>
            </div>
          </template>

          <template v-else>
            <span class="aa-dialog-icon is-danger" aria-hidden="true"><AppIcon name="trash" :size="20" /></span>
            <h2 id="aa-dialog-title">Delete this account?</h2>
            <div id="aa-dialog-desc">
              <div class="aa-dialog-person">
                <span class="aa-avatar" :class="{ 'is-master': dialog.account.role === 'admin' }" aria-hidden="true">{{ initials(dialog.account.name) }}</span>
                <div><strong>{{ dialog.account.name }}</strong><span>{{ dialog.account.email }}</span><span>{{ roleLabel(dialog.account.role) }}</span></div>
              </div>
              <ul class="aa-consequences">
                <li>They will no longer be able to sign in.</li>
                <li>Events they created are kept; only Master Admins can manage them afterwards.</li>
                <li>Any event registrations made with this account are removed.</li>
              </ul>
              <p class="aa-strong-note">This can’t be undone.</p>
            </div>
          </template>

          <p v-if="dialogError" class="aa-form-error" role="alert"><AppIcon name="alert-circle" :size="16" />{{ dialogError }}</p>

          <div class="aa-dialog-actions">
            <button type="button" class="aa-btn aa-btn--ghost" :disabled="dialogBusy" @click="closeDialog">{{ dialog.type === 'profile' ? 'Close' : 'Cancel' }}</button>
            <button v-if="dialog.type !== 'profile'" type="submit" class="aa-btn" :class="dialog.type === 'delete' ? 'aa-btn--danger-solid' : 'aa-btn--primary'" :disabled="dialogBusy || (dialog.type === 'role' && nextRole === dialog.account.role) || (dialog.type === 'edit' && (!editForm.name.trim() || (editForm.password && editForm.password.length < 8)))" :aria-busy="dialogBusy">
              <span v-if="dialogBusy" class="aa-spinner" aria-hidden="true"></span>
              {{ dialog.type === 'edit' ? (dialogBusy ? 'Saving…' : 'Save changes') : dialog.type === 'grant' ? (dialogBusy ? 'Creating…' : 'Create Master Admin') : dialog.type === 'role' ? (dialogBusy ? 'Saving…' : 'Change role') : (dialogBusy ? 'Deleting…' : 'Delete account') }}
            </button>
          </div>
        </form>
      </dialog>
    </section>
  </AdminLayout>
</template>

<style scoped>
.aa-page {
  --aa-ink: #0f1c2e;
  --aa-text: #33475b;
  --aa-muted: #5f7286;
  --aa-line: #e3e9f0;
  --aa-line-soft: #eef2f6;
  --aa-green: #0b7f62;
  --aa-green-dark: #086a51;
  --aa-green-soft: #e8f5f0;
  --aa-red: #b42318;
  --aa-amber: #8a5a06;
  max-width: 1240px;
  margin: 0 auto;
  padding: 28px 0 40px;
  color: var(--aa-text);
}

.aa-document {
  background: #f3f5f6;
  border: 1px solid #dfe5eb;
  border-radius: 18px;
  padding: 18px 18px 28px;
  box-shadow: 0 8px 24px rgba(15, 32, 48, .04);
}

.aa-document-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;
  padding: 18px 0 22px;
}
.aa-brand-wrap {
  display: flex;
  align-items: center;
  gap: 18px;
  min-height: 92px;
}
.aa-brand-mark {
  position: relative;
  width: 68px;
  height: 68px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0e7d71, #0b5e54);
  box-shadow: inset 0 0 0 8px rgba(255,255,255,.12);
}
.aa-brand-mark::before {
  content: "";
  position: absolute;
  inset: 14px 18px 14px 18px;
  border-radius: 50%;
  border: 3px solid rgba(255,255,255,.6);
  transform: rotate(-12deg);
}
.aa-leaf {
  position: absolute;
  width: 17px;
  height: 22px;
  background: rgba(255,255,255, .85);
  border-radius: 80% 0 80% 0;
  transform-origin: bottom center;
  box-shadow: inset 0 0 0 1px rgba(12,89,77,.08);
}
.aa-leaf-one { left: 18px; top: 10px; transform: rotate(-32deg); }
.aa-leaf-two { left: 28px; top: 20px; transform: rotate(28deg); }
.aa-leaf-three { left: 38px; top: 16px; transform: rotate(62deg); }
.aa-brand-copy {
  display: grid;
  gap: 4px;
  color: #0e1a2d;
}
.aa-brand-kh {
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: -.03em;
}
.aa-brand-en {
  font-size: .68rem;
  letter-spacing: .12em;
  font-weight: 700;
  text-transform: uppercase;
  color: #273952;
}
.aa-voucher-box {
  width: min(320px, 100%);
  border: 1px solid #d3d9df;
  border-radius: 12px;
  background: rgba(255,255,255,.55);
  padding: 10px 14px 12px;
}
.aa-voucher-head {
  text-align: center;
  font-size: .82rem;
  font-weight: 800;
  color: #1a2d3d;
  letter-spacing: .08em;
  margin-bottom: 10px;
}
.aa-voucher-row {
  display: grid;
  grid-template-columns: 108px 1fr;
  gap: 8px;
  align-items: center;
  margin-top: 8px;
  font-size: .9rem;
  color: #223548;
}
.aa-voucher-row label { font-weight: 600; }
.aa-voucher-row input {
  width: 100%;
  height: 32px;
  border: 1px solid #d4dce5;
  border-radius: 8px;
  background: rgba(255,255,255,.75);
  padding: 0 8px;
  color: #1c2d3d;
  font: inherit;
}

.aa-form-sheet {
  border: 1px solid #dfe7ee;
  border-radius: 14px;
  background: rgba(255,255,255,.62);
  padding: 14px 18px 18px;
}
.aa-meta-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  padding: 8px 0 14px;
  border: 1px solid #dfe7ee;
  border-radius: 10px;
  background: rgba(255,255,255,.4);
}
.aa-meta-field {
  display: grid;
  gap: 10px;
  padding: 0 14px;
  min-height: 82px;
}
.aa-meta-field label, .aa-panel h3, .aa-field label {
  font-weight: 700;
  color: #1a2c3f;
}
.aa-select--filled .aa-select-chevron { right: 12px; }
.aa-select--filled select {
  background: rgba(255,255,255,.72);
  color: #1d2d3d;
  font-weight: 600;
}

.aa-two-col {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr;
  gap: 16px;
  margin-top: 18px;
  align-items: start;
}
.aa-panel {
  border: 1px solid #dfe7ee;
  border-radius: 12px;
  background: rgba(255,255,255,.26);
  padding: 12px 14px 14px;
}
.aa-panel h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  font-size: .98rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #1f3143;
}
.aa-panel h3::before {
  content: "";
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 2px;
  background: var(--aa-green);
}
.aa-panel--bottom {
  margin-top: 18px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.aa-panel-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.aa-inline-hint {
  width: 100%;
  margin: 0;
  color: var(--aa-muted);
  font-size: .8rem;
}
.aa-option-list {
  display: grid;
  gap: 10px;
}
.aa-option-list--compact {
  gap: 8px;
}
.aa-option {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 26px;
  padding: 0 4px;
  color: #223548;
  font-size: .92rem;
}
.aa-option input {
  width: 16px;
  height: 16px;
  accent-color: var(--aa-green);
}
.aa-field--stack {
  display: grid;
  gap: 8px;
  margin-top: 14px;
}
.aa-field--small { margin-top: 16px; }
.aa-field--stack .aa-password { width: 100%; }
.aa-password input, .aa-field input, .aa-field select {
  height: 38px;
}
.aa-role-note {
  margin: 0;
  min-height: 18px;
  color: var(--aa-muted);
  font-size: .8rem;
}
.aa-form-error--sheet {
  margin-top: 14px;
  justify-content: flex-start;
}
.aa-submit-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 18px;
}

/* Header */
.aa-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 22px; }
.aa-eyebrow { margin: 0 0 6px; color: var(--aa-green); font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
.aa-head h1 { margin: 0; color: var(--aa-ink); font-size: clamp(1.5rem, 2.4vw, 1.85rem); font-weight: 700; letter-spacing: -.02em; line-height: 1.2; }
.aa-stats { display: flex; gap: 10px; margin: 0; }
.aa-stats div { min-width: 104px; padding: 10px 14px; border: 1px solid var(--aa-line); border-radius: 10px; background: #fff; }
.aa-stats dt { color: var(--aa-muted); font-size: .7rem; font-weight: 600; }
.aa-stats dd { margin: 2px 0 0; color: var(--aa-ink); font-size: 1.2rem; font-weight: 700; font-variant-numeric: tabular-nums; }

/* Toast */
.aa-live:empty { display: none; }
.aa-toast { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 18px; padding: 12px 12px 12px 16px; border: 1px solid; border-radius: 10px; font-size: .88rem; font-weight: 550; line-height: 1.45; }
.aa-toast > svg { flex: none; margin-top: 1px; }
.aa-toast span { flex: 1; }
.aa-toast--success { border-color: #b7e4d2; background: #f0faf6; color: #075e48; }
.aa-toast--error { border-color: #f5c2bd; background: #fef4f3; color: var(--aa-red); }
.aa-fade-enter-active, .aa-fade-leave-active { transition: opacity .18s ease, transform .18s ease; }
.aa-fade-enter-from, .aa-fade-leave-to { opacity: 0; transform: translateY(-4px); }

/* Layout */
.aa-grid { display: grid; grid-template-columns: minmax(340px, 540px); gap: 24px; align-items: start; }
.aa-card { min-width: 0; border: 1px solid var(--aa-line); border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 28, 46, .04), 0 6px 20px rgba(15, 28, 46, .04); }
.aa-card--form { position: sticky; top: 92px; }
.aa-card-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--aa-line-soft); }
.aa-card-head h2 { margin: 0; color: var(--aa-ink); font-size: 1rem; font-weight: 700; }
.aa-card-head p { margin: 2px 0 0; color: var(--aa-muted); font-size: .8rem; }
.aa-card-icon { display: grid; flex: none; width: 38px; height: 38px; place-items: center; border-radius: 10px; background: var(--aa-green-soft); color: var(--aa-green); }

/* Form */
.aa-form { display: grid; gap: 18px; padding: 20px 22px 22px; }
.aa-field { display: grid; align-content: start; gap: 6px; }
.aa-field > label { display: block; margin: 0; color: var(--aa-ink); font-size: .84rem; font-weight: 600; }
.aa-req { color: var(--aa-red); }
.aa-field input,
.aa-select select {
  width: 100%;
  min-width: 0;
  height: 44px;
  min-height: 44px;
  box-sizing: border-box;
  margin: 0;
  padding: 0 14px;
  border: 1px solid #cfd9e3;
  border-radius: 9px;
  background: #fff;
  color: var(--aa-ink);
  font: inherit;
  font-size: .9rem;
  box-shadow: none;
  transition: border-color .15s ease, box-shadow .15s ease;
}
.aa-field input::placeholder { color: #8a9bab; }
.aa-field input:hover, .aa-select select:hover { border-color: #b3c2d0; }
.aa-field input:focus, .aa-select select:focus { border-color: var(--aa-green); outline: none; box-shadow: 0 0 0 3px rgba(11, 127, 98, .16); }
.aa-field.has-error input { border-color: var(--aa-red); }
.aa-field.has-error input:focus { box-shadow: 0 0 0 3px rgba(180, 35, 24, .14); }
.aa-err { margin: 0; color: var(--aa-red); font-size: .8rem; font-weight: 550; }
.aa-hint { margin: 0; color: var(--aa-muted); font-size: .8rem; line-height: 1.45; }
.aa-rule { display: flex; align-items: center; gap: 6px; margin: 0; color: var(--aa-muted); font-size: .8rem; }
.aa-rule.is-met { color: var(--aa-green); }

.aa-password { position: relative; }
.aa-password input { padding-right: 48px; }
.aa-password-toggle { position: absolute; top: 4px; right: 4px; }

.aa-select { position: relative; display: block; }
.aa-select select { padding-right: 38px; appearance: none; cursor: pointer; }
.aa-select-chevron { position: absolute; top: 50%; right: 12px; color: var(--aa-muted); pointer-events: none; transform: translateY(-50%); }

.aa-role-card { display: flex; gap: 10px; margin-top: 4px; padding: 11px 12px; border: 1px solid var(--aa-line); border-radius: 9px; background: #f8fafc; color: var(--aa-muted); }
.aa-role-card > svg { flex: none; margin-top: 1px; color: var(--aa-green); }
.aa-role-card strong { display: block; color: var(--aa-ink); font-size: .82rem; }
.aa-role-card p { margin: 2px 0 0; font-size: .8rem; line-height: 1.45; }
.aa-role-card.is-full { border-color: #f1d8a8; background: #fffaf0; }
.aa-role-card.is-full > svg { color: var(--aa-amber); }

.aa-form-error { display: flex; align-items: flex-start; gap: 8px; margin: 0; padding: 10px 12px; border: 1px solid #f5c2bd; border-radius: 9px; background: #fef4f3; color: var(--aa-red); font-size: .84rem; font-weight: 550; }
.aa-form-error svg { flex: none; margin-top: 2px; }

/* Buttons */
.aa-btn {
  display: inline-flex;
  min-width: 0;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin: 0;
  padding: 0 16px;
  border: 1px solid transparent;
  border-radius: 9px;
  background: transparent;
  box-shadow: none;
  font: inherit;
  font-size: .86rem;
  font-weight: 650;
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.aa-btn:hover, .aa-btn:not(:disabled):hover { transform: none; }
.aa-btn:disabled { cursor: not-allowed; opacity: .6; }
.aa-btn[aria-busy="true"] { cursor: progress; opacity: .85; }
.aa-btn--primary { background: var(--aa-green); color: #fff; }
.aa-btn--primary:not(:disabled):hover { background: var(--aa-green-dark); }
.aa-btn--ghost { border-color: #cfd9e3; background: #fff; color: var(--aa-ink); }
.aa-btn--ghost:not(:disabled):hover { border-color: #b3c2d0; background: #f6f8fa; }
.aa-btn--quiet { min-height: 36px; padding: 0 10px; color: var(--aa-text); font-size: .82rem; }
.aa-btn--quiet:not(:disabled):hover { background: #f1f5f9; color: var(--aa-ink); }
.aa-btn--danger { color: var(--aa-red); }
.aa-btn--danger:not(:disabled):hover { background: #fef4f3; color: #912018; }
.aa-btn--danger-solid { background: var(--aa-red); color: #fff; }
.aa-btn--danger-solid:not(:disabled):hover { background: #912018; }
.aa-submit { width: 100%; min-height: 46px; margin-top: 2px; font-size: .92rem; }
.aa-icon-btn { display: grid; width: 36px; min-width: 0; height: 36px; min-height: 0; place-items: center; margin: 0; padding: 0; border: 0; border-radius: 7px; background: transparent; box-shadow: none; color: var(--aa-muted); cursor: pointer; }
.aa-icon-btn:hover, .aa-icon-btn:not(:disabled):hover { background: rgba(15, 28, 46, .06); color: var(--aa-ink); transform: none; }
.aa-btn:focus-visible, .aa-icon-btn:focus-visible { outline: 3px solid rgba(11, 127, 98, .35); outline-offset: 2px; }
.aa-spinner { width: 15px; height: 15px; border: 2px solid rgba(255, 255, 255, .45); border-top-color: #fff; border-radius: 50%; animation: aa-spin .7s linear infinite; }
@keyframes aa-spin { to { transform: rotate(360deg); } }

/* Team list */
.aa-card--list { container-type: inline-size; overflow: hidden; }
.aa-tools { display: flex; gap: 10px; padding: 14px 22px; border-bottom: 1px solid var(--aa-line-soft); }
.aa-search { position: relative; display: flex; flex: 1 1 auto; min-width: 0; align-items: center; }
.aa-search svg { position: absolute; left: 12px; color: var(--aa-muted); pointer-events: none; }
.aa-search input { width: 100%; height: 40px; min-height: 40px; box-sizing: border-box; margin: 0; padding: 0 12px 0 36px; border: 1px solid #cfd9e3; border-radius: 9px; background: #fff; color: var(--aa-ink); font: inherit; font-size: .88rem; }
.aa-search input:focus { border-color: var(--aa-green); outline: none; box-shadow: 0 0 0 3px rgba(11, 127, 98, .16); }
.aa-select--sm { flex: 0 0 180px; }
.aa-select--sm select { height: 40px; min-height: 40px; }

.aa-members { margin: 0; padding: 0; list-style: none; }
.aa-member {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) minmax(150px, auto) auto;
  align-items: center;
  gap: 14px;
  padding: 14px 22px;
  border-bottom: 1px solid var(--aa-line-soft);
  transition: background-color .4s ease;
}
.aa-member:last-child { border-bottom: 0; }
.aa-member:hover { background: #fafbfc; }
.aa-member.is-new { background: #f0faf6; }
.aa-avatar { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 50%; background: #e2e8f0; color: #334155; font-size: .78rem; font-weight: 700; }
.aa-avatar.is-master { background: #0f2f39; color: #fff; }
.aa-member-main { min-width: 0; }
.aa-member-name { display: flex; align-items: center; gap: 8px; margin: 0; }
.aa-member-name strong { overflow: hidden; color: var(--aa-ink); font-size: .92rem; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
.aa-you { flex: none; padding: 2px 8px; border-radius: 999px; background: var(--aa-green-soft); color: var(--aa-green-dark); font-size: .7rem; font-weight: 700; }
.aa-member-email { margin: 2px 0 0; overflow: hidden; color: var(--aa-muted); font-size: .84rem; text-overflow: ellipsis; white-space: nowrap; }
.aa-member-role { display: grid; justify-items: start; gap: 4px; }
.aa-member-role small { color: var(--aa-muted); font-size: .74rem; }
.aa-role { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border: 1px solid; border-radius: 999px; font-size: .76rem; font-weight: 650; white-space: nowrap; }
.aa-role--master { border-color: #0f2f39; background: #0f2f39; color: #fff; }
.aa-role--own { border-color: #cfe6dd; background: var(--aa-green-soft); color: #075e48; }
.aa-member-actions { display: flex; justify-content: flex-end; gap: 4px; }
.aa-self-note { padding: 0 10px; color: var(--aa-muted); font-size: .8rem; }
.aa-footnote { display: flex; align-items: flex-start; gap: 8px; margin: 0; padding: 12px 22px 14px; border-top: 1px solid var(--aa-line-soft); background: #fafbfc; color: var(--aa-muted); font-size: .78rem; line-height: 1.45; }
.aa-footnote svg { flex: none; margin-top: 2px; }

@container (max-width: 620px) {
  .aa-member { grid-template-columns: 40px minmax(0, 1fr); grid-template-areas: "av main" "av role" "actions actions"; row-gap: 8px; padding: 14px 16px; }
  .aa-avatar { grid-area: av; align-self: start; }
  .aa-member-main { grid-area: main; }
  .aa-member-role { grid-area: role; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .aa-member-actions { grid-area: actions; flex-wrap: wrap; justify-content: flex-start; margin-left: 42px; }
  .aa-tools { padding: 12px 16px; }
}
@container (max-width: 420px) {
  .aa-member { grid-template-columns: 40px minmax(0, 1fr); grid-template-areas: "av main" "av role" "actions actions"; }
  .aa-member-actions { flex-direction: row; justify-content: flex-start; margin-left: 42px; }
  .aa-tools { flex-direction: column; }
  .aa-select--sm { flex-basis: auto; }
}

/* States */
.aa-state { display: grid; justify-items: center; gap: 6px; padding: 48px 24px; text-align: center; }
.aa-state strong { color: var(--aa-ink); font-size: .95rem; }
.aa-state p { max-width: 36ch; margin: 0 0 8px; color: var(--aa-muted); font-size: .86rem; }
.aa-state-icon { display: grid; width: 48px; height: 48px; margin-bottom: 6px; place-items: center; border-radius: 12px; background: var(--aa-green-soft); color: var(--aa-green); }
.aa-state-icon.is-error { background: #fef4f3; color: var(--aa-red); }

.aa-skeletons { padding: 8px 22px 14px; }
.aa-skeleton-row { display: flex; align-items: center; gap: 14px; padding: 12px 0; }
.aa-skeleton { display: block; border-radius: 6px; background: linear-gradient(90deg, #eef2f6 25%, #f6f8fa 50%, #eef2f6 75%); background-size: 200% 100%; animation: aa-shimmer 1.3s ease infinite; }
.aa-skeleton--avatar { flex: none; width: 40px; height: 40px; border-radius: 50%; }
.aa-skeleton-lines { display: grid; flex: 1; gap: 8px; }
.aa-skeleton--w60 { width: 55%; height: 11px; }
.aa-skeleton--w40 { width: 38%; height: 10px; }
.aa-skeleton--pill { flex: none; width: 96px; height: 24px; border-radius: 999px; }
@keyframes aa-shimmer { to { background-position: -200% 0; } }

/* Dialog */
.aa-dialog { position: fixed; inset: 0; margin: auto; height: fit-content; width: min(460px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); padding: 0; border: 0; border-radius: 14px; background: #fff; box-shadow: 0 24px 60px rgba(15, 28, 46, .28); color: var(--aa-text); }
.aa-dialog::backdrop { background: rgba(15, 28, 46, .5); }
.aa-field input { box-sizing: border-box; width: 100%; height: 42px; padding: 0 12px; border: 1px solid #cfdbe7; border-radius: 10px; font: inherit; }
.aa-profile { display: grid; max-height: 50vh; gap: 0; margin: 0; overflow-y: auto; }
.aa-profile > div { display: flex; justify-content: space-between; gap: 16px; padding: 8px 0; border-bottom: 1px solid #e6ecf1; font-size: .88rem; }
.aa-profile dt { flex: none; color: #5f7286; }
.aa-profile dd { margin: 0; text-align: right; overflow-wrap: anywhere; }
.aa-profile-docs { display: flex; flex-wrap: wrap; gap: 8px; }
.aa-dialog-body { display: grid; gap: 14px; padding: 24px; }
.aa-dialog-body h2 { margin: 0; color: var(--aa-ink); font-size: 1.12rem; font-weight: 700; }
.aa-dialog-body p { margin: 0; font-size: .9rem; line-height: 1.5; }
.aa-dialog-icon { display: grid; width: 44px; height: 44px; place-items: center; border-radius: 12px; background: var(--aa-green-soft); color: var(--aa-green); }
.aa-dialog-icon.is-warn { background: #fdf3e1; color: var(--aa-amber); }
.aa-dialog-icon.is-danger { background: #fef4f3; color: var(--aa-red); }
.aa-dialog-person { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--aa-line); border-radius: 10px; background: #f8fafc; }
.aa-dialog-person div { display: grid; min-width: 0; gap: 2px; }
.aa-dialog-person strong { color: var(--aa-ink); font-size: .92rem; }
.aa-dialog-person span:not(.aa-avatar) { overflow: hidden; color: var(--aa-muted); font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
.aa-consequences { display: grid; list-style: disc; gap: 6px; margin: 14px 0 10px; padding-left: 20px; font-size: .88rem; line-height: 1.45; }
.aa-strong-note { color: var(--aa-red); font-weight: 650; }
.aa-dialog-body .aa-callout { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border: 1px solid #f1d8a8; border-radius: 9px; background: #fffaf0; color: var(--aa-amber); font-size: .84rem; font-weight: 550; }
.aa-dialog-body .aa-callout svg { flex: none; margin-top: 2px; }
.aa-dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }

.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

/* Responsive */
@media (max-width: 1080px) {
  .aa-grid { grid-template-columns: minmax(0, 1fr); }
  .aa-card--form { position: static; }
  .aa-form { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 16px; }
  .aa-form > .aa-form-error, .aa-submit { grid-column: 1 / -1; }
  .aa-submit { justify-self: end; width: auto; min-width: 200px; }
}
@media (max-width: 640px) {
  .aa-page { padding-top: 18px; }
  .aa-head { flex-direction: column; align-items: stretch; }
  .aa-stats div { flex: 1; }
  .aa-form { grid-template-columns: minmax(0, 1fr); padding: 18px 16px; }
  .aa-submit { width: 100%; justify-self: stretch; }
  .aa-card-head { padding: 16px; }
  .aa-dialog-actions { flex-direction: column-reverse; }
  .aa-dialog-actions .aa-btn { width: 100%; min-height: 44px; }
  .aa-btn--quiet { min-height: 40px; }
}
@media (prefers-reduced-motion: reduce) {
  .aa-skeleton, .aa-spinner { animation: none; }
  .aa-member, .aa-fade-enter-active, .aa-fade-leave-active { transition: none; }
}
</style>
