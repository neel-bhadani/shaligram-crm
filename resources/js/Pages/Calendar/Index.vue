<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import StageBadge from '@/Components/StageBadge.vue'

const props = defineProps({
  month: String,      // '2026-09' — the month being viewed
  events: Array,      // follow-ups in the visible grid range, each carrying its
                      // IST date string, so nothing here has to re-derive it
  summary: Object,    // { today, upcoming, overdue } under the active filters
  filters: Object,    // { assignee, status, project, stage }
  options: Object,    // users (admin), projects, stages, colors, role labels, today
})

const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

/* ---------------- timezone-safe date arithmetic ----------------
   scheduled_at reaches this page as a pre-grouped 'YYYY-MM-DD' string in the
   app timezone (Asia/Kolkata). Every calculation below works on those strings
   through UTC-midday Date objects, so the browser's own timezone can never
   move a follow-up onto the wrong day. */

const fmtYMD = (y, m, d) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
const daysInMonth = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate()
const weekday = s => new Date(`${s}T12:00:00Z`).getUTCDay() // 0 = Sunday
const addDays = (s, n) => {
  const d = new Date(`${s}T12:00:00Z`)
  d.setUTCDate(d.getUTCDate() + n)
  return fmtYMD(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate())
}
const shiftMonth = (month, n) => {
  const [y, m] = month.split('-').map(Number)
  const total = y * 12 + (m - 1) + n
  return `${Math.floor(total / 12)}-${String((total % 12) + 1).padStart(2, '0')}`
}

/* ---------------- the month's grid ----------------
   The weeks run Sunday → Saturday. Leading and trailing days that belong to
   the adjacent months are part of the grid (and were fetched), but are muted
   and only count as themselves in their own month. */

const grid = computed(() => {
  const [y, m] = props.month.split('-').map(Number)
  const lead = weekday(fmtYMD(y, m, 1))
  const cells = Math.ceil((lead + daysInMonth(y, m)) / 7) * 7
  const start = addDays(fmtYMD(y, m, 1), -lead)
  return Array.from({ length: cells }, (_, i) => addDays(start, i))
})

const monthLabel = computed(() =>
  new Date(`${props.month}-01T12:00:00Z`).toLocaleString('en-IN', { month: 'long', year: 'numeric', timeZone: 'UTC' }))

const isOutside = day => day.slice(0, 7) !== props.month

/* ---------------- events ----------------
   The server sorts by scheduled_at, so grouping keeps that order within a day. */

const byDate = computed(() => {
  const map = {}
  for (const e of props.events) (map[e.date] ??= []).push(e)
  return map
})
const dayEvents = day => byDate.value[day] ?? []

/* the month's own dates, for the mobile agenda (trail days stay on the grid) */
const monthAgenda = computed(() => {
  const [y, m] = props.month.split('-').map(Number)
  const out = []
  for (let d = 1; d <= daysInMonth(y, m); d++) {
    const date = fmtYMD(y, m, d)
    if ((byDate.value[date] ?? []).length) out.push({ date, events: byDate.value[date] })
  }
  return out
})

const monthHasEvents = computed(() => monthAgenda.value.length > 0)

/* ---------------- the four visual states ---------------- */
const statusMeta = {
  overdue: { label: 'Overdue', chip: 'bg-rose-100 font-semibold text-rose-800 hover:bg-rose-200' },
  pending: { label: 'Pending', chip: 'bg-teal-700 text-white hover:bg-teal-800' },
  upcoming: { label: 'Upcoming', chip: 'bg-teal-50 font-medium text-teal-800 hover:bg-teal-100' },
  completed: { label: 'Completed', chip: 'bg-slate-100 text-slate-500 hover:bg-slate-200' },
  cancelled: { label: 'Cancelled', chip: 'bg-slate-100 text-slate-400 line-through' },
}
const chip = e => (statusMeta[e.display_status] ?? statusMeta.pending).chip
const statusLabel = e => (statusMeta[e.display_status] ?? statusMeta.pending).label

/* ---------------- filters ----------------
   Everything the page asks lives in the URL (?month=2026-09&project=3…), so a
   refresh or a back/forward press restores exactly this view. Changing a
   select or stepping a month is one GET with the whole state, filters kept. */

const f = reactive({
  assignee: props.filters.assignee ?? '',
  status: props.filters.status ?? '',
  project: props.filters.project ?? '',
  stage: props.filters.stage ?? '',
})

watch(() => props.filters, nv => {
  f.assignee = nv.assignee ?? ''
  f.status = nv.status ?? ''
  f.project = nv.project ?? ''
  f.stage = nv.stage ?? ''
})

