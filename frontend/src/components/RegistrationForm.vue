<script setup>
import { reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useRegistrationStore } from '../stores/registration'
const router = useRouter(); const store = useRegistrationStore(); const form = reactive({ event_name: 'Community Tech Summit', event_date: '' })
const submit = async () => { await store.register(form); router.push('/registration/success') }
</script>
<template><form class="form" @submit.prevent="submit"><label>Event name<input v-model="form.event_name" required /></label><label>Event date<input v-model="form.event_date" type="datetime-local" required /></label><p v-if="store.error" class="error">{{ store.error }}</p><button :disabled="store.loading">{{ store.loading ? 'Registering...' : 'Confirm registration' }}</button></form></template>