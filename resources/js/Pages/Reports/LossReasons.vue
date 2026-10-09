<script setup>
/*
 | Leads · By loss reason.
 |
 | Counts the LOSS, not the lead's current state: a lead is in this report for
 | the period it was marked lost in, from the follow-up history, once per lead,
 | under the reason recorded on that loss. A lead lost twice in the window is
 | counted once, under its latest loss. The one undated figure on the page —
 | In Lost now — is leads.stage, and says so.
 |
 | Each row expands into two breakdowns of its own leads: by project, and by who
 | marked them lost. They live under the row rather than on separate screens so
 | the reason and its split are read together.
 |
 | Clicking a reason opens the Leads page on exactly those leads — Stage Lost,
 | dates applied to Marked lost, the same window, that reason — which is the
 | same LossEvents query this count came from, so the list's total is this
 | row's number.
 */
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import ReportFilterBar from '@/Components/ReportFilterBar.vue'
import ReportKpis from '@/Components/ReportKpis.vue'
import ReportChart from '@/Components/ReportChart.vue'
import { useReportFilters } from '@/composables/useReportFilters.js'
import { withoutEmpty } from '@/lib/withoutEmpty.js'

const props = defineProps({
  rows: Array,
  totals: Object,
  // the day losses began to be logged in the CRM; null if none have been
  attributionBegan: String,
  filters: Object,
  range: Object,
  options: Object,
})

const { selectRange, clear } = useReportFilters(route('reports.loss-reasons'), () => props.filters, [])

const NO_REASON = 'none'

const pct = (n, of) => (of > 0 ? Math.round((n / of) * 1000) / 10 : null)

const href = (row, extra = {}) => route('leads.index', withoutEmpty({
  reset: 1,
  stage: 'lost',
  date_basis: 'lost',
  from: props.range.from,
  to: props.range.to,
  reason: row.key,
  ...extra,
}))

const topReason = computed(() => props.rows.find(r => r.key !== NO_REASON && r.total > 0) ?? null)

const kpis = computed(() => [
  { v: props.totals.lost, l: 'Leads lost', d: 'Marked lost in the selected period, once per lead' },
  {
    v: props.totals.noReason, l: 'No reason recorded',
    tone: props.totals.noReason > 0 ? 'bad' : null,
    d: props.totals.lost > 0
      ? `${pct(props.totals.noReason, props.totals.lost)}% of the leads lost in the period`
      : 'Nothing lost in the period',
  },
  {
    v: topReason.value?.label ?? null, l: 'Most common reason',
    d: topReason.value ? `${topReason.value.total} leads · ${topReason.value.share}%` : 'No reason recorded in the period',
  },
  { v: props.totals.inLostNow, l: 'In Lost now', d: 'Sitting in Lost today — not date-filtered' },
])

const chartRows = computed(() => props.rows.filter(r => r.total > 0))

/* ---------------- sorting and expanding ---------------- */

const sortKey = ref('total')
const sortDir = ref('desc')

const toggleSort = key => {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    return
  }

  sortKey.value = key
  sortDir.value = key === 'label' ? 'asc' : 'desc'
}

const sorted = computed(() => {
  const sign = sortDir.value === 'asc' ? 1 : -1

  return [...props.rows].sort((a, b) => {
    const x = a[sortKey.value] ?? -1
    const y = b[sortKey.value] ?? -1

    return typeof x === 'string' ? sign * x.localeCompare(y) : sign * (x - y)
  })
})

const open = ref({})
const toggleRow = key => { open.value = { ...open.value, [key]: !open.value[key] } }
</script>

