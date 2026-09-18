<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import StageBadge from '@/Components/StageBadge.vue'
import CompleteTaskModal from '@/Components/CompleteTaskModal.vue'
import TodoFormModal from '@/Components/TodoFormModal.vue'
import CallButtons from '@/Components/CallButtons.vue'
import AssignedTo from '@/Components/AssignedTo.vue'
import FilterChips from '@/Components/FilterChips.vue'
import LeadActivityTimeline from '@/Components/LeadActivityTimeline.vue'
import { useFilterVisit, useDebouncedFilters } from '@/composables/useFilterVisit.js'

const props = defineProps({
  todos: Object,
  tab: String,
  // the four tab badges: how much is in each list, no filter applied
  counts: Object,
  // the type breakdown of THIS tab, every other filter applied except type
  types: Object,
  filters: Object,
  options: Object,
})

const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

/*
 | The keys are the filter vocabulary and are unchanged — the session, the
 | controller's validator and the dashboard's link all speak `overdue`. The
 | labels are what a user reads, and "Waiting longer" is what the dashboard
 | panel that links here is called, so arriving from it does not land on a tab
 | with a different name for the same list.
 */
const tabs = [
  { key: 'overdue', label: 'Waiting longer' },
  { key: 'today', label: 'Today' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'completed', label: 'Completed' },
]

const f = reactive({
  search: props.filters.search ?? '',
  // set by the chips now, not by a dropdown
  type: props.filters.type ?? '',
  assigned_to: props.filters.assigned_to ?? '',

  // '' is All time. 'custom' is a state of this control only — the server
  // stores the pair of dates and derives the word on the way back out
  range: props.filters.range ?? '',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
})

// the tab we last asked for: props.tab only catches up once the visit lands,
// so a debounced search firing mid-flight would otherwise send the old tab
let wantedTab = props.tab
watch(() => props.tab, v => (wantedTab = v))

const { visit } = useFilterVisit(route('todos.index'))

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
 | Every field the page owns goes out on every visit, the tab among them, and
 | reset=1 goes with them: the request is the whole instruction, so what is
 | named is on and what is missing is off. The empty ones are dropped before
 | it leaves — see withoutEmpty() in the composable for why the two belong
 | together.
 |
 | A half-typed custom range sends nothing at all. The error is already on
 | screen; a visit on top of it would either reload the same list for no reason
 | or apply a range the user has not finished choosing.
 |
 | Only on the tab that has a date control, though. The range means nothing on
 | the three pending tabs, so a half-filled pair left behind on Completed must
 | not be able to hold the user there — the control they would have to fix to
 | escape is not even on screen once they have clicked away.
 */
const push = () => {
  if (wantedTab === 'completed' && f.range === 'custom' && (!f.from || !f.to || dateError.value)) return

  visit({ reset: 1, tab: wantedTab, ...payload() })
}

const filters = useDebouncedFilters(f, push)

// a tab click goes out at once — drop any pending search push so it cannot
// land afterwards and pull us back to the previous tab
const setTab = key => { filters.cancel(); wantedTab = key; push() }

const clearFilters = () => {
  filters.silently(() => Object.keys(f).forEach(k => (f[k] = '')))
  filters.cancel()

  // every field is empty now, so this sends reset=1 and the tab: the session
  // entry is wiped — type chip back to All, dates back to All time — and the
  // tab rides along because it is the one the user is looking at, not
  // something they asked to lose
  push()
}

/* ---------------- what the filter bar may offer ---------------- */

/*
 | The date control belongs to Completed alone.
 |
 | The three pending tabs are states measured against today — overdue is before
 | it, Today is on it, Upcoming is after it — so a window cannot narrow them
 | usefully, and on Upcoming it cannot narrow them at all: every range this
 | control offers ends today. The server ignores the range on those tabs
 | (TodoController::applyTab), and offering a control that provably does nothing
 | is worse than not offering it, so it comes off the bar too.
 |
 | props.tab, not wantedTab: this describes the list on screen, and mid-flight
 | the list on screen is still the old tab's.
 */
