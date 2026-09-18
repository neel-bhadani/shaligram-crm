<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import StageBadge from './StageBadge.vue'
import { brokerLabel } from '@/lib/brokerLabel.js'
import { relativeTime } from '@/lib/relativeTime.js'
import { pickable } from '@/composables/useTaxonomy.js'

const props = defineProps({
  show: Boolean,
  leadId: Number,
  options: Object,
  // opt-in: the Calendar page reuses this popup as the way into
  // CompleteTaskModal, so a follow-up can be updated without leaving it.
  // Off elsewhere, so Leads keeps its own single "Edit lead" action.
  allowFollowUp: Boolean,
  // Leads is the only page with somewhere for "Edit lead" to open into
  // (LeadFormModal, via @edit). Calendar has none, so it opts out rather
  // than showing a button that does nothing.
  allowEdit: { type: Boolean, default: true },
})
const emit = defineEmits(['close', 'edit', 'followup'])

const lead = ref(null)
const timeline = ref([])
const loading = ref(false)
const showAll = ref(false)
// role => candidates, not a flat list — see LeadController::reassignCandidates().
// The stage picker below decides which role's list is offered.
const reassignCandidates = ref({})
const reassignStage = ref('')
const reassignTo = ref('')
const reassigning = ref(false)

async function load() {
  if (!props.show || !props.leadId) {
    lead.value = null; timeline.value = []; reassignCandidates.value = {}
    return
  }

  loading.value = true
  try {
    const { data } = await axios.get(route('leads.show', props.leadId))
    lead.value = data.lead
    timeline.value = data.timeline ?? []
    reassignCandidates.value = data.reassignCandidates ?? {}
    reassignStage.value = lead.value?.stage ?? ''
  } catch (e) {
    // A non-admin who just reassigned this lead away from themselves no
    // longer passes LeadPolicy::view() on it — the same rule that dropped it
    // from their list drops it here too. There is nothing left to show, so
    // close rather than surface the 403 as a broken modal.
    if (e.response?.status === 403) {
      emit('close')
      return
    }
    throw e
  } finally {
    loading.value = false
  }
}

watch(() => props.show, v => {
  showAll.value = false
  reassignTo.value = ''
  load()
})

// picking a different stage changes who is offered — the choice made under
// the old stage rarely still makes sense under the new one
watch(reassignStage, () => { reassignTo.value = '' })

/*
 | pickable(), the same helper the lead form's own stage field uses: every
 | active stage, plus the lead's current one even if it has since been
 | retired. Nothing here restricts which stage may follow which — see
 | LeadReassignRequest, which validates the same "active, or the lead's own
 | value" rule as every other stage field in the application.
 */
const reassignStageOptions = computed(() =>
  pickable(props.options.stages, props.options.activeStages, lead.value?.stage))

// the desk the picked stage belongs to, falling back to the lead's own
// current owner's role for a terminal stage — see CrmTaxonomy::stageOwnerRoles()
const reassignRole = computed(() => props.options.stageOwnerRoles?.[reassignStage.value] ?? lead.value?.assigned_role)

const reassignRoleCandidates = computed(() => reassignCandidates.value[reassignRole.value] ?? [])

// shown whenever ANY stage's desk has somebody to offer, not only the one the
// lead happens to be sitting in right now — see LeadController::reassignCandidates()
const canReassign = computed(() => Object.values(reassignCandidates.value).some(list => list.length))

function reassign() {
  if (!reassignTo.value) { return }

  reassigning.value = true
  // Inertia's router, not a bare axios PUT: leads.reassign redirects back()
  // on success, and only Inertia's client follows a PUT redirect as a GET
  // (a 303) — a plain axios PUT would replay the redirect as PUT against a
  // route that doesn't accept it (405). Going through the router also gets
  // the success toast for free, since app.js flashes it on every Inertia
  // response.
  router.put(route('leads.reassign', props.leadId), {
    assigned_to: reassignTo.value,
    stage: reassignStage.value || null,
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { reassignTo.value = ''; load() },
    onFinish: () => { reassigning.value = false },
  })
}

const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

const color = s => props.options.stageColors?.[s] ?? '#8A94A0'

/*
 | Oldest first, and by default only the most recent RECENT of them. The
 | earlier ones sit above, behind the toggle, so the list still reads top to
 | bottom in order.
 */
const RECENT = 20

const visible = computed(() => showAll.value ? timeline.value : timeline.value.slice(-RECENT))
const hiddenCount = computed(() => timeline.value.length - visible.value.length)