<template>
  <AppLayout title="Loss reasons" :subtitle="`Leads marked lost · ${range.label}`">
    <ReportFilterBar :range="range" :presets="options.ranges"
                     @select="selectRange" @clear="clear" />

    <ReportKpis :kpis="kpis" />

    <!-- an empty period is a statement, not an empty table -->
    <div v-if="totals.lost === 0" class="card px-5 py-14 text-center text-sm text-slate-500">
      <p class="mb-1 font-semibold text-slate-700">No leads were marked lost between {{ range.label }}</p>
      Choose a wider date range to see why leads were lost.
    </div>

    <template v-else>
      <div class="mb-5">
        <ReportChart :rows="chartRows" title="Leads lost by reason" metric="Leads lost"
                     :note="`Leads marked lost ${range.label}, once per lead, under the reason recorded on that loss.`" />
      </div>

      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-slate-50 text-left text-xs text-slate-500">
              <th class="w-8 py-2.5 pl-4"><span class="sr-only">Expand</span></th>
              <th v-for="c in [
                    { key: 'label', label: 'Reason', numeric: false, note: 'Why the lead was lost, as recorded on the loss' },
                    { key: 'total', label: 'Leads lost', numeric: true, note: 'Marked lost in the period, counted once per lead' },
                    { key: 'share', label: '% of lost', numeric: true, note: 'Share of every lead lost in the period' },
                  ]" :key="c.key"
                  class="px-4 py-2.5 font-semibold" :class="c.numeric ? 'text-right' : 'text-left'"
                  :aria-sort="sortKey === c.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'">
                <button class="inline-flex items-center gap-1 hover:text-slate-800" :title="c.note"
                        @click="toggleSort(c.key)">
                  {{ c.label }}
                  <span class="text-[10px]" :class="sortKey === c.key ? 'text-teal-700' : 'text-slate-300'">
                    {{ sortKey === c.key && sortDir === 'asc' ? '▲' : '▼' }}
                  </span>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in sorted" :key="row.key">
              <tr class="border-t border-slate-100" :class="row.key === NO_REASON ? 'bg-amber-50/60' : ''">
                <td class="py-3 pl-4">
                  <button v-if="row.total > 0" class="text-slate-400 hover:text-slate-700"
                          :aria-expanded="!!open[row.key]" :aria-label="`Break down ${row.label}`"
                          @click="toggleRow(row.key)">
                    <svg class="h-3.5 w-3.5 transition-transform" :class="open[row.key] ? 'rotate-90' : ''"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <path d="M9 6l6 6-6 6" />
                    </svg>
                  </button>
                </td>
                <td class="px-4 py-3 font-medium">
                  <Link v-if="row.total > 0" :href="href(row)"
                        class="text-teal-800 underline-offset-2 hover:underline">{{ row.label }}</Link>
                  <span v-else class="text-slate-500">{{ row.label }}</span>
                </td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ row.total }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.share === null ? '—' : `${row.share}%` }}</td>
              </tr>

              <tr v-if="open[row.key]" class="border-t border-slate-100 bg-slate-50/60">
                <td></td>
                <td colspan="3" class="px-4 pb-4 pt-2">
                  <div class="grid gap-4 md:grid-cols-2">
                    <div>
                      <div class="mb-1 text-xs font-semibold text-slate-500">By project</div>
                      <table class="w-full text-xs">
                        <tr v-for="p in row.projects" :key="p.key" class="border-t border-slate-100">
                          <td class="py-1.5">
                            <Link v-if="p.key !== '__none__'" :href="href(row, { project_id: p.key })"
                                  class="text-teal-800 underline-offset-2 hover:underline">{{ p.label }}</Link>
                            <span v-else class="text-slate-500">{{ p.label }}</span>
                          </td>
                          <td class="py-1.5 text-right font-semibold tabular-nums">{{ p.total }}</td>
                          <td class="w-16 py-1.5 text-right tabular-nums text-slate-500">{{ p.share }}%</td>
                        </tr>
                      </table>
                    </div>
                    <div>
                      <div class="mb-1 text-xs font-semibold text-slate-500">By who marked it lost</div>
                      <table class="w-full text-xs">
                        <tr v-for="p in row.people" :key="p.key" class="border-t border-slate-100">
                          <td class="py-1.5" :class="['imported', 'automation'].includes(p.key) ? 'italic text-slate-500' : ''">
                            {{ p.label }}
                          </td>
                          <td class="py-1.5 text-right font-semibold tabular-nums">{{ p.total }}</td>
                          <td class="w-16 py-1.5 text-right tabular-nums text-slate-500">{{ p.share }}%</td>
                        </tr>
                      </table>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600">
              <td></td>
              <td class="px-4 py-2.5">Total</td>
              <td class="px-4 py-2.5 text-right tabular-nums">{{ totals.lost }}</td>
              <td class="px-4 py-2.5 text-right tabular-nums">100%</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </template>

    <p class="mt-3 text-xs leading-relaxed text-slate-400">
      Each lead lost between these dates is counted once, under the reason recorded on its latest loss in the
      period — including leads reopened since. A lead lost more than once before reasons were kept per loss
      has its reason on its latest loss only; its earlier losses read No reason recorded.
      Click a reason to open the leads behind it.
    </p>
    <p class="mt-1.5 text-xs leading-relaxed text-slate-400">
      <template v-if="attributionBegan">
        Who marked a lead lost is only reliable for losses logged in the CRM, from {{ attributionBegan }}.
      </template>
      <template v-else>No loss has been logged in the CRM yet, so no loss can be attributed to a person.</template>
      Losses brought in by the legacy import show as Imported: the import recorded who owned the lead, not who
      lost it.
    </p>
  </AppLayout>
</template>
