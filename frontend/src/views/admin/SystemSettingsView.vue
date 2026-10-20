<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { useAuthStore } from '../../stores/auth'
import api from '../../services/api'

const auth = useAuthStore()
const canManageStaff = computed(() => auth.isAdmin || auth.hasPermission('users.manage'))
const canManageRoles = computed(() => auth.isAdmin || auth.hasPermission('roles.manage'))
const canManageWorkflows = computed(() => auth.isAdmin || auth.hasPermission('workflows.manage'))

const DEFAULTS = Object.freeze({
  site_name: 'Event Registration System',
  registration_notice: '',
  maintenance_mode: false
})

const settings = reactive({ ...DEFAULTS })
const originalSettings = ref(null)
const loading = ref(true)
const loaded = ref(false)
const saving = ref(false)
const message = ref('')
const error = ref('')
const activeTab = ref('general')
const accessLoading = ref(false)
const accessSaving = ref(false)
const accessError = ref('')
const accessMessage = ref('')
const access = reactive({ roles: [], permissions: [], staff: [], users: [], workflows: [] })
const rolePermissionDrafts = reactive({})
const rolePermissionOriginals = reactive({})
const staffForm = reactive({ staff_id: '', user_id: '', name: '', phone: '', position: '', employment_status: 'active' })
const editingStaffId = ref(null)
const workflowForm = reactive({ name: '', description: '', is_active: true, approval_rule: 'sequential', stages: [] })
const editingWorkflowId = ref(null)

const hasChanges = computed(() => originalSettings.value !== null && JSON.stringify(settings) !== JSON.stringify(originalSettings.value))
const activeTabIntro = computed(() => ({
  staff: { title: 'Staff and login accounts', text: 'Keep staff details here, then link each person to an account so they can sign in. Inactive staff cannot access the system.' },
  roles: { title: 'Roles and access', text: 'Choose what each role can do. Changes apply to everyone assigned that role. Master Admin access is protected.' },
  workflows: { title: 'Approval workflows', text: 'Build a review path by adding stages, choosing who reviews each stage, and setting how many approvals are needed.' }
}[activeTab.value] || null))
const siteInitials = computed(() => settings.site_name.split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join('').toUpperCase() || 'ER')
const noticeLength = computed(() => settings.registration_notice.length)
const permissionGroups = computed(() => [...new Set(access.permissions.map((permission) => permission.group))])
const availableUsers = computed(() => access.users.filter((user) => !access.staff.some((staff) => staff.user_id === user.id && staff.id !== editingStaffId.value)))
const initials = (name = '') => name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('') || '?'

const snapshot = () => JSON.parse(JSON.stringify(settings))
const applySettings = (data = {}) => {
  Object.assign(settings, {
    site_name: typeof data.site_name === 'string' ? data.site_name : DEFAULTS.site_name,
    registration_notice: typeof data.registration_notice === 'string' ? data.registration_notice : '',
    maintenance_mode: data.maintenance_mode === true || data.maintenance_mode === 1 || data.maintenance_mode === '1' || data.maintenance_mode === 'true'
  })
}

const setError = (exception, action) => {
  const status = exception.response?.status
  if (status === 401) return 'Your session expired. Sign in again to continue.'
  if (status === 403) return `You do not have permission to ${action} system settings.`
  const validationMessage = Object.values(exception.response?.data?.errors || {}).flat()[0]
  return validationMessage || exception.response?.data?.message || `Unable to ${action} system settings. Please try again.`
}

const load = async () => {
  loading.value = true
  loaded.value = false
  error.value = ''
  message.value = ''
  try {
    const { data } = await api.get('/admin/settings')
    applySettings(data?.data || {})
    originalSettings.value = snapshot()
    loaded.value = true
  } catch (exception) {
    error.value = setError(exception, 'load')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  if (!hasChanges.value || !settings.site_name.trim()) return
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await api.put('/admin/settings', {
      site_name: settings.site_name.trim(),
      registration_notice: settings.registration_notice.trim(),
      maintenance_mode: settings.maintenance_mode
    })
    applySettings(data?.data || settings)
    originalSettings.value = snapshot()
    message.value = 'Your settings are saved and active.'
  } catch (exception) {
    error.value = setError(exception, 'save')
  } finally {
    saving.value = false
  }
}

const discardChanges = () => {
  if (!originalSettings.value) return
  Object.assign(settings, originalSettings.value)
  error.value = ''
  message.value = ''
}

const resetToDefaults = async () => {
  if (!window.confirm('Restore the default system name, clear the registration notice, and turn off maintenance mode?')) return
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await api.put('/admin/settings', DEFAULTS)
    applySettings(data?.data || DEFAULTS)
    originalSettings.value = snapshot()
    message.value = 'Default settings restored.'
  } catch (exception) {
    error.value = setError(exception, 'reset')
  } finally {
    saving.value = false
  }
}

const loadAccess = async () => {
  accessLoading.value = true
  accessError.value = ''
  try {
    const { data } = await api.get('/admin/settings/access-control')
    for (const key of ['roles', 'permissions', 'staff', 'users', 'workflows']) access[key] = data?.data?.[key] || []
    for (const role of access.roles) {
      rolePermissionDrafts[role.id] = role.permissions.map((permission) => permission.id)
      rolePermissionOriginals[role.id] = [...rolePermissionDrafts[role.id]]
    }
    if (!editingWorkflowId.value && workflowForm.stages.length === 1 && !workflowForm.stages[0].name) {
      workflowForm.stages[0].required_role_id = access.roles.find((role) => role.key === 'maker')?.id || access.roles[0]?.id || ''
    }
  } catch (exception) {
    accessError.value = setError(exception, 'load access control')
  } finally {
    accessLoading.value = false
  }
}

const roleHasPermission = (role, permission) => (rolePermissionDrafts[role.id] || []).includes(permission.id)
const rolePermissionsChanged = (role) => JSON.stringify(rolePermissionDrafts[role.id] || []) !== JSON.stringify(rolePermissionOriginals[role.id] || [])
const toggleRolePermission = (role, permission, checked) => {
  const ids = new Set(rolePermissionDrafts[role.id] || [])
  checked ? ids.add(permission.id) : ids.delete(permission.id)
  rolePermissionDrafts[role.id] = [...ids]
}
const saveRolePermissions = async (role) => {
  accessSaving.value = true
  accessError.value = ''
  accessMessage.value = ''
  try {
    const { data } = await api.put(`/admin/settings/access-control/roles/${role.id}/permissions`, { permission_ids: rolePermissionDrafts[role.id] || [] })
    role.permissions = data.data.permissions
    rolePermissionOriginals[role.id] = [...(rolePermissionDrafts[role.id] || [])]
    accessMessage.value = `${role.name} permissions saved.`
  } catch (exception) {
    accessError.value = setError(exception, 'save role permissions')
  } finally {
    accessSaving.value = false
  }
}

