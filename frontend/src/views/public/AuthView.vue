<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import UserLayout from '../../layouts/UserLayout.vue'
import picture from '../../assets/picture.jpg'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const isSignup = computed(() => route.path === '/account/register')
const redirectPath = computed(() => typeof route.query.redirect === 'string' ? route.query.redirect : '')
const loginForm = reactive({ email: '', password: '' })
const rememberMe = ref(false)
const loginPasswordVisible = ref(false)
const signupForm = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const signupPasswordVisible = ref(false)
const signupConfirmVisible = ref(false)
const passwordsMatch = computed(() => !signupForm.password_confirmation || signupForm.password === signupForm.password_confirmation)

const login = async () => {
  try {
    await auth.login(loginForm)
    const requestedPath = redirectPath.value
    router.push(auth.user?.role === 'admin' ? (requestedPath.startsWith('/admin') ? requestedPath : '/admin') : (requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/register'))
  } catch { /* displayed in the form */ }
}
const signup = async () => {
  if (signupForm.password !== signupForm.password_confirmation) { auth.error = 'Passwords do not match.'; return }
  try {
    await auth.registerAccount({ ...signupForm, name: signupForm.name || signupForm.email.split('@')[0] })
    const requestedPath = redirectPath.value
    router.push(requestedPath && !requestedPath.startsWith('/admin') ? requestedPath : '/register')
  } catch { /* displayed in the form */ }
}
</script>

<template>
  <UserLayout>
    <section class="auth-page">
      <div class="auth-cards">
        <section v-if="!isSignup" class="auth-card auth-card-login">
          <div class="auth-form-panel">
            <p class="auth-kicker">Welcome back 👋</p>
            <h1>WELCOME BACK</h1>
            <form class="auth-form" @submit.prevent="login">
              <label>Email<input v-model="loginForm.email" type="email" placeholder="Enter your email" autocomplete="email" required /></label>
              <label>Password<div class="password-field"><input v-model="loginForm.password" :type="loginPasswordVisible ? 'text' : 'password'" placeholder="**********" autocomplete="current-password" required /><button type="button" class="password-toggle" :aria-label="loginPasswordVisible ? 'Hide password' : 'Show password'" @click="loginPasswordVisible = !loginPasswordVisible"><svg v-if="loginPasswordVisible" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg></button></div></label>
              <div class="login-options"><label class="remember-option"><input v-model="rememberMe" type="checkbox" /> <span>Remember me</span></label><RouterLink class="forgot-link" to="/login">Forgot password?</RouterLink></div>
              <p v-if="auth.error && !auth.loading" class="auth-error">{{ auth.error }}</p>
              <button type="submit" :disabled="auth.loading">{{ auth.loading ? 'Signing in...' : 'Sign in' }}</button>
            </form>
            <div class="social-login-options login-social"><button type="button" class="google-auth-button"><svg class="google-auth-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.35 12.27c0-.71-.06-1.4-.18-2.06H12v3.9h5.24a4.48 4.48 0 0 1-1.94 2.94v2.45h3.14c1.84-1.7 2.91-4.2 2.91-7.23Z"/><path fill="#34A853" d="M12 21.52c2.63 0 4.84-.87 6.45-2.37l-3.14-2.45c-.87.58-1.98.92-3.31.92-2.54 0-4.7-1.72-5.47-4.03H3.29v2.53A9.74 9.74 0 0 0 12 21.52Z"/><path fill="#FBBC05" d="M6.53 13.59A5.86 5.86 0 0 1 6.23 12c0-.55.1-1.09.3-1.59V7.88H3.29A9.74 9.74 0 0 0 2.26 12c0 1.57.38 3.05 1.03 4.12l3.24-2.53Z"/><path fill="#EA4335" d="M12 6.38c1.43 0 2.71.49 3.72 1.45l2.79-2.79C16.84 3.48 14.63 2.48 12 2.48a9.74 9.74 0 0 0-8.71 5.4l3.24 2.53c.77-2.31 2.93-4.03 5.47-4.03Z"/></svg><span>Sign in with Google</span></button></div>
            <p class="auth-switch">Don't have an account? <RouterLink :to="{ path: '/account/register', query: redirectPath ? { redirect: redirectPath } : undefined }">Sign up for free!</RouterLink></p>
          </div>
          <div class="auth-visual"><img class="auth-visual-image" :src="picture" alt="Mountain landscape" /><span>Event</span><strong>Make every moment count.</strong><small>Plan, publish, and grow your event.</small></div>
        </section>

        <section v-else class="auth-card auth-card-signup">
          <div class="auth-form-panel">
            <p class="auth-kicker">Create your account ✨</p>
            <h1>Sign up</h1>
            <form class="auth-form" @submit.prevent="signup">
              <label>Email<input v-model="signupForm.email" type="email" placeholder="Email" autocomplete="email" required /></label>
              <label>Password<div class="password-field"><input v-model="signupForm.password" :type="signupPasswordVisible ? 'text' : 'password'" placeholder="Create password" minlength="8" autocomplete="new-password" required /><button type="button" class="password-toggle" :aria-label="signupPasswordVisible ? 'Hide password' : 'Show password'" @click="signupPasswordVisible = !signupPasswordVisible"><svg v-if="signupPasswordVisible" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg></button></div></label>
              <label>Confirm password<div class="password-field" :class="{ 'has-error': !passwordsMatch }"><input v-model="signupForm.password_confirmation" :type="signupConfirmVisible ? 'text' : 'password'" placeholder="Confirm password" minlength="8" autocomplete="new-password" required /><button type="button" class="password-toggle" :aria-label="signupConfirmVisible ? 'Hide password' : 'Show password'" @click="signupConfirmVisible = !signupConfirmVisible"><svg v-if="signupConfirmVisible" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A11.2 11.2 0 0 1 12 6c6.5 0 10 6 10 6a17.8 17.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.5 6 10 6c1.7 0 3.1-.4 4.4-1"/></svg></button></div></label>
              <small v-if="!passwordsMatch" class="password-match-error">Passwords do not match.</small>
              <p v-if="auth.error && !auth.loading" class="auth-error">{{ auth.error }}</p>
              <button type="submit" :disabled="auth.loading">{{ auth.loading ? 'Creating account...' : 'Create account' }}</button>
            </form>
            <p class="auth-switch">Already have an account? <RouterLink :to="{ path: '/login', query: redirectPath ? { redirect: redirectPath } : undefined }">Login</RouterLink></p>
            <div class="auth-divider"><span>Or</span></div>
            <div class="social-login-options"><button type="button" class="google-auth-button"><svg class="google-auth-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.35 12.27c0-.71-.06-1.4-.18-2.06H12v3.9h5.24a4.48 4.48 0 0 1-1.94 2.94v2.45h3.14c1.84-1.7 2.91-4.2 2.91-7.23Z"/><path fill="#34A853" d="M12 21.52c2.63 0 4.84-.87 6.45-2.37l-3.14-2.45c-.87.58-1.98.92-3.31.92-2.54 0-4.7-1.72-5.47-4.03H3.29v2.53A9.74 9.74 0 0 0 12 21.52Z"/><path fill="#FBBC05" d="M6.53 13.59A5.86 5.86 0 0 1 6.23 12c0-.55.1-1.09.3-1.59V7.88H3.29A9.74 9.74 0 0 0 2.26 12c0 1.57.38 3.05 1.03 4.12l3.24-2.53Z"/><path fill="#EA4335" d="M12 6.38c1.43 0 2.71.49 3.72 1.45l2.79-2.79C16.84 3.48 14.63 2.48 12 2.48a9.74 9.74 0 0 0-8.71 5.4l3.24 2.53c.77-2.31 2.93-4.03 5.47-4.03Z"/></svg><span>Login with Google</span></button></div>
          </div>
          <div class="auth-visual"><img class="auth-visual-image" :src="picture" alt="Mountain landscape" /><span>Event</span><strong>Bring your ideas to life.</strong><small>Everything you need in one place.</small></div>
        </section>
      </div>
    </section>
  </UserLayout>
</template>
