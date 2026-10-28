<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '../../AppIcon.vue'
import { formatHours } from '../../../utils/timesheet'

// Side panel for one day: hours, leave, note, attachment — or, for approvers, a comment.
const props = defineProps({
  day: { type: Number, default: null },
  info: { type: Object, default: null },
  entry: { type: Object, default: () => ({}) },
  state: { type: Object, default: () => ({}) },
  label: { type: String, default: '' },
  leaveTypes: { type: Object, required: true },
  balances: { type: Array, required: true },
  rules: { type: Object, required: true },
  mode: { type: String, default: 'edit' }, // edit | view | review
  approverComment: { type: String, default: '' },
  uploading: Boolean,
  uploadError: { type: String, default: '' },
  canPrev: Boolean,
  canNext: Boolean,
})
const emit = defineEmits(['close', 'change', 'upload', 'download', 'comment', 'prev', 'next'])
const dialog = ref(null)
const fileInput = ref(null)
const title = ref(null)

watch(() => props.day, async (day) => {
  if (day && !dialog.value?.open) { await nextTick(); dialog.value?.showModal() }
  if (!day && dialog.value?.open) dialog.value.close()
  await nextTick()
  title.value?.focus()
})
const remaining = (type) => props.balances.find((item) => item.type === type)?.remaining
const hoursMax = computed(() => (props.entry.leave_type && props.entry.leave_part !== 'full' ? props.rules.half_day_max : 24))
const set = (patch) => emit('change', props.day, patch)
const setLeave = (type) => set(type ? { leave_type: type, leave_part: props.entry.leave_part || 'full', hours: (props.entry.leave_part || 'full') === 'full' ? null : Math.min(Number(props.entry.hours || 0), props.rules.half_day_max) || null } : { leave_type: null, leave_part: null })
const setPart = (part) => set({ leave_part: part, hours: part === 'full' ? null : (props.entry.hours ? Math.min(Number(props.entry.hours), props.rules.half_day_max) : props.rules.half_day_max) })
const pickFile = (event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) emit('upload', props.day, file) }
const onBackdrop = (event) => { if (event.target === dialog.value) emit('close') }
const comment = ref('')
watch(() => [props.day, props.approverComment], () => { comment.value = props.approverComment || '' }, { immediate: true })
</script>

