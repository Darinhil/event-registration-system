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
    <template v-else-if="name === 'trash'">
      <path d="M4 7h16M10 11v6M14 11v6M5.5 7l1 13h11l1-13M9 7V4h6v3" />
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
    <template v-else-if="name === 'ticket'">
      <path d="M3 9V7.5A1.5 1.5 0 0 1 4.5 6h15A1.5 1.5 0 0 1 21 7.5V9a3 3 0 0 0 0 6v1.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 16.5V15a3 3 0 0 0 0-6Z" /><path d="M14 6v2.2M14 11v2M14 15.8V18" />
    </template>
    <template v-else-if="name === 'check-circle'">
      <circle cx="12" cy="12" r="8.6" /><path d="m8.4 12.2 2.4 2.4 4.8-4.9" />
    </template>
    <template v-else-if="name === 'lock'">
      <rect x="5" y="10.5" width="14" height="9.5" rx="2" /><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7M12 14.5v2" />
    </template>
    <template v-else-if="name === 'mail'">
      <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" />
    </template>
    <template v-else-if="name === 'inbox'">
      <path d="M4 13.5 6.2 5.6A1.6 1.6 0 0 1 7.7 4.5h8.6a1.6 1.6 0 0 1 1.5 1.1L20 13.5V18a1.6 1.6 0 0 1-1.6 1.6H5.6A1.6 1.6 0 0 1 4 18Z" /><path d="M4 13.5h4.6a3.4 3.4 0 0 0 6.8 0H20" />
    </template>
    <template v-else-if="name === 'user-plus'">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M19 8v6M22 11h-6" />
    </template>
    <template v-else-if="name === 'user-check'">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="m16 11 2 2 4-4" />
    </template>
    <template v-else-if="name === 'users-group'">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
    </template>
    <template v-else-if="name === 'calendar-plus'">
      <rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18M12 14v5M9.5 16.5h5" />
    </template>
    <template v-else-if="name === 'calendar-check'">
      <rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /><path d="m9 16 2 2 4-4" />
    </template>
    <template v-else-if="name === 'scan'">
      <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2" /><rect x="7.5" y="7.5" width="3.5" height="3.5" rx=".6" /><rect x="13" y="7.5" width="3.5" height="3.5" rx=".6" /><rect x="7.5" y="13" width="3.5" height="3.5" rx=".6" /><path d="M13 13h1.5v1.5M16.5 16.5h-2" />
    </template>
    <template v-else-if="name === 'chart-column'">
      <path d="M3 3v16a2 2 0 0 0 2 2h16" /><path d="M18 17V9M13 17V5M8 17v-3" />
    </template>
    <template v-else-if="name === 'chart-pie'">
      <path d="M21 12a1 1 0 0 0 1-1.1 10 10 0 0 0-8.9-8.9A1 1 0 0 0 12 3v8a1 1 0 0 0 1 1Z" /><path d="M21.2 15.9A10 10 0 1 1 8 2.8" />
    </template>
    <template v-else-if="name === 'trending-up'">
      <path d="m22 7-8.5 8.5-5-5L2 17" /><path d="M16 7h6v6" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M12 5v14M5 12h14" />
    </template>
    <template v-else-if="name === 'refresh'">
      <path d="M21 12a9 9 0 1 1-9-9c2.5 0 4.9 1 6.7 2.7L21 8" /><path d="M21 3v5h-5" />
    </template>
    <template v-else-if="name === 'chevron-right'">
      <path d="m9 18 6-6-6-6" />
    </template>
    <template v-else-if="name === 'alert-circle'">
      <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
    </template>
    <template v-else-if="name === 'log-out'">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5M21 12H9" />
    </template>
    <template v-else-if="name === 'timer'">
      <path d="M10 2h4M12 14l3-3" /><circle cx="12" cy="14" r="8" />
    </template>
    <template v-else-if="name === 'plane'">
      <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2Z" />
    </template>
    <template v-else-if="name === 'cart'">
      <circle cx="8" cy="21" r="1" /><circle cx="19" cy="21" r="1" /><path d="M2 2h2l2.7 12.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L22 7H5.1" />
    </template>
    <template v-else-if="name === 'fuel'">
      <path d="M3 22h12M4 9h10M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18" /><path d="M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 4 0V9.8a2 2 0 0 0-.6-1.4L18 5" />
    </template>
    <template v-else-if="name === 'receipt'">
      <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z" /><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11" />
    </template>
    <template v-else-if="name === 'shield-check'">
      <path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.7 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1Z" /><path d="m9 12 2 2 4-4" />
    </template>
    <template v-else-if="name === 'graduation'">
      <path d="M21.4 10.9a1 1 0 0 0 0-1.8L12.8 5.2a2 2 0 0 0-1.7 0L2.6 9.1a1 1 0 0 0 0 1.8l8.6 3.9a2 2 0 0 0 1.7 0Z" /><path d="M22 10v6M6 12.5V16a6 3 0 0 0 12 0v-3.5" />
    </template>
    <template v-else-if="name === 'file-text'">
      <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" /><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8" />
    </template>
    <template v-else-if="name === 'layout-grid'">
      <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />
    </template>
    <template v-else-if="name === 'list'">
      <path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01" />
    </template>
    <template v-else-if="name === 'eye'">
      <path d="M2.1 12.3a1 1 0 0 1 0-.6 10.8 10.8 0 0 1 19.8 0 1 1 0 0 1 0 .6 10.8 10.8 0 0 1-19.8 0" /><circle cx="12" cy="12" r="3" />
    </template>
    <template v-else-if="name === 'eye-off'">
      <path d="M10.7 5.1A10.7 10.7 0 0 1 21.9 11.7a1 1 0 0 1 0 .6 10.8 10.8 0 0 1-1.4 2.5M14.1 14.2a3 3 0 0 1-4.2-4.2M17.5 17.5A10.8 10.8 0 0 1 2.1 12.3a1 1 0 0 1 0-.6 10.8 10.8 0 0 1 4.4-5.2M2 2l20 20" />
    </template>
    <template v-else-if="name === 'qr-code'">
      <rect x="3" y="3" width="6" height="6" rx="1" /><rect x="15" y="3" width="6" height="6" rx="1" /><rect x="3" y="15" width="6" height="6" rx="1" /><path d="M21 15h-3a2 2 0 0 0-2 2v3M21 21v.01M12 7v3a2 2 0 0 1-2 2H7M3 12h.01M12 3h.01M12 16v.01M16 12h1M21 12v.01M12 21v-1" />
    </template>
    <template v-else-if="name === 'more'">
      <circle cx="12" cy="5" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="12" cy="19" r="1" />
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
    </template>
    <template v-else-if="name === 'x'">
      <path d="M18 6 6 18M6 6l12 12" />
    </template>
    <template v-else-if="name === 'filter-x'">
      <path d="M13 13.2V20l-2-1v-5.8L4.3 5.6A1 1 0 0 1 5 4h11" /><path d="m16 6 5 5M21 6l-5 5" />
    </template>
    <template v-else-if="name === 'type'">
      <path d="M4 7V5h16v2M9 19h6M12 5v14" />
    </template>
    <template v-else-if="name === 'align-left'">
      <path d="M4 6h16M4 12h10M4 18h14" />
    </template>
    <template v-else-if="name === 'phone'">
      <path d="M13.8 16.6a1 1 0 0 0 1.2-.3l.4-.5a2 2 0 0 1 1.6-.8h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.5.4a1 1 0 0 0-.3 1.2 14 14 0 0 0 6.4 6.4" />
    </template>
    <template v-else-if="name === 'hash'">
      <path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18" />
    </template>
    <template v-else-if="name === 'chevron-down'">
      <path d="m6 9 6 6 6-6" />
    </template>
    <template v-else-if="name === 'chevron-left'">
      <path d="m15 18-6-6 6-6" />
    </template>
    <template v-else-if="name === 'circle-dot'">
      <circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="2.5" />
    </template>
    <template v-else-if="name === 'square-check'">
      <rect x="3" y="3" width="18" height="18" rx="3" /><path d="m8.5 12 2.5 2.5 5-5" />
    </template>
    <template v-else-if="name === 'upload'">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m17 8-5-5-5 5M12 3v12" />
    </template>
    <template v-else-if="name === 'download'">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m7 10 5 5 5-5M12 15V3" />
    </template>
    <template v-else-if="name === 'link'">
      <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7" /><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7" />
    </template>
    <template v-else-if="name === 'image'">
      <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21" />
    </template>
    <template v-else-if="name === 'arrow-up'">
      <path d="m5 12 7-7 7 7M12 19V5" />
    </template>
    <template v-else-if="name === 'arrow-down'">
      <path d="M12 5v14M19 12l-7 7-7-7" />
    </template>
    <template v-else-if="name === 'check'">
      <path d="M20 6 9 17l-5-5" />
    </template>
    <template v-else-if="name === 'save'">
      <path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7M7 3v4a1 1 0 0 0 1 1h7" />
    </template>
    <template v-else-if="name === 'send'">
      <path d="M14.5 21.7a.5.5 0 0 0 .9 0l6.5-19a.5.5 0 0 0-.6-.6l-19 6.5a.5.5 0 0 0 0 .9l7.9 3.2a2 2 0 0 1 1.1 1.1Z" /><path d="m21.9 2.1-10.9 11" />
    </template>
    <template v-else-if="name === 'undo'">
      <path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" />
    </template>
    <template v-else-if="name === 'sort'">
      <path d="m3 16 4 4 4-4M7 20V4M21 8l-4-4-4 4M17 4v16" />
    </template>
    <template v-else-if="name === 'arrow-up-right'">
      <path d="M7 7h10v10M7 17 17 7" />
    </template>
    <template v-else-if="name === 'copy'">
      <rect x="8" y="8" width="14" height="14" rx="2" /><path d="M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2" />
    </template>
    <template v-else-if="name === 'activity'">
      <path d="M22 12h-2.5a2 2 0 0 0-1.9 1.5l-2.3 8.3a.3.3 0 0 1-.5 0L9.2 2.2a.3.3 0 0 0-.5 0l-2.3 8.3A2 2 0 0 1 4.5 12H2" />
    </template>
  </svg>
</template>