const resetStaffForm = () => {
  Object.assign(staffForm, { staff_id: '', user_id: '', name: '', phone: '', position: '', employment_status: 'active' })
  editingStaffId.value = null
}
const editStaff = (staff) => {
  Object.assign(staffForm, { staff_id: staff.staff_id, user_id: staff.user_id || '', name: staff.name, phone: staff.phone || '', position: staff.position || '', employment_status: staff.employment_status })
  editingStaffId.value = staff.id
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
const saveStaff = async () => {
  accessSaving.value = true
  accessError.value = ''
  accessMessage.value = ''
  try {
    const payload = { ...staffForm, user_id: staffForm.user_id || null }
    const url = editingStaffId.value ? `/admin/settings/access-control/staff/${editingStaffId.value}` : '/admin/settings/access-control/staff'
    const { data } = editingStaffId.value ? await api.put(url, payload) : await api.post(url, payload)
    const index = access.staff.findIndex((staff) => staff.id === data.data.id)
    if (index < 0) access.staff.unshift(data.data)
    else access.staff[index] = data.data
    accessMessage.value = editingStaffId.value ? 'Staff record updated.' : 'Staff record created.'
    resetStaffForm()
  } catch (exception) {
    accessError.value = Object.values(exception.response?.data?.errors || {}).flat()[0] || setError(exception, 'save staff')
  } finally {
    accessSaving.value = false
  }
}
const setAccountStatus = async (staff) => {
  if (!staff.user) return
  const nextStatus = staff.user.account_status === 'suspended' ? 'active' : 'suspended'
  if (nextStatus === 'suspended' && !window.confirm(`Suspend ${staff.user.name}'s login account?`)) return
  accessSaving.value = true
  accessError.value = ''
  try {
    const { data } = await api.put(`/admin/settings/access-control/users/${staff.user.id}/status`, { account_status: nextStatus })
    staff.user = { ...staff.user, ...data.data }
    access.users = access.users.map((user) => user.id === data.data.id ? { ...user, ...data.data } : user)
    accessMessage.value = `${data.data.name}'s account is ${nextStatus}.`
  } catch (exception) {
    accessError.value = setError(exception, 'update account status')
  } finally {
    accessSaving.value = false
  }
}

const newWorkflowStage = (roleId = access.roles.find((role) => role.key === 'maker')?.id || access.roles[0]?.id || '') => ({ name: '', required_role_id: roleId, required_approvals: 1, approval_rule: 'any' })
const resetWorkflowForm = () => {
  Object.assign(workflowForm, { name: '', description: '', is_active: true, approval_rule: 'sequential', stages: [newWorkflowStage()] })
  editingWorkflowId.value = null
}
const editWorkflow = (workflow) => {
  Object.assign(workflowForm, {
    name: workflow.name,
    description: workflow.description || '',
    is_active: workflow.is_active,
    approval_rule: workflow.approval_rule,
    stages: workflow.stages.map((stage) => ({ name: stage.name, required_role_id: stage.required_role_id, required_approvals: stage.required_approvals, approval_rule: stage.approval_rule }))
  })
  editingWorkflowId.value = workflow.id
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
const moveWorkflowStage = (index, direction) => {
  const target = index + direction
  if (target < 0 || target >= workflowForm.stages.length) return
  const [stage] = workflowForm.stages.splice(index, 1)
  workflowForm.stages.splice(target, 0, stage)
}
const saveWorkflow = async () => {
  accessSaving.value = true
  accessError.value = ''
  accessMessage.value = ''
  try {
    const url = editingWorkflowId.value ? `/admin/settings/access-control/workflows/${editingWorkflowId.value}` : '/admin/settings/access-control/workflows'
    const { data } = editingWorkflowId.value ? await api.put(url, workflowForm) : await api.post(url, workflowForm)
    const index = access.workflows.findIndex((workflow) => workflow.id === data.data.id)
    if (index < 0) access.workflows.push(data.data)
    else access.workflows[index] = data.data
    accessMessage.value = editingWorkflowId.value ? 'Workflow updated.' : 'Workflow created.'
    resetWorkflowForm()
  } catch (exception) {
    accessError.value = Object.values(exception.response?.data?.errors || {}).flat()[0] || setError(exception, 'save workflow')
  } finally {
    accessSaving.value = false
  }
}
const deleteWorkflow = async (workflow) => {
  if (!window.confirm(`Delete the “${workflow.name}” workflow and its stages?`)) return
  accessSaving.value = true
  accessError.value = ''
  try {
    await api.delete(`/admin/settings/access-control/workflows/${workflow.id}`)
    access.workflows = access.workflows.filter((item) => item.id !== workflow.id)
    if (editingWorkflowId.value === workflow.id) resetWorkflowForm()
    accessMessage.value = 'Workflow deleted.'
  } catch (exception) {
    accessError.value = setError(exception, 'delete workflow')
  } finally {
    accessSaving.value = false
  }
}

onMounted(() => {
  resetWorkflowForm()
  if (auth.isAdmin) load()
  else activeTab.value = canManageStaff.value ? 'staff' : canManageRoles.value ? 'roles' : 'workflows'
  loadAccess()
})
</script>

<template>
  <AdminLayout>
    <div class="settings-page">
      <header class="settings-page-header">
        <div>
          <p class="settings-eyebrow">Administration</p>
          <h1>System settings</h1>
          <p class="settings-page-lede">Manage your site, staff access, roles, and approval steps.</p>
        </div>
        <div class="settings-header-status">
          <span v-if="hasChanges && activeTab === 'general'" class="unsaved-status">Unsaved changes</span>
          <div v-if="loaded" class="system-status-pill" :class="settings.maintenance_mode ? 'is-maintenance' : 'is-live'"><span class="status-indicator"></span>{{ settings.maintenance_mode ? 'Maintenance is on' : 'System is live' }}</div>
        </div>
      </header>

      <nav class="settings-tabs" aria-label="Settings sections">
        <button v-if="auth.isAdmin" type="button" :class="{ 'is-active': activeTab === 'general' }" @click="activeTab = 'general'">Site settings</button>
        <button v-if="canManageStaff" type="button" :class="{ 'is-active': activeTab === 'staff' }" @click="activeTab = 'staff'">Staff <span>{{ access.staff.length }}</span></button>
        <button v-if="canManageRoles" type="button" :class="{ 'is-active': activeTab === 'roles' }" @click="activeTab = 'roles'">Roles</button>
        <button v-if="canManageWorkflows" type="button" :class="{ 'is-active': activeTab === 'workflows' }" @click="activeTab = 'workflows'">Approval workflows <span>{{ access.workflows.length }}</span></button>
      </nav>

      <section v-if="activeTabIntro" class="settings-section-intro"><span class="section-intro-icon" aria-hidden="true">{{ activeTab === 'staff' ? '01' : activeTab === 'roles' ? '02' : '03' }}</span><div><h2>{{ activeTabIntro.title }}</h2><p>{{ activeTabIntro.text }}</p></div></section>

      <div v-if="activeTab === 'general' && loading" class="settings-loading" role="status">
        <span class="loading-spinner" aria-hidden="true"></span>
        <span>Loading your system settings…</span>
      </div>

      <div v-if="activeTab === 'general' && error" class="settings-feedback settings-feedback--error" role="alert">
        <span class="feedback-icon" aria-hidden="true">!</span>
        <p>{{ error }}</p>
        <button v-if="!loaded" type="button" class="text-button" :disabled="loading" @click="load">Try again</button>
      </div>
      <div v-if="activeTab === 'general' && message" class="settings-feedback settings-feedback--success" role="status">
        <span class="feedback-icon" aria-hidden="true">✓</span>
        <p>{{ message }}</p>
      </div>

      <form v-if="loaded && activeTab === 'general'" class="settings-layout" @submit.prevent="save">
        <div class="settings-content">
          <section class="settings-panel">
            <header class="panel-heading">
              <span class="panel-icon panel-icon--blue" aria-hidden="true">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 1.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2h-2.6v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1-1.8-1.8.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H6.3v-2.6h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.8-1.8.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5v-.2H15v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.8 1.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.2V14h-.2a1.7 1.7 0 0 0-1.5 1Z"/></svg>
              </span>
              <div><h2>Site identity</h2><p>Set the name shown in the browser and across attendee pages.</p></div>
            </header>
            <div class="panel-body">
              <label class="settings-field" for="site-name">
                <span class="field-label">System name <span class="required-mark">Required</span></span>
                <input id="site-name" v-model.trim="settings.site_name" type="text" maxlength="150" autocomplete="organization" placeholder="Event Registration System" required />
                <span class="field-help">This name appears in the browser tab and on attendee pages.</span>
              </label>
            </div>
          </section>

          <section class="settings-panel">
            <header class="panel-heading">
              <span class="panel-icon panel-icon--violet" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z"/><path d="M4 6v14.5A2.5 2.5 0 0 1 6.5 18H20M8 7h8m-8 4h8"/></svg>
              </span>
              <div><h2>Attendee communication</h2><p>Share a helpful note with people as they register for an event.</p></div>
            </header>
            <div class="panel-body">
              <label class="settings-field" for="registration-notice">
                <span class="field-label">Registration notice <span class="field-optional">Optional</span></span>
                <textarea id="registration-notice" v-model="settings.registration_notice" rows="5" maxlength="1000" placeholder="For example: Please arrive 15 minutes early and bring a photo ID." />
                <span class="field-help field-help--split"><span>Shown above the event registration form.</span><span :class="{ 'is-near-limit': noticeLength > 900 }">{{ noticeLength }} / 1000</span></span>
              </label>
              <div v-if="settings.registration_notice" class="notice-preview">
                <span class="preview-label">Attendee preview</span>
                <div class="preview-message"><span aria-hidden="true">i</span><p>{{ settings.registration_notice }}</p></div>
              </div>
            </div>
          </section>

          <section class="settings-panel settings-panel--availability">
            <header class="panel-heading">
              <span class="panel-icon panel-icon--amber" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 3 2.8 19a1.4 1.4 0 0 0 1.2 2.1h16a1.4 1.4 0 0 0 1.2-2.1L12 3Z"/><path d="M12 9v5m0 3h.01"/></svg>
              </span>
              <div><h2>Availability</h2><p>Temporarily pause access while you perform system work.</p></div>
            </header>
            <label class="maintenance-control" for="maintenance-mode">
              <span class="maintenance-copy">
                <strong>Maintenance mode</strong>
                <span>{{ settings.maintenance_mode ? 'Attendees and staff cannot use the system. Admin accounts remain available.' : 'The registration system is open to attendees and staff.' }}</span>
              </span>
              <span class="switch-wrap"><input id="maintenance-mode" v-model="settings.maintenance_mode" type="checkbox" role="switch" :aria-checked="settings.maintenance_mode" /><span class="switch-ui" aria-hidden="true"></span></span>
            </label>
            <p v-if="settings.maintenance_mode !== originalSettings?.maintenance_mode" class="maintenance-unsaved">This change takes effect after you save.</p>
          </section>

          <footer class="settings-actions">
            <div class="settings-actions-secondary">
              <button type="button" class="settings-button settings-button--quiet" :disabled="saving || !hasChanges" @click="discardChanges">Discard changes</button>
              <button type="button" class="settings-button settings-button--reset" :disabled="saving" @click="resetToDefaults">Restore defaults</button>
            </div>
            <button type="submit" class="settings-button settings-button--primary" :disabled="saving || !hasChanges || !settings.site_name.trim()">
              <span v-if="saving" class="button-spinner" aria-hidden="true"></span>
              <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8m0-18v5h8"/></svg>
              {{ saving ? 'Saving…' : 'Save changes' }}
            </button>
          </footer>
        </div>

        <aside class="settings-aside" aria-label="Settings summary">
          <section class="preview-card">
            <div class="preview-card-top"><span class="preview-label">Site identity</span><span class="preview-live"><i></i> Live preview</span></div>
            <div class="identity-preview"><span class="identity-avatar">{{ siteInitials }}</span><div><strong>{{ settings.site_name || 'Your system name' }}</strong><span>Event registration</span></div></div>
            <div class="preview-divider"></div>
            <div class="summary-row"><span>Availability</span><strong :class="settings.maintenance_mode ? 'summary-maintenance' : 'summary-live'">{{ settings.maintenance_mode ? 'Paused' : 'Operational' }}</strong></div>
            <div class="summary-row"><span>Registration notice</span><strong>{{ settings.registration_notice ? 'Configured' : 'Not set' }}</strong></div>
          </section>
          <section class="help-card">
            <span class="help-icon" aria-hidden="true">?</span>
            <h3>Remember to save</h3>
            <p>Your edits take effect after you select Save changes. Restore defaults resets the site name, notice, and maintenance mode.</p>
          </section>
        </aside>
      </form>

      <template v-if="activeTab !== 'general'">
        <div v-if="accessLoading" class="settings-loading" role="status"><span class="loading-spinner" aria-hidden="true"></span><span>Loading access and workflow settings…</span></div>
        <div v-if="accessError" class="settings-feedback settings-feedback--error" role="alert"><span class="feedback-icon" aria-hidden="true">!</span><p>{{ accessError }}</p><button type="button" class="text-button" @click="loadAccess">Try again</button></div>
        <div v-if="accessMessage" class="settings-feedback settings-feedback--success" role="status"><span class="feedback-icon" aria-hidden="true">✓</span><p>{{ accessMessage }}</p></div>
      </template>

      <section v-if="canManageStaff && activeTab === 'staff' && !accessLoading && !accessError" class="settings-admin-grid">
        <form class="settings-panel admin-form-panel" @submit.prevent="saveStaff">
          <header class="panel-heading"><span class="panel-icon panel-icon--blue" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 21a6 6 0 0 1 12 0m2-10v6m3-3h-6"/></svg></span><div><h2>{{ editingStaffId ? 'Edit staff member' : 'Add a staff member' }}</h2><p>Enter their work details. You can link a login account below.</p></div></header>
          <div class="admin-form-fields">
            <label class="settings-field"><span class="field-label">Staff ID <span class="required-mark">Required</span></span><input v-model.trim="staffForm.staff_id" required maxlength="40" placeholder="ST-001" /></label>
            <label class="settings-field"><span class="field-label">Full name <span class="required-mark">Required</span></span><input v-model.trim="staffForm.name" required maxlength="120" autocomplete="name" placeholder="Staff member name" /></label>
            <label class="settings-field"><span class="field-label">Phone</span><input v-model.trim="staffForm.phone" type="tel" maxlength="30" autocomplete="tel" placeholder="Phone number" /></label>
            <label class="settings-field"><span class="field-label">Position</span><input v-model.trim="staffForm.position" maxlength="120" placeholder="e.g. Programme Officer" /></label>
            <label class="settings-field"><span class="field-label">Employment status</span><select v-model="staffForm.employment_status"><option value="active">Active</option><option value="on_leave">On leave</option><option value="inactive">Inactive</option></select></label>
            <label class="settings-field"><span class="field-label">Login account</span><select v-model="staffForm.user_id"><option value="">No account linked</option><option v-for="user in availableUsers" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email || user.phone || 'No contact' }}</option></select><span class="field-help">Create accounts under Admin Accounts, then link them here.</span></label>
          </div>
          <div class="admin-form-actions"><button type="button" class="settings-button settings-button--quiet" @click="resetStaffForm">Clear</button><button type="submit" class="settings-button settings-button--primary" :disabled="accessSaving"><span v-if="accessSaving" class="button-spinner" aria-hidden="true"></span>{{ editingStaffId ? 'Save staff record' : 'Add staff member' }}</button></div>
        </form>

        <section class="settings-panel staff-list-panel">
          <header class="panel-heading"><span class="panel-icon panel-icon--violet" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20 8v6m3-3h-6"/></svg></span><div><h2>Staff directory <span class="panel-count">{{ access.staff.length }}</span></h2><p>Employment status and login access at a glance.</p></div></header>
          <div v-if="!access.staff.length" class="empty-state"><span>✦</span><strong>No staff records yet</strong><p>Add staff above to create your directory.</p></div>
          <div v-else class="staff-list">
            <article v-for="staff in access.staff" :key="staff.id" class="staff-row">
              <span class="staff-avatar">{{ initials(staff.name) }}</span>
              <div class="staff-main"><div class="staff-name-line"><strong>{{ staff.name }}</strong><span class="staff-status" :class="`status-${staff.employment_status}`">{{ staff.employment_status.replace('_', ' ') }}</span></div><span class="staff-meta">{{ staff.staff_id }} · {{ staff.position || 'No position set' }}</span><span class="staff-meta">{{ staff.phone || 'No phone' }}<template v-if="staff.user"> · {{ staff.user.email || staff.user.phone }}</template><template v-else> · No login account</template></span></div>
              <div class="staff-actions"><button type="button" class="icon-action" :aria-label="`Edit ${staff.name}`" @click="editStaff(staff)">Edit</button><button v-if="staff.user" type="button" class="icon-action" :class="staff.user.account_status === 'suspended' ? 'action-activate' : 'action-suspend'" :disabled="accessSaving" @click="setAccountStatus(staff)">{{ staff.user.account_status === 'suspended' ? 'Activate account' : 'Suspend account' }}</button></div>
            </article>
          </div>
        </section>
      </section>

      <section v-if="canManageRoles && activeTab === 'roles' && !accessLoading && !accessError" class="roles-workspace">
        <div class="roles-intro"><div><p class="settings-eyebrow">Access control</p><h2>Roles & permissions</h2><p>Choose which parts of the system each role can access. Changes apply to staff accounts using that role.</p></div><span class="role-total">{{ access.roles.length }} system roles</span></div>
        <div class="roles-grid">
          <article v-for="role in access.roles" :key="role.id" class="role-card">
            <header class="role-card-heading"><span class="role-avatar" :class="`role-${role.key}`">{{ role.name.slice(0, 1) }}</span><div><h3>{{ role.name }}</h3><p>{{ role.description }}</p></div><span v-if="role.key === 'admin'" class="protected-role">Protected</span></header>
            <div class="role-permission-groups">
              <section v-for="group in permissionGroups" :key="group" class="permission-group"><h4>{{ group }}</h4><label v-for="permission in access.permissions.filter((item) => item.group === group)" :key="permission.id" class="permission-row"><span><strong>{{ permission.name }}</strong></span><input type="checkbox" :aria-label="`${permission.name} for ${role.name}`" :checked="roleHasPermission(role, permission)" :disabled="role.key === 'admin'" @change="toggleRolePermission(role, permission, $event.target.checked)" /></label></section>
            </div>
            <footer class="role-card-footer"><span>{{ (rolePermissionDrafts[role.id] || []).length }} permissions</span><button type="button" class="settings-button settings-button--primary" :disabled="accessSaving || role.key === 'admin' || !rolePermissionsChanged(role)" @click="saveRolePermissions(role)">{{ role.key === 'admin' ? 'All permissions' : 'Save permissions' }}</button></footer>
          </article>
        </div>
      </section>

      <section v-if="canManageWorkflows && activeTab === 'workflows' && !accessLoading && !accessError" class="workflow-workspace">
        <form class="settings-panel workflow-editor" @submit.prevent="saveWorkflow">
          <header class="panel-heading"><span class="panel-icon panel-icon--blue" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="5" r="2"/><circle cx="19" cy="12" r="2"/><circle cx="5" cy="19" r="2"/><path d="M7 5h4a3 3 0 0 1 3 3v1a3 3 0 0 0 3 3m-10 7h4a3 3 0 0 0 3-3v-1"/></svg></span><div><h2>{{ editingWorkflowId ? 'Edit workflow' : 'Build an approval workflow' }}</h2><p>Arrange review stages, assign a role, and define how each stage is approved.</p></div></header>
          <div class="workflow-fields">
            <label class="settings-field"><span class="field-label">Workflow name <span class="required-mark">Required</span></span><input v-model.trim="workflowForm.name" required maxlength="150" placeholder="e.g. Event publication approval" /></label>
            <label class="settings-field"><span class="field-label">Description</span><textarea v-model="workflowForm.description" rows="2" maxlength="2000" placeholder="What does this workflow approve?" /></label>
            <div class="workflow-options"><label class="settings-field"><span class="field-label">Stage sequence</span><select v-model="workflowForm.approval_rule"><option value="sequential">One stage after another</option><option value="parallel">All stages run in parallel</option></select></label><label class="workflow-active"><input v-model="workflowForm.is_active" type="checkbox" /> <span><strong>Workflow active</strong><small>Available for new submissions</small></span></label></div>
          </div>
          <div class="stages-heading"><div><h3>Approval stages</h3><p>Each stage is assigned to one role.</p></div><button type="button" class="settings-button settings-button--quiet" :disabled="workflowForm.stages.length >= 20" @click="workflowForm.stages.push(newWorkflowStage())">+ Add stage</button></div>
          <div class="workflow-stages">
            <article v-for="(stage, index) in workflowForm.stages" :key="index" class="workflow-stage">
              <span class="stage-number">{{ String(index + 1).padStart(2, '0') }}</span>
              <div class="stage-fields"><label class="settings-field"><span class="field-label">Stage name</span><input v-model.trim="stage.name" required maxlength="120" :placeholder="`Stage ${index + 1}`" /></label><label class="settings-field"><span class="field-label">Required role</span><select v-model="stage.required_role_id" required><option v-for="role in access.roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label><label class="settings-field stage-approval-count"><span class="field-label">Approvals needed</span><input v-model.number="stage.required_approvals" type="number" min="1" max="99" required /></label><label class="settings-field"><span class="field-label">Approval rule</span><select v-model="stage.approval_rule"><option value="any">Any assigned reviewer</option><option value="all">All assigned reviewers</option></select></label></div>
              <div class="stage-controls"><button type="button" :disabled="index === 0" :aria-label="`Move stage ${index + 1} up`" @click="moveWorkflowStage(index, -1)">↑</button><button type="button" :disabled="index === workflowForm.stages.length - 1" :aria-label="`Move stage ${index + 1} down`" @click="moveWorkflowStage(index, 1)">↓</button><button type="button" class="stage-remove" :disabled="workflowForm.stages.length === 1" :aria-label="`Remove stage ${index + 1}`" @click="workflowForm.stages.splice(index, 1)">×</button></div>
            </article>
          </div>
          <footer class="workflow-form-footer"><button v-if="editingWorkflowId" type="button" class="settings-button settings-button--quiet" @click="resetWorkflowForm">Cancel edit</button><button type="submit" class="settings-button settings-button--primary" :disabled="accessSaving"><span v-if="accessSaving" class="button-spinner" aria-hidden="true"></span>{{ editingWorkflowId ? 'Save workflow' : 'Create workflow' }}</button></footer>
        </form>
        <section class="settings-panel workflow-list-panel"><header class="panel-heading"><span class="panel-icon panel-icon--violet" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="2" cy="5" r="1"/><circle cx="2" cy="12" r="1"/><circle cx="2" cy="19" r="1"/></svg></span><div><h2>Saved workflows <span class="panel-count">{{ access.workflows.length }}</span></h2><p>Review and edit approval paths.</p></div></header>
          <div v-if="!access.workflows.length" class="empty-state"><span>↗</span><strong>No workflows created</strong><p>Build an approval path to get started.</p></div>
          <article v-for="workflow in access.workflows" :key="workflow.id" class="saved-workflow"><div class="saved-workflow-head"><div><strong>{{ workflow.name }}</strong><p>{{ workflow.description || 'No description' }}</p></div><span class="workflow-status" :class="workflow.is_active ? 'is-active' : 'is-paused'">{{ workflow.is_active ? 'Active' : 'Paused' }}</span></div><div class="workflow-path"><span v-for="(stage, index) in workflow.stages" :key="stage.id" class="workflow-path-stage"><i>{{ index + 1 }}</i><span><strong>{{ stage.name }}</strong><small>{{ stage.required_role?.name }} · {{ stage.required_approvals }} approval{{ stage.required_approvals === 1 ? '' : 's' }}</small></span><b v-if="index < workflow.stages.length - 1">{{ workflow.approval_rule === 'parallel' ? '+' : '›' }}</b></span></div><footer><span>{{ workflow.approval_rule === 'parallel' ? 'Parallel stages' : 'Sequential stages' }}</span><span><button type="button" class="icon-action" @click="editWorkflow(workflow)">Edit</button><button type="button" class="icon-action action-suspend" :disabled="accessSaving" @click="deleteWorkflow(workflow)">Delete</button></span></footer></article>
        </section>
      </section>
    </div>
  </AdminLayout>
