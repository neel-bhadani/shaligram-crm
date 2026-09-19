<script setup>
import { ref, computed, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StageFormModal from '@/Components/StageFormModal.vue'
import SourceFormModal from '@/Components/SourceFormModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

/*
 | Stages & Sources — the vocabulary every other page in this application
 | speaks, edited by the admin instead of by a developer.
 |
 | Admin only. `role:admin` on the route group is what enforces that; nothing on
 | this page is a permission check, it is all presentation of one the server
 | already made.
 |
 | ---------------------------------------------------------------------------
 | There is no Delete on most rows, and that is the design
 | ---------------------------------------------------------------------------
 |
 | `leads.stage`, `leads.source` and `todos.outcome_stage` hold these keys as
 | bare strings. Deleting a row somebody has filed work under does not tidy
 | anything, it turns every one of those strings into a word the application no
 | longer knows — and the reports that counted them quietly start returning
 | different numbers. So the button an admin actually wants is the IN USE
 | toggle, which changes nothing at all: the row stops being offered in
 | dropdowns and every lead, every history row and every past report stays
 | exactly as it was.
 |
 | Delete survives only for a row nothing has ever been written in. The server
 | decides that — see PipelineController — and sends the reason down with each
 | row, so the tooltip on the greyed-out button and the message in the refusal
 | are the same sentence.
 |
 | ---------------------------------------------------------------------------
 | Order is the axis order
 | ---------------------------------------------------------------------------
 |
 | `sort_order` is not decoration on this screen. It drives the stage dropdown,
 | every zero-filled chart axis, the Leads page's chip strip and the funnel's
 | bands. Dragging a row here changes all of them, which is why the reorder
 | saves immediately rather than waiting behind a Save button somebody would
 | leave unpressed.
 |
 | Drag AND two arrow buttons. Pointer drag is what an admin reaches for and it
 | is unusable from a keyboard and awkward on a phone, so the same move is a
 | button as well — the arrows are the real control and the drag is the
 | shortcut, not the other way round.
 */
const props = defineProps({ tab: String, stages: Array, sources: Array, options: Object })

const tab = ref(props.tab ?? 'stages')

watch(() => props.tab, v => { if (v) tab.value = v })

/*
 | The tab lives in the URL so a reload, a back button and a link all land on
 | the same one. `preserveState` keeps the modals and the drag list as they are;
 | `only: []` asks for no props back, because nothing on the page depends on
 | which tab is open — the server already sent both lists.
 */
const switchTab = next => {
  tab.value = next
  router.get(route('pipeline.index'), { tab: next },
    { preserveState: true, preserveScroll: true, replace: true, only: [] })
}

/* ---------------- the rows, as this page holds them ---------------- */

/*
 | A local copy, because a drag has to redraw the list before the server has
 | agreed to it — reading props directly would snap the row back to where it
 | was for the length of the round trip. Replaced wholesale whenever the server
 | sends new rows, so the truth is still the server's.
 */
const rows = ref([])
const syncRows = () => { rows.value = tab.value === 'stages' ? [...props.stages] : [...props.sources] }

watch([() => props.stages, () => props.sources, tab], syncRows, { immediate: true })

const isStages = computed(() => tab.value === 'stages')

/* ---------------- reordering ---------------- */

const dragging = ref(null)
const savingOrder = ref(false)

const onDragStart = index => { dragging.value = index }

const onDragOver = index => {
  if (dragging.value === null || dragging.value === index) return

  const list = [...rows.value]
  list.splice(index, 0, list.splice(dragging.value, 1)[0])
  rows.value = list
  dragging.value = index
}

const onDrop = () => {
  if (dragging.value === null) return
  dragging.value = null
  saveOrder()
}

/** The arrow buttons: the same move, from a keyboard. */
const nudge = (index, by) => {
  const to = index + by

  if (to < 0 || to >= rows.value.length) return

  const list = [...rows.value]
  list.splice(to, 0, list.splice(index, 1)[0])
  rows.value = list
  saveOrder()
}

/*
 | The whole order every time, never "move row 4 above row 2". A numbered list
 | is idempotent: a dropped response the browser retries, or two admins dragging
 | at once, cannot leave the pipeline in an order neither of them chose.
 */
const saveOrder = () => {
  savingOrder.value = true

  router.post(
    route(isStages.value ? 'pipeline.stages.reorder' : 'pipeline.sources.reorder'),
    { order: rows.value.map(r => r.id) },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => { savingOrder.value = false },
      // whatever the server made of it wins — syncRows() runs on the new props
      onError: syncRows,
    },
  )
}

