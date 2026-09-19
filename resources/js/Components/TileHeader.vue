<script setup>
/*
 | The header every tile on the dashboard wears.
 |
 | It exists because they did not use to wear one. The chart cards drew a
 | title over a two-line note in an 84px box; the two follow-up panels drew a
 | title beside a count in a 51px box with no note at all — so the row of
 | charts and the row of panels started their content at different heights and
 | the grid read as two different pages stacked on top of each other. That was
 | a QA finding, and the only fix that stays fixed is one header used by all of
 | them rather than four that agree today.
 |
 | Its height is a constant, not a function of its text. The note is clamped to
 | two lines and given the room for two whether it needs them, one, or none at
 | all, so a tile with a long note and a tile with no note are exactly as tall
 | as each other. That is the property a dense grid depends on: a header that
 | grows with its words pushes its own body down and throws the whole row out
 | of step.
 |
 | Nothing here is bound to a prop that could change how it looks. Tiles
 | rendering a header each of their own is the thing this file is fixing, so
 | there is deliberately nothing left to render differently with.
 */
defineProps({
  title: { type: String, default: '' },
  /*
   | The sub-line. Says which population the tile counted and nothing else —
   | and on the tiles the date picker does not move, says that.
   */
  note: { type: String, default: '' },
  /*
   | An optional figure printed after the title, greyed and a size down: the
   | follow-up panels count their rows there. Null, not 0, means "this tile has
   | no such number" — a panel holding nothing still prints its 0.
   */
  count: { type: [Number, String], default: null },
})
</script>

<template>
  <div class="flex min-h-[4.75rem] shrink-0 items-start gap-2 border-b border-slate-100 dark:border-slate-700/60 px-4 py-3">
    <div class="min-w-0 flex-1">
      <h3 class="truncate text-[13px] font-semibold leading-5 text-slate-900 dark:text-slate-100" :title="title">
        {{ title }}
        <!-- the count reads second: label first, number after -->
        <span v-if="count !== null"
              class="ml-1 text-xs font-normal tabular-nums text-slate-400">{{ count }}</span>
      </h3>

      <!--
        A fixed 30px — two lines of 15 — rather than a min-height, so the box is
        the same whether the note runs to two lines, one, or is absent. `title`
        is what a clamped second line is read with.
      -->
      <p class="mt-0.5 line-clamp-2 h-[30px] text-[11px] leading-[15px] text-slate-400"
         :title="note">{{ note }}</p>
    </div>

    <!-- the delta chip on a KPI tile, and nothing else so far -->
    <slot name="actions" />
  </div>
</template>
