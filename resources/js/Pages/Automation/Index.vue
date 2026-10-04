<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '../../Layouts/AppLayout.vue'
import AlertList from '../../Components/AlertList.vue'
import ConfirmDialog from '../../Components/ConfirmDialog.vue'
import FormField from '../../Components/FormField.vue'
import HelpTip from '../../Components/HelpTip.vue'
import RuleFormModal from '../../Components/RuleFormModal.vue'
import TemplateFormModal from '../../Components/TemplateFormModal.vue'
import { rulePhrase } from '../../lib/rulePhrase.js'
import { useFilterVisit } from '../../composables/useFilterVisit.js'

/*
 | The Automation page. Six tabs, one payload.
 |
 | Everything loads in one response rather than a request per tab. These are
 | small tables — a builder's office will have a dozen rules and a handful of
 | messages — and the alternative is five spinners on a page whose entire
 | purpose is letting somebody see how the pieces fit together.
 |
 | Tabs are switched in the browser, without a round trip, and the address bar
 | stays /automation. A tab parameter is still honoured on the way in, because
 | things link INTO a tab: the alert raised when loop protection holds a rule
 | back points at ?tab=activity, and that has to land on the activity log rather
 | than on the rules list. The server reads it, and it is wiped once the page
 | mounts — the same as a filter arriving on a link to any other list page.
 */
const props = defineProps({
  tab: String,
  autoSend: Array,
  otherAutomation: { type: Array, default: () => [] },
  rules: Array,
  templates: Array,
  providerTemplates: Object,
  queue: Array,
  needsChecking: { type: Array, default: () => [] },
  activity: Array,
  catalog: Object,
  whatsapp: Object,
  placeholders: Object,
  thresholds: Object,
  schedulerRunning: { type: [Boolean, null], default: null },
  // the alerts tab, from the same trait the /alerts page uses
  alerts: Object,
  filters: Object,
  counts: Object,
})

const TABS = [
  { key: 'auto_send', label: 'Auto-send' },
  { key: 'tags', label: 'Tags' },
  { key: 'rules', label: 'Rules' },
  { key: 'queue', label: 'Queue' },
  { key: 'alerts', label: 'Alerts' },
  { key: 'activity', label: 'Activity' },
]

const tab = ref(props.tab ?? 'auto_send')

/*
 | The Rules tab is not in the tab bar. The client sets up messages on
 | Auto-send and uses no other automation, and the builder only confused them.
 | It still opens from /automation?tab=rules, for whoever is troubleshooting.
 */
const visibleTabs = computed(() => TABS.filter(t => t.key !== 'rules' || props.tab === 'rules'))

// no visit of its own: this is here for the clean address bar on arrival
useFilterVisit(route('automation.index'))

const badge = key => ({
  auto_send: props.autoSend.filter(row => row.template_id).length,
  rules: props.rules.length,
  templates: props.templates.length,
  queue: props.queue.filter(m => m.status === 'queued').length + props.needsChecking.length,
  alerts: props.counts?.unread ?? 0,
  activity: 0,
}[key])

/* ================= rules ================= */

const ruleModal = ref(false)
const editingRule = ref(null)

const openRule = rule => {
  editingRule.value = rule
  ruleModal.value = true
}

const phrase = rule => rulePhrase(rule, props.catalog)

const activeRules = computed(() => props.rules.filter(r => r.is_active).length)

/*
 | THE ACTIVATION GUARD RAIL.
 |
 | Switching a rule on is the moment it stops being a draft and starts changing
 | the database, so the admin is shown how many leads it currently applies to
 | and asked to confirm. The count comes from the same endpoint the Test button
 | uses — nothing is fired to produce it.
 |
 | Switching a rule OFF needs no confirmation. Stopping automation is always
 | safe, and asking about it would train people to click through the dialog
 | that matters.
 */
const toggling = ref(null)          // the rule awaiting confirmation
const toggleCount = ref(null)
const toggleBusy = ref(false)

const askToggle = rule => {
  if (rule.is_active) {
    router.post(route('automation.rules.toggle', rule.id), { is_active: false }, { preserveScroll: true })
    return
  }

  toggling.value = rule
  toggleCount.value = null

  axios.post(route('automation.rules.match'), {
    trigger: rule.trigger,
    trigger_config: rule.trigger_config,
    conditions: rule.conditions,
  })
    .then(({ data }) => { toggleCount.value = data })
    .catch(() => { toggleCount.value = { count: null, summary: '' } })
}

const toggleMessage = computed(() => {
  if (!toggling.value) return ''
  if (toggleCount.value === null) return 'Checking how many leads this applies to…'

  const n = toggleCount.value.count
  const head = n === null
    ? 'Could not check how many leads this applies to.'
    : n === 0
      ? 'No lead matches this rule right now.'
      : `This applies to ${n} existing lead${n === 1 ? '' : 's'}.`

  return `${head} ${toggleCount.value.summary ?? ''}`.trim()
})

const confirmToggle = () => {
  toggleBusy.value = true

  router.post(route('automation.rules.toggle', toggling.value.id), { is_active: true }, {
    preserveScroll: true,
    onFinish: () => {
      toggleBusy.value = false
      toggling.value = null
    },
  })
}

/*
 | THE DELETION GUARD RAIL. A rule that has fired four hundred times is part of
 | the explanation for where four hundred leads ended up, so the dialog says so
 | rather than asking a generic "are you sure".
 */
const deletingRule = ref(null)

const deleteRuleMessage = computed(() => {
  const rule = deletingRule.value

  if (!rule) return ''

  return rule.fire_count > 0
    ? `“${rule.name}” has run ${rule.fire_count} time${rule.fire_count === 1 ? '' : 's'}. `
      + 'Deleting it does not undo anything it has already done, and the Activity tab keeps the '
      + 'record — but the rule\'s name will disappear from it. Delete anyway?'
    : `“${rule.name}” has never run. Deleting it changes nothing.`
})

