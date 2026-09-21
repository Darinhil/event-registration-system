<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import logo from '../assets/logo.png'
const router = useRouter(); const auth = useAuthStore()
const logout = async () => { await auth.logout(); router.push('/') }
</script>
<template><div class="shell"><header class="topbar"><RouterLink to="/" class="brand"><img :src="logo" alt="" /> <span>Event</span></RouterLink><nav><RouterLink v-if="auth.isAuthenticated" to="/me">My information</RouterLink><RouterLink v-if="auth.isAuthenticated" to="/check-in">Check-in</RouterLink><template v-if="!auth.isAuthenticated"><RouterLink class="login-link" to="/login">Login</RouterLink><RouterLink class="get-started-link" to="/account/register">Get Started</RouterLink></template><button v-else class="link-button" @click="logout">Logout</button></nav></header><main><slot /></main></div></template>