const showDateFilter = computed(() => props.tab === 'completed')

/*
 | Whether the user has narrowed this list themselves — which decides whether an
 | empty table means "you have nothing" or "nothing matched".
 |
 | The dates count only where they do anything, which is the same tab the
 | control shows on. A range left over from a visit to Completed is not a filter
 | the user is looking at while they are on Upcoming.
 */
const hasFilters = computed(() =>
  Boolean(f.search || f.type || f.assigned_to ||
    (showDateFilter.value && (f.range || f.from || f.to))),
)

/* ---------------- type chips ---------------- */

/*
 | All, then one per configured type, in config order.
 |
 | The counts are the server's — one grouped query over this tab's list with the
 | type clause left out — so clicking a chip re-filters the table without moving
 | the numbers on the chips beside it, or the badges on the tabs above it.
 |
 | No colours. Task types have none in config and inventing four would imply a
 | meaning they do not have; FilterChips renders a colourless chip in neutral
 | slate, which is exactly how the Leads page draws its own "All".
 |
 | Labels come from config('crm.todo_types') by way of the server. No type is
 | spelled out in this file.
 */
const chips = computed(() => [
  { key: '', label: 'All', value: props.types.total, color: null },
  ...props.types.bars.map(bar => ({ ...bar, color: null })),
])

/** Clicking the active chip clears the filter, exactly as All does. */
const setType = key => {
  filters.silently(() => { f.type = f.type === key ? '' : key })
  filters.cancel()
  push()
}

/* modals */
const completeOpen = ref(false)
const active = ref(null)
const formOpen = ref(false)
const editing = ref(null)

const openComplete = t => { active.value = t; completeOpen.value = true }
const openAdd = () => { editing.value = null; formOpen.value = true }
const openEdit = t => { editing.value = t; formOpen.value = true }

/* helpers */
const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

const relative = v => {
  const h = (new Date(v) - new Date()) / 36e5
  if (h < -24) return `${Math.round(-h / 24)}d late`
  if (h < 0) return `${Math.round(-h)}h late`
  if (h < 24) return `in ${Math.max(1, Math.round(h))}h`
  return `in ${Math.round(h / 24)}d`
}

const isOverdue = t => t.status === 'pending' && new Date(t.scheduled_at) < new Date()

/*
 | The server no longer sends a row whose lead has been deleted — Todo::hasLead()
 | leaves it out. These guards are the second line: a null relation should cost
 | one row's worth of detail, not the whole page.
 */
const leadName = t => t.lead?.full_name ?? 'Lead deleted'
const leadLine = t => [t.lead?.mobile_number, t.lead?.project?.name].filter(Boolean).join(' · ') || '—'

/* ---------------- inline expandable activity ---------------- */

/*
 | The row-level +/- control. One follow-up row is expanded at a time, pinned by
 | the follow-up's id (expandedTodoId), while the activity itself is cached by
 | the LEAD's id: two follow-ups on the same lead show the same history with one
 | request. The cache is in-memory for this page visit only — never localStorage
 | — and the fetch is deferred until the first "+" click, so a page of follow-
 | ups costs nothing on load.
 */
const expandedTodoId = ref(null)
const activityCache = reactive({})
const loadingLeadId = ref(null)
const failedLeadIds = reactive({})

/*
 | Only one empty/invalid lead check. A row with no lead is unexpandable: there
 | is no lead whose activity could be shown, and the page hides those rows'
 | leads anyway.
 */
const expandable = t => Boolean(t.lead?.id)

const isRowExpanded = t => expandedTodoId.value === t.id

const timelineFor = t => activityCache[t.lead?.id] ?? []

const isLoadingFor = t => loadingLeadId.value === t.lead?.id

const hasFailedFor = t => Boolean(failedLeadIds[t.lead?.id])

/*
 | The request is keyed by lead id, so an activity still in flight when the user
 | opens another row is remembered, never duplicated, and lands in the cache for
 | whichever row asks next. Display never reads the in-flight response anyway:
 | a row renders only its own lead's cached timeline, or the loading line.
 */
