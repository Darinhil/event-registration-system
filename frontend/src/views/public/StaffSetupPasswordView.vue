<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../services/api'
import logo from '../../assets/LLEE_Cambodia_Inverse_L.png'

const route = useRoute()
const router = useRouter()
const email = computed(() => typeof route.query.email === 'string' ? route.query.email : '')
const token = computed(() => typeof route.query.token === 'string' ? route.query.token : '')
const form = reactive({ password: '', password_confirmation: '' })
const busy = ref(false)
const error = ref('')
const complete = ref(false)

const submit = async () => {
  if (busy.value) return
  error.value = ''
  if (!token.value || !email.value) {
    error.value = 'This setup link is incomplete. Ask your administrator to send another invitation.'
    return
  }
  if (form.password.length < 8) {
    error.value = 'Use at least 8 characters for your password.'
    return
  }
  if (form.password !== form.password_confirmation) {
    error.value = 'The passwords do not match.'
    return
  }

  busy.value = true
  try {
    await api.post('/auth/set-password', {
      token: token.value,
      email: email.value,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })
    complete.value = true
  } catch (requestError) {
    error.value = Object.values(requestError.response?.data?.errors || {}).flat()[0]
      || requestError.response?.data?.message
      || 'We could not set your password. Please try again or request a new invitation.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="setup-page">
    <section class="setup-card" aria-labelledby="setup-title">
      <img class="setup-logo" :src="logo" alt="Live & Learn Cambodia" />
      <template v-if="complete">
        <h1 id="setup-title">Password set</h1>
        <p>Your staff account is ready. Sign in with your email address and new password.</p>
        <button type="button" class="setup-button" @click="router.push('/login')">Go to sign in</button>
      </template>
      <template v-else>
        <h1 id="setup-title">Set up your staff account</h1>
        <p>Create a password for <strong>{{ email || 'your staff account' }}</strong>.</p>
        <form class="setup-form" @submit.prevent="submit">
          <label for="setup-password">Password</label>
          <input id="setup-password" v-model="form.password" type="password" minlength="8" autocomplete="new-password" required />
          <label for="setup-password-confirmation">Confirm password</label>
          <input id="setup-password-confirmation" v-model="form.password_confirmation" type="password" minlength="8" autocomplete="new-password" required />
          <p v-if="error" class="setup-error" role="alert">{{ error }}</p>
          <button class="setup-button" type="submit" :disabled="busy">{{ busy ? 'Saving password…' : 'Set password' }}</button>
        </form>
      </template>
    </section>
  </main>
</template>

<style scoped>
.setup-page { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7fa; color: #172b4d; font-family: inherit; }
.setup-card { width: min(100%, 440px); padding: 32px; border: 1px solid #e3e9f0; border-radius: 16px; background: #fff; box-shadow: 0 12px 36px rgba(15, 28, 46, .08); }
.setup-logo { display: block; width: 180px; max-height: 62px; object-fit: contain; object-position: left center; margin-bottom: 28px; }
.setup-card h1 { margin: 0; font-size: 1.55rem; }
.setup-card > p { margin: 9px 0 22px; color: #5f7286; line-height: 1.5; }
.setup-form { display: grid; gap: 9px; }
.setup-form label { margin-top: 8px; font-size: .88rem; font-weight: 650; }
.setup-form input { width: 100%; height: 46px; box-sizing: border-box; padding: 0 13px; border: 1px solid #cfd9e3; border-radius: 9px; font: inherit; }
.setup-form input:focus { border-color: #0b7f62; outline: 3px solid rgba(11, 127, 98, .15); }
.setup-button { min-height: 46px; margin-top: 12px; border: 0; border-radius: 9px; background: #0b7f62; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.setup-button:disabled { opacity: .65; cursor: wait; }
.setup-error { margin: 6px 0 0; color: #b42318; font-size: .88rem; }
</style>
