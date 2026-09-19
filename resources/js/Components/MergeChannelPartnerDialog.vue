<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import SearchableSelect from './SearchableSelect.vue'

/*
 | Merging one partner into another — the cleanup for what inline creation
 | costs.
 |
 | Partners are added mid-call by whoever is logging the lead, so "Shreeji",
 | "Shreeji Realty" and "Shreeji Realty Pvt Ltd" will all end up in the list.
 | The typeahead and the near-match warning catch most of it; neither can catch
 | somebody who sincerely believes the firm they are typing is a new one. This
 | is how those rows get put back together, and without it the broker report
 | degrades into a list of near-identical names within a month.
 |
 | THE DIRECTION IS THE WHOLE DIALOG. One of these two rows is about to be
 | deleted and every lead on it reattributed, so the copy never says "merge
 | these" — it names the row that disappears and the row that survives, in that
 | order, in a sentence, above a button that repeats it.
 */
const props = defineProps({ show: Boolean, partner: Object, options: Object })
const emit = defineEmits(['close'])

const form = useForm({ target_id: '' })

watch(() => props.show, v => {
  if (!v) return
  form.reset()
  form.clearErrors()
})

/*
 | Every active partner except the one being merged away. Firms and brokers
 | alike: a firm entered once as a broker is one of the commonest duplicates,
 | and it is exactly the pair this dialog exists for.
 */
const targets = computed(() =>
  (props.options.mergeTargets ?? [])
    .filter(t => t.id !== props.partner?.id)
    .map(t => ({ value: t.id, label: t.label })))

const target = computed(() => targets.value.find(t => t.value === form.target_id) ?? null)

const leads = computed(() => props.partner?.leads_count ?? 0)
const brokers = computed(() => props.partner?.brokers_count ?? 0)

/*
 | A firm's brokers move with it. If the chosen target is a broker they would
 | land under a broker, which is the two-level nesting this table forbids —
 | MergeChannelPartnerRequest refuses it, and saying so before the click is what
 | makes the refusal actionable.
 */
const targetType = computed(() =>
  (props.options.mergeTargets ?? []).find(t => t.id === form.target_id)?.type ?? null)

const brokersBlock = computed(() => brokers.value > 0 && targetType.value === 'broker')

const submit = () => form.post(route('channel-partners.merge', props.partner.id), {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})
</script>

<template>
  <Modal :show="show" title="Merge channel partner" max-width="max-w-lg" @close="emit('close')">
    <p class="text-sm text-slate-600 dark:text-slate-300">
      Everything filed against
      <span class="font-semibold text-slate-800 dark:text-slate-200">{{ partner?.display_label }}</span>
      moves to the partner you choose, and this one is then deleted.
    </p>

    <FormField class="mt-4" label="Merge into" required
               hint="Type to search. Firms and brokers both — a firm entered once as a broker is the usual case."
               :error="form.errors.target_id">
      <SearchableSelect v-model="form.target_id" :options="targets"
                        placeholder="Search for the partner to keep" />
    </FormField>

    <!-- what is about to move, in numbers, before the button is pressed -->
    <div v-if="target" class="info-box mt-4">
      <p>
        <span class="font-semibold">{{ leads }}</span> lead{{ leads === 1 ? '' : 's' }}
        <template v-if="brokers">
          and <span class="font-semibold">{{ brokers }}</span> broker{{ brokers === 1 ? '' : 's' }}
        </template>
        will be reattributed to <span class="font-semibold">{{ target.label }}</span>.
      </p>
      <p class="mt-1.5">
        “{{ partner?.name }}” is then soft deleted — the row stays for the record, and the
        name is freed so it can be entered again if it turns out to be a different firm.
      </p>
    </div>

    <div v-if="brokersBlock" class="warn-box mt-3">
      “{{ partner?.name }}” has {{ brokers }} broker{{ brokers === 1 ? '' : 's' }} filed under it,
      so it can only be merged into a firm — a broker cannot sit under another broker.
      Choose a firm, or move those brokers first.
    </div>

    <p class="mt-3 text-xs leading-relaxed text-slate-400">
      This runs as one transaction: either every lead moves and this partner is deleted, or
      nothing changes. It cannot be undone from this screen.
    </p>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn-danger flex-1 sm:flex-none disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="form.processing || !form.target_id || brokersBlock" @click="submit">
        {{ form.processing ? 'Merging…' : 'Merge and delete' }}
      </button>
    </template>
  </Modal>
</template>
