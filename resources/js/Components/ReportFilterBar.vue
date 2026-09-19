<script setup>
/*
 | The filter bar both report pages wear: a date range and a Clear button.
 |
 | The range control is DateRangePicker, the same component the dashboard draws,
 | so the presets, their order, their labels and the custom popover are one
 | thing in one file. It used to be a <select> of its own here with a different
 | list in a different order, which is a bug in a reporting tool whether or not
 | either list is individually wrong — a number is only as trustworthy as the
 | window a reader believes it covers.
 |
 | Nothing else is on it. The menu link chose what the report groups by and,
 | on the follow-ups report, which bucket it counts; the heading states both.
 | Project, source and assigned-to sat here once and each of them is already a
 | grouping in the menu — filtering by source while grouped by source is a
 | one-row report — so stacking them on top turned every report into an ad-hoc
 | query builder rather than the one thing it is named after.
 */
import DateRangePicker from '@/Components/DateRangePicker.vue'

defineProps({
  // { key, from, to, label, today } — the window the server resolved
  range: { type: Object, required: true },
  // [{ key, label }], from config('crm.date_ranges')
  presets: { type: Array, required: true },
})

const emit = defineEmits(['select', 'clear'])
</script>

<template>
  <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3">
    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Dates</span>

    <DateRangePicker :range="range" :presets="presets" @select="emit('select', $event)" />

    <button class="btn-ghost w-full md:w-auto" @click="emit('clear')">Clear</button>
  </div>
</template>
