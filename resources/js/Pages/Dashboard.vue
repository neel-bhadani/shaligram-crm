<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChartCard from '@/Components/ChartCard.vue'
import CrossFilterChips from '@/Components/CrossFilterChips.vue'
import DateRangePicker from '@/Components/DateRangePicker.vue'
import FunnelChart from '@/Components/FunnelChart.vue'
import KpiTile from '@/Components/KpiTile.vue'
import StageBadge from '@/Components/StageBadge.vue'
import TileHeader from '@/Components/TileHeader.vue'
import CompleteTaskModal from '@/Components/CompleteTaskModal.vue'
import CallButtons from '@/Components/CallButtons.vue'
import FollowUpModal from '@/Components/FollowUpModal.vue'
import { useFilterVisit } from '@/composables/useFilterVisit.js'

const props = defineProps({
  range: Object,
  cards: Object,
  charts: Object,
  // null unless this is the first dashboard load of a session that has work
  // owed — see the notice block further down
  todayDigest: { type: Object, default: null },
  followUps: Object,
  // { stage, source, reached } — '' for each one that is off
  filters: { type: Object, default: () => ({ stage: '', source: '', reached: '' }) },
  options: Object,
})

// the panels name the staff member only for an admin: everyone else is looking
// at their own rows and would read their own name on every one
const isAdmin = computed(() => usePage().props.auth.user.role === 'admin')

/* ---------------- date range ---------------- */

/*
 | The control itself is DateRangePicker, shared with the two report pages, so
 | the presets, their order, their labels and the custom popover are one thing
 | in one file rather than three that have to be kept in step. What stays here
 | is the only part that was ever the dashboard's own: what a choice does.
 |
 | A preset and a custom pair are alternatives, so each visit names only the one
 | being chosen and carries reset=1 with it. The request is then the whole
 | instruction — what is missing is off — so yesterday's custom dates cannot sit
 | in the session outranking the preset the user just clicked. See
 | withoutEmpty() in the composable for why the reset and the dropped empties
 | belong together.
 */
/*
 | The three cross-filter keys stay in the address bar; the date range does
 | not. A range is a preference and lives in the session, as it always has. A
 | cross-filter is a VIEW — "Facebook leads standing at In discussion" — and a
 | view is a thing somebody sends to somebody else, so it has to survive a
 | refresh and paste into a message. See cleanUrl().
 */
const CROSS_KEYS = ['stage', 'source', 'reached']

const { visit, cleanUrl } = useFilterVisit(route('dashboard'), CROSS_KEYS)

/*
 | The range half of a visit: a preset, or a custom pair, never both. The
 | server reads a pair as custom whatever else it is told, so the two cannot be
 | sent together and the one in force has to be reconstructed here rather than
 | echoed back wholesale.
 */
const rangeParams = () => props.range.key === 'custom'
  ? { from: props.range.from, to: props.range.to }
  : { range: props.range.key }

const crossParams = () => ({
  stage: props.filters.stage,
  source: props.filters.source,
  reached: props.filters.reached,
})

/*
 | Every visit carries reset=1 and then names the whole state again — the
 | range AND the cross-filter — so the request is the entire instruction and
 | what is missing is off. That is what lets a single visit turn one filter off
 | while leaving the other two and the dates exactly where they were.
 |
 | withoutEmpty() in the composable drops the '' values on the way out, so a
 | filter being switched off simply is not in the request. See the note at the
 | foot of useFilterVisit.js for why reset and the dropped empties belong
 | together.
 */
const push = (overrides = {}) =>
  visit({ reset: 1, ...rangeParams(), ...crossParams(), ...overrides })

/*
 | Changing the dates keeps the cross-filter, and does not carry the old range
 | with it: `choice` is a preset or a pair, and reset=1 has already dropped
 | whichever of the two is not in it.
 */
const setRange = choice => visit({ reset: 1, ...crossParams(), ...choice })

/*
 | Clicking a segment. The same click again removes it — that is the whole of
 | the toggle, and it is here rather than in each chart so all four visuals
 | cannot come to disagree about what a second click does.
 |
 | A cross-filter applies to EVERY query on the page, the visual it was clicked
 | on included, and there is no exception anywhere. The reason is
 | reconciliation: a tile that quietly excused itself from the filter would be
 | a tile whose number answers a different question from the tile beside it,
 | and this page's whole claim is that its numbers agree with each other. It
 | costs the clicked chart its other bars, which is what the chips above the
 | grid and the "Clear all" beside them are for — and the live segment is
 | always the one still on screen, so a second click on it always works.
 */
const toggleCross = (key, value) => {
  if (!value) return

  push({ [key]: props.filters[key] === value ? '' : value })
}

