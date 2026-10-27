<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ balances: { type: Array, required: true } })
const showAll = ref(false)
// Types nobody is entitled to and nobody has used stay out of the way until asked for.
const relevant = (item) => item.entitlement > 0 || item.carried_over > 0 || item.used_before > 0 || item.pending > 0 || item.used_this_month > 0
const visible = computed(() => (showAll.value ? props.balances : props.balances.filter(relevant)))
const hiddenCount = computed(() => props.balances.filter((item) => !relevant(item)).length)
const n = (value) => Number(value).toString()
</script>

<template>
  <aside class="mt-card mt-balance" aria-labelledby="mt-balance-title">
    <h2 id="mt-balance-title" class="mt-card__title">Leave balance</h2>
    <p v-if="!visible.length" class="mt-muted">No leave entitlements are recorded for you this year.</p>
    <ul>
      <li v-for="item in visible" :key="item.type" :class="{ 'is-over': item.remaining < 0, 'is-live': item.used_this_month > 0 }">
        <div class="mt-balance__head">
          <strong>{{ item.label }}</strong>
          <span class="mt-balance__remaining" :aria-label="`${n(item.remaining)} days remaining`"><b>{{ n(item.remaining) }}</b> left</span>
        </div>
        <dl>
          <div><dt>Entitlement</dt><dd>{{ n(item.entitlement) }}</dd></div>
          <div><dt>Carried over</dt><dd>+ {{ n(item.carried_over) }}</dd></div>
          <div><dt>Used before</dt><dd>− {{ n(item.used_before) }}</dd></div>
          <div><dt>Pending approval</dt><dd>− {{ n(item.pending) }}</dd></div>
          <div class="is-month"><dt>This month</dt><dd>− {{ n(item.used_this_month) }}</dd></div>
        </dl>
        <p v-if="item.remaining < 0" class="mt-balance__warn" role="alert">This month’s leave is more than you have left.</p>
      </li>
    </ul>
    <button v-if="hiddenCount" type="button" class="mt-link" :aria-expanded="showAll" @click="showAll = !showAll">{{ showAll ? 'Show only my leave types' : `Show all leave types (${hiddenCount} more)` }}</button>
    <details class="mt-formula">
      <summary>How is “left” calculated?</summary>
      <p><b>Left</b> = entitlement for the year + days carried over − leave approved in other months − leave in timesheets still awaiting approval − leave marked this month. Days are in working days; a half day counts as 0.5.</p>
    </details>
  </aside>
</template>
