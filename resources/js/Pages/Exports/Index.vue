<script setup>
/*
 | The Export Data page: one card, three choices.
 |
 | Pick what to export (three types), narrow it with the same filters those
 | pages offer, pick a format, and press Export. The click is a plain fetch —
 | not an Inertia visit — so the browser receives a file and this page never
 | moves. Errors come back as JSON and are printed under the controls rather
 | than as a redirect.
 |
 | Filters here mean exactly what they mean on the pages they came from: the
 | stage dropdown is CrmTaxonomy's, the projects are the user's visible
 | projects, and the server re-scopes everything through Lead::visibleTo() and
 | Todo::forUser() before a single row is written. A field that does not apply
 | to the selected data type is not drawn — there is no stage dropdown on the
 | channel-partners export — and a field the user is not allowed to use (the
 | assigned-to filter, which only means anything past your own rows) is not
 | drawn either. The POST would be refused on shape alone, but the page never
 | sends it in the first place.
 |
 | Excel and CSV export every matching row in one file, no cap. PDF is the one
 | exception: dompdf renders the whole document in memory, so past
 | `options.pdfRowLimit` rows it is not refused, it is paged — one file of at
 | most that many rows per click of "Download next". The preview below works
 | out how many PDF downloads that will take as soon as PDF is picked, and
 | each click asks the server for the next page in turn; the server enforces
 | the same page size and the same filters regardless of what this page sends.
 |
 | "Matching records: X" previews the exact count under the filters, using a
 | lightweight count endpoint that runs the same query and the same visibility
 | the download uses — so the number is never an approximation.
 */
import { reactive, ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ options: Object })

const page = usePage()
const user = computed(() => page.props.auth.user)

// the permission that drew the link is the one that lets the page run; the
// others decide which filters this person may use, mirroring the pages they
// were copied from
const seeAllLeads = computed(() => props.options.seeAllLeads)
const isAdmin = computed(() => props.options.isAdmin)

const f = reactive({
  data_type: 'leads',
  search: '',
  from: '',
  to: '',
  project_id: '',
  stage: '',
  source: '',
  channel_partner_id: '',
  status: '',
  type: '',
  assigned_to: '',
})

const format = ref('pdf')
const exporting = ref(false)
const error = ref('')

// the exact count underneath the currents filters — null until the first count
// arrives, a number after
const matchingCount = ref(null)
const counting = ref(false)

const dataTypeLabel = computed(() => props.options.types[f.data_type])

const numberText = (n) => Number(n).toLocaleString('en-US')
const matchingCountText = computed(() =>
  matchingCount.value === null ? '…' : numberText(matchingCount.value))

/*
 | Whether PDF needs more than one file for the current filters, and — if so
 | — which page comes next. `pdfNextPage` starts back at 1 whenever the count
 | changes (fetchCount resets it), so a filter change never leaves the page
 | offering to download "rows 3,001–3,500" against a result that no longer
 | has that many rows.
 */
const pdfPaginated = computed(() =>
  matchingCount.value !== null && matchingCount.value > props.options.pdfRowLimit)
const pdfTotalPages = computed(() =>
  matchingCount.value === null ? 0 : Math.ceil(matchingCount.value / props.options.pdfRowLimit))
const pdfNextPage = ref(1)
const pdfAllDownloaded = computed(() => pdfPaginated.value && pdfNextPage.value > pdfTotalPages.value)

/** The row range a given PDF page covers, against the current count. */
const pdfRange = (pageNum) => {
  const limit = props.options.pdfRowLimit
  const start = (pageNum - 1) * limit + 1
  const end = Math.min(pageNum * limit, matchingCount.value ?? 0)
  return { start, end }
}

/*
 | What the Export button says while a download is in flight, and what it
 | offers next once one lands. The row count is already on screen from the
 | preview by the time anyone clicks, so the button can name it outright
 | ("Exporting 10,969 leads…") rather than sit on a generic spinner —
 | "Preparing your export…" only covers the rare case where the count has not
 | landed yet. A paginated PDF names the batch instead of the whole result,
 | since that batch is all a single click will produce.
 */
const exportingLabel = computed(() => {
  if (exporting.value) {
    if (format.value === 'pdf' && pdfPaginated.value) {
      const { start, end } = pdfRange(pdfNextPage.value)
      return `Downloading rows ${numberText(start)}–${numberText(end)} of ${matchingCountText.value}…`
    }

    return matchingCount.value === null
      ? 'Preparing your export…'
      : `Exporting ${matchingCountText.value} ${dataTypeLabel.value.toLowerCase()}…`
  }

  if (format.value === 'pdf' && pdfPaginated.value) {
    return pdfAllDownloaded.value ? 'All rows exported' : `Download next ${numberText(props.options.pdfRowLimit)}`
  }

  return 'Export'
})

