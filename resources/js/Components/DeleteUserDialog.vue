<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import UserHandoverFields from './UserHandoverFields.vue'

/*
 | The delete confirmation, which is a soft delete and says so.
 |
 | ConfirmDialog is not enough here: this one has to carry a decision, not just
 | a yes. A user holding open leads cannot be removed without saying where the
 | work goes, so the same handover block the edit modal uses appears inside the
 | confirmation itself — one dialog, one submit, one transaction on the server.
 */
const props = defineProps({ show: Boolean, user: Object, options: Object })
const emit = defineEmits(['close'])

const form = useForm({ handover_to: '', leave_unassigned: false })

watch(() => props.show, v => {
  if (!v) return
  form.reset()
  form.clearErrors()
})

const workload = computed(() => ({
  leads: props.user?.open_leads_count ?? 0,
  todos: props.user?.pending_todos_count ?? 0,
}))

const holdsWork = computed(() => workload.value.leads + workload.value.todos > 0)

const candidates = computed(() =>
  props.options.assignable.filter(u => u.role === props.user?.role && u.id !== props.user?.id))

const submit = () => form.delete(route('users.destroy', props.user.id), {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})
</script>

<template>
  <Modal :show="show" title="Delete user" max-width="max-w-lg" @close="emit('close')">
    <p class="text-sm text-slate-600 dark:text-slate-300">
      <span class="font-semibold text-slate-800 dark:text-slate-200">{{ user?.display_name }}</span>
      will no longer be able to sign in.
    </p>

    <!--
      Said out loud, because "delete" in most applications does not mean this.
      Their completed calls are what the Completed tab and every dashboard chart
      are counted from; removing the row would rewrite history rather than hide
      a person.
    -->
    <p class="info-box mt-3">
      This is a soft delete. Their completed calls and the leads they have worked stay
      exactly as they are, so the history behind every chart is untouched.
    </p>

    <UserHandoverFields
      v-if="holdsWork"
      v-model:target="form.handover_to"
      v-model:confirmed="form.leave_unassigned"
      class="mt-4"
      :user="user" :workload="workload" :candidates="candidates"
      :error="form.errors.handover_to"
      verb="deleted"
    />

    <p v-else class="mt-3 text-sm text-slate-500 dark:text-slate-400">
      They hold no open leads and no pending follow-ups, so there is nothing to hand over.
    </p>

    <!-- the two refusals the server can still come back with: deleting
         yourself, and deleting the last active admin -->
    <p v-if="form.errors.user" class="mt-3 text-sm font-medium text-rose-700 dark:text-rose-300">{{ form.errors.user }}</p>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn-danger flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Working…' : 'Delete user' }}
      </button>
    </template>
  </Modal>
</template>
