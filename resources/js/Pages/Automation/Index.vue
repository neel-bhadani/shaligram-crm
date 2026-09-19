<script setup>
import { computed, ref } from 'vue'
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
 | The Automation page. Five tabs, one payload.
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
  rules: Array,
  templates: Array,
  queue: Array,
  activity: Array,
  catalog: Object,
  whatsapp: Object,
  placeholders: Object,
  categories: Object,
  thresholds: Object,
  schedulerRunning: { type: [Boolean, null], default: null },
  // the alerts tab, from the same trait the /alerts page uses
  alerts: Object,
  filters: Object,
  counts: Object,
})

const TABS = [
  { key: 'rules', label: 'Rules' },
  { key: 'templates', label: 'Templates' },
  { key: 'queue', label: 'Queue' },
  { key: 'alerts', label: 'Alerts' },
  { key: 'activity', label: 'Activity' },
]

const tab = ref(props.tab ?? 'rules')

// no visit of its own: this is here for the clean address bar on arrival
useFilterVisit(route('automation.index'))

const badge = key => ({
  rules: props.rules.length,
  templates: props.templates.length,
  queue: props.queue.filter(m => m.status === 'queued').length,
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

/* ================= templates ================= */

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
 | "{{1}} = first_name, {{2}} = project" — what Meta will be sent.
 |
 | Built here rather than in the markup because Vue's template parser reads a
 | literal "{{" inside an interpolation as the start of another one, whatever it
 | is nested in. Showing it at all is deliberate: the numbering is stored the
 | moment a message is saved, and an admin who submits a template to Meta later
 | should not be meeting it for the first time.
 */
const metaNumbering = template =>
  (template.placeholder_map ?? [])
    .map((name, i) => `{{${i + 1}}} = ${name}`)
    .join(', ')

/* ================= queue ================= */

const queued = computed(() => props.queue.filter(m => m.status === 'queued'))
const history = computed(() => props.queue.filter(m => m.status !== 'queued'))

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

const sendByApi = message =>
  router.post(route('automation.messages.send', message.id), {}, { preserveScroll: true })

const cancelMessage = message =>
  router.post(route('automation.messages.cancel', message.id), {}, { preserveScroll: true })

const statusChip = status => ({
  queued: 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300',
  opened: 'border-teal-200 dark:border-teal-500/30 bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-300',
  sent: 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
  failed: 'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-300',
  cancelled: 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-500 dark:text-slate-400',
}[status] ?? 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-500 dark:text-slate-400')

/* the WhatsApp API settings form */
const waForm = useForm({
  phone_number_id: props.whatsapp.phone_number_id ?? '',
  access_token: '',
  auto_send: props.whatsapp.auto_send ?? false,
})

const saveWhatsApp = () => waForm.put(route('automation.whatsapp.update'), {
  preserveScroll: true,
  onSuccess: () => { waForm.access_token = '' },
})

/* ================= activity ================= */

const resultChip = result => ({
  fired: 'border-teal-200 dark:border-teal-500/30 bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-300',
  success: 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
  skipped: 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-500 dark:text-slate-400',
  failed: 'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-300',
  loop_guard: 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300',
  cooldown: 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300',
}[result] ?? 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-500 dark:text-slate-400')

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
      <Link :href="route('automation.guide')" class="btn-ghost">How this works</Link>
      <button v-if="tab === 'rules'" class="btn" @click="openRule(null)">New rule</button>
      <button v-if="tab === 'templates'" class="btn" @click="openTemplate(null)">New message</button>
    </template>

    <!-- ---------------- tabs ---------------- -->
    <div class="mb-5 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-700">
      <button
        v-for="t in TABS" :key="t.key"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition"
        :class="tab === t.key
          ? 'border-teal-700 text-teal-800 dark:text-teal-300'
          : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100'"
        @click="tab = t.key"
      >
        {{ t.label }}
        <span
          v-if="badge(t.key)"
          class="ml-1 rounded-full bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:text-slate-300"
        >{{ badge(t.key) }}</span>
      </button>
    </div>

    <!-- ================= RULES ================= -->
    <div v-show="tab === 'rules'">

      <!--
        The scheduler warning. Time-based rules and every built-in alert depend
        on a cron job that this application cannot start for itself. When it is
        not running nothing errors — event rules work perfectly and the time
        ones simply never fire, which is the worst kind of broken.
      -->
      <div v-if="schedulerRunning === false" class="warn-box mb-4">
        <strong>The hourly task has not run recently.</strong>
        Rules that watch for overdue follow-ups or stalled leads will not fire, and the automatic
        alerts will not be raised. Rules that react to something happening — a lead being added, a
        stage changing — are unaffected. Ask whoever set up the server to check that Laravel's
        scheduler is running.
      </div>

      <!-- ---------------- empty state that teaches ---------------- -->
      <div v-if="!rules.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800 dark:text-slate-200">You have no rules yet</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
          A rule watches for one thing happening — a lead arriving, a stage changing, a follow-up
          going overdue — and then does something about it, like giving the lead to a telecaller
          or booking a call for tomorrow.
        </p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
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
                  <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ rule.name }}</h3>
                  <span
                    class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                    :class="rule.is_active
                      ? 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                      : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-500 dark:text-slate-400'"
                  >{{ rule.is_active ? 'On' : 'Off' }}</span>
                  <span
                    v-if="rule.is_time_based"
                    class="rounded border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 px-1.5 py-0.5 text-[10px]
                           font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"
                  >Hourly</span>
                </div>

                <!-- the same sentence the builder shows, from the same function -->
                <p class="mt-1.5 text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ phrase(rule) }}</p>

                <p v-if="rule.description" class="mt-1.5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
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
                  :class="rule.is_active ? '' : 'border-teal-600 text-teal-700 dark:text-teal-300'"
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

    <!-- ================= TEMPLATES ================= -->
    <div v-show="tab === 'templates'">
      <div v-if="!templates.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800 dark:text-slate-200">No messages written yet</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
          A message is something you write once and send many times — a welcome, a brochure
          follow-up, a thank you after a site visit. The customer's name and project are filled
          in automatically when it is used.
        </p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
          Messages are never sent on their own. A rule puts one in the Queue and somebody opens it
          in WhatsApp and sends it.
        </p>
        <button class="btn mt-5" @click="openTemplate(null)">Write your first message</button>
      </div>

      <div v-else class="grid gap-3 lg:grid-cols-2">
        <div v-for="template in templates" :key="template.id" class="card flex flex-col p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ template.name }}</h3>
                <span
                  class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                  :class="template.category === 'marketing'
                    ? 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300'
                    : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 text-slate-600 dark:text-slate-300'"
                >{{ categories[template.category]?.label ?? template.category }}</span>
                <span
                  v-if="!template.is_active"
                  class="rounded border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 px-1.5 py-0.5 text-[10px]
                         font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"
                >Off</span>
              </div>
              <p class="mt-1 text-[11px] text-slate-400">{{ template.cost_note }}</p>
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

          <div class="mt-3 flex-1 rounded-xl bg-slate-100 dark:bg-slate-700 p-2.5">
            <div class="whitespace-pre-wrap rounded-xl rounded-tl-sm bg-white dark:bg-slate-800 px-3 py-2 text-xs
                        leading-relaxed text-slate-800 dark:text-slate-200 shadow-sm">{{ template.preview }}</div>
          </div>

          <p class="mt-2 text-[11px] text-slate-400">
            Used {{ template.messages_count }} time{{ template.messages_count === 1 ? '' : 's' }}
            <template v-if="template.placeholder_map?.length">
              · Meta numbering: {{ metaNumbering(template) }}
            </template>
          </p>
        </div>
      </div>
    </div>

    <!-- ================= QUEUE ================= -->
    <div v-show="tab === 'queue'">

      <div class="info-box mb-4 flex items-start gap-2">
        <span class="flex-1">
          <strong>Nothing here is sent automatically.</strong>
          A rule writes the message and puts it in this list. You open it in WhatsApp, check it,
          and press send yourself.
        </span>
        <HelpTip title="Why not just send it?" align="right">
          Two reasons. Sending from software needs a paid WhatsApp Business Platform account,
          which is not the same thing as the free WhatsApp Business app on a phone — and that is
          not set up yet.
          <br><br>
          And even once it is, a person reading the message before it goes to a customer catches
          the ones a rule got wrong. Automatic sending stays switched off until an admin turns it
          on deliberately.
        </HelpTip>
      </div>

      <div v-if="!queue.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800 dark:text-slate-200">Nothing waiting to be sent</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
          When a rule with a “Queue a WhatsApp message” action fires, the message appears here
          with the customer's details already filled in. You open it in WhatsApp and send it.
        </p>
        <button class="btn-ghost mt-5" @click="tab = 'rules'">See the rules</button>
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
                  <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ message.lead?.name ?? 'Deleted lead' }}</span>
                  <span class="text-xs text-slate-400">{{ message.to_number }}</span>
                  <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase
                               tracking-wide" :class="statusChip(message.status)">{{ message.status }}</span>
                </div>
                <div class="mt-2 whitespace-pre-wrap rounded-xl rounded-tl-sm bg-slate-50 dark:bg-slate-900/60 px-3 py-2
                            text-xs leading-relaxed text-slate-700 dark:text-slate-300">{{ message.body }}</div>
                <p class="mt-1.5 text-[11px] text-slate-400">
                  {{ message.template ?? 'No template' }}
                  <template v-if="message.rule"> · queued by “{{ message.rule }}”</template>
                  · {{ when(message.created_at) }}
                </p>
              </div>

              <div class="flex flex-none flex-wrap gap-1.5">
                <button class="btn-xs border-teal-600 text-teal-700 dark:text-teal-300" @click="openInWhatsApp(message)">
                  Open in WhatsApp
                </button>
                <button class="btn-xs" @click="sendByApi(message)">Send by API</button>
                <button class="btn-xs hover:border-rose-500 hover:text-rose-600"
                        @click="cancelMessage(message)">Cancel</button>
              </div>
            </div>
          </div>
        </div>

        <template v-if="history.length">
          <h3 class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-slate-400">
            Recently handled
          </h3>
          <div class="card divide-y divide-slate-100 dark:divide-slate-700/60">
            <div v-for="message in history" :key="message.id" class="flex flex-wrap items-center gap-2 px-4 py-2.5">
              <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                    :class="statusChip(message.status)">{{ message.status }}</span>
              <span class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ message.lead?.name ?? 'Deleted lead' }}</span>
              <span class="text-[11px] text-slate-400">
                {{ message.template ?? '—' }}
                <template v-if="message.user"> · by {{ message.user }}</template>
                · {{ when(message.sent_at ?? message.created_at) }}
              </span>
              <span v-if="message.error" class="w-full text-[11px] leading-relaxed text-rose-600 dark:text-rose-400">
                {{ message.error }}
              </span>
            </div>
          </div>
        </template>
      </template>

      <!-- ---------------- API settings ---------------- -->
      <div class="card mt-6 p-4">
        <div class="mb-1 flex items-center gap-1.5">
          <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Sending by API</h3>
          <HelpTip title="Click-to-send vs the API" align="right">
            <strong>Click-to-send</strong> works today and costs nothing. It opens WhatsApp with
            the message already typed and you press send. Because we hand it to WhatsApp, we can
            only record that it was <em>opened</em> — not whether you sent it.
            <br><br>
            <strong>API sending</strong> sends without anybody opening anything, and records that
            it was really sent. It needs a WhatsApp Business Platform account through Meta or a
            provider, with a monthly cost and an approval process.
          </HelpTip>
        </div>

        <p v-if="!whatsapp.configured" class="warn-box mt-2">{{ whatsapp.not_configured }}</p>
        <p v-else class="info-box mt-2">
          The API is configured. Automatic sending is
          <strong>{{ whatsapp.auto_send ? 'ON' : 'off' }}</strong>.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <FormField label="Phone number ID" :error="waForm.errors.phone_number_id"
                     hint="From the WhatsApp section of your Meta app dashboard.">
            <input v-model="waForm.phone_number_id" type="text" class="w-full" />
          </FormField>

          <FormField label="Access token" :error="waForm.errors.access_token"
                     :hint="whatsapp.access_token_tail
                       ? `A token ending ${whatsapp.access_token_tail} is saved. Leave empty to keep it.`
                       : 'Stored encrypted. It is never shown again after saving.'">
            <input v-model="waForm.access_token" type="password" class="w-full"
                   autocomplete="new-password" placeholder="••••••••" />
          </FormField>
        </div>

        <label class="mt-4 flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
          <input v-model="waForm.auto_send" type="checkbox" class="mt-0.5 h-4 w-4"
                 :disabled="!whatsapp.configured" />
          <span>
            Send queued messages automatically
            <span class="block text-xs text-slate-400">
              Off by default, and it cannot be switched on until the API is configured.
              Leave it off if you want somebody to read every message before it goes out.
            </span>
          </span>
        </label>

        <div class="mt-4">
          <button class="btn" :disabled="waForm.processing" @click="saveWhatsApp">
            {{ waForm.processing ? 'Saving…' : 'Save WhatsApp settings' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ================= ALERTS ================= -->
    <div v-show="tab === 'alerts'">
      <p class="mb-4 max-w-3xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
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
        <h3 class="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-100">Alerts you get without any rule</h3>
        <ul class="space-y-1.5 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
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
      <p class="mb-4 max-w-3xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
        Everything automation has done, newest first. This is where to look when a lead turns up
        somewhere unexpected — or when a rule is switched on and nothing seems to be happening.
      </p>

      <div v-if="!activity.length" class="card px-6 py-10 text-center">
        <p class="text-base font-semibold text-slate-800 dark:text-slate-200">Nothing has run yet</p>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
          Once you switch a rule on, every single thing it does will be listed here — what it did,
          to which lead, and whether it worked. Including the times it decided not to.
        </p>
      </div>

      <div v-else class="card overflow-x-auto">
        <table class="w-full text-left text-xs">
          <thead class="border-b border-slate-100 dark:border-slate-700/60 text-[10px] uppercase tracking-wide text-slate-400">
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
              <td class="whitespace-nowrap px-4 py-2.5 text-slate-500 dark:text-slate-400">{{ when(log.fired_at) }}</td>
              <td class="px-4 py-2.5 font-medium text-slate-700 dark:text-slate-300">{{ log.rule }}</td>
              <td class="px-4 py-2.5 text-slate-500 dark:text-slate-400">{{ log.lead ?? '—' }}</td>
              <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300">{{ actionWord(log.action) }}</td>
              <td class="px-4 py-2.5">
                <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase
                             tracking-wide" :class="resultChip(log.result)">
                  {{ log.result.replace('_', ' ') }}
                </span>
                <span v-if="log.error" class="mt-1 block max-w-lg leading-relaxed text-slate-500 dark:text-slate-400">
                  {{ log.error }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ---------------- modals ---------------- -->
    <RuleFormModal
      :show="ruleModal" :catalog="catalog" :rule="editingRule"
      @close="ruleModal = false"
    />

    <TemplateFormModal
      :show="templateModal" :template="editingTemplate"
      :placeholders="placeholders" :categories="categories"
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
      :show="!!deletingRule"
      title="Delete this rule?"
      :message="deleteRuleMessage"
      confirm-text="Delete rule"
      @close="deletingRule = null"
      @confirm="confirmDeleteRule"
    />

    <ConfirmDialog
      :show="!!deletingTemplate"
      title="Delete this message?"
      :message="`“${deletingTemplate?.name}” will be removed. Messages already sent keep their wording in the log. If a rule still uses it, you will be told rather than losing the rule.`"
      confirm-text="Delete message"
      @close="deletingTemplate = null"
      @confirm="confirmDeleteTemplate"
    />
  </AppLayout>
</template>
