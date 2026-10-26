<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import UserLayout from '../../layouts/UserLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import logo from '../../assets/LLEE_Cambodia_Inverse_L.png'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const isSignup = computed(() => route.path === '/account/register')
const forgotOpen = ref(false)
const redirectPath = computed(() => typeof route.query.redirect === 'string' ? route.query.redirect : '')
const keepRedirect = computed(() => (redirectPath.value ? { redirect: redirectPath.value } : undefined))

// Same formats the server accepts (AuthController): an email, or a phone number.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_RE = /^[+]?[0-9][0-9\s().-]{7,24}$/
const isEmailOrPhone = (value) => EMAIL_RE.test(value) || PHONE_RE.test(value)
const focus = (id) => nextTick(() => document.getElementById(id)?.focus())

/* ---------- Login ---------- */
const rememberedIdentifier = localStorage.getItem('event_remembered_identifier') || localStorage.getItem('event_remembered_email') || ''
const loginForm = reactive({ identifier: rememberedIdentifier, password: '' })
const rememberMe = ref(Boolean(rememberedIdentifier))
const loginPasswordVisible = ref(false)
const loginErrors = reactive({})
const loginFormError = ref('')

const validateLogin = () => {
  Object.keys(loginErrors).forEach((key) => delete loginErrors[key])
  const identifier = loginForm.identifier.trim()
  if (!identifier) loginErrors.identifier = 'Enter your email or phone number.'
  else if (!isEmailOrPhone(identifier)) loginErrors.identifier = 'Enter a valid email or phone number.'
  if (!loginForm.password) loginErrors.password = 'Enter your password.'
  if (loginErrors.identifier) { focus('login-identifier'); return false }
  if (loginErrors.password) { focus('login-password'); return false }
  loginForm.identifier = identifier
  return true
}

