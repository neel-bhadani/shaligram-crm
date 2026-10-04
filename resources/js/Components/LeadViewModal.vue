<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { Head } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import StageBadge from './StageBadge.vue'
import LeadActivityTimeline from './LeadActivityTimeline.vue'
import LeadWhatsAppPanel from './LeadWhatsAppPanel.vue'
import SectionBoundary from './SectionBoundary.vue'
import LeadReassignPanel from './LeadReassignPanel.vue'
import { brokerLabel } from '@/lib/brokerLabel.js'

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
// other leads on this number — see LeadController::sameMobile()
const sameMobile = ref([])
const loading = ref(false)
// role => candidates, not a flat list — see LeadController::reassignCandidates()
const reassignCandidates = ref({})

async function load() {
  if (!props.show || !props.leadId) {
    lead.value = null; timeline.value = []; reassignCandidates.value = {}; sameMobile.value = []
    return
  }

  loading.value = true
  try {
    const { data } = await axios.get(route('leads.show', props.leadId))
    lead.value = data.lead
    timeline.value = data.timeline ?? []
    reassignCandidates.value = data.reassignCandidates ?? {}
    sameMobile.value = data.sameMobile ?? []
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

watch(() => props.show, () => load())

// shown whenever ANY stage's desk has somebody to offer, not only the one the
// lead happens to be sitting in right now — see LeadController::reassignCandidates()
const canReassign = computed(() => Object.values(reassignCandidates.value).some(list => list.length))

const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'
</script>

<template>
  <Modal :show="show" :title="lead?.full_name ?? 'Lead'" @close="emit('close')">
    <!-- names the tab after the open lead; unmounting on close hands it back to the page -->
    <Head v-if="show && lead" :title="lead.full_name" />

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

      <div v-if="sameMobile.length" class="warn-box mt-5">
        <p class="text-xs font-semibold">This phone number is on {{ sameMobile.length }} other lead{{ sameMobile.length === 1 ? '' : 's' }}</p>
        <ul class="mt-1.5 space-y-1 text-xs">
          <li v-for="(other, i) in sameMobile" :key="other.id ?? `hidden-${i}`" class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>{{ other.name ?? 'A lead you cannot view' }}</span>
            <span class="text-slate-500">· {{ other.project ?? 'No project' }} ·</span>
            <StageBadge :stage="other.stage" />
            <span class="text-slate-500">· {{ other.owner ?? 'Please contact the admin' }}</span>
          </li>
        </ul>
      </div>

      <!-- each section fails on its own, without blanking the lead — see SectionBoundary -->
      <SectionBoundary name="Activity">
        <LeadActivityTimeline :timeline="timeline" :stage-colors="options.stageColors" />
      </SectionBoundary>

      <SectionBoundary name="WhatsApp">
        <LeadWhatsAppPanel :lead-id="lead.id" />
      </SectionBoundary>

      <SectionBoundary name="Reassign">
        <LeadReassignPanel v-if="allowEdit && canReassign" :lead="lead" :candidates="reassignCandidates"
                           :options="options" @reassigned="load" />
      </SectionBoundary>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Close</button>
      <button v-if="allowFollowUp && lead?.pending_todo" class="btn flex-1 sm:flex-none"
              @click="emit('followup', lead)">Update follow-up</button>
      <button v-if="allowEdit" class="btn flex-1 sm:flex-none" @click="emit('edit', leadId)">Edit lead</button>
    </template>
  </Modal>
</template>
