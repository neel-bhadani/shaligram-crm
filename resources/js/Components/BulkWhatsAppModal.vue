<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { localNow, isPast } from '@/lib/localDateTime'
import { marketingNote } from '@/lib/templateFacts'

/*
 | One tag to many leads: the ticked ones, or everything matching the list's
 | filter. Admin only.
 |
 | Two steps, always. "Check" asks the server who would receive it — the
 | confirm screen: N selected · M will receive, who is left out and why, and
 | the message as one named lead would get it. Sending repeats that plan on
 | the server with the numbers shown here, and is refused if they moved.
 */
const props = defineProps({
  show: Boolean,
  // 'selected' | 'filter'
  mode: { type: String, default: 'selected' },
  leadIds: { type: Array, default: () => [] },
  // how many the list says match, for the title before checking
  count: { type: Number, default: 0 },
  // [{ id, name, bulk_refusal }] — the same tag list as everywhere else
  tags: { type: Array, default: () => [] },
})
const emit = defineEmits(['close'])

const tagId = ref('')
const later = ref(false)
const laterAt = ref('')
const plan = ref(null)
const busy = ref(false)
const problem = ref('')

const tag = computed(() => props.tags.find(t => t.id === Number(tagId.value)) ?? null)
const canCheck = computed(() => tag.value && !tag.value.bulk_refusal
  && (!later.value || (laterAt.value && !isPast(laterAt.value))))
const canSend = computed(() => plan.value && !plan.value.over_cap && plan.value.recipients > 0 && !busy.value)

const minutes = computed(() => plan.value ? Math.ceil(plan.value.recipients / plan.value.per_minute) : 0)

// in script, not the markup: Vue reads a literal "{{" as an interpolation
const variable = n => `{{${n}}}`

watch(() => props.show, open => {
  if (!open) return
  tagId.value = ''
  later.value = false
  laterAt.value = ''
  plan.value = null
  problem.value = ''
})

// anything changed after checking needs checking again
watch([tagId, later, laterAt], () => { plan.value = null })

const selection = () => ({
  template_id: tag.value?.id,
  mode: props.mode,
  lead_ids: props.mode === 'selected' ? props.leadIds : undefined,
})

function check() {
  busy.value = true
  problem.value = ''

  axios.post(route('leads.whatsapp.bulk.preview'), selection())
    .then(({ data }) => { plan.value = data })
    .catch(error => { problem.value = error.response?.data?.message ?? 'The request did not reach the server.' })
    .finally(() => { busy.value = false })
}

function send() {
  busy.value = true
  problem.value = ''

  axios.post(route('leads.whatsapp.bulk.start'), {
    ...selection(),
    send_at: later.value ? laterAt.value : null,
    expect_selected: plan.value.selected,
    expect_recipients: plan.value.recipients,
  })
    .then(({ data }) => {
      emit('close')
      // the server flashed what it started; the Queue shows it on arrival
      router.visit(data.url)
    })
    .catch(error => {
      const body = error.response?.data
      problem.value = body?.message ?? 'The request did not reach the server. Nothing was sent.'
      // the list moved: show the new numbers to confirm instead
      if (body?.selected !== undefined) plan.value = { ...plan.value, ...body }
    })
    .finally(() => { busy.value = false })
}
</script>

