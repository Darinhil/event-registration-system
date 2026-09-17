<script setup>defineProps({ status: { type: String, default: 'pending' } })</script>
<template><span class="status" :class="`status--${status}`">{{ status }}</span></template>