async function loadActivity(leadId) {
  loadingLeadId.value = leadId
  delete failedLeadIds[leadId]

  try {
    // the same endpoint the lead view modal reads, so authorization (LeadPolicy)
    // and the timeline are identical to Lead -> View -> Activity
    const { data } = await axios.get(route('leads.show', leadId))
    activityCache[leadId] = data.timeline ?? []
  } catch {
    // 403 from LeadPolicy::view — the same rule that let the lead onto the
    // page — shows a small inline error rather than breaking the table
    failedLeadIds[leadId] = true
  } finally {
    if (loadingLeadId.value === leadId) loadingLeadId.value = null
  }
}

function toggleActivity(t) {
  const leadId = t.lead?.id
  if (!leadId) return

  // clicking a row already expanded collapses it — no navigation, no request
  if (expandedTodoId.value === t.id) {
    expandedTodoId.value = null
    return
  }

  // click "+" on B while A is open: B takes the single expanded slot
  expandedTodoId.value = t.id

  if (!(leadId in activityCache) && loadingLeadId.value !== leadId) {
    loadActivity(leadId)
  }
}

/*
 | The expanded row spans the whole table. The column count follows the headers
 | exactly: the expand column, the four always-present columns, and then the
 | tab-dependent ones.
 */
const activityColspan = computed(() => {
  let cols = 5 // expand, Lead, Type, Scheduled, Stage
  cols += props.tab === 'completed' ? 3 : 0 // Outcome, Remarks, Completed
  if (props.tab !== 'completed') cols += isAdmin.value ? 2 : 1 // Handled by + Due, or just Due
  cols += 1 // actions
  return cols
})

/*
 | Every visit — a tab, a filter, a search, a page change — hands the page a new
 | list, and the expanded row may be gone from it. Close the expansion rather
 | than leave it pointing at a follow-up no longer on screen.
 */
watch(() => props.todos, () => { expandedTodoId.value = null })
</script>

