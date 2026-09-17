<script setup>defineProps({ disabled: Boolean }); defineEmits(['check-in'])</script>
<template><button :disabled="disabled" @click="$emit('check-in')">Check in attendee</button></template>