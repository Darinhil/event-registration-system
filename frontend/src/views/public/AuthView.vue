<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import UserLayout from '../../layouts/UserLayout.vue'
import logo from '../../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const isSignup = computed(() => route.path === '/account/register')
const forgotOpen = ref(false)
const redirectPath = computed(() => typeof route.query.redirect === 'string' ? route.query.redirect : '')
const rememberedIdentifier = localStorage.getItem('event_remembered_identifier') || localStorage.getItem('event_remembered_email') || ''
const loginForm = reactive({ identifier: rememberedIdentifier, password: '' })
const rememberMe = ref(Boolean(rememberedIdentifier))
const loginPasswordVisible = ref(false)
const loginValidationError = ref('')
const signupForm = reactive({ name: '', identifier: '', password: '', password_confirmation: '' })
const signupPasswordVisible = ref(false)
const signupConfirmVisible = ref(false)
const passwordsMatch = computed(() => !signupForm.password_confirmation || signupForm.password === signupForm.password_confirmation)

const clearLoginError = () => {
  loginValidationError.value = ''
  auth.error = null
}

const validateLogin = () => {
  const identifier = loginForm.identifier.trim()
  const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier)
  const isPhone = /^\+?[0-9\s().-]{7,}$/.test(identifier)

  if (!identifier) {
    loginValidationError.value = 'Enter your email or phone number.'
    return false
  }
  if (!isEmail && !isPhone) {
    loginValidationError.value = 'Enter a valid email or phone number.'
    return false
  }
  if (!loginForm.password) {
    loginValidationError.value = 'Enter your password.'
    return false
  }
  loginForm.identifier = identifier
  loginValidationError.value = ''
  return true
}

