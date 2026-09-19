<script setup>
import { reactive, computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StageBadge from '@/Components/StageBadge.vue'
import LeadFormModal from '@/Components/LeadFormModal.vue'
import LeadViewModal from '@/Components/LeadViewModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import AssignedTo from '@/Components/AssignedTo.vue'
import FilterChips from '@/Components/FilterChips.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'
import { brokerLabel } from '@/lib/brokerLabel.js'
import { filterable } from '@/composables/useTaxonomy'

const props = defineProps({
  leads: Object,
  filters: Object,
  options: Object,
  // the stage breakdown of this list, every filter applied except stage
  stageCounts: Object,
})

// every source there has ever been, retired ones marked — see filterable()
const sourceFilterOptions = computed(() => filterable(props.options.sources, props.options.activeSources))

const role = computed(() => usePage().props.auth.user.role)
const canEdit = computed(() => role.value !== 'telecaller')
const isAdmin = computed(() => role.value === 'admin')

/* ---------------- filters, kept in the session ---------------- */

const f = reactive({
  search: props.filters.search ?? '',
  // set by the chips now, not by a dropdown
  stage: props.filters.stage ?? '',
  project_id: props.filters.project_id ?? '',
  source: props.filters.source ?? '',
  // where the leads report's "By channel partner" rows drill through to
  channel_partner_id: props.filters.channel_partner_id ?? '',
  assigned_to: props.filters.assigned_to ?? '',

  // '' is All time. 'custom' is a state of this control only — the server
  // stores the pair of dates and derives the word on the way back out
  range: props.filters.range ?? '',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
})

const { visit } = useFilterVisit(route('leads.index'))

/*
 | A preset and a custom pair are alternatives, so only one of them is ever on
 | the wire. Without this, switching from a custom range back to "Last 7 days"
 | would still carry the two dates along, and the server — which reads a pair as
 | custom whatever else it is told — would keep showing the custom range under a
 | control that said something else.
 |
 | 'custom' itself is never sent. It is not a value the server stores; the dates
 | are, and it infers the word from them.
 */
const payload = () => {
  const custom = f.range === 'custom'

  return { ...f, range: custom ? '' : f.range, from: custom ? f.from : '', to: custom ? f.to : '' }
}

/*
 | The same rules the server applies, so an impossible range is never sent.
 |
 | Both dates are ISO yyyy-mm-dd, so they compare correctly as plain strings and
 | none of this has to build a Date. That matters: the browser may be in any
 | timezone, and `options.today` is today in IST as the server sees it.
 */
const dateError = computed(() => {
  if (f.range !== 'custom' || !f.from || !f.to) return ''
  if (f.from > f.to) return 'From must not be after To.'
  if (f.to > props.options.today) return 'To must not be in the future.'
  return ''
})

/*
 | Every field the page owns goes out on every visit, and reset=1 goes with
 | them, so the request is the whole instruction: what is named is on, what is
 | missing is off. The empty ones are dropped before it leaves — see
 | withoutEmpty() in the composable for why the two belong together.
 |
 | A half-typed custom range sends nothing at all. The error is already on
 | screen; a visit on top of it would either reload the same list for no reason
 | or apply a range the user has not finished choosing.
 */
const push = () => {
  if (f.range === 'custom' && (!f.from || !f.to || dateError.value)) return

  visit({ reset: 1, ...payload() })
}

const filters = useDebouncedFilters(f, push)

const clearFilters = () => {
  filters.silently(() => Object.keys(f).forEach(k => (f[k] = '')))
  filters.cancel()

  // every field is empty now, so this sends reset=1 and nothing else: the
  // session entry is wiped and nothing survives to reappear on the next visit.
  // That is the stage chip back to All and the dates back to All time too.
  push()
}

/* ---------------- stage chips ---------------- */

/*
 | All, then one per configured stage, in config order.
 |
 | The counts are the server's — one grouped query over this same filtered list
 | with the stage clause left out — so clicking a chip re-filters the table
 | without moving the numbers on the chips beside it. "All" is summed from the
 | others on that side, so the row can never fail to add up.
 |
 | Colours come from config through options.stageColors, the same map StageBadge
 | reads, so a chip and a badge for one stage are the same colour. All has none:
 | it is not a stage, and it is styled from the palette's slate instead.
 */
const chips = computed(() => [
  { key: '', label: 'All', value: props.stageCounts.total, color: null },
  ...props.stageCounts.bars.map(bar => ({ ...bar, color: props.options.stageColors[bar.key] })),
])

/** Clicking the active chip clears the filter, exactly as All does. */
const setStage = key => {
  filters.silently(() => { f.stage = f.stage === key ? '' : key })
  filters.cancel()
  push()
}

/* ---------------- modals ---------------- */
const formOpen = ref(false)
const editing = ref(null)
const viewOpen = ref(false)
const viewId = ref(null)
const confirmOpen = ref(false)
const deleting = ref(null)
const processing = ref(false)

const openAdd = () => { editing.value = null; formOpen.value = true }
const openEdit = lead => { editing.value = lead; formOpen.value = true }
const openView = id => { viewId.value = id; viewOpen.value = true }

const editFromView = id => {
  viewOpen.value = false
  editing.value = props.leads.data.find(l => l.id === id)
  formOpen.value = true
}

const confirmDelete = lead => { deleting.value = lead; confirmOpen.value = true }

const doDelete = () => {
  processing.value = true
  router.delete(route('leads.destroy', deleting.value.id), {
    preserveScroll: true,
    onFinish: () => { processing.value = false; confirmOpen.value = false },
  })
}

/* ---------------- helpers ---------------- */
const fmtDate = v => v ? new Date(v).toLocaleDateString('en-IN',
  { day: '2-digit', month: 'short', year: '2-digit' }) : '—'

const ageClass = d => d === null ? 'text-slate-400'
  : d > 7 ? 'text-rose-700 dark:text-rose-300' : d > 3 ? 'text-amber-700 dark:text-amber-300' : 'text-slate-600 dark:text-slate-300'
</script>

<template>
  <Head title="Leads" />

  <AppLayout title="Leads" subtitle="All enquiries across projects">
    <template #actions>
      <button v-if="canEdit" class="btn w-full sm:w-auto" @click="openAdd">Add lead</button>
    </template>

    <div class="card overflow-hidden">

      <!--
        Filters. Everything is full width and stacked below md, and inline from
        768 up — six controls do not fit on one line until about 1100px, so
        above md they wrap onto a second row rather than being squeezed to
        widths nobody can read a project name in.

        No stage dropdown: the chips below are the stage filter now, and having
        both would be two controls for one piece of state.
      -->
      <div class="flex flex-wrap gap-2 border-b border-slate-100 dark:border-slate-700/60 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search name, mobile or email"
               class="w-full md:!w-64" />
        <select v-model="f.project_id" class="w-full md:!w-44">
          <option value="">All projects</option>
          <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <!--
          Every source there has ever been, retired ones marked. A filter reads
          rather than writes — see filterable() — so narrowing to a source the
          admin switched off last month still finds the leads that came through
          it, which is exactly when somebody wants to.
        -->
        <select v-model="f.source" class="w-full md:!w-40">
          <option value="">All sources</option>
          <option v-for="o in sourceFilterOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
        </select>

        <!--
          The channel partner. This is the control the leads report's
          "By channel partner" rows drill through to — a row there is a count
          over a population, and clicking it has to open that population as a
          list. Without a control here the filter would arrive from the report,
          apply, and be invisible and unclearable on the page it landed on.
        -->
        <select v-model="f.channel_partner_id" class="w-full md:!w-52"
                aria-label="Channel partner">
          <option value="">All channel partners</option>
          <option v-for="p in options.channelPartners" :key="p.id" :value="p.id">{{ p.label }}</option>
        </select>
        <select v-if="isAdmin" v-model="f.assigned_to" class="w-full md:!w-44"
                aria-label="Assigned to">
          <option value="">Assigned to</option>
          <option v-for="u in options.users" :key="u.id" :value="u.id">
            {{ u.first_name }} {{ u.last_name }}
          </option>
        </select>

        <!-- on leads.created_at; the server owns the boundaries -->
        <select v-model="f.range" class="w-full md:!w-36" aria-label="Date added">
          <option value="">All time</option>
          <option value="today">Today</option>
          <option value="7">Last 7 days</option>
          <option value="30">Last 30 days</option>
          <option value="custom">Custom…</option>
        </select>

        <!--
          Only when it is asked for, and nothing is applied until both dates are
          set and agree with each other. `max` is today in IST from the server,
          not the browser's idea of today.
        -->
        <template v-if="f.range === 'custom'">
          <input v-model="f.from" type="date" :max="options.today"
                 class="w-full md:!w-40" aria-label="From date" />
          <input v-model="f.to" type="date" :min="f.from" :max="options.today"
                 class="w-full md:!w-40" aria-label="To date" />
        </template>

        <button class="btn-ghost w-full md:w-auto" @click="clearFilters">Clear</button>

        <!-- w-full so the complaint gets a line of its own rather than
             elbowing a control off the row it belongs to -->
        <p v-if="dateError" class="w-full text-xs font-medium text-rose-700 dark:text-rose-300" role="alert">
          {{ dateError }}
        </p>
      </div>

      <!--
        Stage chips: the stage filter, and the stage breakdown, in one control.
        The strip itself is FilterChips, shared with the To-do page so the two
        cannot drift apart.
      -->
      <FilterChips :chips="chips" :active="f.stage" @select="setStage" />

      <!-- empty -->
      <div v-if="!leads.data.length" class="px-5 py-14 text-center text-sm text-slate-500 dark:text-slate-400">
        <p class="mb-1 font-semibold text-slate-700 dark:text-slate-300">No leads match these filters</p>
        Clear the filters, or add the first lead for this project.
      </div>

      <!-- desktop table -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 dark:bg-slate-900/60 text-left text-xs text-slate-500 dark:text-slate-400">
            <th class="px-4 py-2.5 font-semibold">Name</th>
            <th class="px-4 py-2.5 font-semibold">Mobile</th>
            <th class="px-4 py-2.5 font-semibold">Stage</th>
            <th class="px-4 py-2.5 font-semibold">Project</th>
            <th class="px-4 py-2.5 font-semibold">Source</th>
            <th v-if="isAdmin" class="px-4 py-2.5 font-semibold">Assigned to</th>
            <th class="px-4 py-2.5 font-semibold">Created</th>
            <th class="px-4 py-2.5 font-semibold">Days in stage</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in leads.data" :key="l.id"
              class="border-b border-l-4 border-slate-100 dark:border-slate-700/60 hover:bg-slate-50/70"
              :style="{ borderLeftColor: options.stageColors[l.stage] }">
            <td class="px-4 py-3">
              <div class="font-semibold">{{ l.full_name }}</div>
              <div class="text-xs text-slate-400">{{ l.email }}</div>
            </td>
            <td class="px-4 py-3">{{ l.mobile_number }}</td>
            <td class="px-4 py-3"><StageBadge :stage="l.stage" /></td>
            <td class="px-4 py-3">{{ l.project?.name }}</td>
            <td class="px-4 py-3">
              {{ options.sources[l.source] }}
              <!-- the partner this lead came through, or the free text a lead
                   from before the partner list still carries -->
              <div v-if="brokerLabel(l)" class="text-xs text-slate-400">{{ brokerLabel(l) }}</div>
            </td>
            <td v-if="isAdmin" class="px-4 py-3"><AssignedTo :user="l.owner" /></td>
            <td class="px-4 py-3">{{ fmtDate(l.created_at) }}</td>
            <td class="px-4 py-3 font-semibold" :class="ageClass(l.days_in_stage)">
              {{ l.days_in_stage === null ? '—' : l.days_in_stage + 'd' }}
            </td>
            <td class="px-4 py-3">
              <div class="flex gap-1.5">
                <button class="btn-xs" @click="openView(l.id)">View</button>
                <button v-if="canEdit" class="btn-xs" @click="openEdit(l)">Edit</button>
                <button v-if="isAdmin" class="btn-xs hover:!border-rose-600 hover:!text-rose-700 dark:text-rose-300"
                        @click="confirmDelete(l)">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- mobile cards: a nine column table is unusable on a phone -->
      <div v-if="leads.data.length" class="divide-y divide-slate-100 dark:divide-slate-700/60 lg:hidden">
        <div v-for="l in leads.data" :key="l.id" class="border-l-4 p-4"
             :style="{ borderLeftColor: options.stageColors[l.stage] }">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="truncate font-semibold">{{ l.full_name }}</div>
              <div class="text-xs text-slate-400">{{ l.mobile_number }}</div>
            </div>
            <StageBadge :stage="l.stage" />
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Project</dt><dd class="text-right">{{ l.project?.name }}</dd>
            <dt class="text-slate-400">Source</dt>
            <dd class="text-right">
              {{ options.sources[l.source] }}
              <span v-if="brokerLabel(l)" class="block text-slate-400">{{ brokerLabel(l) }}</span>
            </dd>
            <template v-if="isAdmin">
              <dt class="text-slate-400">Assigned to</dt>
              <dd class="text-right"><AssignedTo :user="l.owner" /></dd>
            </template>
            <dt class="text-slate-400">Days in stage</dt>
            <dd class="text-right font-semibold" :class="ageClass(l.days_in_stage)">
              {{ l.days_in_stage === null ? '—' : l.days_in_stage + 'd' }}
            </dd>
          </dl>

          <div class="mt-3 flex gap-2 border-t border-slate-100 dark:border-slate-700/60 pt-3">
            <button class="btn-xs flex-1" @click="openView(l.id)">View</button>
            <button v-if="canEdit" class="btn-xs flex-1" @click="openEdit(l)">Edit</button>
            <button v-if="isAdmin" class="btn-xs flex-1 hover:!border-rose-600 hover:!text-rose-700 dark:text-rose-300"
                    @click="confirmDelete(l)">Delete</button>
          </div>
        </div>
      </div>

      <!-- pagination -->
      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
        <span>Showing {{ leads.from ?? 0 }}–{{ leads.to ?? 0 }} of {{ leads.total }} leads</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in leads.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <LeadFormModal :show="formOpen" :lead="editing" :options="options" @close="formOpen = false" />
    <LeadViewModal :show="viewOpen" :lead-id="viewId" :options="options"
                   @close="viewOpen = false" @edit="editFromView" />
    <ConfirmDialog
      :show="confirmOpen" :processing="processing"
      title="Delete this lead?"
      :message="`${deleting?.full_name} · ${deleting?.mobile_number} will be removed from all lists and reports.`"
      confirm-text="Delete lead"
      @close="confirmOpen = false" @confirm="doDelete"
    />
  </AppLayout>
</template>
