<template>
  <section class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 sm:p-6">
    <div v-if="$slots.title" class="mb-5 flex items-center gap-3">
      <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-cyan-100 text-cyan-700">
        <slot name="icon">✦</slot>
      </div>
      <div>
        <h2 class="text-base font-semibold text-slate-800"><slot name="title" /></h2>
        <p v-if="$slots.description" class="mt-0.5 text-sm text-slate-500"><slot name="description" /></p>
      </div>
    </div>
    <slot />
  </section>
</template>
