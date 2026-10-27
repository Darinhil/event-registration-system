<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { getEventReportAttendees } from '../../services/adminService'
import { toCsv, toLocalParts } from '../../utils/eventStats'

const route = useRoute()
const eventId = computed(() => route.params.id)
const event = ref(null)
const rows = ref([])
const loading = ref(true)
const error = ref('')
const search = ref('')
const checkIn = ref('all') // all | in | out
const exported = ref('')

// The report endpoint returns every registrant (the old list stopped at the first page of 25).
const load = async () => {
  loading.value = true; error.value = ''
  try {
    const [report, details] = await Promise.all([getEventReportAttendees(eventId.value), api.get(`/events/${eventId.value}`).catch(() => null)])
    rows.value = report.data?.data?.attendees || []
    event.value = details?.data?.data || report.data?.data?.event || null
  } catch (requestError) {
    error.value = requestError.response?.status === 403 ? 'You don’t have access to this event’s registrants.' : 'We couldn’t load the registrants. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const term = computed(() => search.value.trim().toLowerCase())
const filtered = computed(() => rows.value.filter((row) => {
  if (checkIn.value === 'in' && !row.checked_in_at) return false
  if (checkIn.value === 'out' && row.checked_in_at) return false
  if (!term.value) return true
  return [row.name, row.email, row.phone, row.registration_code, row.profile?.organization].some((value) => String(value || '').toLowerCase().includes(term.value))
}))
const checkedInCount = computed(() => rows.value.filter((row) => row.checked_in_at).length)
const filters = computed(() => [
  { value: 'all', label: 'All', count: rows.value.length },
  { value: 'in', label: 'Checked in', count: checkedInCount.value },
  { value: 'out', label: 'Not checked in', count: rows.value.length - checkedInCount.value },
])
watch([search, checkIn], () => { exported.value = '' })

const checkedLabel = (value) => {
  const parts = toLocalParts(value)
  return parts.value ? parts.value.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''
}
const initials = (name) => String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()

const exportCsv = () => {
  const list = filtered.value
  const header = ['Name', 'Email', 'Phone', 'Organization', 'Registration ID', 'Status', 'Checked in at']
  const lines = list.map((row) => { const at = toLocalParts(row.checked_in_at); return [row.name, row.email, row.phone, row.profile?.organization, row.registration_code, row.status, at.value ? `${at.date} ${at.time}` : ''] })
  const anchor = document.createElement('a')
  anchor.href = URL.createObjectURL(new Blob(['﻿', toCsv(header, lines)], { type: 'text/csv;charset=utf-8' }))
  anchor.download = `event-${eventId.value}-registrants.csv`
  anchor.click()
  setTimeout(() => URL.revokeObjectURL(anchor.href), 1000)
  exported.value = `Exported ${list.length} ${list.length === 1 ? 'registrant' : 'registrants'}.`
}
</script>

<template>
  <AdminLayout>
    <section class="rl-page">
      <RouterLink class="rl-back" :to="`/admin/events/${eventId}`"><AppIcon name="chevron-left" :size="16" />Back to event</RouterLink>
      <header class="rl-header">
        <div>
          <h1>Registrants</h1>
          <p>{{ event?.name || 'Everyone who registered for this event.' }}</p>
        </div>
        <button type="button" class="es-btn" :disabled="!filtered.length" @click="exportCsv"><AppIcon name="arrow-down" :size="16" />Export {{ term || checkIn !== 'all' ? 'filtered' : 'all' }} (CSV)</button>
      </header>
      <p v-if="exported" class="rl-note" role="status"><AppIcon name="check" :size="15" />{{ exported }}</p>

      <article class="rl-panel">
        <div class="rl-toolbar">
          <label class="rl-search"><AppIcon name="search" :size="17" /><input v-model="search" type="search" placeholder="Search name, email, phone or ID" aria-label="Search registrants" /></label>
          <div class="rl-filters" role="group" aria-label="Filter by check-in">
            <button v-for="option in filters" :key="option.value" type="button" :aria-pressed="checkIn === option.value" :class="{ 'is-active': checkIn === option.value }" @click="checkIn = option.value">{{ option.label }}<span>{{ option.count }}</span></button>
          </div>
        </div>

        <div v-if="loading" class="rl-state" role="status">Loading registrants…</div>
        <div v-else-if="error" class="rl-state is-error" role="alert"><p>{{ error }}</p><button type="button" class="es-btn" @click="load"><AppIcon name="refresh" :size="16" />Try again</button></div>
        <div v-else-if="!rows.length" class="rl-state">
          <span class="rl-state__icon"><AppIcon name="users" :size="24" /></span>
          <strong>No registrants yet</strong>
          <p>People who register for this event will appear here.</p>
        </div>
        <div v-else-if="!filtered.length" class="rl-state"><strong>No registrants match</strong><button type="button" class="es-btn" @click="search = ''; checkIn = 'all'">Clear filters</button></div>
        <div v-else class="rl-table-wrap">
          <table class="rl-table">
            <caption class="sr-only">Registrants for {{ event?.name || 'this event' }}</caption>
            <thead><tr><th scope="col">Registrant</th><th scope="col" class="col-contact">Contact</th><th scope="col">Registration ID</th><th scope="col">Check-in</th></tr></thead>
            <tbody>
              <tr v-for="row in filtered" :key="row.id">
                <td><div class="rl-person"><span class="rl-avatar" aria-hidden="true">{{ initials(row.name) }}</span><span><strong>{{ row.name || 'Unnamed attendee' }}</strong><small>{{ row.profile?.organization || '—' }}</small></span></div></td>
                <td class="col-contact"><span class="rl-stack"><span>{{ row.email || '—' }}</span><small>{{ row.phone || '' }}</small></span></td>
                <td><code class="rl-code">{{ row.registration_code || row.id }}</code></td>
                <td><span v-if="row.checked_in_at" class="rl-badge is-in"><AppIcon name="check" :size="13" />Checked in<small>{{ checkedLabel(row.checked_in_at) }}</small></span><span v-else class="rl-badge">Not checked in</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer v-if="rows.length" class="rl-footer">Showing {{ filtered.length }} of {{ rows.length }} {{ rows.length === 1 ? 'registrant' : 'registrants' }}</footer>
      </article>
    </section>
  </AdminLayout>
</template>
