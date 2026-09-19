<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | The Follow-up page's "Switch project" action — moves a lead to a different
 | project without closing it or duplicating it, from the follow-up row
 | itself rather than the lead detail modal. See
 | LeadFollowUpService::switchProject().
 |
 | Every role that can see this follow-up can use this: LeadPolicy::
 | switchProject() carries no project boundary of its own, unlike Reassign —
 | the target here is a project, not a person tied to one.
 */
const props = defineProps({ show: Boolean, todo: Object, options: Object })
const emit = defineEmits(['close'])

const form = useForm({ project_id: '' })

watch(() => props.show, open => {
  form.clearErrors()
  form.project_id = ''
})

const lead = computed(() => props.todo?.lead ?? null)

// every active project except the one the lead is already on — there is
// nothing to switch to otherwise
const choices = computed(() =>
  (props.options?.projects ?? []).filter(p => p.id !== lead.value?.project_id))

const submit = () => {
  if (!form.project_id || !lead.value) return

  form.put(route('leads.switch-project', lead.value.id), {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :show="show" title="Switch project" max-width="max-w-md" @close="emit('close')">
    <div v-if="lead" class="space-y-4">
      <p class="text-sm text-slate-600">
        Move <span class="font-semibold">{{ lead.full_name }}</span> from
        <span class="font-semibold">{{ lead.project?.name ?? 'its current project' }}</span> to a different
        project. The lead stays open, at the same stage, and is reassigned to whoever owns leads at that
        stage on the new project.
      </p>

      <FormField label="New project" required :error="form.errors.project_id">
        <select v-model="form.project_id">
          <option value="" disabled>Choose a project…</option>
          <option v-for="p in choices" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </FormField>

      <p v-if="!choices.length" class="text-xs text-slate-400">
        No other active projects to switch this lead to.
      </p>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing || !form.project_id" @click="submit">
        {{ form.processing ? 'Switching…' : 'Switch project' }}
      </button>
    </template>
  </Modal>
</template>
