<script setup>
/*
 | The funnel. Six bands, top to bottom, each one a stage a lead passes through
 | on its way to a booking.
 |
 | Markup and CSS, not a chart library. A funnel is six labelled bars whose
 | widths are a ratio — there is no axis to draw, no scale to place, no legend
 | and no tooltip, and everything a reader needs is printed on the band itself.
 | Drawn on a canvas the labels would be text the browser cannot select, the
 | counts would be text a screen reader cannot reach, and each band would need
 | hit-testing to be clickable. Drawn as elements they are buttons.
 |
 | Every band is a button whatever its count, and the click target is the whole
 | row rather than the coloured part of it. A band at zero is still a band you
 | can filter the page to — and a zero-width bar with a click on it is a
 | control the user cannot hit.
 |
 | The colours are config('crm.stage_colors'), the same source StageBadge, the
 | filter chips and both stage charts read, so a stage is one colour everywhere
 | in the application.
 */
defineProps({
  /**
   * @type {{ key: string, label: string, value: number, color: string,
   *          width: number, drop: ?number }[]}
   */
  bands: { type: Array, required: true },
  /** the key of the band the page is currently filtered to, or '' */
  active: { type: String, default: '' },
})

defineEmits(['select'])

/*
 | The drop from the band above, in words.
 |
 | An em dash where there is nothing to divide by — the top band, which has no
 | band above it, and any band whose predecessor is empty. Never 0%: 0% means
 | "nobody was lost here", and that is a different statement from "there is
 | nothing to measure".
 |
 | A band can be larger than the one above it. The funnel counts what happened
 | inside the selected period, not a cohort walking down it, so a fortnight in
 | which more leads were shown details than were first connected is a real and
 | ordinary reading. It says so with an up arrow rather than printing a
 | negative drop-off, which reads as a typo.
 */
const dropText = band => {
  if (band.drop === null) return '—'
  if (band.drop < 0) return `▲ ${Math.abs(band.drop)}%`

  return `▼ ${band.drop}%`
}

const dropClass = band => {
  if (band.drop === null) return 'text-slate-300'

  return band.drop < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'
}

// a band drawn at its true share can be a sliver; this keeps a stripe of
// colour under every label so the row still reads as a band of the funnel
const barWidth = band => `${Math.max(band.width, band.value > 0 ? 2 : 0)}%`
</script>

<template>
  <div class="flex h-full flex-col justify-between gap-1">
    <button
      v-for="band in bands" :key="band.key"
      type="button"
      :aria-pressed="active === band.key"
      class="group relative w-full rounded-md px-2 py-1 text-left transition hover:bg-slate-50 dark:hover:bg-slate-700"
      :class="active === band.key ? 'bg-slate-50 dark:bg-slate-900/60 ring-1 ring-inset ring-slate-200 dark:ring-slate-600' : ''"
      @click="$emit('select', band.key)"
    >
      <!-- line 1: the stage, its count, and the drop from the band above -->
      <div class="flex items-baseline justify-between gap-2">
        <span class="flex min-w-0 items-center gap-1.5 text-[11px] font-semibold text-slate-600 dark:text-slate-300">
          <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: band.color }"></span>
          <span class="truncate">{{ band.label }}</span>
        </span>

        <span class="flex shrink-0 items-baseline gap-2 tabular-nums">
          <span class="text-[11px]" :class="dropClass(band)">{{ dropText(band) }}</span>
          <span class="text-xs font-bold text-slate-900 dark:text-slate-100">{{ band.value }}</span>
        </span>
      </div>

      <!-- line 2: the band itself, on a track so an empty stage still has a row -->
      <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
        <div class="h-full rounded-full transition-[width] duration-300"
             :style="{ width: barWidth(band), backgroundColor: band.color }"></div>
      </div>
    </button>
  </div>
</template>
