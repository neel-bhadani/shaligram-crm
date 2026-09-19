<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useTheme } from '@/composables/useTheme'
import { chipStyle } from '@/lib/dynamicChipColor'

const props = defineProps({ stage: String })

const page = usePage()
const colors = computed(() => page.props.options?.stageColors ?? {})
const labels = computed(() => page.props.options?.stages ?? {})

const color = computed(() => colors.value[props.stage] ?? '#8A94A0')
const label = computed(() => labels.value[props.stage] ?? props.stage)

const { isDark } = useTheme()
const style = computed(() => chipStyle(color.value, isDark.value))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
    :style="style"
  >
    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ label }}
  </span>
</template>
