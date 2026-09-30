<script setup>
import { reactive } from 'vue'
import { useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import { useAuthStore } from '../../stores/auth'

const router = useRouter(); const auth = useAuthStore(); const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const submit = async () => { if (form.password !== form.password_confirmation) { auth.error = 'Passwords do not match.'; return } try { await auth.registerAccount({ name: form.name, email: form.email, password: form.password }); router.push('/register') } catch { /* message is shown below */ } }
</script>

<template><UserLayout><section class="panel narrow auth-panel"><p class="eyebrow">Join the event</p><h1>Register account</h1><p class="muted">Create your attendee account before reserving your place.</p><form class="form" @submit.prevent="submit"><label>Full name<input v-model="form.name" autocomplete="name" required /></label><label>Email address<input v-model="form.email" type="email" autocomplete="email" required /></label><label>Password<input v-model="form.password" type="password" minlength="8" autocomplete="new-password" required /></label><label>Confirm password<input v-model="form.password_confirmation" type="password" minlength="8" autocomplete="new-password" required /></label><p v-if="auth.error" class="error">{{ auth.error }}</p><button :disabled="auth.loading">{{ auth.loading ? 'Creating account...' : 'Register account' }}</button></form><p class="form-footer">Already registered? <RouterLink to="/login">Login</RouterLink></p></section></UserLayout></template>