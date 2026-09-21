<script setup>
defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 18 },
})
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'calendar'">
      <rect x="3" y="4.5" width="18" height="16" rx="2" /><path d="M16 2.5v4M8 2.5v4M3 9.5h18" /><path d="M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01" />
    </template>
    <template v-else-if="name === 'user'">
      <circle cx="12" cy="8" r="3.5" /><path d="M4.5 20c.7-3.3 3.2-5 7.5-5s6.8 1.7 7.5 5" />
    </template>
    <template v-else-if="name === 'users'">
      <circle cx="9" cy="8" r="3" /><path d="M3.5 19c.5-3 2.3-4.5 5.5-4.5s5 1.5 5.5 4.5" /><path d="M15 5.5a3 3 0 0 1 0 5.8M17 14.8c2.1.5 3.3 1.8 3.6 4.2" />
    </template>
    <template v-else-if="name === 'clock'">
      <circle cx="12" cy="12" r="8.5" /><path d="M12 7v5l3.5 2" />
    </template>
    <template v-else-if="name === 'accessibility'">
      <circle cx="12" cy="4.5" r="1.8" /><path d="M5 8h14M12 7v12M8.5 20l3.5-7 3.5 7M8 10l4 3 4-3" />
    </template>
    <template v-else-if="name === 'pin'">
      <path d="M19 10c0 5-7 10-7 10S5 15 5 10a7 7 0 1 1 14 0Z" /><circle cx="12" cy="10" r="2.3" />
    </template>
    <template v-else-if="name === 'heart'">
      <path d="M20.8 8.8c0 5.2-8.8 10-8.8 10s-8.8-4.8-8.8-10A4.8 4.8 0 0 1 12 6.2a4.8 4.8 0 0 1 8.8 2.6Z" />
    </template>
    <template v-else-if="name === 'pen'">
      <path d="m4 16.5-.8 4.3 4.3-.8L19 8.5a2.8 2.8 0 0 0-4-4L4 16.5Z" /><path d="m13.5 6.5 4 4M3.5 20.5l3.8-.7" />
    </template>
    <template v-else-if="name === 'info'">
      <circle cx="12" cy="12" r="9" /><path d="M12 10.8v5M12 7.5h.01" />
    </template>
    <template v-else-if="name === 'arrow-left'">
      <path d="M19 12H5M11 6l-6 6 6 6" />
    </template>
    <template v-else-if="name === 'arrow-right'">
      <path d="M5 12h14M13 6l6 6-6 6" />
    </template>
  </svg>
</template>
