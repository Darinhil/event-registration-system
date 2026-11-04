<script setup>
import { nextTick, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '../AppIcon.vue'

defineProps({ request: { type: Object, required: true } })
defineEmits(['again'])
onMounted(async () => { await nextTick(); document.getElementById('rq-done-title')?.focus() })
</script>

<template>
  <section class="lv-card lv-done" aria-labelledby="rq-done-title">
    <span class="lv-done__icon"><AppIcon name="check" :size="28" /></span>
    <h2 id="rq-done-title" tabindex="-1">Request submitted</h2>
    <p>{{ request.approver }} will review it. You can follow it in My Requests.</p>
    <dl class="lv-summary">
      <div><dt>Request</dt><dd>{{ request.type_label }}</dd></div>
      <div><dt>Details</dt><dd>{{ request.summary }}</dd></div>
      <div v-if="request.attachment_name"><dt>Attachment</dt><dd>{{ request.attachment_name }}</dd></div>
    </dl>
    <div class="lv-done__actions">
      <RouterLink :to="{ query: { section: 'my-requests' } }" class="lv-btn lv-btn--ghost">View My Requests</RouterLink>
      <button type="button" class="lv-btn lv-btn--primary" @click="$emit('again')"><AppIcon name="plus" :size="16" />New request</button>
    </div>
  </section>
</template>
