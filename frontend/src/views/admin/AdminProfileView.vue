<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'
import api from '../../services/api'

const auth = useAuthStore()
const isStaff = computed(() => auth.canAccessAdmin)
const roleLabel = computed(() => auth.roleLabel)

const initials = computed(() => (auth.user?.name || 'U').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'U')
const createdDate = computed(() => auth.user?.created_at ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(auth.user.created_at)) : '')

/* ---------- Read-only account details ---------- */
// The API has no endpoint to edit these yourself, so the page explains who can help instead.
const details = computed(() => [
  { label: 'Full name', value: auth.user?.name, icon: 'user' },
  { label: 'Email address', value: auth.user?.email, icon: 'mail', note: 'Used to sign in' },
  { label: 'Phone number', value: auth.user?.phone, icon: 'phone' },
  { label: 'Username', value: auth.user?.username, icon: 'type' },
])
const detailsHelp = computed(() => {
  if (!isStaff.value) return 'These come from your account. To change the details on an event registration, open it from My information.'
  if (auth.isAdmin) return 'Self-service editing of these details isn’t available yet.'
  return 'These are managed by your organization. Ask a Master Admin if something needs to change.'
})

/* ---------- Profile photo ---------- */
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const PHOTO_MAX = 5 * 1024 * 1024
const fileInput = ref(null)
const selectedPhoto = ref(null)
const photoPreview = ref('')
const serverPhotoSrc = ref('')
const photoBroken = ref(false)
const photoSaving = ref(false)
const photoProgress = ref(0)
const photoError = ref('')
const photoDone = ref('')
const dragging = ref(false)
let photoRequest = 0

const displayPhoto = computed(() => (selectedPhoto.value ? photoPreview.value : serverPhotoSrc.value))
const selectedSize = computed(() => {
  const bytes = selectedPhoto.value?.size || 0
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
})

const loadServerPhoto = async () => {
  const requestId = ++photoRequest
  if (!auth.user?.profile_photo) { serverPhotoSrc.value = ''; return }
  try {
    const { data } = await api.get('/me/profile-photo', { responseType: 'blob' })
    if (requestId !== photoRequest) return
    serverPhotoSrc.value = auth.setProfilePhotoPreview(data)
    photoBroken.value = false
  } catch {
    if (requestId === photoRequest) serverPhotoSrc.value = ''
  }
}
onMounted(loadServerPhoto)
watch(() => auth.user?.profile_photo, loadServerPhoto)

const revokePreview = () => { if (photoPreview.value?.startsWith('blob:')) URL.revokeObjectURL(photoPreview.value) }
const usePhoto = (file) => {
  photoError.value = ''
  photoDone.value = ''
  if (!file) return
  if (!PHOTO_TYPES.includes(file.type)) { photoError.value = 'That file type isn’t supported. Choose a JPG, PNG or WEBP image.'; return }
  if (file.size > PHOTO_MAX) { photoError.value = `That image is ${(file.size / 1024 / 1024).toFixed(1)} MB. Choose one that’s 5 MB or smaller.`; return }
  revokePreview()
  selectedPhoto.value = file
  photoPreview.value = URL.createObjectURL(file)
  photoBroken.value = false
}
const onFileChange = (event) => { const file = event.target.files?.[0]; event.target.value = ''; usePhoto(file) }
const onDrop = (event) => { dragging.value = false; if (!photoSaving.value) usePhoto(event.dataTransfer?.files?.[0]) }
const openPicker = () => { if (!photoSaving.value) fileInput.value?.click() }
const cancelPhoto = () => {
  revokePreview()
  selectedPhoto.value = null
  photoPreview.value = ''
  photoBroken.value = false
  photoError.value = ''
}
const savePhoto = async () => {
  if (!selectedPhoto.value || photoSaving.value) return
  photoError.value = ''
  photoDone.value = ''
  photoSaving.value = true
  photoProgress.value = 0
  try {
    const payload = new FormData()
    payload.append('profile_photo', selectedPhoto.value)
    await auth.updateProfile(payload, {
      onUploadProgress: (event) => { if (event.total) photoProgress.value = Math.round((event.loaded / event.total) * 100) },
    })
    cancelPhoto()
    photoDone.value = 'Profile photo updated. It now shows on your account menu.'
    auth.fetchMe().catch(() => {})
  } catch (error) {
    const validationErrors = error.response?.data?.errors
    photoError.value = validationErrors
      ? Object.values(validationErrors).flat()[0]
      : error.response ? (error.response.data?.message || 'Your photo couldn’t be uploaded.') : 'We couldn’t reach the server. Your photo is still selected — try again.'
  } finally {
    photoSaving.value = false
  }
}
onBeforeUnmount(revokePreview)

