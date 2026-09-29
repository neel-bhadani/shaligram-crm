<script setup>
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'

/*
 | "Send WhatsApp" on the lead view.
 |
 | What can be sent depends on WhatsApp's 24-hour rule, and the server answers
 | it: until the customer has replied, only a Meta-approved template; for 24
 | hours after, free text as well. The panel shows whichever applies and how
 | long is left, and the template preview is the real message — filled in with
 | this lead's values on the server, the same fill the send uses.
 |
 | With the API off there is no rule to follow: the message opens in WhatsApp
 | for the user to send, which is the same click-to-send the Queue has always
 | done.
 */
const props = defineProps({
  leadId: { type: Number, required: true },
})
const emit = defineEmits(['sent'])

const info = ref(null)
const loadError = ref('')
const mode = ref('template')
const templateId = ref('')
const text = ref('')
const sending = ref(false)
const result = ref(null)

onMounted(async () => {
  try {
    const { data } = await axios.get(route('leads.whatsapp.show', props.leadId))
    info.value = data
    if (data.templates.length) { templateId.value = data.templates[0].id }
    if (!data.templates.length && !templateOnly.value) { mode.value = 'text' }
  } catch (e) {
    loadError.value = e.response?.data?.message ?? 'Could not load the WhatsApp options for this lead.'
  }
})

// free text is only refused when it would actually go through Meta
const templateOnly = computed(() => !!info.value && info.value.api_ready && !info.value.window.open)

const chosen = computed(() => info.value?.templates.find(t => t.id === templateId.value) ?? null)

const remaining = computed(() => {
  const closes = info.value?.window.closes_at
  if (!closes) { return '' }

  const minutes = Math.max(0, Math.round((new Date(closes) - Date.now()) / 60000))
  const hours = Math.floor(minutes / 60)

  return hours ? `${hours}h ${minutes % 60}m` : `${minutes}m`
})

const canSend = computed(() => {
  if (!info.value?.number || sending.value) { return false }

  return mode.value === 'template'
    ? !!chosen.value && !chosen.value.blank.length
    : !templateOnly.value && text.value.trim() !== ''
})

async function send() {
  result.value = null
  sending.value = true

  // with the API off the answer is a wa.me link, so the tab is claimed now,
  // inside the click — a window.open() after an await is a blocked pop-up
  const tabRef = info.value.api_ready ? null : window.open('', '_blank')

  try {
    const { data } = await axios.post(route('leads.whatsapp.send', props.leadId),
      mode.value === 'template' ? { whatsapp_template_id: templateId.value } : { text: text.value })

    result.value = data

    if (tabRef) {
      data.click_url ? (tabRef.location = data.click_url) : tabRef.close()
    }

    if (data.ok) {
      text.value = ''
      emit('sent')
    }
  } catch (e) {
    if (tabRef) { tabRef.close() }

    const errors = e.response?.data?.errors
    result.value = {
      ok: false,
      message: errors ? Object.values(errors)[0][0] : (e.response?.data?.message ?? 'Could not send the message.'),
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="mt-6 border-t border-slate-100 pt-5">
    <h4 class="text-xs font-semibold text-slate-500">Send WhatsApp</h4>

    <p v-if="loadError" class="mt-2 text-xs text-rose-600">{{ loadError }}</p>
    <p v-else-if="!info" class="mt-2 text-xs text-slate-400">Loading…</p>

    <template v-else>
      <p v-if="!info.number" class="warn-box mt-2 text-xs">
        This lead has no usable mobile number, so there is nobody to message.
      </p>

      <template v-else>
        <p class="mt-0.5 text-xs text-slate-400">
          To +{{ info.number }}
          <template v-if="!info.api_ready"> · API sending is off, so this opens in WhatsApp for you to send.</template>
          <template v-else-if="info.window.open"> · The customer replied — you can write freely for another {{ remaining }}.</template>
        </p>

        <div class="mt-3 flex gap-1.5">
          <button type="button" class="btn-xs" :class="mode === 'template' && 'border-teal-600 text-teal-700'"
                  @click="mode = 'template'">Template</button>
          <button type="button" class="btn-xs" :class="mode === 'text' && 'border-teal-600 text-teal-700'"
                  :disabled="templateOnly" @click="mode = 'text'">Free text</button>
        </div>

        <p v-if="templateOnly" class="mt-2 text-xs text-amber-700">{{ info.template_only }}</p>

        <div v-if="mode === 'template'" class="mt-3">
          <p v-if="!info.templates.length" class="text-xs text-slate-500">
            No approved template is ready to send. An admin can sync and map them on the Automation
            page, Templates tab.
          </p>
          <template v-else>
            <select v-model="templateId" class="w-full sm:!py-1.5 sm:text-xs" aria-label="WhatsApp template">
              <option v-for="t in info.templates" :key="t.id" :value="t.id">{{ t.label }}</option>
            </select>
            <div v-if="chosen" class="mt-2 rounded-xl bg-slate-100 p-2.5">
              <div class="whitespace-pre-wrap rounded-xl rounded-tl-sm bg-white px-3 py-2 text-xs
                          leading-relaxed text-slate-800 shadow-sm">{{ chosen.preview }}</div>
            </div>
            <p v-if="chosen?.blank.length" class="mt-1.5 text-xs text-amber-700">
              This lead has no {{ chosen.blank.join(', ') }}, and WhatsApp will not send a template with a blank in it.
            </p>
          </template>
        </div>

        <div v-else class="mt-3">
          <textarea v-model="text" rows="3" maxlength="4096" class="w-full text-sm"
                    :disabled="templateOnly" placeholder="Write a message…" />
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button type="button" class="btn-ghost sm:!py-1.5 sm:text-xs" :disabled="!canSend" @click="send">
            {{ sending ? 'Sending…' : (info.api_ready ? 'Send' : 'Open in WhatsApp') }}
          </button>

          <span v-if="result?.ok && result.status === 'sent'" class="text-xs text-emerald-700">Sent on WhatsApp.</span>
          <span v-else-if="result?.ok" class="text-xs text-teal-700">{{ result.message }}</span>
        </div>

        <div v-if="result && !result.ok" class="mt-2 text-xs text-rose-600">
          <p class="break-words">{{ result.message }}</p>
          <a v-if="result.click_url" :href="result.click_url" target="_blank" rel="noopener"
             class="mt-1 inline-block font-semibold text-teal-700 underline">Open in WhatsApp instead</a>
        </div>
      </template>
    </template>
  </div>
</template>
