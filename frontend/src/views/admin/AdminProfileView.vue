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
              <div><h2>Personal information</h2><p>Keep your account details up to date.</p></div>
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
            <div class="profile-card-heading"><div><h2>Security</h2><p>Change your password regularly to keep your account secure.</p></div></div>
            <form class="profile-password-form" @submit.prevent="changePassword">
              <label>Current password<input v-model="password.current_password" type="password" autocomplete="current-password" required /></label>
              <label>New password<input v-model="password.new_password" type="password" minlength="8" autocomplete="new-password" required /><small>Use at least 8 characters.</small></label>
              <label>Confirm new password<input v-model="password.new_password_confirmation" type="password" minlength="8" autocomplete="new-password" required /></label>
              <div class="profile-card-actions"><button type="submit" class="button button-secondary" :disabled="changingPassword">{{ changingPassword ? 'Changing…' : 'Change password' }}</button></div>
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