const confirmDeleteRule = () => {
  router.delete(route('automation.rules.destroy', deletingRule.value.id), {
    preserveScroll: true,
    onFinish: () => { deletingRule.value = null },
  })
}

/* ================= auto-send ================= */

/*
 | One dropdown per stage. Changing it saves straight away — there is no Save
 | button to forget — and the server writes or updates the stage's rule.
 */
const activeTemplates = computed(() => props.templates.filter(t => t.is_active))
const savingSlot = ref(null)

// Other automation's only action. Off needs no confirmation: stopping is always safe
const switchOff = rule =>
  router.post(route('automation.rules.toggle', rule.id), { is_active: false }, { preserveScroll: true })

const setAutoSend = (row, value) => {
  savingSlot.value = row.key

  router.put(route('automation.auto_send.update', row.key), { template_id: value || null }, {
    preserveScroll: true,
    onFinish: () => { savingSlot.value = null },
  })
}

/* ================= templates ================= */

/*
 | 11za's template list. Read on demand — the first time the Tags tab is
 | opened with the API set up, and whenever Refresh is pressed — and kept on
 | the server, so the dropdown fills straight away next time. After a failed
 | read the tab does not ask again by itself: only Refresh does.
 */
const providerList = ref(props.providerTemplates ?? { templates: [], total: 0, at: null, failed: false })
const providerTruncated = computed(() => providerList.value.total > providerList.value.templates.length)
const providerResult = ref(null)
const loadingProvider = ref(false)

const refreshProvider = () => {
  loadingProvider.value = true

  axios.post(route('automation.templates.provider'))
    .then(({ data }) => {
      providerResult.value = data
      providerList.value = {
        templates: data.templates ?? [],
        total: data.total ?? 0,
        at: data.at ?? providerList.value.at,
        page_size: data.page_size ?? providerList.value.page_size,
        failed: data.failed ?? !data.ok,
      }
      if (data.ok) router.reload({ only: ['templates'] })
    })
    .catch(error => {
      providerList.value = { ...providerList.value, failed: true }
      providerResult.value = {
        ok: false,
        message: error.response?.data?.message ?? 'The request did not reach the server. Type the template name and language instead.',
      }
    })
    .finally(() => { loadingProvider.value = false })
}

watch(tab, key => {
  if (key === 'tags' && props.whatsapp.configured && !providerList.value.at
      && !providerList.value.failed && !providerResult.value) {
    refreshProvider()
  }
}, { immediate: true })

const templateModal = ref(false)
const editingTemplate = ref(null)
const deletingTemplate = ref(null)

const openTemplate = template => {
  editingTemplate.value = template
  templateModal.value = true
}

const toggleTemplate = template =>
  router.post(route('automation.templates.toggle', template.id),
    { is_active: !template.is_active }, { preserveScroll: true })

const confirmDeleteTemplate = () => {
  router.delete(route('automation.templates.destroy', deletingTemplate.value.id), {
    preserveScroll: true,
    onFinish: () => { deletingTemplate.value = null },
  })
}

/*
 | "{{1}}" — built here rather than in the markup because Vue's template parser
 | reads a literal "{{" inside an interpolation as the start of another one.
 */
const slotLabel = i => `{{${i + 1}}}`

/* ================= queue ================= */

const queued = computed(() => props.queue.filter(m => m.status === 'queued'))
const history = computed(() => props.queue.filter(m => m.status !== 'queued'))

/*
 | "Did Rahul get the site visit message?" — a filter over the log by name,
 | number, message or rule. Client-side: it is the recent past, already here.
 */
const logSearch = ref('')

const filteredHistory = computed(() => {
  const term = logSearch.value.trim().toLowerCase()

  if (!term) return history.value

  return history.value.filter(m =>
    [m.to_name, m.lead?.name, m.to_number, m.template, m.rule, m.provider_message_id]
      .some(v => (v ?? '').toString().toLowerCase().includes(term)))
})

/*
 | Open the message in WhatsApp.
 |
 | The tab is opened from inside the click handler, before the request goes out.
 | A window.open() that waits for a promise is a pop-up as far as every browser
 | is concerned and gets blocked — so the tab is claimed first and pointed at
 | the URL when it arrives.
 */
const openInWhatsApp = message => {
  const tabRef = window.open('', '_blank')

  axios.post(route('automation.messages.open', message.id))
    .then(({ data }) => {
      if (tabRef) tabRef.location = data.url
      // refresh so the row moves from Queued to Opened
      router.reload({ only: ['queue'] })
    })
    .catch(() => {
      if (tabRef) tabRef.close()
      router.reload({ only: ['queue'] })
    })
}

/*
 | Settling a message whose outcome is unknown. Both answers come only after
 | looking it up in 11za's own message log — the buttons say so, and the
 | dialog says where to look, because a guess here either marks a customer
 | contacted who never was or messages them twice.
 */
const settling = ref(null)   // { message, answer: 'delivered' | 'not_sent' }

const settleTitle = computed(() => settling.value?.answer === 'delivered'
  ? 'You found it in the 11za message log?'
  : 'You checked, and it is not in the 11za message log?')

const settleMessage = computed(() => {
  const m = settling.value?.message
  if (!m) return ''

  const where = `Look in the 11za panel's message log for ${m.to_number}, around ${when(m.handed_at)}.`

  return settling.value.answer === 'delivered'
    ? `${where} Only confirm if you can see it there — this records the message as sent, under your name.`
    : `${where} If it is not there, it is sent again now. If it did arrive after all, the customer gets it twice.`
})

const confirmSettle = () => {
  const { message, answer } = settling.value
  const name = answer === 'delivered' ? 'automation.messages.checked-delivered' : 'automation.messages.checked-not-sent'

  router.post(route(name, message.id), {}, {
    preserveScroll: true,
    onFinish: () => { settling.value = null },
  })
}

