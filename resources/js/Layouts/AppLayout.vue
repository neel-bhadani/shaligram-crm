<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AlertBell from '../Components/AlertBell.vue'

defineProps({ title: String, subtitle: String })

const page = usePage()
const user = computed(() => page.props.auth.user)
const open = ref(false)

/*
 | Channel Partners, Users and Integrations are admin-only and are left out of
 | the array rather than rendered disabled — a greyed link advertises a page
 | somebody cannot reach. This is presentation only: `role:admin` on each route
 | group is what actually refuses a telecaller who types /channel-partners,
 | /users or /integrations into the address bar, and all three come back 403
 | rather than empty.
 |
 | Channel Partners sits next to Leads rather than next to Users, because it is
 | reference data the Leads page reads — the broker picker on the lead form is
 | this list — and not a staff-administration screen.
 */
const nav = computed(() => [
  { name: 'Dashboard', href: route('dashboard'), active: route().current('dashboard') },
  { name: 'Leads',     href: route('leads.index'), active: route().current('leads.*') },
  { name: 'Follow-ups', href: route('todos.index'), active: route().current('todos.*') },
  /*
   | Alerts is for everyone, and deliberately so. A telecaller is told about
   | their own overdue follow-ups and carries an unread count in the header on
   | every page; hiding the page they would land on would leave that count
   | pointing at nothing. Each person sees only the alerts addressed to them.
   */
  { name: 'Alerts', href: route('alerts.index'), active: route().current('alerts.*') },
  ...(user.value?.role === 'admin'
    ? [
        /*
         | Automation sits directly under Alerts because the two are read
         | together: a rule raises an alert, and the alert is how you find out
         | the rule did something. Admin-only here and admin-only for real —
         | `role:admin` on the route group is what refuses a telecaller who
         | types /automation, and they get a 403 rather than an empty page.
         */
        {
          name: 'Automation',
          href: route('automation.index'),
          active: route().current('automation.*'),
        },
        /*
         | Projects and Channel Partners sit together because they are the same
         | kind of thing: reference data the Add lead form reads, not staff
         | administration. Projects is first of the two — every lead in the
         | database points at one, and a broker is optional.
         |
         | Admin-only here and admin-only for real: `role:admin` on the route
         | group is what refuses a telecaller who types /projects, and they get
         | a 403 rather than an empty page.
         */
        { name: 'Projects', href: route('projects.index'), active: route().current('projects.*') },
        {
          name: 'Channel Partners',
          href: route('channel-partners.index'),
          active: route().current('channel-partners.*'),
        },
        /*
         | Stages & Sources sits with Projects and Channel Partners because it
         | is the same kind of thing — reference data every lead points at, not
         | staff administration. Last of the three, because it is the one that
         | is set up once and rarely opened again.
         |
         | Admin-only here and admin-only for real: `role:admin` on the route
         | group is what refuses a telecaller who types /pipeline, and they get
         | a 403 rather than an empty page.
         */
        {
          name: 'Stages & Sources',
          href: route('pipeline.index'),
          active: route().current('pipeline.*'),
        },
        { name: 'Users', href: route('users.index'), active: route().current('users.*') },
        /*
         | Integrations is last because it is the one nobody opens twice: it is
         | set up once and then only visited when leads have stopped arriving.
         |
         | Admin-only here and admin-only for real — `role:admin` on the route
         | group in routes/web.php is what refuses a telecaller who types
         | /integrations, and they get a 403 rather than an empty page. Leaving
         | the link out is presentation; the middleware is the answer.
         */
        {
          name: 'Integrations',
          href: route('integrations.index'),
          active: route().current('integrations.*'),
        },
      ]
    : []),
])

/*
 | Reports: seven links, two pages.
 |
 | Every link is one of the two report routes with a different `group` in the
 | query string — "Leads · By source" and "Leads · By project" are the same page
 | asked a different question, and six routes would have been six copies of one
 | filter bar, one chart and one table.
 |
 | Both sections list groupings and nothing else. The four Follow-ups statuses
 | used to sit here too, and that was the bug: a follow-ups report is always a
 | grouping AND a status, so a flat list of both meant two items described the
 | page and two items lit up. They are two axes, so they get two controls — the
 | statuses are tabs on the page now, where a second axis can sit beside the
 | first without either pretending to be the whole selection.
 |
 | With one axis per section the items are genuine alternatives: they name the
 | same key with different values, so at most one can match and exactly one
 | does.
 |
 | The assigned-to links are left out for anyone who cannot see past their own
 | rows, on the same test each report uses to decide whether to offer the
 | grouping at all — every row would be that person. Presentation only: the
 | privacy boundary is scopeVisibleTo and scopeForUser inside ReportController,
 | and a telecaller who types the query string is given the default grouping
 | over their own data rather than a 403.
 */
const wideLeads = computed(() => !! user.value?.seeAllLeads)
const wideTodos = computed(() => user.value?.role === 'admin')

const withHrefs = (routeName, items) =>
    items.map(i => ({ ...i, href: route(routeName, { group: i.group }) }))

