<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import Chart from 'chart.js/auto'
import TileHeader from './TileHeader.vue'
import { useTheme } from '@/composables/useTheme'

/*
 | Every card looks the same, and there is deliberately no prop that can change
 | that.
 |
 | There used to be one — `snapshot`, which tinted the header and darkened the
 | border on the two charts that ignore the date picker. It was a third way of
 | saying what the title and the note already say, and because it was a prop,
 | the cards could be made to disagree from the outside: two of them ended
 | up reading as a different kind of panel and the grid looked broken. The
 | styling lives here now and takes no arguments, so the cards cannot drift
 | apart again.
 |
 | The header itself is TileHeader, shared with the KPI tiles and the two
 | follow-up panels. It used to be markup of this card's own, which is how the
 | chart row and the panel row came to start their content at different
 | heights — one header for every tile on the page is what stops that coming
 | back.
 |
 | `note` defaults to a string rather than undefined so that a card with no
 | note still renders the same elements and the same attributes as one with a
 | note. Identical markup, different text.
 */
const props = defineProps({
  title: { type: String, default: '' },
  note: { type: String, default: '' },
  config: { type: Object, required: true },
  height: { type: String, default: 'h-[280px]' },
  // for charts with no axis of their own to fall back on — a doughnut with
  // every slice at zero draws nothing, where a bar chart still shows a scale
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'No data in this range' },
  /*
   | Whether a click on the plot does anything — a cursor and a hover lift,
   | nothing more. What a click MEANS is the config's `onClick`, which belongs
   | to the page that built the chart; this card has never known what it is
   | drawing and is not about to start.
   */
  clickable: { type: Boolean, default: false },
})

const canvas = ref(null)
const box = ref(null)
let chart = null
let observer = null
let timer

const { isDark } = useTheme()

/*
 | Every chart's own axis ticks, legend labels and gridlines read Chart.js's
 | global defaults unless a config overrides them — most of ours do not — so
 | setting these two here, before render(), is what keeps a dark-mode axis
 | label from staying the light-mode grey Chart.js would otherwise bake in.
 | Mutated on the shared Chart object rather than passed through `config`,
 | because `config` is built once by the parent and does not know the theme.
 */
const applyChartTheme = () => {
  Chart.defaults.color = isDark.value ? '#94a3b8' : '#64748b'
  Chart.defaults.borderColor = isDark.value ? 'rgba(148, 163, 184, 0.18)' : '#e2e8f0'
}

/*
 | Two separate paths, and keeping them separate is the point.
 |
 |   the data changed  →  render(), which tears the chart down and builds a
 |                        new one from the new config
 |   the size changed  →  resize(), which only asks the chart to re-measure
 |
 | Rebuilding on a resize is what made the charts flicker and restart their
 | animation mid-drag. It was also pure waste: nothing about the data had
 | changed, only the number of pixels available to draw it in.
 */
const render = () => {
  if (!canvas.value) return

  applyChartTheme()

  // destroy before recreating, or old canvases leak and
  // ghost tooltips follow the mouse around the screen
  chart?.destroy()
  chart = new Chart(canvas.value, props.config)
}

const resize = () => {
  // the observer can fire once more while the component is being torn down,
  // after the chart is gone — there is nothing to re-measure then
  if (!chart || !canvas.value) return

  chart.resize()
}

onMounted(() => {
  render()

  /*
   | A ResizeObserver on the plot box rather than a window resize listener.
   | The box is what actually decides how big the chart can be, and it can
   | change size without the window doing anything — a sibling column growing,
   | a panel opening, the sidebar margin appearing at the lg breakpoint. A
   | window listener sees none of that; this sees all of it, window resizes
   | included.
   */
  observer = new ResizeObserver(() => {
    clearTimeout(timer)
    timer = setTimeout(resize, 120)
  })

  if (box.value) observer.observe(box.value)
})

watch(() => props.config, render, { deep: true })

// the config itself hasn't changed when the toggle is flipped, so it needs
// its own watcher to make an already-drawn chart pick up the new palette
watch(isDark, render)

onBeforeUnmount(() => {
  // the pending debounce goes too: left alone it fires after the component is
  // gone, holding this whole closure alive until it does
  clearTimeout(timer)
  observer?.disconnect()
  observer = null
  chart?.destroy()
  chart = null
})
</script>

<template>
  <!--
    min-w-0 is doing the real work here.

    These cards are grid items, and a grid item's automatic minimum size is its
    content — which for us is a canvas that Chart.js has given an explicit pixel
    width. That width then became a floor the column could not go below, so the
    card kept the widest size it had ever had: the page grew a horizontal
    scrollbar, the box stopped shrinking, and the chart — correctly filling a box
    that was no longer shrinking — appeared frozen until a reload. min-w-0 drops
    that floor so the column can shrink, and Chart.js follows it down.
  -->
  <div class="tile" :class="clickable ? 'tile-lift' : ''">
    <TileHeader :title="title" :note="note" />

    <div class="flex-1 px-4 py-3">
      <!--
        overflow-hidden covers the frame between the box shrinking and Chart.js
        redrawing the canvas at the new size: without it that one oversized
        frame is enough to flash a horizontal scrollbar across the page.
      -->
      <div ref="box" class="relative overflow-hidden" :class="height">
        <canvas ref="canvas" :class="clickable ? 'cursor-pointer' : ''"></canvas>
        <div v-if="empty"
             class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">
          {{ emptyText }}
        </div>
      </div>
    </div>
  </div>
</template>