const visit = (extra = {}) => {
  const params = { month: props.month }
  if (f.assignee) params.assignee = f.assignee
  if (f.status) params.status = f.status
  if (f.project) params.project = f.project
  if (f.stage) params.stage = f.stage

  router.get(route('calendar.index', { ...params, ...extra }), {}, {
    preserveState: true,
    preserveScroll: true,
  })
}

const prevMonth = () => visit({ month: shiftMonth(props.month, -1) })
const nextMonth = () => visit({ month: shiftMonth(props.month, 1) })
const thisMonth = () => visit({ month: props.options.today.slice(0, 7) })

const clearFilters = () => {
  f.assignee = ''
  f.status = ''
  f.project = ''
  f.stage = ''
  visit()
}

/* ---------------- daily detail ----------------
   One existing destination for everything: the related lead. There is no
   follow-up detail page, so a row links to the lead (leads.show) — the page
   that already shows its history. */

const detailDate = ref(null)
const detailEvents = computed(() => (detailDate.value ? dayEvents(detailDate.value) : []))

const openDay = date => { detailDate.value = date }

const dateLabel = date => {
  const when = new Date(`${date}T12:00:00Z`).toLocaleString('en-IN', {
    weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC',
  })
  if (date === props.options.today) return `Today · ${when}`
  if (date === addDays(props.options.today, 1)) return `Tomorrow · ${when}`
  return when
}

const fmtTime = e => `${e.time} · ${props.options.todoTypes[e.type] ?? e.type}`
</script>

