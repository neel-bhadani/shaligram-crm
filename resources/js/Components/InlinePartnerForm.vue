<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'
import FormField from './FormField.vue'
import { nearMatches } from '@/lib/partnerName.js'

/*
 | Adding a channel partner without leaving the Add lead modal.
 |
 | A SECTION, NOT A SECOND MODAL. A modal stacked on a modal is two scroll
 | locks, two Escape handlers racing for the same keypress and, on a phone, a
 | sheet rising over a sheet where the one underneath is still the one the
 | browser thinks it is scrolling. This expands below the partner select inside
 | the form that is already open, so there is one dialog, one scroll container
 | and one way out.
 |
 | FOUR FIELDS, and the shortness is the feature rather than a compromise. The
 | person filling this in has somebody on the phone. Type, name, firm and a
 | number is what they can answer without breaking the conversation; asking for
 | a contact person, an email and an address as well gets the form abandoned or
 | gets six fields of rubbish, and rubbish is worse than blanks because it looks
 | like data. The rest is filled in later from the Channel Partners page.
 |
 | The lead is NOT saved by any of this. The partner is created on its own, the
 | select above is pointed at it, this section collapses, and the user carries
 | on typing the lead — which may still fail validation afterwards without the
 | partner being affected. See ChannelPartnerController::quickStore() for why
 | the two are deliberately not one transaction.
 */
const props = defineProps({
  /** Prefilled from whatever was typed into the select's search box. */
  initialName: { type: String, default: '' },

  /** Active firms, for the parent dropdown: [{ id, name }]. */
  firms: { type: Array, required: true },

  /** firm / broker labels, from config('crm.channel_partner_types'). */
  types: { type: Object, required: true },

  /**
   * Every partner the lead form knows about, for the near-match warning:
   * [{ id, name, label }]. Active ones — the switched-off and deleted rows the
   * client cannot see are caught by the server instead, which is the only place
   * that can see them.
   */
  partners: { type: Array, required: true },
})

const emit = defineEmits(['created', 'cancel', 'select'])

const blank = { type: 'broker', name: '', parent_id: '', phone: '' }

const form = ref({ ...blank })
const errors = ref({})
const conflict = ref(null)
const saving = ref(false)
// the near-match the user has explicitly chosen to ignore
const dismissed = ref(false)
// what the server found that this page could not — see remoteMatches below
const remote = ref([])

watch(() => props.initialName, name => {
  Object.assign(form.value, { ...blank, name: name ?? '' })
  errors.value = {}
  conflict.value = null
  dismissed.value = false
  remote.value = []
}, { immediate: true })

const isBroker = computed(() => form.value.type === 'broker')

// clear the hidden field, or a stale parent gets posted — the server nulls it
// for a firm as well, and refuses one that arrives anyway
watch(isBroker, broker => { if (!broker) form.value.parent_id = '' })

/*
 | DEFENCE TWO of three: the near-match warning.
 |
 | The typeahead above is the first — an existing partner is meant to surface
 | before anybody reaches for Add new — and the unique index is the third. This
 | is the one that catches the case the other two cannot: a name that is not
 | byte-identical to an existing row and is obviously the same company anyway.
 | "Shreeji" against "Shreeji Realty", "shreeji realty" against "Shreeji
 | Realty", "Shreeji Estate" against "Shreeji Properties".
 |
 | It compares the identifying part of the name — lower case, punctuation gone,
 | trade words like realty / estate / properties stripped — against the same
 | reduction of every partner the form knows about. lib/partnerName.js is the
 | mirror of the model's own two methods, so this warns about exactly what the
 | server would.
 |
 | A WARNING, NEVER A REFUSAL. Two genuinely different firms can share a first
 | word, and the person on the phone knows which they are talking to; a form
 | that argued with them would get the name typed as "Shreeji Realty 2". So the
 | existing partner is one click away and the save is still available beside it.
 */
const local = computed(() => nearMatches(form.value.name, props.partners))

/*
 | The server's half of the same question, debounced as the name is typed.
 |
 | The list above is the ACTIVE partners this page was shipped, so a name that
 | closely matches one somebody switched off last month raises nothing here —
 | and that is exactly how a second row for an existing broker gets created. The
 | server can see those rows; the browser cannot. Same normalisation on both
 | sides, so this widens the population rather than changing the rule.
 |
 | The instant check is not replaced by it. A warning that arrives 400ms after
 | the user has moved on to the phone field is a warning they have already typed
 | past, so the local one fires immediately and this fills in behind it.
 */
let timer

const checkRemote = () => {
  clearTimeout(timer)

  const name = form.value.name.trim()

  if (name.length < 2) { remote.value = []; return }

  timer = setTimeout(async () => {
    try {
      const { data } = await axios.post(route('channel-partners.check-name'), { name })
      remote.value = data.matches ?? []
    } catch {
      // the local check has already had its say and the unique index is still
      // underneath this; a failed hint is not worth interrupting anybody for
      remote.value = []
    }
  }, 400)
}

watch(() => form.value.name, () => { dismissed.value = false; checkRemote() })

onBeforeUnmount(() => clearTimeout(timer))

/**
 * Everything worth warning about, deduplicated by id — the local hits (instant,
 * active only) and the server's (complete, switched-off rows included).
 */