<template>
  <dialog ref="dialog" class="mt-panel" aria-labelledby="mt-panel-title" @close="emit('close')" @click="onBackdrop">
    <div v-if="day && info" class="mt-panel__inner">
      <header class="mt-panel__head">
        <div>
          <p class="mt-eyebrow">{{ info.holiday ? 'Public holiday' : info.weekend ? 'Weekend' : 'Working day' }}</p>
          <h2 id="mt-panel-title" ref="title" tabindex="-1">{{ label }}</h2>
        </div>
        <div class="mt-panel__nav">
          <button type="button" class="mt-icon-btn" :disabled="!canPrev" aria-label="Previous day" @click="emit('prev')"><AppIcon name="chevron-left" :size="18" /></button>
          <button type="button" class="mt-icon-btn" :disabled="!canNext" aria-label="Next day" @click="emit('next')"><AppIcon name="chevron-right" :size="18" /></button>
          <button type="button" class="mt-icon-btn" aria-label="Close" @click="emit('close')"><AppIcon name="x" :size="20" /></button>
        </div>
      </header>

      <div class="mt-panel__body">
        <p v-if="approverComment && mode !== 'review'" class="mt-callout is-comment"><AppIcon name="info" :size="16" /><span><strong>Approver’s comment:</strong> {{ approverComment }}</span></p>
        <ul v-if="state.issues?.length" class="mt-panel__issues">
          <li v-for="issue in state.issues" :key="issue.message" :class="`is-${issue.level}`"><AppIcon name="alert-circle" :size="15" />{{ issue.message }}</li>
        </ul>

        <!-- Weekends and holidays: no hours here -->
        <template v-if="!info.working">
          <p class="mt-muted">{{ info.holiday ? `${info.holiday} is a public holiday` : 'This is a weekend' }}, so no hours are expected.</p>
          <p v-if="mode === 'edit'" class="mt-callout"><AppIcon name="timer" :size="16" /><span>Worked on this day? Hours on non-working days are paid through an overtime request. <RouterLink :to="{ query: { section: 'overtime-request' } }">Request overtime</RouterLink></span></p>
        </template>

        <!-- Read-only view -->
        <dl v-else-if="mode !== 'edit'" class="mt-panel__facts">
          <div><dt>Hours</dt><dd>{{ entry.hours ? formatHours(entry.hours) : '—' }}</dd></div>
          <div><dt>Leave</dt><dd>{{ entry.leave_type ? `${leaveTypes[entry.leave_type]} · ${entry.leave_part === 'full' ? 'Full day' : entry.leave_part === 'am' ? 'Morning' : 'Afternoon'}` : 'None' }}</dd></div>
          <div v-if="entry.note"><dt>Note</dt><dd>{{ entry.note }}</dd></div>
          <div v-if="entry.attachment"><dt>Attachment</dt><dd><button type="button" class="mt-link" @click="emit('download', day)">{{ entry.attachment.name }}</button></dd></div>
        </dl>

        <!-- Editing -->
        <template v-else>
          <div class="mt-field">
            <label for="mt-panel-leave" class="mt-label">Leave</label>
            <select id="mt-panel-leave" :value="entry.leave_type || ''" @change="setLeave($event.target.value || null)">
              <option value="">No leave — working day</option>
              <option v-for="(name, key) in leaveTypes" :key="key" :value="key">{{ name }}{{ remaining(key) !== undefined ? ` (${remaining(key)} left)` : '' }}</option>
            </select>
          </div>
          <fieldset v-if="entry.leave_type" class="mt-field mt-parts">
            <legend class="mt-label">How long?</legend>
            <label v-for="part in [['full', 'Full day'], ['am', 'Morning'], ['pm', 'Afternoon']]" :key="part[0]" :class="{ 'is-checked': entry.leave_part === part[0] }">
              <input type="radio" name="mt-panel-part" :value="part[0]" :checked="entry.leave_part === part[0]" @change="setPart(part[0])" />{{ part[1] }}
            </label>
          </fieldset>
          <div class="mt-field">
            <label for="mt-panel-hours" class="mt-label">Hours worked</label>
            <input id="mt-panel-hours" type="number" inputmode="decimal" min="0" :max="hoursMax" step="0.25" :disabled="entry.leave_part === 'full'" :value="entry.hours ?? ''" placeholder="0" @change="set({ hours: $event.target.value === '' ? null : Number($event.target.value) })" />
            <p class="mt-hint">{{ entry.leave_part === 'full' ? 'A full day of leave has no working hours.' : entry.leave_type ? `Half day — up to ${rules.half_day_max} h.` : `Standard day: ${rules.standard_hours} h, after the unpaid lunch break.` }}</p>
          </div>
          <div class="mt-field">
            <label for="mt-panel-note" class="mt-label">Note <span class="mt-optional">Optional</span></label>
            <textarea id="mt-panel-note" rows="2" maxlength="500" :value="entry.note || ''" placeholder="e.g. Field visit to Kampong Cham" @change="set({ note: $event.target.value })"></textarea>
          </div>
          <div class="mt-field">
            <span class="mt-label" id="mt-panel-file-label">Attachment <span class="mt-optional">Optional</span></span>
            <div class="mt-file">
              <AppIcon :name="entry.attachment ? 'file-text' : 'upload'" :size="18" />
              <span class="mt-file__name">{{ uploading ? 'Uploading…' : entry.attachment ? entry.attachment.name : 'e.g. a medical certificate (PDF, JPG, PNG, max 5 MB)' }}</span>
              <button v-if="entry.attachment" type="button" class="mt-btn mt-btn--ghost mt-btn--sm" @click="set({ attachment: null })">Remove</button>
              <button type="button" class="mt-btn mt-btn--ghost mt-btn--sm" aria-describedby="mt-panel-file-label" :disabled="uploading" @click="fileInput?.click()">{{ entry.attachment ? 'Replace' : 'Upload' }}</button>
              <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" tabindex="-1" aria-hidden="true" @change="pickFile" />
            </div>
            <p v-if="uploadError" class="mt-error" role="alert"><AppIcon name="alert-circle" :size="14" />{{ uploadError }}</p>
          </div>
        </template>

        <!-- Approver commenting on a day -->
        <div v-if="mode === 'review' && info.working" class="mt-field">
          <label for="mt-panel-comment" class="mt-label">Comment for the staff member <span class="mt-optional">Sent if you return the timesheet</span></label>
          <textarea id="mt-panel-comment" v-model="comment" rows="2" maxlength="500" placeholder="e.g. You were at the workshop all day — please enter 6.5 h" @change="emit('comment', day, comment)"></textarea>
        </div>
      </div>
      <footer class="mt-panel__foot"><button type="button" class="mt-btn mt-btn--primary" @click="mode === 'review' && emit('comment', day, comment); emit('close')">Done</button></footer>
    </div>
  </dialog>
</template>