<template>
  <Head title="Calendar" />

  <AppLayout title="Calendar" subtitle="Follow-ups on their scheduled dates">

    <!-- today · upcoming · overdue — the same scopes the Todos page counts -->
    <div class="grid grid-cols-3 gap-3">
      <div class="card p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-teal-700">Today's Follow-ups</div>
        <div class="mt-1 text-2xl font-bold text-slate-900">{{ summary.today }}</div>
      </div>
      <div class="card p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Upcoming Follow-ups</div>
        <div class="mt-1 text-2xl font-bold text-slate-900">{{ summary.upcoming }}</div>
      </div>
      <div class="card p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-rose-700">Overdue Follow-ups</div>
        <div class="mt-1 text-2xl font-bold text-slate-900">{{ summary.overdue }}</div>
      </div>
    </div>

    <!-- month navigation + filters -->
    <div class="card mt-4">
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3 sm:p-4">
        <button class="btn-ghost px-3 py-2" aria-label="Previous month" @click="prevMonth">
          &larr;
        </button>
        <button class="btn-ghost px-3 py-2" aria-label="Next month" @click="nextMonth">
          &rarr;
        </button>
        <h2 class="min-w-0 flex-1 text-base font-semibold tracking-tight text-slate-900">{{ monthLabel }}</h2>
        <button class="btn-ghost px-3 py-2"
                :class="{ 'pointer-events-none opacity-50': props.month === props.options.today.slice(0, 7) }"
                @click="thisMonth">Today</button>
      </div>

      <div class="flex flex-wrap items-end gap-2 p-3 sm:p-4">
        <select v-if="isAdmin" v-model="f.assignee" class="w-full md:!w-44" aria-label="Assignee"
                @change="visit()">
          <option value="">All assignees</option>
          <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.first_name }} {{ u.last_name }}</option>
        </select>

        <select v-model="f.status" class="w-full md:!w-36" aria-label="Status" @change="visit()">
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="completed">Completed</option>
          <option value="overdue">Overdue</option>
        </select>

        <select v-model="f.project" class="w-full md:!w-44" aria-label="Project" @change="visit()">
          <option value="">All projects</option>
          <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>

        <select v-model="f.stage" class="w-full md:!w-44" aria-label="Stage" @change="visit()">
          <option value="">All stages</option>
          <option v-for="(label, key) in options.stages" :key="key" :value="key">{{ label }}</option>
        </select>

        <button class="btn-ghost w-full md:w-auto" @click="clearFilters">Clear</button>
      </div>
    </div>

    <!-- no follow-ups anywhere in the viewed month (or nothing matched) -->
    <div v-if="!events.length" class="card mt-4 px-5 py-14 text-center text-sm text-slate-500">
      <p class="mb-1 font-semibold text-slate-700">No follow-ups scheduled.</p>
      Follow-ups you schedule will appear on their scheduled date.
    </div>

    <template v-else>
      <!-- ================= desktop calendar =================
           A week grid: date number, up to three chips, then "+N more" which opens
           the day. Cells keep a fixed minimum height and hide anything past the
           third row, so no day can grow into a tower. -->
      <div class="card mt-4 hidden overflow-hidden md:block">
        <div class="grid grid-cols-7 bg-slate-50 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          <div v-for="d in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="d" class="border-b border-slate-200 px-1 py-2">
            {{ d }}
          </div>
        </div>

        <div class="grid grid-cols-7">
          <div v-for="day in grid" :key="day"
               class="flex min-h-28 flex-col border-b border-r border-slate-100 p-1.5 align-top transition-colors"
               :class="[day.slice(0, 7) === props.month
                 ? 'bg-white hover:bg-slate-50'
                 : 'bg-slate-50/60 hover:bg-slate-100']">
            <div class="flex items-center justify-between">
              <button type="button" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs"
                      :class="day === props.options.today
                        ? 'bg-teal-700 font-semibold text-white'
                        : isOutside(day) ? 'text-slate-400 hover:bg-slate-200' : 'font-medium text-slate-700 hover:bg-slate-200'"
                      @click="openDay(day)">
                {{ Number(day.slice(8)) }}
              </button>
              <span v-if="dayEvents(day).length" class="text-[10px] text-slate-400">
                {{ dayEvents(day).length }}
              </span>
            </div>

            <div class="mt-1 flex min-h-0 flex-col">
              <Link v-for="e in dayEvents(day).slice(0, 3)" :key="e.id" :href="e.url"
                    class="mt-0.5 block w-full truncate rounded px-1.5 py-0.5 text-[11px] leading-4"
                    :class="chip(e)">
                {{ e.time }} {{ e.lead?.name ?? 'Lead deleted' }}
              </Link>
              <button v-if="dayEvents(day).length > 3" type="button"
                      class="mt-0.5 w-full rounded px-1.5 py-0.5 text-left text-[11px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                      @click="openDay(day)">
                +{{ dayEvents(day).length - 3 }} more
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ================= mobile agenda =================
           The month grid is unreadable at 320px, so a phone gets the same
           follow-ups as a compact agenda: one section per date that has
           anything, each row linking to the lead. Nothing shrinks below the
           page's normal text sizes. -->
      <div class="card mt-4 md:hidden">
        <div v-if="!monthAgenda.length" class="px-5 py-10 text-center text-sm text-slate-500">
          <p class="mb-1 font-semibold text-slate-700">No follow-ups scheduled.</p>
          Follow-ups you schedule will appear on their scheduled date.
        </div>
        <div v-else class="divide-y divide-slate-100">
          <div v-for="group in monthAgenda" :key="group.date" class="px-4 py-3">
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              {{ dateLabel(group.date) }}
            </div>
            <div v-for="e in group.events" :key="e.id">
              <Link :href="e.url" class="group block rounded-lg px-1 py-1.5 hover:bg-slate-50">
                <div class="flex items-start justify-between gap-2">
                  <span class="min-w-0 font-semibold text-slate-900 group-hover:text-teal-700">
                    {{ e.lead?.name ?? 'Lead deleted' }}
                  </span>
                  <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        :class="chip(e)">{{ statusLabel(e) }}</span>
                </div>
                <div class="mt-0.5 text-xs text-slate-500">
                  {{ fmtTime(e) }}<template v-if="e.project"> · {{ e.project.name }}</template>
                </div>
              </Link>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- +N more / date tap: everything that day, one entry per follow-up -->
    <Modal :show="!!detailDate" :title="detailDate ? dateLabel(detailDate) : ''" @close="detailDate = null">
      <div v-if="!detailEvents.length" class="py-6 text-center text-sm text-slate-500">
        No follow-ups scheduled.
      </div>
      <div v-for="e in detailEvents" :key="e.id" class="border-b border-slate-100 py-2">
        <div class="flex items-center justify-between gap-3">
          <Link :href="e.url" class="font-semibold text-teal-700 hover:underline" @click="detailDate = null">
            {{ e.lead?.name ?? 'Lead deleted' }}
          </Link>
          <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                :class="chip(e)">{{ statusLabel(e) }}</span>
        </div>
        <dl class="mt-1 grid grid-cols-2 gap-y-0.5 text-xs text-slate-500 sm:grid-cols-4">
          <div><dt class="text-slate-400">Time</dt><dd>{{ e.time }}</dd></div>
          <div><dt class="text-slate-400">Assignee</dt><dd>{{ e.assignee?.name ?? '—' }}</dd></div>
          <div><dt class="text-slate-400">Project</dt><dd>{{ e.project?.name ?? '—' }}</dd></div>
          <div><dt class="text-slate-400">Stage</dt><dd><StageBadge v-if="e.lead?.stage" :stage="e.lead.stage" /></dd></div>
        </dl>
      </div>
    </Modal>
  </AppLayout>
</template>