<script setup>
import { computed, ref } from 'vue'
import ChartCard from '@/Components/ChartCard.vue'
import { channelPartnerConfig, partnerColor, partnerLabel, summarizeChannelPartners } from '@/lib/channelPartnerChart.js'

const props = defineProps({ rows: { type: Array, required: true }, period: { type: String, required: true } })
const topN = ref(15)
const shape = ref('bar')
const summary = computed(() => summarizeChannelPartners(props.rows, topN.value))
const config = computed(() => channelPartnerConfig(summary.value.rows, shape.value))
const height = computed(() => shape.value === 'bar' ? Math.max(320, summary.value.rows.length * 32 + 55) : 320)
</script>

<template>
  <section class="min-w-0" aria-label="Channel partner chart">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
      <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700" role="status">
        No channel partner: <strong>{{ summary.unassigned.toLocaleString() }}</strong> leads
        ({{ summary.share.toFixed(1) }}%)
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-xs font-medium text-slate-600">
          Show
          <select v-model.number="topN" aria-label="Top channel partners" class="!w-auto !py-1 text-xs">
            <option v-for="n in [10, 15, 25]" :key="n" :value="n">Top {{ n }}</option>
          </select>
        </label>
        <div class="flex overflow-hidden rounded-lg border border-slate-200" aria-label="Chart type">
          <button v-for="mode in ['bar', 'doughnut']" :key="mode" type="button"
                  class="px-2.5 py-1 text-xs font-medium capitalize transition"
                  :class="shape === mode ? 'bg-teal-700 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'"
                  :aria-pressed="shape === mode" @click="shape = mode">{{ mode }}</button>
        </div>
      </div>
    </div>
    <p v-if="!summary.rows.length" class="card p-8 text-center text-sm text-slate-500" role="status">
      No channel partner activity for this period.
    </p>
    <div v-else :style="{ '--partner-chart-height': `${height}px` }">
      <ChartCard title="Leads by channel partner" :config="config" height="partner-chart-height"
                 :note="`Leads created ${period}. Top ${topN} partners; remaining partners grouped as Others. No channel partner is shown separately. The table includes everyone.`" />
      <ul v-if="shape === 'doughnut'" class="mt-3 grid grid-cols-1 gap-2 text-xs text-slate-600 sm:grid-cols-2 lg:grid-cols-3" aria-label="Chart legend">
        <li v-for="(row, index) in summary.rows" :key="row.key" class="flex min-w-0 items-center gap-2" :title="row.label">
          <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: partnerColor(row, index) }" />
          <span>{{ partnerLabel(row.label) }}: {{ row.total.toLocaleString() }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
:deep(.partner-chart-height) {
  height: var(--partner-chart-height);
}
</style>
