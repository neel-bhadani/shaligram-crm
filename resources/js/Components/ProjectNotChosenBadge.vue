<script setup>
/**
 * Says so when a lead is still on the WhatsApp default project: its real
 * project has not been chosen yet, and Switch project is how it is.
 */
defineProps({ show: Boolean })
</script>

<template>
  <span v-if="show"
        class="ml-1.5 inline-flex whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
    Project not chosen
  </span>
</template>
