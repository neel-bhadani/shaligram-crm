<script setup>
/*
 | The follow-ups report: one page, two groupings and four status buckets.
 |
 | The status is the list being counted, not a filter over it, and the four are
 | the Follow-ups page's own four tabs — which is what lets a row hand its group
 | straight to ?tab=<status> and land on exactly the rows that were counted.
 |
 | Only Completed is measured over the date range. The other three are states a
 | follow-up is in right now — scheduled before today, today, after today — and
 | the page says so under the filter bar rather than leaving a reader to wonder
 | why the picker moved nothing. See ReportController::applyStatus() for why
 | narrowing them by the picker would empty Upcoming permanently.
 |
 | Every value read from a prop inside this block goes through `props.`. In
 | <script setup> a prop is auto-exposed to the TEMPLATE only; the bare name is
 | not a variable in the script, and referring to one here throws a
 | ReferenceError at first render that no build step catches. That is exactly
 | how this page white-screened once already.
 */
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ReportFilterBar from '@/Components/ReportFilterBar.vue'
import ReportKpis from '@/Components/ReportKpis.vue'
import ReportChart from '@/Components/ReportChart.vue'
import ReportTable from '@/Components/ReportTable.vue'
import { useReportFilters } from '@/composables/useReportFilters.js'
import { withoutEmpty } from '@/lib/withoutEmpty.js'

const props = defineProps({
  rows: Array,
  totals: Object,
  filters: Object,
  range: Object,
  options: Object,
})

/*
 | Two axes on this page and each carries the other: changing tab resends the
 | grouping and the window, changing the window resends the grouping and the
 | tab. See useReportFilters — every visit is a reset, so anything not resent is
 | switched off.
 */
const { selectRange, set, clear } = useReportFilters(
  route('reports.followups'),
  () => props.filters,
  ['group', 'status'],
)

/*
 | The status tabs. Keys and labels come from the server, which reads them from
 | config('crm.reports.follow_up_statuses') — the same list ReportController
 | validates against and the same words the sidebar used to spell out, so a tab
 | and the bucket it selects cannot drift apart.
 |
 | The status is a separate axis from the grouping, which is why it is a control
 | here rather than a sidebar link. Listed in the sidebar alongside the two
 | groupings it made every follow-ups report look like two selections at once.
 */
const tabs = computed(() => Object.entries(props.options.statuses).map(([key, label]) => ({ key, label })))

const setStatus = key => set({ status: key })

const dimension = computed(() => props.options.dimensions[props.filters.group] ?? 'Group')
const status = computed(() => props.options.statuses[props.filters.status] ?? '')
const isCompleted = computed(() => props.filters.status === 'completed')

/*
 | The drill-through: the same follow-ups, as a list.
 |
 | The dates go only with Completed, because only Completed was measured over
 | them. Sending them with a pending status would narrow the list on
 | scheduled_at to a window the report never applied, and the list would come up
 | shorter than the number that was clicked.
 |
 | The status is the tab, and the tab keys are the same words, so a row lands on
 | the list it was counted from rather than on one that merely resembles it.
 */
const href = row => row.drillable
  ? route('todos.index', withoutEmpty({
      reset: 1,
      tab: props.filters.status,
      [props.filters.group]: row.key,
      ...(isCompleted.value ? { from: props.range.from, to: props.range.to } : {}),
    }))
  : null

const kpis = computed(() => [
  {
    v: props.totals.total, l: `${status.value} follow-ups`,
    tone: props.filters.status === 'overdue' && props.totals.total ? 'bad' : null,
    d: isCompleted.value ? `Closed ${props.range.label}` : 'As things stand right now',
  },
  {
    v: `${props.totals.covered}/${props.totals.groups}`,
    l: `${dimension.value} values in use`,
    d: `Groups with at least one, out of every ${dimension.value.toLowerCase()}`,
  },
  {
    v: props.totals.largest?.label ?? null, l: 'Largest group',
    d: props.totals.largest ? `${props.totals.largest.value} follow-ups` : 'Nothing to rank',
  },
  ...(isCompleted.value
    ? [{
        v: props.totals.avgDays, unit: ' d', l: 'Avg days to close',
        tone: props.totals.avgDays > 1 ? 'bad' : null,
        d: 'Scheduled time to logged time. Below zero is early.',
      }]
    : []),
])

const columns = computed(() => [
  { key: 'label', label: dimension.value, type: 'text' },
  { key: 'total', label: 'Follow-ups', type: 'number', note: `${status.value} follow-ups in this group` },
  { key: 'share', label: '% of total', type: 'percent', note: "This group's share of the bucket" },
  ...(isCompleted.value
    ? [{
        key: 'avgDays', label: 'Avg days to close', type: 'days',
        note: 'Average gap between the scheduled time and the time the call was logged. Below zero is early.',
      }]
    : []),
])
</script>

<template>
  <Head title="Follow-ups report" />

  <AppLayout title="Follow-ups report"
             :subtitle="`Grouped by ${dimension.toLowerCase()} · ${status} · ${range.label}`">
    <!--
      The status axis, as a tab row above the filter bar.
      
      Same markup and same classes as the Follow-ups list page's tabs, so the
      report and the list it drills into read as the same product rather than
      two takes on one idea. No counts on these: the list page's badges are the
      size of each tab's whole backlog, and a number here would be read as the
      size of the report under it, which is a different question once a date
      range is involved.
    -->
    <div class="card mb-4 overflow-hidden">
      <div class="flex gap-1 overflow-x-auto border-b border-slate-100 dark:border-slate-700/60 px-2 sm:px-4">
        <button v-for="t in tabs" :key="t.key"
                class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium"
                :class="filters.status === t.key
                  ? 'border-teal-700 font-semibold text-slate-900 dark:text-slate-100'
                  : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
                :aria-current="filters.status === t.key ? 'page' : undefined"
                @click="setStatus(t.key)">
          {{ t.label }}
        </button>
      </div>
    </div>

    <ReportFilterBar :range="range" :presets="options.ranges"
                     @select="selectRange" @clear="clear" />

    <!--
      Said once, plainly, under the control it is about.
      
      Three of the four tabs are positions relative to today — scheduled before
      it, on it, after it — and the date range does not move them. The heading
      states the range on every tab, so on these three it is describing the
      picker rather than the figures, and a reader who is not told that cannot
      tell a deliberate exception from a filter that failed to apply.
    -->
    <p v-if="!isCompleted" class="mb-4 -mt-2 text-xs text-slate-500 dark:text-slate-400">
      <span class="font-semibold">{{ status }}</span> counts where a follow-up stands right now,
      measured against today rather than against the dates above — those apply to Completed.
    </p>

    <ReportKpis :kpis="kpis" />

    <div class="mb-5">
      <ReportChart :rows="rows" :title="`${status} by ${dimension.toLowerCase()}`"
                   metric="Follow-ups"
                   :note="isCompleted
                     ? `Closed ${range.label}, zero-filled so an empty ${dimension.toLowerCase()} still shows.`
                     : `As things stand right now, zero-filled so an empty ${dimension.toLowerCase()} still shows.`" />
    </div>

    <ReportTable :rows="rows" :columns="columns" :href="href" sort="total" />

    <p class="mt-3 text-xs leading-relaxed text-slate-400">
      Completed counts <span class="font-medium">completed_at</span> inside the date range; the
      other three count <span class="font-medium">scheduled_at</span> against today. Click any row
      to open those follow-ups on the Follow-ups page.
    </p>
  </AppLayout>
</template>