const login = async () => {
  if (auth.loading) return
  loginFormError.value = ''
  if (!validateLogin()) return
  try {
    await auth.login(loginForm)
    if (rememberMe.value) localStorage.setItem('event_remembered_identifier', loginForm.identifier)
    else localStorage.removeItem('event_remembered_identifier')
    const requestedPath = redirectPath.value
    router.push(auth.canAccessAdmin ? (requestedPath.startsWith('/admin') ? requestedPath : '/admin') : (requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/me'))
  } catch (error) {
    loginFormError.value = !error.response
      ? 'We couldn’t reach the server. Check your connection and try again.'
      : /invalid credentials/i.test(auth.error || '') ? 'That email or phone and password don’t match an account.' : (auth.error || 'Unable to sign in.')
    focus('login-password')
  }
}

/* ---------- Sign up ---------- */
const signupForm = reactive({ name: '', identifier: '', password: '', password_confirmation: '' })
const signupPasswordVisible = ref(false)
const signupErrors = reactive({})
const signupFormError = ref('')
const passwordLongEnough = computed(() => signupForm.password.length >= 8)
const passwordsMatch = computed(() => !signupForm.password_confirmation || signupForm.password === signupForm.password_confirmation)

const validateSignup = () => {
  Object.keys(signupErrors).forEach((key) => delete signupErrors[key])
  const identifier = signupForm.identifier.trim()
  if (!signupForm.name.trim()) signupErrors.name = 'Enter your full name.'
  if (!identifier) signupErrors.identifier = 'Enter your email or phone number.'
  else if (!isEmailOrPhone(identifier)) signupErrors.identifier = 'Enter a valid email or phone number.'
  if (!passwordLongEnough.value) signupErrors.password = 'Use at least 8 characters.'
  if (!signupForm.password_confirmation) signupErrors.password_confirmation = 'Re-enter your password.'
  else if (!passwordsMatch.value) signupErrors.password_confirmation = 'The passwords don’t match.'
  const first = ['name', 'identifier', 'password', 'password_confirmation'].find((key) => signupErrors[key])
  if (first) { focus(`signup-${first}`); return false }
  signupForm.identifier = identifier
  return true
}

const signup = async () => {
  if (auth.loading) return
  signupFormError.value = ''
  if (!validateSignup()) return
  try {
    await auth.registerAccount({ ...signupForm, name: signupForm.name.trim() })
    const requestedPath = redirectPath.value
    router.push(requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/me')
  } catch (error) {
    const errors = error.response?.data?.errors || {}
    const identifierError = [errors.identifier].flat()[0]
    if (identifierError) {
      signupErrors.identifier = /taken/i.test(identifierError) ? 'An account already uses this email or phone. Sign in instead.' : identifierError
      focus('signup-identifier')
    } else if (errors.name) {
      signupErrors.name = [errors.name].flat()[0]; focus('signup-name')
    } else if (errors.password) {
      signupErrors.password = [errors.password].flat()[0]; focus('signup-password')
    } else {
      signupFormError.value = !error.response ? 'We couldn’t reach the server. Check your connection and try again.' : (auth.error || 'Unable to create your account.')
    }
  }
}

// Switching between Login and Sign up starts clean (errors used to carry over).
watch(isSignup, () => {
  auth.error = null
  loginFormError.value = ''; signupFormError.value = ''; forgotOpen.value = false
  Object.keys(loginErrors).forEach((key) => delete loginErrors[key])
  Object.keys(signupErrors).forEach((key) => delete signupErrors[key])
})
const clearLogin = (key) => { delete loginErrors[key]; loginFormError.value = '' }
const clearSignup = (key) => { delete signupErrors[key]; signupFormError.value = '' }
</script>

<template>
  <UserLayout>
    <section class="au-page">
      <div class="au-wrap">
        <RouterLink class="au-home" to="/"><AppIcon name="chevron-left" :size="16" />Back to home</RouterLink>

        <article class="au-card" aria-labelledby="au-title">
          <img class="au-logo" :src="logo" alt="Live &amp; Learn Cambodia" />

          <nav class="au-switch" aria-label="Account">
            <RouterLink :to="{ path: '/login', query: keepRedirect }" :class="{ 'is-active': !isSignup }" :aria-current="!isSignup ? 'page' : undefined">Log in</RouterLink>
            <RouterLink :to="{ path: '/account/register', query: keepRedirect }" :class="{ 'is-active': isSignup }" :aria-current="isSignup ? 'page' : undefined">Sign up</RouterLink>
          </nav>

          <!-- Log in -->
          <template v-if="!isSignup">
            <header class="au-head">
              <h1 id="au-title">Welcome back</h1>
              <p>Sign in to see your registrations and event passes.</p>
            </header>
            <div v-if="loginFormError" class="au-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ loginFormError }}</span></div>
            <form class="au-form" novalidate :aria-busy="auth.loading" @submit.prevent="login">
              <div class="au-field">
                <label for="login-identifier">Email or phone</label>
                <div class="au-input" :class="{ 'is-invalid': loginErrors.identifier }">
                  <AppIcon name="mail" :size="18" />
                  <input id="login-identifier" v-model="loginForm.identifier" type="text" autocomplete="username" placeholder="Email or phone number" :aria-invalid="loginErrors.identifier ? 'true' : undefined" :aria-describedby="loginErrors.identifier ? 'login-identifier-error' : undefined" @input="clearLogin('identifier')" />
                </div>
                <p v-if="loginErrors.identifier" id="login-identifier-error" class="au-error"><AppIcon name="alert-circle" :size="14" />{{ loginErrors.identifier }}</p>
              </div>
              <div class="au-field">
                <label for="login-password">Password</label>
                <div class="au-input" :class="{ 'is-invalid': loginErrors.password }">
                  <AppIcon name="lock" :size="18" />
                  <input id="login-password" v-model="loginForm.password" :type="loginPasswordVisible ? 'text' : 'password'" autocomplete="current-password" placeholder="Your password" :aria-invalid="loginErrors.password ? 'true' : undefined" :aria-describedby="loginErrors.password ? 'login-password-error' : undefined" @input="clearLogin('password')" />
                  <button type="button" class="au-reveal" :aria-pressed="loginPasswordVisible" :aria-label="loginPasswordVisible ? 'Hide password' : 'Show password'" @click="loginPasswordVisible = !loginPasswordVisible"><AppIcon :name="loginPasswordVisible ? 'eye' : 'eye-off'" :size="18" /></button>
                </div>
                <p v-if="loginErrors.password" id="login-password-error" class="au-error"><AppIcon name="alert-circle" :size="14" />{{ loginErrors.password }}</p>
              </div>
              <div class="au-row">
                <label class="au-check" for="remember-me"><input id="remember-me" v-model="rememberMe" type="checkbox" name="remember_me" />Remember me</label>
                <button type="button" class="au-link" :aria-expanded="forgotOpen" aria-controls="forgot-help" @click="forgotOpen = !forgotOpen">Forgot password?</button>
              </div>
              <p v-if="forgotOpen" id="forgot-help" class="au-note" role="status"><AppIcon name="info" :size="16" />Password resets aren’t available online yet. Ask your event organizer or administrator to reset it for you.</p>
              <button type="submit" class="au-submit" :disabled="auth.loading"><span v-if="auth.loading" class="au-spinner" aria-hidden="true"></span>{{ auth.loading ? 'Signing in…' : 'Sign in' }}</button>
            </form>
            <p class="au-foot">Don’t have an account? <RouterLink :to="{ path: '/account/register', query: keepRedirect }">Create one — it’s free</RouterLink></p>
          </template>

          <!-- Sign up -->
          <template v-else>
            <header class="au-head">
              <h1 id="au-title">Create your account</h1>
              <p>Register for events and keep all your passes in one place.</p>
            </header>
            <div v-if="signupFormError" class="au-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ signupFormError }}</span></div>
            <form class="au-form" novalidate :aria-busy="auth.loading" @submit.prevent="signup">
              <div class="au-field">
                <label for="signup-name">Full name</label>
                <div class="au-input" :class="{ 'is-invalid': signupErrors.name }">
                  <AppIcon name="user" :size="18" />
                  <input id="signup-name" v-model="signupForm.name" type="text" autocomplete="name" maxlength="100" placeholder="e.g. Sokha Leng" :aria-invalid="signupErrors.name ? 'true' : undefined" :aria-describedby="signupErrors.name ? 'signup-name-error' : undefined" @input="clearSignup('name')" />
                </div>
                <p v-if="signupErrors.name" id="signup-name-error" class="au-error"><AppIcon name="alert-circle" :size="14" />{{ signupErrors.name }}</p>
              </div>
              <div class="au-field">
                <label for="signup-identifier">Email or phone</label>
                <div class="au-input" :class="{ 'is-invalid': signupErrors.identifier }">
                  <AppIcon name="mail" :size="18" />
                  <input id="signup-identifier" v-model="signupForm.identifier" type="text" autocomplete="username" placeholder="Email or phone number" :aria-invalid="signupErrors.identifier ? 'true' : undefined" :aria-describedby="signupErrors.identifier ? 'signup-identifier-error' : 'signup-identifier-hint'" @input="clearSignup('identifier')" />
                </div>
                <p v-if="signupErrors.identifier" id="signup-identifier-error" class="au-error"><AppIcon name="alert-circle" :size="14" /><span>{{ signupErrors.identifier }}<template v-if="/Sign in instead/.test(signupErrors.identifier)"> <RouterLink :to="{ path: '/login', query: keepRedirect }">Go to log in</RouterLink></template></span></p>
                <p v-else id="signup-identifier-hint" class="au-hint">You’ll use this to sign in.</p>
              </div>
              <div class="au-field">
                <label for="signup-password">Password</label>
                <div class="au-input" :class="{ 'is-invalid': signupErrors.password }">
                  <AppIcon name="lock" :size="18" />
                  <input id="signup-password" v-model="signupForm.password" :type="signupPasswordVisible ? 'text' : 'password'" autocomplete="new-password" placeholder="Create a password" :aria-invalid="signupErrors.password ? 'true' : undefined" aria-describedby="signup-password-rule" @input="clearSignup('password')" />
                  <button type="button" class="au-reveal" :aria-pressed="signupPasswordVisible" :aria-label="signupPasswordVisible ? 'Hide passwords' : 'Show passwords'" @click="signupPasswordVisible = !signupPasswordVisible"><AppIcon :name="signupPasswordVisible ? 'eye' : 'eye-off'" :size="18" /></button>
                </div>
                <p id="signup-password-rule" class="au-rule" :class="{ 'is-met': passwordLongEnough, 'is-error': signupErrors.password }"><AppIcon :name="passwordLongEnough ? 'check-circle' : 'info'" :size="14" />At least 8 characters</p>
              </div>
              <div class="au-field">
                <label for="signup-password_confirmation">Confirm password</label>
                <div class="au-input" :class="{ 'is-invalid': signupErrors.password_confirmation || !passwordsMatch, 'is-valid': signupForm.password_confirmation && passwordsMatch && passwordLongEnough }">
                  <AppIcon name="lock" :size="18" />
                  <input id="signup-password_confirmation" v-model="signupForm.password_confirmation" :type="signupPasswordVisible ? 'text' : 'password'" autocomplete="new-password" placeholder="Re-enter your password" :aria-invalid="signupErrors.password_confirmation || !passwordsMatch ? 'true' : undefined" aria-describedby="signup-match" @input="clearSignup('password_confirmation')" />
                  <AppIcon v-if="signupForm.password_confirmation && passwordsMatch && passwordLongEnough" name="check" :size="18" class="au-ok" />
                </div>
                <p v-if="signupErrors.password_confirmation || !passwordsMatch" id="signup-match" class="au-error" aria-live="polite"><AppIcon name="alert-circle" :size="14" />{{ signupErrors.password_confirmation || 'The passwords don’t match.' }}</p>
                <p v-else id="signup-match" class="au-sr" aria-live="polite">{{ signupForm.password_confirmation && passwordsMatch ? 'Passwords match.' : '' }}</p>
              </div>
              <button type="submit" class="au-submit" :disabled="auth.loading"><span v-if="auth.loading" class="au-spinner" aria-hidden="true"></span>{{ auth.loading ? 'Creating account…' : 'Create account' }}</button>
            </form>
            <p class="au-foot">Already have an account? <RouterLink :to="{ path: '/login', query: keepRedirect }">Log in</RouterLink></p>
          </template>
        </article>
      </div>
    </section>
  </UserLayout>
</template>
