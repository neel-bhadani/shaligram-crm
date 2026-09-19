<script setup>
import { watch, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import StageBadge from './StageBadge.vue'
import CallButtons from './CallButtons.vue'
import { useFutureDateTime } from '@/composables/useFutureDateTime'
import { useFollowUpConflict } from '@/composables/useFollowUpConflict'
import { pickable } from '@/composables/useTaxonomy'

const props = defineProps({ show: Boolean, todo: Object, options: Object })

const emit = defineEmits(['close'])

/*
 | The stages this call may be closed at: the ones still in use, plus the one
 | the lead is already standing in. That second half matters here more than
 | anywhere — a lead in a retired stage still gets called, and the person
 | logging the call must be able to leave it where it is. CompleteTodoRequest
 | allows exactly the same set.
 */
const stageOptions = computed(() =>
  pickable(props.options.stages, props.options.activeStages, props.todo?.lead?.stage))

/*
 | The form a fresh open starts from, captured once.
 |
 | Inertia rewrites a useForm's "defaults" to whatever was submitted the last
 | time the form saved successfully, so a bare reset() on open would bring
 | back the PREVIOUS follow-up's answers — its "What happened?", its next
 | date, its remarks for the next call — instead of a clean form. Restore
 | these pristine defaults first, then reset, so a reset can only ever mean
 | "a blank call record" and never "whatever the last save happened to be".
 */
const blank = {
  remarks: '', stage: 'connected',
  follow_up_type: 'call', follow_up_at: '', follow_up_remarks: '',
  reason: '', booked_unit: '', booking_date: '',
}

const form = useForm({ ...blank })

// the past-date guard on the follow-up field; the message matches the one
// CompleteTodoRequest sends back, so a bypassed `min` reads the same either way
const { min: minAt, refresh: refreshMinAt, past: datePast, error: dateError } =
  useFutureDateTime(() => form.follow_up_at, 'The next follow-up must be in the future.')

const isTerminal = computed(() => props.options.terminalStages.includes(form.stage))
const showReason = computed(() => form.stage === 'lost')
const showUnit   = computed(() => form.stage === 'booking_done')

/*
 | Booking or losing the lead ends the chain: those are the two stages that must
 | leave no pending to-do behind, so there is nothing to ask for. Every other
 | outcome leaves the lead open, and an open lead with no task is invisible on
 | every list in the application — which is why these three appear, and why the
 | date and the type are required rather than offered.
 */
const showNext = computed(() => !isTerminal.value)

/*
 | "Site visit scheduled" is the stage where the next task *is* the site visit —
 | one date, typed once, doing both jobs. The type is fixed to match, so the
 | task lands on the salesperson's list as a visit rather than as another call.
 */
const visitPreset = computed(() => form.stage === props.options.handoverStage)

/*
 | The handover, and the one case this modal must not warn about.
 |
 | Moving a lead to "Site visit scheduled" hands it to that stage's desk
 | (`handoverRole`) whenever its owner is on another, and which salesperson is
 | decided by a round robin that does not run until the form is saved. The
 | follow-up being booked here lands on that person, not on the one holding the
 | lead now — so a warning would name the wrong diary. Nothing is shown rather
 | than something wrong.
 */
const handsOver = computed(() =>
  visitPreset.value && props.todo?.lead?.assigned_role !== props.options.handoverRole)

/*
 | Otherwise the next follow-up lands on whoever owns the lead, and the task
 | being closed is excluded — it is pending right now and is about to be
 | completed by this very save, so a clash with it is a clash with nothing.
 */
const { conflict, clear: clearConflict } = useFollowUpConflict({
  user: () => props.todo?.lead?.assigned_to,
  at: () => (showNext.value ? form.follow_up_at : ''),
  exclude: () => props.todo?.id ?? null,
  skip: () => handsOver.value,
})

watch(() => props.show, v => {
  // a warning left over from the last lead this modal was opened for
  clearConflict()

  if (!v) return

  form.defaults(blank)   // undo Inertia's post-save rewrite of the defaults
  form.reset()           // ... so this now means a truly blank form
  form.clearErrors()
  // now, as of this opening — not as of whenever the page was loaded
  refreshMinAt()
  // start from the lead's current stage, except fresh which always moves on
  form.stage = props.todo?.lead?.stage === 'fresh' ? 'connected' : props.todo?.lead?.stage
})

watch(showReason, v => { if (!v) form.reason = '' })
watch(showUnit,   v => { if (!v) form.booked_unit = '' })

// a hidden field must not save a stale value, and the preset must win the
// moment the stage asks for it
watch([showNext, visitPreset], ([next, visit]) => {
  if (!next) {
    form.follow_up_at = ''
    form.follow_up_remarks = ''
    form.follow_up_type = 'call'

    return
  }

  if (visit) form.follow_up_type = 'site_visit'
})

const submit = () => form.post(route('todos.complete', props.todo.id), {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})
</script>

<template>
  <Modal :show="show" :title="`Update — ${todo?.lead?.full_name ?? ''}`" @close="emit('close')">

    <div class="mb-5 grid gap-4 sm:grid-cols-2">
      <div>
        <div class="text-[11px] font-semibold text-slate-400">Mobile</div>
        <CallButtons v-if="todo?.lead?.mobile_number" class="mt-1" :mobile="todo.lead.mobile_number" />
        <div v-else class="text-sm">—</div>
      </div>
      <div>
        <div class="text-[11px] font-semibold text-slate-400">Current stage</div>
        <div class="mt-0.5"><StageBadge v-if="todo?.lead" :stage="todo.lead.stage" /></div>
      </div>
    </div>

    <!-- not "on this call": the follow-up may be a WhatsApp, a meeting or a
         site visit, and only the type on the row says which -->
    <FormField label="What happened?" required
               hint="Recorded against the call you just made."
               :error="form.errors.remarks">
      <textarea v-model="form.remarks" rows="3"
                placeholder="Spoke to customer, asked for the floor plan…"></textarea>
    </FormField>

    <FormField class="mt-4" label="New stage" required :error="form.errors.stage">
      <select v-model="form.stage">
        <option v-for="o in stageOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
      </select>
    </FormField>

    <FormField v-if="showReason" class="mt-4" label="Reason for loss" required :error="form.errors.reason">
      <select v-model="form.reason">
        <option value="">Select a reason</option>
        <option v-for="(l, k) in options.reasons" :key="k" :value="k">{{ l }}</option>
      </select>
    </FormField>

    <FormField v-if="showUnit" class="mt-4" label="Unit booked" required :error="form.errors.booked_unit">
      <input v-model="form.booked_unit" type="text" placeholder="A-402" />
    </FormField>

    <!-- the next task: nothing schedules one on its own any more -->
    <template v-if="showNext">
      <div class="mt-6 border-t border-slate-200 dark:border-slate-700 pt-5">
        <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          Next follow-up
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="Next follow-up type" required
                     :hint="visitPreset ? 'Fixed by the stage: this follow-up is the site visit.' : ''"
                     :error="form.errors.follow_up_type">
            <select v-model="form.follow_up_type" :disabled="visitPreset">
              <option v-for="(l, k) in options.types" :key="k" :value="k">{{ l }}</option>
            </select>
          </FormField>

          <FormField label="Next follow-up date and time" required
                     :hint="visitPreset ? 'The visit the customer agreed to, saved exactly as entered.' : ''"
                     :error="form.errors.follow_up_at || dateError">
            <input v-model="form.follow_up_at" type="datetime-local" :min="minAt" />
            <!-- a warning, not a refusal: this time saves if the user keeps it -->
            <div v-if="conflict" class="warn-box mt-2">{{ conflict }}</div>
          </FormField>
        </div>

        <FormField class="mt-4" label="Remarks for the next follow-up"
                   hint="A note to whoever picks this up. Optional."
                   :error="form.errors.follow_up_remarks">
          <textarea v-model="form.follow_up_remarks" rows="2"
                    placeholder="Send the payment plan before calling."></textarea>
        </FormField>

        <div v-if="visitPreset" class="info-box mt-4">
          This also moves the lead to a salesperson, and the visit above becomes their follow-up.
        </div>
      </div>
    </template>

    <div v-else class="info-box mt-5">
      This closes the lead. No further follow-up will be scheduled.
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none"
              :disabled="form.processing || (showNext && datePast)" @click="submit">
        {{ form.processing ? 'Saving…' : (showNext ? 'Save and schedule next' : 'Save and close lead') }}
      </button>
    </template>
  </Modal>
</template>
