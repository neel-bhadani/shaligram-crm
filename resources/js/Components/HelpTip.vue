<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

/*
 | The small "?" beside anything with a consequence.
 |
 | It exists for the four things on the Automation page that are cheap to get
 | wrong and expensive to undo: loop protection, marketing versus utility
 | pricing, click-to-send versus the API, and why a new rule starts switched
 | off. Each of those needs two sentences at the moment somebody is looking at
 | the control — not on a help page they will never open.
 |
 | Click, not hover. A hover tooltip does not exist on a phone or a tablet, and
 | the office admin this page is written for is as likely to be on one as not.
 | Click also means the text stays put while they read it.
 */
defineProps({
  title: String,
  // aligns the panel to the right edge when the tip sits near the window edge
  align: { type: String, default: 'left' },
})

const open = ref(false)
const root = ref(null)

const onDocClick = e => {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
const onKey = e => { if (e.key === 'Escape') open.value = false }

onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKey)
})
onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKey)
})
</script>

<template>
  <span ref="root" class="relative inline-flex">
    <button
      type="button"
      class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 dark:border-slate-600
             text-[10px] font-bold leading-none text-slate-500 dark:text-slate-400 transition
             hover:border-teal-600 hover:text-teal-700"
      :class="open ? 'border-teal-600 bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-300' : ''"
      :aria-expanded="open"
      aria-label="What does this mean?"
      @click.stop="open = !open"
    >?</button>

    <!--
      z-[70]: above the modal layer (z-60), because most of these live inside
      the rule builder and a tip that opened behind the form it explains would
      be worse than no tip at all.
    -->
    <div
      v-if="open"
      class="absolute top-6 z-[70] w-72 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 text-left
             shadow-xl"
      :class="align === 'right' ? 'right-0' : 'left-0'"
    >
      <p v-if="title" class="mb-1 text-xs font-semibold text-slate-900 dark:text-slate-100">{{ title }}</p>
      <div class="text-xs leading-relaxed text-slate-600 dark:text-slate-300"><slot /></div>
    </div>
  </span>
</template>
