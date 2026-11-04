<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import DateInput from '../DateInput.vue'
import RequestFormParts from './RequestFormParts.vue'
import RequestSubmitted from './RequestSubmitted.vue'
import { getTimesheet } from '../../services/timesheetService'
import { useStaffRequestForm } from '../../utils/staffRequestForm'

const REASON_MAX = 500
const ICONS = { annual: 'calendar', sick: 'heart', unpaid: 'circle-dot' }
const form = reactive({ type: 'annual', start: '', end: '', reason: '' })
const request = useStaffRequestForm('leave')
const { approvers, approverId, attachment, saving, saved, formError, errors: serverErrors, formEl } = request
const errors = ref({})
const tried = ref(false)

// Balances come from the timesheet: leave is deducted once it's on an approved timesheet.
const balances = ref([])
const balancesError = ref('')
onMounted(async () => {
  const today = new Date()
  try {
    balances.value = (await getTimesheet(today.getFullYear(), today.getMonth() + 1)).data.data.balances.map((item) => ({ ...item, used: item.used_before + item.pending + item.used_this_month, total: item.entitlement + item.carried_over, icon: ICONS[item.type] || 'circle-dot' }))
  } catch {
    balancesError.value = 'We couldn’t load your leave balance.'
  }
})
const capped = computed(() => balances.value.filter((item) => item.limited))

