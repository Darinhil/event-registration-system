<script setup>
import { onMounted } from 'vue'
import UserLayout from '../../layouts/UserLayout.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { formatDate } from '../../utils/formatDate'
const auth = useAuthStore(); onMounted(() => auth.fetchMe())
</script>

<template><UserLayout><section class="panel"><p class="eyebrow">Attendee portal</p><h1>My information</h1><div v-if="auth.user" class="profile-grid"><div><span class="field-label">Name</span><strong>{{ auth.user.name }}</strong></div><div><span class="field-label">Email</span><strong>{{ auth.user.email }}</strong></div></div><div class="section-heading"><h2>My registrations</h2><span>{{ auth.user?.registrations?.length || 0 }} total</span></div><div v-if="auth.user?.registrations?.length" class="registration-list"><article v-for="registration in auth.user.registrations" :key="registration.id" class="registration-row"><div><strong>{{ registration.event_name }}</strong><span>{{ formatDate(registration.event_date) }}</span><small v-if="registration.registration_deadline">Editing deadline: {{ new Date(registration.registration_deadline).toLocaleString() }}</small></div><StatusBadge :status="registration.status" /><div class="registration-actions"><RouterLink class="text-link" :to="`/registration/success?id=${registration.id}`">View pass</RouterLink><RouterLink v-if="registration.can_edit" class="primary-button" :to="`/registration/${registration.id}/edit`">Edit registration</RouterLink><span v-else class="editing-closed">🔒 Editing closed</span></div></article></div><p v-else class="empty-state">No registrations yet. <RouterLink to="/register">Register for the event</RouterLink></p></section></UserLayout></template>
