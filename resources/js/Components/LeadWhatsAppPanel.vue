<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'

/*
 | WhatsApp for ONE lead: pick a message, see it filled in with this lead's
 | real values, send it. There is no list of leads anywhere in here, on
 | purpose — this is a per-lead action, not a broadcast.
 |
 | By API when the API is on and the message has an 11za template name;
 | otherwise click-to-send, which opens WhatsApp with the text typed.
 |
 | The 24-hour window is always "unknown, template required". Knowing it needs
 | the inbound webhook, which is not built; the panel never claims the lead is
 | outside it.
 */
const props = defineProps({
  leadId: { type: Number, required: true },
})

const data = ref(null)
const failed = ref(false)
const pickedId = ref('')
const sending = ref(false)
const result = ref(null)

async function load() {
  failed.value = false
  try {
    const response = await axios.get(route('leads.whatsapp.show', props.leadId))
    data.value = response.data
  } catch {
    failed.value = true
  }
}

watch(() => props.leadId, () => { data.value = null; pickedId.value = ''; result.value = null; load() }, { immediate: true })

const picked = computed(() => data.value?.templates.find(t => t.id === Number(pickedId.value)) ?? null)

/*
 | The tab is claimed inside the click handler for click-to-send, before the
 | request goes out — a window.open() that waits for a promise is a pop-up to
 | every browser. Nothing is claimed for an API send.
 */
function send() {
  if (!picked.value) return

  const tabRef = picked.value.by_api ? null : window.open('', '_blank')
  sending.value = true
  result.value = null

  axios.post(route('leads.whatsapp.send', props.leadId), { template_id: picked.value.id })
    .then(({ data: body }) => {
      if (tabRef && body.url) tabRef.location = body.url
      result.value = { ok: true, message: body.message }
      if (body.history) data.value.history = body.history
    })
    .catch(error => {
      if (tabRef) tabRef.close()
      const body = error.response?.data
      result.value = {
        ok: false,
        message: body?.message ?? 'The request did not reach the server. Nothing was sent.',
      }
      if (body?.history) data.value.history = body.history
    })
    .finally(() => { sending.value = false })
}

// in script, not the markup: Vue's parser reads a literal "{{" inside an
// interpolation as the start of another one
const variable = n => `{{${n}}}`

const when = iso => iso
  ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
  : '—'
</script>

<template>
  <div class="mt-6 border-t border-slate-100 pt-5">
    <div class="flex flex-wrap items-center gap-2">
      <h4 class="text-xs font-semibold text-slate-500">WhatsApp</h4>
      <span v-if="data" class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px]
                               font-semibold text-slate-500">{{ data.window }}</span>
    </div>

    <p v-if="failed" class="mt-2 text-xs text-rose-600">Could not load WhatsApp messages for this lead.</p>
    <p v-else-if="!data" class="mt-2 text-xs text-slate-400">Loading…</p>

    <template v-else>
      <p v-if="!data.number" class="mt-2 text-xs text-slate-500">
        This lead has no usable mobile number, so there is nothing to send to.
      </p>

      <template v-else-if="data.templates.length">
        <p class="mt-0.5 text-xs text-slate-400">
          {{ data.api_enabled
            ? 'Messages marked “by API” are sent through 11za. The rest open in WhatsApp for you to send.'
            : 'Opens in WhatsApp with the message typed, for you to send.' }}
        </p>

        <select v-model="pickedId" class="mt-3 w-full sm:!py-1.5 sm:text-xs" aria-label="WhatsApp message">
          <option value="">Choose a message…</option>
          <option v-for="t in data.templates" :key="t.id" :value="t.id">
            {{ t.name }}{{ t.by_api ? ' · by API' : ' · click-to-send' }}
          </option>
        </select>

        <div v-if="picked" class="mt-3">
          <div class="rounded-xl bg-slate-100 p-2.5">
            <div class="whitespace-pre-wrap rounded-xl rounded-tl-sm bg-white px-3 py-2 text-xs
                        leading-relaxed text-slate-800 shadow-sm">{{ picked.preview }}</div>
          </div>
          <p v-if="picked.by_api && picked.values.length" class="mt-1.5 text-[11px] text-slate-400">
            Sent as {{ picked.provider_template }} with
            <span v-for="(v, i) in picked.values" :key="v.position">
              {{ i ? ', ' : '' }}{{ variable(v.position) }} = “{{ v.value || '(empty)' }}”
            </span>
          </p>
          <p v-else-if="picked.api_reason" class="mt-1.5 text-[11px] text-slate-500">
            Not by API: {{ picked.api_reason }}
          </p>

          <button class="btn-xs mt-2 border-teal-600 text-teal-700" :disabled="sending" @click="send">
            {{ sending ? 'Sending…' : (picked.by_api ? 'Send by API' : 'Open in WhatsApp') }}
            to {{ data.number }}
          </button>
        </div>

        <p v-if="result" class="mt-2 text-xs" :class="result.ok ? 'text-emerald-700' : 'text-rose-600'">
          {{ result.message }}
        </p>
      </template>

      <p v-else class="mt-2 text-xs text-slate-500">No WhatsApp messages have been written yet.</p>

      <ul v-if="data.history.length" class="mt-3 space-y-1.5">
        <li v-for="m in data.history" :key="m.id" class="text-[11px] leading-relaxed">
          <span class="font-medium text-slate-700">{{ m.template ?? 'A deleted message' }}</span>
          <span class="text-slate-400">
            · {{ m.rule ? `rule “${m.rule}”` : (m.by ? `by ${m.by}` : '') }} · {{ when(m.at) }}
          </span>
          <span class="block" :class="m.status === 'failed' ? 'text-rose-600' : 'text-slate-500'">
            {{ m.outcome }}<template v-if="m.error"> — {{ m.error }}</template>
          </span>
          <details v-if="m.provider_response" class="text-slate-500">
            <summary class="cursor-pointer">11za's response</summary>
            <pre class="mt-1 whitespace-pre-wrap break-all rounded bg-slate-50 p-2 font-mono text-[10px]">{{ m.provider_response }}</pre>
          </details>
        </li>
      </ul>
    </template>
  </div>
</template>
