<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from '../../AppIcon.vue'
import { WEEKDAYS, formatHours, weeksOf } from '../../../utils/timesheet'

const props = defineProps({
  year: { type: Number, required: true },
  month: { type: Number, required: true },
  days: { type: Array, required: true },
  entries: { type: Object, required: true },
  analysis: { type: Object, required: true },
  leaveTypes: { type: Object, required: true },
  readonly: Boolean,
  selected: { type: Array, default: () => [] },
  dayComments: { type: Object, default: () => ({}) },
  activeDay: { type: Number, default: null },
})
const emit = defineEmits(['open', 'select', 'hours'])

// Phones get a list grouped by week instead of a 7-column grid.
const narrow = ref(false)
let media
const sync = () => { narrow.value = media.matches }
onMounted(() => { media = window.matchMedia('(max-width: 760px)'); sync(); media.addEventListener('change', sync) })
onBeforeUnmount(() => media?.removeEventListener('change', sync))

const weeks = computed(() => weeksOf(props.days))
const weekTotal = (week) => week.reduce((sum, info) => sum + (info ? Number(props.entries[info.day]?.hours || 0) : 0), 0)
const focusDay = ref(props.days.find((info) => info.working)?.day || 1) // roving tab stop

const kindLabel = (info) => {
  const state = props.analysis.stateOf[info.day] || {}
  const entry = props.entries[info.day] || {}
  if (state.kind === 'holiday') return { icon: 'calendar', text: info.holiday, tone: 'holiday' }
  if (state.kind === 'weekend') return { icon: 'circle-dot', text: 'Weekend', tone: 'weekend' }
  if (state.kind === 'leave') return { icon: 'calendar-check', text: `${props.leaveTypes[entry.leave_type]} leave`, tone: 'leave' }
  if (state.kind === 'half') return { icon: 'calendar-check', text: `${props.leaveTypes[entry.leave_type]} · ${entry.leave_part === 'am' ? 'AM' : 'PM'}`, tone: 'leave' }
  if (state.issues?.some((issue) => issue.level === 'error')) return { icon: 'alert-circle', text: state.kind === 'missing' ? 'Missing' : 'Check', tone: 'error' }
  return null
}
const hasWarning = (day) => props.analysis.stateOf[day]?.issues?.some((issue) => issue.level === 'warning')
const hasError = (day) => props.analysis.stateOf[day]?.issues?.some((issue) => issue.level === 'error')
const canType = (info) => !props.readonly && info.working && props.entries[info.day]?.leave_part !== 'full'
const dayName = (info) => new Date(props.year, props.month - 1, info.day).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })
const describe = (info) => [dayName(info), kindLabel(info)?.text, props.entries[info.day]?.hours ? formatHours(props.entries[info.day].hours) : '', props.dayComments[info.day] ? 'has approver comment' : '', props.selected.includes(info.day) ? 'selected' : ''].filter(Boolean).join(', ')

const onCellClick = (event, info) => {
  if (event.target.closest('input')) return
  focusDay.value = info.day
  if (event.shiftKey && !props.readonly) emit('select', info.day, true)
  else emit('open', info.day)
}
const move = async (from, delta) => {
  const target = Math.min(props.days.length, Math.max(1, from + delta))
  focusDay.value = target
  await nextTick()
  document.querySelector(`[data-day-cell="${target}"]`)?.focus()
}
const onKeydown = (event, info) => {
  if (event.target.tagName === 'INPUT') { if (event.key === 'Escape') event.target.closest('[data-day-cell]')?.focus(); return }
  const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: narrow.value ? -1 : -7, ArrowDown: narrow.value ? 1 : 7 }[event.key]
  if (step) { event.preventDefault(); move(info.day, step); return }
  if (event.key === 'Home') { event.preventDefault(); move(info.day, -31) }
  if (event.key === 'End') { event.preventDefault(); move(info.day, 31) }
  if (event.key === 'Enter') { event.preventDefault(); emit('open', info.day) }
  if (event.key === ' ' && !props.readonly) { event.preventDefault(); emit('select', info.day, false) }
}
const onHours = (info, event) => emit('hours', info.day, event.target.value === '' ? null : Number(event.target.value))
</script>