const reports = computed(() => [
  {
    name: 'Leads',
    routeName: 'reports.leads',
    items: withHrefs('reports.leads', [
      { name: 'By stage',   group: 'stage' },
      { name: 'By source',  group: 'source' },
      { name: 'By project', group: 'project' },
      /*
       | Not gated, unlike By assigned to. Partner names are not admin-only —
       | the lead form's broker picker offers the same list to everyone who can
       | file a lead — and the counts on the report are scoped to the viewer's
       | own leads by scopeVisibleTo, exactly as By source is. The roster with
       | its phone numbers and addresses is the admin-only thing, and that is
       | the Channel Partners page above.
       */
      { name: 'By channel partner', group: 'channel_partner' },
      ...(wideLeads.value ? [{ name: 'By assigned to', group: 'assigned_to' }] : []),
    ]),
  },
  {
    name: 'Follow-ups',
    routeName: 'reports.followups',
    items: withHrefs('reports.followups', [
      { name: 'By type',        group: 'type' },
      ...(wideTodos.value ? [{ name: 'By assigned to', group: 'assigned_to' }] : []),
    ]),
  },
])

const onReports = computed(() => route().current('reports.*'))

/*
 | Closed by default, and the click is the last word.
 |
 | Ten items left permanently open dominate a sidebar whose other four are one
 | line each, so the group starts shut and opens on two occasions: the user
 | opens it, or they arrive on a report — landing on a page whose menu entry is
 | hidden behind a closed heading is disorienting, and the watcher covers that
 | because AppLayout survives an Inertia visit and `setup` does not run again.
 |
 | It is a ref the watcher writes rather than `open || onReports`, which is what
 | it was: that version could not be closed while you were standing on a report,
 | because the computed put it straight back open on the next tick.
 */
const showReports = ref(false)

watch(onReports, (isOn) => { if (isOn) showReports.value = true }, { immediate: true })

/*
 | Which child link is the one you are on.
 |
 | The grouping, and nothing else. Every item in both sections is a grouping, so
 | this compares one key against one key — which is what stops two items
 | claiming the same page. The status is a separate axis with a separate control
 | on the page, and it has no say here: changing tab must not move the sidebar,
 | and changing grouping must not move the tabs.
 |
 | The answer comes from the resolved filters rather than from the URL, because
 | route().current() cannot tell two links to the same route apart, and because
 | the address bar is wiped clean once the visit lands.
 */
const activeChild = (group, item) =>
  route().current(group.routeName) && (page.props.filters ?? {}).group === item.group

const logout = () => router.post(route('logout'))

/*
 | The header is stuck to the top, so it needs to read as a layer only once
 | there is something underneath it. Flat at the top of the page, shadow after
 | the first couple of pixels of scroll.
 */
const scrolled = ref(false)
const onScroll = () => { scrolled.value = window.scrollY > 2 }

onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
})
onUnmounted(() => window.removeEventListener('scroll', onScroll))
</script>