/* ---------------- add / edit ---------------- */

const stageForm = ref(false)
const sourceForm = ref(false)
const editing = ref(null)

const openAdd = () => {
  editing.value = null
  isStages.value ? (stageForm.value = true) : (sourceForm.value = true)
}

const openEdit = row => {
  editing.value = row
  isStages.value ? (stageForm.value = true) : (sourceForm.value = true)
}

const closeForm = () => { stageForm.value = false; sourceForm.value = false; editing.value = null }

/* ---------------- the in-use toggle ---------------- */

/*
 | Switching something ON is a plain save. Switching it OFF asks first, and the
 | question names the number of leads it is about to hide — an admin retiring
 | "In discussion" should not find out afterwards that thirty leads were sitting
 | in it. It is not a destructive act and the dialog does not pretend it is:
 | nothing moves, nothing is deleted, and clicking the toggle back undoes it.
 */
const deactivating = ref(null)

const toggle = row => {
  // switching something back on takes nothing away, so it needs no question
  row.is_active ? (deactivating.value = row) : submitToggle(row, true)
}

/*
 | The toggle posts the whole row, because LeadStageRequest requires a label and
 | a colour on every save and a PUT carrying only `is_active` would fail
 | validation on two fields the user never saw. Building it here keeps the
 | request class honest — it always receives a complete stage.
 */
const submitToggle = (row, active) => {
  const payload = isStages.value
    ? { label: row.label, color: row.color, is_terminal: row.is_terminal, is_active: active }
    : {
        label: row.label,
        default_stage_key: row.default_stage_key || null,
        default_owner_role: row.default_owner_role || null,
        is_active: active,
      }

  router.put(
    route(isStages.value ? 'pipeline.stages.update' : 'pipeline.sources.update', row.id),
    payload,
    { preserveScroll: true, onSuccess: () => { deactivating.value = null } },
  )
}

const deactivateMessage = computed(() => {
  const row = deactivating.value

  if (!row) return ''

  const noun = isStages.value ? 'stage' : 'source'
  const leads = row.leads === 1 ? '1 lead' : `${row.leads} leads`

  const held = isStages.value
    ? `${leads} ${row.leads === 1 ? 'is' : 'are'} sitting in this stage right now.`
    : `${leads} ${row.leads === 1 ? 'came' : 'came'} from this source.`

  const rules = row.rules.length
    ? ` ${row.rules.length === 1 ? 'An automation rule uses' : 'Automation rules use'} it: ${row.rules.join(', ')} — ${row.rules.length === 1 ? 'it' : 'they'} will stop having an effect.`
    : ''

  return `${held} They keep it, and every past report still counts them — it just stops being offered when anybody picks a ${noun}.${rules}`
})

/* ---------------- delete ---------------- */

const deleting = ref(null)
const deleteProcessing = ref(false)

const confirmDelete = () => {
  deleteProcessing.value = true

  router.delete(
    route(isStages.value ? 'pipeline.stages.destroy' : 'pipeline.sources.destroy', deleting.value.id),
    {
      preserveScroll: true,
      onFinish: () => { deleteProcessing.value = false; deleting.value = null },
    },
  )
}

const deleteMessage = computed(() => deleting.value
  ? `Delete "${deleting.value.label}" for good? Nothing has ever been filed under it, so there is nothing to lose — but it cannot be undone.`
  : '')

/** Why a row's Delete is greyed out, or '' when it is not. */
const lockReason = row => row.cannot_delete ?? ''

/* ---------------- routing ---------------- */

/*
 | Who a lead added at this stage goes to. Terminal stages route to nobody new —
 | the lead stays with whoever added it.
 */
const deskLabel = row => (row.owner_role ? (props.options.roles[row.owner_role] ?? row.owner_role) : 'Whoever adds it')

/*
 | Marked for as long as it stays set, not only in the toast when it was saved:
 | a stage past the site visit on the telecaller desk is allowed, but somebody
 | opening this screen next month should still be able to see it.
 */
const misrouted = row => row.past_handover && row.owner_role === 'telecaller'
const misroutedTitle = 'Past the site visit, but new leads here go to a telecaller — they have no reason to call, and no salesperson sees the lead.'
</script>