const removeCross = key => push({ [key]: '' })

// back to the unfiltered page, dates untouched
const clearCross = () => visit({ reset: 1, ...rangeParams() })

/*
 | The chips, built here rather than sent down: the page already holds the
 | stage and source vocabularies in `options`, and a second copy of them on the
 | wire is a second place for a label to be wrong.
 |
 | Stage and Reached carry the stage's own colour so that a chip, a StageBadge,
 | a funnel band and a bar in either stage chart are the same colour for the
 | same stage. Source has no colour in config and is given none.
 */
const chips = computed(() => {
  const stages = props.options.stages ?? {}
  const colors = props.options.stageColors ?? {}

  return [
    props.filters.stage
      ? { key: 'stage', label: 'Stage', text: stages[props.filters.stage] ?? props.filters.stage,
          color: colors[props.filters.stage] ?? null }
      : null,
    props.filters.source
      ? { key: 'source', label: 'Source',
          text: props.options.sources?.[props.filters.source] ?? props.filters.source, color: null }
      : null,
    props.filters.reached
      ? { key: 'reached', label: 'Reached', text: stages[props.filters.reached] ?? props.filters.reached,
          color: colors[props.filters.reached] ?? null }
      : null,
  ].filter(Boolean)
})

const filtered = computed(() => chips.value.length > 0)

/*
 | The chart configs below read this to place a legend and size a bar. It used
 | to be a function calling window.innerWidth, which meant the answer was
 | whatever the width happened to be the one time each config was built — and
 | after that it never changed, so a chart dragged from desktop to phone width
 | kept its desktop legend until the page was reloaded.
 |
 | A ref fixes that, but it deliberately holds the *answer* rather than the
 | width: assigning the same boolean is a no-op in Vue, so the configs are
 | rebuilt only when the breakpoint is actually crossed. Holding the raw width
 | would give every config a new identity on every pixel of a drag, and
 | ChartCard would tear down and rebuild each chart for all of them.
 */
const NARROW_BELOW = 860

const narrow = ref(window.innerWidth < NARROW_BELOW)

/*
 | Debounced, because crossing the breakpoint is the one thing here that does
 | rebuild a chart. A drag that wobbles either side of 860 would otherwise
 | rebuild all three on every crossing; this waits for the drag to settle.
 */
let widthTimer
const onWidthChange = () => {
  clearTimeout(widthTimer)
  widthTimer = setTimeout(() => { narrow.value = window.innerWidth < NARROW_BELOW }, 120)
}

onMounted(() => window.addEventListener('resize', onWidthChange))
onBeforeUnmount(() => {
  clearTimeout(widthTimer)
  window.removeEventListener('resize', onWidthChange)
})

/*
 | The six tiles, in the words a builder uses. Every label here is a thing that
 | happened to a customer — an enquiry, a visit, a booking — rather than a
 | column name. The note under each says which population it counted and
 | nothing else; the arithmetic behind them is untouched.
 |
 | Each one also carries a colour and a series. The colour comes out of
 | config('crm.stage_colors') — for the three tiles that ARE a stage it is that
 | stage's own colour, so the Bookings figure, the Booking done bar, the
 | Booking done band and a Booking done badge are one green; the other three
 | borrow from the same palette rather than introducing a second one. The
 | series is the tile's own metric bucketed across the range, zero-filled by
 | the server, and it is drawn without an axis or a label because nothing is
 | meant to be read off it.
 */
const kpis = computed(() => {
  const color = props.options.stageColors ?? {}
  const spark = props.cards.spark ?? {}

  return [
    {
      k: 'total',
      v: props.cards.total,
      l: 'New enquiries',
      /*
       | Conversion belongs on this tile and nowhere else, because the number
       | above it is its denominator: of these enquiries, this many have booked
       | since. A dash when there were none to divide by — never 0%, never an
       | error — and when there is nothing to say the note simply does not say
       | it, where it used to print a sentence whose only content was that it
       | had nothing to report.
       */
      /*
       | Short, because six tiles across a 1280 window is about 160px each and
       | the note gets two clamped lines of it. Every word here is carrying its
       | weight: which table, which column, which window.
       */
      d: props.cards.conversion === null
        ? 'Leads created in this period'
        : `Created in this period · ${props.cards.conversion}% booked`,
      /*
       | The change against the period immediately before this one, of the same
       | length. Absent rather than zeroed when there was no prior period to
       | compare against.
       */
      delta: props.cards.delta === null
        ? null
        : `${props.cards.delta >= 0 ? '+' : ''}${props.cards.delta}%`,
      deltaUp: props.cards.delta >= 0,
      c: color.connected,
      s: spark.total,
    },
    /*
     | The two tiles the date picker does not move, and the note under each is
     | where that is said. Every other figure on this page is "in the selected
     | period"; these two are "right now", and a reader who is not told cannot
     | tell a deliberate exception from a filter that failed to apply.
     */
    { k: 'today', v: props.cards.today, l: 'Enquiries today', flag: 'All dates',
      d: 'Leads created since midnight', c: color.details_shared, s: spark.today },
    { k: 'visits', v: props.cards.visits, l: 'Site visits',
      d: 'Site visits done in this period', c: color.site_visit_done, s: spark.visits },
    { k: 'booked', v: props.cards.booked, l: 'Bookings', tone: 'good',
      d: 'Bookings made in this period', c: color.booking_done, s: spark.booked },
    { k: 'lost', v: props.cards.lost, l: 'Lost',
      d: 'Closed without booking, this period', c: color.lost, s: spark.lost },
    // the two panels at the foot of the page, added together
    { k: 'pending', v: props.cards.pending, l: 'Calls pending', flag: 'All dates',
      tone: props.cards.pending ? 'bad' : null,
      d: 'Still open, due today or earlier',
      c: color.not_connected, s: spark.pending },
  ]
})

