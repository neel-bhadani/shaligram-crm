<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import HelpTip from './HelpTip.vue'
import { rulePhrase } from '../lib/rulePhrase.js'

/*
 | The rule builder.
 |
 | Four dropdowns and a sentence. The sentence is the important half: it sits
 | above the form, updates on every keystroke, and turns a set of controls into
 | something the office admin can read back and confirm before anything is
 | switched on. Everything else here exists to keep that sentence honest.
 |
 | No trigger, stage, source, role or action is named in this file. The whole
 | vocabulary arrives as `catalog` from config/automation.php via RuleCatalog,
 | which is also what the server validates against — so a control can never
 | offer something the save would reject, and a value the save accepts can
 | never be missing from a control.
 */
const props = defineProps({
  show: Boolean,
  catalog: { type: Object, required: true },
  // null when adding; the rule row when editing
  rule: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const editing = computed(() => !!props.rule)

const form = useForm({
  name: '',
  description: '',
  trigger: 'lead_created',
  trigger_config: {},
  conditions: [],
  actions: [],
})

/* ---------------- loading ---------------- */

const blank = () => ({
  name: '',
  description: '',
  trigger: 'lead_created',
  trigger_config: defaultsFor(props.catalog.triggers.lead_created?.params),
  conditions: [],
  actions: [],
})

/*
 | A parameter's `default` from the catalogue, applied when the control appears
 | rather than when the form is saved. An admin who adds "create a follow-up"
 | and reads the preview should see "create a call follow-up in 24 hours", not
 | "create a … follow-up in …" until they have touched two more controls.
 */
function defaultsFor(params) {
  const out = {}

  Object.entries(params || {}).forEach(([key, meta]) => {
    if (meta.default !== undefined && meta.default !== null) out[key] = meta.default
  })

  return out
}

watch(() => props.show, open => {
  if (!open) return

  testResult.value = null

  if (props.rule) {
    form.defaults({
      name: props.rule.name ?? '',
      description: props.rule.description ?? '',
      trigger: props.rule.trigger,
      trigger_config: { ...(props.rule.trigger_config ?? {}) },
      conditions: (props.rule.conditions ?? []).map(c => ({ ...c })),
      actions: (props.rule.actions ?? []).map(a => ({ ...a })),
    })
  } else {
    form.defaults(blank())
  }

  form.reset()
  form.clearErrors()
})

/* ---------------- the vocabulary ---------------- */

const triggerList = computed(() => Object.entries(props.catalog.triggers ?? {}))
const conditionList = computed(() => Object.entries(props.catalog.conditions ?? {}))
const actionList = computed(() => Object.entries(props.catalog.actions ?? {}))

const triggerMeta = computed(() => props.catalog.triggers?.[form.trigger] ?? null)
const triggerParams = computed(() => Object.entries(triggerMeta.value?.params ?? {}))

const optionsFor = token => props.catalog.options?.[token] ?? []

/*
 | A parameter with a `when` clause only exists while that clause holds — the
 | alert's "which role" box appears once "Everybody in a role" is chosen and
 | goes away again if it is not. Presentation only: AutomationRuleRequest
 | applies the same test server-side, so a stale value posted from a fiddled
 | form is dropped rather than stored.
 */
const visibleParams = (params, values) =>
  Object.entries(params ?? {}).filter(([, meta]) =>
    !meta.when || Object.entries(meta.when).every(([k, v]) => values[k] === v))

/* ---------------- the sentence ---------------- */

const preview = computed(() => rulePhrase({
  trigger: form.trigger,
  trigger_config: form.trigger_config,
  conditions: form.conditions,
  actions: form.actions,
}, props.catalog))

/*
 | The loop warning, at save time, before the rule can ever run.
 |
 | A rule that changes a stage AND watches for stage changes is the shape that
 | can ping-pong: it moves a lead, the move sets off another rule, and that one
 | moves it back. Loop protection stops it after three touches, so this is not
 | a refusal — it is the difference between learning this now and learning it
 | from an alert saying "automation held itself back" next Tuesday.
 */
const couldLoop = computed(() =>
  form.trigger === 'stage_changed' && form.actions.some(a => a.type === 'change_stage'))

const isTimeBased = computed(() => triggerMeta.value?.kind === 'time')

/* ---------------- editing the parts ---------------- */

watch(() => form.trigger, trigger => {
  // the old trigger's parameters mean nothing to the new one
  form.trigger_config = defaultsFor(props.catalog.triggers?.[trigger]?.params)
  testResult.value = null
})

const addCondition = () => {
  const [firstKey] = conditionList.value[0] ?? []

  form.conditions.push({ field: firstKey ?? '', value: '' })
  testResult.value = null
}

const removeCondition = i => {
  form.conditions.splice(i, 1)
  testResult.value = null
}

const onConditionField = i => {
  // the value belonged to the old field's list
  form.conditions[i].value = ''
  testResult.value = null
}

const addAction = () => {
  const [firstKey, meta] = actionList.value[0] ?? []

  form.actions.push({ type: firstKey ?? '', ...defaultsFor(meta?.params) })
}

const removeAction = i => form.actions.splice(i, 1)

const onActionType = i => {
  const type = form.actions[i].type

  // rebuilt rather than merged: parameters from the action they just switched
  // away from would be posted alongside the new one's
  form.actions[i] = { type, ...defaultsFor(props.catalog.actions?.[type]?.params) }
}

/* ---------------- the Test button ---------------- */

const testing = ref(false)
const testResult = ref(null)

/*
 | The blast radius, before anything is switched on.
 |
 | It posts the rule as it currently stands — saved or not — and the server
 | answers with a count and the first ten leads. Nothing on that path touches
 | the engine, so pressing this can never make the rule run; the controller
 | says so at length, and the test suite proves it.
 */
const runTest = () => {
  testing.value = true
  testResult.value = null

  axios.post(route('automation.rules.match'), {
    trigger: form.trigger,
    trigger_config: form.trigger_config,
    conditions: form.conditions.filter(c => c.field && c.value !== ''),
  })
    .then(({ data }) => { testResult.value = data })
    .catch(() => {
      testResult.value = { error: 'Could not check that just now. Try again in a moment.' }
    })
    .finally(() => { testing.value = false })
}

/* ---------------- saving ---------------- */

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  }

  editing.value
    ? form.put(route('automation.rules.update', props.rule.id), options)
    : form.post(route('automation.rules.store'), options)
}

