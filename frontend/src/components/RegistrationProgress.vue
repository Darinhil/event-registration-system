<script setup>
defineProps({
  currentStep: { type: Number, required: true },
  steps: { type: Array, required: true },
})
const emit = defineEmits(['go-to'])
</script>

<template>
  <div class="registration-progress-tabs">
    <nav class="flex w-full" aria-label="Registration progress">
      <button v-for="(step, index) in steps" :key="step[0]" type="button" class="registration-progress-tab" :class="{ 'is-active': currentStep === index + 1, 'is-complete': currentStep > index + 1 }" :aria-current="currentStep === index + 1 ? 'step' : undefined" :aria-label="`${step[0]} step`" @click="index + 1 < currentStep ? emit('go-to', index + 1) : null">
        <span class="registration-progress-icon">{{ currentStep > index + 1 ? '✓' : '○' }}</span>
        <span>{{ step[0] }}</span>
      </button>
    </nav>
  </div>
</template>
