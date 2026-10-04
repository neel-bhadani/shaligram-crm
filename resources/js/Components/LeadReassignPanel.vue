<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { pickable } from '@/composables/useTaxonomy.js'

/*
 | Reassign one lead to a different stage and owner, from the lead modal.
 |
 | Its own component so SectionBoundary can contain it: an error in markup
 | written straight into LeadViewModal belongs to the modal and would blank it
 | whole.
 */
const props = defineProps({
  lead: { type: Object, required: true },
  // role => candidates, not a flat list — see LeadController::reassignCandidates()
  candidates: { type: Object, default: () => ({}) },
  options: { type: Object, required: true },
})
const emit = defineEmits(['reassigned'])

/* ---------------- state ---------------- */

// the stage picker decides which role's list is offered
const reassignStage = ref(props.lead.stage ?? '')
const reassignTo = ref('')
const reassigning = ref(false)

/* ---------------- worked out from it ---------------- */

/*
 | pickable(), the same helper the lead form's own stage field uses: every
 | active stage, plus the lead's current one even if it has since been
 | retired. Nothing here restricts which stage may follow which — see
 | LeadReassignRequest, which validates the same "active, or the lead's own
 | value" rule as every other stage field in the application.
 */
const reassignStageOptions = computed(() =>
  pickable(props.options.stages, props.options.activeStages, props.lead.stage))

// the desk the picked stage belongs to, falling back to the lead's own
// current owner's role for a terminal stage — see CrmTaxonomy::stageOwnerRoles()
const reassignRole = computed(() => props.options.stageOwnerRoles?.[reassignStage.value] ?? props.lead.assigned_role)

const reassignRoleCandidates = computed(() => props.candidates[reassignRole.value] ?? [])

/* ---------------- functions ---------------- */

function reassign() {
  if (!reassignTo.value) { return }

  reassigning.value = true
  // Inertia's router, not a bare axios PUT: leads.reassign redirects back()
  // on success, and only Inertia's client follows a PUT redirect as a GET
  // (a 303) — a plain axios PUT would replay the redirect as PUT against a
  // route that doesn't accept it (405). Going through the router also gets
  // the success toast for free, since app.js flashes it on every Inertia
  // response.
  router.put(route('leads.reassign', props.lead.id), {
    assigned_to: reassignTo.value,
    stage: reassignStage.value || null,
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { reassignTo.value = ''; emit('reassigned') },
    onFinish: () => { reassigning.value = false },
  })
}

/* ---------------- watchers, last ---------------- */

// picking a different stage changes who is offered — the choice made under
// the old stage rarely still makes sense under the new one
watch(reassignStage, () => { reassignTo.value = '' })

// the lead reloaded (after a reassign, or another lead opened): start from its stage
watch(() => props.lead, lead => {
  reassignStage.value = lead.stage ?? ''
  reassignTo.value = ''
})
</script>

<template>
  <div class="mt-6 border-t border-slate-100 pt-5">
    <h4 class="text-xs font-semibold text-slate-500">Reassign</h4>
    <p class="mt-0.5 text-xs text-slate-400">Move this lead to a different stage and owner.</p>
    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
      <select v-model="reassignStage" class="w-full sm:!w-auto sm:!py-1.5 sm:text-xs" aria-label="Reassign stage">
        <option v-for="s in reassignStageOptions" :key="s.key" :value="s.key">{{ s.label }}</option>
      </select>
      <select v-model="reassignTo" class="w-full sm:!w-auto sm:!py-1.5 sm:text-xs" aria-label="Reassign to">
        <option value="" disabled>Reassign to…</option>
        <option v-for="c in reassignRoleCandidates" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <button type="button" class="btn-ghost w-full sm:w-auto sm:!py-1.5 sm:text-xs"
              :disabled="!reassignTo || reassigning" @click="reassign">
        {{ reassigning ? 'Reassigning…' : 'Reassign' }}
      </button>
    </div>
  </div>
</template>