</template>

<style scoped>
.settings-page { width: min(100% - 48px, 1160px); margin: 0 auto; padding: 38px 0 70px; color: #193653; }
.settings-page-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 28px; }
.settings-header-status { display: grid; justify-items: end; gap: 8px; }.unsaved-status { color: #a16f18; font-size: .72rem; font-weight: 750; }
.settings-section-intro { display: flex; align-items: flex-start; gap: 13px; margin: -4px 0 18px; padding: 17px 19px; border: 1px solid #dfe9f3; border-radius: 13px; background: linear-gradient(110deg,#fff 0%,#f8fbff 100%); }.section-intro-icon { display: grid; place-items: center; flex: 0 0 34px; width: 34px; height: 34px; border-radius: 10px; background: #eaf2ff; color: #3976d0; font-size: .65rem; font-weight: 800; }.settings-section-intro h2 { margin: 1px 0 4px; color: #2e4b68; font-size: .86rem; }.settings-section-intro p { max-width: 780px; margin: 0; color: #7d8fa2; font-size: .74rem; line-height: 1.55; }
.settings-eyebrow { margin: 0 0 9px; color: #3477dd; font-size: .7rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
.settings-eyebrow span { padding: 0 5px; color: #a6b7c9; }
.settings-page-header h1 { margin: 0; color: #173b67; font-size: clamp(2rem, 4vw, 2.8rem); font-weight: 650; letter-spacing: -.045em; line-height: 1.06; }
.settings-page-lede { margin: 10px 0 0; color: #73869b; font-size: .98rem; line-height: 1.5; }
.system-status-pill { display: inline-flex; align-items: center; gap: 9px; flex: 0 0 auto; padding: 9px 13px; border: 1px solid #dbe8e0; border-radius: 999px; background: #f5fbf7; color: #32724f; font-size: .75rem; font-weight: 700; }
.system-status-pill.is-maintenance { border-color: #f1dfb8; background: #fff9ed; color: #946312; }
.status-indicator,.preview-live i { width: 8px; height: 8px; border-radius: 50%; background: #35a66a; box-shadow: 0 0 0 3px rgba(53,166,106,.12); }
.is-maintenance .status-indicator { background: #dc9b21; box-shadow: 0 0 0 3px rgba(220,155,33,.14); }
.settings-layout { display: grid; grid-template-columns: minmax(0,1fr) 270px; align-items: start; gap: 20px; }
.settings-content { display: grid; gap: 16px; min-width: 0; }
.settings-panel,.preview-card,.help-card { border: 1px solid #e0e8f0; border-radius: 16px; background: #fff; box-shadow: 0 8px 24px rgba(31,60,91,.045); }
.settings-panel { padding: 22px 24px 24px; }
.panel-heading { display: flex; align-items: flex-start; gap: 13px; padding-bottom: 19px; border-bottom: 1px solid #edf1f5; }
.panel-icon { display: grid; place-items: center; flex: 0 0 40px; width: 40px; height: 40px; border-radius: 12px; }
.panel-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.panel-icon--blue { background: #edf4ff; color: #3478dc; }.panel-icon--violet { background: #f4f0ff; color: #7961cc; }.panel-icon--amber { background: #fff6e5; color: #c58a1b; }
.panel-heading h2 { margin: 1px 0 4px; color: #1d3c5c; font-size: 1rem; font-weight: 750; letter-spacing: -.01em; }
.panel-heading p { margin: 0; color: #8292a4; font-size: .8rem; line-height: 1.5; }
.panel-body { padding-top: 20px; }
.settings-field { display: grid; gap: 8px; max-width: 620px; }
.field-label { display: flex; align-items: center; gap: 9px; color: #38536f; font-size: .78rem; font-weight: 750; }
.required-mark,.field-optional { color: #8999aa; font-size: .65rem; font-weight: 600; }
.required-mark { color: #c65e61; }
.settings-field input,.settings-field textarea { width: 100%; border: 1px solid #d6e1eb; border-radius: 10px; background: #fbfdff; color: #243e59; font: inherit; font-size: .85rem; transition: border-color .18s,box-shadow .18s,background .18s; }
.settings-field input { min-height: 46px; padding: 0 13px; }.settings-field textarea { min-height: 116px; padding: 12px 13px; line-height: 1.55; resize: vertical; }
.settings-field input::placeholder,.settings-field textarea::placeholder { color: #a4b0bd; }
.settings-field input:focus,.settings-field textarea:focus { outline: 0; border-color: #5c96ed; background: #fff; box-shadow: 0 0 0 3px rgba(63,130,224,.13); }
.field-help { color: #8998a8; font-size: .7rem; line-height: 1.45; }.field-help--split { display: flex; justify-content: space-between; gap: 12px; }.field-help--split span:last-child { white-space: nowrap; }.field-help .is-near-limit { color: #bc6b24; font-weight: 700; }
.notice-preview { display: grid; gap: 8px; margin-top: 20px; padding-top: 17px; border-top: 1px solid #edf1f5; }.preview-label { color: #8797a8; font-size: .63rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.preview-message { display: flex; align-items: flex-start; gap: 10px; padding: 12px 13px; border: 1px solid #d7e6f5; border-radius: 10px; background: #f4f9ff; }.preview-message > span { display: grid; place-items: center; flex: 0 0 19px; height: 19px; border-radius: 50%; background: #dcecff; color: #3478dc; font-size: .72rem; font-weight: 800; }.preview-message p { margin: 0; color: #496681; font-size: .77rem; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.maintenance-control { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 18px; padding: 15px 16px; border: 1px solid #e5ebf1; border-radius: 12px; background: #fcfdff; cursor: pointer; }.maintenance-copy { display: grid; gap: 4px; }.maintenance-copy strong { color: #314d68; font-size: .82rem; }.maintenance-copy > span { max-width: 620px; color: #8796a6; font-size: .72rem; line-height: 1.5; }
.switch-wrap { position: relative; display: inline-flex; flex: 0 0 44px; width: 44px; height: 25px; }.switch-wrap input { position: absolute; z-index: 1; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }.switch-ui { width: 44px; height: 25px; border-radius: 99px; background: #cbd5df; transition: background .2s; }.switch-ui::after { position: absolute; top: 3px; left: 3px; width: 19px; height: 19px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(22,40,58,.2); content: ''; transition: transform .2s; }.switch-wrap input:checked + .switch-ui { background: #d69a2c; }.switch-wrap input:checked + .switch-ui::after { transform: translateX(19px); }.switch-wrap input:focus-visible + .switch-ui { outline: 3px solid rgba(63,130,224,.22); outline-offset: 2px; }
.maintenance-unsaved { margin: 9px 0 0; color: #a87820; font-size: .7rem; }
.settings-aside { position: sticky; top: 24px; display: grid; gap: 14px; }.preview-card { padding: 17px; }.preview-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }.preview-live { display: inline-flex; align-items: center; gap: 6px; color: #7b8e9f; font-size: .65rem; font-weight: 650; }.preview-live i { width: 6px; height: 6px; box-shadow: none; }
.identity-preview { display: flex; align-items: center; gap: 11px; margin: 19px 0 17px; }.identity-avatar { display: grid; place-items: center; flex: 0 0 42px; width: 42px; height: 42px; border-radius: 13px; background: linear-gradient(145deg,#397ee6,#2560bd); color: #fff; font-size: .78rem; font-weight: 800; letter-spacing: .04em; }.identity-preview > div { display: grid; gap: 4px; min-width: 0; }.identity-preview strong { overflow: hidden; color: #294763; font-size: .78rem; text-overflow: ellipsis; white-space: nowrap; }.identity-preview > div > span { color: #95a2b0; font-size: .66rem; }.preview-divider { height: 1px; background: #edf1f5; }.summary-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 13px; color: #8292a2; font-size: .68rem; }.summary-row strong { color: #49627b; font-size: .68rem; }.summary-row .summary-live { color: #34845a; }.summary-row .summary-maintenance { color: #b47a17; }
.help-card { padding: 17px; background: #f8fbff; }.help-icon { display: grid; place-items: center; width: 25px; height: 25px; border: 1px solid #d5e3f2; border-radius: 50%; color: #5483bb; font-size: .75rem; font-weight: 800; }.help-card h3 { margin: 12px 0 5px; color: #36536f; font-size: .78rem; }.help-card p { margin: 0; color: #8596a7; font-size: .7rem; line-height: 1.55; }
.settings-actions { position: sticky; bottom: 12px; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 2px; padding: 12px; border: 1px solid #dce6f0; border-radius: 14px; background: rgba(255,255,255,.96); box-shadow: 0 9px 24px rgba(28,58,91,.1); backdrop-filter: blur(12px); }.settings-actions-secondary { display: flex; flex-wrap: wrap; gap: 7px; }
.settings-button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 13px; border: 1px solid transparent; border-radius: 9px; font: inherit; font-size: .75rem; font-weight: 720; cursor: pointer; transition: background .16s,border-color .16s,color .16s,transform .16s; }.settings-button:active:not(:disabled) { transform: translateY(1px); }.settings-button:focus-visible,.text-button:focus-visible { outline: 3px solid rgba(63,130,224,.22); outline-offset: 2px; }.settings-button:disabled { opacity: .48; cursor: not-allowed; }.settings-button--quiet { border-color: #dce5ed; background: #fff; color: #60758b; }.settings-button--quiet:hover:not(:disabled) { background: #f5f8fb; }.settings-button--reset { background: transparent; color: #9a6870; }.settings-button--reset:hover:not(:disabled) { background: #fff4f4; color: #a54855; }.settings-button--primary { min-width: 140px; background: #286ed0; color: #fff; box-shadow: 0 3px 8px rgba(40,110,208,.2); }.settings-button--primary:hover:not(:disabled) { background: #1e5fb9; }.settings-button svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }.button-spinner,.loading-spinner { width: 16px; height: 16px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin .7s linear infinite; }.settings-loading { display: flex; align-items: center; gap: 10px; width: min(100%,800px); margin: 0 auto; padding: 18px; border: 1px solid #e2eaf2; border-radius: 12px; background: #fff; color: #71849a; font-size: .8rem; }.loading-spinner { color: #397ee0; }.settings-feedback { display: flex; align-items: center; gap: 11px; width: min(100%,900px); margin: 0 auto 16px; padding: 12px 14px; border: 1px solid; border-radius: 11px; }.settings-feedback p { flex: 1; margin: 0; font-size: .78rem; line-height: 1.5; }.settings-feedback--error { border-color: #f1cece; background: #fff6f5; color: #ac4949; }.settings-feedback--success { border-color: #cbe8d6; background: #f4fbf6; color: #35744f; }.feedback-icon { display: grid; place-items: center; flex: 0 0 22px; width: 22px; height: 22px; border: 1px solid currentColor; border-radius: 50%; font-size: .72rem; font-weight: 800; }.text-button { padding: 6px 8px; border: 0; border-radius: 6px; background: transparent; color: inherit; font: inherit; font-size: .73rem; font-weight: 750; cursor: pointer; }.text-button:hover:not(:disabled) { background: rgba(0,0,0,.04); }.text-button:disabled { opacity: .5; cursor: wait; }
.settings-tabs { display: flex; gap: 5px; margin: 0 0 20px; padding: 5px; overflow-x: auto; border: 1px solid #e1e9f1; border-radius: 12px; background: #f5f8fb; }
.settings-tabs button { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 38px; padding: 0 14px; border: 0; border-radius: 8px; background: transparent; color: #72859a; font: inherit; font-size: .75rem; font-weight: 720; white-space: nowrap; cursor: pointer; }
.settings-tabs button.is-active { background: #fff; color: #2869c5; box-shadow: 0 2px 6px rgba(35,62,91,.1); }
.settings-tabs button span,.panel-count { display: inline-grid; place-items: center; min-width: 19px; height: 19px; padding: 0 5px; border-radius: 99px; background: #edf2f7; color: #71849a; font-size: .64rem; }
.settings-admin-grid { display: grid; grid-template-columns: minmax(290px,.75fr) minmax(0,1.25fr); align-items: start; gap: 16px; }
.admin-form-panel,.staff-list-panel,.workflow-editor,.workflow-list-panel { padding: 21px; }
.admin-form-fields,.workflow-fields { display: grid; gap: 14px; padding-top: 19px; }
.settings-field select { width: 100%; min-height: 43px; padding: 0 12px; border: 1px solid #d6e1eb; border-radius: 9px; background: #fbfdff; color: #38536f; font: inherit; font-size: .8rem; }
.admin-form-actions,.workflow-form-footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #edf1f5; }
.panel-count { margin-left: 4px; vertical-align: 1px; }
.staff-list { display: grid; }.staff-row { display: flex; align-items: flex-start; gap: 11px; padding: 15px 0; border-bottom: 1px solid #edf1f5; }.staff-row:last-child { border-bottom: 0; }
.staff-avatar,.role-avatar { display: grid; place-items: center; flex: 0 0 38px; width: 38px; height: 38px; border-radius: 12px; background: #edf4ff; color: #3976c6; font-size: .72rem; font-weight: 800; }
.staff-main { display: grid; flex: 1; gap: 4px; min-width: 0; }.staff-name-line { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; }.staff-name-line strong { color: #34516d; font-size: .78rem; }.staff-meta { overflow: hidden; color: #8a99a8; font-size: .66rem; text-overflow: ellipsis; white-space: nowrap; }
.staff-status,.workflow-status { padding: 3px 7px; border-radius: 99px; background: #eff8f2; color: #43835d; font-size: .58rem; font-weight: 750; text-transform: capitalize; }.staff-status.status-on_leave { background: #fff7e8; color: #a57926; }.staff-status.status-inactive,.workflow-status.is-paused { background: #f3f5f7; color: #8492a0; }
.staff-actions { display: flex; align-items: flex-end; flex-direction: column; gap: 5px; }.icon-action { padding: 4px 6px; border: 0; border-radius: 5px; background: transparent; color: #4d7bb4; font: inherit; font-size: .64rem; font-weight: 700; cursor: pointer; white-space: nowrap; }.icon-action:hover { background: #f1f6fc; }.icon-action:disabled { opacity: .45; cursor: not-allowed; }.action-suspend { color: #a95d60; }.action-suspend:hover { background: #fff4f3; }.action-activate { color: #3c855a; }.action-activate:hover { background: #f0faf3; }
.empty-state { display: grid; justify-items: center; gap: 5px; padding: 32px 14px; color: #8393a3; text-align: center; }.empty-state > span { display: grid; place-items: center; width: 38px; height: 38px; margin-bottom: 4px; border-radius: 12px; background: #f1f6fc; color: #5382ba; }.empty-state strong { color: #536c85; font-size: .8rem; }.empty-state p { margin: 0; font-size: .7rem; }
.roles-workspace { display: grid; gap: 18px; }.roles-intro { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; }.roles-intro .settings-eyebrow { margin-bottom: 5px; }.roles-intro h2 { margin: 0; color: #1d3c5c; font-size: 1.25rem; letter-spacing: -.025em; }.roles-intro p:last-child { max-width: 650px; margin: 6px 0 0; color: #8292a3; font-size: .77rem; line-height: 1.5; }.role-total { padding: 7px 10px; border: 1px solid #dce6f0; border-radius: 99px; color: #75889c; font-size: .67rem; font-weight: 700; white-space: nowrap; }
.roles-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 14px; }.role-card { min-width: 0; overflow: hidden; border: 1px solid #e0e8f0; border-radius: 15px; background: #fff; box-shadow: 0 7px 20px rgba(31,60,91,.04); }.role-card-heading { display: flex; align-items: flex-start; gap: 10px; padding: 16px; border-bottom: 1px solid #edf1f5; }.role-avatar { flex-basis: 34px; width: 34px; height: 34px; border-radius: 10px; }.role-avatar.role-checker { background: #edf8f7; color: #39887d; }.role-avatar.role-reviewer { background: #f3efff; color: #7965bb; }.role-avatar.role-verifier { background: #fff5e8; color: #bd7b29; }.role-avatar.role-approver { background: #edf3fc; color: #4e6ea1; }.role-avatar.role-admin { background: #e9eef8; color: #263f6b; }
.role-card-heading > div { flex: 1; min-width: 0; }.role-card-heading h3 { margin: 0; color: #31506e; font-size: .83rem; }.role-card-heading p { margin: 4px 0 0; color: #8998a8; font-size: .67rem; line-height: 1.4; }.protected-role { padding: 4px 7px; border-radius: 99px; background: #f1f4f8; color: #74869a; font-size: .58rem; font-weight: 700; }
.role-permission-groups { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 14px; padding: 15px 16px; }.permission-group h4 { margin: 0 0 6px; color: #8494a5; font-size: .61rem; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }.permission-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 38px; padding: 5px 0; border-bottom: 1px solid #f0f3f6; cursor: pointer; }.permission-row:last-child { border-bottom: 0; }.permission-row > span { display: grid; gap: 2px; min-width: 0; }.permission-row strong { color: #4d657e; font-size: .66rem; font-weight: 650; }.permission-row small { overflow: hidden; color: #a1adba; font-size: .58rem; text-overflow: ellipsis; }.permission-row input,.workflow-active input { width: 15px; height: 15px; flex: 0 0 auto; accent-color: #3478dc; }.permission-row input:disabled { opacity: .6; }.role-card-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 11px 15px; border-top: 1px solid #edf1f5; background: #fbfcfe; }.role-card-footer > span { color: #8a99a7; font-size: .64rem; }
.workflow-fields { grid-template-columns: repeat(2,minmax(0,1fr)); }.workflow-fields > label:nth-child(2),.workflow-options { grid-column: 1/-1; }.workflow-options { display: grid; grid-template-columns: 1fr 1fr; align-items: end; gap: 14px; }.workflow-active { display: flex; align-items: center; gap: 8px; min-height: 43px; padding: 8px 11px; border: 1px solid #e4ebf1; border-radius: 9px; background: #fbfdff; cursor: pointer; }.workflow-active > span { display: grid; gap: 3px; }.workflow-active strong { color: #526a82; font-size: .7rem; }.workflow-active small { color: #909eac; font-size: .62rem; }
.stages-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 22px; padding-bottom: 11px; border-bottom: 1px solid #edf1f5; }.stages-heading h3 { margin: 0; color: #37536f; font-size: .83rem; }.stages-heading p { margin: 4px 0 0; color: #8b99a8; font-size: .66rem; }.workflow-stages { display: grid; gap: 9px; margin-top: 12px; }.workflow-stage { display: grid; grid-template-columns: 34px minmax(0,1fr) auto; align-items: start; gap: 10px; padding: 12px; border: 1px solid #e6edf3; border-radius: 11px; background: #fcfdff; }.stage-number { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; background: #eaf2ff; color: #3877d0; font-size: .66rem; font-weight: 800; }.stage-fields { display: grid; grid-template-columns: 1.15fr 1fr .6fr 1fr; gap: 9px; }.stage-fields .field-label { min-height: 18px; font-size: .63rem; }.stage-fields input,.stage-fields select { min-height: 36px; padding: 0 8px; font-size: .69rem; }.stage-controls { display: grid; grid-template-columns: repeat(3,25px); gap: 3px; }.stage-controls button { width: 25px; height: 25px; border: 1px solid #e2e9ef; border-radius: 6px; background: #fff; color: #70849a; cursor: pointer; }.stage-controls button:disabled { opacity: .35; cursor: not-allowed; }.stage-controls .stage-remove { color: #b26365; }.workflow-form-footer { margin-top: 15px; }
.workflow-list-panel { display: grid; gap: 12px; }.saved-workflow { overflow: hidden; border: 1px solid #e4ebf2; border-radius: 12px; }.saved-workflow-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 13px 14px; }.saved-workflow-head > div { min-width: 0; }.saved-workflow-head strong { color: #36536f; font-size: .78rem; }.saved-workflow-head p { margin: 4px 0 0; color: #8a99a8; font-size: .66rem; }.workflow-status.is-active { background: #eff8f2; color: #43835d; }.workflow-path { display: flex; align-items: center; gap: 7px; overflow-x: auto; padding: 12px 14px; border-top: 1px solid #eff2f5; border-bottom: 1px solid #eff2f5; background: #fbfdff; }.workflow-path-stage { display: inline-flex; align-items: center; gap: 6px; flex: 0 0 auto; }.workflow-path-stage > i { display: grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; background: #e9f1fc; color: #4079be; font-size: .58rem; font-style: normal; font-weight: 800; }.workflow-path-stage > span { display: grid; gap: 2px; }.workflow-path-stage strong { color: #536b84; font-size: .61rem; }.workflow-path-stage small { color: #98a5b1; font-size: .56rem; }.workflow-path-stage > b { padding-left: 3px; color: #b1bdc8; font-size: .9rem; }.saved-workflow footer { display: flex; align-items: center; justify-content: space-between; gap: 9px; padding: 7px 11px; color: #91a0ae; font-size: .62rem; }.saved-workflow footer > span:last-child { display: flex; gap: 7px; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 880px) { .settings-layout { grid-template-columns: minmax(0,1fr) 230px; gap: 14px; }.settings-panel { padding: 19px; }.preview-card,.help-card { padding: 14px; } }
@media (max-width: 980px) { .workflow-stage { grid-template-columns: 30px minmax(0,1fr); }.stage-controls { grid-column: 2; grid-row: 1; justify-self: end; }.stage-fields { grid-column: 1/-1; grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 700px) { .settings-page { width: min(100% - 32px,600px); padding: 26px 0 42px; }.settings-page-header { align-items: flex-start; flex-direction: column; gap: 14px; margin-bottom: 20px; }.settings-header-status { justify-items: start; order: -1; }.settings-layout { grid-template-columns: 1fr; }.settings-aside { position: static; grid-template-columns: repeat(2,minmax(0,1fr)); }.settings-actions { position: static; flex-direction: column-reverse; align-items: stretch; }.settings-actions-secondary { display: grid; grid-template-columns: 1fr 1fr; }.settings-button--primary { width: 100%; }.settings-admin-grid,.roles-grid { grid-template-columns: 1fr; }.workflow-options { grid-template-columns: 1fr; }.workflow-stage { grid-template-columns: 28px minmax(0,1fr); }.stage-fields { grid-template-columns: repeat(2,minmax(0,1fr)); }.roles-intro { align-items: flex-start; flex-direction: column; gap: 10px; } }
@media (max-width: 460px) { .settings-aside { grid-template-columns: 1fr; }.settings-panel { padding: 17px 15px; }.panel-heading { gap: 10px; }.panel-icon { flex-basis: 36px; width: 36px; height: 36px; border-radius: 10px; }.settings-actions-secondary { grid-template-columns: 1fr; }.maintenance-control { padding: 13px; } }
@media (prefers-reduced-motion: reduce) { *,*::before,*::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; } }
</style>
