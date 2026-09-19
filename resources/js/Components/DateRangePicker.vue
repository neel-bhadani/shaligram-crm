<script setup>
/*
 | The date range control, drawn once and worn by every page that has one.
 |
 | It was the dashboard's, written inline there, and the report pages grew a
 | plain <select> of their own instead — different options in a different order
 | with a different label for the same window, which is a bug in a reporting
 | tool whether or not either one is individually wrong. There is one control
 | now, so the two cannot disagree about what "Last 7 days" is or about how many
 | choices there are.
 |
 | It owns the popover and the draft dates, because those are its own business,
 | and nothing else: it does not know which page it is on, what a range means or
 | how to fetch anything. Choosing emits, and the page decides what a choice
 | does — which is what lets the dashboard and the two reports use it while
 | sending different filters alongside.
 |
 | The three presets come from the server so the list and the validation rules
 | behind it are the same list. `maxSpanDays` is optional: the dashboard caps a
 | custom range at two years because it buckets one into charts, the reports do
 | not cap it, and a check against an absent limit is skipped rather than
 | compared with undefined.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  /*
   | { key, from, to, label, today, maxSpanDays? }
   |
   | `key` is the preset in force, or 'custom'. `today` is today in IST as the
   | server sees it, and it is what the date inputs cap themselves at — the
   | browser may be in any timezone, and its idea of today can be a day out.
   */
  range: { type: Object, required: true },
  /*
   | [{ key, label }], in the order they are drawn — a list rather than a map,
   | because '7' and '30' are integer-like keys and an object would iterate
   | them ahead of 'today' however it was written. See config('crm.date_ranges').
   */
  presets: { type: Array, required: true },
})

/*
 | `select` carries either { range: key } or { from, to } — never both, because
 | a preset and a custom pair are alternatives and the server reads a pair as
 | custom whatever else it is told.
 */
const emit = defineEmits(['select'])

const pickerOpen = ref(false)
const draft = ref({ from: props.range.from, to: props.range.to })
const pickerError = ref('')

const isCustom = computed(() => props.range.key === 'custom')

// once a custom range is applied the button carries it, e.g. "1 Jun – 15 Jun"
const customLabel = computed(() => isCustom.value ? props.range.label : 'Custom')

const setRange = key => {
  pickerOpen.value = false
  emit('select', { range: key })
}

const openPicker = () => {
  draft.value = { from: props.range.from, to: props.range.to }
  pickerError.value = ''
  pickerOpen.value = true
}

const closePicker = () => {
  pickerOpen.value = false
  pickerError.value = ''
}

/*
 | Both dates are ISO yyyy-mm-dd, so they compare correctly as plain strings
 | and none of this has to build a Date. That matters here: the browser may be
 | in any timezone, and `range.today` is today in IST as the server sees it.
 */
const spanDays = (from, to) =>
  Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) + 1

// the same rules the server applies, so an invalid range is never sent
const validate = ({ from, to }) => {
  if (!from || !to) return 'Choose both a From and a To date.'
  if (from > to) return 'From must not be after To.'
  if (to > props.range.today) return 'To must not be in the future.'
  if (props.range.maxSpanDays && spanDays(from, to) > props.range.maxSpanDays) {
    return 'Choose a range of two years or less.'
  }
  return ''
}

const applyCustom = () => {
  pickerError.value = validate(draft.value)

  if (pickerError.value) return

  pickerOpen.value = false
  emit('select', { from: draft.value.from, to: draft.value.to })
}

// clear a stale complaint as soon as the user starts fixing it
watch(draft, () => { pickerError.value = '' }, { deep: true })

// Escape closes it from anywhere, not only from inside the two date fields
const onKeydown = e => { if (e.key === 'Escape') closePicker() }
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="relative w-full sm:w-auto">
    <div class="flex w-full overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 sm:w-auto">
      <button
        v-for="r in presets" :key="r.key"
        class="flex-1 whitespace-nowrap border-r border-slate-200 dark:border-slate-700 px-2.5 py-2 text-xs sm:px-3 sm:text-sm"
        :class="range.key === r.key ? 'bg-slate-900 text-white' : 'text-slate-500 dark:text-slate-400'"
        @click="setRange(r.key)"
      >{{ r.label }}</button>

      <button
        class="flex-1 whitespace-nowrap px-2.5 py-2 text-xs sm:px-3 sm:text-sm"
        :class="isCustom ? 'bg-slate-900 text-white' : 'text-slate-500 dark:text-slate-400'"
        aria-haspopup="dialog"
        :aria-expanded="pickerOpen"
        @click="pickerOpen ? closePicker() : openPicker()"
      >{{ customLabel }}</button>
    </div>

    <!-- click-away target; also the scrim behind the sheet on a phone -->
    <div v-if="pickerOpen" class="fixed inset-0 z-30 bg-slate-900/40 sm:bg-transparent"
         @click="closePicker" />

    <!--
      A sheet on phones and a popover on wider screens. Either way it is
      taken out of flow, so opening it never pushes the header apart.
    -->
    <div
      v-if="pickerOpen"
      class="fixed inset-x-3 bottom-3 z-40 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 shadow-xl
             sm:absolute sm:inset-x-auto sm:bottom-auto sm:right-0 sm:top-full sm:mt-2 sm:w-72"
      role="dialog" aria-label="Custom date range"
    >
      <div class="grid grid-cols-2 gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">From</span>
          <input v-model="draft.from" type="date" :max="range.today" class="w-full text-sm" />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">To</span>
          <input v-model="draft.to" type="date" :min="draft.from" :max="range.today"
                 class="w-full text-sm" />
        </label>
      </div>

      <p v-if="pickerError" class="mt-2.5 text-xs font-medium text-rose-700 dark:text-rose-300" role="alert">
        {{ pickerError }}
      </p>

      <div class="mt-4 flex justify-end gap-2">
        <button type="button" class="btn-ghost" @click="closePicker">Cancel</button>
        <button type="button" class="btn" @click="applyCustom">Apply</button>
      </div>
    </div>
  </div>
</template>
