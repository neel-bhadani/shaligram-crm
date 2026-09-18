<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import StageBadge from './StageBadge.vue'
import LeadActivityTimeline from './LeadActivityTimeline.vue'
import { brokerLabel } from '@/lib/brokerLabel.js'
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
// role => candidates, not a flat list — see LeadController::reassignCandidates().
// The stage picker below decides which role's list is offered.
const reassignCandidates = ref({})
const reassignStage = ref('')
const reassignTo = ref('')
const reassigning = ref(false)

// distinct from reassign above: this moves the lead's PERSON to a different
// PROJECT, closing this lead as lost and opening a new one there for them —
// see LeadController::transfer()
const transferOpen = ref(false)
const transferProjectId = ref('')
const transferNote = ref('')
const transferring = ref(false)

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
  reassignTo.value = ''
  transferOpen.value = false
  transferProjectId.value = ''
  transferNote.value = ''
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

// every active project but this lead's own — not narrowed to the current
// user's projects, since the point of transferring is offering one they may
// not normally touch
const transferProjectOptions = computed(() =>
  props.options.projects.filter(p => p.id !== lead.value?.project_id))

// a lead already booked or lost has nowhere left to transfer from
const canTransfer = computed(() => lead.value && !props.options.terminalStages.includes(lead.value.stage))

function transfer() {
  if (!transferProjectId.value || !transferNote.value) { return }

  transferring.value = true
  router.put(route('leads.transfer', props.leadId), {
    project_id: transferProjectId.value,
    note: transferNote.value,
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { transferOpen.value = false; transferProjectId.value = ''; transferNote.value = ''; load() },
    onFinish: () => { transferring.value = false },
  })
}

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

      <LeadActivityTimeline :timeline="timeline" :stage-colors="options.stageColors" />

      <!--
        Transfer to project — its own labeled section, styled like Activity's
        heading above it so the modal reads as three distinct zones (history,
        transfer, reassign) rather than one continuous form. Distinct from
        Reassign in the footer below: Reassign moves this lead to a different
        person on the same project; this closes it as lost here and opens a
        new lead for the same person on a different project, kept with the
        same owner. See LeadController::transfer().

        The label and description stay on screen whether the form is open or
        not — a bare "Transfer to project…" button with no explanation until
        after it's clicked left people guessing what they were about to do.
      -->
      <div v-if="allowEdit && canTransfer" class="mt-6 border-t border-slate-100 pt-5">
        <h4 class="text-xs font-semibold text-slate-500">Transfer to project</h4>
        <p class="mt-0.5 text-xs text-slate-400">
          Lead isn't interested in {{ lead.project?.name ?? 'this project' }} but wants another —
          closes this lead as Lost and opens a new one on that project, still assigned to
          {{ lead.owner?.display_name ?? 'its current owner' }}.
        </p>

        <button v-if="!transferOpen" type="button" class="btn-ghost mt-2 text-xs"
                @click="transferOpen = true">Transfer to project…</button>

        <div v-else class="mt-2 space-y-2">
          <select v-model="transferProjectId" class="w-full text-xs" aria-label="Transfer to project">
            <option value="" disabled>Choose a project…</option>
            <option v-for="p in transferProjectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <textarea v-model="transferNote" rows="2" class="w-full text-xs"
                    placeholder="Why is this lead moving projects?" aria-label="Transfer note"></textarea>
          <div class="flex justify-end gap-1.5">
            <button type="button" class="btn-ghost !py-1.5 text-xs" @click="transferOpen = false">Cancel</button>
            <button type="button" class="btn !py-1.5 text-xs"
                    :disabled="!transferProjectId || !transferNote || transferring"
                    @click="transfer">{{ transferring ? 'Transferring…' : 'Transfer' }}</button>
          </div>
        </div>
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
