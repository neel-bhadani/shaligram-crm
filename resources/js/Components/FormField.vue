<script setup>
defineProps({
  label: String,
  required: Boolean,
  error: String,
  hint: String,
})
</script>

<template>
  <div>
    <label class="mb-1.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">
      {{ label }} <span v-if="required" class="text-rose-600 dark:text-rose-400">*</span>
    </label>

    <!-- inputs are styled globally in app.css so every control matches -->
    <slot />

    <p v-if="hint && !error" class="mt-1.5 text-xs text-slate-400">{{ hint }}</p>
    <p v-if="error" class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ error }}</p>
  </div>
</template>