const similar = computed(() => {
  if (dismissed.value) return []

  const seen = new Map()

  for (const p of local.value) seen.set(p.id, { ...p, is_active: true })
  for (const p of remote.value) seen.set(p.id, p)

  return [...seen.values()]
})

const canSave = computed(() =>
  !saving.value && form.value.name.trim() !== '' && form.value.phone.trim() !== '')

/** Take the suggestion: select the existing partner and close the whole thing. */
const useExisting = partner => emit('select', partner.id)

const submit = async () => {
  if (!canSave.value) return

  saving.value = true
  errors.value = {}
  conflict.value = null

  try {
    const { data } = await axios.post(route('channel-partners.quick-store'), form.value)

    emit('created', data.partner)
  } catch (e) {
    const status = e?.response?.status

    if (status === 422) {
      errors.value = e.response.data.errors ?? {}
    } else if (status === 409) {
      /*
       | Two people added the same broker in the same few seconds and the unique
       | index picked a winner. The row that won comes back with the message, so
       | this ends in one click rather than in a retry that would lose again.
       */
      conflict.value = e.response.data
    } else {
      errors.value = { name: ['Could not add the partner. Please try again.'] }
    }
  } finally {
    saving.value = false
  }
}

const firstError = field => errors.value[field]?.[0]
</script>

<template>
  <!--
    Visibly nested: an inset panel with its own heading, so it reads as a
    detour inside the lead form rather than as more lead fields.
  -->
  <div class="mt-3 rounded-lg border border-teal-200 dark:border-teal-500/30 bg-teal-50/40 dark:bg-teal-500/10 p-4">
    <div class="mb-3 flex items-baseline justify-between gap-3">
      <h4 class="text-xs font-semibold uppercase tracking-wide text-teal-900 dark:text-teal-200">
        New channel partner
      </h4>
      <span class="text-xs text-slate-500 dark:text-slate-400">The rest of the details are added later</span>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <FormField label="Type" required :error="firstError('type')">
        <select v-model="form.type">
          <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
        </select>
      </FormField>

      <FormField label="Name" required :error="firstError('name')">
        <input v-model="form.name" type="text"
               :placeholder="isBroker ? 'Ravi Kumar' : 'Shreeji Realty'" />
      </FormField>
    </div>

    <!-- brokers only: a firm is the top of the tree and has no parent -->
    <FormField v-if="isBroker" class="mt-3" label="Parent firm"
               hint="Leave blank for an individual broker."
               :error="firstError('parent_id')">
      <select v-model="form.parent_id">
        <option value="">Independent — no firm</option>
        <option v-for="f in firms" :key="f.id" :value="f.id">{{ f.name }}</option>
      </select>
    </FormField>

    <FormField class="mt-3" label="Phone" required :error="firstError('phone')">
      <input v-model="form.phone" type="text" inputmode="tel" />
    </FormField>

    <!--
      The near-match. The existing partner is a button, so taking the suggestion
      is one click; proceeding anyway is one click beside it.
    -->
    <div v-if="similar.length" class="warn-box mt-3">
      <p class="font-semibold">
        Similar partner{{ similar.length === 1 ? '' : 's' }} already exist{{ similar.length === 1 ? 's' : '' }}:
      </p>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <template v-for="p in similar" :key="p.id">
          <button v-if="p.is_active !== false" type="button"
                  class="rounded-md border border-amber-300 dark:border-amber-500/40 bg-white dark:bg-slate-800 px-2.5 py-1 text-xs font-semibold
                         text-amber-900 dark:text-amber-200 hover:border-amber-500"
                  @click="useExisting(p)">
            Use “{{ p.label }}”
          </button>
          <!-- switched off, so it cannot be put on a lead: the lead form's rule
               refuses an inactive partner and offering it would be a dead end -->
          <span v-else class="rounded-md border border-amber-200 dark:border-amber-500/30 bg-white/60 dark:bg-slate-800/60 px-2.5 py-1 text-xs text-slate-700 dark:text-slate-300">
            “{{ p.label }}” — switched off. Ask an admin to reactivate it.
          </span>
        </template>
        <button type="button" class="text-xs font-medium text-amber-900 dark:text-amber-200 underline underline-offset-2"
                @click="dismissed = true">
          No, this is a different one
        </button>
      </div>
    </div>

    <!-- the same thing, decided by the unique index a moment too late -->
    <div v-if="conflict" class="warn-box mt-3">
      <p class="font-semibold">{{ conflict.message }}</p>
      <button v-if="conflict.partner" type="button"
              class="mt-2 rounded-md border border-amber-300 dark:border-amber-500/40 bg-white dark:bg-slate-800 px-2.5 py-1 text-xs font-semibold
                     text-amber-900 dark:text-amber-200 hover:border-amber-500"
              @click="useExisting(conflict.partner)">
        Use “{{ conflict.partner.label }}”
      </button>
    </div>

    <div class="mt-4 flex gap-2">
      <button type="button" class="btn" :disabled="!canSave" @click="submit">
        {{ saving ? 'Adding…' : 'Add partner' }}
      </button>
      <button type="button" class="btn-ghost" @click="emit('cancel')">Cancel</button>
    </div>
  </div>
</template>