/*
 | Turning a click on a bar into a stage key.
 |
 | 'index' with intersect: false rather than the default hit test, and that is
 | the difference between a chart you can steer with and one you can only leave.
 | A bar at zero has no height and no area, so an intersecting hit test cannot
 | find it — and a bar at zero is exactly what every other stage becomes the
 | moment one of them is selected. Asking for the nearest INDEX instead means
 | the whole column, or the whole row, is the target: the reader can move the
 | filter straight from one stage to another without clearing it first.
 |
 | `axis` has to be named for the horizontal chart. Index mode measures along
 | one axis and the census's categories run down the y, so left to the default
 | it would look for a column where there are rows.
 */
const barIndex = (event, chart, axis = 'x') => {
  const hit = chart.getElementsAtEventForMode(event, 'index', { intersect: false, axis }, true)

  return hit.length ? hit[0].index : null
}

const tick = { color: '#64748b', font: { size: 11 } }
const grid = { color: '#eef2f3' }

/*
 | The two stage charts. Same nine stages in the same order, same colours out
 | of config('crm.stage_colors') — a stage is the colour it is everywhere in
 | the app, badges included — and the same server query, one with a date window
 | and one without; see DashboardController::stagesByLead().
 |
 | They are deliberately not the same shape. The census has the top row to
 | itself and stays horizontal; the period chart shares the row underneath and
 | is vertical. Drawn as a matched pair they read as one chart drawn twice, and
 | the second gets taken for a redrawing of the first rather than for the
 | different question it is: "where does everything stand" against "what came
 | in during these dates". The forms differ so the questions do.
 */

/*
 | The width of the census's label column, as a floor rather than a measurement.
 |
 | Chart.js sizes a category axis to whatever its longest tick happens to need,
 | so left alone the x the bars begin at is a function of the text beside them —
 | it moves with the font the browser resolves, and it is not a number this file
 | knows. A floor pins the nine stages into one left column and the nine bars
 | onto one starting edge.
 |
 | Math.max, not a bare assignment: a floor can only ever add room, so no label
 | can be squeezed into a column too narrow to hold it. On a phone the floor is
 | lower, because there the column is competing with the bars for a third of the
 | width rather than a tenth.
 */
const STAGE_LABEL_COL = 148
const STAGE_LABEL_COL_NARROW = 116

/*
 | Every lead, at the stage it stands at now. The date picker cannot move it.
 |
 | Horizontal, and now across the full width of the page: a bar has the whole
 | row to run along, so it can afford to be a little thicker than it was when
 | this shared a row with the chart below it.
 */
const allStagesChart = computed(() => {
  const rows = props.charts.stagesAllTime.bars

  return {
    type: 'bar',
    data: {
      labels: rows.map(r => r.label),
      datasets: [{
        data: rows.map(r => r.value),
        backgroundColor: rows.map(r => r.color),
        borderRadius: 4, barThickness: narrow.value ? 14 : 20,
      }],
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      // a bar is a stage, and clicking one filters the page to it. The same
      // bar again clears it; see toggleCross().
      onClick: (event, elements, chart) => {
        const index = barIndex(event, chart, 'y')

        if (index !== null) toggleCross('stage', rows[index]?.key)
      },
      scales: {
        x: { grid, ticks: { ...tick, precision: 0 }, beginAtZero: true },
        y: {
          grid: { display: false },
          ticks: tick,
          afterFit: scale => {
            scale.width = Math.max(
              scale.width,
              narrow.value ? STAGE_LABEL_COL_NARROW : STAGE_LABEL_COL,
            )
          },
        },
      },
    },
  }
})

