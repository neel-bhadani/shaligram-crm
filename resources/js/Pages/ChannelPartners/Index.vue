<script setup>
import { ref, reactive, computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChannelPartnerFormModal from '@/Components/ChannelPartnerFormModal.vue'
import DeleteChannelPartnerDialog from '@/Components/DeleteChannelPartnerDialog.vue'
import MergeChannelPartnerDialog from '@/Components/MergeChannelPartnerDialog.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'

/*
 | The channel partner roster. Reading it is open to every signed-in role —
 | `auth` on the route group is the whole door, and ChannelPartnerPolicy::viewAny
 | says it again — and editing and merging are the same crowd: the PUT this
 | page's Edit button saves to and the POST /merge this page's Merge button
 | sends both sit on the same group, with ChannelPartnerPolicy::update and
 | ::merge repeating them. Only delete stays admin-only, and the boundary is
 | the route, not the button: DELETE is behind `role:admin` in routes/web.php,
 | destroy() is double-locked through ChannelPartnerPolicy::delete, and a
 | non-admin who types that request directly gets a 403 before anything
 | changes. The buttons below are drawn accordingly — Edit and Merge for
 | everyone, Delete for an admin — which is presentation matching
 | already-working routes.
 |
 | THERE IS NO ADD BUTTON, and its absence is the design. A partner is created
 | from inside the Add lead modal, at the moment somebody is logging the lead
 | that came through them — that is the only moment anybody actually knows the
 | broker's name and number, and there is no POST route to this page's
 | controller at all. A second Add here would produce rows an admin typed from
 | memory a day later and never attributed a lead to. The notice above the table
 | says so, because a management screen with no way to add anything reads as a
 | missing button unless it explains itself.
 |
 | What this page is for instead: filling in the details the four-field inline
 | form deliberately did not ask for, switching partners off, deleting them, and
 | MERGING the duplicates that creating rows mid-call inevitably produces. That
 | last one is not optional — see MergeChannelPartnerDialog.
 |
 | FIRMS AND BROKERS ARE NOT DRAWN AS A TREE, and that was a choice. A firm
 | heading with its brokers indented under it reads beautifully on a whiteboard
 | and badly on this page: the list is paginated, so a group can straddle two
 | pages; it fights the search box and the status filter, which routinely leave
 | a heading with one row or none under it; and an individual broker belongs to
 | no firm at all, so the tree needs a fourth branch meaning "no firm", which is
 | a filter wearing a costume.
 |
 | So the relationship is a COLUMN and a FILTER. Every broker names its firm in
 | the Parent firm column. Every firm says how many brokers it holds, and that
 | count is a link: clicking it filters the list to that firm's brokers, which
 | is the grouped view on demand, composable with everything else on the bar and
 | unbothered by pagination.
 |
 | The lead count is the column this screen exists for. It is what the client
 | asked the whole feature for — which broker actually brings business — and the
 | report under Reports · Leads · By channel partner is the same number with the
 | site visits, bookings and conversion beside it.
 */
const props = defineProps({ partners: Object, filters: Object, options: Object })

const page = usePage()
const isAdmin = computed(() => page.props.auth.user?.role === 'admin')

const f = reactive({
  search: props.filters.search ?? '',
  type: props.filters.type ?? '',
  status: props.filters.status ?? '',
  parent_id: props.filters.parent_id ?? '',
})

const { visit } = useFilterVisit(route('channel-partners.index'))

// reset=1 with every key the page owns: the request is the whole instruction,
// the same contract the Leads, To-do and Users pages use
const push = () => visit({ reset: 1, ...f })

const filters = useDebouncedFilters(f, push)

const clearFilters = () => {
  filters.silently(() => Object.keys(f).forEach(k => (f[k] = '')))
  filters.cancel()
  push()
}

/*
 | "Brokers of this firm", from a click on the firm's own broker count. It sets
 | the type filter too, so the firm itself is not sitting at the top of a list
 | that says it is showing that firm's brokers.
 */
const showBrokersOf = firm => {
  filters.silently(() => {
    f.parent_id = firm.id
    f.type = 'broker'
    f.status = ''
  })
  filters.cancel()
  push()
}

/** The firm the parent filter is currently pinned to, for the notice above the table. */
const pinnedFirm = computed(() =>
  props.options.firms.find(x => String(x.id) === String(f.parent_id)) ?? null)

const clearParent = () => {
  filters.silently(() => { f.parent_id = ''; f.type = '' })
  filters.cancel()
  push()
}

/* modals */
const formOpen = ref(false)
const editing = ref(null)
const deleting = ref(null)
const merging = ref(null)

// no openAdd: this page does not create partners — see the note at the top
const openEdit = p => { editing.value = p; formOpen.value = true }
const openDelete = p => { deleting.value = p }
const openMerge = p => { merging.value = p }

const deleteOpen = computed({
  get: () => deleting.value !== null,
  set: v => { if (!v) deleting.value = null },
})

const mergeOpen = computed({
  get: () => merging.value !== null,
  set: v => { if (!v) merging.value = null },
})

/*
 | Why a delete is greyed out, or '' when it is not. The server refuses the same
 | case in ChannelPartnerController::destroy(), so this is the explanation and
 | not the guard.
 */
const lockReason = p =>
  p.type === 'firm' && p.active_brokers_count > 0
    ? `${p.active_brokers_count} active broker${p.active_brokers_count === 1 ? '' : 's'} `
      + 'are filed under this firm. Reassign or deactivate them first.'
    : ''
</script>

<template>
  <Head title="Channel Partners" />

  <AppLayout title="Channel Partners" subtitle="The firms and brokers your leads come through">
    <!--
      No #actions slot. Creation is not available from this page — the header
      would otherwise carry an Add button that has no route behind it.
    -->

    <p class="info-box mb-4">
      Partners are added while logging a lead, not from here. On the Leads page, choose
      <span class="font-semibold">Broker</span> as the source and use
      <span class="font-semibold">Add new partner</span> in the picker — the person taking
      the enquiry is the one who knows the broker's name and number. This page is where
      those rows are then completed, merged and retired.
    </p>

    <div class="card overflow-hidden">

      <div class="flex flex-wrap gap-2 border-b border-slate-100 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search name, contact, phone or email"
               class="w-full md:!w-72" />

        <select v-model="f.type" class="w-full md:!w-40" aria-label="Type">
          <option value="">All types</option>
          <option v-for="(label, key) in options.types" :key="key" :value="key">{{ label }}</option>
        </select>

        <select v-model="f.status" class="w-full md:!w-40" aria-label="Status">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>

        <!-- the grouped view, as a filter — see the script comment -->
        <select v-model="f.parent_id" class="w-full md:!w-52" aria-label="Parent firm">
          <option value="">Any firm</option>
          <option v-for="firm in options.firms" :key="firm.id" :value="firm.id">
            Brokers of {{ firm.name }}
          </option>
        </select>

        <button class="btn-ghost w-full md:w-auto" @click="clearFilters">Clear</button>
      </div>

      <!-- what the list is currently pinned to, and the way back out of it -->
      <div v-if="pinnedFirm"
           class="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-teal-50/60 px-4 py-2.5
                  text-xs text-teal-900">
        Showing the brokers filed under
        <span class="font-semibold">{{ pinnedFirm.name }}</span>.
        <button class="font-semibold underline underline-offset-2" @click="clearParent">
          Show everyone
        </button>
      </div>

      <div v-if="!partners.data.length" class="px-5 py-14 text-center text-sm text-slate-500">
        <p class="mb-1 font-semibold text-slate-700">No channel partners match</p>
        Try clearing the filters. New partners appear here once somebody adds one while
        logging a broker lead.
      </div>

      <!-- table on desktop, cards on mobile: the same pattern as Users and To-do -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 text-left text-xs text-slate-500">
            <th class="px-4 py-2.5 font-semibold">Name</th>
            <th class="px-4 py-2.5 font-semibold">Type</th>
            <th class="px-4 py-2.5 font-semibold">Parent firm</th>
            <th class="px-4 py-2.5 font-semibold">Contact person</th>
            <th class="px-4 py-2.5 font-semibold">Phone</th>
            <th class="px-4 py-2.5 font-semibold">Email</th>
            <th class="px-4 py-2.5 font-semibold">Status</th>
            <th class="px-4 py-2.5 text-right font-semibold">Leads</th>
            <th class="px-4 py-2.5 text-right font-semibold">Bookings</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in partners.data" :key="p.id" class="border-b border-slate-100"
              :class="p.is_active ? '' : 'bg-slate-50/60'">
            <td class="px-4 py-3">
              <div class="font-semibold" :class="p.is_active ? '' : 'text-slate-500'">{{ p.name }}</div>
              <!-- a firm says what it holds, and the count is the way into it -->
              <button v-if="p.type === 'firm' && p.brokers_count"
                      class="text-xs text-teal-800 underline-offset-2 hover:underline"
                      @click="showBrokersOf(p)">
                {{ p.brokers_count }} broker{{ p.brokers_count === 1 ? '' : 's' }}
              </button>
            </td>
            <td class="px-4 py-3">{{ options.types[p.type] ?? p.type }}</td>
            <td class="px-4 py-3">
              <span v-if="p.parent_name">{{ p.parent_name }}</span>
              <span v-else-if="p.type === 'broker'" class="text-slate-400">Independent</span>
              <span v-else class="text-slate-300">—</span>
            </td>
            <td class="px-4 py-3">{{ p.contact_person || '—' }}</td>
            <td class="px-4 py-3">
              <div class="tabular-nums">{{ p.phone }}</div>
              <div v-if="p.alt_phone" class="text-xs tabular-nums text-slate-400">{{ p.alt_phone }}</div>
            </td>
            <td class="px-4 py-3 break-all">{{ p.email || '—' }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="p.is_active
                      ? 'bg-emerald-50 text-emerald-800'
                      : 'bg-slate-200 text-slate-600'">
                {{ p.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <!-- the two numbers the whole feature was asked for: how much
                 business came through this partner, and how much of it closed -->
            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ p.leads_count }}</td>
            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ p.bookings_count }}</td>
            <!--
              Edit and merge are everyone's: the PUT and the POST /merge they
              save to sit on the same `auth` group as this page, so whatever
              label a telecaller reads they may also fix, and the duplicate
              they find they may put back together. Delete is admin-only — its
              route is behind `role:admin`, and a direct DELETE is refused
              there, whichever way the button is drawn.
            -->
            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5">
                <button class="btn-xs" @click="openEdit(p)">Edit</button>
                <!-- the duplicate cleanup; every partner can be merged away -->
                <button class="btn-xs" @click="openMerge(p)">Merge</button>
                <button v-if="isAdmin" class="btn-xs disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!!lockReason(p)" :title="lockReason(p)"
                        @click="openDelete(p)">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="partners.data.length" class="divide-y divide-slate-100 lg:hidden">
        <div v-for="p in partners.data" :key="p.id" class="p-4"
             :class="p.is_active ? '' : 'bg-slate-50/60'">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="truncate font-semibold">{{ p.name }}</div>
              <div class="truncate text-xs text-slate-400">
                {{ options.types[p.type] ?? p.type }}
                <span v-if="p.parent_name">· {{ p.parent_name }}</span>
                <span v-else-if="p.type === 'broker'">· Independent</span>
              </div>
            </div>
            <span class="flex-none rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="p.is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-200 text-slate-600'">
              {{ p.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Contact person</dt>
            <dd class="text-right">{{ p.contact_person || '—' }}</dd>
            <dt class="text-slate-400">Phone</dt>
            <dd class="text-right tabular-nums">{{ p.phone }}</dd>
            <dt class="text-slate-400">Email</dt>
            <dd class="break-all text-right">{{ p.email || '—' }}</dd>
            <template v-if="p.type === 'firm'">
              <dt class="text-slate-400">Brokers</dt>
              <dd class="text-right">
                <button v-if="p.brokers_count" class="text-teal-800 underline underline-offset-2"
                        @click="showBrokersOf(p)">{{ p.brokers_count }}</button>
                <span v-else>0</span>
              </dd>
            </template>
            <dt class="text-slate-400">Leads</dt>
            <dd class="text-right font-semibold tabular-nums">{{ p.leads_count }}</dd>
            <dt class="text-slate-400">Bookings</dt>
            <dd class="text-right font-semibold tabular-nums">{{ p.bookings_count }}</dd>
          </dl>

          <div class="mt-3 flex gap-2 border-t border-slate-100 pt-3">
            <button class="btn-xs flex-1" @click="openEdit(p)">Edit</button>
            <button class="btn-xs flex-1" @click="openMerge(p)">Merge</button>
            <button v-if="isAdmin" class="btn-xs flex-1 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!!lockReason(p)" :title="lockReason(p)"
                    @click="openDelete(p)">Delete</button>
          </div>
        </div>
      </div>

      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
        <span>{{ partners.total }} partner{{ partners.total === 1 ? '' : 's' }}</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in partners.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <ChannelPartnerFormModal :show="formOpen" :partner="editing" :options="options"
                             @close="formOpen = false" />
    <DeleteChannelPartnerDialog :show="deleteOpen" :partner="deleting"
                                @close="deleteOpen = false" />
    <MergeChannelPartnerDialog :show="mergeOpen" :partner="merging" :options="options"
                               @close="mergeOpen = false" />
  </AppLayout>
</template>
