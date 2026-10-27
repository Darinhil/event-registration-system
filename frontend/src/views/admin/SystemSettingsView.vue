<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { TITLE_SUFFIX, useSettingsStore } from '../../stores/settings'

const NAME_MAX = 150
const NOTICE_MAX = 1000
const store = useSettingsStore()

const saved = reactive({ site_name: '', registration_notice: '', maintenance_mode: false })
const form = reactive({ site_name: '', registration_notice: '', maintenance_mode: false })
const errors = reactive({ site_name: '', registration_notice: '' })
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const saveError = ref('')
const toast = ref('')
const nameInput = ref(null)
const noticeInput = ref(null)

const normalized = (values) => ({ site_name: values.site_name.trim(), registration_notice: values.registration_notice.trim(), maintenance_mode: !!values.maintenance_mode })
const dirty = computed(() => JSON.stringify(normalized(form)) !== JSON.stringify(normalized(saved)))
const changedCount = computed(() => ['site_name', 'registration_notice', 'maintenance_mode'].filter((key) => normalized(form)[key] !== normalized(saved)[key]).length)
const previewTitle = computed(() => `${form.site_name.trim() || '…'} · ${TITLE_SUFFIX}`)
const previewNotice = computed(() => form.registration_notice.trim())

const fill = (data) => {
  const values = { site_name: data.site_name || '', registration_notice: data.registration_notice || '', maintenance_mode: !!data.maintenance_mode }
  Object.assign(saved, values)
  Object.assign(form, values)
}

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    fill((await api.get('/admin/settings')).data.data)
  } catch (e) {
    loadError.value = e.response?.data?.message || 'We couldn’t load the settings. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}

const validate = () => {
  const name = form.site_name.trim()
  errors.site_name = !name ? 'Enter a system name.' : name.length > NAME_MAX ? `Use ${NAME_MAX} characters or fewer.` : ''
  errors.registration_notice = form.registration_notice.trim().length > NOTICE_MAX ? `Use ${NOTICE_MAX} characters or fewer.` : ''
  return !errors.site_name && !errors.registration_notice
}

let toastTimer
const showToast = (text) => { toast.value = text; clearTimeout(toastTimer); toastTimer = setTimeout(() => (toast.value = ''), 6000) }

/* Turning maintenance on pauses every registration, so it gets an explicit confirmation. */
const confirmEl = ref(null)
const confirmOpen = ref(false)
let confirmReturnFocus = null
const askConfirm = async () => {
  confirmReturnFocus = document.activeElement
  confirmOpen.value = true
  await nextTick()
  confirmEl.value?.showModal()
}
const closeConfirm = () => confirmEl.value?.close()
const onConfirmClosed = () => {
  confirmOpen.value = false
  if (confirmReturnFocus?.isConnected) confirmReturnFocus.focus()
  confirmReturnFocus = null
}

const save = async ({ confirmed = false } = {}) => {
  if (saving.value || !dirty.value) return
  saveError.value = ''
  if (!validate()) {
    await nextTick()
    ;(errors.site_name ? nameInput : noticeInput).value?.focus()
    return
  }
  if (form.maintenance_mode && !saved.maintenance_mode && !confirmed) return askConfirm()
  saving.value = true
  try {
    const { data } = await api.put('/admin/settings', normalized(form))
    fill(data.data)
    store.apply(data.data)
    showToast('Settings saved. Changes are live for everyone now.')
  } catch (e) {
    const fieldErrors = e.response?.data?.errors || {}
    errors.site_name = fieldErrors.site_name?.[0] || ''
    errors.registration_notice = fieldErrors.registration_notice?.[0] || ''
    if (errors.site_name || errors.registration_notice) (errors.site_name ? nameInput : noticeInput).value?.focus()
    else saveError.value = e.response ? (e.response.data?.message || 'The settings couldn’t be saved.') : 'We couldn’t reach the server. Your changes are still here — try again.'
  } finally {
    saving.value = false
  }
}
const confirmMaintenance = async () => { closeConfirm(); await save({ confirmed: true }) }
const discard = () => { Object.assign(form, { ...saved }); errors.site_name = ''; errors.registration_notice = ''; saveError.value = '' }

// Don't lose edits by navigating away.
const leaveMessage = 'You have unsaved settings. Leave without saving?'
onBeforeRouteLeave(() => (!dirty.value || window.confirm(leaveMessage)))
const onBeforeUnload = (event) => { if (dirty.value) { event.preventDefault(); event.returnValue = leaveMessage } }
const onKeydown = (event) => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && dirty.value) { event.preventDefault(); save() } }
onMounted(() => { load(); window.addEventListener('beforeunload', onBeforeUnload); window.addEventListener('keydown', onKeydown) })
onBeforeUnmount(() => { clearTimeout(toastTimer); window.removeEventListener('beforeunload', onBeforeUnload); window.removeEventListener('keydown', onKeydown) })
</script>

