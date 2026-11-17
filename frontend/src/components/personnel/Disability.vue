<script setup>
import { computed, onMounted, ref } from 'vue'
import AppIcon from '../../components/AppIcon.vue'
import { getPersonnelProfile } from '../../services/staffRequestService'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

const DISABILITY_TYPES = {
  V: { label: 'ពិការភាពមើលឃើញ / Difficulty seeing', icon: 'eye-off' },
  H: { label: 'ពិការភាពស្តាប់ / Difficulty hearing', icon: 'info' },
  M: { label: 'ពិការភាពធ្វើចលនា / Difficulty moving', icon: 'truck' },
  R: { label: 'ពិការភាពចងចាំ / Difficulty remembering', icon: 'activity' },
  S: { label: 'ពិការភាពថែទាំខ្លួនឯង / Difficulty with self-care', icon: 'user' },
  X: { label: 'ពិការភាពទំនាក់ទំនង / Difficulty communicating', icon: 'send' },
  O: { label: 'គ្មានពិការភាព / No disability', icon: 'check-circle' },
}

const loading = ref(true)
const error = ref('')
const disability = ref(null)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getPersonnelProfile()
    disability.value = data.data.profile?.disability || null
  } catch {
    error.value = 'Could not load disability information. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const hasDisability = computed(() => disability.value && disability.value.type && disability.value.type !== 'O')
const disabilityType = computed(() => disability.value?.type)
const disabilityNotes = computed(() => disability.value?.notes || '')
const disabilityLabel = computed(() => disabilityType.value ? DISABILITY_TYPES[disabilityType.value]?.label : '')
const disabilityIcon = computed(() => disabilityType.value ? DISABILITY_TYPES[disabilityType.value]?.icon : 'accessibility')
</script>

<template>
  <div class="disability-view">
    <header class="dv-header">
      <h1>Disability Information</h1>
      <p class="dv-subtitle">Disability status and support needs</p>
    </header>

    <div v-if="loading" class="dv-loading" role="status">
      <AppIcon name="loader" :size="24" spin />
      <span>Loading disability information…</span>
    </div>

    <section v-else-if="error" class="dv-card dv-error" role="alert">
      <AppIcon name="alert-circle" :size="24" />
      <p>{{ error }}</p>
      <button type="button" class="dv-btn dv-btn--ghost" @click="load">
        <AppIcon name="refresh" :size="16" /> Try again
      </button>
    </section>

    <section v-else class="dv-card">
      <div class="dv-status" :class="{ 'has-disability': hasDisability, 'no-disability': !hasDisability }">
        <AppIcon class="dv-status-icon" :name="disabilityIcon" :size="32" />
        <div class="dv-status-text">
          <strong v-if="hasDisability">{{ disabilityLabel }}</strong>
          <strong v-else class="no-disability-text">{{ DISABILITY_TYPES.O.label }}</strong>
        </div>
      </div>

      <div v-if="disabilityNotes" class="dv-notes">
        <h3>Notes</h3>
        <p>{{ disabilityNotes }}</p>
      </div>

      <div v-if="!hasDisability && !disabilityNotes" class="dv-empty">
        <AppIcon name="info" :size="24" />
        <p>No disability information recorded. This section can be updated in your Personnel Profile.</p>
      </div>

      <div class="dv-meta">
        <small>Disability information is part of your Personnel Profile. Updates can be made in the Profile form.</small>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dv-header { margin-bottom: 24px; }
.dv-header h1 { margin: 0 0 4px; font-size: 1.5rem; font-weight: 600; color: #172b4d; }
.dv-subtitle { margin: 0; color: #5e6c84; }

.dv-loading { display: flex; align-items: center; gap: 12px; padding: 32px; color: #5e6c84; }

.dv-card {
  background: #fff;
  border: 1px solid #dfe1e6;
  border-radius: 8px;
  padding: 24px;
}

.dv-error { display: flex; flex-direction: column; align-items: center; gap: 16px; text-align: center; color: #de350b; }
.dv-error p { margin: 0; }
.dv-btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-weight: 500; cursor: pointer; border: none; }
.dv-btn--ghost { background: transparent; color: #0052cc; border: 1px solid #0052cc; }
.dv-btn--ghost:hover { background: #e8f0fe; }

.dv-status { display: flex; align-items: center; gap: 16px; padding: 16px; border-radius: 8px; margin-bottom: 16px; }
.dv-status.has-disability { background: #fff3cd; border: 1px solid #ffc107; }
.dv-status.no-disability { background: #e8f5e9; border: 1px solid #4caf50; }
.dv-status-icon { flex-shrink: 0; }
.dv-status.has-disability .dv-status-icon { color: #856404; }
.dv-status.no-disability .dv-status-icon { color: #2e7d32; }
.dv-status-text strong { font-size: 1.1rem; }
.dv-status.has-disability .dv-status-text { color: #856404; }
.dv-status.no-disability .dv-status-text { color: #2e7d32; }

.dv-notes { margin-top: 16px; padding-top: 16px; border-top: 1px solid #dfe1e6; }
.dv-notes h3 { margin: 0 0 8px; font-size: 0.9rem; font-weight: 600; color: #42526e; text-transform: uppercase; letter-spacing: 0.5px; }
.dv-notes p { margin: 0; color: #172b4d; line-height: 1.5; }

.dv-empty { display: flex; flex-direction: column; align-items: center; text-align: center; padding: 32px 16px; color: #5e6c84; gap: 12px; }
.dv-empty p { margin: 0; max-width: 400px; }

.dv-meta { margin-top: 24px; padding-top: 16px; border-top: 1px solid #dfe1e6; color: #5e6c84; font-size: 0.85rem; }
</style>