<template>
  <Head title="Stages & sources" />

  <AppLayout title="Stages &amp; sources" subtitle="The pipeline every lead moves through, and where leads come from">
    <template #actions>
      <button class="btn w-full sm:w-auto" @click="openAdd">
        {{ isStages ? 'Add stage' : 'Add source' }}
      </button>
    </template>

    <div class="card overflow-hidden">

      <!-- tabs -->
      <div class="flex gap-1 border-b border-slate-100 dark:border-slate-700/60 p-3 sm:px-4">
        <button
          v-for="t in [{ key: 'stages', label: 'Stages' }, { key: 'sources', label: 'Sources' }]"
          :key="t.key"
          class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
          :class="tab === t.key ? 'bg-slate-900 text-white' : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-600'"
          @click="switchTab(t.key)"
        >
          {{ t.label }}
        </button>

        <span v-if="savingOrder" class="self-center pl-2 text-xs text-slate-400">Saving order…</span>
      </div>

      <p class="border-b border-slate-100 dark:border-slate-700/60 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-500 dark:text-slate-400">
        <template v-if="isStages">
          Drag a row, or use the arrows, to change the order. That order is the order of
          every dropdown, every chart axis and the funnel.
          A new lead goes to the desk its stage names, whoever adds it.
          Switching a stage off hides it from the dropdowns — leads already in it keep it,
          and every past report still counts them.
        </template>
        <template v-else>
          Switching a source off hides it from the Add lead form. Leads already filed under
          it keep it, and every past report still counts them.
        </template>
      </p>

      <table class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 dark:bg-slate-900/60 text-left text-xs text-slate-500 dark:text-slate-400">
            <th class="w-10 px-2 py-2.5"></th>
            <th class="px-4 py-2.5 font-semibold">{{ isStages ? 'Stage' : 'Source' }}</th>
            <th class="px-4 py-2.5 font-semibold">Key</th>
            <th v-if="isStages" class="px-4 py-2.5 font-semibold">New leads go to</th>
            <th v-if="!isStages" class="px-4 py-2.5 font-semibold">Default stage</th>
            <th v-if="!isStages" class="px-4 py-2.5 font-semibold">Default owner</th>
            <th class="px-4 py-2.5 font-semibold">Leads</th>
            <th class="px-4 py-2.5 font-semibold">In use</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, i) in rows" :key="row.id"
            class="border-b border-slate-100 dark:border-slate-700/60"
            :class="[row.is_active ? '' : 'bg-slate-50/60', dragging === i ? 'opacity-50' : '']"
            draggable="true"
            @dragstart="onDragStart(i)"
            @dragover.prevent="onDragOver(i)"
            @drop.prevent="onDrop"
            @dragend="onDrop"
          >
            <td class="px-2 py-3 align-middle">
              <div class="flex flex-col items-center gap-0.5">
                <button class="text-xs leading-none text-slate-300 hover:text-slate-600 dark:hover:text-slate-300 disabled:opacity-30"
                        :disabled="i === 0" :aria-label="`Move ${row.label} up`" @click="nudge(i, -1)">▲</button>
                <span class="cursor-grab select-none text-slate-300" aria-hidden="true">⠿</span>
                <button class="text-xs leading-none text-slate-300 hover:text-slate-600 dark:hover:text-slate-300 disabled:opacity-30"
                        :disabled="i === rows.length - 1" :aria-label="`Move ${row.label} down`" @click="nudge(i, 1)">▼</button>
              </div>
            </td>

            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <span v-if="isStages" class="h-3 w-3 flex-none rounded-full"
                      :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                <span class="font-semibold" :class="row.is_active ? '' : 'text-slate-500 dark:text-slate-400'">{{ row.label }}</span>
              </div>
              <div class="mt-1 flex flex-wrap gap-1.5">
                <!--
                  The system marker. Not a warning: these rows are perfectly
                  editable, they just cannot be renamed at the key, switched off
                  or deleted, because PHP names them.
                -->
                <span v-if="row.is_system"
                      class="rounded-full bg-slate-200 dark:bg-slate-600 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:text-slate-300"
                      title="Named in the application's own code. Its label and colour are yours; its key is not.">
                  Built in
                </span>
                <span v-if="row.is_handover"
                      class="rounded-full bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 text-[11px] font-medium text-indigo-700 dark:text-indigo-300"
                      title="Reaching this stage moves the lead from a telecaller to a salesperson.">
                  Handover
                </span>
                <span v-if="isStages && row.is_terminal"
                      class="rounded-full bg-slate-100 dark:bg-slate-700 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:text-slate-300"
                      title="A lead here is closed — no follow-up is booked.">
                  Ends the journey
                </span>
              </div>
            </td>

            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ row.key }}</td>

            <td v-if="isStages" class="px-4 py-3">
              <span :class="row.owner_role ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400'">{{ deskLabel(row) }}</span>
              <span v-if="misrouted(row)"
                    class="ml-1.5 rounded-full bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:text-amber-300"
                    :title="misroutedTitle">
                Past the site visit
              </span>
            </td>

            <td v-if="!isStages" class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ row.default_stage ?? '—' }}</td>
            <td v-if="!isStages" class="px-4 py-3 text-slate-500 dark:text-slate-400">
              {{ row.default_owner_role ? (options.roles[row.default_owner_role] ?? row.default_owner_role) : '—' }}
            </td>

            <td class="px-4 py-3 tabular-nums">{{ row.leads }}</td>

            <td class="px-4 py-3">
              <button
                class="rounded-full px-2 py-0.5 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50"
                :class="row.is_active ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300'"
                :disabled="row.is_active && !!row.cannot_deactivate"
                :title="row.is_active ? (row.cannot_deactivate || 'Switch off') : 'Switch back on'"
                @click="toggle(row)"
              >
                {{ row.is_active ? 'In use' : 'Off' }}
              </button>
            </td>

            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5">
                <button class="btn-xs" @click="openEdit(row)">Edit</button>
                <button class="btn-xs disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!!lockReason(row)" :title="lockReason(row)"
                        @click="deleting = row">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- phones and tablets: the same rows as cards, arrows only, no drag -->
      <div class="divide-y divide-slate-100 dark:divide-slate-700/60 lg:hidden">
        <div v-for="(row, i) in rows" :key="row.id" class="p-4"
             :class="row.is_active ? '' : 'bg-slate-50/60'">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span v-if="isStages" class="h-3 w-3 flex-none rounded-full"
                      :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                <span class="truncate font-semibold">{{ row.label }}</span>
              </div>
              <div class="mt-0.5 font-mono text-xs text-slate-400">{{ row.key }}</div>
              <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ row.leads }} {{ row.leads === 1 ? 'lead' : 'leads' }}
                <template v-if="row.is_system"> · Built in</template>
                <template v-if="isStages && row.is_terminal"> · Ends the journey</template>
              </div>
              <div v-if="isStages" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                New leads: {{ deskLabel(row) }}
                <span v-if="misrouted(row)" class="font-medium text-amber-800 dark:text-amber-300" :title="misroutedTitle">
                  · past the site visit
                </span>
              </div>
            </div>

            <div class="flex flex-none flex-col items-end gap-1.5">
              <button
                class="rounded-full px-2 py-0.5 text-xs font-medium disabled:opacity-50"
                :class="row.is_active ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300'"
                :disabled="row.is_active && !!row.cannot_deactivate"
                @click="toggle(row)"
              >{{ row.is_active ? 'In use' : 'Off' }}</button>

              <div class="flex gap-1">
                <button class="btn-xs" :disabled="i === 0" @click="nudge(i, -1)">▲</button>
                <button class="btn-xs" :disabled="i === rows.length - 1" @click="nudge(i, 1)">▼</button>
              </div>
            </div>
          </div>

          <div class="mt-3 flex gap-1.5">
            <button class="btn-xs" @click="openEdit(row)">Edit</button>
            <button class="btn-xs disabled:opacity-40" :disabled="!!lockReason(row)"
                    :title="lockReason(row)" @click="deleting = row">Delete</button>
          </div>
        </div>
      </div>
    </div>

    <StageFormModal :show="stageForm" :stage="editing" :options="options" @close="closeForm" />
    <SourceFormModal :show="sourceForm" :source="editing" :options="options" @close="closeForm" />

    <ConfirmDialog
      :show="deactivating !== null"
      :title="`Switch ${deactivating?.label} off?`"
      :message="deactivateMessage"
      confirm-text="Switch off"
      @close="deactivating = null"
      @confirm="submitToggle(deactivating, false)"
    />

    <ConfirmDialog
      :show="deleting !== null"
      :title="`Delete ${deleting?.label}?`"
      :message="deleteMessage"
      :processing="deleteProcessing"
      @close="deleting = null"
      @confirm="confirmDelete"
    />
  </AppLayout>
</template>
