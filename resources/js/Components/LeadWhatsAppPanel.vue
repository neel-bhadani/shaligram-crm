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

/*
 | ORDER MATTERS in this file: state, then what is worked out from it, then
 | the functions, then the watchers — last. The lead watcher runs immediately,
 | during setup, and anything it touches that is declared below it is not
 | there yet: a `const` read before its line throws, and the throw takes the
 | whole lead modal down with it.
 */

/* ---------------- state ---------------- */

const data = ref(null)
const failed = ref(false)
const pickedId = ref('')
const sending = ref(false)
const result = ref(null)
// ticked to send to a customer who opted out; reset whenever the message changes
const confirmOptedOut = ref(false)
const optOutBusy = ref(false)
const optOutResult = ref(null)

/* ---------------- worked out from it ---------------- */

const picked = computed(() => data.value?.templates.find(t => t.id === Number(pickedId.value)) ?? null)
const optedOut = computed(() => !!data.value?.opt_out?.active)

/* ---------------- functions ---------------- */

async function load() {
  failed.value = false
  try {
    const response = await axios.get(route('leads.whatsapp.show', props.leadId))
    data.value = response.data
  } catch {
    failed.value = true
  }
}

/** Everything that belonged to the lead the panel was showing before. */
function reset() {
  data.value = null
  pickedId.value = ''
  result.value = null
  confirmOptedOut.value = false
  optOutResult.value = null
}

/*
 | The tab is claimed inside the click handler for click-to-send, before the
 | request goes out — a window.open() that waits for a promise is a pop-up to
 | every browser. Nothing is claimed for an API send.
 */
function send() {
  if (!picked.value) return
  if (optedOut.value && !confirmOptedOut.value) return

  const tabRef = picked.value.by_api ? null : window.open('', '_blank')
  sending.value = true
  result.value = null

  axios.post(route('leads.whatsapp.send', props.leadId), {
    template_id: picked.value.id,
    confirm_opted_out: optedOut.value && confirmOptedOut.value,
  })
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

/*
 | The opt-out switch. Anybody who can edit the lead can switch it on; only an
 | admin can switch it off again — the server decides, and says which buttons
 | to show.
 */
function setOptOut(optedOutNow) {
  optOutBusy.value = true
  optOutResult.value = null

  axios.put(route('leads.whatsapp.opt-out', props.leadId), { opted_out: optedOutNow })
    .then(({ data: body }) => {
      data.value.opt_out = body.opt_out
      optOutResult.value = { ok: true, message: body.message }
    })
    .catch(error => {
      optOutResult.value = {
        ok: false,
        message: error.response?.data?.message || 'The request did not reach the server. Nothing was changed.',
      }
    })
    .finally(() => { optOutBusy.value = false })
}

// in script, not the markup: Vue's parser reads a literal "{{" inside an
// interpolation as the start of another one
const variable = n => `{{${n}}}`

const when = iso => iso
  ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
  : '—'

/* ---------------- watchers, last ---------------- */

// immediately: this is the first load, as well as the switch to another lead
watch(() => props.leadId, () => { reset(); load() }, { immediate: true })

watch(pickedId, () => { confirmOptedOut.value = false })
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
      <!-- the opt-out: a warning when on, a switch either way -->
      <div v-if="data.opt_out?.active" class="warn-box mt-2 text-xs">
        <strong>This customer asked not to be messaged on WhatsApp.</strong>
        Bulk and automatic messages skip them.
        <span class="block text-[11px] text-slate-500">
          Opted out {{ when(data.opt_out.at) }}<template v-if="data.opt_out.by"> · recorded by {{ data.opt_out.by }}</template>
        </span>
        <button v-if="data.opt_out.can_switch_off" class="btn-xs mt-1.5" :disabled="optOutBusy"
                @click="setOptOut(false)">Allow WhatsApp messages again</button>
      </div>
      <button v-else-if="data.opt_out?.can_switch_on" class="btn-xs mt-2" :disabled="optOutBusy"
              @click="setOptOut(true)">Customer asked not to be messaged</button>
      <p v-if="optOutResult" class="mt-1 text-xs" :class="optOutResult.ok ? 'text-emerald-700' : 'text-rose-600'">
        {{ optOutResult.message }}
      </p>

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

          <label v-if="optedOut" class="mt-2 flex items-start gap-2 text-xs text-rose-700">
            <input v-model="confirmOptedOut" type="checkbox" class="mt-0.5 h-3.5 w-3.5" />
            This customer opted out. Send this one message anyway.
          </label>
          <button class="btn-xs mt-2 border-teal-600 text-teal-700"
                  :disabled="sending || (optedOut && !confirmOptedOut)" @click="send">
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
