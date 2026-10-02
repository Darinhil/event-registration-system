<script setup>
import { onMounted, reactive, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import api from '../../services/api'

const accounts = ref([])
const loading = ref(true)
const error = ref('')
const success = ref('')
const creating = ref(false)
const showPassword = ref(false)
const form = reactive({ name: '', email: '', password: '', role: 'event_admin' })

const roleLabel = (role) => (role === 'admin' ? 'Admin' : 'Event Admin')
const initials = (name = '') =>
  name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() || '')
    .join('') || '?'

let successTimer
const flashSuccess = (message) => {
  success.value = message
  clearTimeout(successTimer)
  successTimer = setTimeout(() => (success.value = ''), 4000)
}

const load = async () => {
  try {
    accounts.value = (await api.get('/admin/admin-accounts')).data.data || []
  } catch (e) {
    error.value = e.response?.data?.message || 'Unable to load admin accounts.'
  } finally {
    loading.value = false
  }
}

const create = async () => {
  error.value = ''
  creating.value = true
  try {
    const { data } = await api.post('/admin/admin-accounts', form)
    accounts.value.unshift(data.data)
    Object.assign(form, { name: '', email: '', password: '', role: 'event_admin' })
    showPassword.value = false
    flashSuccess(`Account for ${data.data.name} created successfully.`)
  } catch (e) {
    error.value =
      Object.values(e.response?.data?.errors || {}).flat()[0] ||
      e.response?.data?.message ||
      'Unable to create account.'
  } finally {
    creating.value = false
  }
}

