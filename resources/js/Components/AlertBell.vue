<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

/*
 | The bell in the header, on every page and for every role.
 |
 | An alert is not a follow-up. Nothing here completes work, and opening one
 | does not change the lead — it marks the alert read and takes you to the lead
 | so you can decide what to do. That separation is the whole reason alerts are
 | not simply to-dos with a different colour.
 |
 | The count and the last ten arrive as shared Inertia props, so the bell is
 | correct the moment a page paints rather than a second later when a fetch
 | comes back. Read alerts stay in the list: a bell that emptied itself the
 | moment you looked at it gives you no way back to the one you glanced at.
 */
const page = usePage()

const alerts = computed(() => page.props.alerts ?? { unread: 0, recent: [] })
const unread = computed(() => alerts.value.unread ?? 0)
const recent = computed(() => alerts.value.recent ?? [])

const open = ref(false)
const root = ref(null)

/*
 | Severity is colour and nothing else — it does not change who is told, or
 | when, or whether it deduplicates. A dot rather than a filled row, so ten
 | alerts do not read as ten warnings.
 */
const dot = severity => ({
  urgent: 'bg-rose-500',
  warning: 'bg-amber-500',
}[severity] ?? 'bg-slate-300')

const openAlert = alert => {
  open.value = false
  // POST, so reading and navigating are one action and cannot disagree
  router.post(route('alerts.read', alert.id), {}, { preserveScroll: true })
}

const markAllRead = () => {
  router.post(route('alerts.read-all'), {}, { preserveScroll: true, onSuccess: () => { open.value = false } })
}

const onDocClick = e => {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
const onKey = e => { if (e.key === 'Escape') open.value = false }

onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKey)
})
onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKey)
})

const when = iso => {
  if (!iso) return ''
  const then = new Date(iso)
  const mins = Math.round((Date.now() - then.getTime()) / 60000)

  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  if (mins < 1440) return `${Math.round(mins / 60)}h ago`

  return then.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 dark:border-slate-700
             text-slate-500 dark:text-slate-400 transition hover:border-slate-300 dark:hover:border-slate-500 hover:text-slate-700 dark:hover:text-slate-200"
      :class="open ? 'border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-900/60 text-slate-700 dark:text-slate-300' : ''"
      :aria-label="unread ? `${unread} unread alerts` : 'Alerts'"
      :aria-expanded="open"
      @click.stop="open = !open"
    >
      <svg class="h-4.5 w-4.5" style="width:18px;height:18px" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
      </svg>

      <!-- 9+ rather than a number that would burst the badge -->
      <span
        v-if="unread"
        class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full
               bg-rose-600 px-1 text-[10px] font-bold leading-none text-white"
      >{{ unread > 9 ? '9+' : unread }}</span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-11 z-50 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700
             bg-white dark:bg-slate-800 shadow-xl sm:w-96"
    >
      <div class="flex items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-700/60 px-4 py-3">
        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
          Alerts
          <span v-if="unread" class="ml-1 text-xs font-normal text-slate-500 dark:text-slate-400">{{ unread }} unread</span>
        </div>
        <button v-if="unread" class="text-xs font-medium text-teal-700 dark:text-teal-300 hover:underline" @click="markAllRead">
          Mark all read
        </button>
      </div>

      <!--
        The empty state says what alerts ARE rather than "No alerts". Somebody
        opening an empty bell on their first day should come away knowing what
        would have been in it.
      -->
      <div v-if="!recent.length" class="px-4 py-6 text-center">
        <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Nothing to tell you</p>
        <p class="mx-auto mt-1 max-w-64 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
          Alerts appear here when a follow-up goes overdue, a lead stops moving, or an
          automation rule wants you to know something. They are not tasks — reading one
          changes nothing about the lead.
        </p>
      </div>

      <div v-else class="max-h-96 overflow-y-auto">
        <button
          v-for="alert in recent" :key="alert.id"
          class="flex w-full items-start gap-2.5 border-b border-slate-50 px-4 py-3 text-left
                 transition last:border-0 hover:bg-slate-50 dark:hover:bg-slate-700"
          :class="alert.read ? 'opacity-60' : ''"
          @click="openAlert(alert)"
        >
          <span class="mt-1.5 h-2 w-2 flex-none rounded-full" :class="dot(alert.severity)" />
          <span class="min-w-0 flex-1">
            <span class="block text-xs font-semibold leading-snug text-slate-900 dark:text-slate-100">{{ alert.title }}</span>
            <span v-if="alert.body" class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ alert.body }}</span>
            <span class="mt-0.5 block text-[10px] uppercase tracking-wide text-slate-400">
              {{ when(alert.created_at) }}
            </span>
          </span>
        </button>
      </div>

      <Link
        :href="route('alerts.index')"
        class="block border-t border-slate-100 dark:border-slate-700/60 px-4 py-2.5 text-center text-xs font-medium text-teal-700 dark:text-teal-300
               hover:bg-slate-50 dark:hover:bg-slate-700"
        @click="open = false"
      >See all alerts</Link>
    </div>
  </div>
</template>
