<script setup>
import { reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import { useAuthStore } from '../../stores/auth'

const router = useRouter(); const route = useRoute(); const auth = useAuthStore(); const form = reactive({ email: '', password: '' })
const submit = async () => { try { await auth.login(form); router.push(route.query.redirect || '/me') } catch { /* message is shown below */ } }
</script>

<template><UserLayout><section class="panel narrow auth-panel"><p class="eyebrow">Welcome back</p><h1>Login</h1><p class="muted">Sign in to manage your registration and personal QR pass.</p><form class="form" @submit.prevent="submit"><label>Email address<input v-model="form.email" type="email" autocomplete="email" required /></label><label>Password<input v-model="form.password" type="password" autocomplete="current-password" required /></label><p v-if="auth.error" class="error">{{ auth.error }}</p><button :disabled="auth.loading">{{ auth.loading ? 'Signing in...' : 'Login' }}</button></form><p class="form-footer">New here? <RouterLink to="/account/register">Register account</RouterLink></p></section></UserLayout></template>