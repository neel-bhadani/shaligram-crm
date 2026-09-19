<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'

/*
 | A row of count chips that is also a filter.
 |
 | Shared by the Leads page (stages) and the To-do page (task types), because
 | "the two pages must look like the same system" is not something two copies of
 | the same markup can promise. The pages supply the chips and own what a click
 | means; everything about how a chip looks, wraps and scrolls is here.
 |
 | A chip's `color` is optional. Where the category already has a colour in
 | config — a lead stage — the dot carries it and the selected chip is filled
 | with it, so a chip and a StageBadge for one stage are the same colour. Where
 | it does not — a task type — the chip is neutral slate. Inventing four colours
 | for four task types would look like it meant something.
 */
const props = defineProps({
  /** @type {{ key: string, label: string, value: number, color?: ?string }[]} */
  chips: { type: Array, required: true },

  /** The selected key. '' is the "All" chip. */
  active: { type: String, default: '' },
})

defineEmits(['select'])

/*
 | Below md the strip scrolls sideways instead of wrapping, which can leave the
 | selected chip off-screen — on first paint after a refresh most of all, where
 | the filter survived in the session and the user never clicked anything.
 |
 | scrollLeft on the strip rather than scrollIntoView(), which walks up the
 | ancestors and can move the page as well. The strip is `relative`, so it is
 | the offsetParent and offsetLeft is measured against it.
 */
const strip = ref(null)

const showActive = async () => {
  await nextTick()

  const box = strip.value

  if (!box || box.scrollWidth <= box.clientWidth + 1) return

  const chip = box.querySelector('[data-active="true"]')

  if (!chip) return

  box.scrollTo({
    left: Math.max(0, chip.offsetLeft - (box.clientWidth - chip.offsetWidth) / 2),
    behavior: 'smooth',
  })
}

onMounted(showActive)
watch(() => props.active, showActive)
</script>

<template>
  <!--
    Wrap above md, scroll below it. Nothing here truncates — the count is the
    reason the chips exist, so a chip is either fully readable or scrolled to.
  -->
  <div ref="strip"
       class="relative flex gap-2 overflow-x-auto border-b border-slate-100 dark:border-slate-700/60 px-3 py-2.5
              sm:px-4 md:flex-wrap md:overflow-x-visible">
    <button
      v-for="chip in chips" :key="chip.key || 'all'"
      type="button"
      :data-active="active === chip.key"
      :aria-pressed="active === chip.key"
      class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border
             px-3 py-1.5 text-xs font-semibold transition"
      :class="active === chip.key
        ? (chip.color ? 'border-transparent text-white' : 'border-slate-900 bg-slate-900 text-white')
        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700'"
      :style="active === chip.key && chip.color
        ? { backgroundColor: chip.color, borderColor: chip.color }
        : null"
      @click="$emit('select', chip.key)"
    >
      <!-- the dot carries the colour while the chip is unselected; once it is
           selected the whole chip is that colour and a dot on top of it would
           be a second mark for the same thing -->
      <span v-if="active !== chip.key" class="h-1.5 w-1.5 rounded-full"
            :class="chip.color ? '' : 'bg-slate-400'"
            :style="chip.color ? { backgroundColor: chip.color } : null"></span>

      {{ chip.label }}

      <span class="tabular-nums"
            :class="active === chip.key ? 'text-white/75' : 'text-slate-400'">{{ chip.value }}</span>
    </button>
  </div>
</template>