/* ---------- Change password (PATCH /me/password) ---------- */
const password = reactive({ current_password: '', new_password: '', new_password_confirmation: '' })
const passwordErrors = reactive({})
const passwordSaving = ref(false)
const passwordDone = ref('')
const visible = reactive({ current_password: false, new_password: false, new_password_confirmation: false })
const fieldMap = { current_password: 'pw-current', new_password: 'pw-new', new_password_confirmation: 'pw-confirm' }
const requirements = computed(() => [
  { id: 'length', label: 'At least 8 characters', met: password.new_password.length >= 8 },
  { id: 'different', label: 'Different from your current password', met: !!password.new_password && password.new_password !== password.current_password },
  { id: 'match', label: 'Both new passwords match', met: !!password.new_password && password.new_password === password.new_password_confirmation },
])
const passwordDirty = computed(() => Object.values(password).some(Boolean))

const clearPasswordErrors = () => Object.keys(passwordErrors).forEach((key) => delete passwordErrors[key])
const focusFirstPasswordError = () => nextTick(() => {
  const key = Object.keys(fieldMap).find((name) => passwordErrors[name])
  if (key) document.getElementById(fieldMap[key])?.focus()
})
const changePassword = async () => {
  if (passwordSaving.value) return
  clearPasswordErrors()
  passwordDone.value = ''
  // Same rules as the server: current password, at least 8 characters, matching confirmation.
  if (!password.current_password) passwordErrors.current_password = 'Enter your current password.'
  if (password.new_password.length < 8) passwordErrors.new_password = 'Use at least 8 characters.'
  else if (password.new_password === password.current_password) passwordErrors.new_password = 'Choose a password different from your current one.'
  if (!password.new_password_confirmation) passwordErrors.new_password_confirmation = 'Re-enter the new password.'
  else if (password.new_password_confirmation !== password.new_password) passwordErrors.new_password_confirmation = 'The passwords don’t match.'
  if (Object.keys(passwordErrors).length) return focusFirstPasswordError()
  passwordSaving.value = true
  try {
    await auth.updatePassword({ ...password })
    Object.assign(password, { current_password: '', new_password: '', new_password_confirmation: '' })
    Object.keys(visible).forEach((key) => (visible[key] = false))
    passwordDone.value = 'Password changed. Use your new password the next time you sign in.'
  } catch (error) {
    const errors = error.response?.data?.errors || {}
    Object.entries(errors).forEach(([key, messages]) => { if (fieldMap[key]) passwordErrors[key] = [messages].flat()[0] })
    if (!Object.keys(errors).length) {
      const message = error.response?.data?.message
      if (error.response?.status === 422 && /current password/i.test(message || '')) passwordErrors.current_password = message
      else passwordErrors.form = error.response ? (message || 'Your password couldn’t be changed.') : 'We couldn’t reach the server. Check your connection and try again.'
    }
    focusFirstPasswordError()
  } finally {
    passwordSaving.value = false
  }
}
const clearFieldError = (key) => { delete passwordErrors[key]; delete passwordErrors.form }
// Hide the success note once the user starts typing again (not when the form clears itself after saving).
watch(() => ({ ...password }), (value) => { if (passwordDone.value && Object.values(value).some(Boolean)) passwordDone.value = '' })

/* ---------- Don't lose unsaved work ---------- */
onBeforeRouteLeave(() => (!(selectedPhoto.value || passwordDirty.value) || window.confirm('You have unsaved changes on this page. Leave anyway?')))
</script>

