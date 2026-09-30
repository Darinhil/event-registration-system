<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { useAuthStore } from '../../stores/auth'
import api from '../../services/api'

const auth = useAuthStore()
const editing = ref(false)
const saving = ref(false)
const changingPassword = ref(false)
const successMessage = ref('')
const errorMessage = ref('')
const photoPreview = ref(auth.user?.profile_photo || '')
const selectedPhoto = ref(null)
const profilePhotoBroken = ref(false)
const serverPhotoSrc = ref('')

const profile = reactive({
  name: auth.user?.name || '',
  email: auth.user?.email || '',
  phone: auth.user?.phone || '',
  username: auth.user?.username || '',
})

const password = reactive({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
})

const showPassword = reactive({ current: false, new: false, confirm: false })

const passwordChecks = computed(() => ({
  length: password.new_password.length >= 8,
  match: password.new_password.length > 0 && password.new_password === password.new_password_confirmation,
}))

const confirmMismatch = computed(() => password.new_password_confirmation.length > 0 && !passwordChecks.value.match)

const passwordStrength = computed(() => {
  const pw = password.new_password
  if (!pw) return null
  let score = 0
  if (pw.length >= 8) score += 1
  if (pw.length >= 12) score += 1
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score += 1
  if (/\d/.test(pw)) score += 1
  if (/[^A-Za-z0-9]/.test(pw)) score += 1
  if (score <= 2) return { label: 'Weak', value: 30, color: '#e46a77' }
  if (score <= 3) return { label: 'Fair', value: 55, color: '#e9a23b' }
  if (score <= 4) return { label: 'Good', value: 80, color: '#3fb984' }
  return { label: 'Strong', value: 100, color: '#0d9488' }
})

const initials = computed(() => (profile.name || 'AD').slice(0, 2).toUpperCase())
const displayPhoto = computed(() => selectedPhoto.value ? photoPreview.value : serverPhotoSrc.value)
const createdDate = computed(() => {
  if (!auth.user?.created_at) return 'Not available'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(auth.user.created_at))
})

watch(photoPreview, () => { profilePhotoBroken.value = false })
const loadServerPhoto = async () => {
  if (!auth.user?.profile_photo) {
    serverPhotoSrc.value = ''
    return
  }
  try {
    const { data } = await api.get('/me/profile-photo', { responseType: 'blob' })
    if (serverPhotoSrc.value.startsWith('blob:')) URL.revokeObjectURL(serverPhotoSrc.value)
    serverPhotoSrc.value = URL.createObjectURL(data)
    profilePhotoBroken.value = false
  } catch {
    serverPhotoSrc.value = ''
  }
}
onMounted(loadServerPhoto)
watch(() => auth.user?.profile_photo, loadServerPhoto)
onBeforeUnmount(() => { if (serverPhotoSrc.value.startsWith('blob:')) URL.revokeObjectURL(serverPhotoSrc.value) })

const clearMessages = () => {
  successMessage.value = ''
  errorMessage.value = ''
}

const startEditing = () => {
  clearMessages()
  editing.value = true
}

const cancelEditing = () => {
  profile.name = auth.user?.name || ''
  profile.email = auth.user?.email || ''
  profile.phone = auth.user?.phone || ''
  profile.username = auth.user?.username || ''
  photoPreview.value = auth.user?.profile_photo || ''
  profilePhotoBroken.value = false
  selectedPhoto.value = null
  editing.value = false
}

const choosePhoto = (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  if (!file.type.startsWith('image/')) {
    errorMessage.value = 'Please choose an image file.'
    return
  }
  selectedPhoto.value = file
  profilePhotoBroken.value = false
  photoPreview.value = URL.createObjectURL(file)
}

const saveProfile = async () => {
  clearMessages()
  if (!profile.name.trim() || !profile.email.trim()) {
    errorMessage.value = 'Full name and email are required.'
    return
  }
  saving.value = true
  try {
    const payload = new FormData()
    Object.entries(profile).forEach(([key, value]) => payload.append(key, value || ''))
    if (selectedPhoto.value) payload.append('profile_photo', selectedPhoto.value)
    await auth.updateProfile(payload)
    // Reload the persisted profile so the UI uses the server's stored photo URL.
    await auth.fetchMe()
    photoPreview.value = auth.user?.profile_photo || photoPreview.value
    selectedPhoto.value = null
    editing.value = false
    successMessage.value = 'Profile changes saved successfully.'
  } catch (error) {
    const validationErrors = error.response?.data?.errors
    errorMessage.value = validationErrors
      ? Object.values(validationErrors).flat()[0]
      : error.response?.data?.message || 'Unable to save profile changes.'
  } finally {
    saving.value = false
  }
}

const changePassword = async () => {
  clearMessages()
  if (password.new_password.length < 8) {
    errorMessage.value = 'Your new password must be at least 8 characters.'
    return
  }
  if (password.new_password !== password.new_password_confirmation) {
    errorMessage.value = 'The new password and confirmation do not match.'
    return
  }
  if (!window.confirm('Change your password now? You will need the new password next time you sign in.')) return

  changingPassword.value = true
  try {
    await auth.updatePassword(password)
    password.current_password = ''
    password.new_password = ''
    password.new_password_confirmation = ''
    successMessage.value = 'Password changed successfully.'
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Unable to change password.'
  } finally {
    changingPassword.value = false
  }
}
</script>

