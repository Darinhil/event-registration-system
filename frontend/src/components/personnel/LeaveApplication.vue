<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '../AppIcon.vue'

const emit = defineEmits(['submitted'])

// The leave workflow has no API yet, so these are the same sample balances the page showed before.
const balances = [
  { type: 'Annual', entitled: 15, used: 3, icon: 'calendar' },
  { type: 'Sick', entitled: 10, used: 5, icon: 'heart' },
  { type: 'Other', entitled: 5, used: 2, icon: 'circle-dot' },
].map((item) => ({ ...item, remaining: item.entitled - item.used }))

const MAX_FILE = 5 * 1024 * 1024
const REASON_MAX = 500
const form = reactive({ type: 'Annual', start: '', end: '', reason: '', file: null })
const errors = ref({})
const tried = ref(false)
const submitted = ref(null)
const fileInput = ref(null)
const formEl = ref(null)

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
const selected = computed(() => balances.find((item) => item.type === form.type))
const overBalance = computed(() => duration.value && duration.value.working > selected.value.remaining)
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`

/* ---------- Validation ---------- */
const validate = () => {
  const e = {}
  if (!form.start) e.start = 'Choose the first day of your leave.'
  if (!form.end) e.end = 'Choose the last day of your leave.'
  else if (form.start && form.end < form.start) e.end = 'The end date can’t be before the start date.'
  if (duration.value && duration.value.working === 0) e.end = 'These dates fall on a weekend — choose at least one working day.'
  if (overBalance.value) e.end = `This is ${plural(duration.value.working, 'working day')}, but you have ${plural(selected.value.remaining, 'day')} of ${form.type.toLowerCase()} leave left.`
  if (form.reason.length > REASON_MAX) e.reason = `Keep the reason under ${REASON_MAX} characters.`
  if (form.file && form.file.size > MAX_FILE) e.file = 'The attachment must be 5 MB or smaller.'
  errors.value = e
  return Object.keys(e).length === 0
}
// After the first attempt, re-check as the person fixes things so errors clear immediately.
watch(form, () => { if (tried.value) validate() }, { deep: true })
const err = (key) => errors.value[key] || ''
const describedBy = (key, hint) => (err(key) ? `lv-${key}-error` : hint || undefined)

const pickFile = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (!/\.(pdf|jpe?g|png|webp)$/i.test(file.name)) { errors.value = { ...errors.value, file: 'Attach a PDF or an image (JPG, PNG or WebP).' }; return }
  form.file = file
  if (!tried.value) { const { file: _, ...rest } = errors.value; errors.value = rest }
}
const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`)

const submit = async () => {
  tried.value = true
  if (!validate()) {
    await nextTick()
    // Fields first; an attachment problem has no input to mark, so focus its button instead.
    const target = formEl.value?.querySelector('[aria-invalid="true"]') || (errors.value.file && formEl.value?.querySelector('.lv-file .lv-btn:last-of-type'))
    target?.focus()
    return
  }
  submitted.value = { type: form.type, start: form.start, end: form.end, days: duration.value.working, reason: form.reason.trim(), file: form.file?.name || '' }
  emit('submitted', submitted.value)
  await nextTick()
  document.getElementById('lv-done-title')?.focus()
}
const startAnother = () => {
  Object.assign(form, { type: 'Annual', start: '', end: '', reason: '', file: null })
  errors.value = {}
  tried.value = false
  submitted.value = null
}
</script>

