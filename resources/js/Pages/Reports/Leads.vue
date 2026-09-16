<script setup>
/*
 | The leads report: one page, shared groupings.
 |
 | Every "Leads · By …" link in the sidebar lands here with a different `group`
 | in the query string. The server sends rows of the same shape for each
 | dimension. Channel partners get a summarized chart; the table and metrics
 | always use every row, and drill-through uses the dimension's lead filter.
 |
 | Two populations are on screen at once and the notes say so, because they are
 | measured over the same window through different columns:
 |
 |   Leads          leads.created_at in the range — who arrived
 |   Site visits
 |   Bookings       todos.outcome_stage with todos.completed_at in the range,
 |   Lost           counted once per lead — what happened
 |
 | A booking is counted on the day it happened, so a lead that came in months
 | ago and booked this week is in the Bookings column and not in the Leads one.
 | That is the whole reason the two are not the same query, and it is why the
 | conversion column has a note under it saying what it divides by.
 */
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ReportFilterBar from '@/Components/ReportFilterBar.vue'
import ReportKpis from '@/Components/ReportKpis.vue'
import ReportChart from '@/Components/ReportChart.vue'
import ChannelPartnerChart from '@/Components/ChannelPartnerChart.vue'
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
 | `group` is the only key this page owns besides the dates, and it rides along
 | with every range change — see useReportFilters. Note the getter: the props
 | object is reactive, so reading `props.filters` inside the closure is what
 | keeps the grouping current after an Inertia visit reuses this component.
 */
const { selectRange, clear } = useReportFilters(route('reports.leads'), () => props.filters, ['group'])

const dimension = computed(() => props.options.dimensions[props.filters.group] ?? 'Group')

/*
 | Which filter on the Leads page names this grouping. The keys are the
 | dimension keys from config('crm.reports.lead_dimensions'); the values are the
 | filter keys LeadController::filters() owns.
 */
const DRILL_KEY = {
  stage: 'stage',
  source: 'source',
  project: 'project_id',
  channel_partner: 'channel_partner_id',
  assigned_to: 'assigned_to',
}

/*
 | The drill-through: the same population, as a list.
 |
 | `from`/`to` rather than the range word, so the list is filtered by the exact
 | window the report measured whichever preset produced it. reset=1 makes the
 | link the whole instruction, so nothing left in that page's session narrows
 | the list further than the number that was clicked.
 */
const href = row => row.drillable
  ? route('leads.index', withoutEmpty({
      reset: 1,
      from: props.range.from,
      to: props.range.to,
      [DRILL_KEY[props.filters.group]]: row.key,
    }))
  : null

const kpis = computed(() => [
  { v: props.totals.total, l: 'Leads', d: 'Created in the selected period' },
  { v: props.totals.visits, l: 'Site visits', d: 'Visited in the selected period' },
  { v: props.totals.booked, l: 'Bookings', tone: 'good', d: 'Booked in the selected period' },
  {
    v: props.totals.conversion, unit: '%', l: 'Conversion',
    tone: props.totals.conversion === null ? null : 'good',
    d: 'Bookings ÷ leads, both in the period',
  },
])

const columns = computed(() => [
  { key: 'label', label: dimension.value, type: 'text' },
  { key: 'total', label: 'Leads', type: 'number', note: 'Created in the selected period' },
  { key: 'share', label: '% of leads', type: 'percent', note: "This group's share of the leads created" },
  { key: 'visits', label: 'Site visits', type: 'number', note: 'Visited in the period, counted once per lead' },
  { key: 'booked', label: 'Bookings', type: 'number', note: 'Booked in the period, counted once per lead' },
  { key: 'lost', label: 'Lost', type: 'number', note: 'Closed without booking in the period' },
  {
    key: 'conversion', label: 'Conversion %', type: 'percent',
    note: 'Bookings in the period ÷ leads created in the period. A dash means no leads to divide by.',
  },
])
</script>

<template>
  <Head title="Leads report" />

  <AppLayout title="Leads report" :subtitle="`Grouped by ${dimension.toLowerCase()} · ${range.label}`">
    <ReportFilterBar :range="range" :presets="options.ranges"
                     @select="selectRange" @clear="clear" />

    <ReportKpis :kpis="kpis" />

    <div class="mb-5">
      <ChannelPartnerChart v-if="filters.group === 'channel_partner'" :rows="rows" :period="range.label" />
      <ReportChart v-else :rows="rows" :title="`Leads by ${dimension.toLowerCase()}`"
                   metric="Leads"
                   :colors="filters.group === 'stage' ? options.stageColors : null"
                   :note="`Leads created ${range.label}, zero-filled so an empty ${dimension.toLowerCase()} still shows.`" />
    </div>

    <!--
      The same breakdown as numbers. Sortable on every column, and a row is a
      link: clicking Broker opens the Leads page filtered to broker leads
      created in this same window.
    -->
    <ReportTable :rows="rows" :columns="columns" :href="href" sort="total" />

    <p class="mt-3 text-xs leading-relaxed text-slate-400">
      Site visits, bookings and lost count what happened between these dates, once per lead,
      from the follow-up history — so a lead that arrived earlier and booked this week is in
      Bookings without being in Leads. Conversion divides the two, and can read above 100% for
      a group whose bookings came off older leads. Click any row to open the leads behind it.
    </p>
  </AppLayout>
</template>