/*
 | Same date rules the server enforces, so an impossible pair is never sent —
 | and both dates are ISO yyyy-mm-dd, so they compare correctly as plain
 | strings without building a Date in whatever timezone the browser is in.
 | `options.today` is today in IST as the server sees it.
 */
const dateError = computed(() => {
  if (!f.from && !f.to) return ''
  if (!f.from || !f.to) return 'Choose both a From and a To date, or neither.'
  if (f.from > f.to) return 'From must not be after To.'
  if (f.to > props.options.today) return 'To must not be in the future.'
  return ''
})

/*
 | Only the fields this data type owns ride along — the same whitelist the
 | server applies (ExportDataController::ownedFilters), mirrored here so the
 | page never even asks for an irrelevant filter. No format: this is what both
 | the count preview and the download start from.
 */
const filterPayload = () => {
  const base = {
    data_type: f.data_type,
    from: f.from || undefined,
    to: f.to || undefined,
  }

  switch (f.data_type) {
    case 'leads':
      return { ...base, project_id: f.project_id || undefined, stage: f.stage || undefined, source: f.source || undefined, channel_partner_id: f.channel_partner_id || undefined, assigned_to: seeAllLeads.value ? f.assigned_to || undefined : undefined }
    case 'followups':
      return { ...base, status: f.status || undefined, type: f.type || undefined, project_id: f.project_id || undefined, stage: f.stage || undefined, assigned_to: isAdmin.value ? f.assigned_to || undefined : undefined }
    case 'channel_partners':
      return { ...base, search: f.search || undefined, type: f.type || undefined, status: f.status || undefined }
  }
}

const clear = () => {
  Object.keys(f).forEach(k => (f[k] = ''))
  f.data_type = 'leads'
  error.value = ''
  // the watch picks the change up and refreshes the count
}

/*
 | Save the file a browser downloaded. The filename lives in the
 | Content-Disposition header the server set (shaligram-leads-2026-09-18.pdf),
 | with the data type's default as the fallback.
 */
const saveBlob = (blob, disposition, suggested) => {
  const match = disposition?.match(/filename="?([^";]+)"?/i)
  const filename = match ? match[1] : suggested

  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

/*
 | A 422 comes back as a JSON body even though the request asked for a blob,
 | so an error has to be read as text and un-json'd before it can be printed.
 | Anything else — a 403, a 5xx — falls back to a safe generic sentence with
 | no stack trace leaking anywhere.
 */
const readError = async (e) => {
  const data = e.response?.data
  if (!data) return 'Something went wrong. Please try again.'

  if (typeof data === 'string') {
    try { return JSON.parse(data).message ?? 'Something went wrong. Please try again.' }
    catch { return data }
  }
  if (data instanceof Blob) {
    try {
      const json = JSON.parse(await data.text())
      return json.message ?? 'Something went wrong. Please try again.'
    } catch { /* not JSON — an HTML error page */ }
  }
  return data.message ?? 'Something went wrong. Please try again.'
}

/*
 | One download. For CSV, Excel, and a PDF small enough to need only one
 | file, every matching row the user is allowed to see comes back in one
 | file. For a paginated PDF, one click is one page — `pdfNextPage` rows
 | `pdfRowLimit()` at a time — and a successful download moves the pointer to
 | the next page so the following click continues where this one left off.
 | The Export button disables itself the moment the click fires — so it
 | cannot be double-clicked into two simultaneous exports for the same page —
 | and is restored whether the request succeeds or fails.
 */
const download = async (fmt) => {
  if (dateError.value) { error.value = dateError.value; return }
  if (exporting.value) { return }
  if (fmt === 'pdf' && pdfPaginated.value && pdfAllDownloaded.value) { return }

  const requestPage = fmt === 'pdf' ? pdfNextPage.value : 1

  exporting.value = true
  error.value = ''

  try {
    const res = await axios.post(route('export-data.download'), {
      ...filterPayload(),
      format: fmt,
      page: requestPage,
    }, {
      responseType: 'blob',
      headers: { Accept: 'application/json' },
    })

    const disposition = res.headers['content-disposition'] ?? ''
    const ext = { pdf: 'pdf', excel: 'xlsx', csv: 'csv' }[fmt]
    const slug = f.data_type === 'channel_partners' ? 'channel-partners' : f.data_type
    const suggested = pdfPaginated.value && fmt === 'pdf'
      ? `shaligram-${slug}-${props.options.today}-rows-${pdfRange(requestPage).start}-${pdfRange(requestPage).end}.${ext}`
      : `shaligram-${slug}-${props.options.today}.${ext}`
    saveBlob(res.data, disposition, suggested)

    if (fmt === 'pdf') { pdfNextPage.value += 1 }
  } catch (e) {
    error.value = await readError(e)
  } finally {
    exporting.value = false
  }
}

