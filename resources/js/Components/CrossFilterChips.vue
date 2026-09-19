<script setup>
import { useTheme } from '@/composables/useTheme'
import { chipOutlineStyle } from '@/lib/dynamicChipColor'

const { isDark } = useTheme()

/*
 | What the page is currently filtered to, and the way back out of it.
 |
 | Clicking a segment is how a cross-filter goes on, and a click is easy to
 | forget you made: half an hour later the dashboard reads low and the reason
 | is a bar somebody tapped on the way past. So every active filter is written
 | out here in words — dimension, value, and an × — under the control that sets
 | the dates, and there is a Clear all beside them.
 |
 | It renders nothing at all when nothing is selected. A permanently visible
 | empty filter bar is a row of the page spent saying "no".
 |
 | The stage and reached chips carry their stage's own colour, out of
 | config('crm.stage_colors'), so a chip and a StageBadge and a funnel band for
 | one stage are the same colour. Source has no colour in config and gets none
 | here: inventing eight would look like it meant something.
 */
defineProps({
  /**
   * @type {{ key: string, label: string, text: string, color: ?string }[]}
   *   key   which filter to drop when the × is pressed
   *   label the dimension, e.g. "Stage"
   *   text  the value, e.g. "Connected"
   */
  chips: { type: Array, required: true },
})

defineEmits(['remove', 'clear'])
</script>

<template>
  <!-- wraps rather than scrolls: a chip half off the edge of a phone is a
       filter the reader cannot see is on, which is the whole failure this
       row exists to prevent -->
  <div v-if="chips.length" class="mb-3 flex flex-wrap items-center gap-1.5">
    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Filtered by</span>

    <span
      v-for="chip in chips" :key="chip.key"
      class="inline-flex items-center gap-1.5 rounded-full border py-1 pl-2.5 pr-1 text-xs font-semibold"
      :style="chip.color ? chipOutlineStyle(chip.color, isDark) : null"
      :class="chip.color ? '' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
    >
      <span class="font-normal opacity-70">{{ chip.label }}</span>{{ chip.text }}

      <button type="button"
              class="flex h-4 w-4 items-center justify-center rounded-full text-current transition hover:bg-current/15"
              :aria-label="`Remove the ${chip.label.toLowerCase()} filter`"
              @click="$emit('remove', chip.key)">
        <svg class="h-2.5 w-2.5" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M1 1l8 8M9 1l-8 8" stroke-linecap="round" />
        </svg>
      </button>
    </span>

    <button type="button"
            class="rounded-full px-2 py-1 text-xs font-semibold text-slate-500 dark:text-slate-400 underline-offset-2
                   transition hover:text-teal-700 hover:underline"
            @click="$emit('clear')">Clear all</button>
  </div>
</template>