<template>
  <component :is="isStaff ? AdminLayout : UserLayout">
    <section class="pf-page" aria-labelledby="pf-title">
      <header class="pf-header">
        <RouterLink v-if="!isStaff" class="pf-back" to="/me"><AppIcon name="chevron-left" :size="16" />My information</RouterLink>
        <h1 id="pf-title">Profile settings</h1>
        <p>Change your photo and password, and check the details on your account.</p>
      </header>

      <!-- Identity + photo -->
      <section class="pf-card pf-identity" :class="{ 'is-dragging': dragging }" aria-labelledby="pf-photo-title" @dragover.prevent="dragging = true" @dragleave.self="dragging = false" @drop.prevent="onDrop">
        <button type="button" class="pf-avatar" :class="{ 'is-preview': selectedPhoto }" :disabled="photoSaving" :aria-label="displayPhoto ? 'Change profile photo' : 'Add a profile photo'" @click="openPicker">
          <img v-if="displayPhoto && !photoBroken" :src="displayPhoto" alt="" @error="photoBroken = true" />
          <span v-else class="pf-avatar__initials" aria-hidden="true">{{ initials }}</span>
          <span class="pf-avatar__cam" aria-hidden="true"><AppIcon name="image" :size="16" /></span>
        </button>
        <input ref="fileInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" tabindex="-1" aria-hidden="true" @change="onFileChange" />

        <div class="pf-identity__body">
          <div class="pf-identity__who">
            <h2 class="pf-name">{{ auth.user?.name || 'Your account' }}</h2>
            <p class="pf-email">{{ auth.user?.email }}</p>
            <p class="pf-meta">
              <span class="pf-role" :class="{ 'is-master': auth.isAdmin }"><AppIcon v-if="auth.isAdmin" name="shield-check" :size="13" />{{ roleLabel }}</span>
              <span v-if="createdDate">Member since {{ createdDate }}</span>
            </p>
          </div>

          <div class="pf-photo">
            <h3 id="pf-photo-title" class="sr-only">Profile photo</h3>
            <template v-if="selectedPhoto">
              <p class="pf-photo__note"><AppIcon name="info" :size="15" />New photo selected · {{ selectedSize }} — save to use it.</p>
              <div v-if="photoSaving" class="pf-progress" role="progressbar" :aria-valuenow="photoProgress" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress"><span :style="{ width: `${photoProgress}%` }"></span></div>
              <div class="pf-row">
                <button type="button" class="my-btn my-btn--primary" :disabled="photoSaving" :aria-busy="photoSaving" @click="savePhoto"><AppIcon name="check" :size="15" />{{ photoSaving ? `Uploading… ${photoProgress}%` : 'Save photo' }}</button>
                <button type="button" class="my-btn" :disabled="photoSaving" @click="openPicker">Choose another</button>
                <button type="button" class="pf-text-btn" :disabled="photoSaving" @click="cancelPhoto">Cancel</button>
              </div>
            </template>
            <template v-else>
              <div class="pf-row">
                <button type="button" class="my-btn" @click="openPicker"><AppIcon name="upload" :size="15" />{{ serverPhotoSrc ? 'Change photo' : 'Upload photo' }}</button>
              </div>
              <p class="pf-hint">JPG, PNG or WEBP, up to 5 MB. You can also drop an image here.</p>
            </template>
            <p v-if="photoError" class="pf-inline is-error" role="alert"><AppIcon name="alert-circle" :size="16" />{{ photoError }}</p>
            <p v-if="photoDone" class="pf-inline is-ok" role="status"><AppIcon name="check-circle" :size="16" />{{ photoDone }}</p>
          </div>
        </div>
      </section>

      <div class="pf-grid">
        <!-- Password (editable) -->
        <section class="pf-card" aria-labelledby="pf-password-title">
          <header class="pf-card__head">
            <span class="pf-card__icon" aria-hidden="true"><AppIcon name="lock" :size="18" /></span>
            <div>
              <h2 id="pf-password-title">Change password</h2>
              <p>You’ll stay signed in on this device after changing it.</p>
            </div>
          </header>

          <p v-if="passwordDone" class="pf-inline is-ok" role="status"><AppIcon name="check-circle" :size="16" />{{ passwordDone }}</p>
          <p v-if="passwordErrors.form" class="pf-inline is-error" role="alert"><AppIcon name="alert-circle" :size="16" />{{ passwordErrors.form }}</p>

          <form class="pf-form" novalidate @submit.prevent="changePassword">
            <!-- Hidden username helps password managers pair the new password with this account. -->
            <input class="sr-only" type="text" name="username" autocomplete="username" :value="auth.user?.email || ''" tabindex="-1" aria-hidden="true" readonly />

            <div v-for="field in [
              { key: 'current_password', id: 'pw-current', label: 'Current password', auto: 'current-password' },
              { key: 'new_password', id: 'pw-new', label: 'New password', auto: 'new-password' },
              { key: 'new_password_confirmation', id: 'pw-confirm', label: 'Confirm new password', auto: 'new-password' },
            ]" :key="field.key" class="pf-field" :class="{ 'has-error': passwordErrors[field.key] }">
              <label :for="field.id">{{ field.label }}</label>
              <div class="pf-input">
                <input :id="field.id" v-model="password[field.key]" :type="visible[field.key] ? 'text' : 'password'" :autocomplete="field.auto" :aria-invalid="passwordErrors[field.key] ? 'true' : undefined" :aria-describedby="[passwordErrors[field.key] ? `${field.id}-error` : '', field.key === 'new_password' ? 'pw-rules' : ''].filter(Boolean).join(' ') || undefined" @input="clearFieldError(field.key)" />
                <button type="button" class="pf-eye" :aria-label="`${visible[field.key] ? 'Hide' : 'Show'} ${field.label.toLowerCase()}`" :aria-pressed="visible[field.key]" @click="visible[field.key] = !visible[field.key]"><AppIcon :name="visible[field.key] ? 'eye-off' : 'eye'" :size="18" /></button>
              </div>
              <p v-if="passwordErrors[field.key]" :id="`${field.id}-error`" class="pf-error"><AppIcon name="alert-circle" :size="14" />{{ passwordErrors[field.key] }}</p>
              <ul v-if="field.key === 'new_password'" id="pw-rules" class="pf-rules" aria-label="Password requirements">
                <li v-for="rule in requirements" :key="rule.id" :class="{ 'is-met': rule.met }"><AppIcon :name="rule.met ? 'check-circle' : 'circle-dot'" :size="14" />{{ rule.label }}<span class="sr-only">{{ rule.met ? ' (met)' : ' (not met yet)' }}</span></li>
              </ul>
            </div>

            <div class="pf-form__foot">
              <button type="submit" class="my-btn my-btn--primary" :disabled="passwordSaving" :aria-busy="passwordSaving"><span v-if="passwordSaving" class="pf-spinner" aria-hidden="true"></span>{{ passwordSaving ? 'Updating…' : 'Update password' }}</button>
            </div>
          </form>
        </section>

        <!-- Account details (read-only) -->
        <section class="pf-card" aria-labelledby="pf-details-title">
          <header class="pf-card__head">
            <span class="pf-card__icon is-muted" aria-hidden="true"><AppIcon name="user" :size="18" /></span>
            <div>
              <h2 id="pf-details-title">Account details <span class="pf-readonly"><AppIcon name="lock" :size="12" />Read-only</span></h2>
              <p>{{ detailsHelp }}</p>
            </div>
          </header>
          <dl class="pf-details">
            <div v-for="item in details" :key="item.label">
              <dt><AppIcon :name="item.icon" :size="15" />{{ item.label }}</dt>
              <dd :class="{ 'is-empty': !item.value }">{{ item.value || 'Not added' }}</dd>
              <dd v-if="item.note && item.value" class="pf-details__note">{{ item.note }}</dd>
            </div>
          </dl>
          <RouterLink v-if="!isStaff" class="my-btn pf-details__link" to="/me"><AppIcon name="ticket" :size="15" />Go to My information</RouterLink>
        </section>
      </div>
    </section>
  </component>
</template>