const remove = async (account) => {
  if (!window.confirm(`Delete ${account.name}'s account?`)) return
  try {
    await api.delete(`/admin/admin-accounts/${account.id}`)
    accounts.value = accounts.value.filter((item) => item.id !== account.id)
    flashSuccess(`${account.name}'s account was removed.`)
  } catch (e) {
    error.value = e.response?.data?.message || 'Unable to delete account.'
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <section class="event-dashboard-page aa-page">
      <header class="dashboard-page-heading aa-heading">
        <div>
          <p class="admin-eyebrow">Administration</p>
          <h1>Admin Accounts</h1>
          <p>Create and manage Admin and Event Admin access.</p>
        </div>
        <span v-if="!loading" class="aa-count-chip">
          {{ accounts.length }} {{ accounts.length === 1 ? 'account' : 'accounts' }}
        </span>
      </header>

      <transition name="aa-fade">
        <p v-if="error" class="aa-alert aa-alert--error" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          {{ error }}
        </p>
      </transition>
      <transition name="aa-fade">
        <p v-if="success" class="aa-alert aa-alert--success" role="status">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
          </svg>
          {{ success }}
        </p>
      </transition>

      <div class="aa-grid">
        <article class="aa-card aa-card--form">
          <header class="aa-card-head">
            <span class="aa-card-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><line x1="19" y1="8" x2="19" y2="14" /><line x1="22" y1="11" x2="16" y2="11" />
              </svg>
            </span>
            <div>
              <h2>New account</h2>
              <p>Add a teammate to help run events.</p>
            </div>
          </header>

          <form class="aa-form" @submit.prevent="create">
            <div class="aa-field">
              <label for="aa-name">Full name</label>
              <input id="aa-name" v-model="form.name" type="text" required autocomplete="name" placeholder="e.g. Alex Morgan" />
            </div>

            <div class="aa-field">
              <label for="aa-email">Email</label>
              <input id="aa-email" v-model="form.email" type="email" required autocomplete="off" placeholder="name@company.com" />
            </div>

            <div class="aa-field">
              <label for="aa-password">Password</label>
              <div class="aa-password">
                <input
                  id="aa-password"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  minlength="8"
                  required
                  autocomplete="new-password"
                  placeholder="••••••••"
                />
                <button type="button" class="aa-password-toggle" @click="showPassword = !showPassword">
                  {{ showPassword ? 'Hide' : 'Show' }}
                </button>
              </div>
              <small>Minimum 8 characters.</small>
            </div>

            <div class="aa-field">
              <label for="aa-role">Role</label>
              <div class="aa-select-wrap">
                <select id="aa-role" v-model="form.role">
                  <option value="event_admin">Event Admin</option>
                  <option value="admin">Admin</option>
                </select>
                <svg class="aa-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polyline points="6 9 12 15 18 9" />
                </svg>
              </div>
              <small>Admins manage everything · Event Admins manage assigned events.</small>
            </div>

            <button class="aa-submit" type="submit" :disabled="creating">
              <span v-if="creating" class="aa-spinner" aria-hidden="true"></span>
              {{ creating ? 'Creating…' : 'Create account' }}
            </button>
          </form>
        </article>

        <article class="aa-card aa-card--list">
          <header class="aa-card-head">
            <span class="aa-card-icon aa-card-icon--teal" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
              </svg>
            </span>
            <div>
              <h2>Team members</h2>
              <p>Everyone with admin access.</p>
            </div>
          </header>

          <div v-if="loading" class="aa-skeletons" aria-busy="true" aria-label="Loading accounts">
            <div v-for="n in 4" :key="n" class="aa-skeleton-row">
              <span class="aa-skeleton aa-skeleton--avatar"></span>
              <div class="aa-skeleton-lines">
                <span class="aa-skeleton aa-skeleton--line aa-skeleton--w60"></span>
                <span class="aa-skeleton aa-skeleton--line aa-skeleton--w40"></span>
              </div>
              <span class="aa-skeleton aa-skeleton--pill"></span>
            </div>
          </div>

          <div v-else-if="accounts.length === 0" class="aa-empty">
            <span class="aa-empty-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><line x1="19" y1="8" x2="19" y2="14" /><line x1="22" y1="11" x2="16" y2="11" />
              </svg>
            </span>
            <strong>No accounts yet</strong>
            <p>Create the first admin account using the form.</p>
          </div>

          <div v-else class="aa-table-wrap">
            <table class="aa-table">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>Role</th>
                  <th class="aa-th-actions"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="account in accounts" :key="account.id">
                  <td>
                    <div class="aa-member">
                      <span class="aa-avatar" :class="account.role === 'admin' ? 'aa-avatar--admin' : 'aa-avatar--event'" aria-hidden="true">
                        {{ initials(account.name) }}
                      </span>
                      <div class="aa-member-meta">
                        <strong>{{ account.name }}</strong>
                        <span>{{ account.email }}</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="aa-role-pill" :class="account.role === 'admin' ? 'aa-role-pill--admin' : 'aa-role-pill--event'">
                      <span class="aa-role-dot" aria-hidden="true"></span>
                      {{ roleLabel(account.role) }}
                    </span>
                  </td>
                  <td class="aa-actions">
                    <button class="aa-delete" type="button" :title="`Delete ${account.name}'s account`" @click="remove(account)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><line x1="10" y1="11" x2="10" y2="17" /><line x1="14" y1="11" x2="14" y2="17" />
                      </svg>
                      <span>Delete</span>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </div>
    </section>
  </AdminLayout>
</template>

<style scoped>
.aa-page {
  --aa-radius: 16px;
  padding-bottom: 24px;
}

.aa-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
}

.aa-count-chip {
  margin-bottom: 4px;
  padding: 7px 14px;
  border: 1px solid var(--ui-border);
  border-radius: 999px;
  background: #fff;
  color: var(--ui-muted);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  white-space: nowrap;
}

/* Alerts */
.aa-alert {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 18px;
  padding: 12px 16px;
  border-radius: 12px;
  font-size: 0.82rem;
  font-weight: 600;
}
.aa-alert svg {
  flex: 0 0 auto;
  width: 17px;
  height: 17px;
}
.aa-alert--error {
  border: 1px solid #ffd3d8;
  background: #fff5f6;
  color: #b23a4c;
}
.aa-alert--success {
  border: 1px solid #bdebd4;
  background: #f2fcf7;
  color: #1e7a52;
}
.aa-fade-enter-active,
.aa-fade-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.aa-fade-enter-from,
.aa-fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

/* Layout */
.aa-grid {
  display: grid;
  grid-template-columns: minmax(320px, 400px) minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}
@media (max-width: 960px) {
  .aa-grid {
    grid-template-columns: 1fr;
  }
}

/* Cards */
.aa-card {
  overflow: hidden;
  border: 1px solid var(--ui-border);
  border-radius: var(--aa-radius);
  background: #fff;
  box-shadow: var(--ui-shadow);
}
.aa-card-head {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 20px 24px;
  border-bottom: 1px solid #edf2f8;
  background: linear-gradient(180deg, #fbfdff, #f7fafd);
}
.aa-card-head h2 {
  margin: 0;
  color: var(--ui-ink);
  font-size: 0.95rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.aa-card-head p {
  margin: 3px 0 0;
  color: var(--ui-subtle);
  font-size: 0.72rem;
}
.aa-card-icon {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: #eaf2ff;
  color: var(--ui-blue);
}
.aa-card-icon--teal {
  background: #e6f7f1;
  color: #149a6e;
}
.aa-card-icon svg {
  width: 19px;
  height: 19px;
}

/* Form */
.aa-form {
  display: grid;
  gap: 16px;
  padding: 24px;
}
.aa-field {
  display: grid;
  gap: 7px;
}
.aa-field label {
  color: #435f7d;
  font-size: 0.76rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.aa-field input {
  width: 100%;
  box-sizing: border-box;
  min-height: 46px;
  padding: 0 14px;
  border: 1px solid #d8e4f0;
  border-radius: 10px;
  background: #fbfdff;
  color: var(--ui-ink);
  font: inherit;
  font-size: 0.85rem;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.aa-field input::placeholder {
  color: #a9bacd;
}
.aa-field input:focus {
  outline: none;
  border-color: #6ea3f2;
  background: #fff;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}
.aa-field small {
  color: var(--ui-subtle);
  font-size: 0.7rem;
}

.aa-password {
  position: relative;
}
.aa-password input {
  padding-right: 62px;
}
.aa-password-toggle {
  position: absolute;
  top: 50%;
  right: 8px;
  transform: translateY(-50%);
  margin: 0;
  padding: 5px 9px;
  border: 0;
  border-radius: 7px;
  background: #eef4fc;
  color: #4c6b8d;
  font: inherit;
  font-size: 0.68rem;
  font-weight: 700;
  cursor: pointer;
}
.aa-password-toggle:hover {
  transform: translateY(-50%);
  background: #e2edfb;
  color: var(--ui-blue-dark);
}

.aa-select-wrap {
  position: relative;
}
.aa-select-wrap select {
  appearance: none;
  width: 100%;
  box-sizing: border-box;
  min-height: 46px;
  padding: 0 40px 0 14px;
  border: 1px solid #d8e4f0;
  border-radius: 10px;
  background: #fbfdff;
  color: var(--ui-ink);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.aa-select-wrap select:focus {
  outline: none;
  border-color: #6ea3f2;
  background: #fff;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}
.aa-chevron {
  position: absolute;
  top: 50%;
  right: 14px;
  transform: translateY(-50%);
  width: 16px;
  height: 16px;
  color: #7d92ab;
  pointer-events: none;
}

.aa-submit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  margin-top: 4px;
  min-height: 47px;
  padding: 0 20px;
  border: 0;
  border-radius: 11px;
  background: linear-gradient(180deg, #3b82f6, var(--ui-blue));
  color: #fff;
  font: inherit;
  font-size: 0.86rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  cursor: pointer;
  box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22);
  transition: background 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
}
.aa-submit:hover:not(:disabled) {
  background: var(--ui-blue-dark);
  box-shadow: 0 10px 22px rgba(23, 78, 166, 0.28);
}
.aa-submit:disabled {
  opacity: 0.75;
  cursor: wait;
}
.aa-spinner {
  width: 15px;
  height: 15px;
  border: 2px solid rgba(255, 255, 255, 0.45);
  border-top-color: #fff;
  border-radius: 50%;
  animation: aa-spin 0.7s linear infinite;
}
@keyframes aa-spin {
  to {
    transform: rotate(360deg);
  }
}

/* List */
.aa-table-wrap {
  overflow-x: auto;
}
.aa-table {
  width: 100%;
  border-collapse: collapse;
}
.aa-table th {
  padding: 12px 24px;
  border-bottom: 1px solid #edf2f8;
  background: transparent;
  color: #7a8ca3;
  font-size: 0.62rem;
  font-weight: 700;
  text-align: left;
  text-transform: uppercase;
  letter-spacing: 0.09em;
}
.aa-table td {
  padding: 14px 24px;
  border-bottom: 1px solid #f1f5fa;
  font-size: 0.82rem;
  color: #526e97;
  vertical-align: middle;
}
.aa-table tbody tr {
  transition: background-color 0.14s ease;
}
.aa-table tbody tr:hover {
  background: #f8fbff;
}
.aa-table tbody tr:last-child td {
  border-bottom: 0;
}
.aa-th-actions {
  width: 1%;
}

.aa-member {
  display: flex;
  align-items: center;
  gap: 13px;
}
.aa-avatar {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.aa-avatar--admin {
  background: linear-gradient(135deg, #3b82f6, #174ea6);
  color: #fff;
  box-shadow: 0 4px 10px rgba(37, 99, 235, 0.28);
}
.aa-avatar--event {
  background: linear-gradient(135deg, #34d399, #0e9f6e);
  color: #fff;
  box-shadow: 0 4px 10px rgba(14, 159, 110, 0.24);
}
.aa-member-meta {
  display: grid;
  gap: 2px;
  min-width: 0;
}
.aa-member-meta strong {
  color: var(--ui-ink);
  font-size: 0.84rem;
  font-weight: 700;
}
.aa-member-meta span {
  color: var(--ui-subtle);
  font-size: 0.72rem;
}

.aa-role-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 5px 12px;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  white-space: nowrap;
}
.aa-role-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}
.aa-role-pill--admin {
  background: #eaf2ff;
  color: #2563eb;
}
.aa-role-pill--event {
  background: #e6f7f1;
  color: #0e9f6e;
}

.aa-actions {
  text-align: right;
}
.aa-delete {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  padding: 7px 12px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: #d04b5b;
  font: inherit;
  font-size: 0.72rem;
  font-weight: 700;
  cursor: pointer;
  transition: background-color 0.14s ease, border-color 0.14s ease, color 0.14s ease;
}
.aa-delete svg {
  width: 14px;
  height: 14px;
}
.aa-delete:hover {
  border-color: #ffd3d8;
  background: #fff5f6;
  color: #a92f40;
}

/* Loading skeleton */
.aa-skeletons {
  padding: 14px 24px 20px;
}
.aa-skeleton-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 0;
}
.aa-skeleton {
  position: relative;
  overflow: hidden;
  background: #eef3f9;
  border-radius: 8px;
}
.aa-skeleton::after {
  content: '';
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.75), transparent);
  animation: aa-shimmer 1.4s ease infinite;
}
@keyframes aa-shimmer {
  100% {
    transform: translateX(100%);
  }
}
.aa-skeleton--avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  flex: 0 0 auto;
}
.aa-skeleton-lines {
  display: grid;
  gap: 8px;
  flex: 1;
}
.aa-skeleton--line {
  height: 10px;
}
.aa-skeleton--w60 {
  width: 60%;
}
.aa-skeleton--w40 {
  width: 40%;
}
.aa-skeleton--pill {
  width: 96px;
  height: 24px;
  border-radius: 999px;
  flex: 0 0 auto;
}

/* Empty state */
.aa-empty {
  display: grid;
  justify-items: center;
  gap: 6px;
  padding: 56px 24px;
  text-align: center;
}
.aa-empty-icon {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  margin-bottom: 8px;
  border-radius: 16px;
  background: #eaf2ff;
  color: var(--ui-blue);
  box-shadow: inset 0 0 0 1px #d7e7fb;
}
.aa-empty-icon svg {
  width: 24px;
  height: 24px;
}
.aa-empty strong {
  color: var(--ui-ink);
  font-size: 0.9rem;
}
.aa-empty p {
  margin: 0;
  color: var(--ui-subtle);
  font-size: 0.76rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 640px) {
  .aa-heading {
    flex-direction: column;
    align-items: flex-start;
  }
  .aa-table th,
  .aa-table td {
    padding-left: 16px;
    padding-right: 16px;
  }
  .aa-form,
  .aa-card-head,
  .aa-skeletons {
    padding-left: 16px;
    padding-right: 16px;
  }
  .aa-delete span {
    display: none;
  }
  .aa-delete {
    padding: 7px 9px;
  }
}
</style>
