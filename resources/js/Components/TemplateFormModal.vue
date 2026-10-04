<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import HelpTip from './HelpTip.vue'

/*
 | A message: which 11za template, and what goes into each of its variables.
 |
 | The wording is 11za's, so there is nothing to write here. What only the CRM
 | knows is that the template's {{1}} is the lead's first name and {{2}} the
 | project — without that every send goes out blank — so the screen is: pick
 | the template, then one dropdown per variable.
 |
 | The template is picked from 11za's own list when it could be read, which is
 | what stops a mistyped name reaching 11za as "template doesn't exist". When
 | the list is not available it falls back to typing the name and language.
 */
const props = defineProps({
  show: Boolean,
  template: { type: Object, default: null },
  // { name: { label, example } } from config/automation.php
  placeholders: { type: Object, required: true },
  // [{ name, language, body, variables }] as last read from 11za
  providerTemplates: { type: Array, default: () => [] },
})
const emit = defineEmits(['close'])

const editing = computed(() => !!props.template)

const form = useForm({
  name: '',
  provider_template_name: '',
  provider_template_language: 'en',
  placeholder_map: [],
  is_active: true,
})

watch(() => props.show, open => {
  if (!open) return

  form.defaults(props.template
    ? {
        name: props.template.name,
        provider_template_name: props.template.provider_template_name ?? '',
        provider_template_language: props.template.provider_template_language ?? 'en',
        placeholder_map: [...(props.template.placeholder_map ?? [])],
        is_active: props.template.is_active,
      }
    : { name: '', provider_template_name: '', provider_template_language: 'en', placeholder_map: [], is_active: true })

  form.reset()
  form.clearErrors()
})

/* ---------------- the 11za template ---------------- */

const fromList = computed(() => props.providerTemplates.length > 0)
const pickKey = t => `${t.name}|${t.language ?? ''}`

const picked = computed(() => props.providerTemplates.find(t =>
  t.name === form.provider_template_name
  && (t.language === null || t.language === form.provider_template_language)) ?? null)

// a saved name 11za no longer lists stays selectable, so editing does not lose it
const missingFromList = computed(() =>
  fromList.value && form.provider_template_name && !picked.value)

const onPick = event => {
  const t = props.providerTemplates.find(p => pickKey(p) === event.target.value)

  form.provider_template_name = t?.name ?? ''
  form.provider_template_language = t?.language ?? form.provider_template_language ?? 'en'

  if (!t) return
  if (!form.name) form.name = t.name.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

  // one dropdown per variable, as many as 11za says the template has
  if (t.variables !== null && t.variables !== undefined) {
    form.placeholder_map = Array.from({ length: t.variables }, (_, i) => form.placeholder_map[i] ?? '')
  }
}

/* ---------------- the variables ---------------- */

const placeholderList = computed(() => Object.entries(props.placeholders))

// fixed by 11za's list when it said how many; otherwise the admin adds them
const variablesFixed = computed(() => picked.value?.variables !== null && picked.value?.variables !== undefined)

const addVariable = () => form.placeholder_map.push('')
const removeVariable = () => form.placeholder_map.pop()

// Built here because Vue reads a literal "{{" in the markup as an interpolation
const slotLabel = i => `{{${i + 1}}}`

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }

  editing.value
    ? form.put(route('automation.templates.update', props.template.id), options)
    : form.post(route('automation.templates.store'), options)
}
</script>

