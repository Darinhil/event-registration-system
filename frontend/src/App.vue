<script setup>
import { RouterView } from 'vue-router'
import { setupPageVisit } from './router'
</script>

<!-- The event setup page is rebuilt on each visit; see setupPageVisit in router/index.js. -->
<template>
  <RouterView v-slot="{ Component, route }">
    <component :is="Component" :key="route.meta.setupPage ? `event-setup-${setupPageVisit}` : undefined" />
  </RouterView>
</template>