<template>
  <AdminLayout>
    <section class="st-page" aria-labelledby="st-title">
      <header class="st-head">
        <p class="st-eyebrow">Administration</p>
        <h1 id="st-title">Settings</h1>
        <p class="st-lead">System-wide options for the public site and event registration. Changes go live as soon as you save.</p>
      </header>

      <div class="st-live" role="status" aria-live="polite">
        <p v-if="toast" class="st-toast"><AppIcon name="check-circle" :size="18" /><span>{{ toast }}</span><button type="button" class="st-icon-btn" aria-label="Dismiss message" @click="toast = ''"><AppIcon name="x" :size="16" /></button></p>
      </div>

      <div v-if="loading" class="st-card st-skeleton" aria-busy="true" aria-label="Loading settings">
        <span v-for="n in 3" :key="n" class="st-skel-row"><span class="st-skel st-skel--a"></span><span class="st-skel st-skel--b"></span></span>
      </div>

      <div v-else-if="loadError" class="st-card st-state" role="alert">
        <span class="st-state-icon" aria-hidden="true"><AppIcon name="alert-circle" :size="22" /></span>
        <strong>Settings unavailable</strong>
        <p>{{ loadError }}</p>
        <button type="button" class="st-btn st-btn--ghost" @click="load"><AppIcon name="refresh" :size="16" />Try again</button>
      </div>

      <form v-else class="st-form" novalidate @submit.prevent="save()">
        <!-- System name -->
        <section class="st-card st-row" aria-labelledby="st-name-title">
          <div class="st-row-intro">
            <h2 id="st-name-title">System name</h2>
            <p>Shown in the browser tab on every page, for attendees and staff.</p>
          </div>
          <div class="st-row-body">
            <div class="st-field" :class="{ 'has-error': errors.site_name }">
              <label for="st-name">Name <span class="st-req" aria-hidden="true">*</span></label>
              <input id="st-name" ref="nameInput" v-model="form.site_name" type="text" :maxlength="NAME_MAX" required autocomplete="off" :aria-invalid="!!errors.site_name" :aria-describedby="errors.site_name ? 'st-name-err st-name-count' : 'st-name-count'" @input="errors.site_name = ''" />
              <div class="st-field-foot">
                <p v-if="errors.site_name" id="st-name-err" class="st-err">{{ errors.site_name }}</p>
                <span id="st-name-count" class="st-count">{{ form.site_name.length }}/{{ NAME_MAX }}</span>
              </div>
            </div>
            <div class="st-preview" aria-hidden="true">
              <span class="st-preview-label">Preview</span>
              <div class="st-tab"><span class="st-tab-dot"></span><span class="st-tab-title">{{ previewTitle }}</span></div>
            </div>
          </div>
        </section>

        <!-- Attendee notice -->
        <section class="st-card st-row" aria-labelledby="st-notice-title">
          <div class="st-row-intro">
            <h2 id="st-notice-title">Attendee notice</h2>
            <p>A short message at the top of every public page — home, event pages and registration forms. Leave it empty to hide it.</p>
          </div>
          <div class="st-row-body">
            <div class="st-field" :class="{ 'has-error': errors.registration_notice }">
              <label for="st-notice">Message <span class="st-optional">Optional</span></label>
              <textarea id="st-notice" ref="noticeInput" v-model="form.registration_notice" rows="4" :maxlength="NOTICE_MAX" placeholder="e.g. Please bring a photo ID to check in." :aria-invalid="!!errors.registration_notice" :aria-describedby="errors.registration_notice ? 'st-notice-err st-notice-count' : 'st-notice-count'" @input="errors.registration_notice = ''"></textarea>
              <div class="st-field-foot">
                <p v-if="errors.registration_notice" id="st-notice-err" class="st-err">{{ errors.registration_notice }}</p>
                <span id="st-notice-count" class="st-count">{{ form.registration_notice.length }}/{{ NOTICE_MAX }}</span>
              </div>
            </div>
            <div class="st-preview" aria-hidden="true">
              <span class="st-preview-label">What attendees see</span>
              <p v-if="previewNotice" class="st-notice-preview"><AppIcon name="info" :size="18" /><span>{{ previewNotice }}</span></p>
              <p v-else class="st-preview-empty">No notice is shown.</p>
            </div>
          </div>
        </section>

        <!-- Maintenance mode -->
        <section class="st-card st-row" :class="{ 'is-warning': form.maintenance_mode }" aria-labelledby="st-maint-title">
          <div class="st-row-intro">
            <h2 id="st-maint-title">Maintenance mode</h2>
            <p>Pause new registrations across the whole system, for example during an update or a data check.</p>
          </div>
          <div class="st-row-body">
            <label class="st-switch-row" for="st-maint">
              <span class="st-switch-copy">
                <strong>Pause new registrations</strong>
                <span class="st-status" :class="form.maintenance_mode ? 'is-on' : 'is-off'">{{ form.maintenance_mode ? 'On — registrations paused' : 'Off — registrations open' }}</span>
              </span>
              <input id="st-maint" v-model="form.maintenance_mode" type="checkbox" role="switch" class="st-switch" aria-describedby="st-maint-effects" />
            </label>
            <div id="st-maint-effects" class="st-effects">
              <p>While it’s on:</p>
              <ul>
                <li><AppIcon name="lock" :size="15" />Every event stops accepting new registrations and explains why.</li>
                <li><AppIcon name="eye" :size="15" />Attendees can still browse events and view or edit their existing registrations.</li>
                <li><AppIcon name="shield-check" :size="15" />Staff keep full access, including check-in. A banner reminds everyone in admin that it’s on.</li>
              </ul>
            </div>
          </div>
        </section>

        <p v-if="saveError" class="st-save-error" role="alert"><AppIcon name="alert-circle" :size="16" />{{ saveError }}</p>

        <!-- Save bar -->
        <div class="st-savebar" :class="{ 'is-dirty': dirty }">
          <p class="st-savebar-msg">
            <template v-if="dirty"><span class="st-dot" aria-hidden="true"></span>{{ changedCount }} unsaved {{ changedCount === 1 ? 'change' : 'changes' }}</template>
            <template v-else><AppIcon name="check" :size="16" />All changes saved</template>
          </p>
          <div class="st-savebar-actions">
            <button type="button" class="st-btn st-btn--ghost" :disabled="!dirty || saving" @click="discard">Discard</button>
            <button type="submit" class="st-btn st-btn--primary" :disabled="!dirty || saving" :aria-busy="saving">
              <span v-if="saving" class="st-spinner" aria-hidden="true"></span>{{ saving ? 'Saving…' : 'Save changes' }}
            </button>
          </div>
        </div>
      </form>

      <dialog ref="confirmEl" class="st-dialog" aria-labelledby="st-confirm-title" aria-describedby="st-confirm-desc" @close="onConfirmClosed">
        <div v-if="confirmOpen" class="st-dialog-body">
          <span class="st-dialog-icon" aria-hidden="true"><AppIcon name="alert-circle" :size="22" /></span>
          <h2 id="st-confirm-title">Pause all new registrations?</h2>
          <p id="st-confirm-desc">No one will be able to register for any event until you turn maintenance mode off again. Existing registrations and check-in are not affected.</p>
          <div class="st-dialog-actions">
            <button type="button" class="st-btn st-btn--ghost" @click="closeConfirm">Cancel</button>
            <button type="button" class="st-btn st-btn--warn" @click="confirmMaintenance">Turn on and save</button>
          </div>
        </div>
      </dialog>
    </section>
  </AdminLayout>