const sendByApi = message =>
  router.post(route('automation.messages.send', message.id), {}, { preserveScroll: true })

const cancelMessage = message =>
  router.post(route('automation.messages.cancel', message.id), {}, { preserveScroll: true })

const statusChip = status => ({
  queued: 'border-amber-200 bg-amber-50 text-amber-800',
  opened: 'border-teal-200 bg-teal-50 text-teal-700',
  sending: 'border-sky-200 bg-sky-50 text-sky-700',
  unknown: 'border-slate-300 bg-white text-slate-600',
  sent: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  failed: 'border-rose-200 bg-rose-50 text-rose-700',
  skipped: 'border-slate-200 bg-slate-50 text-slate-500',
  cancelled: 'border-slate-200 bg-slate-50 text-slate-500',
}[status] ?? 'border-slate-200 bg-slate-50 text-slate-500')

/* the WhatsApp API settings form */
const waForm = useForm({
  auth_token: '',
  origin_website: props.whatsapp.origin_website ?? '',
  base_url: props.whatsapp.base_url ?? '',
  api_enabled: props.whatsapp.api_enabled ?? false,
  auto_send: props.whatsapp.auto_send ?? false,
})

const saveWhatsApp = () => waForm.put(route('automation.whatsapp.update'), {
  preserveScroll: true,
  onSuccess: () => {
    waForm.auth_token = ''
    // what was just saved is the new baseline for "unsaved changes"
    waForm.defaults()
  },
})

/*
 | Test connection. 11za has no way to check credentials without sending, so
 | this sends one real message to the number typed here. Always ends with
 | something on screen: 11za's raw answer, the server's refusal, or the fact
 | that the request itself never came back. A button that goes quiet on
 | failure is how a bad token hid for a week.
 */
const testing = ref(false)
const testResult = ref(props.whatsapp.last_test ?? null)
const testMobile = ref('')
const testTemplateId = ref('')

// only messages with an 11za template name can be sent as the test
const testableTemplates = computed(() => props.templates.filter(t => t.provider_template_name))

const testConnection = () => {
  testing.value = true

  axios.post(route('automation.whatsapp.test'), { mobile: testMobile.value, template_id: testTemplateId.value || null })
    .then(({ data }) => { testResult.value = data })
    .catch(error => {
      const data = error.response?.data
      testResult.value = {
        ok: false,
        message: data?.message
          ?? (error.response
            ? `The server answered ${error.response.status} and no reason. Nothing was tested.`
            : 'The request did not reach the server. Check your connection and try again.'),
      }
    })
    .finally(() => { testing.value = false })
}

/* ================= activity ================= */

const resultChip = result => ({
  fired: 'border-teal-200 bg-teal-50 text-teal-700',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  skipped: 'border-slate-200 bg-slate-50 text-slate-500',
  failed: 'border-rose-200 bg-rose-50 text-rose-700',
  loop_guard: 'border-amber-200 bg-amber-50 text-amber-800',
  cooldown: 'border-amber-200 bg-amber-50 text-amber-800',
}[result] ?? 'border-slate-200 bg-slate-50 text-slate-500')

const actionWord = action => ({
  rule_fired: 'Rule matched',
  rule_failed: 'Rule failed',
  suppressed: 'Held back',
}[action] ?? (props.catalog.actions?.[action]?.label ?? action))

const when = iso => iso
  ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
  : '—'

const whenShort = iso => iso
  ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
  : 'never'
</script>

