<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import api from '../../services/api'
import { getAdminEvents, getEventReportAttendees } from '../../services/adminService'
import { ageCode, genderCode } from '../../utils/registrationCodes'
import { scheduleLabels, toLocalParts } from '../../utils/eventStats'

const route = useRoute()
const router = useRouter()
const eventId = computed(() => String(route.params.id || ''))
const selectedEventId = ref(eventId.value)
const events = ref([])
const event = ref(null)
const rows = ref([])
const loading = ref(true)
const error = ref('')
const search = ref('')
const sort = ref('name-asc')
const page = ref(1)
const pageSize = 10
const modal = ref('')
const selectedRow = ref(null)
const editing = ref({})
const saving = ref(false)
const busyId = ref(null)
const notice = ref('')

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const [eventList, report, details] = await Promise.all([
      getAdminEvents(),
      getEventReportAttendees(eventId.value),
      api.get(`/events/${eventId.value}`),
    ])
    events.value = Array.isArray(eventList.data) ? eventList.data : []
    rows.value = report.data?.data?.attendees || []
    event.value = details.data?.data || report.data?.data?.event || null
    selectedEventId.value = eventId.value
    page.value = 1
  } catch (requestError) {
    const status = requestError.response?.status
    error.value = status === 403
      ? 'You don’t have access to this event’s registrants.'
      : status === 404
        ? 'This event doesn’t exist or was deleted.'
        : 'We couldn’t load the event and registrants. Check your connection and try again.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(eventId, load)
watch([search, sort], () => { page.value = 1 })

const term = computed(() => search.value.trim().toLocaleLowerCase())
const normalizeLabel = (label) => String(label).toLocaleLowerCase().replace(/[^a-z]/g, '')
const getAnswer = (row, key) => {
  if (row.profile?.[key] !== null && row.profile?.[key] !== undefined && row.profile?.[key] !== '') return row.profile[key]
  const aliases = key === 'age' ? ['age', 'agegroup'] : [key]
  const match = Object.entries(row.form_answers || {}).find(([label]) => aliases.includes(normalizeLabel(label)))
  return match?.[1]
}
const textValue = (value) => Array.isArray(value) ? value.join(', ') : String(value ?? '')
const filteredRows = computed(() => {
  const query = term.value
  const filtered = rows.value.filter((row) => {
    if (!query) return true
    const answers = Object.entries(row.form_answers || {}).flat()
    return [row.name, row.email, row.phone, row.registration_code, row.profile?.organization, ...answers]
      .some((value) => String(value ?? '').toLocaleLowerCase().includes(query))
  })
  return filtered.sort((a, b) => {
    if (sort.value === 'name-desc') return String(b.name || '').localeCompare(String(a.name || ''))
    if (sort.value === 'recent') return String(b.registered_at || '').localeCompare(String(a.registered_at || ''))
    if (sort.value === 'oldest') return String(a.registered_at || '').localeCompare(String(b.registered_at || ''))
    return String(a.name || '').localeCompare(String(b.name || ''))
  })
})
const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize)))
const pageRows = computed(() => filteredRows.value.slice((page.value - 1) * pageSize, page.value * pageSize))
const rangeStart = computed(() => filteredRows.value.length ? (page.value - 1) * pageSize + 1 : 0)
const rangeEnd = computed(() => Math.min(page.value * pageSize, filteredRows.value.length))
const schedule = computed(() => scheduleLabels(event.value?.starts_at, event.value?.ends_at))
const checkInActive = computed(() => event.value?.status !== 'cancelled')
const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
const formatRegisteredAt = (value) => {
  const date = toLocalParts(value).value
  return date ? date.toLocaleString(undefined, { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
}
const requestMessage = (requestError, fallback) => {
  const firstValidationError = Object.values(requestError.response?.data?.errors || {}).flat()[0]
  return firstValidationError || requestError.response?.data?.message || fallback
}
const displayGender = (row) => genderCode(getAnswer(row, 'gender')) || '—'
const displayAge = (row) => ageCode(getAnswer(row, 'age')) || '—'

const changeEvent = () => {
  if (selectedEventId.value && selectedEventId.value !== eventId.value) {
    router.push(`/admin/events/${selectedEventId.value}/registrants`)
  }
}

const showView = (row) => { selectedRow.value = row; modal.value = 'view' }
const showEdit = (row) => {
  selectedRow.value = row
  editing.value = {
    full_name: row.name || '',
    email: row.email || '',
    phone: row.phone || '',
    gender: String(row.profile?.gender || '').toLowerCase(),
    age: row.profile?.age ?? '',
  }
  modal.value = 'edit'
}
const closeModal = () => {
  if (saving.value || busyId.value) return
  modal.value = ''
  selectedRow.value = null
}
const saveRegistrant = async () => {
  if (!selectedRow.value || saving.value) return
  saving.value = true
  notice.value = ''
  try {
    await api.put(`/admin/events/${eventId.value}/registrants/${selectedRow.value.id}`, {
      full_name: editing.value.full_name,
      email: editing.value.email || null,
      phone: editing.value.phone || null,
      gender: editing.value.gender || null,
      age: editing.value.age === '' ? null : Number(editing.value.age),
    })
    modal.value = ''
    selectedRow.value = null
    notice.value = 'Registrant details saved.'
    await load()
  } catch (requestError) {
    notice.value = requestMessage(requestError, 'Registrant details could not be saved. Check the fields and try again.')
  } finally {
    saving.value = false
  }
}
const confirmDelete = async () => {
  if (!selectedRow.value || busyId.value) return
  busyId.value = selectedRow.value.id
  notice.value = ''
  try {
    await api.delete(`/admin/events/${eventId.value}/registrants/${selectedRow.value.id}`)
    modal.value = ''
    selectedRow.value = null
    notice.value = 'Registrant deleted.'
    await load()
  } catch (requestError) {
    notice.value = requestMessage(requestError, 'Registrant could not be deleted. Please try again.')
    if (requestError.response?.status === 422) {
      modal.value = 'view'
      await load()
      selectedRow.value = rows.value.find((row) => String(row.id) === String(busyId.value)) || selectedRow.value
    }
  } finally {
    busyId.value = null
  }
}

const exportRows = () => {
  const formLabels = [...new Set(filteredRows.value.flatMap((row) => Object.keys(row.form_answers || {})))]
  const header = ['Name', 'Email', 'Phone', 'Gender', 'Age group', 'Organization', 'Registration ID', 'Registered at', ...formLabels]
  const data = filteredRows.value.map((row) => [
    row.name,
    row.email,
    row.phone,
    textValue(getAnswer(row, 'gender')),
    textValue(getAnswer(row, 'age')),
    row.profile?.organization,
    row.registration_code || row.id,
    formatRegisteredAt(row.registered_at),
    ...formLabels.map((label) => textValue(row.form_answers?.[label])),
  ])
  return [header, ...data]
}
const fileBase = computed(() => (String(event.value?.name || 'event').replace(/[^\w -]/g, '').trim().replace(/\s+/g, '-').toLowerCase() || 'event'))
const exportExcel = async () => {
  if (!filteredRows.value.length) return
  try {
    const XLSX = await import('xlsx')
    const sheet = XLSX.utils.aoa_to_sheet(exportRows())
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, sheet, 'Registrants')
    XLSX.writeFile(workbook, `${fileBase.value}-registrants.xlsx`)
    notice.value = `Exported ${filteredRows.value.length} ${filteredRows.value.length === 1 ? 'registrant' : 'registrants'}.`
  } catch {
    notice.value = 'The Excel file could not be created. Please try again.'
  }
}
const printRegistrants = () => {
  if (filteredRows.value.length) window.print()
}
</script>

<template>
  <AdminLayout>
    <section class="rl-page edl-page" aria-labelledby="edl-title">
      <header class="edl-title">
        <h1 id="edl-title">Event Detail</h1>
      </header>

      <div v-if="loading" class="rl-panel rl-state" role="status">Loading event and registrants…</div>
      <div v-else-if="error" class="rl-panel rl-state is-error" role="alert">
        <p>{{ error }}</p>
        <button type="button" class="es-btn" @click="load"><AppIcon name="refresh" :size="16" />Try again</button>
      </div>
      <template v-else>
        <section class="rl-panel edl-event-picker" aria-label="Select event">
          <div class="edl-picker-main">
            <label for="edl-event-select">Select event:</label>
            <select id="edl-event-select" v-model="selectedEventId" @change="changeEvent">
              <option v-for="option in events" :key="option.id" :value="String(option.id)">{{ option.name }}{{ option.status === 'published' ? ' (Active)' : ` (${option.status})` }}</option>
            </select>
            <span class="edl-total">{{ events.length }} total</span>
          </div>
          <RouterLink class="es-btn edl-new-event" to="/admin/events/new"><AppIcon name="plus" :size="16" />New Event</RouterLink>
        </section>

        <section class="rl-panel edl-event-card" aria-label="Selected event">
          <div>
            <h2>{{ event?.name || 'Event' }}</h2>
            <p class="edl-event-meta">
              <span><AppIcon name="pin" :size="15" /><strong>{{ event?.location || 'Location to be confirmed' }}</strong></span>
              <span class="edl-meta-separator" aria-hidden="true">·</span>
              <span><AppIcon name="clock" :size="15" />{{ schedule.date }} · {{ schedule.time }}</span>
            </p>
          </div>
          <RouterLink v-if="checkInActive" class="edl-terminal" :to="{ path: '/admin/check-ins', query: { event: eventId } }"><i aria-hidden="true"></i>Check-in Terminal Active</RouterLink>
          <span v-else class="edl-terminal is-inactive"><i aria-hidden="true"></i>Check-in Terminal Inactive</span>
        </section>

        <section class="rl-panel edl-registrants" aria-labelledby="edl-registrants-title">
          <header class="edl-list-head">
            <div class="edl-list-title">
              <h2 id="edl-registrants-title">List Registrant</h2>
              <span class="edl-count">{{ rows.length }} {{ rows.length === 1 ? 'Registrant' : 'Registrants' }}</span>
            </div>
            <div class="edl-list-actions rl-no-print">
              <button type="button" class="es-btn" :disabled="!filteredRows.length" @click="exportExcel"><AppIcon name="download" :size="15" />Excel</button>
              <button type="button" class="es-btn" :disabled="!filteredRows.length" @click="printRegistrants"><AppIcon name="file-text" :size="15" />PDF</button>
              <RouterLink class="es-btn es-btn--primary" :to="{ path: '/admin/check-ins', query: { event: eventId } }"><AppIcon name="scan" :size="15" />Check-in</RouterLink>
            </div>
          </header>

          <div class="edl-toolbar rl-no-print">
            <label class="rl-search edl-search">
              <AppIcon name="search" :size="16" />
              <input v-model="search" type="search" placeholder="Search name, email, phone or code" aria-label="Search registrants" />
            </label>
            <label class="edl-sort-label">
              <span class="sr-only">Sort registrants</span>
              <select v-model="sort" aria-label="Sort registrants">
                <option value="name-asc">Name (A-Z)</option>
                <option value="name-desc">Name (Z-A)</option>
                <option value="recent">Newest first</option>
                <option value="oldest">Oldest first</option>
              </select>
            </label>
          </div>

          <div v-if="!rows.length" class="rl-state">
            <span class="rl-state__icon"><AppIcon name="users" :size="24" /></span>
            <strong>No registrants yet</strong>
            <p>People who register for this event will appear here.</p>
          </div>
          <div v-else-if="!filteredRows.length" class="rl-state">
            <strong>No registrants match that search.</strong>
            <button type="button" class="es-btn" @click="search = ''">Clear search</button>
          </div>
          <template v-else>
            <div class="rl-table-wrap edl-table-wrap rl-no-print">
              <table class="rl-table edl-table">
                <caption class="sr-only">Registrants for {{ event?.name }}</caption>
                <thead>
                  <tr><th scope="col">Name</th><th scope="col">Gender (M/F/N)</th><th scope="col">Age (K/Y/A/E)</th><th scope="col">Time</th><th scope="col" class="edl-actions-heading">Actions</th></tr>
                </thead>
                <tbody>
                  <tr v-for="row in pageRows" :key="row.id">
                    <td>
                      <div class="rl-person edl-person">
                        <span class="rl-avatar" aria-hidden="true">{{ initials(row.name) }}</span>
                        <span><strong>{{ row.name || 'Unnamed attendee' }}</strong><small>{{ row.email || row.phone || '—' }}</small></span>
                      </div>
                    </td>
                    <td><span class="edl-code" :aria-label="`Gender: ${textValue(getAnswer(row, 'gender')) || 'not provided'}`">{{ displayGender(row) }}</span></td>
                    <td><span class="edl-code" :aria-label="`Age group: ${textValue(getAnswer(row, 'age')) || 'not provided'}`">{{ displayAge(row) }}</span></td>
                    <td class="edl-time">{{ formatRegisteredAt(row.registered_at) }}</td>
                    <td>
                      <div class="edl-row-actions">
                        <button type="button" class="edl-icon-button" :aria-label="`View ${row.name || 'registrant'}`" title="View" @click="showView(row)"><AppIcon name="eye" :size="15" /></button>
                        <button type="button" class="edl-icon-button is-edit" :aria-label="`Edit ${row.name || 'registrant'}`" title="Edit" @click="showEdit(row)"><AppIcon name="pen" :size="15" /></button>
                        <button type="button" class="edl-icon-button is-delete" :aria-label="`Delete ${row.name || 'registrant'}`" title="Delete" @click="selectedRow = row; modal = 'delete'"><AppIcon name="trash" :size="15" /></button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="rl-table-wrap edl-print-table">
              <table class="rl-table">
                <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Gender</th><th>Age group</th><th>Registered at</th><th>Registration ID</th></tr></thead>
                <tbody>
                  <tr v-for="row in filteredRows" :key="row.id">
                    <td>{{ row.name || 'Unnamed attendee' }}</td><td>{{ row.email || '—' }}</td><td>{{ row.phone || '—' }}</td>
                    <td>{{ textValue(getAnswer(row, 'gender')) || '—' }}</td><td>{{ textValue(getAnswer(row, 'age')) || '—' }}</td>
                    <td>{{ formatRegisteredAt(row.registered_at) }}</td><td>{{ row.registration_code || row.id }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <footer v-if="filteredRows.length" class="edl-footer">
            <span>Showing {{ rangeStart }} to {{ rangeEnd }} of {{ filteredRows.length }} registrants</span>
            <nav class="edl-pagination rl-no-print" aria-label="Registrant pages">
              <button type="button" :disabled="page <= 1" aria-label="Previous page" @click="page = Math.max(1, page - 1)"><AppIcon name="chevron-left" :size="16" /></button>
              <span aria-current="page">{{ page }}</span>
              <button type="button" :disabled="page >= pageCount" aria-label="Next page" @click="page = Math.min(pageCount, page + 1)"><AppIcon name="chevron-right" :size="16" /></button>
            </nav>
          </footer>
        </section>
        <p v-if="notice" class="edl-notice" role="status">{{ notice }}</p>
      </template>

      <div v-if="modal && selectedRow" class="edl-modal-backdrop rl-no-print" @click.self="closeModal">
        <section class="edl-modal" role="dialog" aria-modal="true" :aria-labelledby="`edl-modal-${modal}`">
          <template v-if="modal === 'view'">
            <header><div><h2 id="edl-modal-view">Registrant details</h2><p>{{ selectedRow.registration_code || `Registration #${selectedRow.id}` }}</p></div><button type="button" class="edl-modal-close" aria-label="Close" @click="closeModal"><AppIcon name="x" :size="18" /></button></header>
            <dl class="edl-detail-grid">
              <div><dt>Name</dt><dd>{{ selectedRow.name || '—' }}</dd></div>
              <div><dt>Email</dt><dd>{{ selectedRow.email || '—' }}</dd></div>
              <div><dt>Phone</dt><dd>{{ selectedRow.phone || '—' }}</dd></div>
              <div><dt>Gender</dt><dd>{{ textValue(getAnswer(selectedRow, 'gender')) || '—' }}</dd></div>
              <div><dt>Age / age group</dt><dd>{{ textValue(getAnswer(selectedRow, 'age')) || '—' }}</dd></div>
              <div><dt>Organization</dt><dd>{{ selectedRow.profile?.organization || '—' }}</dd></div>
              <div><dt>Registered</dt><dd>{{ formatRegisteredAt(selectedRow.registered_at) }}</dd></div>
              <div><dt>Check-in</dt><dd>{{ selectedRow.checked_in_at ? formatRegisteredAt(selectedRow.checked_in_at) : 'Not checked in' }}</dd></div>
            </dl>
            <section v-if="Object.keys(selectedRow.form_answers || {}).length" class="edl-answers">
              <h3>Additional answers</h3>
              <dl class="edl-detail-grid">
                <div v-for="(value, label) in selectedRow.form_answers" :key="label"><dt>{{ label }}</dt><dd>{{ textValue(value) || '—' }}</dd></div>
              </dl>
            </section>
            <footer><button type="button" class="es-btn" @click="closeModal">Close</button></footer>
          </template>

          <template v-else-if="modal === 'edit'">
            <header><div><h2 id="edl-modal-edit">Edit registrant</h2><p>Update attendee contact and profile details.</p></div><button type="button" class="edl-modal-close" aria-label="Close" @click="closeModal"><AppIcon name="x" :size="18" /></button></header>
            <form class="edl-form" @submit.prevent="saveRegistrant">
              <label>Full name<input v-model.trim="editing.full_name" required minlength="2" maxlength="100" autocomplete="name" /></label>
              <div class="edl-form-row">
                <label>Email<input v-model.trim="editing.email" type="email" maxlength="150" autocomplete="email" /></label>
                <label>Phone<input v-model.trim="editing.phone" type="tel" maxlength="30" autocomplete="tel" /></label>
              </div>
              <div class="edl-form-row">
                <label>Gender<select v-model="editing.gender"><option value="">Not provided</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select></label>
                <label>Age<input v-model="editing.age" type="number" min="1" max="120" /></label>
              </div>
              <footer><button type="button" class="es-btn" :disabled="saving" @click="closeModal">Cancel</button><button type="submit" class="es-btn es-btn--primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save changes' }}</button></footer>
            </form>
          </template>

          <template v-else>
            <header><div><h2 id="edl-modal-delete">Delete registrant?</h2><p>This removes {{ selectedRow.name || 'this registrant' }} from the event.</p></div><button type="button" class="edl-modal-close" aria-label="Close" @click="closeModal"><AppIcon name="x" :size="18" /></button></header>
            <p class="edl-delete-warning">This action cannot be undone. Any check-in record for this registrant will also be deleted.</p>
            <footer><button type="button" class="es-btn" :disabled="!!busyId" @click="closeModal">Cancel</button><button type="button" class="es-btn es-btn--danger" :disabled="!!busyId" @click="confirmDelete">{{ busyId ? 'Deleting…' : 'Delete registrant' }}</button></footer>
          </template>
        </section>
      </div>
    </section>
  </AdminLayout>
</template>
