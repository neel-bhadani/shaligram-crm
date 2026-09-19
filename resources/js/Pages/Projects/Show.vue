<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ProjectFormModal from '@/Components/ProjectFormModal.vue'

/*
 | One project, and what has actually happened on it.
 |
 | The two breakdowns answer deliberately different questions and are labelled
 | so on screen, because they do not add up to the same number and a reader who
 | assumes they do will conclude the page is broken:
 |
 |   BY STAGE   where the leads stand right now. Every lead is in exactly one
 |              row, so it sums to the lead total.
 |
 |   BY SOURCE  where the leads came from. Also sums to the total.
 |
 |   THE KPIs   what has happened, ever — site visits and bookings come from
 |              the follow-up history, so a lead that visited in March and was
 |              lost in June is counted as a visit and sits under Lost in the
 |              stage breakdown. Both are true.
 |
 | Everything here is all-time. A project is a multi-year thing, and a 30-day
 | window would make a development that finished selling last year look dead.
 | The page says "All time" rather than leaving it to be guessed at.
 */
const props = defineProps({
  project: Object,
  totals: Object,
  byStage: Array,
  bySource: Array,
  leads: Array,
  salespeople: Array,
  options: Object,
})

const editOpen = ref(false)

/*
 | Who handles this project: the people its salesperson round robin takes turns
 | among. Every active salesperson is listed; saving removes only the ones shown
 | here unticked, so somebody switched off keeps their projects.
 |
 | The warning reads what is SAVED, not the boxes as they stand — it describes
 | what happens to the next lead, and unsaved ticks do not change that.
 */
const team = useForm({
  salesperson_ids: props.salespeople.filter(s => s.assigned).map(s => s.id),
})

const unstaffed = computed(() => !props.salespeople.some(s => s.assigned))

const teamError = computed(() => Object.values(team.errors)[0] ?? null)

const saveTeam = () => team.put(route('projects.salespeople.update', props.project.id), {
  preserveScroll: true,
  onSuccess: () => team.defaults(),
})

/* an em dash, never 0% — see ProjectController */
const pct = v => v === null || v === undefined ? '—' : `${v}%`

const kpis = computed(() => [
  { v: props.totals.leads, l: 'Leads', d: 'Filed against this project, all time' },
  { v: props.totals.visits, l: 'Site visits', d: 'Leads that have visited, ever' },
  { v: props.totals.booked, l: 'Bookings', tone: 'good', d: 'Leads that have booked, ever' },
  {
    v: pct(props.totals.conversion), l: 'Conversion',
    tone: props.totals.conversion === null ? null : 'good',
    d: 'Bookings ÷ every lead on this project',
  },
])

/* the drill-through: reset=1 makes the link the whole instruction, so nothing
   left in the Leads page's session narrows the list further than this project */
const allLeadsHref = route('leads.index', { reset: 1, project_id: props.project.id })

const widest = computed(() => Math.max(1, ...props.byStage.map(s => s.total)))

const when = iso => iso
  ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
  : '—'
</script>

