<script setup>
import { computed, watch } from 'vue'

/*
 | "Who takes this over?" — the block that appears whenever a user holding live
 | work is about to be switched off or removed.
 |
 | One component, two hosts: the edit modal shows it when a save deactivates
 | somebody, the delete dialog shows it always. They are the same decision with
 | the same consequences, and HandsOverWork validates them with the same rules,
 | so they must not be two pieces of markup that drift.
 |
 | The two options are not symmetrical and the copy says so. Reassigning moves
 | leads and tasks together. Leaving them unassigned takes the leads off
 | everybody's list — which is a real choice an admin may want — but their
 | pending tasks cannot follow, because `todos.assigned_to` is NOT NULL. Those
 | come to the admin doing this. That sentence is on screen before the button
 | is pressed rather than in a flash message afterwards.
 */
const props = defineProps({
  user: Object,
  workload: Object,        // { leads, todos }
  candidates: Array,       // active users of the same role
  error: String,
  /** 'deactivated' or 'deleted' — the only word that differs between hosts. */
  verb: { type: String, default: 'removed' },
})

const target = defineModel('target')
const confirmed = defineModel('confirmed', { type: Boolean })

const line = computed(() => {
  const { leads, todos } = props.workload
  const parts = []
  if (leads) parts.push(`${leads} open ${leads === 1 ? 'lead' : 'leads'}`)
  if (todos) parts.push(`${todos} pending ${todos === 1 ? 'follow-up' : 'follow-ups'}`)
  return parts.join(' and ')
})

// the two controls are alternatives; picking a person retracts the
// leave-unassigned confirmation rather than sending both
watch(target, v => { if (v) confirmed.value = false })
watch(confirmed, v => { if (v) target.value = '' })
</script>

<template>
  <div class="rounded-lg border border-amber-200 dark:border-amber-500/30 bg-amber-50/60 p-4">
    <div class="text-xs font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">
      This user's work
    </div>

    <p class="mt-1.5 text-sm text-amber-900 dark:text-amber-200">
      {{ user.display_name }} holds {{ line }}. Decide where {{ workload.leads ? 'they' : 'it' }}
      go{{ workload.leads ? '' : 'es' }} before this account is {{ verb }}.
    </p>

    <label class="mt-3 block text-xs font-semibold text-slate-600 dark:text-slate-300">Reassign to</label>
    <select v-model="target" class="mt-1.5">
      <option value="">Select a person…</option>
      <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }}</option>
    </select>

    <!--
      Said plainly rather than left as an empty dropdown. Same role is a server
      rule, and an admin staring at "Select a person…" with nothing under it
      deserves to know why rather than assume the page is broken.
    -->
    <p v-if="!candidates.length" class="mt-1.5 text-xs text-amber-900 dark:text-amber-200">
      Nobody else active does this job, so leaving the leads unassigned is the only option here.
    </p>

    <div class="mt-3 border-t border-amber-200 dark:border-amber-500/30 pt-3">
      <label class="flex cursor-pointer items-start gap-2.5">
        <input v-model="confirmed" type="checkbox" class="mt-0.5 h-4 w-4 flex-none rounded border-slate-300 dark:border-slate-600" />
        <span class="text-xs leading-relaxed text-amber-900 dark:text-amber-200">
          Leave the leads unassigned. They will appear on nobody's list until somebody is
          given them.
          <template v-if="workload.todos">
            Their {{ workload.todos }} pending
            {{ workload.todos === 1 ? 'follow-up moves' : 'follow-ups move' }} to you, so nothing
            drops off the schedule.
          </template>
        </span>
      </label>
    </div>

    <p v-if="error" class="mt-2 text-xs font-medium text-rose-700 dark:text-rose-300">{{ error }}</p>
  </div>
</template>
