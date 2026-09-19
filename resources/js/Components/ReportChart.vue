<script setup>
/*
 | The report chart: the selected grouping as bars or as a doughnut.
 |
 | ChartCard does the drawing, the resizing and — the part that matters — the
 | destroy() before every rebuild, so switching shape here cannot leak a canvas
 | or leave a ghost tooltip following the mouse. This file only decides what
 | config to hand it, and a new config is what a shape change is: the `config`
 | computed below returns a different object, ChartCard's deep watcher fires,
 | and the old chart is torn down before the new one exists.
 |
 | The toggle is local state and deliberately not a filter. It changes nothing
 | about what is counted, so putting it on the wire would mean a server round
 | trip, a session write and a re-query to redraw the same numbers in a
 | different shape.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import ChartCard from '@/Components/ChartCard.vue'

const props = defineProps({
  rows: { type: Array, required: true },
  title: { type: String, required: true },
  note: { type: String, default: '' },
  // what the bar/slice length means, for the tooltip
  metric: { type: String, default: 'Count' },
  /*
   | Stage is the one dimension with colours of its own — config('crm.stage_colors'),
   | the same map StageBadge reads — so a stage is the colour it is everywhere
   | in the app. Every other dimension gets the palette below, which carries no
   | meaning and is not asked to.
   */
  colors: { type: Object, default: null },
})

const shape = ref('bar')

/*
 | Where the doughnut's legend goes, decided by the width of the card it is in
 | rather than the width of the window — see Dashboard.vue's sourceChart,
 | which this mirrors. 600px is the line: below it the plot is too narrow to
 | give a quarter of itself away to a column of labels, so they go underneath;
 | above it there is room for both side by side.
 */
const LEGEND_BESIDE_ABOVE = 600

const card = ref(null)
const roomy = ref(true)

let observer
let timer

onMounted(() => {
  if (!card.value) return

  roomy.value = card.value.offsetWidth >= LEGEND_BESIDE_ABOVE

  observer = new ResizeObserver(([entry]) => {
    clearTimeout(timer)
    timer = setTimeout(() => {
      roomy.value = entry.contentRect.width >= LEGEND_BESIDE_ABOVE
    }, 120)
  })

  observer.observe(card.value)
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  observer?.disconnect()
  observer = null
})

/*
 | A fixed palette walked in order, so a group is the same colour every time the
 | page is drawn and two groups next to each other are never the same colour.
 | Long enough for the widest grouping in the app — nine stages — and it wraps
 | rather than running out.
 */
const PALETTE = [
  '#0F766E', '#2F6FB0', '#8145A8', '#C2711A', '#1E7A45',
  '#B23A38', '#5B58B8', '#B4881B', '#8A94A0',
]

const labels = computed(() => props.rows.map(r => r.label))
const values = computed(() => props.rows.map(r => r.total))

const colorFor = (row, i) => props.colors?.[row.key] ?? PALETTE[i % PALETTE.length]

const total = computed(() => values.value.reduce((a, b) => a + b, 0))

/*
 | A doughnut with every slice at zero draws nothing at all, where a bar chart
 | still shows its scale — so the empty overlay is only ever needed on one of
 | the two shapes. ChartCard renders it over the plot box either way; this is
 | just about when it is warranted.
 */
const empty = computed(() => shape.value === 'doughnut' && total.value === 0)

const tick = { color: '#64748b', font: { size: 11 } }

const config = computed(() => shape.value === 'bar'
  ? {
      type: 'bar',
      data: {
        labels: labels.value,
        datasets: [{
          label: props.metric,
          data: values.value,
          backgroundColor: props.rows.map(colorFor),
          borderRadius: 4,
          maxBarThickness: 46,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { ...tick, autoSkip: false, maxRotation: 60 }, grid: { display: false } },
          // a count axis with fractional gridlines is a count axis lying about
          // what it can hold
          y: { beginAtZero: true, ticks: { ...tick, precision: 0 }, grid: { color: '#eef2f3' } },
        },
      },
    }
  : {
      type: 'doughnut',
      data: {
        labels: labels.value,
        datasets: [{
          data: values.value,
          backgroundColor: props.rows.map(colorFor),
          borderWidth: 0,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '58%',
        plugins: {
          legend: { position: roomy.value ? 'right' : 'bottom', labels: { ...tick, boxWidth: 10, padding: 10 } },
          tooltip: {
            callbacks: {
              /*
               | The share is recomputed from the slices rather than read off
               | the row, because a doughnut hides its zero slices and the
               | percentage a reader is checking is the one against the ring in
               | front of them. It is the same denominator either way — the
               | zero rows contribute nothing to it.
               */
              label: c => {
                const sum = c.dataset.data.reduce((a, b) => a + b, 0)
                const pct = sum > 0 ? Math.round((c.parsed / sum) * 1000) / 10 : 0
                return ` ${c.label}: ${c.parsed} (${pct}%)`
              },
            },
          },
        },
      },
    })
</script>

<template>
  <div ref="card" class="relative">
    <!--
      The toggle sits over ChartCard's header rather than inside it. ChartCard
      takes no slot and no styling argument on purpose — that is what keeps
      every card header in the app the same height — so the control is
      positioned against this wrapper instead of being handed in.
    -->
    <div class="absolute right-4 top-3.5 z-10 flex overflow-hidden rounded-lg border border-slate-200">
      <button
        v-for="s in ['bar', 'doughnut']" :key="s"
        class="px-2.5 py-1 text-xs font-medium capitalize transition"
        :class="shape === s ? 'bg-teal-700 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'"
        :aria-pressed="shape === s"
        @click="shape = s"
      >{{ s }}</button>
    </div>

    <ChartCard :title="title" :note="note" :config="config" :empty="empty"
               empty-text="Nothing in this range" height="h-[320px]" />
  </div>
</template>