<template>
  <Head :title="project.name" />

  <AppLayout :title="project.name"
             :subtitle="[project.location, project.type_label].filter(Boolean).join(' · ')">
    <template #actions>
      <Link :href="route('projects.index')" class="btn-ghost">All projects</Link>
      <button class="btn" @click="editOpen = true">Edit project</button>
    </template>

    <div class="space-y-5">

      <!-- ---------------- header ---------------- -->
      <div class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="project.is_active
                      ? 'bg-emerald-50 text-emerald-800'
                      : 'bg-slate-200 text-slate-600'">
                {{ project.is_active ? 'Selling now' : 'Inactive' }}
              </span>
              <span class="text-xs text-slate-400">{{ project.type_label }}</span>
              <span v-if="project.location" class="text-xs text-slate-400">· {{ project.location }}</span>
            </div>

            <p v-if="project.description"
               class="mt-2 max-w-3xl whitespace-pre-wrap text-sm leading-relaxed text-slate-600">
              {{ project.description }}
            </p>
          </div>

          <p class="flex-none text-xs text-slate-400">
            Added {{ when(project.created_at) }}
            <template v-if="project.creator"> by {{ project.creator }}</template>
          </p>
        </div>

        <p v-if="!project.is_active" class="warn-box mt-3">
          This project is switched off, so it does not appear on the Add lead form and no new
          leads can be filed against it. Everything below is unchanged and stays that way.
        </p>
      </div>

      <!-- ---------------- salespeople ---------------- -->
      <div class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold text-slate-900">Salespeople on this project</h2>
            <p class="mt-0.5 text-xs text-slate-400">
              When a lead on this project needs a salesperson, it goes to the next of the people
              ticked here, in turn.
            </p>
          </div>
          <button v-if="salespeople.length" class="btn flex-none"
                  :disabled="team.processing || !team.isDirty" @click="saveTeam">
            Save salespeople
          </button>
        </div>

        <p v-if="!salespeople.length" class="warn-box mt-3">
          There are no active salespeople. Leads that need one stay with whoever added them until a
          salesperson is added or switched on from the Users page.
        </p>

        <template v-else>
          <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            <label v-for="s in salespeople" :key="s.id"
                   class="flex items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
              <input v-model="team.salesperson_ids" type="checkbox" :value="s.id"
                     class="h-4 w-4 flex-none rounded border-slate-300" />
              <span class="truncate">{{ s.name }}</span>
            </label>
          </div>

          <p v-if="teamError" class="mt-2 text-xs text-rose-700">{{ teamError }}</p>

          <p v-if="unstaffed" class="warn-box mt-3">
            Nobody is assigned to this project. Its leads that need a salesperson go to any active
            salesperson instead, and every admin is alerted when that happens.
          </p>
        </template>
      </div>

      <!-- ---------------- KPIs ---------------- -->
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="k in kpis" :key="k.l" class="card p-4">
          <div class="text-2xl font-semibold tabular-nums"
               :class="k.tone === 'good' ? 'text-emerald-700' : 'text-slate-900'">{{ k.v }}</div>
          <div class="mt-0.5 text-sm font-medium text-slate-700">{{ k.l }}</div>
          <div class="mt-0.5 text-xs text-slate-400">{{ k.d }}</div>
        </div>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">

        <!-- ---------------- by stage ---------------- -->
        <div class="card p-4">
          <h2 class="text-sm font-semibold text-slate-900">Where the leads stand</h2>
          <p class="mt-0.5 text-xs text-slate-400">
            Every lead on this project, in the stage it is in today. Adds up to
            {{ totals.leads }}.
          </p>

          <div v-if="!totals.leads" class="py-8 text-center text-sm text-slate-500">
            No leads have been filed against this project yet.
          </div>

          <div v-else class="mt-4 space-y-2">
            <div v-for="s in byStage" :key="s.key" class="flex items-center gap-3">
              <span class="w-36 flex-none truncate text-xs text-slate-600">{{ s.label }}</span>
              <span class="h-4 flex-1 overflow-hidden rounded bg-slate-100">
                <span class="block h-full rounded transition-all"
                      :style="{
                        width: `${Math.round(s.total / widest * 100)}%`,
                        backgroundColor: options.stageColors[s.key] ?? '#94a3b8',
                      }" />
              </span>
              <span class="w-10 flex-none text-right text-xs tabular-nums text-slate-700">
                {{ s.total }}
              </span>
              <span class="w-12 flex-none text-right text-xs tabular-nums text-slate-400">
                {{ pct(s.share) }}
              </span>
            </div>
          </div>
        </div>

        <!-- ---------------- by source ---------------- -->
        <div class="card p-4">
          <h2 class="text-sm font-semibold text-slate-900">Where the leads came from</h2>
          <p class="mt-0.5 text-xs text-slate-400">
            Sources nobody used are left out rather than drawn as empty rows.
          </p>

          <div v-if="!bySource.length" class="py-8 text-center text-sm text-slate-500">
            Nothing to break down yet.
          </div>

          <!-- table on desktop, cards on mobile: the same pattern as the other pages -->
          <table v-else class="mt-4 hidden w-full text-sm lg:table">
            <thead>
              <tr class="text-left text-xs text-slate-400">
                <th class="pb-1.5 font-semibold">Source</th>
                <th class="pb-1.5 text-right font-semibold">Leads</th>
                <th class="pb-1.5 text-right font-semibold">Share</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in bySource" :key="s.key" class="border-t border-slate-100">
                <td class="py-1.5 text-slate-700">{{ s.label }}</td>
                <td class="py-1.5 text-right tabular-nums">{{ s.total }}</td>
                <td class="py-1.5 text-right tabular-nums text-slate-400">{{ pct(s.share) }}</td>
              </tr>
            </tbody>
          </table>

          <div v-if="bySource.length" class="mt-4 divide-y divide-slate-100 lg:hidden">
            <div v-for="s in bySource" :key="s.key" class="flex items-center justify-between gap-3 py-1.5">
              <span class="text-sm text-slate-700">{{ s.label }}</span>
              <span class="flex-none text-xs tabular-nums text-slate-400">
                <span class="text-slate-700">{{ s.total }}</span> · {{ pct(s.share) }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- ---------------- leads ---------------- -->
      <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-4">
          <div>
            <h2 class="text-sm font-semibold text-slate-900">Latest leads</h2>
            <p class="mt-0.5 text-xs text-slate-400">
              The {{ options.leadPreview }} most recent. The full list, with filters and search,
              is on the Leads page.
            </p>
          </div>
          <Link :href="allLeadsHref" class="btn-ghost">
            See all {{ totals.leads }} lead{{ totals.leads === 1 ? '' : 's' }}
          </Link>
        </div>

        <div v-if="!leads.length" class="px-5 py-12 text-center">
          <p class="mb-1 text-sm font-semibold text-slate-700">No leads yet</p>
          <p class="mx-auto max-w-md text-sm text-slate-500">
            Leads filed against this project from the Add lead form will appear here.
          </p>
        </div>

        <table v-else class="hidden w-full text-sm lg:table">
          <thead>
            <tr class="bg-slate-50 text-left text-xs text-slate-500">
              <th class="px-4 py-2.5 font-semibold">Name</th>
              <th class="px-4 py-2.5 font-semibold">Mobile</th>
              <th class="px-4 py-2.5 font-semibold">Stage</th>
              <th class="px-4 py-2.5 font-semibold">Source</th>
              <th class="px-4 py-2.5 font-semibold">With</th>
              <th class="px-4 py-2.5 font-semibold">Added</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="lead in leads" :key="lead.id" class="border-b border-slate-100">
              <td class="px-4 py-3 font-medium">{{ lead.name }}</td>
              <td class="px-4 py-3 tabular-nums text-slate-500">{{ lead.mobile }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium text-white"
                      :style="{ backgroundColor: options.stageColors[lead.stage] ?? '#94a3b8' }">
                  {{ lead.stageLabel }}
                </span>
              </td>
              <td class="px-4 py-3 text-slate-500">{{ lead.source }}</td>
              <td class="px-4 py-3 text-slate-500">{{ lead.owner ?? '—' }}</td>
              <td class="px-4 py-3 text-slate-500">{{ when(lead.created_at) }}</td>
            </tr>
          </tbody>
        </table>

        <div v-if="leads.length" class="divide-y divide-slate-100 lg:hidden">
          <div v-for="lead in leads" :key="lead.id" class="p-4">
            <div class="mb-1.5 flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="truncate font-semibold">{{ lead.name }}</div>
                <div class="truncate text-xs tabular-nums text-slate-400">{{ lead.mobile }}</div>
              </div>
              <span class="flex-none rounded-full px-2 py-0.5 text-xs font-medium text-white"
                    :style="{ backgroundColor: options.stageColors[lead.stage] ?? '#94a3b8' }">
                {{ lead.stageLabel }}
              </span>
            </div>
            <div class="text-xs text-slate-400">
              {{ lead.source }} · {{ lead.owner ?? 'Unassigned' }} · {{ when(lead.created_at) }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <ProjectFormModal :show="editOpen" :project="project" :options="options"
                      @close="editOpen = false" />
  </AppLayout>
</template>