const exportIt = () => download(format.value)

/*
 | The "Matching records: X" preview: an exact count for the current filters.
 | Left running on every keystroke of a search it would spam the server, so it
 | is debounced; stale replies are dropped so a slow count can never overwrite
 | a newer one.
 */
let countTimer = undefined
let countSeq = 0

const fetchCount = async () => {
  clearTimeout(countTimer)

  // the count is about to change, so any PDF pagination in progress against
  // the old count no longer means anything — back to page 1
  pdfNextPage.value = 1

  // an invalid date pair is never worth counting — the server would 422 it
  if (dateError.value) { matchingCount.value = null; return }

  const seq = ++countSeq
  counting.value = true

  try {
    const res = await axios.post(route('export-data.count'), filterPayload())
    if (seq === countSeq) matchingCount.value = res.data.count
  } catch {
    if (seq === countSeq) matchingCount.value = null
  } finally {
    if (seq === countSeq) counting.value = false
  }
}

const scheduleCount = () => {
  clearTimeout(countTimer)
  countTimer = window.setTimeout(fetchCount, 300)
}

watch(() => [
  f.data_type,
  f.from,
  f.to,
  f.project_id,
  f.stage,
  f.source,
  f.channel_partner_id,
  f.status,
  f.type,
  f.assigned_to,
  f.search,
], scheduleCount)

onMounted(fetchCount)
onBeforeUnmount(() => clearTimeout(countTimer))
</script>