/*
 | The same census, narrowed to the leads created inside the range. Its bars
 | sum to the New enquiries card.
 |
 | Vertical, at half the width, with nine stage names to fit along the bottom.
 | Laid flat the longest of them is wider than the slot it gets — about 110px
 | of text in something between 30 and 55 — so they are laid at a fixed angle
 | instead. Rotation is the one answer here that does not depend on how long
 | the words happen to be: the spacing a rotated label needs is set by its line
 | height and the angle, not by its length, so nine of them clear each other at
 | every width this card is ever given, and the axis simply grows downwards for
 | the longest one rather than clipping it.
 |
 | Abbreviating them was the alternative and is worse: a stage's name is
 | config('crm.stages'), the same string the badges and the filters show, and
 | shortening it here would put a second vocabulary for the nine stages in this
 | file for one axis to use.
 */
const periodStagesChart = computed(() => {
  const rows = props.charts.stagesInPeriod.bars

  return {
    type: 'bar',
    data: {
      labels: rows.map(r => r.label),
      datasets: [{
        data: rows.map(r => r.value),
        backgroundColor: rows.map(r => r.color),
        borderRadius: 4, maxBarThickness: narrow.value ? 22 : 30,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      // the same dimension as the census above it, so the same filter key
      onClick: (event, elements, chart) => {
        const index = barIndex(event, chart, 'x')

        if (index !== null) toggleCross('stage', rows[index]?.key)
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: {
            ...tick,
            // a shade smaller than the rest of the page's ticks, which is what
            // buys the angled labels their room back
            font: { size: 10 },
            /*
             | Both pinned, and that is the point of setting them.
             |
             | autoSkip drops every other label the moment the axis is short of
             | room, and a stage missing from the axis reads as a stage with
             | nothing in it — the exact thing zero-filling the bars is there to
             | prevent. And left to choose an angle, Chart.js straightens the
             | labels whenever it decides they fit and lays them back down when
             | they do not, so the axis would change shape as the range changed
             | the numbers beside it.
             */
            autoSkip: false, minRotation: 45, maxRotation: 45,
          },
        },
        y: { grid, ticks: { ...tick, precision: 0 }, beginAtZero: true },
      },
    },
  }
})

/*
 | The note under "Where all enquiries stand", and it carries the count.
 |
 | The count is the point of putting a note there at all: it is the one number
 | on the card that says how big the book is, and it does not move when the
 | picker does. A reader who changes the range and watches this stay put has
 | been told, without reading a word, that this chart is not part of the range.
 */
const allStagesNote = computed(() => filtered.value
  /*
   | Under a cross-filter it is still every enquiry ever received, at whatever
   | stage each stands — of the ones the filter selected. Saying "all" there
   | would be the card claiming a population it is no longer counting, and the
   | chips above the grid say which one it is.
   */
  ? `Matching enquiries, whatever the dates · ${props.charts.stagesAllTime.total} total`
  : `All enquiries ever received · ${props.charts.stagesAllTime.total} total`)

/*
 | The source palette. The stage charts do not use it — a stage's colour comes
 | from config('crm.stage_colors') so that a stage is the same colour
 | everywhere in the app, badges included.
 */
const PALETTE = ['#2F6FB0', '#0F766E', '#8145A8', '#C2711A', '#5B58B8', '#1E7A45', '#B4881B', '#8A94A0']

/*
 | Where the doughnut's legend goes, decided by the width of the card it is in
 | rather than the width of the window.
 |
 | Those are not the same question and the window cannot answer it, and it can
 | answer it even less now this card is half a row from lg up rather than a
 | whole one below 1280. A 1024-wide window leaves it 356px and a 1440-wide one
 | 564px, so at both of those the legend belongs underneath — but a 1900-wide
 | monitor gives the same half-row card 714px, where it belongs beside. No
 | window breakpoint can express that. A ResizeObserver on a wrapper around the
 | card measures the thing that actually decides, at every width, with no grid
 | arithmetic to keep in step — which is why moving the grid to two columns at
 | lg needed nothing changed here.
 |
 | 600px is the line: below it the plot is too narrow to give a quarter of
 | itself away to a column of labels, so they go underneath; above it there is
 | room for both side by side.
 |
 | The ref holds the answer, not the width: assigning the same boolean is a
 | no-op in Vue, so the config is rebuilt only when the threshold is crossed
 | and not on every pixel of a drag.
 */
const LEGEND_BESIDE_ABOVE = 600

const sourceCard = ref(null)
const sourceRoomy = ref(true)

let sourceObserver
let sourceTimer

onMounted(() => {
  if (!sourceCard.value) return

  /*
   | Measured once, synchronously, before the observer is attached. A child's
   | onMounted runs before its parent's, so the chart already exists by the
   | time this does; taking the first reading here rather than waiting out the
   | debounce means a narrow card never paints a right-hand legend for a fifth
   | of a second and then throws it away.
   */
  sourceRoomy.value = sourceCard.value.offsetWidth >= LEGEND_BESIDE_ABOVE

  // debounced after that, because crossing the threshold rebuilds the chart
  // and a drag should not rebuild it on every pixel
  sourceObserver = new ResizeObserver(([entry]) => {
    clearTimeout(sourceTimer)
    sourceTimer = setTimeout(() => {
      sourceRoomy.value = entry.contentRect.width >= LEGEND_BESIDE_ABOVE
    }, 120)
  })

  sourceObserver.observe(sourceCard.value)
})

onBeforeUnmount(() => {
  clearTimeout(sourceTimer)
  sourceObserver?.disconnect()
  sourceObserver = null
})

const sourceChart = computed(() => {
  return {
    type: 'doughnut',
    data: {
      labels: props.charts.bySource.map(r => r.label),
      datasets: [{ data: props.charts.bySource.map(r => r.value),
                   backgroundColor: PALETTE, borderWidth: 2, borderColor: '#fff' }],
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '58%',
      /*
       | A slice is a source, and only the sources with leads in them are
       | drawn — a doughnut has no zero slice to click, unlike a bar chart's
       | zero bar. That is what the chips are for: with a source selected the
       | ring is that one source, and it is removed from the chip or by
       | clicking the ring again.
       */
      onClick: (event, elements) => {
        if (elements.length) toggleCross('source', props.charts.bySource[elements[0].index]?.key)
      },
      plugins: {
        legend: { position: sourceRoomy.value ? 'right' : 'bottom',
                  labels: { boxWidth: 9, boxHeight: 9, padding: 9, color: '#64748b', font: { size: 11.5 } } },
        tooltip: { callbacks: {
          label: c => ` ${c.label}: ${c.raw} (${props.charts.bySource[c.dataIndex]?.percent ?? 0}%)`,
        } },
      },
    },
  }
})

/* ---------------- the sign-in modal ---------------- */

/*
 | Opened a beat after the dashboard is on screen, never before.
 |
 | A modal that is already up when the page paints covers a blank page: the
 | reader is asked to dismiss something before they have seen what it is in
 | front of. So this waits for a frame to be handed to the compositor, and then
 | a further moment on top, and the dashboard is what appears first.
 |
 | requestAnimationFrame alone is not the promise it looks like — the callback
 | runs *before* the paint it is scheduled with. Pairing it with a timeout is
 | what puts this after a real frame rather than merely after mount.
 */
const DIGEST_DELAY_MS = 450

const digestOpen = ref(false)

let digestFrame
let digestTimer

onMounted(() => {
  // the server sends this at most once a session, and only when there is
  // something owed, so its presence is the whole decision
  if (!props.todayDigest) return

  digestFrame = requestAnimationFrame(() => {
    digestTimer = setTimeout(() => { digestOpen.value = true }, DIGEST_DELAY_MS)
  })
})

onBeforeUnmount(() => {
  cancelAnimationFrame(digestFrame)
  clearTimeout(digestTimer)
})

/*
 | Closing is local and needs to be nothing more. The server recorded that this
 | session was told at the moment it sent the prop, so the next dashboard visit
 | will not send `todayDigest` at all — there is no state here to persist and no
 | request to make. Escape, the close button, the overlay and "Go to my to-do
 | list" all land here.
 */
const closeDigest = () => { digestOpen.value = false }

/* ---------------- follow-up panels ---------------- */

/*
 | Neither panel follows the range selector — they are about right now, so the
 | server sends them unfiltered and this only decides how they read.
 */
const clock = v => v ? new Date(v).toLocaleTimeString('en-IN',
  { hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

const fmt = v => v ? new Date(v).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true }) : '—'

/*
 | Both panels are pending work, and LeadController::destroy() cancels a
 | deleted lead's pending todos, so a null lead should never reach them —
 | Todo::hasLead() makes sure of it either way. These guards are the second
 | line: a null relation should cost one row's worth of detail, not the whole
 | dashboard.
 */
const leadName = t => t.lead?.full_name ?? 'Lead deleted'

const leadMobile = t => t.lead?.mobile_number ?? '—'

/*
 | Name · role on one muted line, not the stacked AssignedTo cell the tables
 | use: three lines of it inside a two-line row, repeated down the panel, was
 | most of the noise. The panel is already a list of follow-ups assigned to
 | someone, so the "Assigned to" label is dropped too — a label repeated on
 | every row of a list of assignments says nothing the list has not.
 */
const assignee = t => t.owner
  ? [t.owner.display_name, props.options.roleLabels?.[t.owner.role] ?? t.owner.role]
      .filter(Boolean).join(' · ')
  : '—'

/*
 | The two panels. "Due today" and "Waiting longer" — the second one is
 | everything still open that was scheduled before today, and it is named for
 | what it is rather than for a status word nobody in a sales office uses.
 |
 | The rose wash the late rows carried is gone. A tinted row plus a coloured
 | left border plus rose bold text was three signals for one fact, and the
 | weakest of the three was sitting behind the words. What is left is a thin
 | coloured border down the edge and the timestamp itself in rose: the row
 | reads as text on white, and the eye still finds the late ones.
 */
const panels = computed(() => [
  {
    key: 'today',
    tab: 'today',
    title: 'Due today',
    /*
     | The note both panels carry, and it is the same sentence in both because
     | it is the same exception: these two are "right now". The date picker
     | does not move them and it never has — what does move them now is a
     | cross-filter, which narrows which leads are being asked about without
     | touching when the calls are due.
     */
    note: 'Open follow-ups dated today — not moved by the date range',
    // amber, so a glance tells the two panels apart without reading the headers
    accent: 'border-l-amber-500',
    timeClass: 'text-slate-500 dark:text-slate-400',
    // nothing in this panel is from another day, so the date would add nothing
    time: clock,
    empty: "Nothing due today — you're clear",
    ...props.followUps.today,
  },
  {
    key: 'waiting',
    /*
     | `overdue` survives as an identifier — the Todo scope, the payload key and
     | the To-do page's tab all still call this set that, and one internal word
     | for one concept is worth more than a rename that would have to land in
     | three places to stay honest. Nothing here is rendered; the panel is
     | titled above and the To-do page's own tab is labelled to match.
     */
    tab: 'overdue',
    title: 'Waiting longer',
    note: 'Open follow-ups from before today — not moved by the date range',
    accent: 'border-l-rose-500',
    timeClass: 'font-semibold text-rose-700 dark:text-rose-300',
    // these are from earlier days, so the day matters as much as the time
    time: fmt,
    empty: 'Nothing waiting — every call has been made on time.',
    ...props.followUps.overdue,
  },
])

/* ---------------- logging a call ---------------- */

const completeOpen = ref(false)
const active = ref(null)

const openComplete = t => { active.value = t; completeOpen.value = true }

/*
 | CompleteTaskModal is shared with the To-do page, so it cannot know which
 | props its host needs refreshing, and it emits `close` on cancel just as it
 | does on save. Watching the request go past is what lets the modal stay
 | untouched: flag the POST on its way out, reload on the way back in.
 |
 | router.reload() re-visits the current URL, which forces preserveScroll and
 | preserveState — the page does not jump and the panels keep their scroll
 | offsets. The range comes back from the session either way; cleanUrl runs
 | after it because Inertia writes that URL to the address bar on the way
 | through, and it is the one URL a filter control did not put there.
 */
const isLogCall = url => /\/todos\/\d+\/complete\/?$/.test(new URL(String(url), window.location.origin).pathname)

let logging = false

const stopBefore = router.on('before', e => {
  const { method, url } = e.detail.visit
  logging = method === 'post' && isLogCall(url)
})

const stopSuccess = router.on('success', () => {
  if (!logging) return
  logging = false

  /*
   | Charts included, and they have to be.
   |
   | They used to be left out, on the grounds that one logged call does not
   | move them. It can move all three: logging a call usually moves a lead, and
   | a lead that moves is a lead standing somewhere else — in both stage charts
   | at once. Refreshing only the cards left the Booking card reading 3 beside a
   | chart still drawing 2 — the numbers disagreeing on screen while the
   | database was perfectly consistent.
   |
   | `todayDigest` is deliberately not in the list. It is a sign-in notice, not
   | a live count, and re-requesting it would make the server think it had been
   | shown a second time.
   */
  router.reload({ only: ['cards', 'charts', 'followUps'], onFinish: cleanUrl })
})

onBeforeUnmount(() => { stopBefore(); stopSuccess() })
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout title="Dashboard" subtitle="Overview of leads and follow-ups">
    <template #actions>
      <DateRangePicker :range="range" :presets="options.ranges" @select="setRange" />
    </template>

    <!--
      What the grid is currently filtered to, directly under the control that
      sets the dates. It renders nothing when nothing is selected, so the
      unfiltered page does not carry an empty row saying "no filters".
    -->
    <CrossFilterChips :chips="chips" @remove="removeCross" @clear="clearCross" />

    <!--
      Row 1 — the six figures.

      6 across from xl, 3 from lg, 2 from md, 1 below it. Those are the widths
      the CONTENT gets rather than the window: the sidebar takes 15rem from lg
      up, so a 1024 window leaves this grid about 728px and a 768 one about
      712px — nearly the same width for very different windows, which is why
      the tile count steps at lg rather than tracking the window evenly.

      gap-3 throughout the page, not gap-4. A BI grid is meant to read as one
      surface with lines ruled through it; the tiles are what should be
      noticed, not the channels between them.
    -->
    <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
      <KpiTile
        v-for="k in kpis" :key="k.k"
        :label="k.l" :value="k.v" :note="k.d" :tone="k.tone" :flag="k.flag"
        :color="k.c" :spark="k.s" :delta="k.delta" :delta-up="k.deltaUp"
      />
    </div>

    <!--
      Row 2 — where the enquiries stand, and which of them arrived in this
      period. The same server query one window apart; see
      DashboardController::stagesByLead().

      They are deliberately not the same shape. Drawn as a matched pair they
      read as one chart drawn twice, and the second gets taken for a redrawing
      of the first rather than for the different question it is. The census
      stays horizontal, the period chart vertical, so the forms differ where
      the questions do.

      Both are clickable, both set the same `stage` filter, and both lift under
      the cursor to say so.
    -->
    <div class="mb-3 grid gap-3 md:grid-cols-2">
      <ChartCard title="Where all enquiries stand"
                 :note="allStagesNote"
                 :config="allStagesChart"
                 height="h-[248px]"
                 clickable />

      <ChartCard title="Enquiries in this period"
                 note="Leads created in the selected period"
                 :config="periodStagesChart"
                 height="h-[248px]"
                 clickable />
    </div>

    <!--
      Row 3 — where they came from, and how far they got.

      A third and two thirds from lg up: the doughnut is a ring and a short
      list of labels and does not grow more legible with width, where the
      funnel is six labelled bands whose whole job is to be read across. Below
      lg they fall back to halves — the span is `lg:col-span-2` and not
      `md:col-span-2` for exactly that reason, or the funnel would take the
      whole of a two-column row and leave an empty cell beside the doughnut —
      and below md to a single column.

      The doughnut is wrapped so its legend can follow the width of the CARD
      rather than the width of the window — see the ResizeObserver above.
      `grid` on the wrapper, not merely min-w-0, so the lone child stretches to
      the cell in both axes and the two tiles in the row stay the same height.
    -->
    <div class="mb-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
      <div ref="sourceCard" class="grid min-w-0">
        <ChartCard title="Where enquiries came from"
                   note="Leads created in the selected period, by source"
                   :config="sourceChart"
                   height="h-[248px]"
                   clickable
                   :empty="!charts.bySource.length" empty-text="No leads in this range" />
      </div>

      <!--
        The funnel. Six stages of one journey, each band carrying its count and
        the drop from the band above.

        Its numbers are not its own: they are stageEvents(), the query the Site
        visits, Bookings and Lost tiles are read out of, so the Site visit done
        band IS the Site visits tile and the Booking done band IS the Bookings
        tile rather than two figures that happen to agree.

        Clicking a band filters the page to the leads that reached that stage,
        which is a different question from the one the stage charts ask — "has
        been through" rather than "is standing at" — and gets a filter key of
        its own for that reason.
      -->
      <div class="grid min-w-0 lg:col-span-2">
        <div class="tile tile-lift">
          <TileHeader title="How far enquiries get"
                      note="Leads that reached each stage in the selected period, from logged follow-ups" />

          <div class="flex-1 px-4 py-3">
            <div class="h-[248px]">
              <FunnelChart :bands="charts.funnel.bands" :active="filters.reached"
                           @select="key => toggleCross('reached', key)" />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!--
      Row 4 — the two work lists, equal weight, and unchanged.

      Same queries, same rows, same Log call buttons. Side by side from lg up
      rather than from md: half of a 768 window is 356px, and squeezing a row
      that carries a name, a time, a mobile, a stage badge, an assignee and
      three controls into that is a real regression to a panel this rebuild was
      told to leave alone.

      Due today is first in the array, so it is also the first card once the
      grid stacks on a phone.
    -->
    <div class="grid gap-3 lg:grid-cols-2">
      <div v-for="p in panels" :key="p.key" class="tile overflow-hidden">
        <!-- the same header as every other tile on the page, fixed while the
             list scrolls under it -->
        <TileHeader :title="p.title" :note="p.note" :count="p.total" />

        <!--
          A fixed height either way, so the two panels line up whether one holds
          fifty rows and the other none, and a phone does not get an endless
          page. overscroll-contain stops a flick past the last row from carrying
          on into the page behind it.
        -->
        <div v-if="!p.rows.length"
             class="flex h-[22rem] items-center justify-center px-5 text-center text-sm
                    font-semibold text-slate-700 dark:text-slate-300">
          {{ p.empty }}
        </div>

        <div v-else class="h-[22rem] overflow-y-auto overscroll-contain">
          <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
            <!--
              Two lines, and the same shape on every one: the name bold at the
              top left, the time at the top right, the detail underneath, the
              actions under the time. The eye runs down four columns instead of
              hunting across a row of content-sized boxes.

              The right-hand column is a fixed 11rem from sm up, not `auto`.
              That is the fix for the thing the rows were actually doing wrong:
              each row is its own grid, so an `auto` column was as wide as
              whatever that row happened to hold — a long timestamp here, a
              lead with no mobile and therefore no call buttons there — and the
              time and the buttons landed at a different x on every line. A
              fixed track makes the column a property of the panel rather than
              of the row. 11rem is the button cluster (two 36px icon links, a
              6px gap each side and the Update button) with room to spare, so a
              font that renders a little wide cannot push it out of the track.
            -->
            <div v-for="t in p.rows" :key="t.id"
                 class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2
                        border-l px-4 py-3 transition-colors hover:bg-slate-50/70
                        sm:grid-cols-[minmax(0,1fr)_11rem] sm:gap-y-1.5 sm:px-5"
                 :class="p.accent">

              <!-- line 1: name, and the time hard against the right edge -->
              <div class="truncate text-sm font-semibold"
                   :class="t.lead ? '' : 'italic text-slate-400'">{{ leadName(t) }}</div>

              <div class="whitespace-nowrap text-right text-xs tabular-nums" :class="p.timeClass">
                {{ p.time(t.scheduled_at) }}
              </div>

              <!--
                line 2. Fixed widths on the first two, so the mobile starts at
                the same x on every row and the badge does too, whatever the
                stage is called. The mobile is never truncated — half a phone
                number is useless — and the badge box is 10.25rem because that
                is the widest configured stage label, "Site visit scheduled",
                with its dot and pill padding, plus a little slack.

                The assignee takes what is left, and `basis-32` is what makes it
                degrade by wrapping instead of by shrinking: where the row is
                too narrow to seat all three it drops to its own line at full
                width, rather than being squeezed to "P…".
              -->
              <div class="col-span-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1
                          text-xs text-slate-500 dark:text-slate-400 sm:col-span-1">
                <span class="w-20 shrink-0 tabular-nums">{{ leadMobile(t) }}</span>

                <span class="w-[10.25rem] shrink-0">
                  <StageBadge v-if="t.lead" :stage="t.lead.stage" />
                </span>

                <!-- admin only, and for everyone else the element is not there
                     at all, so it cannot leave a gap where it would have sat -->
                <span v-if="isAdmin" class="min-w-0 flex-1 basis-32 truncate text-slate-400"
                      :title="assignee(t)">{{ assignee(t) }}</span>
              </div>

              <!--
                One filled button per row, and it is the one that does something
                to the data. Call and WhatsApp are the icon-only outline variant
                CallButtons already ships for the To-do table — no fork, no
                second style — and the arbitrary variant only evens their height
                up with Update so the three read as one control group.
              -->
              <div class="col-span-2 flex items-center justify-end gap-1.5 sm:col-span-1">
                <CallButtons v-if="t.lead?.mobile_number" compact
                             class="[&>a]:py-1.5" :mobile="t.lead.mobile_number" />

                <button type="button"
                        class="btn whitespace-nowrap px-2.5 py-1.5 text-xs"
                        @click="openComplete(t)">Update</button>
              </div>
            </div>
          </div>

          <p v-if="p.total > p.rows.length"
             class="border-t border-slate-100 dark:border-slate-700/60 px-5 py-3 text-xs text-slate-400">
            Showing first {{ p.rows.length }} of {{ p.total }} —
            <Link :href="route('todos.index', { tab: p.tab })" class="underline hover:text-teal-700">
              open the Follow-ups page to see all.</Link>
          </p>
        </div>
      </div>
    </div>

    <!-- the same modal the To-do page uses, and the same endpoint behind it -->
    <CompleteTaskModal :show="completeOpen" :todo="active" :options="options"
                       @close="completeOpen = false" />

    <!--
      The sign-in modal. Teleported to the body by Modal.vue, so where it sits
      in this template decides nothing but reading order; it lives beside the
      page's other modal rather than up among the cards it opens over.
    -->
    <FollowUpModal :show="digestOpen" :digest="todayDigest" @close="closeDigest" />
  </AppLayout>
</template>
