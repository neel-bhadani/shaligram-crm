<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import StageBadge from '@/Components/StageBadge.vue'

/*
 | The sign-in modal: what is owed today, said once.
 |
 | Modal.vue does all of the dialog work — the overlay, Escape, the close
 | button, the click-away, the sticky header and footer, the bottom sheet on a
 | phone and the scrolling body between them. Nothing here re-implements any of
 | it, and nothing here traps the reader: all three ways out are Modal's own and
 | every one of them just emits `close`.
 |
 | The server decides whether this exists at all. `digest` arrives once per
 | session, and only when there is something owed, so there is no empty state
 | in here and no "have we shown this already" check either. It is also the
 | server that decides what is in it: an admin is sent the team's rows grouped
 | by owner, everyone else is sent their own in a single unnamed group. This
 | file does no filtering, because it is never handed anything to filter.
 */
const props = defineProps({
  show: Boolean,
  digest: { type: Object, default: null },
})

defineEmits(['close'])

const total = computed(() => props.digest?.total ?? 0)
const groups = computed(() => props.digest?.groups ?? [])
const more = computed(() => props.digest?.more ?? 0)

const countLine = computed(() =>
  `You have ${total.value} ${total.value === 1 ? 'call' : 'calls'} due today or earlier`)

/*
 | A row from an earlier day prints its date; one from today prints the clock
 | alone. That is the only thing `earlier` changes — same list, same styling,
 | no second bucket. "10:30 AM" against a call from last Tuesday would be the
 | one genuinely misleading way to show these.
 */
const clock = v => new Date(v).toLocaleTimeString('en-IN',
  { hour: '2-digit', minute: '2-digit', hour12: true })

const stamp = v => new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true })

const when = row => row.at ? (row.earlier ? stamp(row.at) : clock(row.at)) : '—'
</script>

<template>
  <Modal :show="show" title="Your follow-ups today" max-width="max-w-lg" @close="$emit('close')">
    <p class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ countLine }}</p>

    <div class="mt-4 space-y-5">
      <section v-for="(group, i) in groups" :key="group.name ?? i">
        <!--
          Admin only: for anyone else the server sends one group with no name
          and this heading is not rendered, so a list that is entirely your own
          does not carry your own name down the side of it.

          The count is that person's whole workload, which is why it can be
          larger than the number of rows beneath it — the modal lists the first
          few and the footer of the list accounts for the rest.
        -->
        <h4 v-if="group.name"
            class="mb-2 flex items-baseline justify-between gap-2 border-b border-slate-100 dark:border-slate-700/60 pb-1.5">
          <span class="truncate text-sm font-semibold text-slate-700 dark:text-slate-300">{{ group.name }}</span>
          <span class="flex-none text-xs text-slate-400">{{ group.count }}</span>
        </h4>

        <!--
          Two lines on every row, and the same shape on each: the name and the
          time on the first, the number and the stage on the second. The
          right-hand column is a fixed track so the time lands at the same x on
          every row rather than wherever that row's name happens to end.

          7rem, because en-IN renders the longest stamp as "01 Sept, 01:12 pm"
          — September is the one four-letter month — which measures about 105px
          at text-xs with tabular figures. 6.5rem would have clipped it by a
          pixel on exactly one month of the year.
        -->
        <ul class="divide-y divide-slate-100 dark:divide-slate-700/60">
          <li v-for="row in group.rows" :key="row.id"
              class="grid grid-cols-[minmax(0,1fr)_7rem] items-center gap-x-3 gap-y-1 py-2.5">
            <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
              {{ row.name ?? 'Lead deleted' }}
            </span>

            <span class="whitespace-nowrap text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
              {{ when(row) }}
            </span>

            <span class="col-span-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
              <span class="w-20 shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ row.mobile ?? '—' }}</span>
              <StageBadge v-if="row.stage" :stage="row.stage" />
            </span>
          </li>
        </ul>
      </section>
    </div>

    <p v-if="more > 0" class="mt-4 text-xs text-slate-400">and {{ more }} more</p>

    <template #footer>
      <!--
        Ghost then primary, the order every other modal in the app uses. Both
        dismiss for the session: Close emits it, and the link navigates away,
        by which time the server has already recorded that this session was
        told.
      -->
      <button type="button" class="btn-ghost flex-1 sm:flex-none" @click="$emit('close')">
        Close
      </button>

      <Link :href="route('todos.index', { tab: 'today' })" class="btn flex-1 sm:flex-none">
        Go to my follow-ups
      </Link>
    </template>
  </Modal>
</template>
