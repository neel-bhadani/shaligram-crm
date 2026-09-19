<script setup>
import { toasts, dismissToast, pauseToast, resumeToast } from '@/composables/useToast'

/*
 | The single toast stack. Teleported to the body and sitting at z-100 so it
 | stays above Modal.vue, which teleports itself to the body at z-60.
 */

const styles = {
  success: 'bg-teal-700 text-white',
  error: 'bg-rose-700 text-white',
  warning: 'bg-amber-400 text-amber-950 dark:text-amber-100',
}

const closeStyles = {
  success: 'text-white/70 hover:text-white',
  error: 'text-white/70 hover:text-white',
  warning: 'text-amber-900/60 hover:text-amber-950',
}
</script>

<template>
  <Teleport to="body">
    <div
      class="pointer-events-none fixed inset-x-3 top-3 z-[100] flex flex-col gap-2
             sm:left-auto sm:right-5 sm:top-5 sm:w-80"
      role="status"
      aria-live="polite"
    >
      <TransitionGroup
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 motion-safe:-translate-y-2 motion-safe:sm:translate-y-0 motion-safe:sm:translate-x-6"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="opacity-0 motion-safe:sm:translate-x-6"
        move-class="motion-safe:transition-transform motion-safe:duration-200"
      >
        <div
          v-for="t in toasts" :key="t.id"
          class="pointer-events-auto flex items-start gap-2.5 rounded-lg px-4 py-3 text-sm shadow-lg"
          :class="styles[t.type]"
          :role="t.type === 'error' ? 'alert' : undefined"
          :aria-live="t.type === 'error' ? 'assertive' : undefined"
          @mouseenter="pauseToast(t.id)"
          @mouseleave="resumeToast(t.id)"
          @focusin="pauseToast(t.id)"
          @focusout="resumeToast(t.id)"
        >
          <svg class="mt-0.5 h-4 w-4 flex-none" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <template v-if="t.type === 'success'">
              <circle cx="12" cy="12" r="9" /><path d="M8.5 12.5l2.5 2.5 4.5-5" />
            </template>
            <template v-else-if="t.type === 'error'">
              <circle cx="12" cy="12" r="9" /><path d="M15 9l-6 6M9 9l6 6" />
            </template>
            <template v-else>
              <path d="M10.3 4.3L2.6 17.5A2 2 0 004.3 20.5h15.4a2 2 0 001.7-3L13.7 4.3a2 2 0 00-3.4 0z" />
              <path d="M12 9.5v4" /><path d="M12 17h.01" />
            </template>
          </svg>

          <span class="min-w-0 flex-1 break-words leading-snug">{{ t.message }}</span>

          <button
            type="button"
            class="-mr-1 -mt-0.5 flex-none px-1 text-lg leading-none transition"
            :class="closeStyles[t.type]"
            aria-label="Dismiss notification"
            @click="dismissToast(t.id)"
          >&times;</button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