<template>
  <AppLayout title="Automation" subtitle="Rules that do the routine work for you.">
    <template #actions>
      <button v-if="tab === 'rules'" class="btn" @click="openRule(null)">New rule</button>
      <button v-if="tab === 'tags'" class="btn" @click="openTemplate(null)">New tag</button>
    </template>

    <!--
      The scheduler warning. Every built-in alert, and any time-based rule,
      depends on a cron job this application cannot start for itself. When it
      is not running nothing errors — the alerts simply never appear, which is
      the worst kind of broken. At the top of the page, not on a tab, so it
      cannot be missed.
    -->
    <div v-if="schedulerRunning === false" class="warn-box mb-4">
      <strong>The hourly task has not run recently.</strong>
      The automatic alerts — overdue follow-ups, leads stuck in a stage — will not be raised.
      Messages sent when a lead reaches a stage are unaffected. Ask whoever set up the server to
      check that Laravel's scheduler is running.
    </div>

    <!-- ---------------- tabs ---------------- -->
    <div class="mb-5 flex flex-wrap gap-1 border-b border-slate-200">
      <button
        v-for="t in visibleTabs" :key="t.key"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition"
        :class="tab === t.key
          ? 'border-teal-700 text-teal-800'
          : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="tab = t.key"
      >
        {{ t.label }}
        <span
          v-if="badge(t.key)"
          class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
        >{{ badge(t.key) }}</span>
      </button>
    </div>

    <!-- ================= RULES ================= -->
    <div v-show="tab === 'rules'">

      <p class="info-box mb-4">
        <strong>For troubleshooting.</strong> This tab is not in the menu. WhatsApp messages are set
        up on <button class="underline" @click="tab = 'auto_send'">Auto-send</button>; rules marked
        “Auto-send” are its stage rows, and changing them here changes that row.
      </p>


      <!-- ---------------- empty state that teaches ---------------- -->
      <div v-if="!rules.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800">You have no rules yet</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500">
          A rule watches for one thing happening — a lead arriving, a stage changing, a follow-up
          going overdue — and then does something about it, like giving the lead to a telecaller
          or booking a call for tomorrow.
        </p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500">
          Every rule is written in plain words as you build it, and you can test it against your
          real leads before switching it on. Nothing runs until you say so.
        </p>
        <div class="mt-5 flex flex-wrap justify-center gap-2">
          <button class="btn" @click="openRule(null)">Create your first rule</button>
          <Link :href="route('automation.guide')" class="btn-ghost">Read how it works</Link>
        </div>
      </div>

      <template v-else>
        <p class="mb-3 text-xs text-slate-400">
          {{ activeRules }} of {{ rules.length }} switched on.
          Rules you have not switched on do nothing at all.
        </p>

        <div class="space-y-3">
          <div v-for="rule in rules" :key="rule.id" class="card p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="text-sm font-semibold text-slate-900">{{ rule.name }}</h3>
                  <span
                    class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                    :class="rule.is_active
                      ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                      : 'border-slate-200 bg-slate-50 text-slate-500'"
                  >{{ rule.is_active ? 'On' : 'Off' }}</span>
                  <span
                    v-if="rule.on_auto_send"
                    class="rounded border border-teal-200 bg-teal-50 px-1.5 py-0.5 text-[10px]
                           font-semibold uppercase tracking-wide text-teal-700"
                  >Auto-send</span>
                  <span
                    v-if="rule.is_time_based"
                    class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px]
                           font-semibold uppercase tracking-wide text-slate-500"
                  >Hourly</span>
                </div>

                <!-- the same sentence the builder shows, from the same function -->
                <p class="mt-1.5 text-sm leading-relaxed text-slate-700">{{ phrase(rule) }}</p>

                <p v-if="rule.description" class="mt-1.5 text-xs leading-relaxed text-slate-500">
                  {{ rule.description }}
                </p>

                <p class="mt-2 text-[11px] text-slate-400">
                  Run {{ rule.fire_count }} time{{ rule.fire_count === 1 ? '' : 's' }} ·
                  last {{ whenShort(rule.last_fired_at) }}
                  <template v-if="rule.created_by"> · written by {{ rule.created_by }}</template>
                </p>

                <p v-if="rule.could_loop" class="warn-box mt-2">
                  This rule changes a stage and also watches for stage changes, so it can set off
                  another rule. Automation stops itself after
                  {{ catalog.loop.max_touches_per_chain }} changes in a row and tells the admins.
                </p>
              </div>

              <div class="flex flex-none flex-wrap gap-1.5">
                <button class="btn-xs" @click="openRule(rule)">Edit</button>
                <button
                  class="btn-xs"
                  :class="rule.is_active ? '' : 'border-teal-600 text-teal-700'"
                  @click="askToggle(rule)"
                >{{ rule.is_active ? 'Switch off' : 'Switch on' }}</button>
                <button class="btn-xs hover:border-rose-500 hover:text-rose-600"
                        @click="deletingRule = rule">Delete</button>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ================= AUTO-SEND ================= -->
    <div v-show="tab === 'auto_send'">
      <p class="mb-4 max-w-3xl text-sm leading-relaxed text-slate-500">
        Choose a tag for any stage, and every lead that reaches it is sent that tag. Choose None
        to stop. Changes save straight away.
      </p>

      <p v-if="!activeTemplates.length" class="warn-box mb-4">
        There are no tags to choose yet.
        <button class="underline" @click="tab = 'tags'">Set one up on the Tags tab</button>.
      </p>

      <div class="card overflow-hidden">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-100 text-[10px] uppercase tracking-wide text-slate-400">
            <tr>
              <th class="px-4 py-2.5 font-semibold">When a lead reaches…</th>
              <th class="px-4 py-2.5 font-semibold">Send</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in autoSend" :key="row.key" class="border-b border-slate-50 align-top last:border-0">
              <td class="px-4 py-3 font-medium text-slate-700">{{ row.label }}</td>
              <td class="px-4 py-3">
                <select class="w-full max-w-xs" :value="row.template_id ?? ''"
                        :disabled="savingSlot === row.key"
                        @change="setAutoSend(row, $event.target.value)">
                  <option value="">None</option>
                  <option v-for="t in activeTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                  <!-- a tag switched off since it was chosen: shown, not silently swapped -->
                  <option v-if="row.template_id && !activeTemplates.some(t => t.id === row.template_id)"
                          :value="row.template_id">
                    {{ templates.find(t => t.id === row.template_id)?.name ?? 'A deleted tag' }} (switched off)
                  </option>
                </select>
                <p v-for="other in row.others" :key="other.id" class="mt-1.5 text-[11px] text-slate-500">
                  Also {{ other.is_active ? '' : '(switched off) ' }}sent by the rule “{{ other.name }}” —
                  see Other automation below.
                </p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="mt-3 text-[11px] text-slate-400">
        Messages go by API through 11za when API sending is on (Queue tab). When it is off, they wait
        in the Queue for somebody to open in WhatsApp.
      </p>

      <!--
        OTHER AUTOMATION. Every rule a row above does not show — conditions,
        other actions, time-based. Only there when one exists. Switch off is
        the only thing offered: it cannot create or change anything, and it
        means an admin who finds a rule they do not want can stop it without
        calling a developer.
      -->
      <div v-if="otherAutomation.length" class="mt-6">
        <h3 class="text-sm font-semibold text-slate-900">Other automation</h3>
        <p class="mt-1 max-w-3xl text-xs leading-relaxed text-slate-500">
          These were set up before this screen, or by a developer, and run on their own. You can
          switch one off here; to change or remove one, ask your developer.
        </p>

        <div class="card mt-3 divide-y divide-slate-100">
          <div v-for="rule in otherAutomation" :key="rule.id" class="flex flex-wrap items-start gap-3 px-4 py-3">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-slate-800">{{ rule.name }}</span>
                <span
                  class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                  :class="rule.is_active
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-slate-200 bg-slate-50 text-slate-500'"
                >{{ rule.is_active ? 'On' : 'Off' }}</span>
              </div>
              <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ phrase(rule) }}</p>
              <p class="mt-1 text-[11px] text-slate-400">
                Run {{ rule.fire_count }} time{{ rule.fire_count === 1 ? '' : 's' }} ·
                last {{ whenShort(rule.last_fired_at) }}
              </p>
            </div>
            <button v-if="rule.is_active" class="btn-xs flex-none" @click="switchOff(rule)">Switch off</button>
          </div>
        </div>
      </div>

      <p class="mt-6 text-[11px] text-slate-400">
        Each stage above is an automation rule underneath. Developers can see them all at
        /automation?tab=rules.
      </p>
    </div>

    <!-- ================= TAGS ================= -->
    <div v-show="tab === 'tags'">
      <div class="info-box mb-4 flex items-start gap-2">
        <span class="flex-1">
          <strong>The wording lives in 11za.</strong>
          A tag is a name you pick, pointing at an 11za template and what fills each of its
          gaps. Once it's set up, you only ever pick the tag.
        </span>
        <button v-if="whatsapp.configured" class="btn-xs flex-none" :disabled="loadingProvider" @click="refreshProvider">
          {{ loadingProvider ? 'Reading 11za…' : 'Refresh from 11za' }}
        </button>
      </div>

      <!--
        Whether the template dropdown can fill itself. When it cannot, the name
        is typed — and 11za's raw answer is shown so the reason is not a guess.
      -->
      <div v-if="!whatsapp.configured" class="warn-box mb-4">
        The template list is read from 11za once the auth token and origin website are saved (Queue
        tab). Until then, type each template's name and language.
      </div>
      <div v-else-if="providerResult && !providerResult.ok" class="warn-box mb-4 break-words">
        {{ providerResult.message }}
        <pre v-if="providerResult.raw" class="mt-1.5 whitespace-pre-wrap break-all rounded bg-white/60 p-2 font-mono text-[10px]">11za said: {{ providerResult.raw }}</pre>
      </div>
      <p v-else-if="providerList.failed" class="warn-box mb-4">
        The last read from 11za failed. Press Refresh from 11za to try again.
      </p>
      <p v-if="providerTruncated" class="warn-box mb-4">
        Showing the first {{ providerList.templates.length }} of {{ providerList.total }} templates from 11za.
        Type the name for any other.
      </p>
      <p v-else-if="providerList.at && !providerList.failed && !(providerResult && !providerResult.ok)"
         class="mb-3 text-[11px] text-slate-400">
        {{ providerList.templates.length }} template{{ providerList.templates.length === 1 ? '' : 's' }}
        in 11za, read {{ when(providerList.at) }}<template v-if="providerList.page_size">
        with page size {{ providerList.page_size }}</template>.
      </p>
      <p v-if="providerResult?.ok" class="mb-3 text-[11px] text-slate-500">{{ providerResult.message }}</p>

      <div v-if="!templates.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800">No tags yet</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500">
          Pick a template from your 11za account and say what goes into each of its gaps. Then
          pick the tag on a lead or on Auto-send.
        </p>
        <button class="btn mt-5" @click="openTemplate(null)">Create your first tag</button>
      </div>

      <div v-else class="grid gap-3 lg:grid-cols-2">
        <div v-for="template in templates" :key="template.id" class="card flex flex-col p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-900">{{ template.name }}</h3>
                <span
                  v-if="!template.is_active"
                  class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px]
                         font-semibold uppercase tracking-wide text-slate-500"
                >Off</span>
              </div>
              <p class="mt-1 text-[11px]" :class="template.api_unsendable ? 'text-amber-700' : 'text-slate-500'">
                {{ template.api_unsendable ?? `11za: ${template.provider_template}` }}
              </p>
            </div>

            <div class="flex flex-none flex-wrap gap-1.5">
              <button class="btn-xs" @click="openTemplate(template)">Edit</button>
              <button class="btn-xs" @click="toggleTemplate(template)">
                {{ template.is_active ? 'Switch off' : 'Switch on' }}
              </button>
              <button class="btn-xs hover:border-rose-500 hover:text-rose-600"
                      @click="deletingTemplate = template">Delete</button>
            </div>
          </div>

          <ul class="mt-3 flex-1 space-y-0.5 text-xs text-slate-600">
            <li v-if="!template.placeholder_map.length" class="text-slate-400">No variables.</li>
            <li v-for="(field, i) in template.placeholder_map" :key="i">
              <span class="font-mono text-slate-400">{{ slotLabel(i) }}</span>
              {{ placeholders[field]?.label ?? field }}
            </li>
          </ul>

          <details v-if="template.provider_body" class="mt-2 text-[11px] text-slate-500">
            <summary class="cursor-pointer">The template in 11za</summary>
            <div class="mt-1 whitespace-pre-wrap rounded bg-slate-50 p-2 leading-relaxed">{{ template.provider_body }}</div>
          </details>
          <details v-if="template.old_body" class="mt-2 text-[11px] text-slate-500">
            <summary class="cursor-pointer">Old wording, only used when sending by hand</summary>
            <div class="mt-1 whitespace-pre-wrap rounded bg-slate-50 p-2 leading-relaxed">{{ template.old_body }}</div>
            <p v-if="!template.old_body_in_use" class="mt-1 text-slate-400">Not used: 11za's own wording is known.</p>
          </details>

          <p class="mt-2 text-[11px] text-slate-400">
            Used {{ template.messages_count }} time{{ template.messages_count === 1 ? '' : 's' }}
          </p>
        </div>
      </div>
    </div>

    <!-- ================= QUEUE ================= -->
    <div v-show="tab === 'queue'">

      <div class="info-box mb-4 flex items-start gap-2">
        <span class="flex-1">
          <template v-if="!whatsapp.api_enabled">
            <strong>Nothing here is sent automatically.</strong>
            A rule writes the message and puts it in this list. You open it in WhatsApp, check it,
            and press send yourself.
          </template>
          <template v-else-if="!whatsapp.auto_send">
            <strong>API sending is on; automatic sending is off.</strong>
            Messages a rule sends by API wait here until you press Send by API.
          </template>
          <template v-else>
            <strong>API messages are sent automatically.</strong>
            A rule's API message goes to the send worker straight away; the log below shows what
            11za said. Click-to-send messages still wait here for you.
          </template>
        </span>
        <HelpTip title="What “sent” means here" align="right">
          “Accepted by 11za” means 11za took the message and gave it an id. Whether it was then
          delivered or read is not recorded — that needs a connection back from 11za this CRM
          does not have yet.
          <br><br>
          API messages are sent by the background worker that runs every minute, so allow a
          minute or two.
        </HelpTip>
      </div>

      <!--
        OUTCOME UNKNOWN. Every one, however old, above everything else: each
        is a customer who may or may not have heard from us.
      -->
      <div v-if="needsChecking.length" class="mb-6">
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
          Outcome unknown — check 11za ({{ needsChecking.length }})
        </h3>
        <div class="space-y-2">
          <div v-for="message in needsChecking" :key="message.id" class="card border-slate-300 p-4">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-semibold text-slate-900">{{ message.to_name ?? message.lead?.name ?? 'Deleted lead' }}</span>
              <span class="text-xs text-slate-500">{{ message.to_number }}</span>
              <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                    :class="statusChip('unknown')">Outcome unknown</span>
            </div>
            <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
              Handed to 11za {{ when(message.handed_at) }}, then the send was interrupted before 11za
              answered. It may or may not have reached the customer.
              <strong>Look in the 11za panel's message log for {{ message.to_number }} around
              {{ when(message.handed_at) }}</strong>, then say what you found.
            </p>
            <p class="mt-1 text-[11px] text-slate-400">
              {{ message.template ? `Tag: ${message.template}` : 'No tag' }}
              <template v-if="message.rule"> · rule “{{ message.rule }}”</template>
              <template v-else-if="message.user"> · by {{ message.user }}</template>
            </p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button class="btn-xs" @click="settling = { message, answer: 'delivered' }">
                I checked 11za — it delivered
              </button>
              <button class="btn-xs" @click="settling = { message, answer: 'not_sent' }">
                I checked 11za — it never went
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!queue.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800">Nothing waiting to be sent</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500">
          When a lead reaches a stage set up on Auto-send, the message appears here with the customer's
          details already filled in, along with what happened to it.
        </p>
        <button class="btn-ghost mt-5" @click="tab = 'auto_send'">Set up Auto-send</button>
      </div>

      <template v-else>
        <h3 v-if="queued.length" class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
          Waiting to be sent ({{ queued.length }})
        </h3>

        <div class="space-y-2">
          <div v-for="message in queued" :key="message.id" class="card p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-sm font-semibold text-slate-900">{{ message.to_name ?? message.lead?.name ?? 'Deleted lead' }}</span>
                  <span class="text-xs text-slate-400">{{ message.to_number }}</span>
                  <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase
                               tracking-wide" :class="statusChip(message.status)">{{ message.outcome }}</span>
                  <span v-if="message.lead?.opted_out"
                        class="rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold
                               uppercase tracking-wide text-rose-700">Opted out</span>
                </div>
                <div class="mt-2 whitespace-pre-wrap rounded-xl rounded-tl-sm bg-slate-50 px-3 py-2
                            text-xs leading-relaxed text-slate-700">{{ message.body }}</div>
                <p class="mt-1.5 text-[11px] text-slate-400">
                  {{ message.template ? `Tag: ${message.template}` : 'No tag' }}
                  <template v-if="message.rule"> · queued by “{{ message.rule }}”</template>
                  · {{ when(message.created_at) }}
                </p>
              </div>

              <div class="flex flex-none flex-wrap gap-1.5">
                <button class="btn-xs border-teal-600 text-teal-700" @click="openInWhatsApp(message)">
                  Open in WhatsApp
                </button>
                <button v-if="!whatsapp.configured || (whatsapp.api_enabled && message.api_ready)"
                        class="btn-xs" @click="sendByApi(message)">Send by API</button>
                <button class="btn-xs hover:border-rose-500 hover:text-rose-600"
                        @click="cancelMessage(message)">Cancel</button>
              </div>
            </div>
          </div>
        </div>

        <template v-if="history.length">
          <div class="mb-2 mt-6 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Message log</h3>
            <input v-model="logSearch" type="search" class="w-64 text-xs"
                   placeholder="Search name, number, tag, rule…" />
          </div>
          <div class="card divide-y divide-slate-100">
            <div v-if="!filteredHistory.length" class="px-4 py-3 text-xs text-slate-400">
              Nothing in the recent log matches “{{ logSearch }}”.
            </div>
            <div v-for="message in filteredHistory" :key="message.id" class="flex flex-wrap items-center gap-2 px-4 py-2.5">
              <!-- unconfirmed is neutral, not green and not red: 11za said yes,
                   only the id lookup came up empty -->
              <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                    :class="message.unconfirmed ? 'border-slate-300 bg-white text-slate-600' : statusChip(message.status)"
              >{{ message.unconfirmed ? 'Sent (unconfirmed)' : message.status }}</span>
              <span class="text-xs font-medium text-slate-700">{{ message.to_name ?? message.lead?.name ?? 'Deleted lead' }}</span>
              <span class="text-[11px] text-slate-400">{{ message.to_number ?? 'no number' }}</span>
              <span class="text-[11px] text-slate-400">
                {{ message.template ? `Tag: ${message.template}` : 'No tag' }}
                <template v-if="message.rule"> · rule “{{ message.rule }}”</template>
                <template v-else-if="message.user"> · by {{ message.user }}</template>
                · {{ when(message.sent_at ?? message.created_at) }}
              </span>
              <span class="w-full text-[11px] text-slate-600">{{ message.outcome }}</span>
              <span v-if="message.error" class="w-full break-words text-[11px] leading-relaxed text-rose-600">
                {{ message.error }}
              </span>
              <details v-if="message.provider_response" class="w-full text-[11px] text-slate-500"
                       :open="message.unconfirmed">
                <summary class="cursor-pointer">11za's response{{ message.provider_template ? ` · sent as ${message.provider_template}` : '' }}</summary>
                <pre class="mt-1 whitespace-pre-wrap break-all rounded bg-slate-50 p-2 font-mono text-[10px]">{{ message.provider_response }}</pre>
              </details>
            </div>
          </div>
        </template>
      </template>

      <!-- ---------------- API settings ---------------- -->
      <div class="card mt-6 p-4">
        <div class="mb-1 flex items-center gap-1.5">
          <h3 class="text-sm font-semibold text-slate-900">Sending by API</h3>
          <HelpTip title="Click-to-send vs the API" align="right">
            <strong>Click-to-send</strong> works today and costs nothing. It opens WhatsApp with
            the message already typed and you press send. Because we hand it to WhatsApp, we can
            only record that it was <em>opened</em> — not whether you sent it.
            <br><br>
            <strong>API sending</strong> sends without anybody opening anything, and records
            whether 11za accepted it (not whether it was delivered or read). It goes through your
            11za WhatsApp Business API account, using templates set up in the 11za panel.
          </HelpTip>
        </div>

        <p v-if="!whatsapp.configured" class="warn-box mt-2">{{ whatsapp.not_configured }}</p>
        <p v-else class="info-box mt-2">
          Credentials are saved. API sending is
          <strong>{{ whatsapp.api_enabled ? 'ON' : 'off' }}</strong>; automatic sending is
          <strong>{{ whatsapp.auto_send ? 'ON' : 'off' }}</strong>.
          Sending depends on the background worker running every minute.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
          <FormField label="11za auth token" :error="waForm.errors.auth_token"
                     :hint="whatsapp.auth_token_tail
                       ? `A token ending ${whatsapp.auth_token_tail} is saved. Leave empty to keep it.`
                       : 'From the 11za panel. Stored encrypted; never shown again after saving.'">
            <input v-model="waForm.auth_token" type="password" class="w-full"
                   autocomplete="new-password" placeholder="••••••••" />
          </FormField>

          <FormField label="Origin website" :error="waForm.errors.origin_website"
                     hint="The website registered with your 11za account.">
            <input v-model="waForm.origin_website" type="text" class="w-full" />
          </FormField>

          <FormField label="Base URL" :error="waForm.errors.base_url"
                     :hint="`Leave empty for ${whatsapp.default_base_url}. Only 11za addresses are accepted.`">
            <input v-model="waForm.base_url" type="url" class="w-full" :placeholder="whatsapp.default_base_url" />
          </FormField>
        </div>

        <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
          <input v-model="waForm.api_enabled" type="checkbox" class="mt-0.5 h-4 w-4"
                 :disabled="!whatsapp.configured" />
          <span>
            Use API sending
            <span class="block text-xs text-slate-400">
              Off: every WhatsApp message is click-to-send. On: rules set to “By API”, and messages
              sent from a lead, go through 11za — using the 11za template behind each tag.
            </span>
          </span>
        </label>

        <label class="mt-3 flex items-start gap-2 text-sm text-slate-700">
          <input v-model="waForm.auto_send" type="checkbox" class="mt-0.5 h-4 w-4"
                 :disabled="!whatsapp.configured || !waForm.api_enabled" />
          <span>
            Send queued messages automatically
            <span class="block text-xs text-slate-400">
              Off: a rule's API message waits in this Queue for somebody to press Send by API.
              On: it is sent straight away. Leave it off if you want to read every message first.
            </span>
          </span>
        </label>

        <p v-if="waForm.isDirty" class="warn-box mt-4">
          You have unsaved changes. Test connection checks the <strong>saved</strong> settings,
          not what is typed here — save first.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <button class="btn" :disabled="waForm.processing" @click="saveWhatsApp">
            {{ waForm.processing ? 'Saving…' : 'Save WhatsApp settings' }}
          </button>
        </div>

        <div class="mt-5 border-t border-slate-100 pt-4">
          <h4 class="text-xs font-semibold text-slate-500">Test connection</h4>
          <p class="mt-1 text-xs text-slate-400">
            11za has no way to check the settings without sending, so this
            <strong>sends one real WhatsApp message</strong> to the number you type, using the saved
            settings and the sample values (“Rahul Mehta”, “Skyline Residency”). Use your own phone.
          </p>
          <div class="mt-3 flex flex-wrap items-end gap-2">
            <FormField label="Send to mobile">
              <input v-model="testMobile" type="tel" class="w-44" placeholder="98765 43210" />
            </FormField>
            <FormField label="Tag">
              <select v-model="testTemplateId" class="w-64">
                <option value="">{{ testableTemplates.length ? 'Choose a tag…' : 'No tag has an 11za template yet' }}</option>
                <option v-for="t in testableTemplates" :key="t.id" :value="t.id">{{ t.name }} · {{ t.provider_template }}</option>
              </select>
            </FormField>
            <button class="btn-ghost" :disabled="testing" @click="testConnection">
              {{ testing ? 'Sending…' : 'Send test message' }}
            </button>
          </div>
        </div>

        <div v-if="testResult" class="mt-3 break-words text-xs leading-relaxed"
             :class="testResult.ok ? 'info-box' : 'warn-box'">
          {{ testResult.message }}
          <pre v-if="testResult.response" class="mt-1.5 whitespace-pre-wrap break-all rounded bg-white/60 p-2 font-mono text-[10px]">11za said: {{ testResult.response }}</pre>
          <span v-if="testResult.at" class="block text-[11px] text-slate-400">Tested {{ when(testResult.at) }}</span>
        </div>
      </div>
    </div>

    <!-- ================= ALERTS ================= -->
    <div v-show="tab === 'alerts'">
      <p class="mb-4 max-w-3xl text-sm leading-relaxed text-slate-500">
        Your own alerts. Everyone has their own — a telecaller is told about their overdue
        follow-ups, you are told when automation holds itself back. Nobody is ever alerted about a
        lead they are not allowed to see.
      </p>

      <AlertList
        :alerts="alerts" :filters="filters" :counts="counts" :thresholds="thresholds"
        :paginate="false"
        route-name="automation.index" :route-params="{ tab: 'alerts' }"
      />

      <div class="card mt-5 p-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Alerts you get without any rule</h3>
        <ul class="space-y-1.5 text-xs leading-relaxed text-slate-600">
          <li>· A follow-up more than <strong>{{ thresholds.overdue_days }} days</strong> overdue —
            told to whoever it belongs to.</li>
          <li>· A lead sitting in one stage for more than <strong>{{ thresholds.stuck_days }} days</strong>
            — told to whoever it belongs to.</li>
          <li>· Somebody switched off who still holds open leads — told to all admins.</li>
          <li>· Automation holding a rule back to stop a loop — told to all admins.</li>
        </ul>
        <p class="mt-3 text-[11px] text-slate-400">
          The same alert is never repeated for the same lead and person within
          {{ thresholds.dedupe_hours }} hours, so the bell stays worth looking at.
        </p>
      </div>
    </div>

    <!-- ================= ACTIVITY ================= -->
    <div v-show="tab === 'activity'">
      <p class="mb-4 max-w-3xl text-sm leading-relaxed text-slate-500">
        Everything automation has done, newest first. This is where to look when a lead turns up
        somewhere unexpected — or when a rule is switched on and nothing seems to be happening.
      </p>

      <div v-if="!activity.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800">Nothing has run yet</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500">
          Once you switch a rule on, every single thing it does will be listed here — what it did,
          to which lead, and whether it worked. Including the times it decided not to.
        </p>
      </div>

      <div v-else class="card overflow-hidden">
        <!-- table on desktop, cards on mobile: the same pattern as the other pages -->
        <table class="hidden w-full text-left text-xs lg:table">
          <thead class="border-b border-slate-100 text-[10px] uppercase tracking-wide text-slate-400">
            <tr>
              <th class="px-4 py-2.5 font-semibold">When</th>
              <th class="px-4 py-2.5 font-semibold">Rule</th>
              <th class="px-4 py-2.5 font-semibold">Lead</th>
              <th class="px-4 py-2.5 font-semibold">What</th>
              <th class="px-4 py-2.5 font-semibold">Result</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in activity" :key="log.id" class="border-b border-slate-50 last:border-0"
                :class="log.bad ? 'bg-amber-50/40' : ''">
              <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">{{ when(log.fired_at) }}</td>
              <td class="px-4 py-2.5 font-medium text-slate-700">{{ log.rule }}</td>
              <td class="px-4 py-2.5 text-slate-500">{{ log.lead ?? '—' }}</td>
              <td class="px-4 py-2.5 text-slate-600">{{ actionWord(log.action) }}</td>
              <td class="px-4 py-2.5">
                <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase
                             tracking-wide" :class="resultChip(log.result)">
                  {{ log.result.replace('_', ' ') }}
                </span>
                <span v-if="log.error" class="mt-1 block max-w-lg leading-relaxed text-slate-500">
                  {{ log.error }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="divide-y divide-slate-100 lg:hidden">
          <div v-for="log in activity" :key="log.id" class="px-4 py-3"
               :class="log.bad ? 'bg-amber-50/40' : ''">
            <div class="mb-1 flex items-center justify-between gap-2">
              <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase
                           tracking-wide" :class="resultChip(log.result)">
                {{ log.result.replace('_', ' ') }}
              </span>
              <span class="text-xs text-slate-400">{{ when(log.fired_at) }}</span>
            </div>
            <p class="text-xs font-medium text-slate-700">{{ log.rule }}</p>
            <p class="mt-0.5 text-xs text-slate-500">{{ log.lead ?? '—' }} · {{ actionWord(log.action) }}</p>
            <p v-if="log.error" class="mt-0.5 break-words text-xs text-slate-500">{{ log.error }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ---------------- modals ---------------- -->
    <RuleFormModal
      :show="ruleModal" :catalog="catalog" :rule="editingRule"
      @close="ruleModal = false"
    />

    <TemplateFormModal
      :show="templateModal" :template="editingTemplate"
      :placeholders="placeholders" :provider-templates="providerList.templates" :provider-truncated="providerTruncated"
      @close="templateModal = false"
    />

    <ConfirmDialog
      :show="!!toggling"
      :title="`Switch on “${toggling?.name}”?`"
      :message="toggleMessage"
      confirm-text="Turn it on"
      :processing="toggleBusy || toggleCount === null"
      @close="toggling = null"
      @confirm="confirmToggle"
    />

    <ConfirmDialog
      :show="!!settling"
      :title="settleTitle"
      :message="settleMessage"
      :confirm-text="settling?.answer === 'delivered' ? 'Yes, it is in the log' : 'Not in the log — send again'"
      @close="settling = null"
      @confirm="confirmSettle"
    />

    <ConfirmDialog
      :show="!!deletingRule"
      title="Delete this rule?"
      :message="deleteRuleMessage"
      confirm-text="Delete rule"
      @close="deletingRule = null"
      @confirm="confirmDeleteRule"
    />

    <ConfirmDialog
      :show="!!deletingTemplate"
      title="Delete this tag?"
      :message="`“${deletingTemplate?.name}” will be removed. Messages already sent keep their wording in the log. If a rule still uses it, you will be told rather than losing the rule.`"
      confirm-text="Delete tag"
      @close="deletingTemplate = null"
      @confirm="confirmDeleteTemplate"
    />
  </AppLayout>
</template>
