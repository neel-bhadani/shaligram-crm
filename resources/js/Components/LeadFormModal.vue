<script setup>
import { ref, watch, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import SearchableSelect from './SearchableSelect.vue'
import InlinePartnerForm from './InlinePartnerForm.vue'
import { toast } from '@/composables/useToast'
import { useFutureDateTime } from '@/composables/useFutureDateTime'
import { useFollowUpConflict } from '@/composables/useFollowUpConflict'
import { pickable } from '@/composables/useTaxonomy'

const props = defineProps({
  show: Boolean,
  lead: Object,          // null when adding
  options: Object,
})
const emit = defineEmits(['close'])

/*
 | The lead's own columns. The three `follow_up_*` fields below are not among
 | them — they describe the to-do this form books alongside the lead, and the
 | controller strips them back out before the insert.
 */
const blank = {
  first_name: '', middle_name: '', last_name: '',
  mobile_number: '', email: '',
  project_id: '', source: 'walk_in',
  /*
   | `broker_name` is NOT here any more, and it is not sent.
   |
   | The column still exists and every lead created before channel partners
   | carries its text — that is the point. A blank in this object would post an
   | empty string over it the first time somebody edited an old lead's phone
   | number, which is exactly the history the brief said not to lose.
   | LeadRequest does not validate the key either, so nothing can write it.
   */
  channel_partner_id: '', stage: 'fresh', reason: '', booked_unit: '',
}

const blankFollowUp = { follow_up_type: 'call', follow_up_at: '', follow_up_remarks: '' }

const form = useForm({ ...blank, ...blankFollowUp })
const duplicate = ref(null)

/*
 | A telecaller may open an existing lead and change its Stage and its Next
 | follow-up — see LeadPolicy::update() and LeadRequest::restrictedToCoreDetails()
 | — but not the lead's own core details. Locked only on an edit: a telecaller
 | cannot reach this form with `lead` null in the first place, since `add_leads`
 | gates the Add button that is the only way to open it blank.
 |
 | This is a courtesy, not the boundary — LeadRequest rejects a changed value
 | on any of these fields server-side even if a crafted request disables
 | nothing at all.
 */
const coreFieldsLocked = computed(() =>
  usePage().props.auth.user.role === 'telecaller' && Boolean(props.lead))

const lockedHint = computed(() =>
  coreFieldsLocked.value ? 'Telecallers can update the stage and follow-up here, not this field.' : '')

// the past-date guard on the follow-up field; the message matches the one
// LeadRequest sends back, so a bypassed `min` reads the same either way
const { min: minAt, refresh: refreshMinAt, past: datePast, error: dateError } =
  useFutureDateTime(() => form.follow_up_at, 'The next follow-up must be in the future.')

/*
 | The two vocabulary dropdowns: what is still in use, plus whatever this lead
 | already holds. Opening a lead whose stage or source was retired after it was
 | filed must show that value and must save — LeadRequest allows exactly the
 | same set, and dropping the option would leave the field blank and move the
 | lead somewhere nobody chose on the first save.
 */
const stageOptions  = computed(() => pickable(props.options.stages, props.options.activeStages, props.lead?.stage))
const sourceOptions = computed(() => pickable(props.options.sources, props.options.activeSources, props.lead?.source))

/*
 | The Project dropdown's options: the project(s) this user may file a lead
 | against, plus — when editing — whichever project the lead is already on.
 |
 | The list is scoped per user now (a salesperson sees only the project(s)
 | they are tied to — see LeadController::visibleProjects()), and an existing
 | lead's own project can be one they are no longer tied to. Without this, an
 | edit that never touches Project would show a select with a value nothing
 | in the list matches.
 */
const projectOptions = computed(() => {
  const own = props.lead?.project

  return own && !props.options.projects.some(p => p.id === own.id)
    ? [...props.options.projects, own]
    : props.options.projects
})

/*
 | What a NEW lead starts on. Walk-in and Fresh while those are still in use —
 | they are the overwhelmingly common answer and were the hardcoded defaults —
 | and otherwise the first option the dropdown is actually offering. A default
 | naming a stage the admin has retired would render as a blank select that
 | fails validation on submit.
 */
const firstOffered = (options, preferred) =>
  options.some(o => o.key === preferred) ? preferred : (options[0]?.key ?? '')

/* conditional fields */
const showBroker = computed(() => form.source === 'broker')
const showReason = computed(() => form.stage === 'lost')
const showUnit   = computed(() => form.stage === 'booking_done')

/*
 | The picker's options: the active partners the server sent, plus this lead's
 | own partner if it has since been switched off.
 |
 | Without the second half, opening an old lead whose broker has been
 | deactivated would show an empty picker and — because the field is required
 | for broker leads — force the user to reattribute a lead they only opened to
 | fix a spelling. LeadRequest makes the same exception on the way back in.
 */
/*
 | Partners created from inside this modal, before any page reload has told the
 | server-side props about them.
 |
 | They do not stay here long. A failed lead submit is an Inertia redirect back
 | with errors, which refreshes the page props — so the new partner arrives in
 | `options.channelPartners` on its own and this list becomes redundant. It
 | matters for the minutes in between: from the moment the partner is created to
 | the moment the lead is first submitted, this array is the only thing that
 | knows the row exists, and without it the select would have nothing to show
 | for the id it is holding.
 */
const created = ref([])

/** Every partner the picker offers, deduplicated by id. */
const knownPartners = computed(() => {
  const seen = new Map()

  for (const p of [...props.options.channelPartners, ...created.value]) {
    seen.set(p.id, p)
  }

  return [...seen.values()]
})

const partnerOptions = computed(() => {
  const options = knownPartners.value.map(p => ({ value: p.id, label: p.label, name: p.name }))
  const current = props.lead?.channel_partner

  if (current && !options.some(o => o.value === current.id)) {
    options.unshift({
      value: current.id,
      label: `${current.display_label} (inactive)`,
      name: current.name,
    })
  }

  return options
})

/* ---------------- adding a partner without leaving this modal ---------------- */

/*
 | The inline form is a section of THIS modal rather than a second modal on top
 | of it — see InlinePartnerForm for why stacking breaks on a phone.
 |
 | `addingPartner` is the open/closed state and `partnerSeed` is whatever had
 | been typed into the select's search box when Add new was chosen, so the name
 | field starts filled in with the thing the user was already looking for.
 */
const addingPartner = ref(false)
const partnerSeed = ref('')

const startAddPartner = (typed = '') => {
  partnerSeed.value = typed
  addingPartner.value = true
}

const cancelAddPartner = () => { addingPartner.value = false; partnerSeed.value = '' }

/*
 | The partner exists now. Remember it, select it, collapse the form — and leave
 | the lead exactly as it was, half typed. Nothing about the lead has been saved
 | and nothing about it is about to be.
 */
const onPartnerCreated = partner => {
  created.value.push(partner)
  form.channel_partner_id = partner.id
  form.clearErrors('channel_partner_id')
  cancelAddPartner()
}

/** The near-match warning's "use that one instead" — the same ending, no new row. */
const onPartnerSelected = id => {
  form.channel_partner_id = id
  form.clearErrors('channel_partner_id')
  cancelAddPartner()
}

/*
 | A lead that predates channel partners: broker text, no partner row. Nothing
 | guessed a match for it, so the form does not demand one — it shows what the
 | lead has always said and lets the user attribute it if they want to.
 */
const legacyBroker = computed(() =>
  props.lead && !props.lead.channel_partner_id ? props.lead.broker_name : null)

/*
 | The first follow-up, which nothing schedules on its own any more.
 |
 | Asked for whenever this save leaves the lead open at a stage it was not
 | already sitting at — every new lead, and every stage change on an existing
 | one. Booking or losing it is the case where no task should exist at all, so
 | the fields go and none is created.
 |
 | A stage that is not moving is the third case: the lead is already holding its
 | pending to-do and the user is here to fix a phone number, so asking again
 | would only cancel that task and recreate it. LeadRequest stops requiring the
 | fields on exactly this condition, and LeadFollowUpService leaves the existing
 | task standing when no date arrives.
 */
const stageMoved  = computed(() => !props.lead || props.lead.stage !== form.stage)
const isTerminal  = computed(() => props.options.terminalStages.includes(form.stage))
const showFollowUp = computed(() => stageMoved.value && !isTerminal.value)

// "Site visit scheduled" hands the lead to a salesperson, and the task it hands
// over is the visit itself — so the type is fixed rather than offered
const visitPreset = computed(() => form.stage === props.options.handoverStage)

/*
 | The handover, and the one case this form must not warn about.
 |
 | Moving an existing lead to "Site visit scheduled" hands it to the desk that
 | stage belongs to (`handoverRole`) whenever its owner is on another one — a
 | salesperson picked by a round robin that does not run until this saves — so
 | the follow-up being booked lands on somebody whose name is not knowable here.
 | A warning would name the person holding it now, which is the wrong diary — so
 | nothing is shown.
 |
 | A NEW lead is not affected. Its owner is decided from the stage it is created
 | at, and `defaultOwners` already names them.
 */
const handsOver = computed(() =>
  Boolean(props.lead) && visitPreset.value && props.lead.assigned_role !== props.options.handoverRole)

/*
 | Is the person this follow-up lands on already busy at that time?
 |
 | An existing lead answers for itself; a new one is going to the owner the
 | server would pick for the project and stage chosen, which is what
 | `defaultOwners` holds, keyed project then stage — a salesperson comes from
 | the project's own team. LeadController asks the same LeadAssignmentService
 | store() does, so the name in the warning is the person who will actually get
 | the lead.
 |
 | The lead's current pending task is excluded because this save replaces it:
 | a stage change cancels the task standing and writes the new one, so a clash
 | with the row being replaced is a clash with nothing.
 |
 | A warning, nothing more — the button below is not disabled by it.
 */
const { conflict, clear: clearConflict } = useFollowUpConflict({
  user: () => (props.lead
    ? props.lead.assigned_to
    : props.options.defaultOwners?.[form.project_id]?.[form.stage]),
  at: () => (showFollowUp.value ? form.follow_up_at : ''),
  exclude: () => props.lead?.pending_todo?.id ?? null,
  skip: () => handsOver.value,
})

watch(() => props.show, v => {
  duplicate.value = null
  clearConflict()
  form.clearErrors()

  if (!v) return

  // now, as of this opening — not as of whenever the page was loaded
  refreshMinAt()
  Object.assign(form, blankFollowUp)
  // a half-filled partner form must not be waiting inside the next lead
  cancelAddPartner()

  if (props.lead) {
    Object.keys(blank).forEach(k => (form[k] = props.lead[k] ?? blank[k]))
  } else {
    Object.assign(form, blank)
    form.project_id = props.options.projects[0]?.id ?? ''
    form.source = firstOffered(sourceOptions.value, 'walk_in')
    form.stage  = firstOffered(stageOptions.value, 'fresh')
  }
})

// clear a hidden field, or a stale value gets saved. The controller nulls
// channel_partner_id for a non-broker source as well, which covers a stale tab
watch(showBroker, v => {
  if (!v) {
    form.channel_partner_id = ''
    // the section belongs to a field that is no longer on screen
    cancelAddPartner()
  }
})
watch(showReason, v => { if (!v) form.reason = '' })
watch(showUnit,   v => { if (!v) form.booked_unit = '' })

watch([showFollowUp, visitPreset], ([shown, visit]) => {
  if (!shown) {
    Object.assign(form, blankFollowUp)

    return
  }

  if (visit) form.follow_up_type = 'site_visit'
})

/* live duplicate check — the unique index is the real guarantee */
let timer
const checkDuplicate = () => {
  clearTimeout(timer)
  duplicate.value = null

  if (String(form.mobile_number).length !== 10 || !form.project_id) return

  timer = setTimeout(async () => {
    try {
      const { data } = await axios.post(route('leads.check-duplicate'), {
        mobile_number: form.mobile_number,
        project_id: form.project_id,
        lead_id: props.lead?.id,
      })
      duplicate.value = data.exists ? data.message : null
    } catch (e) {
      // 422 only means the number or project is not usable yet — nothing to say.
      // Anything else and the check genuinely did not run, so warn: the unique
      // index will still reject a duplicate, but not until save.
      if (e?.response?.status !== 422) {
        toast.warning('Could not check for a duplicate number. Please verify before saving.')
      }
    }
  }, 400)
}
watch(() => [form.mobile_number, form.project_id], checkDuplicate)

const submit = () => {
  const opts = {
    preserveScroll: true,
    onSuccess: () => { emit('close'); form.reset() },
  }
  props.lead
    ? form.put(route('leads.update', props.lead.id), opts)
    : form.post(route('leads.store'), opts)
}
</script>

<template>
  <Modal :show="show" :title="lead ? 'Edit lead' : 'Add lead'" @close="emit('close')">

    <div class="grid gap-4 sm:grid-cols-3">
      <FormField label="First name" required :error="form.errors.first_name" :hint="lockedHint">
        <input v-model="form.first_name" type="text" :disabled="coreFieldsLocked" />
      </FormField>
      <FormField label="Middle name" :error="form.errors.middle_name" :hint="lockedHint">
        <input v-model="form.middle_name" type="text" :disabled="coreFieldsLocked" />
      </FormField>
      <FormField label="Last name" required :error="form.errors.last_name" :hint="lockedHint">
        <input v-model="form.last_name" type="text" :disabled="coreFieldsLocked" />
      </FormField>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Mobile number" required :error="form.errors.mobile_number" :hint="lockedHint">
        <input v-model="form.mobile_number" type="text" maxlength="10" inputmode="numeric"
               :disabled="coreFieldsLocked" />
        <div v-if="duplicate" class="warn-box mt-2">{{ duplicate }}</div>
      </FormField>
      <FormField label="Email" :error="form.errors.email" :hint="lockedHint">
        <input v-model="form.email" type="email" :disabled="coreFieldsLocked" />
      </FormField>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Project" required :error="form.errors.project_id" :hint="lockedHint">
        <select v-model="form.project_id" :disabled="coreFieldsLocked">
          <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </FormField>
      <FormField label="Source" required :error="form.errors.source" :hint="lockedHint">
        <select v-model="form.source" :disabled="coreFieldsLocked">
          <option v-for="o in sourceOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
        </select>
      </FormField>
    </div>

    <!--
      The channel partner, which replaced the free-text broker name.
      "Ravi Kumar — Shreeji Realty" is one option and "Shreeji Realty" is
      another: a lead can come through a firm directly, through a broker inside
      it, or through an individual broker with no firm at all.
    -->
    <FormField v-if="showBroker" class="mt-4" label="Channel partner"
               :required="!legacyBroker"
               :hint="legacyBroker
                 ? 'This lead records its broker as free text from before the partner list existed. Leave it, or pick the matching partner to start reporting on it.'
                 : 'The firm or broker this lead came through. Type to search.'"
               :error="form.errors.channel_partner_id">
      <!--
        DEFENCE ONE of three against duplicates: the typeahead.
        An existing partner has to surface before anybody reaches for Add new,
        so the search matches on partial names, word by word and in any order —
        "ravi shreeji" finds "Ravi Kumar — Shreeji Realty". The Add new row is
        pinned above the matches and the matches stay visible underneath it, so
        picking the partner that already exists is always the shorter path.
      -->
      <div class="flex gap-2">
        <div class="min-w-0 flex-1">
          <SearchableSelect v-model="form.channel_partner_id" :options="partnerOptions"
                            placeholder="Type to search firms and brokers"
                            empty-text="No partner matches that name."
                            create-label="Add new partner"
                            @create="startAddPartner" />
        </div>
        <!-- the same action beside the control, for anyone who never opens the
             list because they already know the partner is not in it -->
        <button type="button" class="btn-ghost flex-none whitespace-nowrap"
                :disabled="addingPartner" @click="startAddPartner('')">+ New</button>
      </div>

      <!-- what the lead has said since before this feature, still saying it -->
      <p v-if="legacyBroker" class="mt-1.5 text-xs text-slate-500">
        Currently recorded as <span class="font-medium text-slate-700">{{ legacyBroker }}</span>.
      </p>

      <!--
        Expanded in place, below the select it belongs to. Not a second modal:
        two stacked dialogs mean two scroll locks and two Escape handlers, and
        on a phone the sheet underneath is still the one the browser scrolls.
      -->
      <InlinePartnerForm
        v-if="addingPartner"
        :initial-name="partnerSeed"
        :firms="options.partnerFirms"
        :types="options.partnerTypes"
        :partners="knownPartners"
        @created="onPartnerCreated"
        @select="onPartnerSelected"
        @cancel="cancelAddPartner"
      />
    </FormField>

    <FormField class="mt-4" label="Stage" required :error="form.errors.stage">
      <select v-model="form.stage">
        <option v-for="o in stageOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
      </select>
    </FormField>

    <FormField v-if="showReason" class="mt-4" label="Reason for loss" required :error="form.errors.reason">
      <select v-model="form.reason">
        <option value="">Select a reason</option>
        <option v-for="(label, key) in options.reasons" :key="key" :value="key">{{ label }}</option>
      </select>
    </FormField>

    <FormField v-if="showUnit" class="mt-4" label="Unit booked" required :error="form.errors.booked_unit">
      <input v-model="form.booked_unit" type="text" placeholder="A-402" />
    </FormField>

    <!-- the first follow-up: nothing schedules one on its own any more -->
    <template v-if="showFollowUp">
      <div class="mt-6 border-t border-slate-200 pt-5">
        <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
          {{ lead ? 'Next follow-up' : 'First follow-up' }}
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="Type" required
                     :hint="visitPreset ? 'Fixed by the stage: this follow-up is the site visit.' : ''"
                     :error="form.errors.follow_up_type">
            <select v-model="form.follow_up_type" :disabled="visitPreset">
              <option v-for="(label, key) in options.types" :key="key" :value="key">{{ label }}</option>
            </select>
          </FormField>

          <FormField label="Scheduled at" required
                     hint="Saved exactly as entered."
                     :error="form.errors.follow_up_at || dateError">
            <input v-model="form.follow_up_at" type="datetime-local" :min="minAt" />
            <!-- a warning, not a refusal: this time saves if the user keeps it -->
            <div v-if="conflict" class="warn-box mt-2">{{ conflict }}</div>
          </FormField>
        </div>

        <FormField class="mt-4" label="Remarks"
                   hint="A note on the follow-up itself. Optional."
                   :error="form.errors.follow_up_remarks">
          <textarea v-model="form.follow_up_remarks" rows="2"
                    placeholder="Call after 6 PM, prefers WhatsApp."></textarea>
        </FormField>
      </div>
    </template>

    <div v-else-if="isTerminal" class="info-box mt-5">
      This lead is closed, so no follow-up will be scheduled.
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none"
              :disabled="form.processing || (showFollowUp && datePast)" @click="submit">
        {{ form.processing ? 'Saving…' : (lead ? 'Save changes' : 'Add lead') }}
      </button>
    </template>
  </Modal>
</template>
