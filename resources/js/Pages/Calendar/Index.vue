<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import StageBadge from '@/Components/StageBadge.vue'
import LeadViewModal from '@/Components/LeadViewModal.vue'
import CompleteTaskModal from '@/Components/CompleteTaskModal.vue'
import { withoutEmpty } from '@/lib/withoutEmpty.js'

/*
 | The follow-up calendar. One month of follow-ups on the day they are
 | scheduled, chosen by the server and drawn as a Sun-to-Sat grid.
 |
 | Most of the arithmetic is the server's — which dates the month's cells cover
 | (`range`), which day is today (`options.today`, in IST), which follow-ups
 | fall in that window and what state each is in. The grid here is built from
 | `range`, not recomputed from the month, so a cell and the query that fed it
 | can never disagree about what a weekend spillover day contains.
 */
const props = defineProps({
  month: String,
  monthLabel: String,
  range: Object,
  events: Array,
  summary: Object,
  filters: Object,
  options: Object,
})

const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/* ---------------- state ---------------- */

const f = reactive({
  assigned_to: props.filters.assigned_to ?? '',
  status: props.filters.status ?? '',
  project_id: props.filters.project_id ?? '',
  stage: props.filters.stage ?? '',
})

// back/forward lands on a different URL, which is a different filter set —
// bring the controls with it
watch(() => props.filters, (filters) => {
  f.assigned_to = filters.assigned_to ?? ''
  f.status = filters.status ?? ''
  f.project_id = filters.project_id ?? ''
  f.stage = filters.stage ?? ''
})

/*
 | Every visit sends the whole state — month + all four filters + reset=1 — so
 | the request is the complete instruction, exactly as every other page sends
 | its own. Unlike them, `month` stays in the address bar: it is navigation,
 | so a refresh keeps the month and the back and forward buttons walk it.
 */
const visit = (params) =>
  router.get(route('calendar.index'), withoutEmpty({ reset: 1, ...params }), {
    preserveState: true,
    preserveScroll: true,
  })

const push = () => visit({ month: props.month, ...f })

const clear = () => {
  f.status = ''
  f.assigned_to = ''
  f.project_id = ''
  f.stage = ''
  push()
}

// a month navigated by the back or forward buttons is a different month —
// close whatever was open, before it shows a cell that no longer exists
watch(() => props.month, () => { dayOpen.value = false; viewOpen.value = false; completeOpen.value = false })

/* ---------------- month navigation ---------------- */

const monthDate = computed(() => {
  const [y, m] = props.month.split('-').map(Number)
  return new Date(y, m - 1, 1)
})

const pad = n => String(n).padStart(2, '0')