<template>
  <!-- Desktop / tablet: the whole month at once -->
  <div v-if="!narrow" class="mt-cal" role="grid" :aria-label="`Timesheet for ${new Date(year, month - 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })}. Use arrow keys to move between days, Enter to open a day${readonly ? '' : ', Space to select'}.`">
    <div class="mt-cal__row mt-cal__head" role="row">
      <span v-for="name in WEEKDAYS" :key="name" role="columnheader" :class="{ 'is-weekend': name === 'Sat' || name === 'Sun' }">{{ name }}</span>
      <span role="columnheader" class="mt-cal__total-head">Week</span>
    </div>
    <div v-for="(week, index) in weeks" :key="index" class="mt-cal__row" role="row">
      <template v-for="(info, slot) in week" :key="info ? info.day : `blank-${index}-${slot}`">
        <div v-if="!info" class="mt-day is-blank" role="gridcell" aria-hidden="true"></div>
        <div
          v-else
          class="mt-day"
          :class="[`is-${analysis.stateOf[info.day]?.kind}`, { 'has-error': hasError(info.day), 'has-warning': hasWarning(info.day), 'is-selected': selected.includes(info.day), 'is-active': activeDay === info.day, 'has-comment': dayComments[info.day] }]"
          role="gridcell"
          :tabindex="focusDay === info.day ? 0 : -1"
          :data-day-cell="info.day"
          :aria-label="describe(info)"
          :aria-selected="selected.includes(info.day) ? 'true' : undefined"
          @click="onCellClick($event, info)"
          @keydown="onKeydown($event, info)"
          @focus="focusDay = info.day"
        >
          <div class="mt-day__top">
            <span class="mt-day__date">{{ info.day }}</span>
            <AppIcon v-if="dayComments[info.day]" name="info" :size="14" class="mt-day__comment" />
            <AppIcon v-else-if="hasWarning(info.day)" name="alert-circle" :size="14" class="mt-day__warn" />
          </div>
          <span v-if="kindLabel(info)" class="mt-tag" :class="`is-${kindLabel(info).tone}`" :title="kindLabel(info).text"><AppIcon :name="kindLabel(info).icon" :size="12" /><span>{{ kindLabel(info).text }}</span></span>
          <input
            v-if="canType(info)"
            class="mt-day__hours"
            type="number" inputmode="decimal" min="0" :max="entries[info.day]?.leave_type ? 3.25 : 24" step="0.25"
            :value="entries[info.day]?.hours ?? ''"
            placeholder="0"
            :aria-label="`Hours on ${dayName(info)}`"
            :aria-invalid="hasError(info.day) ? 'true' : undefined"
            tabindex="-1"
            @change="onHours(info, $event)"
            @focus="focusDay = info.day"
          />
          <span v-else-if="info.working && entries[info.day]?.hours" class="mt-day__value">{{ formatHours(entries[info.day].hours) }}</span>
        </div>
      </template>
      <div class="mt-cal__total" role="gridcell" :aria-label="`Week total ${formatHours(weekTotal(week))}`">{{ formatHours(weekTotal(week)) }}</div>
    </div>
  </div>

  <!-- Phones: one row per day, grouped by week -->
  <div v-else class="mt-list">
    <section v-for="(week, index) in weeks" :key="index" class="mt-list__week" :aria-label="`Week ${index + 1}`">
      <header><span>Week {{ index + 1 }}</span><strong>{{ formatHours(weekTotal(week)) }}</strong></header>
      <template v-for="info in week.filter(Boolean)" :key="info.day">
        <div
          class="mt-row"
          :class="[`is-${analysis.stateOf[info.day]?.kind}`, { 'has-error': hasError(info.day), 'is-selected': selected.includes(info.day), 'has-comment': dayComments[info.day] }]"
          :data-day-cell="info.day"
          :tabindex="focusDay === info.day ? 0 : -1"
          :aria-label="describe(info)"
          @click="onCellClick($event, info)"
          @keydown="onKeydown($event, info)"
        >
          <span class="mt-row__date"><strong>{{ info.day }}</strong><small>{{ WEEKDAYS[info.weekday - 1] }}</small></span>
          <span class="mt-row__status">
            <span v-if="kindLabel(info)" class="mt-tag" :class="`is-${kindLabel(info).tone}`"><AppIcon :name="kindLabel(info).icon" :size="12" /><span>{{ kindLabel(info).text }}</span></span>
            <AppIcon v-if="dayComments[info.day]" name="info" :size="14" class="mt-day__comment" />
          </span>
          <input v-if="canType(info)" class="mt-day__hours" type="number" inputmode="decimal" min="0" step="0.25" :value="entries[info.day]?.hours ?? ''" placeholder="0" :aria-label="`Hours on ${dayName(info)}`" :aria-invalid="hasError(info.day) ? 'true' : undefined" @change="onHours(info, $event)" />
          <span v-else class="mt-day__value">{{ info.working && entries[info.day]?.hours ? formatHours(entries[info.day].hours) : '' }}</span>
          <AppIcon name="chevron-right" :size="16" class="mt-row__chev" />
        </div>
      </template>
    </section>
  </div>
</template>
