<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | Add and edit a project, one modal for both — the same shape as
 | LeadFormModal and UserFormModal, so every form in this application behaves
 | identically.
 |
 | The one thing here that is not in the other two is what switching a project
 | off actually means, said on the form at the moment the admin is deciding.
 | "Inactive" is a word that could mean anything; the line under the toggle says
 | which of the two possible things it means — no new leads, all history kept —
 | because the alternative is an admin who deactivates a project and then goes
 | looking for the leads they think they have just hidden.
 */
const props = defineProps({ show: Boolean, project: Object, options: Object })
const emit = defineEmits(['close'])

const editing = computed(() => !!props.project)

const blank = {
  name: '',
  location: '',
  type: 'residential',
  description: '',
  is_active: true,
}

const form = useForm({ ...blank })

watch(() => props.show, open => {
  form.clearErrors()

  if (!open) return

  Object.assign(form, props.project
    ? {
        ...blank,
        name: props.project.name ?? '',
        location: props.project.location ?? '',
        type: props.project.type ?? 'residential',
        description: props.project.description ?? '',
        is_active: props.project.is_active,
      }
    : { ...blank })
})

/*
 | Only warn on the transition, not on every edit of a project that is already
 | switched off. A warning that is permanently on screen is furniture.
 */
const deactivating = computed(() => editing.value && props.project?.is_active && !form.is_active)

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }

  editing.value
    ? form.put(route('projects.update', props.project.id), options)
    : form.post(route('projects.store'), options)
}
</script>

<template>
  <Modal :show="show" :title="editing ? 'Edit project' : 'Add project'" max-width="max-w-xl"
         @close="emit('close')">
    <div class="space-y-4">

      <FormField label="Project name" required :error="form.errors.name"
                 hint="How it appears on every lead, report and dropdown.">
        <input v-model="form.name" type="text" class="w-full" maxlength="120" />
      </FormField>

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Location" :error="form.errors.location"
                   hint="Area and city, as a customer would say it.">
          <input v-model="form.location" type="text" class="w-full" maxlength="160"
                 placeholder="Vesu, Surat" />
        </FormField>

        <FormField label="Type" required :error="form.errors.type">
          <select v-model="form.type" class="w-full">
            <option v-for="(label, key) in options.types" :key="key" :value="key">{{ label }}</option>
          </select>
        </FormField>
      </div>

      <FormField label="Description" :error="form.errors.description"
                 hint="Optional. Configurations, possession date — whatever staff need when a lead asks.">
        <textarea v-model="form.description" rows="3" class="w-full" maxlength="2000" />
      </FormField>

      <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
        <input v-model="form.is_active" type="checkbox" class="mt-0.5 h-4 w-4" />
        <span>
          Selling now
          <span class="block text-xs text-slate-400">
            Switched off, the project disappears from the Add lead form. Every lead already
            filed against it, and all its history, stays exactly as it is.
          </span>
        </span>
      </label>

      <p v-if="deactivating" class="warn-box">
        <strong>This will switch “{{ project.name }}” off.</strong>
        Nobody will be able to file a new lead against it. Its existing leads, follow-ups and
        reports are untouched, and you can switch it back on at any time.
      </p>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (editing ? 'Save changes' : 'Add project') }}
      </button>
    </template>
  </Modal>
</template>