<template>
  <Head title="Export Data" />

  <AppLayout title="Export Data" subtitle="Export CRM data in PDF, Excel, or CSV format.">
    <div class="card overflow-hidden">

      <!-- data type -->
      <div class="border-b border-slate-100 p-3 sm:p-4">
        <label class="block">
          <span class="mb-1 block text-xs font-semibold text-slate-500">Data Type</span>
          <select v-model="f.data_type" class="w-full sm:!w-72">
            <option v-for="(label, key) in options.types" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
      </div>

      <!-- filters, the ones this data type actually owns -->
      <div class="border-b border-slate-100 p-3 sm:p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">

          <template v-if="f.data_type === 'leads' || f.data_type === 'followups'">
            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">From</span>
              <input v-model="f.from" type="date" :max="options.today" aria-label="From date" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">To</span>
              <input v-model="f.to" type="date" :min="f.from" :max="options.today" aria-label="To date" />
            </label>

            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Project</span>
              <select v-model="f.project_id">
                <option value="">All projects</option>
                <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </label>

            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Stage</span>
              <select v-model="f.stage">
                <option value="">All stages</option>
                <option v-for="(label, key) in options.stages" :key="key" :value="key">{{ label }}</option>
              </select>
            </label>

            <label v-if="f.data_type === 'leads'" class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Source</span>
              <select v-model="f.source">
                <option value="">All sources</option>
                <option v-for="(label, key) in options.sources" :key="key" :value="key">{{ label }}</option>
              </select>
            </label>

            <label v-if="f.data_type === 'leads'" class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Channel Partner</span>
              <select v-model="f.channel_partner_id">
                <option value="">All channel partners</option>
                <option v-for="p in options.channelPartners" :key="p.id" :value="p.id">{{ p.label }}</option>
              </select>
            </label>

            <label v-if="f.data_type === 'followups'" class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Status</span>
              <select v-model="f.status">
                <option value="">All statuses</option>
                <option v-for="(label, key) in options.todoStatuses" :key="key" :value="key">{{ label }}</option>
              </select>
            </label>

            <label v-if="f.data_type === 'followups'" class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Type</span>
              <select v-model="f.type">
                <option value="">All types</option>
                <option v-for="(label, key) in options.todoTypes" :key="key" :value="key">{{ label }}</option>
              </select>
            </label>

            <!--
              The assigned-to filter only means anything to somebody who can see
              past their own rows — exactly the test the Leads page and the
              To-do page apply before drawing theirs.
            -->
            <label
              v-if="(f.data_type === 'leads' && seeAllLeads) || (f.data_type === 'followups' && isAdmin)"
              class="block"
            >
              <span class="mb-1 block text-xs font-semibold text-slate-500">Assigned To</span>
              <select v-model="f.assigned_to">
                <option value="">All users</option>
                <option v-for="u in options.users" :key="u.id" :value="u.id">
                  {{ u.first_name }} {{ u.last_name }}
                </option>
              </select>
            </label>
          </template>

          <template v-else>
            <label class="block max-lg:col-span-1">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Name or contact</span>
              <input v-model="f.search" type="search" placeholder="Search name, contact or email"
                     class="w-full" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Type</span>
              <select v-model="f.type">
                <option value="">All types</option>
                <option v-for="(label, key) in options.channelPartnerTypes" :key="key" :value="key">{{ label }}</option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-semibold text-slate-500">Status</span>
              <select v-model="f.status">
                <option value="">All statuses</option>
                <option v-for="(label, key) in options.partnerStatus" :key="key" :value="key">{{ label }}</option>
              </select>
            </label>
          </template>

        </div>

        <p v-if="dateError" class="mt-3 text-xs font-medium text-rose-700" role="alert">{{ dateError }}</p>

        <!--
          the exact number the current filters + visibility would export,
          previewed before anyone presses Export
        -->
        <p class="mt-3 text-xs font-medium text-slate-500" role="status">
          Matching records: {{ matchingCountText }}
        </p>
      </div>

      <!-- format: the same segmented control the date presets wear -->
      <div class="border-b border-slate-100 p-3 sm:p-4">
        <span class="mb-1 block text-xs font-semibold text-slate-500">Format</span>
        <div class="flex w-full overflow-hidden rounded-lg border border-slate-200 bg-white sm:w-auto">
          <button
            v-for="(label, key) in options.formats" :key="key"
            type="button"
            class="flex-1 whitespace-nowrap border-r border-slate-200 px-2.5 py-2 text-xs sm:px-4 sm:text-sm"
            :class="format === key ? 'bg-slate-900 text-white' : 'text-slate-500'"
            @click="format = key"
          >{{ label }}</button>
        </div>

        <!--
          worked out the moment PDF is picked with too many rows for the
          current filters, before anyone has clicked Export at all — how many
          files this export will take, and that Excel or CSV would do it in
          one
        -->
        <template v-if="format === 'pdf' && pdfPaginated">
          <p class="mt-2 text-xs font-medium text-amber-700" role="status">
            {{ matchingCountText }} rows &mdash; PDF exports {{ numberText(options.pdfRowLimit) }} at a time
            ({{ pdfTotalPages }} file{{ pdfTotalPages === 1 ? '' : 's' }}).
          </p>
          <p class="mt-1 text-xs text-slate-500">
            Exporting all {{ matchingCountText }} rows as PDF needs {{ pdfTotalPages }} downloads &mdash; Excel or CSV
            gives you everything in one file.
          </p>
          <p class="mt-2 text-xs font-medium text-slate-500" role="status">
            <template v-if="pdfAllDownloaded">
              All {{ matchingCountText }} rows exported across {{ pdfTotalPages }} files.
            </template>
            <template v-else>
              Next up: rows {{ numberText(pdfRange(pdfNextPage).start) }}&ndash;{{ numberText(pdfRange(pdfNextPage).end) }}
              of {{ matchingCountText }} (file {{ pdfNextPage }} of {{ pdfTotalPages }}).
            </template>
          </p>
        </template>
      </div>

      <!-- actions -->
      <div class="flex flex-col gap-3 p-3 sm:p-4 sm:flex-row sm:items-center sm:justify-between">
        <button class="btn-ghost w-full sm:w-auto" @click="clear">Clear</button>
        <button
          class="btn w-full sm:w-auto"
          :disabled="exporting || (format === 'pdf' && pdfPaginated && pdfAllDownloaded)"
          @click="exportIt"
        >
          <svg v-if="exporting" class="mr-2 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"
               aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          {{ exportingLabel }}
        </button>
      </div>

      <p v-if="error" class="border-t border-slate-100 px-4 pb-4 pt-3 text-xs font-medium text-rose-700" role="alert">
        {{ error }}
      </p>
    </div>

    <p class="mt-3 text-xs text-slate-400">
      Exporting {{ dataTypeLabel.toLowerCase() }} shows only the rows you can already see — the same visibility
      rules as every other page in the CRM, for every format and all record counts.
    </p>
  </AppLayout>
</template>