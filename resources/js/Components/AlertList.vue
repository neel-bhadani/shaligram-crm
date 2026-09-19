<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useFilterVisit } from '@/composables/useFilterVisit.js'

/*
 | The alerts list, rendered once and used twice: the /alerts page every user
 | can open, and the Alerts tab on the Automation page. Two copies would drift
 | the day somebody adds a severity, and the copy that was not updated would
 | quietly stop showing rows.
 |
 | Severity is colour and nothing else. It does not change who is told, when
 | they are told, or whether the alert deduplicates — it is the difference
 | between "worth knowing" and "deal with this today", drawn so a full list can
 | be skimmed.
 */
const props = defineProps({
  alerts: { type: Object, required: true },      // a Laravel paginator
  filters: { type: Object, required: true },
  counts: { type: Object, required: true },
  thresholds: { type: Object, default: () => ({}) },
  // the Automation tab shows the first page and points at /alerts for the rest
  paginate: { type: Boolean, default: true },
  /*
   | Where the filter buttons navigate to.
   |
   | The same list is rendered on two different routes, and the filters have to
   | stay on whichever one the reader is standing on — a severity chip on the
   | Automation page's Alerts tab that jumped to /alerts would throw away the
   | four other tabs they were working in. Both routes resolve the filters the
   | same way, through the ListsAlerts trait, so only the destination differs.
   */
  routeName: { type: String, default: 'alerts.index' },
  routeParams: { type: Object, default: () => ({}) },
})

const rows = computed(() => props.alerts.data ?? [])

const tone = severity => ({
  urgent: { dot: 'bg-rose-500', chip: 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-500/30', label: 'Urgent' },
  warning: { dot: 'bg-amber-500', chip: 'bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-500/30', label: 'Warning' },
}[severity] ?? { dot: 'bg-slate-300', chip: 'bg-slate-50 dark:bg-slate-900/60 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700', label: 'Info' })

// the same filter visit as every other list page: the filters go to the server,
// which keeps them in the session, and then come back out of the address bar
const { visit } = useFilterVisit(route(props.routeName, props.routeParams))

const setStatus = status => visit({ ...props.filters, status })
const setSeverity = severity => visit({ ...props.filters, severity })

const openAlert = alert => router.post(route('alerts.read', alert.id), {}, { preserveScroll: true })

const markAllRead = () => router.post(route('alerts.read-all'), {}, { preserveScroll: true })

const when = iso => {
  if (!iso) return ''

  return new Date(iso).toLocaleString('en-IN', {
    day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit',
  })
}
</script>

<template>
  <div>
    <!-- ---------------- filters ---------------- -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="s in [
            { key: 'all', label: `All (${counts.all})` },
            { key: 'unread', label: `Unread (${counts.unread})` },
            { key: 'read', label: 'Read' },
          ]"
          :key="s.key" class="btn-xs"
          :class="filters.status === s.key ? 'border-teal-600 bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-300' : ''"
          @click="setStatus(s.key)"
        >{{ s.label }}</button>
      </div>

      <span class="hidden h-4 w-px bg-slate-200 dark:bg-slate-600 sm:block" />

      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="s in [
            { key: 'all', label: 'Any level' },
            { key: 'urgent', label: `Urgent (${counts.urgent})` },
            { key: 'warning', label: `Warning (${counts.warning})` },
            { key: 'info', label: `Info (${counts.info})` },
          ]"
          :key="s.key" class="btn-xs"
          :class="filters.severity === s.key ? 'border-teal-600 bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-300' : ''"
          @click="setSeverity(s.key)"
        >{{ s.label }}</button>
      </div>

      <button v-if="counts.unread" class="btn-xs ml-auto" @click="markAllRead">Mark all read</button>
    </div>

    <!-- ---------------- empty ---------------- -->
    <!--
      Not "No alerts". Somebody looking at an empty list on their first day
      should come away knowing what would have been in it and how it differs
      from the Follow-ups page they already use.
    -->
    <div v-if="!rows.length" class="card px-6 py-10 text-center">
      <p class="text-base font-semibold text-slate-800 dark:text-slate-200">
        {{ filters.status === 'all' && filters.severity === 'all'
          ? 'Nothing to tell you yet'
          : 'Nothing matches those filters' }}
      </p>

      <template v-if="filters.status === 'all' && filters.severity === 'all'">
        <p class="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-slate-500 dark:text-slate-400">
          An alert is something you should know about — a follow-up that has gone overdue, a lead
          that has stopped moving, or an automation rule flagging something. It is
          <strong>not</strong> a task: reading an alert changes nothing about the lead, and it
          never appears on your Follow-ups page.
        </p>
        <p v-if="thresholds.overdue_days" class="mx-auto mt-3 max-w-lg text-xs text-slate-400">
          You will be told automatically when a follow-up is more than
          {{ thresholds.overdue_days }} days overdue, or when a lead has sat in one stage for more
          than {{ thresholds.stuck_days }} days. The same alert is never repeated within
          {{ thresholds.dedupe_hours }} hours.
        </p>
      </template>

      <button v-else class="btn-ghost mt-4" @click="go({ status: 'all', severity: 'all' })">
        Clear filters
      </button>
    </div>

    <!-- ---------------- rows ---------------- -->
    <div v-else class="card divide-y divide-slate-100 dark:divide-slate-700/60">
      <button
        v-for="alert in rows" :key="alert.id"
        class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition hover:bg-slate-50 dark:hover:bg-slate-700"
        :class="alert.read_at ? 'opacity-60' : ''"
        @click="openAlert(alert)"
      >
        <span class="mt-1.5 h-2 w-2 flex-none rounded-full" :class="tone(alert.severity).dot" />

        <span class="min-w-0 flex-1">
          <span class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ alert.title }}</span>
            <span
              class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
              :class="tone(alert.severity).chip"
            >{{ tone(alert.severity).label }}</span>
            <span v-if="!alert.read_at"
                  class="rounded bg-teal-50 dark:bg-teal-500/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase
                         tracking-wide text-teal-700 dark:text-teal-300">New</span>
          </span>

          <span v-if="alert.body" class="mt-1 block text-xs leading-relaxed text-slate-600 dark:text-slate-300">
            {{ alert.body }}
          </span>

          <span class="mt-1.5 block text-[11px] text-slate-400">
            {{ when(alert.created_at) }}
            <template v-if="alert.rule"> · from the rule “{{ alert.rule.name }}”</template>
            <template v-else> · raised automatically</template>
          </span>
        </span>
      </button>
    </div>

    <!-- ---------------- paging ---------------- -->
    <div v-if="paginate && alerts.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
      <component
        :is="link.url ? Link : 'span'"
        v-for="link in alerts.links" :key="link.label"
        :href="link.url" preserve-scroll
        class="rounded-md border px-2.5 py-1 text-xs"
        :class="link.active
          ? 'border-teal-600 bg-teal-50 dark:bg-teal-500/10 font-semibold text-teal-700 dark:text-teal-300'
          : link.url ? 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                     : 'border-slate-100 dark:border-slate-700/60 bg-white dark:bg-slate-800 text-slate-300'"
        v-html="link.label"
      />
    </div>

    <div v-else-if="!paginate && alerts.total > rows.length" class="mt-3 text-center">
      <Link :href="route('alerts.index')" class="text-xs font-medium text-teal-700 dark:text-teal-300 hover:underline">
        See all {{ alerts.total }} alerts
      </Link>
    </div>
  </div>
</template>
