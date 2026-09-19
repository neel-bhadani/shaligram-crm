<script setup>
import { ref, reactive, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import UserFormModal from '@/Components/UserFormModal.vue'
import DeleteUserDialog from '@/Components/DeleteUserDialog.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'

/*
 | Staff management, admin only. `role:admin` on the route group is what
 | enforces that — nothing on this page is a permission check, it is all
 | presentation of one the server already made.
 |
 | The two count columns are the reason this is a table and not a list of
 | names. An admin about to switch somebody off has to see what that person is
 | holding before they click, because that is the number the handover dialog
 | will then ask them to place. Both are live work — open leads and pending
 | tasks — never lifetime totals, which would be a bigger, less useful number.
 */
const props = defineProps({ users: Object, filters: Object, options: Object })

const f = reactive({
  search: props.filters.search ?? '',
  role: props.filters.role ?? '',
  status: props.filters.status ?? '',
})

const { visit } = useFilterVisit(route('users.index'))

// reset=1 with every key the page owns: the request is the whole instruction,
// the same contract the Leads and To-do pages use
const push = () => visit({ reset: 1, ...f })

const filters = useDebouncedFilters(f, push)

const clearFilters = () => {
  filters.silently(() => Object.keys(f).forEach(k => (f[k] = '')))
  filters.cancel()
  push()
}

/* modals */
const formOpen = ref(false)
const editing = ref(null)
const deleting = ref(null)

const openAdd = () => { editing.value = null; formOpen.value = true }
const openEdit = u => { editing.value = u; formOpen.value = true }
const openDelete = u => { deleting.value = u }

const deleteOpen = computed({
  get: () => deleting.value !== null,
  set: v => { if (!v) deleting.value = null },
})

/*
 | The two rows that cannot be removed, greyed here and refused again by
 | DeleteUserRequest. Disabling a button the server would reject anyway is not
 | duplication — it is the difference between an explanation and a dead end.
 */
const isSelf = u => u.id === props.options.currentUserId
const isLastAdmin = u => u.role === 'admin' && u.is_active && props.options.activeAdminCount <= 1

const lockReason = u =>
  isSelf(u) ? 'This is your own account.'
    : isLastAdmin(u) ? 'The last active admin cannot be removed.'
      : ''

/*
 | "Role defaults" vs "Custom". Whether a user's permissions were ever set by
 | hand is worth a glance from the list, because a role no longer tells the
 | whole story once one person has been given the run of the pipeline.
 */
const permissionNote = u => {
  if (!u.has_custom_permissions) return 'Role defaults'
  const on = Object.values(u.permissions).filter(Boolean).length
  return `Custom · ${on} of ${Object.keys(props.options.permissions).length} on`
}

/*
 | Sign-ups. One badge per row saying where the account stands, and two
 | buttons on the rows that are waiting for an answer.
 |
 | A pending account is switched off like a deactivated one, but it is a
 | different thing — nobody ever agreed to have them — so it gets its own
 | colour and its own words rather than reading as "Inactive".
 */
const statusBadge = u => ({
  pending:  { label: 'Pending approval', cls: 'bg-amber-100 dark:bg-amber-500/15 text-amber-900 dark:text-amber-200 ring-1 ring-amber-300' },
  rejected: { label: 'Rejected', cls: 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-300' },
}[u.approval_status] ?? (u.is_active
  ? { label: 'Active', cls: 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' }
  : { label: 'Inactive', cls: 'bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300' }))

// waiting rows are warm so they stand out; switched-off ones recede
const rowTint = u =>
  u.approval_status === 'pending' ? 'bg-amber-50/50' : (u.is_active ? '' : 'bg-slate-50/60')

// a rejected request can still be approved later; an approved one is edited
const canApprove = u => u.approval_status === 'pending' || u.approval_status === 'rejected'
const canReject = u => u.approval_status === 'pending'

const answering = ref(null)

const answer = (u, verb) => {
  answering.value = u.id
  router.post(route(`users.${verb}`, u.id), {}, {
    preserveScroll: true,
    onFinish: () => { answering.value = null },
  })
}

const showPending = () => {
  filters.silently(() => { Object.keys(f).forEach(k => (f[k] = '')); f.status = 'pending' })
  filters.cancel()
  push()
}
</script>

<template>
  <Head title="Users" />

  <AppLayout title="Users" subtitle="Staff accounts and what they can reach">
    <template #actions>
      <button class="btn w-full sm:w-auto" @click="openAdd">Add user</button>
    </template>

    <!--
      Whatever the filters are showing. A request that nobody answers is a
      person who cannot start work, and the alert that announced it may have
      been read and forgotten.
    -->
    <div v-if="options.pendingCount && f.status !== 'pending'"
         class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 dark:border-amber-500/30
                bg-amber-50 dark:bg-amber-500/10 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
      <span>
        <b>{{ options.pendingCount }}</b>
        account request{{ options.pendingCount === 1 ? ' is' : 's are' }} waiting for approval.
      </span>
      <button class="btn-xs !border-amber-300 dark:border-amber-500/40 !text-amber-900 dark:text-amber-200" @click="showPending">Show them</button>
    </div>

    <div class="card overflow-hidden">

      <div class="flex flex-wrap gap-2 border-b border-slate-100 dark:border-slate-700/60 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search name, email or mobile"
               class="w-full md:!w-72" />

        <select v-model="f.role" class="w-full md:!w-44" aria-label="Role">
          <option value="">All roles</option>
          <option v-for="(label, key) in options.roleLabels" :key="key" :value="key">{{ label }}</option>
        </select>

        <select v-model="f.status" class="w-full md:!w-40" aria-label="Status">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="pending">Pending approval</option>
          <option value="rejected">Rejected</option>
        </select>

        <button class="btn-ghost w-full md:w-auto" @click="clearFilters">Clear</button>
      </div>

      <div v-if="!users.data.length" class="px-5 py-14 text-center text-sm text-slate-500 dark:text-slate-400">
        <p class="mb-1 font-semibold text-slate-700 dark:text-slate-300">No users match</p>
        Try clearing the filters.
      </div>

      <!-- table on desktop, cards on mobile: the same pattern as To-do -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 dark:bg-slate-900/60 text-left text-xs text-slate-500 dark:text-slate-400">
            <th class="px-4 py-2.5 font-semibold">Name</th>
            <th class="px-4 py-2.5 font-semibold">Contact</th>
            <th class="px-4 py-2.5 font-semibold">Role</th>
            <th class="px-4 py-2.5 font-semibold">Status</th>
            <th class="px-4 py-2.5 font-semibold">Open leads</th>
            <th class="px-4 py-2.5 font-semibold">Pending follow-ups</th>
            <th class="px-4 py-2.5 font-semibold">Permissions</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users.data" :key="u.id" class="border-b border-slate-100 dark:border-slate-700/60"
              :class="rowTint(u)">
            <td class="px-4 py-3">
              <div class="font-semibold" :class="u.is_active || u.approval_status === 'pending' ? '' : 'text-slate-500 dark:text-slate-400'">
                {{ u.display_name }}
              </div>
              <div v-if="isSelf(u)" class="text-xs text-slate-400">You</div>
            </td>
            <td class="px-4 py-3">
              <div>{{ u.email }}</div>
              <div class="text-xs tabular-nums text-slate-400">{{ u.mobile_number }}</div>
            </td>
            <td class="px-4 py-3">{{ options.roleLabels[u.role] ?? u.role }}</td>
            <td class="px-4 py-3">
              <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge(u).cls">
                {{ statusBadge(u).label }}
              </span>
              <div v-if="u.approval_status === 'pending'" class="mt-1 text-xs text-slate-400">
                Signed up {{ u.signed_up_on }}
              </div>
            </td>
            <!-- the two numbers a handover would have to move -->
            <td class="px-4 py-3 tabular-nums">{{ u.open_leads_count }}</td>
            <td class="px-4 py-3 tabular-nums">{{ u.pending_todos_count }}</td>
            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ permissionNote(u) }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5">
                <button v-if="canApprove(u)" class="btn-xs !border-teal-600 !bg-teal-700 !text-white hover:!bg-teal-800"
                        :disabled="answering === u.id" @click="answer(u, 'approve')">Approve</button>
                <button v-if="canReject(u)" class="btn-xs hover:!border-rose-600 hover:!text-rose-700 dark:text-rose-300"
                        :disabled="answering === u.id" @click="answer(u, 'reject')">Reject</button>
                <button class="btn-xs" @click="openEdit(u)">Edit</button>
                <button class="btn-xs disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!!lockReason(u)" :title="lockReason(u)"
                        @click="openDelete(u)">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="users.data.length" class="divide-y divide-slate-100 dark:divide-slate-700/60 lg:hidden">
        <div v-for="u in users.data" :key="u.id" class="p-4"
             :class="rowTint(u)">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="truncate font-semibold">{{ u.display_name }}</div>
              <div class="truncate text-xs text-slate-400">{{ u.email }}</div>
            </div>
            <span class="flex-none rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge(u).cls">
              {{ statusBadge(u).label }}
            </span>
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Role</dt>
            <dd class="text-right">{{ options.roleLabels[u.role] ?? u.role }}</dd>
            <dt class="text-slate-400">Mobile</dt>
            <dd class="text-right tabular-nums">{{ u.mobile_number }}</dd>
            <dt class="text-slate-400">Open leads</dt>
            <dd class="text-right tabular-nums">{{ u.open_leads_count }}</dd>
            <dt class="text-slate-400">Pending follow-ups</dt>
            <dd class="text-right tabular-nums">{{ u.pending_todos_count }}</dd>
            <dt class="text-slate-400">Permissions</dt>
            <dd class="text-right">{{ permissionNote(u) }}</dd>
            <template v-if="u.approval_status === 'pending'">
              <dt class="text-slate-400">Signed up</dt>
              <dd class="text-right">{{ u.signed_up_on }}</dd>
            </template>
          </dl>

          <div v-if="canApprove(u)" class="mt-3 flex gap-2 border-t border-slate-100 dark:border-slate-700/60 pt-3">
            <button class="btn-xs flex-1 !border-teal-600 !bg-teal-700 !text-white"
                    :disabled="answering === u.id" @click="answer(u, 'approve')">Approve</button>
            <button v-if="canReject(u)" class="btn-xs flex-1"
                    :disabled="answering === u.id" @click="answer(u, 'reject')">Reject</button>
          </div>

          <div class="mt-3 flex gap-2 border-t border-slate-100 dark:border-slate-700/60 pt-3">
            <button class="btn-xs flex-1" @click="openEdit(u)">Edit</button>
            <button class="btn-xs flex-1 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!!lockReason(u)" :title="lockReason(u)"
                    @click="openDelete(u)">Delete</button>
          </div>
        </div>
      </div>

      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
        <span>{{ users.total }} user{{ users.total === 1 ? '' : 's' }}</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in users.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <UserFormModal :show="formOpen" :user="editing" :options="options" @close="formOpen = false" />
    <DeleteUserDialog :show="deleteOpen" :user="deleting" :options="options" @close="deleteOpen = false" />
  </AppLayout>
</template>
