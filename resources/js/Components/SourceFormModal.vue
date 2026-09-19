<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | Add and edit a source. The key behaves exactly as it does on a stage — shown,
 | never typeable, slugged from the label on create and fixed afterwards — for
 | the same reason: `leads.source` holds it as a bare string and the Leads report
 | groups on it, so re-keying would rewrite what every past report says.
 |
 | The two defaults are the columns the brief calls `source_defaults`. They are
 | recorded here and NOT yet applied to new leads — see the note on the
 | LeadSource model — so the hints say what they are for without claiming the
 | form already obeys them.
 */
const props = defineProps({ show: Boolean, source: Object, options: Object })
const emit = defineEmits(['close'])

const blank = {
  label: '',
  default_stage_key: '',
  default_owner_role: '',
  is_active: true,
}

const form = useForm({ ...blank })

const editing = computed(() => !!props.source)

watch(() => props.show, open => {
  form.clearErrors()

  if (!open) return

  Object.assign(form, props.source
    ? {
        ...blank,
        label: props.source.label,
        default_stage_key: props.source.default_stage_key ?? '',
        default_owner_role: props.source.default_owner_role ?? '',
        is_active: props.source.is_active,
      }
    : { ...blank })
})

const keyPreview = computed(() =>
  (form.label || '')
    .toLowerCase()
    .replace(/['’]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 50) || '—')

const submit = () => {
  /*
   | Empty string means "no default", and the column is nullable — posting ''
   | into a `nullable|in:` rule fails on the second half of it. Sent as null so
   | the rule sees the absence the user actually chose.
   */
  const done = { preserveScroll: true, onSuccess: () => emit('close') }

  form
    .transform(data => ({
      ...data,
      default_stage_key: data.default_stage_key || null,
      default_owner_role: data.default_owner_role || null,
    }))
    [editing.value ? 'put' : 'post'](
      editing.value
        ? route('pipeline.sources.update', props.source.id)
        : route('pipeline.sources.store'),
      done,
    )
}
</script>

<template>
  <Modal :show="show" :title="editing ? 'Edit source' : 'Add source'" max-width="max-w-lg" @close="emit('close')">
    <div class="space-y-4">

      <FormField label="Name" required :error="form.errors.label">
        <input v-model="form.label" type="text" maxlength="60" placeholder="e.g. Property portal" />
      </FormField>

      <FormField
        label="Key"
        :hint="editing
          ? 'Fixed. Every lead from this source is stored against this word.'
          : 'Made from the name. It cannot be changed afterwards.'"
      >
        <div class="rounded-lg bg-slate-50 dark:bg-slate-900/60 px-3 py-2 font-mono text-sm text-slate-500 dark:text-slate-400">
          {{ editing ? source.key : keyPreview }}
        </div>
      </FormField>

      <FormField label="Default stage" :error="form.errors.default_stage_key"
                 hint="Where a lead from this source is expected to start. Recorded here; the Add lead form does not read it yet.">
        <select v-model="form.default_stage_key">
          <option value="">No default</option>
          <option v-for="(label, key) in options.activeStages" :key="key" :value="key">{{ label }}</option>
        </select>
      </FormField>

      <FormField label="Default owner" :error="form.errors.default_owner_role"
                 hint="Which desk these leads belong to. Recorded here; nothing assigns on it yet.">
        <select v-model="form.default_owner_role">
          <option value="">No default</option>
          <option v-for="(label, key) in options.roles" :key="key" :value="key">{{ label }}</option>
        </select>
      </FormField>

      <FormField v-if="editing" label="In use" :error="form.errors.is_active"
                 :hint="source?.cannot_deactivate
                   || 'Switching a source off hides it from the dropdowns. Every lead already filed under it keeps it, and every past report still counts it.'">
        <label class="flex items-center gap-2 text-sm"
               :class="source?.cannot_deactivate ? 'cursor-not-allowed opacity-50' : ''">
          <input v-model="form.is_active" type="checkbox" :disabled="!!source?.cannot_deactivate" />
          <span>Offer this source in the dropdowns</span>
        </label>
      </FormField>

      <p v-if="editing && source.rules.length && form.is_active === false"
         class="rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
        {{ source.rules.length === 1 ? 'An automation rule uses' : 'Automation rules use' }}
        this source: {{ source.rules.join(', ') }}.
        {{ source.rules.length === 1 ? 'It' : 'They' }} will stop matching new leads while the source is off.
      </p>

    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : 'Save' }}
      </button>
    </template>
  </Modal>
</template>