</template>

<style scoped>
.st-page {
  --st-ink: #0f1c2e;
  --st-text: #33475b;
  --st-muted: #5f7286;
  --st-line: #e3e9f0;
  --st-line-soft: #eef2f6;
  --st-green: #0b7f62;
  --st-green-dark: #086a51;
  --st-green-soft: #e8f5f0;
  --st-red: #b42318;
  --st-amber: #8a5a06;
  max-width: 1040px;
  margin: 0 auto;
  padding: 28px 0 40px;
  color: var(--st-text);
}

.st-head { margin-bottom: 22px; }
.st-eyebrow { margin: 0 0 6px; color: var(--st-green); font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
.st-head h1 { margin: 0; color: var(--st-ink); font-size: clamp(1.5rem, 2.4vw, 1.85rem); font-weight: 700; letter-spacing: -.02em; }
.st-lead { margin: 6px 0 0; color: var(--st-muted); font-size: .92rem; }

.st-live:empty { display: none; }
.st-toast { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 18px; padding: 10px 10px 10px 16px; border: 1px solid #b7e4d2; border-radius: 10px; background: #f0faf6; color: #075e48; font-size: .88rem; font-weight: 550; }
.st-toast > svg { flex: none; margin-top: 1px; }
.st-toast span { flex: 1; padding-top: 1px; }

.st-form { display: grid; gap: 16px; }
.st-card { border: 1px solid var(--st-line); border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 28, 46, .04), 0 6px 20px rgba(15, 28, 46, .04); }
.st-row { display: grid; grid-template-columns: minmax(0, 280px) minmax(0, 1fr); gap: 32px; padding: 24px; }
.st-row.is-warning { border-color: #f1d8a8; }
.st-row-intro h2 { margin: 0; color: var(--st-ink); font-size: 1rem; font-weight: 700; }
.st-row-intro p { margin: 6px 0 0; color: var(--st-muted); font-size: .86rem; line-height: 1.55; }
.st-row-body { display: grid; min-width: 0; gap: 16px; align-content: start; }

.st-field { display: grid; gap: 6px; }
.st-field > label { display: block; margin: 0; color: var(--st-ink); font-size: .84rem; font-weight: 600; }
.st-req { color: var(--st-red); }
.st-optional { margin-left: 4px; color: var(--st-muted); font-size: .76rem; font-weight: 500; }
.st-field input,
.st-field textarea {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  margin: 0;
  border: 1px solid #cfd9e3;
  border-radius: 9px;
  background: #fff;
  box-shadow: none;
  color: var(--st-ink);
  font: inherit;
  font-size: .9rem;
  transition: border-color .15s ease, box-shadow .15s ease;
}
.st-field input { height: 44px; min-height: 44px; padding: 0 14px; }
.st-field textarea { min-height: 104px; padding: 11px 14px; line-height: 1.5; resize: vertical; }
.st-field input::placeholder, .st-field textarea::placeholder { color: #8a9bab; }
.st-field input:hover, .st-field textarea:hover { border-color: #b3c2d0; }
.st-field input:focus, .st-field textarea:focus { border-color: var(--st-green); outline: none; box-shadow: 0 0 0 3px rgba(11, 127, 98, .16); }
.st-field.has-error input, .st-field.has-error textarea { border-color: var(--st-red); }
.st-field-foot { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.st-err { margin: 0; color: var(--st-red); font-size: .8rem; font-weight: 550; }
.st-count { margin-left: auto; color: var(--st-muted); font-size: .76rem; font-variant-numeric: tabular-nums; }

.st-preview { display: grid; gap: 8px; padding: 14px; border: 1px dashed #d5dee7; border-radius: 10px; background: #f8fafc; }
.st-preview-label { color: var(--st-muted); font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.st-tab { display: flex; max-width: 320px; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid var(--st-line); border-bottom: 0; border-radius: 9px 9px 0 0; background: #fff; box-shadow: 0 2px 0 #fff, 0 -1px 4px rgba(15, 28, 46, .04); }
.st-tab-dot { flex: none; width: 14px; height: 14px; border-radius: 4px; background: var(--st-green); }
.st-tab-title { overflow: hidden; color: var(--st-ink); font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
.st-notice-preview { display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 11px 14px; border: 1px solid #cfe6dd; border-radius: 10px; background: #f0faf6; color: #0b4d3c; font-size: .88rem; line-height: 1.5; white-space: pre-line; overflow-wrap: anywhere; }
.st-notice-preview svg { flex: none; margin-top: 2px; }
.st-preview-empty { margin: 0; color: var(--st-muted); font-size: .86rem; font-style: italic; }

/* Switch */
.st-switch-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 0; padding: 14px 16px; border: 1px solid var(--st-line); border-radius: 10px; cursor: pointer; }
.st-switch-row:hover { border-color: #b3c2d0; }
.st-switch-copy { display: grid; gap: 4px; }
.st-switch-copy strong { color: var(--st-ink); font-size: .92rem; }
.st-status { justify-self: start; padding: 2px 9px; border-radius: 999px; font-size: .74rem; font-weight: 650; }
.st-status.is-off { background: var(--st-green-soft); color: #075e48; }
.st-status.is-on { background: #fdf3e1; color: var(--st-amber); }
.st-switch { position: relative; flex: none; width: 44px; height: 26px; min-height: 0; margin: 0; padding: 0; border: 0; border-radius: 999px; background: #cbd5e1; cursor: pointer; appearance: none; transition: background-color .18s ease; }
.st-switch::after { position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(15, 28, 46, .3); content: ""; transition: transform .18s ease; }
.st-switch:checked { background: #b7791f; }
.st-switch:checked::after { transform: translateX(18px); }
.st-switch:focus-visible { outline: 3px solid rgba(11, 127, 98, .35); outline-offset: 2px; }

.st-effects { color: var(--st-text); font-size: .86rem; }
.st-effects p { margin: 0 0 8px; color: var(--st-muted); font-weight: 600; }
.st-effects ul { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
.st-effects li { display: flex; align-items: flex-start; gap: 9px; line-height: 1.5; }
.st-effects li svg { flex: none; margin-top: 3px; color: var(--st-muted); }

.st-save-error { display: flex; align-items: flex-start; gap: 8px; margin: 0; padding: 10px 14px; border: 1px solid #f5c2bd; border-radius: 10px; background: #fef4f3; color: var(--st-red); font-size: .86rem; font-weight: 550; }
.st-save-error svg { flex: none; margin-top: 2px; }

/* Save bar */
.st-savebar { position: sticky; z-index: 5; bottom: 16px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 12px 12px 18px; border: 1px solid var(--st-line); border-radius: 12px; background: rgba(255, 255, 255, .96); box-shadow: 0 8px 28px rgba(15, 28, 46, .1); backdrop-filter: blur(6px); }
.st-savebar.is-dirty { border-color: #f1d8a8; }
.st-savebar-msg { display: flex; align-items: center; gap: 8px; margin: 0; color: var(--st-muted); font-size: .88rem; font-weight: 600; }
.st-savebar.is-dirty .st-savebar-msg { color: var(--st-amber); }
.st-savebar-msg svg { color: var(--st-green); }
.st-dot { width: 8px; height: 8px; border-radius: 50%; background: #b7791f; }
.st-savebar-actions { display: flex; gap: 8px; }

/* Buttons */
.st-btn { display: inline-flex; min-width: 0; min-height: 40px; align-items: center; justify-content: center; gap: 8px; margin: 0; padding: 0 16px; border: 1px solid transparent; border-radius: 9px; background: transparent; box-shadow: none; font: inherit; font-size: .86rem; font-weight: 650; white-space: nowrap; cursor: pointer; transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
.st-btn:hover, .st-btn:not(:disabled):hover { transform: none; }
.st-btn:disabled { cursor: not-allowed; opacity: .55; }
.st-btn[aria-busy="true"] { cursor: progress; opacity: .85; }
.st-btn--primary { background: var(--st-green); color: #fff; }
.st-btn--primary:not(:disabled):hover { background: var(--st-green-dark); }
.st-btn--ghost { border-color: #cfd9e3; background: #fff; color: var(--st-ink); }
.st-btn--ghost:not(:disabled):hover { border-color: #b3c2d0; background: #f6f8fa; }
.st-btn--warn { background: #b7791f; color: #fff; }
.st-btn--warn:not(:disabled):hover { background: #8a5a06; }
.st-btn:focus-visible, .st-icon-btn:focus-visible { outline: 3px solid rgba(11, 127, 98, .35); outline-offset: 2px; }
.st-icon-btn { display: grid; width: 32px; min-width: 0; height: 32px; min-height: 0; place-items: center; margin: 0; padding: 0; border: 0; border-radius: 7px; background: transparent; box-shadow: none; color: inherit; cursor: pointer; }
.st-icon-btn:hover, .st-icon-btn:not(:disabled):hover { background: rgba(15, 28, 46, .06); transform: none; }
.st-spinner { width: 15px; height: 15px; border: 2px solid rgba(255, 255, 255, .45); border-top-color: #fff; border-radius: 50%; animation: st-spin .7s linear infinite; }
@keyframes st-spin { to { transform: rotate(360deg); } }

/* States */
.st-state { display: grid; justify-items: center; gap: 6px; padding: 48px 24px; text-align: center; }
.st-state strong { color: var(--st-ink); }
.st-state p { max-width: 40ch; margin: 0 0 8px; color: var(--st-muted); font-size: .88rem; }
.st-state-icon { display: grid; width: 48px; height: 48px; margin-bottom: 6px; place-items: center; border-radius: 12px; background: #fef4f3; color: var(--st-red); }
.st-skeleton { display: grid; gap: 22px; padding: 24px; }
.st-skel-row { display: grid; grid-template-columns: 240px 1fr; gap: 32px; }
.st-skel { display: block; height: 44px; border-radius: 8px; background: linear-gradient(90deg, #eef2f6 25%, #f6f8fa 50%, #eef2f6 75%); background-size: 200% 100%; animation: st-shimmer 1.3s ease infinite; }
.st-skel--a { height: 36px; }
@keyframes st-shimmer { to { background-position: -200% 0; } }

/* Dialog */
.st-dialog { position: fixed; inset: 0; width: min(440px, calc(100vw - 32px)); height: fit-content; max-height: calc(100dvh - 32px); margin: auto; padding: 0; border: 0; border-radius: 14px; background: #fff; box-shadow: 0 24px 60px rgba(15, 28, 46, .28); color: var(--st-text); }
.st-dialog::backdrop { background: rgba(15, 28, 46, .5); }
.st-dialog-body { display: grid; gap: 12px; padding: 24px; }
.st-dialog-body h2 { margin: 0; color: var(--st-ink); font-size: 1.12rem; }
.st-dialog-body p { margin: 0; font-size: .9rem; line-height: 1.55; }
.st-dialog-icon { display: grid; width: 44px; height: 44px; place-items: center; border-radius: 12px; background: #fdf3e1; color: var(--st-amber); }
.st-dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }

@media (max-width: 900px) {
  .st-row { grid-template-columns: minmax(0, 1fr); gap: 16px; }
  .st-skel-row { grid-template-columns: 1fr; gap: 10px; }
}
@media (max-width: 640px) {
  .st-page { padding-top: 18px; }
  .st-row { padding: 18px 16px; }
  .st-savebar { bottom: 8px; padding: 12px; }
  .st-savebar-actions { width: 100%; }
  .st-savebar-actions .st-btn { flex: 1; min-height: 44px; }
  .st-dialog-actions { flex-direction: column-reverse; }
  .st-dialog-actions .st-btn { width: 100%; min-height: 44px; }
}
@media (prefers-reduced-motion: reduce) {
  .st-skel, .st-spinner { animation: none; }
  .st-switch, .st-switch::after { transition: none; }
}
</style>
