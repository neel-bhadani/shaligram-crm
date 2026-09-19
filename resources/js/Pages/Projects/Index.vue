<script setup>
import { ref, reactive, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ProjectFormModal from '@/Components/ProjectFormModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'

/*
 | The developments this company is selling. Admin only — `role:admin` on the
 | route group is what enforces that; nothing on this page is a permission
 | check, it is all presentation of one the server already made.
 |
 | The two count columns are the reason this is a table and not a list of
 | names. Leads is how much business a project has attracted; Bookings is how
 | much it has closed, and the gap between them is the only thing an admin
 | glancing at this page actually wants to know. Both are all-time — a project
 | is a multi-year thing and a 30-day window would make a finished development
 | look dead.
 */
const props = defineProps({ projects: Object, filters: Object, options: Object })

const f = reactive({
  search: props.filters.search ?? '',
  status: props.filters.status ?? '',
})

const { visit } = useFilterVisit(route('projects.index'))

// reset=1 with every key the page owns: the request is the whole instruction,
// the same contract the Leads, To-do and Users pages use
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
const openEdit = p => { editing.value = p; formOpen.value = true }

/*
 | Delete is offered only for a project no lead has ever pointed at, and the
 | button carries the reason when it is not. The server refuses regardless —
 | ProjectController::destroy() re-counts and blocks — so this is the
 | explanation, not the guarantee. Disabling a button the server would reject
 | anyway is the difference between a reason and a dead end.
 */
/*
 | Two ways a project can be undeletable, and they need different sentences.
 |
 | The ordinary one is that it has leads. The other is that its leads have all
 | been deleted — the count column reads 0, but those leads are restorable and
 | would come back to a project that was no longer there, so the server refuses
 | on that too. "0 leads are filed against this project" as the reason a delete
 | is blocked would read as a bug.
 */
const lockReason = p => {
  if (p.deletable) return ''

  return p.leads_count > 0
    ? `${p.leads_count} lead${p.leads_count === 1 ? '' : 's'} are filed against this project. `
      + 'Switch it off instead — that stops new leads and keeps the history.'
    : 'Leads that were deleted still point at this project and could be restored. '
      + 'Switch it off instead.'
}

const confirmDelete = () => {
  router.delete(route('projects.destroy', deleting.value.id), {
    preserveScroll: true,
    onFinish: () => { deleting.value = null },
  })
}

/* one click from the list, for the common case of retiring a project */
const toggleActive = p => router.put(route('projects.update', p.id), {
  name: p.name,
  location: p.location,
  type: p.type,
  description: p.description,
  is_active: !p.is_active,
}, { preserveScroll: true })

const conversion = p => p.leads_count > 0
  ? `${Math.round(p.bookings_count / p.leads_count * 1000) / 10}%`
  // an em dash, never 0%: a project with no leads has no conversion rate
  : '—'
</script>

<template>
  <Head title="Projects" />

  <AppLayout title="Projects" subtitle="The developments every lead is filed against">
    <template #actions>
      <button class="btn w-full sm:w-auto" @click="openAdd">Add project</button>
    </template>

    <div class="card overflow-hidden">

      <div class="flex flex-wrap gap-2 border-b border-slate-100 dark:border-slate-700/60 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search by name"
               class="w-full md:!w-72" />

        <select v-model="f.status" class="w-full md:!w-40" aria-label="Status">
          <option value="">All statuses</option>
          <option value="active">Selling now</option>
          <option value="inactive">Inactive</option>
        </select>

        <button class="btn-ghost w-full md:w-auto" @click="clearFilters">Clear</button>
      </div>

      <!--
        Two empty states, because they mean opposite things. Nothing at all is
        somebody's first day and needs telling what a project is for; nothing
        matching is a filter to clear.
      -->
      <div v-if="!projects.data.length" class="px-5 py-14 text-center">
        <template v-if="f.search || f.status">
          <p class="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-300">No projects match</p>
          <p class="text-sm text-slate-500 dark:text-slate-400">Try clearing the filters.</p>
        </template>
        <template v-else>
          <p class="mb-1 text-base font-semibold text-slate-800 dark:text-slate-200">No projects yet</p>
          <p class="mx-auto max-w-lg text-sm leading-relaxed text-slate-500 dark:text-slate-400">
            A project is a development you are selling. Every lead is filed against one, so at
            least one has to exist before anybody can add a lead.
          </p>
          <button class="btn mt-5" @click="openAdd">Add your first project</button>
        </template>
      </div>

      <!-- table on desktop, cards on mobile: the same pattern as Users and To-do -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 dark:bg-slate-900/60 text-left text-xs text-slate-500 dark:text-slate-400">
            <th class="px-4 py-2.5 font-semibold">Name</th>
            <th class="px-4 py-2.5 font-semibold">Location</th>
            <th class="px-4 py-2.5 font-semibold">Type</th>
            <th class="px-4 py-2.5 font-semibold">Status</th>
            <th class="px-4 py-2.5 font-semibold">Leads</th>
            <th class="px-4 py-2.5 font-semibold">Bookings</th>
            <th class="px-4 py-2.5 font-semibold">Conversion</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in projects.data" :key="p.id" class="border-b border-slate-100 dark:border-slate-700/60"
              :class="p.is_active ? '' : 'bg-slate-50/60'">
            <td class="px-4 py-3">
              <Link :href="route('projects.show', p.id)"
                    class="font-semibold hover:text-teal-700 hover:underline"
                    :class="p.is_active ? '' : 'text-slate-500 dark:text-slate-400'">{{ p.name }}</Link>
            </td>
            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ p.location ?? '—' }}</td>
            <td class="px-4 py-3">{{ p.type_label }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="p.is_active
                      ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300'
                      : 'bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300'">
                {{ p.is_active ? 'Selling now' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 tabular-nums">{{ p.leads_count }}</td>
            <td class="px-4 py-3 tabular-nums">{{ p.bookings_count }}</td>
            <td class="px-4 py-3 tabular-nums text-slate-500 dark:text-slate-400">{{ conversion(p) }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5">
                <Link :href="route('projects.show', p.id)" class="btn-xs">View</Link>
                <button class="btn-xs" @click="openEdit(p)">Edit</button>
                <button class="btn-xs" @click="toggleActive(p)">
                  {{ p.is_active ? 'Switch off' : 'Switch on' }}
                </button>
                <button class="btn-xs disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!p.deletable" :title="lockReason(p)"
                        @click="deleting = p">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="projects.data.length" class="divide-y divide-slate-100 dark:divide-slate-700/60 lg:hidden">
        <div v-for="p in projects.data" :key="p.id" class="p-4"
             :class="p.is_active ? '' : 'bg-slate-50/60'">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <Link :href="route('projects.show', p.id)" class="block truncate font-semibold">
                {{ p.name }}
              </Link>
              <div class="truncate text-xs text-slate-400">{{ p.location ?? 'No location' }}</div>
            </div>
            <span class="flex-none rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="p.is_active ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300'">
              {{ p.is_active ? 'Selling' : 'Inactive' }}
            </span>
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Type</dt>
            <dd class="text-right">{{ p.type_label }}</dd>
            <dt class="text-slate-400">Leads</dt>
            <dd class="text-right tabular-nums">{{ p.leads_count }}</dd>
            <dt class="text-slate-400">Bookings</dt>
            <dd class="text-right tabular-nums">{{ p.bookings_count }}</dd>
            <dt class="text-slate-400">Conversion</dt>
            <dd class="text-right tabular-nums">{{ conversion(p) }}</dd>
          </dl>

          <div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 dark:border-slate-700/60 pt-3">
            <Link :href="route('projects.show', p.id)" class="btn-xs flex-1 text-center">View</Link>
            <button class="btn-xs flex-1" @click="openEdit(p)">Edit</button>
            <button class="btn-xs flex-1" @click="toggleActive(p)">
              {{ p.is_active ? 'Switch off' : 'Switch on' }}
            </button>
            <button class="btn-xs flex-1 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!p.deletable" :title="lockReason(p)"
                    @click="deleting = p">Delete</button>
          </div>
        </div>
      </div>

      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
        <span>{{ projects.total }} project{{ projects.total === 1 ? '' : 's' }}</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in projects.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <ProjectFormModal :show="formOpen" :project="editing" :options="options"
                      @close="formOpen = false" />

    <ConfirmDialog
      :show="!!deleting"
      title="Delete this project?"
      :message="`“${deleting?.name}” has never had a lead filed against it, so nothing is lost by removing it. You can switch it off instead if you might sell it later.`"
      confirm-text="Delete project"
      @close="deleting = null"
      @confirm="confirmDelete"
    />
  </AppLayout>
</template>
