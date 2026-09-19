<script setup>
import Sparkline from './Sparkline.vue'
import TileHeader from './TileHeader.vue'

/*
 | One tile in the top row. Six of them, and they are the same tile six times.
 |
 | Same header as every other tile on the page — TileHeader, unstyled from out
 | here — then the figure, then its trend. Six across on a wide screen means
 | each is about 160px, so everything below the header is one line: a number, a
 | line under it, nothing else competing.
 |
 | It does not lift on hover, and the four charts below it do. That is not an
 | oversight: the lift is what tells a reader a tile can be clicked, and a KPI
 | cannot be. A total is not a segment — there is nothing for "leads in the
 | selected period" to filter the page down to — so a tile that rose under the
 | cursor and then did nothing would be a worse tile than one that sits still.
 */
defineProps({
  label: { type: String, required: true },
  value: { type: [Number, String], required: true },
  note: { type: String, default: '' },
  /** 'good' | 'bad' | null — tints the figure, nothing else */
  tone: { type: String, default: null },
  /** the accent the sparkline is drawn in */
  color: { type: String, default: '#0F766E' },
  /** @type {number[]} zero-filled, one value per bucket */
  spark: { type: Array, default: () => [] },
  /** "+12%", or null where there is no prior period to compare against */
  delta: { type: String, default: null },
  /** whether that delta is an improvement — decides the chip's colour */
  deltaUp: { type: Boolean, default: false },
  /*
   | A neutral marker where the delta chip would sit. Two of the six tiles are
   | "right now" figures that the date picker does not move, and at six across
   | there is not room to say so in the note as well as saying what the tile
   | counted. A reader who is not told cannot tell a deliberate exception from
   | a filter that failed to apply, so it is said here instead — in the one
   | place on the tile the eye already goes for a qualifier.
   */
  flag: { type: String, default: null },
})
</script>

<template>
  <div class="tile">
    <TileHeader :title="label" :note="note" />

    <div class="flex flex-1 flex-col justify-between gap-2 px-4 pb-3 pt-2.5">
      <!--
        The figure, and beside it the one qualifier the tile carries.

        Both chips sit on this line rather than up in the header, and that is
        not a cosmetic choice: six tiles across a 1280 window is about 180px
        each, and a chip in the header takes a third of the title's room —
        enough to truncate "Enquiries today" to "Enquiries t…", which is a
        worse thing to lose than the space beside a two-digit number.
      -->
      <!--
        A fixed 26px — the figure's own line height — so a tile that carries a
        chip is exactly as tall as one that does not. Left to size itself the
        chip added three pixels, which is invisible while all six tiles share
        one grid row and a 3px step down the column the moment they do not.
      -->
      <div class="flex h-[26px] items-baseline justify-between gap-2">
        <span class="text-[26px] font-bold leading-none tracking-tight tabular-nums"
              :class="tone === 'bad' ? 'text-rose-700 dark:text-rose-300' : tone === 'good' ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-900 dark:text-slate-100'">
          {{ value }}
        </span>

        <!-- the change against the period before this one -->
        <span v-if="delta"
              class="shrink-0 whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums"
              :class="deltaUp ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-300'"
              :title="`${delta} against the period before this one`">
          {{ delta }}
        </span>

        <span v-else-if="flag"
              class="shrink-0 whitespace-nowrap rounded-full bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5
                     text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"
              title="This figure is not moved by the date range">
          {{ flag }}
        </span>
      </div>

      <!-- a fixed 30px, so six tiles carrying six different figures are the
           same height whatever the numbers underneath them do -->
      <div class="h-[30px]">
        <Sparkline :values="spark" :color="color" />
      </div>
    </div>
  </div>
</template>
