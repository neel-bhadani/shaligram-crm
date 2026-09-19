<script setup>
/*
 | The breakdown as numbers, sortable on every column, and the drill-through.
 |
 | The drill-through is what separates this from a picture of a chart: a row is
 | a count over a population, and clicking it opens the list that population
 | actually is. The parent builds the link, because only the parent knows which
 | page a row belongs on and which filter names that group there — this file
 | only knows that a row either has somewhere to go or does not.
 |
 | A row with `drillable: false` is the Unassigned bucket. The Leads page's
 | assigned-to filter takes a user id and there is no id to give it, so the row
 | is rendered plainly rather than as a link that would quietly land on an
 | unfiltered list.
 */
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  rows: { type: Array, required: true },
  /*
   | [{ key, label, type, note? }] — type is 'text' | 'number' | 'percent' |
   | 'days' and decides both the alignment and how a null prints.
   */
  columns: { type: Array, required: true },
  // (row) => href, or null for a row with nowhere to go
  href: { type: Function, default: () => null },
  sort: { type: String, default: 'total' },
})

const key = ref(props.sort)
const dir = ref('desc')

const toggle = column => {
  if (key.value === column.key) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc'
    return
  }

  key.value = column.key
  // a name reads naturally from A, a measurement from its largest value
  dir.value = column.type === 'text' ? 'asc' : 'desc'
}

/*
 | Nulls sort last in both directions, always.
 |
 | A null here is "nothing to divide by" — a group with no leads has no
 | conversion — and it is not a small number. Sorted as one it would take the
 | whole top of an ascending sort by conversion, which is the one column
 | somebody sorts to find their worst channel, and bury the real answer
 | underneath a run of em dashes.
 */
const sorted = computed(() => {
  const rows = [...props.rows]
  const sign = dir.value === 'asc' ? 1 : -1

  return rows.sort((a, b) => {
    const x = a[key.value]
    const y = b[key.value]

    if (x === null || x === undefined) return y === null || y === undefined ? 0 : 1
    if (y === null || y === undefined) return -1

    return typeof x === 'string' ? sign * x.localeCompare(y) : sign * (x - y)
  })
})

const fmt = (row, column) => {
  const v = row[column.key]

  if (v === null || v === undefined) return '—'

  return column.type === 'percent' ? `${v}%`
    : column.type === 'days' ? `${v} d`
    : v
}

const numeric = column => column.type !== 'text'

const totalOf = column => {
  if (column.type !== 'number') return null

  return props.rows.reduce((sum, r) => sum + (r[column.key] ?? 0), 0)
}
</script>

<template>
  <div class="card overflow-hidden">
    <!-- desktop -->
    <table class="hidden w-full text-sm lg:table">
      <thead>
        <tr class="bg-slate-50 dark:bg-slate-900/60 text-left text-xs text-slate-500 dark:text-slate-400">
          <th v-for="c in columns" :key="c.key"
              class="px-4 py-2.5 font-semibold"
              :class="numeric(c) ? 'text-right' : 'text-left'"
              :aria-sort="key === c.key ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'">
            <button class="inline-flex items-center gap-1 hover:text-slate-800 dark:hover:text-slate-100"
                    :title="c.note || `Sort by ${c.label}`"
                    @click="toggle(c)">
              {{ c.label }}
              <span class="text-[10px]" :class="key === c.key ? 'text-teal-700 dark:text-teal-300' : 'text-slate-300'">
                {{ key === c.key && dir === 'asc' ? '▲' : '▼' }}
              </span>
            </button>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in sorted" :key="row.key"
            class="border-t border-slate-100 dark:border-slate-700/60"
            :class="href(row) ? 'cursor-pointer hover:bg-teal-50/60' : ''"
            @click="href(row) && $inertia.visit(href(row))">
          <td v-for="c in columns" :key="c.key"
              class="px-4 py-3"
              :class="[numeric(c) ? 'text-right tabular-nums' : 'font-medium',
                       c.key === 'total' ? 'font-semibold' : '']">
            <!--
              The link lives in the name cell rather than around the row: a <tr>
              cannot be an anchor, and a row that only responds to a click is a
              row nobody can reach with a keyboard or open in a new tab.
            -->
            <Link v-if="c.key === 'label' && href(row)" :href="href(row)"
                  class="text-teal-800 dark:text-teal-300 underline-offset-2 hover:underline"
                  @click.stop>{{ row.label }}</Link>
            <span v-else :class="c.key === 'label' && !href(row) ? 'text-slate-500 dark:text-slate-400' : ''">
              {{ fmt(row, c) }}
            </span>
          </td>
        </tr>
      </tbody>
      <!--
        Summed from the rows on screen, never counted again. The rows are
        zero-filled across every group and each lead falls in exactly one of
        them, so this is the same population the summary cards above count — a
        footer that disagreed with them would be the one failure a report
        cannot survive.
      -->
      <tfoot>
        <tr class="border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-xs font-semibold text-slate-600 dark:text-slate-300">
          <td v-for="c in columns" :key="c.key" class="px-4 py-2.5"
              :class="numeric(c) ? 'text-right tabular-nums' : ''">
            {{ c.key === 'label' ? 'Total' : (totalOf(c) ?? '') }}
          </td>
        </tr>
      </tfoot>
    </table>

    <!-- mobile: the same rows as cards, the same order the sort put them in -->
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60 lg:hidden">
      <div class="flex flex-wrap gap-x-3 gap-y-1 bg-slate-50 dark:bg-slate-900/60 px-4 py-2.5 text-xs text-slate-500 dark:text-slate-400">
        <span class="font-semibold">Sort by</span>
        <button v-for="c in columns" :key="c.key"
                class="font-medium" :class="key === c.key ? 'text-teal-700 dark:text-teal-300' : 'hover:text-slate-700 dark:hover:text-slate-200'"
                @click="toggle(c)">
          {{ c.label }}{{ key === c.key ? (dir === 'asc' ? ' ▲' : ' ▼') : '' }}
        </button>
      </div>

      <component :is="href(row) ? Link : 'div'" v-for="row in sorted" :key="row.key"
                 :href="href(row) || undefined"
                 class="block px-4 py-3">
        <div class="flex items-baseline justify-between gap-3">
          <span class="font-semibold" :class="href(row) ? 'text-teal-800 dark:text-teal-300' : 'text-slate-600 dark:text-slate-300'">
            {{ row.label }}
          </span>
          <span class="text-lg font-bold tabular-nums">{{ row.total }}</span>
        </div>
        <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
          <div v-for="c in columns.filter(c => c.key !== 'label' && c.key !== 'total')"
               :key="c.key" class="flex gap-1">
            <dt>{{ c.label }}</dt>
            <dd class="font-medium tabular-nums text-slate-700 dark:text-slate-300">{{ fmt(row, c) }}</dd>
          </div>
        </dl>
      </component>
    </div>
  </div>
</template>
