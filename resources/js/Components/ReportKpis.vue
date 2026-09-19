<script setup>
/*
 | The summary strip, shared by both report pages so the two cannot drift into
 | different-looking cards.
 |
 | Deliberately the same markup as the dashboard's KPI strip — same border, same
 | grid, same two sub-lines — because a reader moving between the dashboard and
 | a report should not have to work out whether they are looking at the same
 | kind of number. What differs is only how many tiles there are, which the grid
 | takes from the array rather than from a prop.
 |
 | A tile whose value is null prints an em dash. That is the whole reason the
 | value is not formatted by the caller: "no leads to divide by" and "0%" are
 | different answers, and a component that took a pre-formatted string could not
 | tell them apart.
 */
defineProps({
  // [{ v, l, d, tone? }] — value, label, description, optional 'good' | 'bad'
  kpis: { type: Array, required: true },
  suffix: { type: String, default: '' },
})
</script>

<template>
  <div class="mb-5 grid grid-cols-1 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800
              sm:grid-cols-2 lg:grid-cols-4">
    <div v-for="(k, i) in kpis" :key="i"
         class="border-b border-slate-100 dark:border-slate-700/60 p-4 last:border-b-0 sm:border-r lg:border-b-0 lg:last:border-r-0">
      <div class="truncate text-2xl font-bold tracking-tight"
           :class="k.tone === 'bad' ? 'text-rose-700 dark:text-rose-300' : k.tone === 'good' ? 'text-emerald-700 dark:text-emerald-300' : ''"
           :title="k.v === null || k.v === undefined ? 'No data' : String(k.v)">
        {{ k.v === null || k.v === undefined ? '—' : k.v }}<template
          v-if="k.unit && k.v !== null && k.v !== undefined">{{ k.unit }}</template>
      </div>
      <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ k.l }}</div>
      <div class="mt-1.5 text-[11px] text-slate-400">{{ k.d }}</div>
    </div>
  </div>
</template>
