<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import UserLayout from '../../layouts/UserLayout.vue'
import { useAuthStore } from '../../stores/auth'
import api from '../../services/api'

const auth = useAuthStore()
const isStaff = computed(() => auth.canAccessAdmin)
const roleLabel = computed(() => auth.roleLabel)
const saving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')
const selectedPhoto = ref(null)
const photoPreview = ref('')
const serverPhotoSrc = ref('')
const profilePhotoBroken = ref(false)
let photoRequest = 0

const initials = computed(() => (auth.user?.name || 'U').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'U')
const displayPhoto = computed(() => selectedPhoto.value ? photoPreview.value : serverPhotoSrc.value)
const createdDate = computed(() => auth.user?.created_at ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(auth.user.created_at)) : 'Not available')

const loadServerPhoto = async () => {
  const requestId = ++photoRequest
  if (!auth.user?.profile_photo) { serverPhotoSrc.value = ''; return }
  try {
    const { data } = await api.get('/me/profile-photo', { responseType: 'blob' })
    if (requestId !== photoRequest) return
    serverPhotoSrc.value = auth.setProfilePhotoPreview(data)
    profilePhotoBroken.value = false
  } catch {
    if (requestId === photoRequest) serverPhotoSrc.value = ''
  }
}

onMounted(loadServerPhoto)
watch(() => auth.user?.profile_photo, loadServerPhoto)

const clearMessages = () => { successMessage.value = ''; errorMessage.value = '' }
const choosePhoto = (event) => {
  clearMessages()
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { errorMessage.value = 'Please choose a JPG, PNG, or WEBP image.'; return }
  if (file.size > 5 * 1024 * 1024) { errorMessage.value = 'The image must be 5 MB or smaller.'; return }
  if (photoPreview.value?.startsWith('blob:')) URL.revokeObjectURL(photoPreview.value)
  selectedPhoto.value = file
  photoPreview.value = URL.createObjectURL(file)
  profilePhotoBroken.value = false
}
const cancelPhoto = () => {
  if (photoPreview.value?.startsWith('blob:')) URL.revokeObjectURL(photoPreview.value)
  selectedPhoto.value = null
  photoPreview.value = ''
  profilePhotoBroken.value = false
  clearMessages()
}
const savePhoto = async () => {
  if (!selectedPhoto.value) return
  clearMessages()
  saving.value = true
  try {
    const payload = new FormData()
    payload.append('profile_photo', selectedPhoto.value)
    await auth.updateProfile(payload)
    await auth.fetchMe()
    cancelPhoto()
    successMessage.value = 'Profile photo updated successfully.'
  } catch (error) {
    const validationErrors = error.response?.data?.errors
    errorMessage.value = validationErrors ? Object.values(validationErrors).flat()[0] : error.response?.data?.message || 'Unable to upload your profile photo.'
  } finally { saving.value = false }
}
</script>

<template>
  <component :is="isStaff ? AdminLayout : UserLayout">
    <section class="profile-settings-page">
      <header class="profile-settings-header"><div><p class="admin-eyebrow">Account settings</p><h1>Profile settings</h1><p>Review your account details and update your profile photo.</p></div></header>
      <div v-if="successMessage" class="profile-alert profile-alert-success" role="status">{{ successMessage }}</div>
      <div v-if="errorMessage" class="profile-alert profile-alert-error" role="alert">{{ errorMessage }}</div>

      <div class="profile-settings-grid">
        <div class="profile-settings-main">
          <section class="profile-settings-card">
            <div class="profile-card-heading"><div><h2>Personal information</h2><p>Your account information is managed by the system.</p></div></div>
            <div class="profile-photo-editor">
              <div class="profile-photo-large"><img v-if="displayPhoto && !profilePhotoBroken" :src="displayPhoto" alt="Current profile photo" @error="profilePhotoBroken = true" /><span v-else>{{ initials }}</span></div>
              <div class="profile-photo-copy"><strong>Profile photo</strong><p>JPG, PNG, or WEBP up to 5 MB.</p><label class="button button-ghost profile-photo-button">{{ selectedPhoto ? 'Choose another photo' : 'Change photo' }}<input type="file" accept="image/jpeg,image/png,image/webp" @change="choosePhoto" /></label><div v-if="selectedPhoto" class="profile-photo-actions"><button type="button" class="button button-primary" :disabled="saving" @click="savePhoto">{{ saving ? 'Uploading…' : 'Save photo' }}</button><button type="button" class="button button-secondary" :disabled="saving" @click="cancelPhoto">Cancel</button></div></div>
            </div>
            <div class="profile-form-grid profile-readonly-grid">
              <label>Full name<input :value="auth.user?.name || ''" type="text" readonly aria-readonly="true" /></label>
              <label>Email address<input :value="auth.user?.email || ''" type="email" readonly aria-readonly="true" /></label>
              <label>Phone number<input :value="auth.user?.phone || ''" type="tel" readonly aria-readonly="true" /></label>
              <label>Username<input :value="auth.user?.username || ''" type="text" readonly aria-readonly="true" /></label>
            </div>
          </section>
        </div>
        <aside class="profile-account-card"><div class="profile-account-icon">✓</div><p class="admin-eyebrow">Account information</p><h2>{{ auth.user?.name || 'User account' }}</h2><dl><div><dt>Role</dt><dd><span class="profile-role-badge">{{ roleLabel }}</span></dd></div><div><dt>Account created</dt><dd>{{ createdDate }}</dd></div></dl><p class="profile-account-note">Your name, email, username, role, permissions, and account creation date are read-only.</p></aside>
      </div>
    </section>
  </component>
</template>