<template>
  <Modal :show="show" title="Send a WhatsApp tag to many leads" max-width="max-w-2xl" @close="emit('close')">
    <div class="space-y-4 text-sm">
      <p class="text-slate-600">
        {{ mode === 'selected'
          ? `To the ${count} lead${count === 1 ? '' : 's'} you ticked.`
          : `To every lead matching the list's filter — ${count} right now.` }}
      </p>

      <FormField label="Tag" required>
        <select v-model="tagId" class="w-full">
          <option value="">Choose a tag…</option>
          <option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
      </FormField>
      <p v-if="tag?.bulk_refusal" class="warn-box text-xs">{{ tag.bulk_refusal }}</p>
      <p v-if="tag?.approval_warning" class="warn-box text-xs">{{ tag.approval_warning }}</p>
      <p v-if="tag?.marketing" class="text-[11px] leading-relaxed text-amber-800">{{ marketingNote }}</p>

      <div v-if="tag && !tag.bulk_refusal" class="flex flex-wrap items-center gap-3 text-xs text-slate-700">
        <label class="flex items-center gap-1.5">
          <input v-model="later" type="radio" :value="false" class="h-3.5 w-3.5" /> Send now
        </label>
        <label class="flex items-center gap-1.5">
          <input v-model="later" type="radio" :value="true" class="h-3.5 w-3.5" /> Send later
        </label>
        <template v-if="later">
          <input v-model="laterAt" type="datetime-local" class="w-56 text-xs" :min="localNow()"
                 aria-label="Send at (India time)" />
          <span class="text-[11px] text-slate-400">India time</span>
          <span v-if="isPast(laterAt)" class="w-full text-[11px] text-rose-600">That time has already gone.</span>
        </template>
      </div>

      <!-- ---------------- the confirm screen ---------------- -->
      <div v-if="plan" class="space-y-3 border-t border-slate-100 pt-4">
        <p class="text-base font-semibold text-slate-900">
          {{ plan.selected }} selected · {{ plan.recipients }} will receive it
        </p>

        <p v-if="plan.over_cap" class="warn-box text-xs">
          {{ plan.recipients }} would receive it, and a bulk send is limited to {{ plan.cap }}.
          Nothing can be sent until fewer would — narrow the selection.
        </p>
        <p v-else-if="plan.recipients" class="text-xs text-slate-500">
          Sent {{ plan.per_minute }} a minute, so about {{ minutes }} minute{{ minutes === 1 ? '' : 's' }} from the start.
          Each lead's details are read again when theirs goes.
        </p>

        <div v-if="plan.excluded.length">
          <p class="text-xs font-semibold text-slate-500">Left out, and why</p>
          <details v-for="group in plan.excluded" :key="group.reason" class="mt-1 text-xs text-slate-600">
            <summary class="cursor-pointer">{{ group.reason }} — {{ group.count }}</summary>
            <ul class="mt-1 max-h-40 overflow-y-auto rounded bg-slate-50 px-3 py-2">
              <li v-for="lead in group.leads" :key="lead.id">{{ lead.name }} · {{ lead.number ?? 'no number' }}</li>
              <li v-if="group.count > group.leads.length" class="text-slate-400">
                and {{ group.count - group.leads.length }} more — all listed on the send in the Queue
              </li>
            </ul>
          </details>
        </div>

        <div v-if="plan.sample">
          <p class="text-xs font-semibold text-slate-500">{{ plan.sample.name }} would get</p>
          <div v-if="plan.sample.body" class="mt-1 rounded-xl bg-slate-100 p-2.5">
            <div class="whitespace-pre-wrap rounded-xl rounded-tl-sm bg-white px-3 py-2 text-xs leading-relaxed text-slate-800 shadow-sm">{{ plan.sample.body }}</div>
          </div>
          <p v-else class="mt-1 text-[11px] text-slate-500">
            11za's wording for this tag has not been read yet, so it cannot be shown. Press Refresh from 11za on the Tags tab first.
          </p>
          <details v-if="plan.sample.values.length" class="mt-1 text-[11px] text-slate-400">
            <summary class="cursor-pointer">What 11za receives</summary>
            <p class="mt-1">
              <span v-for="(v, i) in plan.sample.values" :key="v.position">
                {{ i ? ', ' : '' }}{{ variable(v.position) }} = “{{ v.value }}”
              </span>
            </p>
          </details>
        </div>
      </div>

      <p v-if="problem" class="text-xs text-rose-600">{{ problem }}</p>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button v-if="!plan" class="btn flex-1 sm:flex-none" :disabled="!canCheck || busy" @click="check">
        {{ busy ? 'Checking…' : 'Check who will receive it' }}
      </button>
      <button v-else class="btn flex-1 sm:flex-none" :disabled="!canSend" @click="send">
        {{ busy ? 'Starting…' : `${later ? 'Schedule for' : 'Send to'} ${plan.recipients} lead${plan.recipients === 1 ? '' : 's'}` }}
      </button>
    </template>
  </Modal>
</template>
