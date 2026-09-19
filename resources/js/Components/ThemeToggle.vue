<script setup>
import { useTheme } from '@/composables/useTheme'

/*
 | The sidebar's own control, sitting under the user block rather than beside
 | it — a switch this small reads as part of the profile row if it touches it,
 | so it gets a divider and its own line instead.
 |
 | Icons rather than a plain switch: a bare pill answers "on or off" and makes
 | the reader work out which is which, where a sun and a moon answer "which
 | theme" on sight — the same reason the rest of the sidebar leans on flat
 | stroked icons over text.
 */
const { isDark, toggleTheme } = useTheme()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="isDark"
    aria-label="Toggle dark mode"
    class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-2 text-sm font-medium
           text-slate-400 transition-colors hover:bg-white/5 hover:text-white
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400"
    @click="toggleTheme"
  >
    <span class="flex items-center gap-2">
      <!-- sun -->
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
      </svg>
      {{ isDark ? 'Dark mode' : 'Light mode' }}
    </span>

    <!-- the track and thumb; the thumb carries the moon so its position alone says which state is on -->
    <span
      class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
      :class="isDark ? 'bg-teal-700' : 'bg-white/15'"
    >
      <span
        class="absolute left-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300
               transition-transform"
        :class="isDark ? 'translate-x-4' : 'translate-x-0'"
      >
        <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path v-if="isDark" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
          <circle v-else cx="12" cy="12" r="5" />
        </svg>
      </span>
    </span>
  </button>
</template>