/* ---------- Duration ---------- */
const toDate = (iso) => new Date(`${iso}T00:00:00`)
// "Mon 2 Nov 2026" — built from parts so every date reads the same way (toLocaleDateString adds a comma only when the year is shown).
const fmt = (iso, { year = true } = {}) => {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).formatToParts(toDate(iso)).map((part) => [part.type, part.value]))
  return `${parts.weekday} ${parts.day} ${parts.month}${year ? ` ${parts.year}` : ''}`
}
const datesValid = computed(() => form.start && form.end && form.end >= form.start)
// Leave is counted in working days: weekends inside the range don't use up the balance.
const duration = computed(() => {
  if (!datesValid.value) return null
  let working = 0
  let calendar = 0
  for (const day = toDate(form.start); day <= toDate(form.end); day.setDate(day.getDate() + 1)) {
    calendar += 1
    if (day.getDay() !== 0 && day.getDay() !== 6) working += 1
  }
  return { working, calendar }
})
const selected = computed(() => balances.value.find((item) => item.type === form.type))
const overBalance = computed(() => Boolean(duration.value && selected.value?.limited && duration.value.working > selected.value.remaining))
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`

/* ---------- Validation ---------- */
const validate = () => {
  const e = {}
  if (!form.start) e.start = 'Choose the first day of your leave.'
  if (!form.end) e.end = 'Choose the last day of your leave.'
  else if (form.start && form.end < form.start) e.end = 'The end date can’t be before the start date.'
  if (duration.value && duration.value.working === 0) e.end = 'These dates fall on a weekend — choose at least one working day.'
  if (overBalance.value) e.end = `This is ${plural(duration.value.working, 'working day')}, but you have ${plural(selected.value.remaining, 'day')} of ${selected.value.label.toLowerCase()} leave left.`
  if (form.reason.length > REASON_MAX) e.reason = `Keep the reason under ${REASON_MAX} characters.`
  if (!approverId.value) e.approver_id = 'Choose who should approve this request.'
  errors.value = e
  return Object.keys(e).length === 0
}
// After the first attempt, re-check as the person fixes things so errors clear immediately.
watch([form, approverId], () => { if (tried.value) validate() }, { deep: true })
// Server errors arrive keyed by the API's field names.
const err = (key) => errors.value[key] || serverErrors[{ start: 'start_date', end: 'end_date', type: 'leave_type' }[key] || key] || ''
const describedBy = (key, hint) => (err(key) ? `lv-${key}-error` : hint || undefined)

const submit = () => {
  tried.value = true
  if (!validate()) { request.focusFirstError(); return }
  request.submit({ leave_type: form.type, start_date: form.start, end_date: form.end, reason: form.reason.trim() })
}
const startAnother = () => {
  Object.assign(form, { type: 'annual', start: '', end: '', reason: '' })
  errors.value = {}
  tried.value = false
  request.reset()
}
</script>

<template>
  <div class="lv">
    <header class="lv-head"><h1>Leave Application</h1></header>

    <div class="lv-layout">
      <RequestSubmitted v-if="saved" :request="saved" @again="startAnother" />

      <form v-else ref="formEl" class="lv-card lv-form" novalidate @submit.prevent="submit">
        <p v-if="formError" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />{{ formError }}</p>

        <fieldset class="lv-field">
          <legend class="lv-label">Leave type <span class="lv-req" aria-hidden="true">*</span></legend>
          <div class="lv-types">
            <label v-for="item in balances" :key="item.type" class="lv-type" :class="{ 'is-selected': form.type === item.type }">
              <input v-model="form.type" type="radio" name="lv-type" :value="item.type" />
              <span class="lv-type__icon"><AppIcon :name="item.icon" :size="18" /></span>
              <span class="lv-type__text"><strong>{{ item.label }}</strong><small v-if="item.limited">{{ plural(item.remaining, 'day') }} left</small></span>
            </label>
          </div>
        </fieldset>

        <div class="lv-grid">
          <div class="lv-field">
            <label for="lv-start" class="lv-label">Start date <span class="lv-req" aria-hidden="true">*</span></label>
            <DateInput id="lv-start" v-model="form.start" class="rq-input" :aria-invalid="err('start') ? 'true' : undefined" :aria-describedby="describedBy('start')" />
            <p v-if="err('start')" id="lv-start-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ err('start') }}</p>
          </div>
          <div class="lv-field">
            <label for="lv-end" class="lv-label">End date <span class="lv-req" aria-hidden="true">*</span></label>
            <DateInput id="lv-end" v-model="form.end" class="rq-input" :min="form.start || undefined" :aria-invalid="err('end') ? 'true' : undefined" :aria-describedby="describedBy('end')" />
            <p v-if="err('end')" id="lv-end-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ err('end') }}</p>
          </div>
        </div>

        <div class="lv-duration" :class="{ 'is-empty': !duration, 'is-over': overBalance }" aria-live="polite">
          <AppIcon :name="overBalance ? 'alert-circle' : 'clock'" :size="20" />
          <div v-if="duration">
            <strong>{{ plural(duration.working, 'working day') }}</strong>
            <span>{{ fmt(form.start, { year: form.start.slice(0, 4) !== form.end.slice(0, 4) }) }} – {{ fmt(form.end) }}<template v-if="duration.calendar !== duration.working"> · {{ plural(duration.calendar - duration.working, 'weekend day') }} not counted</template></span>
            <small v-if="selected?.limited">{{ overBalance ? `More than your ${selected.remaining} remaining days` : `Leaves ${plural(selected.remaining - duration.working, 'day')} of ${selected.label.toLowerCase()} leave` }}</small>
          </div>
          <div v-else><strong>Choose your dates</strong></div>
        </div>

        <div class="lv-field">
          <label for="lv-reason" class="lv-label">Reason <span class="lv-optional">Optional</span></label>
          <textarea id="lv-reason" v-model="form.reason" rows="3" :maxlength="REASON_MAX + 50" placeholder="A short note for your supervisor" :aria-invalid="err('reason') ? 'true' : undefined" :aria-describedby="describedBy('reason', 'lv-reason-count')"></textarea>
          <p v-if="err('reason')" id="lv-reason-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ err('reason') }}</p>
          <p v-else id="lv-reason-count" class="lv-hint lv-hint--right">{{ form.reason.length }} / {{ REASON_MAX }}</p>
        </div>

        <RequestFormParts v-model:approver-id="approverId" v-model:attachment="attachment" :approvers="approvers" :errors="{ ...serverErrors, ...errors }" />

        <footer class="lv-form__foot">
          <span></span>
          <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg" :disabled="saving"><AppIcon name="send" :size="17" />{{ saving ? 'Submitting…' : 'Submit request' }}</button>
        </footer>
      </form>

      <!-- Balances -->
      <aside class="lv-card lv-balance" aria-labelledby="lv-balance-title">
        <h2 id="lv-balance-title" class="lv-card__title">Leave balance</h2>
        <p v-if="balancesError" class="lv-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ balancesError }}</p>
        <ul v-else>
          <li v-for="item in capped" :key="item.type" :class="{ 'is-selected': !saved && form.type === item.type }">
            <div class="lv-balance__row">
              <span class="lv-balance__name"><AppIcon :name="item.icon" :size="16" />{{ item.label }}</span>
              <span class="lv-balance__left"><strong>{{ item.remaining }}</strong> of {{ item.total }} days left</span>
            </div>
            <div class="lv-track" role="progressbar" :aria-valuenow="item.used" aria-valuemin="0" :aria-valuemax="item.total" :aria-label="`${item.label} leave used`"><i :style="{ width: `${item.total ? Math.min((item.used / item.total) * 100, 100) : 0}%` }"></i></div>
            <small>{{ item.used }} used<template v-if="item.pending"> ({{ item.pending }} pending)</template></small>
          </li>
          <li v-if="balances.length > capped.length" class="lv-balance__other"><small>Other leave types have no fixed allowance on record.</small></li>
        </ul>
      </aside>
    </div>
  </div>
</template>

<style>
.lv { --lv-ink: #17365f; --lv-text: #3d5470; --lv-muted: #6b8099; --lv-line: #dfe7ef; --lv-soft: #f6f9fc; --lv-brand: #0b7f62; --lv-brand-dark: #086a51; --lv-brand-soft: #e8f5f0; --lv-danger: #c0392b; --lv-danger-soft: #fdf1f0; --lv-warning: #a35f00; width: min(100%, 1180px); margin: 0 auto; padding: 24px 0 48px; color: var(--lv-text); font-size: 14px; line-height: 1.5; }
.lv button { margin: 0; } /* older global styles give every button a top margin and large padding */
.lv h1, .lv h2 { color: var(--lv-ink); letter-spacing: -.01em; }
.lv-head { margin-bottom: 16px; }
.lv-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.lv-head p { margin: 0; color: var(--lv-muted); }

.lv-layout { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr); align-items: start; gap: 20px; }
.lv-card { border: 1px solid var(--lv-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.lv-card__title { margin: 0 0 18px; font-size: 1.1rem; font-weight: 700; }
.lv-form { display: grid; gap: 20px; padding: 24px 26px; }
.lv-form > .lv-card__title { margin: 0; }

/* Fields */
.lv-field { display: grid; align-content: start; gap: 6px; min-width: 0; margin: 0; padding: 0; border: 0; }
.lv-label { display: block; padding: 0; color: var(--lv-ink); font-size: .9rem; font-weight: 600; }
.lv-req { color: var(--lv-danger); }
.lv-optional { margin-left: 6px; padding: 1px 8px; border-radius: 99px; background: #eef2f6; color: var(--lv-muted); font-size: .72rem; font-weight: 600; }
.lv input[type="date"], .lv textarea { box-sizing: border-box; width: 100%; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--lv-ink); font: inherit; font-size: .95rem; transition: border-color .15s, box-shadow .15s; }
.lv input[type="date"] { height: 44px; padding: 0 12px; }
.lv textarea { min-height: 88px; padding: 10px 12px; resize: vertical; }
.lv input:focus, .lv textarea:focus { border-color: var(--lv-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.lv [aria-invalid="true"] { border-color: var(--lv-danger) !important; }
.lv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.lv-error { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--lv-danger); font-size: .82rem; font-weight: 500; }
.lv-error svg { flex: none; }
.lv-hint { margin: 0; color: var(--lv-muted); font-size: .82rem; }
.lv-hint--right { text-align: right; }
.lv-alert { display: flex; align-items: center; gap: 9px; margin: 0; padding: 10px 14px; border: 1px solid #f2c4bf; border-radius: 10px; background: var(--lv-danger-soft); color: #9b2c22; font-size: .9rem; font-weight: 600; }

/* Leave type cards */
.lv-types { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.lv-type { position: relative; display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid #cfdbe7; border-radius: 12px; background: #fff; cursor: pointer; transition: border-color .15s, background-color .15s, box-shadow .15s; }
.lv-type:hover { border-color: #9fb3c8; }
.lv-type input { position: absolute; opacity: 0; pointer-events: none; }
.lv-type:has(input:focus-visible) { box-shadow: 0 0 0 3px rgba(11, 127, 98, .25); }
.lv-type.is-selected { border-color: var(--lv-brand); background: var(--lv-brand-soft); box-shadow: inset 0 0 0 1px var(--lv-brand); }
.lv-type__icon { display: grid; width: 36px; height: 36px; flex: none; place-items: center; border-radius: 10px; background: var(--lv-soft); color: var(--lv-muted); }
.lv-type.is-selected .lv-type__icon { background: #fff; color: var(--lv-brand); }
.lv-type__text { display: grid; min-width: 0; }
.lv-type__text strong { color: var(--lv-ink); font-size: .95rem; }
.lv-type__text small { color: var(--lv-muted); font-size: .8rem; }

/* Duration summary */
.lv-duration { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 12px; background: var(--lv-brand-soft); color: var(--lv-brand-dark); }
.lv-duration svg { flex: none; margin-top: 2px; }
.lv-duration > div { display: grid; gap: 1px; }
.lv-duration strong { color: var(--lv-ink); font-size: 1.05rem; }
.lv-duration span { color: var(--lv-text); font-size: .88rem; }
.lv-duration small { color: var(--lv-brand-dark); font-size: .82rem; font-weight: 600; }
.lv-duration.is-empty { background: var(--lv-soft); color: var(--lv-muted); }
.lv-duration.is-over { background: #fff4dc; color: var(--lv-warning); }
.lv-duration.is-over small { color: var(--lv-warning); }

/* Attachment */
.lv-file { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px 14px; border: 1px dashed #b9c9d9; border-radius: 12px; background: var(--lv-soft); color: var(--lv-muted); }
.lv-file.has-file { border-style: solid; border-color: #bfe3d6; background: #f6fbf9; color: var(--lv-brand); }
.lv-file.has-error { border-color: var(--lv-danger); }
.lv-file__text { display: grid; min-width: 0; flex: 1; }
.lv-file__text strong { overflow: hidden; color: var(--lv-ink); font-size: .9rem; text-overflow: ellipsis; white-space: nowrap; }
.lv-file__text small { color: var(--lv-muted); font-size: .8rem; }

.lv-form__foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding-top: 18px; border-top: 1px solid var(--lv-line); }

/* Buttons */
.lv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 16px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .9rem; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; }
.lv-btn svg { flex: none; }
.lv-btn--sm { min-height: 36px; padding: 0 12px; font-size: .85rem; }
.lv-btn--lg { min-height: 48px; padding: 0 26px; font-size: .98rem; }
.lv-btn--primary { background: var(--lv-brand); color: #fff; box-shadow: 0 6px 16px rgba(11, 127, 98, .22); }
.lv-btn--primary:hover { background: var(--lv-brand-dark); }
.lv-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--lv-ink); }
.lv-btn--ghost:hover { border-color: #aebfd1; background: var(--lv-soft); }

/* Balance */
.lv-balance { padding: 22px; }
.lv-balance ul { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
.lv-balance li { padding: 12px 14px; border: 1px solid var(--lv-line); border-radius: 12px; transition: border-color .15s, background-color .15s; }
.lv-balance li.is-selected { border-color: #9fd6c3; background: #f6fbf9; }
.lv-balance__row { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.lv-balance__name { display: inline-flex; align-items: center; gap: 8px; color: var(--lv-ink); font-weight: 600; }
.lv-balance__name svg { color: var(--lv-muted); }
.lv-balance__left { color: var(--lv-muted); font-size: .85rem; white-space: nowrap; }
.lv-balance__left strong { color: var(--lv-ink); font-size: 1.15rem; }
.lv-track { height: 6px; margin: 8px 0 6px; overflow: hidden; border-radius: 99px; background: #e6edf3; }
.lv-track i { display: block; height: 100%; border-radius: inherit; background: var(--lv-brand); }
.lv-balance small { color: var(--lv-muted); font-size: .8rem; }

/* Confirmation */
.lv-done { display: grid; justify-items: center; padding: 36px 28px; text-align: center; }
.lv-done__icon { display: grid; width: 60px; height: 60px; place-items: center; margin-bottom: 12px; border-radius: 50%; background: var(--lv-brand-soft); color: var(--lv-brand); }
.lv-done h2 { margin: 0 0 6px; font-size: 1.3rem; outline: 0; }
.lv-done > p { max-width: 440px; margin: 0 0 18px; color: var(--lv-muted); }
.lv-summary { display: grid; width: min(100%, 460px); gap: 0; margin: 0 0 20px; padding: 4px 16px; border: 1px solid var(--lv-line); border-radius: 12px; background: var(--lv-soft); text-align: left; }
.lv-summary > div { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid var(--lv-line); }
.lv-summary > div:last-child { border-bottom: 0; }
.lv-summary dt { color: var(--lv-muted); font-size: .86rem; }
.lv-summary dd { min-width: 0; margin: 0; color: var(--lv-ink); font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.lv-done__actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }

/* Controls shared by the other request forms, in the same style as the leave form. */
.lv input.rq-input, .lv select.rq-select { box-sizing: border-box; width: 100%; height: 44px; padding: 0 12px; border: 1px solid #cfdbe7; border-radius: 10px; background-color: #fff; color: var(--lv-ink); font: inherit; font-size: .95rem; }
.lv select.rq-select:focus { border-color: var(--lv-brand); outline: 0; box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.rq-form { max-width: 760px; }
.rq-form--wide { max-width: 960px; }
.rq-month { display: grid; grid-template-columns: minmax(0, 1fr) 96px; gap: 8px; }
.rq-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.rq-chip { position: relative; display: inline-flex; min-height: 40px; align-items: center; padding: 0 16px; border: 1px solid #cfdbe7; border-radius: 99px; background: #fff; color: var(--lv-text); font-size: .9rem; cursor: pointer; }
.rq-chip input { position: absolute; opacity: 0; pointer-events: none; }
.rq-chip:has(input:focus-visible) { box-shadow: 0 0 0 3px rgba(11, 127, 98, .25); }
.rq-chip.is-selected { border-color: var(--lv-brand); background: var(--lv-brand-soft); color: var(--lv-brand-dark); font-weight: 600; }
.rq-costs { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.rq-money { display: flex; height: 44px; align-items: center; gap: 6px; padding: 0 12px; border: 1px solid #cfdbe7; border-radius: 10px; background: #fff; color: var(--lv-muted); }
.rq-money:focus-within { border-color: var(--lv-brand); box-shadow: 0 0 0 3px rgba(11, 127, 98, .14); }
.lv .rq-money input { width: 100%; min-width: 0; min-height: 0; padding: 0; border: 0; background: transparent; color: var(--lv-ink); font: inherit; box-shadow: none; }
.rq-total { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0; padding: 12px 14px; border-radius: 10px; background: var(--lv-brand-soft); color: var(--lv-text); font-size: .92rem; }
.rq-total strong { color: var(--lv-brand-dark); font-size: 1.05rem; }
.rq-table-wrap { overflow-x: auto; }
.rq-table { width: 100%; min-width: 620px; border-collapse: collapse; text-align: left; font-size: .9rem; }
.rq-table th { padding: 8px 10px; background: var(--lv-soft); color: var(--lv-muted); font-size: .78rem; font-weight: 600; white-space: nowrap; }
.rq-table td { padding: 8px 10px; border-top: 1px solid var(--lv-line); color: var(--lv-text); vertical-align: middle; }
.rq-table .num { text-align: right; white-space: nowrap; }
.rq-table__grow { width: 45%; }
.rq-add { justify-self: start; }
.lv-balance__other { border-style: dashed !important; }
.lv-btn:disabled { opacity: .6; cursor: progress; }

@media (max-width: 960px) {
  .lv-layout { grid-template-columns: 1fr; }
  .lv-balance ul { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .lv { padding-top: 16px; }
  .lv-head h1 { font-size: 1.35rem; }
  .lv-form { padding: 18px 16px; gap: 18px; }
  .lv-types { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .rq-costs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .lv-grid { grid-template-columns: 1fr; }
  .lv-balance { padding: 18px 16px; }
  .lv-balance ul { grid-template-columns: 1fr; }
  .lv-form__foot { flex-direction: column-reverse; align-items: stretch; }
  .lv-form__foot .lv-btn { width: 100%; }
  .lv-done { padding: 28px 16px; }
}
</style>
