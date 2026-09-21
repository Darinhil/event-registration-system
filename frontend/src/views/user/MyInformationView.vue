<script setup>
import { onMounted } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { formatDate } from '../../utils/formatDate'
const auth = useAuthStore(); onMounted(() => auth.fetchMe())
</script>

<template><UserLayout><section class="panel"><p class="eyebrow">Attendee portal</p><h1>My information</h1><div v-if="auth.user" class="profile-grid"><div><span class="field-label">Name</span><strong>{{ auth.user.name }}</strong></div><div><span class="field-label">Email</span><strong>{{ auth.user.email }}</strong></div></div><div class="section-heading"><h2>My registrations</h2><span>{{ auth.user?.registrations?.length || 0 }} total</span></div><div v-if="auth.user?.registrations?.length" class="registration-list"><article v-for="registration in auth.user.registrations" :key="registration.id" class="registration-row"><div><strong>{{ registration.event_name }}</strong><span>{{ formatDate(registration.event_date) }}</span></div><StatusBadge :status="registration.status" /><RouterLink class="text-link" :to="`/registration/success?id=${registration.id}`">View pass</RouterLink></article></div><p v-else class="empty-state">No registrations yet. <RouterLink to="/register">Register for the event</RouterLink></p></section></UserLayout></template>