/* One outline icon per kind of entry, 24×24, drawn with the stroke. */
const ICONS = {
  created: 'M12 4.5v15m7.5-7.5h-15',
  edit: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z',
  stage: 'M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3',
  follow_up_completed: 'M4.5 12.75l6 6 9-13.5',
  follow_up_scheduled: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  assignment: 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
  booking: 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
  lost: 'M6 18L18 6M6 6l12 12',
  automation: 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
  whatsapp: 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z',
  history: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
}

// the stage an entry lands on colours it, exactly as StageBadge colours that
// stage; an entry with no stage stays neutral
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
  <Modal :show="show" :title="lead?.full_name ?? 'Lead'" @close="emit('close')">

    <div v-if="loading" class="py-10 text-center text-sm text-slate-500">Loading…</div>

    <div v-else-if="lead">
      <dl class="grid gap-4 sm:grid-cols-2">
        <div><dt class="text-[11px] font-semibold text-slate-400">Mobile</dt>
             <dd class="text-sm">{{ lead.mobile_number }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Email</dt>
             <dd class="break-all text-sm">{{ lead.email || '—' }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Project</dt>
             <dd class="text-sm">{{ lead.project?.name }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Source</dt>
             <dd class="text-sm">
               {{ options.sources[lead.source] }}
               <!-- the partner row if the lead has one, the old free text if it
                    does not — see lib/brokerLabel.js -->
               <span v-if="brokerLabel(lead)" class="text-slate-400">· {{ brokerLabel(lead) }}</span>
             </dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Stage</dt>
             <dd class="mt-0.5"><StageBadge :stage="lead.stage" /></dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Owner</dt>
             <dd class="text-sm">{{ lead.owner?.display_name ?? '—' }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Days in current stage</dt>
             <dd class="text-sm">{{ lead.days_in_stage === null ? '—' : lead.days_in_stage + ' days' }}</dd></div>
        <div><dt class="text-[11px] font-semibold text-slate-400">Next follow-up</dt>
             <dd class="text-sm">{{ lead.pending_todo ? fmt(lead.pending_todo.scheduled_at) : 'None — lead is closed' }}</dd></div>
        <div v-if="lead.reason"><dt class="text-[11px] font-semibold text-slate-400">Reason for loss</dt>
             <dd class="text-sm">{{ options.reasons[lead.reason] }}</dd></div>
        <div v-if="lead.booked_unit"><dt class="text-[11px] font-semibold text-slate-400">Booked unit</dt>
             <dd class="text-sm">{{ lead.booked_unit }}</dd></div>
      </dl>

      <div class="mt-6 border-t border-slate-100 pt-5">
        <div class="mb-3 flex items-center justify-between gap-2">
          <h4 class="text-xs font-semibold text-slate-500">Activity</h4>
          <button v-if="timeline.length > RECENT" type="button"
                  class="text-xs font-semibold text-slate-500 hover:text-slate-800"
                  @click="showAll = !showAll">
            {{ showAll ? `Show latest ${RECENT}` : `Show all ${timeline.length}` }}
          </button>
        </div>

        <p v-if="!timeline.length" class="text-sm text-slate-400">Nothing recorded yet.</p>

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
    </div>

    <template #footer>
      <div v-if="allowEdit && canReassign" class="flex flex-1 flex-wrap items-center gap-1.5 sm:flex-none">
        <select v-model="reassignStage" class="!w-auto !py-1.5 text-xs" aria-label="Reassign stage">
          <option v-for="s in reassignStageOptions" :key="s.key" :value="s.key">{{ s.label }}</option>
        </select>
        <select v-model="reassignTo" class="!w-auto !py-1.5 text-xs" aria-label="Reassign to">
          <option value="" disabled>Reassign to…</option>
          <option v-for="c in reassignRoleCandidates" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button type="button" class="btn-ghost !py-1.5 text-xs" :disabled="!reassignTo || reassigning"
                @click="reassign">{{ reassigning ? 'Reassigning…' : 'Reassign' }}</button>
      </div>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Close</button>
      <button v-if="allowFollowUp && lead?.pending_todo" class="btn flex-1 sm:flex-none"
              @click="emit('followup', lead)">Update follow-up</button>
      <button v-if="allowEdit" class="btn flex-1 sm:flex-none" @click="emit('edit', leadId)">Edit lead</button>
    </template>
  </Modal>
</template>