<template>
  <Head title="Follow-ups" />

  <AppLayout title="Follow-ups" subtitle="Your calls and site visits">
    <template #actions>
      <button class="btn w-full sm:w-auto" @click="openAdd">Add follow-up</button>
    </template>

    <div class="card overflow-hidden">

      <!-- tabs -->
      <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-2 sm:px-4">
        <button v-for="t in tabs" :key="t.key"
                class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium"
                :class="tab === t.key
                  ? 'border-teal-700 font-semibold text-slate-900'
                  : 'border-transparent text-slate-500 hover:text-slate-700'"
                @click="setTab(t.key)">
          {{ t.label }}
          <span class="ml-1 text-xs text-slate-400">{{ counts[t.key] }}</span>
        </button>
      </div>

      <!--
        Filters. Full width and stacked below md, inline from 768 up, the same
        as the Leads page.

        No type dropdown: the chips below are the type filter now, and having
        both would be two controls for one piece of state.
      -->
      <div class="flex flex-wrap gap-2 border-b border-slate-100 p-3 sm:p-4">
        <input v-model="f.search" type="search" placeholder="Search lead name or mobile"
               class="w-full md:!w-64" />
        <select v-if="isAdmin" v-model="f.assigned_to" class="w-full md:!w-44"
                aria-label="Assigned to">
          <option value="">Assigned to</option>
          <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.first_name }} {{ u.last_name }}</option>
        </select>

        <!--
          Completed only — see showDateFilter. That tab filters on completed_at,
          so the label can say so outright rather than changing with the tab.
        -->
        <template v-if="showDateFilter">
          <select v-model="f.range" class="w-full md:!w-36" aria-label="Date completed">
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
        </template>

        <button class="btn-ghost w-full md:w-auto" @click="clearFilters">Clear</button>

        <!-- w-full so the complaint gets a line of its own rather than
             elbowing a control off the row it belongs to -->
        <p v-if="showDateFilter && dateError" class="w-full text-xs font-medium text-rose-700" role="alert">
          {{ dateError }}
        </p>

        <!-- says which date the filter above is about, once it is doing
             anything at all -->
        <p v-if="showDateFilter && f.range" class="w-full text-xs text-slate-400">
          Filtering on when the follow-up was completed.
        </p>
      </div>

      <!--
        Type chips: the type filter, and the type breakdown of this tab, in one
        control. The strip itself is FilterChips, shared with the Leads page so
        the two cannot drift apart.
      -->
      <FilterChips :chips="chips" :active="f.type" @select="setType" />

      <!--
        Empty. Two different sentences, because they are two different facts: a
        list with no filter on it is empty because there is nothing in it, and a
        filtered one is empty because nothing matched — and only the second has
        anything the user can do about it. Same wording as the Users page.
      -->
      <div v-if="!todos.data.length" class="px-5 py-14 text-center text-sm text-slate-500">
        <template v-if="hasFilters">
          <p class="mb-1 font-semibold text-slate-700">No follow-ups match</p>
          Try clearing the filters.
        </template>
        <template v-else>
          <p class="mb-1 font-semibold text-slate-700">
            {{ tab === 'overdue' ? 'Nothing waiting'
               : tab === 'today' ? 'No calls due today'
               : tab === 'upcoming' ? 'Nothing scheduled ahead' : 'No completed follow-ups yet' }}
          </p>
          Follow-ups you schedule will appear here.
        </template>
      </div>

      <!-- rows: table on desktop, cards on mobile -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 text-left text-xs text-slate-500">
            <th class="w-10 px-2 py-2.5"><span class="sr-only">Expand activity</span></th>
            <th class="px-4 py-2.5 font-semibold">Lead</th>
            <th class="px-4 py-2.5 font-semibold">Type</th>
            <th class="px-4 py-2.5 font-semibold">Scheduled</th>
            <th class="px-4 py-2.5 font-semibold">Stage</th>
            <th v-if="tab === 'completed'" class="px-4 py-2.5 font-semibold">Outcome</th>
            <th v-if="tab === 'completed'" class="px-4 py-2.5 font-semibold">Remarks</th>
            <th v-else-if="isAdmin" class="px-4 py-2.5 font-semibold">Handled by</th>
            <th v-if="tab === 'completed'" class="px-4 py-2.5 font-semibold">Completed</th>
            <th v-else class="px-4 py-2.5 font-semibold">Due</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="t in todos.data" :key="t.id">
            <tr class="border-b border-l-4 border-slate-100"
                :class="isOverdue(t) ? 'border-l-rose-600 bg-rose-50/40' : 'border-l-transparent'">
              <!-- the one thing on this row that toggles the activity panel -->
              <td class="px-2 py-3">
                <button v-if="expandable(t)" type="button"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-slate-200 bg-white text-base leading-none text-slate-500 transition
                               hover:border-slate-300 hover:text-slate-700
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        :aria-expanded="isRowExpanded(t) ? 'true' : 'false'"
                        :aria-controls="`todo-activity-${t.id}`"
                        :aria-label="isRowExpanded(t) ? 'Hide activity' : 'Show activity'"
                        @click="toggleActivity(t)">
                  <span aria-hidden="true">{{ isRowExpanded(t) ? '−' : '+' }}</span>
                </button>
              </td>
              <td class="px-4 py-3">
                <div class="font-semibold" :class="t.lead ? '' : 'italic text-slate-400'">{{ leadName(t) }}</div>
                <div class="text-xs text-slate-400">{{ leadLine(t) }}</div>
              </td>
              <td class="px-4 py-3">{{ options.types[t.type] }}</td>
              <td class="px-4 py-3">{{ fmt(t.scheduled_at) }}</td>
              <td class="px-4 py-3"><StageBadge v-if="t.lead" :stage="t.lead.stage" /></td>

              <template v-if="tab === 'completed'">
                <td class="px-4 py-3"><StageBadge :stage="t.outcome_stage" /></td>
                <td class="max-w-[260px] px-4 py-3 text-slate-600">{{ t.remarks || '—' }}</td>
                <td class="px-4 py-3">
                  {{ fmt(t.completed_at) }}
                  <div class="text-xs text-slate-400">{{ t.completer?.display_name ?? '—' }}</div>
                </td>
              </template>
              <template v-else>
                <td v-if="isAdmin" class="px-4 py-3"><AssignedTo :user="t.owner" /></td>
                <td class="px-4 py-3" :class="isOverdue(t) ? 'font-semibold text-rose-700' : 'text-slate-500'">
                  {{ relative(t.scheduled_at) }}
                </td>
              </template>

              <td class="px-4 py-3">
                <!-- dialling is useful on a closed task too, so it sits outside the pending check -->
                <div class="flex items-center gap-1.5">
                  <template v-if="t.status === 'pending'">
                    <button class="btn px-3 py-1 text-xs" @click="openComplete(t)">Update</button>
                    <button class="btn-xs" @click="openEdit(t)">Edit</button>
                  </template>
                  <CallButtons v-if="t.lead?.mobile_number" compact :mobile="t.lead.mobile_number" />
                </div>
              </td>
            </tr>

            <!--
              The expanded activity row, immediately under the follow-up that
              owns it. The <tr> is always in the table; only its content mounts,
              so the height animation runs on a div inside the colspan cell —
              never on the <tr>, which browsers do not animate well. Collapsed,
              the empty cell collapses to nothing. Switching A -> B trims A's
              content and grows B's at the same time, so one click never flashes
              a stale history.
            -->
            <tr v-if="expandable(t)">
              <td :colspan="activityColspan" class="p-0">
                <!--
                  Smooth but subtle open/close: the content fades while the
                  grid row unfurls between 0fr and 1fr, so tall histories are
                  never clipped. motion-safe: keeps it instant for users who
                  ask for no animation.
                -->
                <Transition
                  enter-active-class="motion-safe:transition-[grid-template-rows,opacity] motion-safe:duration-200 motion-safe:ease-out"
                  enter-from-class="motion-safe:opacity-0 motion-safe:grid-rows-[0fr]"
                  leave-active-class="motion-safe:transition-[grid-template-rows,opacity] motion-safe:duration-200 motion-safe:ease-in"
                  leave-to-class="motion-safe:opacity-0 motion-safe:grid-rows-[0fr]"
                >
                  <div v-if="isRowExpanded(t)" :id="`todo-activity-${t.id}`" class="grid grid-rows-[1fr]">
                    <!-- min-h-0 + overflow-hidden lets the 0fr row actually clip -->
                    <div class="min-h-0 overflow-hidden">
                      <div class="bg-slate-50/70 py-4 pl-11 pr-5">
                        <p v-if="hasFailedFor(t)" class="text-sm text-slate-500">Unable to load activity.</p>
                        <LeadActivityTimeline v-else inset
                                              :timeline="timelineFor(t)"
                                              :stage-colors="options.stageColors"
                                              :loading="isLoadingFor(t)" />
                      </div>
                    </div>
                  </div>
                </Transition>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <div v-if="todos.data.length" class="divide-y divide-slate-100 lg:hidden">
        <div v-for="t in todos.data" :key="t.id" class="border-l-4 p-4"
             :class="isOverdue(t) ? 'border-l-rose-600 bg-rose-50/40' : 'border-l-transparent'">
          <div class="mb-2 flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-2">
              <button v-if="expandable(t)" type="button"
                      class="mt-0.5 flex h-8 w-8 flex-none items-center justify-center rounded-md border border-slate-200 bg-white text-lg leading-none text-slate-500 transition
                             hover:border-slate-300 hover:text-slate-700
                             focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                      :aria-expanded="isRowExpanded(t) ? 'true' : 'false'"
                      :aria-controls="`todo-activity-${t.id}`"
                      :aria-label="isRowExpanded(t) ? 'Hide activity' : 'Show activity'"
                      @click="toggleActivity(t)">
                <span aria-hidden="true">{{ isRowExpanded(t) ? '−' : '+' }}</span>
              </button>
              <div class="min-w-0">
                <div class="truncate font-semibold" :class="t.lead ? '' : 'italic text-slate-400'">{{ leadName(t) }}</div>
                <div class="text-xs text-slate-400">{{ t.lead?.mobile_number ?? '—' }}</div>
              </div>
            </div>
            <StageBadge v-if="t.lead" :stage="t.lead.stage" />
          </div>

          <dl class="grid grid-cols-2 gap-y-1 text-xs">
            <dt class="text-slate-400">Type</dt><dd class="text-right">{{ options.types[t.type] }}</dd>
            <dt class="text-slate-400">Scheduled</dt><dd class="text-right">{{ fmt(t.scheduled_at) }}</dd>
            <template v-if="t.status === 'pending'">
              <dt class="text-slate-400">Due</dt>
              <dd class="text-right" :class="isOverdue(t) ? 'font-semibold text-rose-700' : ''">
                {{ relative(t.scheduled_at) }}
              </dd>
            </template>
            <template v-else>
              <dt class="text-slate-400">Outcome</dt>
              <dd class="text-right">{{ options.stages[t.outcome_stage] }}</dd>
            </template>
            <template v-if="isAdmin">
              <dt class="text-slate-400">Handled by</dt>
              <dd class="text-right"><AssignedTo :user="t.owner" /></dd>
            </template>
          </dl>

          <p v-if="t.remarks" class="mt-2 text-xs text-slate-500">{{ t.remarks }}</p>

          <!-- the same inline activity panel as the desktop row, animated the
               same way: grid row 0fr -> 1fr plus a fade, motion-safe for users
               who prefer no animation -->
          <Transition
            enter-active-class="motion-safe:transition-[grid-template-rows,opacity] motion-safe:duration-200 motion-safe:ease-out"
            enter-from-class="motion-safe:opacity-0 motion-safe:grid-rows-[0fr]"
            leave-active-class="motion-safe:transition-[grid-template-rows,opacity] motion-safe:duration-200 motion-safe:ease-in"
            leave-to-class="motion-safe:opacity-0 motion-safe:grid-rows-[0fr]"
          >
            <div v-if="isRowExpanded(t) && expandable(t)"
                 :id="`todo-activity-${t.id}`" class="mt-3 grid grid-rows-[1fr]">
              <div class="min-h-0 overflow-hidden">
                <div class="rounded-lg bg-slate-50/70 px-3 py-3">
                  <p v-if="hasFailedFor(t)" class="text-sm text-slate-500">Unable to load activity.</p>
                  <LeadActivityTimeline v-else inset
                                        :timeline="timelineFor(t)"
                                        :stage-colors="options.stageColors"
                                        :loading="isLoadingFor(t)" />
                </div>
              </div>
            </div>
          </Transition>

          <!--
            Full width on a phone: this is the layout where someone is actually
            standing in front of the customer, and the dialler is the point.
          -->
          <div v-if="t.lead?.mobile_number || t.status === 'pending'"
               class="mt-3 space-y-2 border-t border-slate-100 pt-3">
            <CallButtons v-if="t.lead?.mobile_number" :mobile="t.lead.mobile_number" />
            <div v-if="t.status === 'pending'" class="flex gap-2">
              <button class="btn flex-1 py-1.5 text-xs" @click="openComplete(t)">Update</button>
              <button class="btn-xs flex-1" @click="openEdit(t)">Edit</button>
            </div>
          </div>
        </div>
      </div>

      <div class="flex flex-col items-start gap-3 px-4 py-3.5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
        <span>{{ todos.total }} follow-up{{ todos.total === 1 ? '' : 's' }}</span>
        <div class="flex flex-wrap gap-1">
          <Link v-for="link in todos.links" :key="link.label" :href="link.url ?? ''"
                class="rounded-md border px-2.5 py-1 text-xs"
                :class="[link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white',
                         !link.url ? 'pointer-events-none opacity-40' : '']"
                preserve-scroll v-html="link.label" />
        </div>
      </div>
    </div>

    <CompleteTaskModal :show="completeOpen" :todo="active" :options="options" @close="completeOpen = false" />
    <TodoFormModal :show="formOpen" :todo="editing" :options="options" @close="formOpen = false" />
  </AppLayout>
</template>