<template>
  <AdminLayout>
    <section class="profile-settings-page">
      <header class="profile-settings-header">
        <div>
          <p class="admin-eyebrow">Account settings</p>
          <h1>Profile settings</h1>
          <p>Manage your personal information and account security.</p>
        </div>
      </header>

      <div v-if="successMessage" class="profile-alert profile-alert-success" role="status">{{ successMessage }}</div>
      <div v-if="errorMessage" class="profile-alert profile-alert-error" role="alert">{{ errorMessage }}</div>

      <div class="profile-settings-grid">
        <div class="profile-settings-main">
          <section class="profile-settings-card">
            <div class="profile-card-heading">
              <div><span class="profile-card-icon">👤</span><div><h2>Personal information</h2><p>Keep your account details up to date.</p></div></div>
              <button v-if="!editing" type="button" class="button button-secondary" @click="startEditing">Edit profile</button>
            </div>

            <div class="profile-photo-editor">
              <div class="profile-photo-large">
                <img v-if="displayPhoto && !profilePhotoBroken" :src="displayPhoto" alt="" @error="profilePhotoBroken = true" />
                <span v-else>{{ initials }}</span>
              </div>
              <div>
                <strong>Profile photo</strong>
                <p>JPG, PNG, or WEBP up to 5 MB.</p>
                <label class="button button-ghost profile-photo-button">
                  {{ editing ? 'Choose photo' : 'Change photo' }}
                  <input type="file" accept="image/jpeg,image/png,image/webp" @click="editing = true" @change="choosePhoto" />
                </label>
              </div>
            </div>

            <div class="profile-form-grid">
              <label>Full name <span>*</span><input v-model="profile.name" :disabled="!editing" type="text" autocomplete="name" /><small v-if="editing">Use the name your team will recognize.</small></label>
              <label>Email address <span>*</span><input v-model="profile.email" :disabled="!editing" type="email" autocomplete="email" /></label>
              <label>Phone number<input v-model="profile.phone" :disabled="!editing" type="tel" autocomplete="tel" /></label>
              <label>Username<input v-model="profile.username" :disabled="!editing" type="text" autocomplete="username" /></label>
            </div>

            <div v-if="editing" class="profile-card-actions">
              <button type="button" class="button button-secondary" @click="cancelEditing">Cancel</button>
              <button type="button" class="button button-primary" :disabled="saving" @click="saveProfile">{{ saving ? 'Saving…' : 'Save changes' }}</button>
            </div>
          </section>

          <section class="profile-settings-card">
            <div class="profile-card-heading"><div><span class="profile-card-icon">🔒</span><div><h2>Security</h2><p>Change your password regularly to keep your account secure.</p></div></div></div>
            <form class="profile-password-form" @submit.prevent="changePassword">
              <label>Current password
                <span class="password-field">
                  <input v-model="password.current_password" :type="showPassword.current ? 'text' : 'password'" autocomplete="current-password" required />
                  <button type="button" class="password-toggle" :aria-label="showPassword.current ? 'Hide current password' : 'Show current password'" @click="showPassword.current = !showPassword.current">
                    <svg v-if="showPassword.current" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg>
                  </button>
                </span>
              </label>
              <label>New password
                <span class="password-field">
                  <input v-model="password.new_password" :type="showPassword.new ? 'text' : 'password'" minlength="8" autocomplete="new-password" required />
                  <button type="button" class="password-toggle" :aria-label="showPassword.new ? 'Hide new password' : 'Show new password'" @click="showPassword.new = !showPassword.new">
                    <svg v-if="showPassword.new" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg>
                  </button>
                </span>
                <span v-if="passwordStrength" class="password-strength">
                  <span class="password-strength-track"><i :style="{ width: `${passwordStrength.value}%`, background: passwordStrength.color }"></i></span>
                  <small :style="{ color: passwordStrength.color }">{{ passwordStrength.label }}</small>
                </span>
              </label>
              <label>Confirm new password
                <span class="password-field" :class="{ 'has-error': confirmMismatch }">
                  <input v-model="password.new_password_confirmation" :type="showPassword.confirm ? 'text' : 'password'" minlength="8" autocomplete="new-password" required />
                  <button type="button" class="password-toggle" :aria-label="showPassword.confirm ? 'Hide password confirmation' : 'Show password confirmation'" @click="showPassword.confirm = !showPassword.confirm">
                    <svg v-if="showPassword.confirm" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg>
                  </button>
                </span>
              </label>
              <ul class="password-requirements" aria-label="Password requirements">
                <li :class="{ ok: passwordChecks.length }">At least 8 characters</li>
                <li :class="{ ok: passwordChecks.match }">Both new passwords match</li>
              </ul>
              <div class="profile-card-actions">
                <p class="password-actions-note"><span>🔐</span> You'll stay signed in after changing your password.</p>
                <button type="submit" class="button button-primary" :disabled="changingPassword">{{ changingPassword ? 'Changing…' : 'Change password' }}</button>
              </div>
            </form>
          </section>
        </div>

        <aside class="profile-account-card">
          <div class="profile-account-icon">✓</div>
          <p class="admin-eyebrow">Account information</p>
          <h2>{{ profile.name || 'Admin account' }}</h2>
          <dl><div><dt>Role</dt><dd><span class="profile-role-badge">Admin</span></dd></div><div><dt>Account created</dt><dd>{{ createdDate }}</dd></div></dl>
          <p class="profile-account-note">Your role and permissions are managed by the system and cannot be changed here.</p>
        </aside>
      </div>
    </section>
  </AdminLayout>
</template>