const err = key => form.errors[key]
</script>

<template>
  <Modal :show="show" :title="editing ? 'Edit rule' : 'New rule'" max-width="max-w-3xl" @close="emit('close')">

    <!--
      THE SENTENCE. First thing on the form, before any control, because it is
      the thing the admin is actually deciding about — the dropdowns below are
      only how you change it.
    -->
    <div class="mb-5 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3.5">
      <div class="mb-1 flex items-center gap-1.5">
        <span class="text-[10px] font-semibold uppercase tracking-wide text-teal-700">In plain words</span>
        <HelpTip title="Read this back to yourself">
          This sentence is your rule, written out. If it does not say what you meant, change the
          dropdowns below until it does — then press Test to see which leads it would affect.
        </HelpTip>
      </div>
      <p class="text-sm font-medium leading-relaxed text-teal-900">{{ preview }}</p>
    </div>

    <div class="space-y-5">

      <!-- ---------------- name ---------------- -->
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Rule name" required :error="err('name')"
                   hint="Something you will recognise in a list, like “Chase ignored leads”.">
          <input v-model="form.name" type="text" class="w-full" maxlength="120" />
        </FormField>

        <FormField label="What it is for" :error="err('description')"
                   hint="Optional. A line for whoever reads this after you.">
          <input v-model="form.description" type="text" class="w-full" maxlength="500" />
        </FormField>
      </div>

      <!-- ---------------- trigger ---------------- -->
      <div class="card p-4">
        <div class="mb-3 flex items-center gap-1.5">
          <h4 class="text-sm font-semibold text-slate-900">1. Trigger</h4>
          <HelpTip title="Trigger">
            What has to happen before this rule runs. Every rule has exactly one.
            Some triggers are events — a lead is added, a stage changes — and run straight away.
            Others are questions the system asks every hour, like “is this follow-up late yet?”.
          </HelpTip>
        </div>

        <FormField label="What has to happen" required :error="err('trigger')"
                   :hint="triggerMeta?.hint">
          <select v-model="form.trigger" class="w-full">
            <option v-for="[key, meta] in triggerList" :key="key" :value="key">{{ meta.label }}</option>
          </select>
        </FormField>

        <div v-if="triggerParams.length" class="mt-4 grid gap-4 sm:grid-cols-2">
          <FormField
            v-for="[key, meta] in triggerParams" :key="key"
            :label="meta.label" :required="meta.required" :hint="meta.hint"
            :error="err(`trigger_config.${key}`)"
          >
            <select v-if="meta.type === 'select'" v-model="form.trigger_config[key]" class="w-full">
              <option value="">Choose…</option>
              <option v-for="o in optionsFor(meta.options)" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <input
              v-else-if="meta.type === 'number'" v-model.number="form.trigger_config[key]"
              type="number" class="w-full" :min="meta.min" :max="meta.max"
            />
            <input v-else v-model="form.trigger_config[key]" type="text" class="w-full" />
          </FormField>
        </div>

        <p v-if="isTimeBased" class="warn-box mt-4">
          This is a time-based rule. It does not run the moment something happens — the system
          checks once an hour and acts on whatever fits. That needs the scheduled task running on
          the server; the Rules tab says whether it is.
        </p>
      </div>

      <!-- ---------------- conditions ---------------- -->
      <div class="card p-4">
        <div class="mb-1 flex items-center gap-1.5">
          <h4 class="text-sm font-semibold text-slate-900">2. Only when…</h4>
          <HelpTip title="Conditions">
            Narrows the rule down. Leave this empty and the rule runs every time the trigger
            happens. Add conditions and <strong>all of them</strong> have to be true — there is no
            “either/or”. If you want Facebook leads OR Instagram leads treated the same way,
            that is two rules, and two rules you can read beat one you have to decode.
          </HelpTip>
        </div>
        <p class="mb-3 text-xs text-slate-400">
          Optional. Leave empty to run on every lead the trigger applies to.
        </p>

        <div v-for="(condition, i) in form.conditions" :key="i" class="mb-2 flex flex-wrap items-start gap-2">
          <select
            v-model="condition.field" class="min-w-36 flex-1"
            @change="onConditionField(i)"
          >
            <option v-for="[key, meta] in conditionList" :key="key" :value="key">{{ meta.label }}</option>
          </select>

          <span class="pt-2 text-xs text-slate-400">is</span>

          <select v-model="condition.value" class="min-w-40 flex-1">
            <option value="">Choose…</option>
            <option
              v-for="o in optionsFor(catalog.conditions[condition.field]?.options)"
              :key="o.value" :value="o.value"
            >{{ o.label }}</option>
          </select>

          <button type="button" class="btn-xs mt-0.5" @click="removeCondition(i)">Remove</button>

          <p v-if="err(`conditions.${i}.value`)" class="w-full text-xs text-rose-600">
            {{ err(`conditions.${i}.value`) }}
          </p>
        </div>

        <button
          v-if="form.conditions.length < 6" type="button" class="btn-xs" @click="addCondition"
        >+ Add a condition</button>
      </div>

      <!-- ---------------- actions ---------------- -->
      <div class="card p-4">
        <div class="mb-1 flex items-center gap-1.5">
          <h4 class="text-sm font-semibold text-slate-900">3. Then do this</h4>
          <HelpTip title="Actions">
            What the rule does, in the order you put them. They all happen together or not at all,
            so a rule can never half-run and leave a lead in a strange state.
            A WhatsApp action <strong>queues</strong> a message for somebody to send — it never
            sends one by itself.
          </HelpTip>
        </div>
        <p class="mb-3 text-xs text-slate-400">At least one. They run in this order.</p>

        <p v-if="err('actions')" class="mb-2 text-xs text-rose-600">{{ err('actions') }}</p>

        <div v-for="(action, i) in form.actions" :key="i" class="mb-3 rounded-lg border border-slate-200 p-3">
          <div class="mb-3 flex items-center gap-2">
            <span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-slate-100
                         text-[10px] font-bold text-slate-500">{{ i + 1 }}</span>
            <select v-model="action.type" class="flex-1" @change="onActionType(i)">
              <option v-for="[key, meta] in actionList" :key="key" :value="key">{{ meta.label }}</option>
            </select>
            <button type="button" class="btn-xs" @click="removeAction(i)">Remove</button>
          </div>

          <p v-if="catalog.actions[action.type]?.hint" class="mb-3 text-xs text-slate-400">
            {{ catalog.actions[action.type].hint }}
          </p>

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              v-for="[key, meta] in visibleParams(catalog.actions[action.type]?.params, action)"
              :key="key"
              :label="meta.label" :required="meta.required" :hint="meta.hint"
              :error="err(`actions.${i}.${key}`)"
              :class="meta.type === 'textarea' ? 'sm:col-span-2' : ''"
            >
              <select v-if="meta.type === 'select'" v-model="action[key]" class="w-full">
                <option value="">Choose…</option>
                <option v-for="o in optionsFor(meta.options)" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <input
                v-else-if="meta.type === 'number'" v-model.number="action[key]"
                type="number" class="w-full" :min="meta.min" :max="meta.max"
              />
              <textarea v-else-if="meta.type === 'textarea'" v-model="action[key]" rows="2" class="w-full" />
              <input v-else v-model="action[key]" type="text" class="w-full" />
            </FormField>
          </div>
        </div>

        <button
          v-if="form.actions.length < 6" type="button" class="btn-xs" @click="addAction"
        >+ Add an action</button>
      </div>

      <!-- ---------------- the loop warning ---------------- -->
      <div v-if="couldLoop" class="warn-box">
        <div class="mb-1 flex items-center gap-1.5">
          <strong>This rule changes a stage, and it also watches for stage changes.</strong>
          <HelpTip title="Why this matters" align="right">
            Changing a stage is itself a stage change, so this rule can set off another rule —
            or itself. Two rules that undo each other will bounce a lead back and forth.
            The system stops that after three changes in a row and tells the admins, so nothing
            runs away; you are being told now so it does not come as a surprise later.
          </HelpTip>
        </div>
        You can still save it. Just check that no other rule moves the lead back again.
      </div>

      <!-- ---------------- the Test button ---------------- -->
      <div class="card p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="flex items-center gap-1.5">
              <h4 class="text-sm font-semibold text-slate-900">Test it first</h4>
              <HelpTip title="What Test does">
                It counts the leads that match right now and shows you the first ten.
                It does <strong>not</strong> run the rule — nothing is assigned, moved, messaged
                or alerted. It is there so you can see how big this is before you switch it on.
              </HelpTip>
            </div>
            <p class="mt-0.5 text-xs text-slate-400">Shows which leads match. Changes nothing.</p>
          </div>
          <button type="button" class="btn-ghost" :disabled="testing" @click="runTest">
            {{ testing ? 'Checking…' : 'Test' }}
          </button>
        </div>

        <div v-if="testResult" class="mt-3">
          <p v-if="testResult.error" class="text-xs text-rose-600">{{ testResult.error }}</p>

          <template v-else>
            <p class="info-box">{{ testResult.summary }}</p>

            <div v-if="testResult.sample?.length" class="mt-3">
              <!-- table on desktop, cards on mobile: the same pattern as the other pages -->
              <table class="hidden w-full text-left text-xs lg:table">
                <thead class="text-[10px] uppercase tracking-wide text-slate-400">
                  <tr>
                    <th class="py-1.5 pr-3 font-semibold">Lead</th>
                    <th class="py-1.5 pr-3 font-semibold">Stage</th>
                    <th class="py-1.5 pr-3 font-semibold">Source</th>
                    <th class="py-1.5 pr-3 font-semibold">Project</th>
                    <th class="py-1.5 font-semibold">With</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="lead in testResult.sample" :key="lead.id" class="border-t border-slate-100">
                    <td class="py-1.5 pr-3 font-medium text-slate-700">{{ lead.name }}</td>
                    <td class="py-1.5 pr-3 text-slate-500">{{ lead.stage }}</td>
                    <td class="py-1.5 pr-3 text-slate-500">{{ lead.source }}</td>
                    <td class="py-1.5 pr-3 text-slate-500">{{ lead.project }}</td>
                    <td class="py-1.5 text-slate-500">{{ lead.owner ?? '—' }}</td>
                  </tr>
                </tbody>
              </table>

              <div class="divide-y divide-slate-100 lg:hidden">
                <div v-for="lead in testResult.sample" :key="lead.id" class="py-2">
                  <p class="text-xs font-medium text-slate-700">{{ lead.name }}</p>
                  <p class="mt-0.5 text-xs text-slate-500">{{ lead.stage }} · {{ lead.source }}</p>
                  <p class="mt-0.5 text-xs text-slate-500">{{ lead.project }} · {{ lead.owner ?? '—' }}</p>
                </div>
              </div>

              <p v-if="testResult.count > testResult.sample.length" class="mt-2 text-xs text-slate-400">
                Showing the first {{ testResult.sample.length }} of {{ testResult.count }}.
              </p>
            </div>
          </template>
        </div>
      </div>

      <!--
        Said on the form rather than only in a toast afterwards, because it is
        the answer to "I saved it, why is nothing happening".
      -->
      <p v-if="!editing" class="info-box">
        New rules are saved <strong>switched off</strong>. Nothing happens until you turn it on
        from the list — and when you do, you will be shown how many leads it applies to first.
      </p>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (editing ? 'Save changes' : 'Save rule') }}
      </button>
    </template>
  </Modal>
</template>