<template>
  <div class="min-h-screen bg-slate-100">

    <!-- scrim behind the mobile drawer -->
    <div v-show="open" class="fixed inset-0 z-30 bg-slate-900/45 lg:hidden" @click="open = false" />

    <!-- sidebar -->
    <!--
      Three regions, and only the middle one scrolls.
      
      The brand and the user block are `shrink-0`, so a flex column can never
      squeeze them to make room for a long menu. The nav is `flex-1 min-h-0`:
      a flex item's automatic minimum size is its content, and a scroll
      container is exempt from that only for as long as it stays a scroll
      container — `min-h-0` says it outright, so the rule holds even if the
      overflow value is ever changed here.

      The padding moved off the aside and onto the three regions. On the aside
      it was a frame around all three, which is why the user block used to float
      20px clear of the bottom edge instead of sitting on it, and why scrolled
      menu items stopped short of the edge rather than running under it.
    -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-slate-900 text-white
             transition-transform duration-200 lg:translate-x-0"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex shrink-0 items-center gap-2 border-b border-white/10 px-5 pb-4 pt-5 font-bold">
        <span class="block h-6 w-6 rounded border-2 border-teal-500"></span>
        Shaligram CRM
        <button class="ml-auto text-xl text-slate-400 lg:hidden" @click="open = false">&times;</button>
      </div>

      <!--
        overscroll-contain so reaching the end of the menu on a phone does not
        hand the rest of the gesture to the page behind the drawer.
      -->
      <nav class="nav-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain p-3">
        <Link
          v-for="item in nav" :key="item.name" :href="item.href"
          class="mb-0.5 flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium transition-colors"
          :class="item.active ? 'bg-teal-700 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
          @click="open = false"
        >{{ item.name }}</Link>

        <!--
          Reports is a disclosure, not a link. There is no /reports landing page
          to send anyone to — the section is its two report pages — so the
          heading opens the group and the children are what navigate.

          Being inside the section is NOT the same as being the selection. The
          parent goes white against its slate-400 siblings and takes no fill;
          the teal fill is reserved for the one thing that is actually selected,
          which is a child. Filling both made the sidebar read as two selections
          at once.
        -->
        <button
          class="mb-0.5 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left font-medium
                 transition-colors"
          :class="onReports ? 'text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
          :aria-expanded="showReports" aria-controls="reports-nav"
          @click="showReports = !showReports"
        >
          Reports
          <!--
            A plain stroked chevron in the text's own colour, so it belongs to
            the row rather than sitting on it. It was a ▶ glyph, which the
            emoji font renders as a filled blue triangle at whatever size it
            likes — the one element in the sidebar that matched nothing else.
          -->
          <svg class="ml-auto h-3.5 w-3.5 transition-transform duration-200"
               :class="showReports ? 'rotate-90' : ''"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
               aria-hidden="true">
            <path d="M9 6l6 6-6 6" />
          </svg>
        </button>

        <!--
          Tighter than the top level, deliberately: half the vertical padding
          and no gap under the group labels, so ten children read as one block
          belonging to the row above rather than as ten more nav items. It is
          also what keeps the whole menu inside a laptop viewport with the
          group open.
        -->
        <div v-show="showReports" id="reports-nav" class="mb-1 ml-3 border-l border-white/10 pl-2">
          <div v-for="group in reports" :key="group.name">
            <div class="px-3 pb-0.5 pt-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
              {{ group.name }}
            </div>

            <Link
              v-for="item in group.items" :key="item.name" :href="item.href"
              class="block rounded-md px-3 py-1 text-sm transition-colors"
              :class="activeChild(group, item)
                ? 'bg-teal-700 font-medium text-white'
                : 'text-slate-400 hover:bg-white/5 hover:text-white'"
              @click="open = false"
            >{{ item.name }}</Link>
          </div>
        </div>
      </nav>

      <div class="shrink-0 px-5 py-4">
        <div class="border-t border-white/10 pt-4">
          <div class="text-sm font-semibold">{{ user.name }}</div>
          <div class="text-xs capitalize text-slate-400">{{ user.role }}</div>
          <!-- every role has a profile; it lives with the name it edits -->
          <div class="mt-2 flex items-center gap-3 text-xs">
            <Link :href="route('account.edit')"
                  :class="route().current('account.*') ? 'text-white' : 'text-slate-400 hover:text-white'"
                  @click="open = false">My profile</Link>
            <button class="text-slate-400 hover:text-white" @click="logout">Sign out</button>
          </div>
        </div>
      </div>
    </aside>

    <!-- main -->
    <div class="lg:ml-60">
      <!--
        Stuck to the top on every page, at z-20: over the page content, under
        the drawer scrim (z-30), the drawer itself (z-40) and the modals (z-60),
        so all three still cover it. Solid white, and a border that only shows
        up once there is content passing underneath.

        Below sm the action buttons wrap onto their own full-width row, which
        would freeze about 130px at the top of a phone. Only the title row is
        stuck there; the buttons live in the bar below and scroll away with the
        page. From sm up they sit back on the title row and stick with it.
      -->
      <header
        class="sticky top-0 z-20 flex flex-nowrap items-start justify-between gap-3 border-b
               lg:flex-wrap lg:items-center lg:gap-4
               border-slate-200 bg-white px-4 py-4 transition-shadow duration-200 sm:px-7"
        :class="{ 'shadow-sm': scrolled, 'max-sm:border-transparent': !scrolled && $slots.actions }"
      >
        <div class="flex min-w-0 flex-1 items-start gap-3 lg:flex-initial lg:items-center">
          <button
            class="shrink-0 rounded-lg border border-slate-200 p-2 lg:hidden"
            aria-label="Open menu" @click="open = true"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 6h18M3 12h18M3 18h18" />
            </svg>
          </button>
          <div class="min-w-0 flex-1 lg:flex-initial">
            <h1 class="break-words text-lg font-semibold tracking-tight text-slate-900 lg:truncate">{{ title }}</h1>
            <p v-if="subtitle" class="break-words text-sm text-slate-500 lg:truncate">{{ subtitle }}</p>
          </div>
        </div>
        <!--
          The bell sits in the stuck header on every page and at every width,
          outside the actions block that collapses onto its own row below sm.
          An unread count that disappeared on a phone would be an unread count
          nobody acted on.
        -->
        <div class="flex shrink-0 flex-row-reverse items-center gap-2 lg:shrink lg:flex-row">
          <AlertBell />
          <div class="hidden flex-wrap items-center gap-2 sm:flex">
            <slot name="actions" />
          </div>
        </div>
      </header>

      <!--
        The same actions below sm, where they are not part of the stuck header.
        Only one of the two copies is ever rendered on screen: this one is
        display:none from sm up, the one in the header is display:none below it,
        which takes the popovers inside them along with it.
      -->
      <div v-if="$slots.actions"
           class="flex w-full flex-wrap items-center gap-2 border-b border-slate-200 bg-white
                  px-4 pb-4 sm:hidden">
        <slot name="actions" />
      </div>

      <main class="mx-auto max-w-[1500px] px-4 pb-12 pt-5 sm:px-7">
        <slot />
      </main>
    </div>

  </div>
</template>
