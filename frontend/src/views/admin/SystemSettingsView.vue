<script setup>
import { onMounted, reactive, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import api from '../../services/api'

const settings = reactive({ site_name: '', registration_notice: '', maintenance_mode: false })
const loading = ref(true); const saving = ref(false); const message = ref(''); const error = ref('')
const load = async () => { try { Object.assign(settings, (await api.get('/admin/settings')).data.data) } catch (e) { error.value = e.response?.data?.message || 'Unable to load system settings.' } finally { loading.value = false } }
const save = async () => { saving.value = true; message.value = ''; error.value = ''; try { Object.assign(settings, (await api.put('/admin/settings', settings)).data.data); message.value = 'System settings saved.' } catch (e) { error.value = Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'Unable to save system settings.' } finally { saving.value = false } }
onMounted(load)
</script>
<template><AdminLayout><section class="profile-settings-page"><header class="profile-settings-header"><div><p class="admin-eyebrow">Administration</p><h1>System settings</h1><p>Configure settings that apply across the event registration system.</p></div></header><div v-if="message" class="profile-alert profile-alert-success" role="status">{{ message }}</div><div v-if="error" class="profile-alert profile-alert-error" role="alert">{{ error }}</div><section v-if="!loading" class="profile-settings-card"><div class="profile-card-heading"><div><h2>General settings</h2><p>These settings are available to Admins only.</p></div></div><div class="profile-form-grid"><label>System name<input v-model="settings.site_name" maxlength="150" /></label><label class="profile-form-wide">Registration notice<textarea v-model="settings.registration_notice" rows="4" maxlength="1000" placeholder="Optional notice shown to attendees." /></label><label class="profile-toggle"><input v-model="settings.maintenance_mode" type="checkbox" /> Enable maintenance mode</label></div><div class="profile-card-actions"><button class="button button-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save settings' }}</button></div></section></section></AdminLayout></template>