const shifted = (n) => {
  const d = new Date(monthDate.value)
  d.setDate(1)
  d.setMonth(d.getMonth() + n)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`
}

const gotoToday = () => visit({ month: props.options.today.slice(0, 7), ...f })

/* ---------------- the grid ---------------- */

const eventsByDate = computed(() => {
  const map = {}
  for (const e of props.events) (map[e.date] ??= []).push(e)
  for (const date in map) map[date].sort((a, b) => a.time.localeCompare(b.time))
  return map
})

const grid = computed(() => {
  const cells = []
  const d = new Date(`${props.range.start}T00:00:00`)
  const end = new Date(`${props.range.end}T00:00:00`)
  const y = monthDate.value.getFullYear()
  const m = monthDate.value.getMonth()

  while (d <= end) {
    const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
    cells.push({
      date,
      day: d.getDate(),
      inMonth: d.getFullYear() === y && d.getMonth() === m,
      isToday: date === props.options.today,
      events: eventsByDate.value[date] ?? [],
    })
    d.setDate(d.getDate() + 1)
  }

  const weeks = []
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7))
  return weeks
})

/* ---------------- events ---------------- */

const timeLabel = (e) => {
  const [h, m] = e.time.split(':').map(Number)
  const hr = h % 12 || 12
  return `${hr}:${pad(m)} ${h >= 12 ? 'PM' : 'AM'}`
}

/* the same four states the server names, in the app's own palette: danger
   for overdue, warning for today, success for done, neutral for ahead */
const STATE_CLASS = {
  overdue: 'bg-rose-100 dark:bg-rose-500/15 text-rose-700 dark:text-rose-300',
  pending: 'bg-amber-100 dark:bg-amber-500/15 text-amber-800 dark:text-amber-300',
  upcoming: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
  completed: 'bg-emerald-100 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 line-through',
}

const DOT_CLASS = {
  overdue: 'bg-rose-500',
  pending: 'bg-amber-500',
  upcoming: 'bg-slate-400',
  completed: 'bg-emerald-500',
}

const stateLabel = {
  overdue: 'Overdue',
  pending: 'Due today',
  upcoming: 'Upcoming',
  completed: 'Completed',
}

const stateClass = e => STATE_CLASS[e.state] ?? STATE_CLASS.upcoming

/* how many rows a cell may print before it becomes "+N more" */
const MAX_ROWS = 3

/* ---------------- day panel & lead view ---------------- */

const dayOpen = ref(false)
const dayDate = ref('')
const dayEvents = ref([])

const openDay = (cell) => {
  dayDate.value = cell.date
  dayEvents.value = cell.events
  dayOpen.value = true
}

const dayTitle = computed(() => {
  const d = new Date(`${dayDate.value}T00:00:00`)
  return d.toLocaleDateString('en-IN',
    { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
})

const viewOpen = ref(false)
const viewId = ref(null)
const openLead = (e) => {
  viewId.value = e.lead.id
  viewOpen.value = true
}

/*
 | "Update follow-up", reached from inside the lead popup. LeadViewModal hands
 | back the full lead it already fetched (mobile, stage, owner and the pending
 | todo among it) — CompleteTaskModal wants that shape nested under a todo, so
 | it is wrapped rather than re-fetched.
 */
const completeOpen = ref(false)
const completeTodo = ref(null)
const openFollowUp = (lead) => {
  completeTodo.value = { id: lead.pending_todo.id, lead }
  viewOpen.value = false
  completeOpen.value = true
}

/*
 | Closing it — by Cancel, the ✕, or a successful save — returns to the lead
 | popup it was opened from, rather than dropping straight back to the grid.
 |
 | The return waits out Modal's own 150ms leave transition (see Modal.vue)
 | before opening the lead popup again. Flipping both in the same tick played
 | one modal's closing animation and the other's opening animation over each
 | other and looked like a stutter; run one after the other, it reads as a
 | single "back" motion. The wait also means the lead popup's onshow fetch
 | runs after the save has landed, so what comes back reflects the update.
 */
const closeFollowUp = () => {
  completeOpen.value = false
  setTimeout(() => { viewOpen.value = true }, 150)
}

/* ---------------- empty states ---------------- */

const hasEvents = computed(() => props.events.length > 0)
const hasFilters = computed(() => Boolean(f.status || f.assigned_to || f.project_id || f.stage))
</script>

<template>
  <Head title="Calendar" />

  <AppLayout title="Calendar" subtitle="Follow-ups by the day they are due">
    <!--
      The summary strip. Same markup as ReportKpis (Dashboard, both report
      pages) — one bordered card with the tiles ruled apart inside it, rather
      than three separate cards — so this reads as the same kind of number as
      everywhere else it is shown. Same definitions as every other page: due
      today is pending and scheduled today, upcoming is pending and scheduled
      after it, waiting longer is pending and scheduled before it. The filters
      narrow them, so a month of Vanam's pending calls is a different answer to
      the same question.
    -->
    <div class="mb-4 grid grid-cols-1 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 sm:grid-cols-3">
      <div class="border-b border-slate-100 dark:border-slate-700/60 p-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0">
        <div class="text-2xl font-bold tracking-tight tabular-nums text-slate-900 dark:text-slate-100">{{ summary.today }}</div>
        <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Today's follow-ups</div>
        <div class="mt-1.5 text-[11px] text-slate-400">Due today</div>
      </div>
      <div class="border-b border-slate-100 dark:border-slate-700/60 p-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0">
        <div class="text-2xl font-bold tracking-tight tabular-nums text-slate-900 dark:text-slate-100">{{ summary.upcoming }}</div>
        <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Upcoming follow-ups</div>
        <div class="mt-1.5 text-[11px] text-slate-400">Scheduled ahead of today</div>
      </div>
      <div class="border-b border-slate-100 dark:border-slate-700/60 p-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0">
        <div class="text-2xl font-bold tracking-tight tabular-nums"
             :class="summary.overdue ? 'text-rose-700 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100'">{{ summary.overdue }}</div>
        <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Overdue follow-ups</div>
        <div class="mt-1.5 text-[11px] text-slate-400">Waiting longer</div>
      </div>
    </div>

    <div class="card overflow-hidden">
      <!-- filters -->
      <div class="flex flex-wrap gap-2 border-b border-slate-100 dark:border-slate-700/60 p-3 sm:p-4">
        <select v-model="f.status" class="w-full md:!w-40" aria-label="Status" @change="push">
          <option value="">Status · All</option>
          <option value="pending">Pending</option>
          <option value="completed">Completed</option>
          <option value="overdue">Overdue</option>
        </select>

        <select v-if="isAdmin" v-model="f.assigned_to" class="w-full md:!w-44" aria-label="Assigned to" @change="push">
          <option value="">Assigned to · All</option>
          <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.first_name }} {{ u.last_name }}</option>
        </select>

        <select v-model="f.project_id" class="w-full md:!w-44" aria-label="Project" @change="push">
          <option value="">Project · All</option>
          <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>

        <select v-model="f.stage" class="w-full md:!w-44" aria-label="Stage" @change="push">
          <option value="">Stage · All</option>
          <option v-for="(label, key) in options.stages" :key="key" :value="key">{{ label }}</option>
        </select>

        <button class="btn-ghost w-full md:w-auto" @click="clear">Clear</button>
      </div>

      <!-- month navigation -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-700/60 px-3 py-3 sm:px-4">
        <div class="flex gap-2">
          <button class="btn-ghost px-3" :aria-label="`Previous month`" @click="visit({ month: shifted(-1), ...f })">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M15 18l-6-6 6-6" />
            </svg>
            <span class="ml-1 hidden sm:inline">Previous</span>
          </button>
          <button class="btn-ghost px-3" :aria-label="`Next month`" @click="visit({ month: shifted(1), ...f })">
            <span class="mr-1 hidden sm:inline">Next</span>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M9 6l6 6-6 6" />
            </svg>
          </button>
        </div>

        <h2 class="order-first w-full text-center text-base font-semibold text-slate-900 dark:text-slate-100 sm:order-none sm:w-auto">
          {{ monthLabel }}
        </h2>

        <button class="btn-ghost px-3" @click="gotoToday">Today</button>
      </div>

      <!-- weekday header -->
      <div class="grid grid-cols-7 border-b border-slate-100 dark:border-slate-700/60 bg-slate-50 dark:bg-slate-900/60">
        <div v-for="d in DAYS" :key="d"
             class="py-2 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          {{ d }}
        </div>
      </div>

      <!-- the grid -->
      <div class="border-l border-t border-slate-100 dark:border-slate-700/60 px-2 pt-2 sm:px-3">
        <div v-for="(week, wi) in grid" :key="wi" class="grid grid-cols-7">
          <div v-for="cell in week" :key="cell.date"
               class="flex min-h-[72px] flex-col border-b border-r border-slate-100 dark:border-slate-700/60 p-1 md:min-h-[108px] md:p-1.5"
               :class="cell.isToday
                 ? 'bg-teal-50/70 dark:bg-teal-500/10 ring-1 ring-inset ring-teal-700 dark:ring-teal-500'
                 : cell.inMonth ? 'bg-white dark:bg-slate-800' : 'bg-slate-50/60 dark:bg-slate-900/40'">
            <div class="flex items-center justify-between gap-1">
              <button
                type="button"
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                :class="cell.isToday ? 'bg-teal-700 text-white' : cell.inMonth ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400'"
                :title="cell.isToday ? 'Today' : undefined"
                @click="openDay(cell)"
              >{{ cell.day }}</button>

              <!-- count badge, the mobile answer to a crowded cell -->
              <span v-if="cell.events.length"
                    class="rounded-full bg-slate-200 dark:bg-slate-600 px-1.5 text-[10px] font-bold tabular-nums text-slate-700 dark:text-slate-300 md:hidden"
                    @click="openDay(cell)">{{ cell.events.length }}</span>
            </div>

            <!-- event rows, desktop/tablet only; a phone opens the day panel -->
            <div class="mt-1 hidden flex-1 flex-col gap-1 md:flex">
              <button
                v-for="e in cell.events.slice(0, MAX_ROWS)"
                :key="e.id"
                type="button"
                class="truncate rounded px-1.5 py-0.5 text-left text-[11px] font-medium leading-tight"
                :class="stateClass(e)"
                :title="`${timeLabel(e)} · ${e.lead.name}`"
                @click="openLead(e)"
              >
                {{ timeLabel(e) }} {{ e.lead.name }}
              </button>

              <button v-if="cell.events.length > MAX_ROWS"
                      type="button"
                      class="rounded bg-white dark:bg-slate-800 px-1.5 py-0.5 text-left text-[11px] font-semibold text-teal-700 dark:text-teal-300 hover:bg-teal-50 dark:hover:bg-teal-500/10"
                      @click="openDay(cell)"
              >+{{ cell.events.length - MAX_ROWS }} more</button>
            </div>
          </div>
        </div>
      </div>

      <!-- empty / message -->
      <div v-if="!hasEvents" class="px-5 py-14 text-center text-sm text-slate-500 dark:text-slate-400">
        <template v-if="hasFilters">
          <p class="mb-1 font-semibold text-slate-700 dark:text-slate-300">No follow-ups match</p>
          Try clearing the filters.
        </template>
        <template v-else>
          <p class="mb-1 font-semibold text-slate-700 dark:text-slate-300">No follow-ups scheduled.</p>
          Follow-ups due in {{ monthLabel }} will appear here.
        </template>
      </div>

      <p v-else class="px-4 py-3 text-xs leading-relaxed text-slate-400">
        Follow-ups sit on their scheduled date, counted in the app timezone (IST). Click a day
        to see everything on it; click a follow-up to open its lead.
      </p>
    </div>

    <!-- the day panel: every follow-up on one date -->
    <Modal :show="dayOpen" :title="dayTitle" @close="dayOpen = false">
      <div v-if="!dayEvents.length" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">
        <p class="mb-1 font-semibold text-slate-700 dark:text-slate-300">No follow-ups scheduled.</p>
        Nothing is due on this day.
      </div>

      <ol v-else class="divide-y divide-slate-100 dark:divide-slate-700/60">
        <li v-for="e in dayEvents" :key="e.id" class="flex items-start gap-3 py-2.5">
          <span class="mt-0.5 w-16 shrink-0 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ timeLabel(e) }}</span>
          <div class="min-w-0 flex-1">
            <button type="button" class="text-left text-sm font-semibold text-slate-900 dark:text-slate-100 hover:text-teal-700"
                    @click="openLead(e)">
              {{ e.lead.name }}
            </button>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
              <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="stateClass(e)">
                {{ stateLabel[e.state] }}
              </span>
              <template v-if="e.assignee"><span>{{ e.assignee.name }}</span><span>&middot;</span></template>
              <template v-if="e.project"><span>{{ e.project.name }}</span><span>&middot;</span></template>
              <span>{{ options.types[e.type] ?? e.type }}</span>
              <span v-if="e.lead.stage" class="inline-block"><StageBadge :stage="e.lead.stage" /></span>
            </div>
            <p v-if="e.remark" class="mt-1 break-words text-xs text-slate-500 dark:text-slate-400">{{ e.remark }}</p>
          </div>
        </li>
      </ol>
    </Modal>

    <LeadViewModal :show="viewOpen" :lead-id="viewId" :options="options" allow-follow-up :allow-edit="false"
                   @close="viewOpen = false" @followup="openFollowUp" />

    <CompleteTaskModal :show="completeOpen" :todo="completeTodo" :options="options"
                        @close="closeFollowUp" />
  </AppLayout>
</template>