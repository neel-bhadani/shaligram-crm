<script setup>
import { computed } from 'vue'

/**
 * Says so when a lead cannot be rung — no phone number, and worse, no email
 * either. Renders nothing for a lead that has a number.
 */
const props = defineProps({ lead: Object })

const label = computed(() => {
  if (!props.lead || props.lead.mobile_number) return null

  return props.lead.email ? 'No phone' : 'No contact details'
})
</script>

<template>
  <span v-if="label"
        class="inline-flex whitespace-nowrap rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">
    {{ label }}
  </span>
</template>