<template>
  <Modal :show="show" :title="editing ? 'Edit message' : 'New message'" max-width="max-w-2xl" @close="emit('close')">
    <div class="space-y-5">

      <!-- ---------------- the 11za template ---------------- -->
      <div v-if="fromList">
        <FormField label="11za template" required :error="form.errors.provider_template_name"
                   hint="From your 11za account. The wording is set up in 11za, not here.">
          <select class="w-full" :value="picked ? pickKey(picked) : ''" @change="onPick">
            <option value="">{{ missingFromList ? `${form.provider_template_name} (not in 11za's list)` : 'Choose a template…' }}</option>
            <option v-for="t in providerTemplates" :key="pickKey(t)" :value="pickKey(t)">
              {{ t.name }}{{ t.language ? ` (${t.language})` : '' }}
            </option>
          </select>
        </FormField>
        <p v-if="missingFromList" class="warn-box mt-2">
          11za does not list “{{ form.provider_template_name }}” any more. Sends will fail until you
          choose a template that exists.
        </p>
      </div>

      <div v-else class="grid gap-4 sm:grid-cols-3">
        <FormField class="sm:col-span-2" label="11za template name" required
                   :error="form.errors.provider_template_name"
                   hint="The list could not be read from 11za, so type the name exactly as it appears in the 11za panel.">
          <input v-model="form.provider_template_name" type="text" class="w-full" placeholder="site_visit_thanks" />
        </FormField>
        <FormField label="Language" required :error="form.errors.provider_template_language"
                   hint="Usually en.">
          <input v-model="form.provider_template_language" type="text" class="w-full" placeholder="en" />
        </FormField>
      </div>

      <!-- 11za's own wording, read-only, so the variables can be matched to it -->
      <div v-if="picked?.body">
        <span class="text-xs font-semibold text-slate-500">The template in 11za</span>
        <div class="mt-1 whitespace-pre-wrap rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-700">{{ picked.body }}</div>
      </div>

      <!-- ---------------- the variables ---------------- -->
      <div>
        <div class="mb-2 flex items-center gap-1.5">
          <span class="text-xs font-semibold text-slate-500">What goes into each variable</span>
          <HelpTip title="Variables">
            11za's template has numbered gaps — {{ slotLabel(0) }}, {{ slotLabel(1) }} and so on.
            11za does not know what they are meant to be; this is where you say. Each one is filled
            in with the real lead's details when the message is sent.
          </HelpTip>
        </div>

        <p v-if="!form.placeholder_map.length" class="mb-2 text-xs text-slate-400">
          {{ variablesFixed ? 'This template has no variables.' : 'No variables yet. Add one for each numbered gap in the 11za template.' }}
        </p>

        <div v-for="(field, i) in form.placeholder_map" :key="i" class="mb-2 flex items-center gap-2">
          <span class="w-12 flex-none font-mono text-xs text-slate-500">{{ slotLabel(i) }}</span>
          <select v-model="form.placeholder_map[i]" class="flex-1">
            <option value="">Choose…</option>
            <option v-for="[key, meta] in placeholderList" :key="key" :value="key">
              {{ meta.label }} — e.g. {{ meta.example }}
            </option>
          </select>
        </div>
        <p v-for="(message, key) in form.errors" v-show="key.startsWith('placeholder_map')" :key="key"
           class="text-xs text-rose-600">{{ message }}</p>

        <div v-if="!variablesFixed" class="mt-2 flex gap-1.5">
          <button type="button" class="btn-xs" @click="addVariable">+ Add a variable</button>
          <button v-if="form.placeholder_map.length" type="button" class="btn-xs" @click="removeVariable">Remove the last</button>
        </div>
      </div>

      <FormField label="Name in the CRM" required :error="form.errors.name"
                 hint="What you pick it by on the Auto-send tab. The customer never sees this.">
        <input v-model="form.name" type="text" class="w-full" maxlength="120" />
      </FormField>

      <!-- wording written before the CRM stopped holding it: kept, never edited -->
      <div v-if="template?.old_body">
        <span class="text-xs font-semibold text-slate-500">Old wording, only used when sending by hand</span>
        <div class="mt-1 whitespace-pre-wrap rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-500">{{ template.old_body }}</div>
      </div>

      <label class="flex items-center gap-2 text-sm text-slate-700">
        <input v-model="form.is_active" type="checkbox" class="h-4 w-4" />
        Can be sent
        <HelpTip title="Switching a message off">
          A switched-off message disappears from the Auto-send dropdowns and the lead's WhatsApp
          button. A stage already set to send it skips it and says so in the Activity tab.
        </HelpTip>
      </label>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (editing ? 'Save changes' : 'Save message') }}
      </button>
    </template>
  </Modal>
</template>
