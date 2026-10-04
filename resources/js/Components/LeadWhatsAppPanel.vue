<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { localNow, isPast } from '@/lib/localDateTime'
import { marketingNote } from '@/lib/templateFacts'

/*
 | WhatsApp for ONE lead: pick a tag, see it filled in with this lead's
 | real values, send it. There is no list of leads anywhere in here, on
 | purpose — this is a per-lead action, not a broadcast.
 |
 | By API when the API is on and the tag has an 11za template name;
 | otherwise click-to-send, which opens WhatsApp with the text typed.
 |
 | No 24-hour window chip: every tag is a template, so the window never
 | changes what the user can do.
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
// send now, or later at a time typed in India time — API only
const later = ref(false)
const laterAt = ref('')
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
  later.value = false
  laterAt.value = ''
}

/*
 | The tab is claimed inside the click handler for click-to-send, before the
 | request goes out — a window.open() that waits for a promise is a pop-up to
 | every browser. Nothing is claimed for an API send.
 */
function send() {
  if (!picked.value) return
  if (optedOut.value && !confirmOptedOut.value) return

  const scheduling = picked.value.by_api && later.value
  if (scheduling && (!laterAt.value || isPast(laterAt.value))) return

  const tabRef = picked.value.by_api ? null : window.open('', '_blank')
  sending.value = true
  result.value = null

  axios.post(route('leads.whatsapp.send', props.leadId), {
    template_id: picked.value.id,
    confirm_opted_out: optedOut.value && confirmOptedOut.value,
    send_at: scheduling ? laterAt.value : null,
  })
    .then(({ data: body }) => {
      if (tabRef && body.url) tabRef.location = body.url
      result.value = { ok: true, message: body.message }
      if (scheduling) { later.value = false; laterAt.value = '' }
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
 | Cancel a message this person scheduled (or any, for an admin). The server
 | decides who may, and refuses once the worker has picked it up.
 */
const cancelling = ref(null)

function cancelScheduled(m) {
  cancelling.value = m.id
  result.value = null

  axios.post(route('leads.whatsapp.cancel', [props.leadId, m.id]))
    .then(({ data: body }) => {
      result.value = { ok: true, message: body.message }
      data.value.history = body.history
    })
    .catch(error => {
      const body = error.response?.data
      result.value = { ok: false, message: body?.message ?? 'The request did not reach the server. Nothing was changed.' }
      if (body?.history) data.value.history = body.history
    })
    .finally(() => { cancelling.value = null })
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
            ? 'Sent through 11za where possible; otherwise opens in WhatsApp for you to send.'
            : 'Opens in WhatsApp with the message typed, for you to send.' }}
        </p>

        <select v-model="pickedId" class="mt-3 w-full sm:!py-1.5 sm:text-xs" aria-label="WhatsApp tag">
          <option value="">Choose a tag…</option>
          <option v-for="t in data.templates" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>

        <div v-if="picked" class="mt-3">
          <p v-if="picked.approval_warning" class="warn-box mb-2 text-xs">{{ picked.approval_warning }}</p>
          <p v-if="picked.marketing" class="mb-2 text-[11px] leading-relaxed text-amber-800">{{ marketingNote }}</p>

          <!-- 11za's wording for this lead, or a plain "not known" — never a stand-in -->
          <div v-if="picked.preview" class="rounded-xl bg-slate-100 p-2.5">
            <div class="whitespace-pre-wrap rounded-xl rounded-tl-sm bg-white px-3 py-2 text-xs
                        leading-relaxed text-slate-800 shadow-sm">{{ picked.preview }}</div>
          </div>
          <p v-else class="text-[11px] text-slate-500">
            11za's wording for this tag has not been read yet, so it cannot be shown. An admin can press
            Refresh from 11za on the Tags tab.
          </p>
          <details v-if="picked.by_api" class="mt-1.5 text-[11px] text-slate-400">
            <summary class="cursor-pointer">What 11za receives</summary>
            <p class="mt-1">
              Template {{ picked.provider_template }}<template v-if="picked.values.length"> with
              <span v-for="(v, i) in picked.values" :key="v.position">
                {{ i ? ', ' : '' }}{{ variable(v.position) }} = “{{ v.value || '(empty)' }}”
              </span></template>
            </p>
          </details>
          <p v-else-if="picked.api_reason" class="mt-1.5 text-[11px] text-slate-500">
            Not by API: {{ picked.api_reason }}
          </p>

          <!-- send now or later: later is API only, and says so when it cannot be -->
          <div v-if="picked.by_api" class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-700">
            <label class="flex items-center gap-1.5">
              <input v-model="later" type="radio" :value="false" class="h-3.5 w-3.5" /> Send now
            </label>
            <label class="flex items-center gap-1.5">
              <input v-model="later" type="radio" :value="true" class="h-3.5 w-3.5" /> Send later
            </label>
          </div>
          <p v-else class="mt-2 text-[11px] text-slate-400">
            Send later needs API sending. This one opens in WhatsApp for you to press send, so it cannot wait for a time.
          </p>

          <div v-if="picked.by_api && later" class="mt-2">
            <label class="block text-[11px] font-medium text-slate-500" for="wa-send-at">Send at (India time)</label>
            <input id="wa-send-at" v-model="laterAt" type="datetime-local" class="mt-1 w-56 sm:!py-1.5 sm:text-xs"
                   :min="localNow()" />
            <p v-if="isPast(laterAt)" class="mt-1 text-[11px] text-rose-600">That time has already gone.</p>
            <p v-else class="mt-1 text-[11px] text-slate-400">
              The lead's details are filled in when it goes. Until then you can cancel it from the list below.
            </p>
          </div>

          <label v-if="optedOut" class="mt-2 flex items-start gap-2 text-xs text-rose-700">
            <input v-model="confirmOptedOut" type="checkbox" class="mt-0.5 h-3.5 w-3.5" />
            This customer opted out. Send this one message anyway.
          </label>
          <button class="btn-xs mt-2 border-teal-600 text-teal-700"
                  :disabled="sending || (optedOut && !confirmOptedOut)
                    || (picked.by_api && later && (!laterAt || isPast(laterAt)))" @click="send">
            <template v-if="sending">Sending…</template>
            <template v-else-if="picked.by_api && later">Schedule for {{ data.number }}</template>
            <template v-else>{{ picked.by_api ? 'Send by API' : 'Open in WhatsApp' }} to {{ data.number }}</template>
          </button>
        </div>

        <p v-if="result" class="mt-2 text-xs" :class="result.ok ? 'text-emerald-700' : 'text-rose-600'">
          {{ result.message }}
        </p>
      </template>

      <p v-else class="mt-2 text-xs text-slate-500">No tags yet. An admin creates them on Automation → Tags.</p>

      <ul v-if="data.history.length" class="mt-3 space-y-1.5">
        <li v-for="m in data.history" :key="m.id" class="text-[11px] leading-relaxed">
          <span class="font-medium text-slate-700">{{ m.template ?? 'A deleted tag' }}</span>
          <span class="text-slate-400">
            · {{ m.rule ? `rule “${m.rule}”` : (m.by ? `by ${m.by}` : '') }} · {{ when(m.at) }}
          </span>
          <span class="block" :class="m.status === 'failed' ? 'text-rose-600' : 'text-slate-500'">
            {{ m.outcome }}<template v-if="m.error"> — {{ m.error }}</template>
          </span>
          <button v-if="m.can_cancel" class="btn-xs mt-1 hover:border-rose-500 hover:text-rose-600"
                  :disabled="cancelling === m.id" @click="cancelScheduled(m)">
            {{ cancelling === m.id ? 'Cancelling…' : 'Cancel' }}
          </button>
          <details v-if="m.provider_response" class="text-slate-500">
            <summary class="cursor-pointer">11za's response</summary>
            <pre class="mt-1 whitespace-pre-wrap break-all rounded bg-slate-50 p-2 font-mono text-[10px]">{{ m.provider_response }}</pre>
          </details>
        </li>
      </ul>
    </template>
  </div>
</template>