const login = async () => {
  if (!validateLogin()) return
  try {
    await auth.login(loginForm)
    if (rememberMe.value) localStorage.setItem('event_remembered_identifier', loginForm.identifier)
    else localStorage.removeItem('event_remembered_identifier')
    const requestedPath = redirectPath.value
    router.push(auth.canAccessAdmin ? (requestedPath.startsWith('/admin') ? requestedPath : '/admin') : (requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/me'))
  } catch { /* displayed in the form */ }
}
const signup = async () => {
  if (signupForm.password !== signupForm.password_confirmation) { auth.error = 'Passwords do not match.'; return }
  try {
    await auth.registerAccount({ ...signupForm, name: signupForm.name || signupForm.identifier.split('@')[0] })
    const requestedPath = redirectPath.value
    router.push(requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/me')
  } catch { /* displayed in the form */ }
}
</script>

<template>
  <UserLayout>
    <section class="auth-page">
      <div class="auth-cards">
        <section v-if="!isSignup" class="auth-card auth-card-login" aria-labelledby="auth-title">
          <div class="auth-form-panel">
            <h1 id="auth-title" class="sr-only">Sign in to your account</h1>
            <div class="auth-tabs-row">
              <img class="auth-signup-logo" :src="logo" alt="Live &amp; Learn Cambodia" />
              <div class="auth-tabs" role="tablist">
                <RouterLink class="auth-tab" :class="{ 'is-active': !isSignup }" :to="{ path: '/login', query: redirectPath ? { redirect: redirectPath } : undefined }" role="tab" :aria-selected="!isSignup">Login</RouterLink>
                <RouterLink class="auth-tab" :class="{ 'is-active': isSignup }" :to="{ path: '/account/register', query: redirectPath ? { redirect: redirectPath } : undefined }" role="tab" :aria-selected="isSignup">Sign up</RouterLink>
              </div>
            </div>
            <div class="auth-panel-body">
            <form class="auth-form" :aria-busy="auth.loading" @submit.prevent="login">
              <label for="login-identifier">Email or Phone<input id="login-identifier" v-model="loginForm.identifier" type="text" placeholder="Enter your email or phone" autocomplete="username" :aria-invalid="Boolean(loginValidationError || auth.error)" :aria-describedby="loginValidationError || auth.error ? 'login-error' : undefined" required @input="clearLoginError" /></label>
              <label for="login-password">Password<div class="password-field"><input id="login-password" v-model="loginForm.password" :type="loginPasswordVisible ? 'text' : 'password'" placeholder="Enter your password" autocomplete="current-password" :aria-invalid="Boolean(loginValidationError || auth.error)" :aria-describedby="loginValidationError || auth.error ? 'login-error' : undefined" required @input="clearLoginError" /><button type="button" class="password-toggle" :aria-label="loginPasswordVisible ? 'Hide password' : 'Show password'" @click="loginPasswordVisible = !loginPasswordVisible"><svg v-if="loginPasswordVisible" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg></button></div></label>
              <div class="login-options"><label class="remember-option" for="remember-me"><input id="remember-me" v-model="rememberMe" type="checkbox" name="remember_me" /> <span>Remember me</span></label><button type="button" class="forgot-link" :aria-expanded="forgotOpen" aria-controls="forgot-help" @click="forgotOpen = !forgotOpen">Forgot password?</button></div>
              <p v-if="forgotOpen" id="forgot-help" class="auth-hint" role="status">Password resets aren’t available online yet. Ask your event organizer or administrator to reset it for you.</p>
              <p v-if="(loginValidationError || auth.error) && !auth.loading" id="login-error" class="auth-error" role="alert">{{ loginValidationError || auth.error }}</p>
              <button type="submit" :disabled="auth.loading">{{ auth.loading ? 'Signing in...' : 'Sign in' }}</button>
            </form>
            <p class="auth-switch">Don't have an account? <RouterLink :to="{ path: '/account/register', query: redirectPath ? { redirect: redirectPath } : undefined }">Sign up for free!</RouterLink></p>
            </div>
          </div>
        </section>

        <section v-else class="auth-card auth-card-signup" aria-labelledby="auth-title">
          <div class="auth-form-panel">
            <h1 id="auth-title" class="sr-only">Create your account</h1>
            <div class="auth-tabs-row">
              <img class="auth-signup-logo" :src="logo" alt="Live &amp; Learn Cambodia" />
              <div class="auth-tabs" role="tablist">
                <RouterLink class="auth-tab" :class="{ 'is-active': !isSignup }" :to="{ path: '/login', query: redirectPath ? { redirect: redirectPath } : undefined }" role="tab" :aria-selected="!isSignup">Login</RouterLink>
                <RouterLink class="auth-tab" :class="{ 'is-active': isSignup }" :to="{ path: '/account/register', query: redirectPath ? { redirect: redirectPath } : undefined }" role="tab" :aria-selected="isSignup">Sign up</RouterLink>
              </div>
            </div>
            <div class="auth-panel-body">
            <form class="auth-form" @submit.prevent="signup">
              <label>Email or Phone<input v-model="signupForm.identifier" type="text" placeholder="Email or Phone" autocomplete="username" required /></label>
              <label>Password<div class="password-field"><input v-model="signupForm.password" :type="signupPasswordVisible ? 'text' : 'password'" placeholder="Create password" minlength="8" autocomplete="new-password" required /><button type="button" class="password-toggle" :aria-label="signupPasswordVisible ? 'Hide password' : 'Show password'" @click="signupPasswordVisible = !signupPasswordVisible"><svg v-if="signupPasswordVisible" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg></button></div></label>
              <label>Confirm password<div class="password-field" :class="{ 'has-error': !passwordsMatch }"><input v-model="signupForm.password_confirmation" :type="signupConfirmVisible ? 'text' : 'password'" placeholder="Confirm password" minlength="8" autocomplete="new-password" required /><button type="button" class="password-toggle" :aria-label="signupConfirmVisible ? 'Hide password' : 'Show password'" @click="signupConfirmVisible = !signupConfirmVisible"><svg v-if="signupConfirmVisible" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg></button></div></label>
              <small v-if="!passwordsMatch" class="password-match-error">Passwords do not match.</small>
              <p v-if="auth.error && !auth.loading" class="auth-error" role="alert">{{ auth.error }}</p>
              <button type="submit" :disabled="auth.loading">{{ auth.loading ? 'Creating account...' : 'Create account' }}</button>
            </form>
            <p class="auth-switch">Already have an account? <RouterLink :to="{ path: '/login', query: redirectPath ? { redirect: redirectPath } : undefined }">Login</RouterLink></p>
            </div>
          </div>
        </section>
      </div>
    </section>
  </UserLayout>
</template>