<template>
  <div class="lv">
    <header class="lv-head">
      <h1>Leave Application</h1>
      <p>Request time off. Your supervisor reviews it first, then HR gives final approval.</p>
    </header>

    <p class="lv-note" role="note"><AppIcon name="info" :size="18" /><span><strong>Preview.</strong> The leave workflow isn’t connected yet — balances are sample figures and requests aren’t sent to your supervisor.</span></p>

    <div class="lv-layout">
      <!-- Request form, or the confirmation once submitted -->
      <section v-if="submitted" class="lv-card lv-done" aria-labelledby="lv-done-title">
        <span class="lv-done__icon"><AppIcon name="check" :size="28" /></span>
        <h2 id="lv-done-title" tabindex="-1">Leave request recorded</h2>
        <p>It’s listed in <strong>My Requests</strong> as pending. In the live portal it would now go to your supervisor.</p>
        <dl class="lv-summary">
          <div><dt>Leave type</dt><dd>{{ submitted.type }}</dd></div>
          <div><dt>Dates</dt><dd>{{ fmt(submitted.start) }} – {{ fmt(submitted.end) }}</dd></div>
          <div><dt>Working days</dt><dd>{{ submitted.days }}</dd></div>
          <div v-if="submitted.reason"><dt>Reason</dt><dd>{{ submitted.reason }}</dd></div>
          <div v-if="submitted.file"><dt>Attachment</dt><dd>{{ submitted.file }}</dd></div>
        </dl>
        <div class="lv-done__actions">
          <RouterLink :to="{ query: { section: 'my-requests' } }" class="lv-btn lv-btn--ghost">View My Requests</RouterLink>
          <button type="button" class="lv-btn lv-btn--primary" @click="startAnother"><AppIcon name="plus" :size="16" />New request</button>
        </div>
      </section>

      <form v-else ref="formEl" class="lv-card lv-form" novalidate @submit.prevent="submit">
        <h2 class="lv-card__title">New request</h2>
        <p v-if="tried && Object.keys(errors).length" class="lv-alert" role="alert"><AppIcon name="alert-circle" :size="18" />Please fix {{ Object.keys(errors).length === 1 ? 'the highlighted field' : `the ${Object.keys(errors).length} highlighted fields` }}.</p>

        <fieldset class="lv-field">
          <legend class="lv-label">Leave type <span class="lv-req" aria-hidden="true">*</span></legend>
          <div class="lv-types">
            <label v-for="item in balances" :key="item.type" class="lv-type" :class="{ 'is-selected': form.type === item.type }">
              <input v-model="form.type" type="radio" name="lv-type" :value="item.type" />
              <span class="lv-type__icon"><AppIcon :name="item.icon" :size="18" /></span>
              <span class="lv-type__text"><strong>{{ item.type }}</strong><small>{{ plural(item.remaining, 'day') }} left</small></span>
            </label>
          </div>
        </fieldset>

        <div class="lv-grid">
          <div class="lv-field">
            <label for="lv-start" class="lv-label">Start date <span class="lv-req" aria-hidden="true">*</span></label>
            <input id="lv-start" v-model="form.start" type="date" required :aria-invalid="err('start') ? 'true' : undefined" :aria-describedby="describedBy('start')" />
            <p v-if="err('start')" id="lv-start-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ err('start') }}</p>
          </div>
          <div class="lv-field">
            <label for="lv-end" class="lv-label">End date <span class="lv-req" aria-hidden="true">*</span></label>
            <input id="lv-end" v-model="form.end" type="date" required :min="form.start || undefined" :aria-invalid="err('end') ? 'true' : undefined" :aria-describedby="describedBy('end')" />
            <p v-if="err('end')" id="lv-end-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ err('end') }}</p>
          </div>
        </div>

        <div class="lv-duration" :class="{ 'is-empty': !duration, 'is-over': overBalance }" aria-live="polite">
          <AppIcon :name="overBalance ? 'alert-circle' : 'clock'" :size="20" />
          <div v-if="duration">
            <strong>{{ plural(duration.working, 'working day') }}</strong>
            <span>{{ fmt(form.start, { year: form.start.slice(0, 4) !== form.end.slice(0, 4) }) }} – {{ fmt(form.end) }}<template v-if="duration.calendar !== duration.working"> · {{ plural(duration.calendar - duration.working, 'weekend day') }} not counted</template></span>
            <small>{{ overBalance ? `More than your ${selected.remaining} remaining ${form.type.toLowerCase()} days` : `Leaves ${plural(selected.remaining - duration.working, 'day')} of ${form.type.toLowerCase()} leave` }}</small>
          </div>
          <div v-else><strong>Choose your dates</strong><span>We’ll count the working days for you.</span></div>
        </div>

        <div class="lv-field">
          <label for="lv-reason" class="lv-label">Reason <span class="lv-optional">Optional</span></label>
          <textarea id="lv-reason" v-model="form.reason" rows="3" :maxlength="REASON_MAX + 50" placeholder="A short note for your supervisor" :aria-invalid="err('reason') ? 'true' : undefined" :aria-describedby="describedBy('reason', 'lv-reason-count')"></textarea>
          <p v-if="err('reason')" id="lv-reason-error" class="lv-error"><AppIcon name="alert-circle" :size="14" />{{ err('reason') }}</p>
          <p v-else id="lv-reason-count" class="lv-hint lv-hint--right">{{ form.reason.length }} / {{ REASON_MAX }}</p>
        </div>

        <div class="lv-field">
          <span id="lv-file-label" class="lv-label">Attachment <span class="lv-optional">Optional</span></span>
          <div class="lv-file" :class="{ 'has-error': err('file'), 'has-file': form.file }">
            <AppIcon :name="form.file ? 'file-text' : 'upload'" :size="20" />
            <div class="lv-file__text">
              <strong>{{ form.file ? form.file.name : 'Supporting document' }}</strong>
              <small>{{ form.file ? fileSize(form.file.size) : 'PDF or image, up to 5 MB — e.g. a medical certificate' }}</small>
            </div>
            <button v-if="form.file" type="button" class="lv-btn lv-btn--ghost lv-btn--sm" @click="form.file = null">Remove</button>
            <button type="button" class="lv-btn lv-btn--ghost lv-btn--sm" aria-describedby="lv-file-label" @click="fileInput?.click()">{{ form.file ? 'Replace' : 'Choose file' }}</button>
            <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="sr-only" tabindex="-1" aria-hidden="true" @change="pickFile" />
          </div>
          <p v-if="err('file')" id="lv-file-error" class="lv-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ err('file') }}</p>
        </div>

        <footer class="lv-form__foot">
          <p class="lv-hint">Fields marked <span class="lv-req">*</span> are required.</p>
          <button type="submit" class="lv-btn lv-btn--primary lv-btn--lg"><AppIcon name="send" :size="17" />Submit request</button>
        </footer>
      </form>

      <!-- Balances -->
      <aside class="lv-card lv-balance" aria-labelledby="lv-balance-title">
        <h2 id="lv-balance-title" class="lv-card__title">Leave balance</h2>
        <ul>
          <li v-for="item in balances" :key="item.type" :class="{ 'is-selected': !submitted && form.type === item.type }">
            <div class="lv-balance__row">
              <span class="lv-balance__name"><AppIcon :name="item.icon" :size="16" />{{ item.type }}</span>
              <span class="lv-balance__left"><strong>{{ item.remaining }}</strong> of {{ item.entitled }} days left</span>
            </div>
            <div class="lv-track" role="progressbar" :aria-valuenow="item.used" aria-valuemin="0" :aria-valuemax="item.entitled" :aria-label="`${item.type} leave used`"><i :style="{ width: `${(item.used / item.entitled) * 100}%` }"></i></div>
            <small>{{ item.used }} used · {{ item.entitled }} entitled</small>
          </li>
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
.lv-note { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 20px; padding: 11px 14px; border: 1px solid #f1dfae; border-radius: 10px; background: #fffaeb; color: #7a5a12; font-size: .9rem; }
.lv-note svg { flex: none; margin-top: 2px; }

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
.lv-types { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
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

@media (max-width: 960px) {
  .lv-layout { grid-template-columns: 1fr; }
  .lv-balance ul { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .lv { padding-top: 16px; }
  .lv-head h1 { font-size: 1.35rem; }
  .lv-form { padding: 18px 16px; gap: 18px; }
  .lv-types { grid-template-columns: 1fr; }
  .lv-grid { grid-template-columns: 1fr; }
  .lv-balance { padding: 18px 16px; }
  .lv-balance ul { grid-template-columns: 1fr; }
  .lv-form__foot { flex-direction: column-reverse; align-items: stretch; }
  .lv-form__foot .lv-btn { width: 100%; }
  .lv-done { padding: 28px 16px; }
}
</style>
