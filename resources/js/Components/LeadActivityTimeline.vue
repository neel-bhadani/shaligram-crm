<script setup>
import { computed, ref, watch } from 'vue'
import StageBadge from './StageBadge.vue'
import { relativeTime } from '@/lib/relativeTime.js'

/*
 | The Activity section of the lead view, as its own component.
 |
 | Shared verbatim with the Follow-ups page's expandable row, so the two cannot
 | drift apart: same entries, same order, same look. It is read-only display,
 | fed the same `timeline` array `LeadTimeline` builds for the lead.
 */
const props = defineProps({
  timeline: { type: Array, default: () => [] },
  stageColors: { type: Object, default: () => ({}) },
  loading: { type: Boolean, default: false },
  /*
   | `inset` drops the outer margin/divider so the panel can sit inside its own
   | padded box (the Follow-ups page's expanded row). Everything inside — title,
   | entries, badges, typography, timestamps — is unchanged, so the two contexts
   | can never disagree about what an entry says.
   */
  inset: { type: Boolean, default: false },
})

// an old lead's entries must not spawn the "Show all" toggle when a new lead's
// shorter history replaces them
watch(() => props.timeline, () => { showAll.value = false })

const showAll = ref(false)

/*
 | Oldest first, and by default only the most recent RECENT of them. The
 | earlier ones sit above, behind the toggle, so the list still reads top to
 | bottom in order.
 */
const RECENT = 20

const visible = computed(() => showAll.value ? props.timeline : props.timeline.slice(-RECENT))
const hiddenCount = computed(() => props.timeline.length - visible.value.length)

/* One outline icon per kind of entry, 24×24, drawn with the stroke. */
const ICONS = {
  created: 'M12 4.5v15m7.5-7.5h-15',
  edit: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z',
  stage: 'M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3',
  follow_up_completed: 'M4.5 12.75l6 6 9-13.5',
  follow_up_scheduled: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  follow_up_rescheduled: 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
  follow_up_cancelled: 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397M4.772 5.79c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
  assignment: 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
  project_switch: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
  booking: 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
  lost: 'M6 18L18 6M6 6l12 12',
  automation: 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
  whatsapp: 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z',
  history: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
}

// the stage an entry lands on colours it, exactly as StageBadge colours that
// stage; an entry with no stage stays neutral
const color = s => props.stageColors[s] ?? '#8A94A0'

const tint = e => {
  const c = e.to_stage ? color(e.to_stage) : '#64748B'

  return { color: c, backgroundColor: c + '18' }
}

// Display only: a jump straight from Fresh/Not connected to a salesperson
// stage reads as if the call that got it there never happened. This shows
// "Connected" as an implied badge in between — the stored stage, the
// database row and the handover logic never see it.
const HANDOVER_LANDING_STAGES = ['details_shared', 'site_visit_scheduled', 'site_visit_done', 'in_discussion']

const impliedStage = (from, to) =>
  ['fresh', 'not_connected'].includes(from) && HANDOVER_LANDING_STAGES.includes(to) ? 'connected' : null
</script>

<template>
  <div :class="inset ? '' : 'mt-6 border-t border-slate-100 pt-5'">
    <div class="mb-3 flex items-center justify-between gap-2">
      <h4 class="text-xs font-semibold text-slate-500">Activity</h4>
      <button v-if="!loading && timeline.length > RECENT" type="button"
              class="text-xs font-semibold text-slate-500 hover:text-slate-800"
              @click="showAll = !showAll">
        {{ showAll ? `Show latest ${RECENT}` : `Show all ${timeline.length}` }}
      </button>
    </div>

    <p v-if="loading" class="text-sm text-slate-500">Loading…</p>

    <p v-else-if="!timeline.length" class="text-sm text-slate-400">Nothing recorded yet.</p>

    <p v-if="hiddenCount" class="mb-3 text-[11px] text-slate-400">
      {{ hiddenCount }} earlier {{ hiddenCount === 1 ? 'entry' : 'entries' }} hidden.
    </p>

    <ol>
      <li v-for="(e, i) in visible" :key="e.key" class="relative flex gap-3 pb-4">
        <span v-if="i < visible.length - 1"
              class="absolute left-3 top-7 bottom-1 w-px bg-slate-200"></span>

        <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full" :style="tint(e)">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="ICONS[e.kind] ?? ICONS.history" />
          </svg>
        </span>

        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-1.5 text-sm font-semibold">
            <span>{{ e.title }}</span>
            <template v-if="e.to_stage">
              <template v-if="e.from_stage">
                <StageBadge :stage="e.from_stage" />
                <span class="text-xs font-normal text-slate-400" aria-label="to">→</span>
                <template v-if="impliedStage(e.from_stage, e.to_stage)">
                  <StageBadge :stage="impliedStage(e.from_stage, e.to_stage)" />
                  <span class="text-xs font-normal text-slate-400" aria-label="to">→</span>
                </template>
              </template>
              <StageBadge :stage="e.to_stage" />
            </template>
          </div>

          <div v-if="e.change" class="mt-0.5 break-words text-xs text-slate-600">
            <span class="text-slate-400 line-through">{{ e.change.from ?? '—' }}</span>
            <span class="px-1 text-slate-400">→</span>
            <span>{{ e.change.to ?? '—' }}</span>
          </div>

          <p v-if="e.remark" class="mt-0.5 whitespace-pre-line break-words text-xs text-slate-500">{{ e.remark }}</p>

          <ul v-if="e.details.length" class="mt-1 space-y-0.5 text-xs text-slate-600">
            <li v-for="(d, j) in e.details" :key="j" class="break-words">
              <span class="font-semibold text-slate-500">{{ d.label }}:</span>
              {{ d.value ?? '—' }}
              <span v-if="d.note" class="text-slate-400">— {{ d.note }}</span>
            </li>
          </ul>

          <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
            <time :datetime="e.at" :title="e.at_exact">{{ relativeTime(e.at) }}</time>
            <span>·</span>
            <span v-if="e.system"
                  class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-700">
              <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linejoin="round" aria-hidden="true">
                <path :d="ICONS.automation" />
              </svg>
              Automation
            </span>
            <span v-else>{{ e.actor }}</span>
          </div>
        </div>
      </li>
    </ol>
  </div>
</template>