<script setup>
import { onErrorCaptured, ref } from 'vue'

/*
 | Keeps one section's crash inside that section.
 |
 | Without it, an error thrown while a section sets up, renders or updates —
 | or in its watchers, hooks and click handlers — travels up and blanks the
 | whole lead modal, which on the most-used screen looks like the CRM is down.
 | Here it stops: the section is replaced by a sentence and a Try again
 | button, and everything around it keeps working.
 |
 | Never silent. The error still goes to the console, in full, with the
 | section's name on it — a contained fault nobody can see is worse than a
 | visible one.
 |
 | Try again builds the section from scratch (a new key) rather than carrying
 | on with one that threw part-way through setting itself up.
 |
 | Not caught: a promise callback Vue does not run, such as an axios .then().
 | Those do not break rendering; the sections turn a failed request into their
 | own message.
 */
const props = defineProps({
  // "WhatsApp", "Activity" — said in the message and the console
  name: { type: String, required: true },
})

const failed = ref(false)
const attempt = ref(0)

onErrorCaptured((error, instance, info) => {
  console.error(`[SectionBoundary] The ${props.name} section failed (${info}).`, error)
  failed.value = true

  // stop here: the rest of the modal goes on rendering
  return false
})

const retry = () => {
  attempt.value++
  failed.value = false
}
</script>

<template>
  <div>
    <div v-if="failed" class="warn-box mt-6 text-xs">
      The {{ name }} section could not be shown.
      <button type="button" class="btn-xs ml-1" @click="retry">Try again</button>
    </div>
    <!-- a new key unmounts the broken section and mounts a fresh one -->
    <div v-else :key="attempt"><slot /></div>
  </div>
</template>
