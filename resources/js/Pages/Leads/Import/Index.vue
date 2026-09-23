<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ options: Object })
const steps = ['Upload', 'Review Parsed Data', 'Follow-up Setup', 'Final Review', 'Import Result']
const step = ref(1)
const file = ref(null)
const upload = ref(null)
const mapping = reactive({})
const dateOrder = ref('auto')
const parsed = ref(null)
const final = ref(null)
const result = ref(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const previewLoading = ref(false)
const previewStale = ref(true)
const selectedSheet = ref('')
const resetRows = ref([])
let previewRequestSeq = 0
const problemPage = ref(1)
const overrides = ref({})
const previewFilter = ref('all')
const previewPage = ref(1)
const editing = ref(null)
const editForm = reactive({})
const settings = reactive({ project_id: null, source: null, stage: 'fresh', assigned_to: null, mode: 'today', start_date: props.options.today, end_date: props.options.today, time: '', time_mode: 'manual', fallback_time: '', follow_up_type: 'call' })
const currentPreview = computed(() => step.value === 2 ? parsed.value : step.value === 4 ? final.value : null)
const previewSafelyLoaded = computed(() => Boolean(currentPreview.value && currentPreview.value.summary))
const hasTimeColumn = computed(() => Boolean(mapping.follow_up_time) || Boolean(mapping.follow_up_datetime))
const timeMode = computed(() => settings.time_mode)
const problems = computed(() => currentPreview.value?.problemRows.slice((problemPage.value - 1) * 50, problemPage.value * 50) || [])
const problemPages = computed(() => Math.ceil((currentPreview.value?.problemRows.length || 0) / 50))
const editableFields = computed(() => {
  const list = ['first_name', 'middle_name', 'last_name', 'mobile_number', 'project', 'source', 'stage']
  if (mapping.email) list.push('email')
  if (mapping.created_at) list.push('created_at')
  return list
})
const fieldTitles = { first_name: 'First name', middle_name: 'Middle name', last_name: 'Last name', mobile_number: 'Mobile number', project: 'Project', source: 'Source', stage: 'Stage', created_at: 'Created at', email: 'Email' }
const fieldLabels = { first_name: 'First Name', middle_name: 'Middle Name', last_name: 'Last Name', mobile_number: 'Mobile', project: 'Project', source: 'Source', stage: 'Status', created_at: 'Created At', assigned_user: 'Assigned To' }
const requiredTitles = { first_name: 'First name (or a Full Name column)', mobile_number: 'Mobile number', full_name: 'Full name' }
const viewOriginal = ref(null)
const previewPages = computed(() => currentPreview.value?.pagination?.last_page || 1)
const visiblePreviewRows = computed(() => currentPreview.value?.rows || [])
function onFilterChange() {
  previewPage.value = 1
  if (parsed.value) previewParsed()
}
function setPage(page) {
  previewPage.value = page
  if (parsed.value) previewParsed()
}
const metrics = { total: 'Total rows', clean: 'Importable', problems: 'Problems', excluded: 'Excluded by user', duplicatesInDatabase: 'Database duplicates', duplicatesInFile: 'In-file duplicates', invalidMobile: 'Invalid mobile', unknownStage: 'Unknown stage', unknownSource: 'Unknown source', unknownProject: 'Unknown project', missingRequired: 'Missing required field', unauthorizedProject: 'Unauthorized project', alreadyImported: 'Already imported', invalidAssignee: 'Invalid assignee', invalidDate: 'Invalid date', invalidFollowUpDate: 'Invalid follow-up date', invalidTime: 'Invalid follow-up time', missingFollowUpTimes: 'Missing follow-up times' }
const fields = ['first_name', 'middle_name', 'last_name', 'mobile_number', 'project', 'source', 'stage', 'created_at', 'assigned_user']
const unsureMapped = (header) => ['medium', 'low'].includes(mappingConfidence.value[Object.keys(mapping).find(key => mapping[key] === header) || ''])
const display = (row, field) => field === 'project' ? row.project : field === 'assigned_user' ? (row.assigned_user || (step.value === 2 ? 'Set in Step 3' : '—')) : field === 'created_at' ? (row.attributes.created_at || 'Will use current import time') : (row.attributes[field] ?? 'Awaiting batch default in Step 3')
const fieldLabel = (field) => field === 'full_name' ? 'Full Name (split automatically)' : field

async function perform(action) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try { await action() } catch (e) {
    error.value = Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Request failed. Please retry.'
  } finally { busy.value = false }
}
function applyMetadata(data) {
  upload.value = { ...upload.value, ...data }
  Object.keys(mapping).forEach(key => delete mapping[key])
  Object.assign(mapping, data.guessedMapping)
  overrides.value = {}
  resetRows.value = []
  selectedSheet.value = data.sheetAmbiguous ? '' : data.sheet
}
const mappingConfidence = computed(() => upload.value?.mappingConfidence || {})
const unresolvedRequired = computed(() => upload.value?.unresolvedRequired || [])
const availableHeaders = computed(() => upload.value?.headers.filter(header => !Object.values(mapping).includes(header)) || [])
const confirmItems = computed(() => {
  const items = []
  for (const field of unresolvedRequired.value) items.push({ field, label: requiredTitles[field] || field, unresolved: true })
  for (const [field, candidates] of Object.entries(upload.value?.confirmations || {})) {
    if (!unresolvedRequired.value.includes(field)) items.push({ field, label: requiredTitles[field] || field, candidates })
  }
  return items
})
function confirmField(field, header) {
  if (!header) return
  setMapping(header, field)
  previewParsed()
}
function setMapping(header, field) {
  Object.keys(mapping).forEach(key => { if (mapping[key] === header) delete mapping[key] })
  if (field) mapping[field] = header
  final.value = null
}
function loadPreview() {
  const seq = ++previewRequestSeq
  previewLoading.value = true
  return axios.post(route('leads.import.preview'), { token: upload.value.token, mapping: { ...mapping }, date_order: dateOrder.value, overrides: overrides.value, reset_rows: resetRows.value, page: previewPage.value, per_page: 30, filter: previewFilter.value })
    .then((response) => {
      if (seq === previewRequestSeq) applyParsed(response.data)
    })
    .finally(() => {
      if (seq === previewRequestSeq) previewLoading.value = false
    })
}
function uploadFile() {
  perform(async () => {
    if (upload.value) await axios.post(route('leads.import.cancel'), { token: upload.value.token })
    upload.value = null
    const body = new FormData()
    body.append('file', file.value)
    const { data } = await axios.post(route('leads.import.upload'), body)
    applyMetadata(data)
    parsed.value = null
    final.value = null
    result.value = null
    if (data.sheetAmbiguous) {
      step.value = 1
      return
    }
    step.value = 2
    await loadPreview()
  })
}
function changeSheet() {
  if (!selectedSheet.value) return
  perform(async () => {
    const { data } = await axios.post(route('leads.import.sheet'), { token: upload.value.token, sheet: selectedSheet.value })
    applyMetadata(data)
    parsed.value = null
    final.value = null
    result.value = null
    step.value = 2
    await loadPreview()
  })
}
function applyParsed(data) {
  parsed.value = data
  overrides.value = data.overrides || {}
  resetRows.value = []
  previewStale.value = false
  if (data.overridesCleared) notice.value = 'Column mapping changed. Row edits were cleared — re-apply them before continuing.'
  final.value = null
  problemPage.value = 1
  previewPage.value = data.pagination?.current_page ?? 1
}
function previewParsed() {
  perform(async () => {
    await loadPreview()
    step.value = 2
  })
}
function originalValue(row, field) {
  if (['first_name', 'middle_name', 'last_name'].includes(field) && mapping.full_name) return row.raw[mapping.full_name]
  const column = mapping[field]
  return column ? (row.raw[column] ?? '') : null
}
function originalHint(row, field) {
  const value = originalValue(row, field)
  if (value === null) return 'Not in the file — comes from the Step 3 batch default.'
  return value === '' ? 'Original: (empty)' : `Original: ${value}`
}
function currentValue(row, field) {
  return field === 'project' ? (row.project || '') : (row.attributes[field] ?? '')
}
function rowHasEdits(row) {
  return Boolean(overrides.value[row.row] && Object.keys(overrides.value[row.row]).length)
}
function openEdit(row) {
  if (busy.value || previewStale.value) return
  editing.value = row
  Object.keys(editForm).forEach(key => delete editForm[key])
  for (const field of editableFields.value) editForm[field] = currentValue(row, field)
}
function closeEdit() {
  editing.value = null
}
function saveEdit() {
  const rowNumber = editing.value.row
  const next = { ...overrides.value }
  const nextRow = { ...next[rowNumber] }
  for (const field of editableFields.value) {
    const value = editForm[field] ?? ''
    if (value !== currentValue(editing.value, field)) nextRow[field] = value
  }
  if (Object.keys(nextRow).length) next[rowNumber] = nextRow
  else delete next[rowNumber]
  overrides.value = next
  editing.value = null
  previewParsed()
}
function resetRow(row) {
  if (busy.value || previewStale.value) return
  const next = { ...overrides.value }
  delete next[row.row]
  resetRows.value = [...new Set([...resetRows.value, row.row])]
  overrides.value = next
  editing.value = null
  previewParsed()
}
const excluding = ref(null)
function requestExclude(row) {
  excluding.value = row
}
function confirmExclude() {
  const row = excluding.value
  excluding.value = null
  perform(async () => {
    const { data } = await axios.post(route('leads.import.exclude'), { token: upload.value.token, row: row.row, page: previewPage.value, per_page: 30, filter: previewFilter.value })
    applyExcluded(data)
  })
}
function restoreRow(row) {
  perform(async () => {
    const { data } = await axios.post(route('leads.import.restore'), { token: upload.value.token, row: row.row, page: previewPage.value, per_page: 30, filter: previewFilter.value })
    applyExcluded(data)
  })
}
function applyExcluded(data) {
  parsed.value = data
  if (data.overrides) overrides.value = data.overrides
  final.value = null
  previewPage.value = data.pagination?.current_page ?? 1
}
watch([mapping, dateOrder], () => {
  previewStale.value = true
  final.value = null
  previewRequestSeq++
  previewLoading.value = false
  if (Object.keys(overrides.value).length) {
    overrides.value = {}
    notice.value = 'Column mapping changed. Row edits were cleared — re-apply them before continuing.'
  }
}, { deep: true, flush: 'sync' })
function previewFinal() {
  if (previewStale.value || !parsed.value) return
  perform(async () => {
    const defaults = { ...settings, project_id: mapping.project ? null : settings.project_id, source: mapping.source ? null : settings.source, stage: mapping.stage ? null : settings.stage }
    delete defaults.time_source
    if (timeMode.value !== 'manual') delete defaults.time
    if (timeMode.value !== 'uploaded') delete defaults.fallback_time
    const { data } = await axios.post(route('leads.import.final-preview'), { token: upload.value.token, accepted_preview: true, mapping: { ...mapping }, date_order: dateOrder.value, defaults })
    final.value = data
    problemPage.value = 1
    step.value = 4
  })
}
function runImport() {
  perform(async () => {
    step.value = 5
    let offset = result.value?.nextOffset || 0
    do {
      const { data } = await axios.post(route('leads.import.chunk'), { token: upload.value.token, plan_id: final.value.plan_id, confirm: true, offset })
      result.value = data
      offset = data.nextOffset
    } while (!result.value.done)
  })
}
function cancel() {
  perform(async () => {
    previewRequestSeq++
    previewLoading.value = false
    if (upload.value) await axios.post(route('leads.import.cancel'), { token: upload.value.token })
    upload.value = null
    file.value = null
    parsed.value = null
    final.value = null
    result.value = null
    step.value = 1
  })
}
</script>

<template>
  <AppLayout title="Import Leads">
    <Head title="Import Leads" />
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div><h1 class="text-xl font-semibold text-slate-800">Import Leads</h1><p class="mt-1 text-sm text-slate-500">Upload a file, review how it was understood, set follow-ups, confirm.</p></div>
        <button v-if="upload && step < 5" class="btn-ghost" :disabled="busy" @click="cancel">Cancel import</button>
      </div>
      <ol class="card flex flex-wrap gap-3 p-4 text-sm" aria-label="Import steps">
        <li v-for="(label, index) in steps" :key="label" :aria-current="step === index + 1 ? 'step' : undefined" :class="step === index + 1 ? 'font-semibold text-teal-700' : 'text-slate-500'">{{ index + 1 }} {{ label }}<span v-if="index < 4" class="ml-3 text-slate-300">›</span></li>
      </ol>
      <p v-if="error" role="alert" class="rounded-lg bg-rose-50 p-4 text-sm text-rose-700">{{ error }}</p>
      <p v-if="notice" role="status" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">{{ notice }}</p>
      <div v-if="step === 1" class="card space-y-4 p-5">
        <h2 class="font-semibold">Upload CSV or Excel</h2>
        <p class="text-sm text-slate-500">CSV, XLS, XLSX · up to 20 MB · 10,000 rows per sheet · up to 100 columns. Your file is read automatically — the header row, the right sheet and the column meanings are detected, then you just confirm. Files are private and expire after 24 hours.</p>
        <input type="file" accept=".csv,.xls,.xlsx" aria-label="Lead file" :disabled="busy" @change="file = $event.target.files[0]" />
        <button class="btn" :disabled="!file || busy" @click="uploadFile">{{ busy ? 'Reading and detecting…' : 'Upload & detect' }}</button>
        <template v-if="upload">
          <p class="text-sm">{{ upload.filename }} · {{ upload.totalRows }} lead rows detected · Sheet: {{ upload.sheet }} · header on row {{ upload.headerRow }}</p>
          <FormField v-if="upload.sheets.length > 1" label="Sheet">
            <p v-if="upload.sheetAmbiguous" class="text-sm text-amber-700">We found multiple sheets that may contain lead data. Please choose one.</p>
            <select v-model="selectedSheet" :disabled="busy"><option value="" disabled>Select a sheet…</option><option v-for="sheet in upload.sheets" :key="sheet">{{ sheet }}</option></select>
            <button class="btn mt-3" :disabled="busy || !selectedSheet" @click="changeSheet">Confirm sheet</button>
          </FormField>
        </template>
      </div>
      <template v-if="step === 2 || step === 4">
        <div class="card space-y-3 p-5">
          <h2 class="font-semibold">{{ step === 2 ? 'Review Parsed Data' : 'Final Review' }}</h2>
          <p class="text-sm text-slate-500">{{ step === 2 ? 'How your file was understood. Fix anything with Edit before moving on.' : 'Confirm the resolved leads and follow-up schedule below.' }} No business records have been written yet.</p>
          <div v-if="step === 2 && confirmItems.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
            <p class="font-semibold">Confirm detected fields</p>
            <p v-if="unresolvedRequired.length" class="mt-1">The file does not obviously provide a column for the required fields below — choose which column holds each one, or set it under Advanced Mapping.</p>
            <p v-else class="mt-1">More than one column could hold this field — confirm which one to use.</p>
            <div v-for="item in confirmItems" :key="item.field" class="mt-2 flex flex-wrap items-center gap-3">
              <label class="w-52 font-medium">{{ item.label }}</label>
              <select :value="mapping[item.field] || ''" :aria-label="`Confirm ${item.label}`" class="max-w-xs !rounded-lg border border-amber-300 bg-white px-2 py-1.5" @change="confirmField(item.field, $event.target.value)">
                <option value="">Select column…</option>
                <option v-for="header in (item.unresolved ? availableHeaders : (item.candidates || []))" :key="header" :value="header">{{ header }}</option>
              </select>
            </div>
            <p v-if="unresolvedRequired.length" class="mt-2 text-xs">Once the required fields are confirmed, the preview below refreshes automatically.</p>
          </div>
          <div v-if="previewLoading && !previewSafelyLoaded" class="grid grid-cols-2 gap-3 sm:grid-cols-4" role="status" aria-label="Preparing preview"><div v-for="placeholder in 8" :key="placeholder" class="h-16 animate-pulse rounded-lg bg-slate-100"></div></div>
          <div v-else-if="previewSafelyLoaded" class="grid grid-cols-2 gap-3 sm:grid-cols-4"><div v-for="(label, key) in metrics" :key="key" class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">{{ label }}</p><p class="text-lg font-semibold">{{ currentPreview.summary[key] || 0 }}</p></div></div>
          <p v-else class="text-sm text-slate-500">Waiting for a valid preview.</p>
          <template v-if="step === 4 && previewSafelyLoaded"><p class="font-semibold">{{ final.summary.toCreate }} leads will be created · {{ final.summary.excluded }} excluded by user · {{ final.summary.problems }} rows skipped · {{ final.summary.followUpsToCreate }} follow-ups</p><div v-for="(count, date) in final.dateDistribution" :key="date" class="text-sm">{{ date }}: {{ count }} follow-ups (Asia/Kolkata)</div><details v-if="final.excludedRows?.length" class="mt-3 rounded-lg bg-slate-50 p-3 text-sm"><summary class="cursor-pointer font-semibold text-slate-700">Excluded rows ({{ final.excludedRows.length }})</summary><div class="mt-2 divide-y divide-slate-100"><div v-for="row in final.excludedRows" :key="row.row" class="flex items-start justify-between gap-3 py-2"><span>Row {{ row.row }} · {{ row.attributes.first_name || 'Missing name' }} · {{ row.attributes.mobile_number || row.raw[mapping.mobile_number] }}</span><span class="text-xs text-slate-500">Excluded by user</span></div></div></details></template>
        </div>
        <div v-if="step === 2" class="card p-4">
          <details class="group">
            <summary class="cursor-pointer text-sm font-semibold text-teal-700">Advanced Mapping — change the detected columns <span class="font-normal text-slate-500">(optional; usually unnecessary)</span></summary>
            <div class="mt-3 space-y-3">
              <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-3">Source column</th><th class="p-3">CRM field</th><th v-for="(_, i) in upload.sampleRows" :key="i" class="p-3">Sample {{ i + 1 }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100"><tr v-for="header in upload.headers" :key="header"><td class="p-3 font-medium">{{ header }}</td><td class="p-3"><select :aria-label="`Map ${header}`" :disabled="busy" :value="Object.keys(mapping).find(key => mapping[key] === header) || ''" @change="setMapping(header, $event.target.value)"><option value="">Do not import</option><option v-for="field in options.fields" :key="field" :value="field">{{ fieldLabel(field) }}</option></select><p v-if="unsureMapped(header)" class="text-xs text-amber-700">Found in the data, not a recognised header — verify.</p></td><td v-for="(sample, i) in upload.sampleRows" :key="i" class="p-3 whitespace-pre-wrap">{{ sample[header] || '—' }}</td></tr></tbody>
              </table></div>
              <p v-if="mapping.full_name && !mapping.first_name" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Mapped as <strong>Full Name</strong> — every value is automatically split into First / Middle / Last by word count.</p>
              <p v-else-if="mapping.full_name && mapping.first_name" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">A full-name column cannot be combined with separate First / Middle / Last columns. Remove one side before previewing.</p>
              <FormField v-if="mapping.created_at || mapping.follow_up_date || mapping.follow_up_datetime" label="Numeric date order" hint="Two-digit years 00–69 mean 2000–2069; 70–99 mean 1970–1999. ISO and Excel dates retain their time."><select v-model="dateOrder" :disabled="busy"><option value="auto">Detect unambiguous dates; flag ambiguous dates</option><option value="dmy">Day–Month–Year</option><option value="mdy">Month–Day–Year</option></select></FormField>
              <button class="btn" :disabled="busy" @click="previewParsed">Apply Mapping / Refresh Preview</button>
              <p v-if="previewStale" class="text-sm text-amber-700">Mapping changed. Refresh the parsed preview before continuing.</p>
            </div>
          </details>
        </div>
        <div class="card overflow-x-auto p-4">
          <div v-if="step === 2" class="mb-3 flex flex-wrap items-center gap-3">
            <select v-model="previewFilter" class="!w-auto text-sm" aria-label="Filter rows" @change="onFilterChange"><option value="all">All</option><option value="clean">Importable</option><option value="problems">Problems</option><option value="edited">Edited</option><option value="duplicates">Duplicates</option><option value="excluded">Excluded</option></select>
            <span class="text-xs text-slate-500">Showing {{ (currentPreview.rows || []).length }} of {{ currentPreview.pagination?.total || 0 }} rows</span>
          </div>
          <p class="mb-3 text-xs text-slate-500">{{ step === 2 ? 'Parsed rows. Use Edit to correct any value, or Exclude to leave a row out of this batch; corrected rows are revalidated against the same rules.' : 'First 20 importable leads' }}</p>
          <table v-if="previewSafelyLoaded" class="w-full whitespace-nowrap text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-3">Row</th><th v-for="field in fields" :key="field" class="p-3">{{ fieldLabels[field] }}</th><template v-if="step === 4"><th class="p-3">Follow-up type</th><th class="p-3">Follow-up date</th><th class="p-3">Time (IST)</th></template><th class="p-3">Status / problems</th></tr></thead><tbody class="divide-y divide-slate-100"><template v-if="step === 2 ? visiblePreviewRows.length : currentPreview.previewRows.length"><tr v-for="row in (step === 2 ? visiblePreviewRows : currentPreview.previewRows)" :key="row.row" :class="row.outcome === 'excluded' ? 'bg-slate-50/80 text-slate-500' : ''"><td class="p-3"><div class="flex items-center gap-3 whitespace-nowrap"><span>{{ row.row }}</span><button v-if="step === 2" type="button" class="text-xs font-medium text-teal-700 underline underline-offset-2 hover:text-teal-900" @click="openEdit(row)">Edit</button><button v-if="step === 2 && row.outcome === 'excluded'" type="button" class="text-xs font-medium text-teal-700 underline underline-offset-2 hover:text-teal-900" @click="restoreRow(row)">Restore</button><button v-else-if="step === 2" type="button" class="text-xs font-medium text-slate-400 underline underline-offset-2 hover:text-slate-600" @click="requestExclude(row)">Exclude</button><button type="button" class="text-xs font-medium text-slate-400 underline underline-offset-2 hover:text-slate-600" @click="viewOriginal = row">View original</button><button v-if="step === 2 && row.edited" type="button" class="text-xs font-medium text-slate-400 underline underline-offset-2 hover:text-slate-600" @click="resetRow(row)">Reset</button></div></td><td v-for="field in fields" :key="field" class="p-3">{{ display(row, field) }}</td><template v-if="step === 4"><td class="p-3">{{ options.todoTypes[row.follow_up_type] || 'None — terminal stage' }}</td><td class="p-3">{{ row.follow_up_at?.slice(0, 10) || '—' }}</td><td class="p-3">{{ row.follow_up_at?.slice(11, 16) || '—' }}</td></template><td class="max-w-md whitespace-normal p-3"><div class="flex flex-wrap items-center gap-2"><span v-if="row.outcome === 'excluded'" class="rounded bg-slate-200/70 px-1.5 py-0.5 text-xs font-medium text-slate-600">Excluded by user</span><span v-if="row.edited" class="rounded bg-teal-50 px-1.5 py-0.5 text-xs font-medium text-teal-700">Edited</span><span v-if="row.outcome !== 'create' && row.errors.length" class="rounded bg-rose-50 px-1.5 py-0.5 text-xs font-medium text-rose-700">Problem ({{ row.errors.length }})</span><span v-if="row.outcome === 'skip_duplicate_file' || row.outcome === 'skip_duplicate_db'" class="rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700">{{ row.outcome === 'skip_duplicate_file' ? 'In-file duplicate' : 'Database duplicate' }}</span><span v-show="row.pending.length" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Pending: {{ row.pending.join(' · ') }}</span></div><p v-for="reason in row.errors" :key="reason" class="text-rose-600">{{ reason }}</p></td></tr></template><tr v-else><td :colspan="fields.length + (step === 4 ? 4 : 2)" class="p-3 text-slate-500">No rows to show.</td></tr></tbody>          </table>
          <p v-else-if="previewLoading" class="mb-3 text-sm text-slate-500">Reading rows…</p>
          <p v-else class="mb-3 text-sm text-slate-500">No rows to review yet.</p>
          <div v-if="step === 2 && previewSafelyLoaded && previewPages > 1" class="mt-3 flex items-center gap-3"><button class="btn-ghost" :disabled="previewPage === 1" @click="setPage(previewPage - 1)">Previous</button><span class="text-sm">Page {{ previewPage }} / {{ previewPages }}</span><button class="btn-ghost" :disabled="previewPage >= previewPages" @click="setPage(previewPage + 1)">Next</button></div>
        </div>
        <div v-if="previewSafelyLoaded && currentPreview.problemRows.length" class="card p-4">
          <h3 class="mb-3 font-semibold">All skipped / problematic rows</h3>
          <div class="divide-y divide-slate-100"><div v-for="row in problems" :key="row.row" class="flex items-start justify-between gap-3 py-3 text-sm"><div><p class="font-medium">Row {{ row.row }} · {{ row.attributes.first_name || 'Missing name' }} · {{ row.attributes.mobile_number || row.raw[mapping.mobile_number] }} <span class="ml-1 rounded bg-rose-50 px-1.5 py-0.5 text-xs font-medium text-rose-700">Problem ({{ row.errors.length }})</span></p><p v-for="reason in row.errors" :key="reason" class="text-rose-700">{{ reason }}</p></div><div v-if="step === 2" class="flex flex-none items-center gap-3"><button v-if="row.edited" type="button" class="text-xs font-medium text-slate-400 underline underline-offset-2 hover:text-slate-600" @click="resetRow(row)">Reset</button><button type="button" class="text-xs font-medium text-teal-700 underline underline-offset-2 hover:text-teal-900" @click="openEdit(row)">Edit</button></div></div></div>
          <div class="mt-3 flex items-center gap-3"><button class="btn-ghost" :disabled="problemPage === 1" @click="problemPage--">Previous</button><span class="text-sm">Page {{ problemPage }} / {{ problemPages }}</span><button class="btn-ghost" :disabled="problemPage >= problemPages" @click="problemPage++">Next</button></div>
        </div>
        <div class="flex justify-between"><button class="btn-ghost" :disabled="busy" @click="step = step === 2 ? 1 : 3">{{ step === 2 ? 'Back to Upload' : 'Back to Follow-up Setup' }}</button><button v-if="step === 2" class="btn" :disabled="busy || previewStale || !parsed" @click="step = 3">Continue to Follow-up Setup</button><button v-else class="btn" :disabled="busy" @click="runImport">Confirm Final Import</button></div>
      </template>
      <form v-if="step === 3" class="card space-y-5 p-5" @submit.prevent="previewFinal">
        <h2 class="font-semibold">Follow-up Setup</h2>
        <p class="text-sm text-slate-500">Only what the file does not already provide. Detected project, source and stage are shown read-only below Template — the file wins those.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="Project" :required="!mapping.project"><p v-if="mapping.project" class="text-sm">Detected in file: {{ mapping.project }}</p><select v-else v-model="settings.project_id" required><option :value="null">Select project</option><option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></FormField>
          <FormField label="Source" :required="!mapping.source"><p v-if="mapping.source" class="text-sm">Detected in file: {{ mapping.source }}</p><select v-else v-model="settings.source" required><option :value="null">Select source</option><option v-for="(label, key) in options.sources" :key="key" :value="key">{{ label }}</option></select></FormField>
          <FormField label="Initial Stage" :required="!mapping.stage"><p v-if="mapping.stage" class="text-sm">Detected in file: {{ mapping.stage }}</p><select v-else v-model="settings.stage" required><option v-for="(label, key) in options.stages" :key="key" :value="key">{{ label }}</option></select></FormField>
          <FormField label="Assign To" hint="Stage roles and salesperson project membership apply. Final Review shows the resolved person."><p v-if="mapping.assigned_user" class="text-sm">Detected in file: {{ mapping.assigned_user }}</p><select v-else v-model="settings.assigned_to"><option :value="null">Use existing lead assignment rules</option><option v-for="u in options.assignableUsers" :key="u.id" :value="u.id">{{ u.name }} ({{ u.role }})</option></select></FormField>
          <FormField label="Follow-up Type" required><select v-model="settings.follow_up_type"><option v-for="(label, key) in options.todoTypes" :key="key" :value="key">{{ label }}</option></select></FormField>
          <FormField label="Follow-up Schedule Mode" required><select v-model="settings.mode"><option value="today">Today</option><option value="specific">Specific Date</option><option value="spread">Spread Across Date Range</option></select></FormField>
          <FormField v-if="settings.mode !== 'today'" :label="settings.mode === 'spread' ? 'Start Date' : 'Date'" required><input v-model="settings.start_date" type="date" required /></FormField>
          <FormField v-if="settings.mode === 'spread'" label="End Date" required><input v-model="settings.end_date" type="date" :min="settings.start_date" required /></FormField>
          <FormField label="Follow-up Time Mode" required>
            <select v-model="settings.time_mode">
              <option v-if="hasTimeColumn" value="uploaded">Use follow-up times from the uploaded file</option>
              <option value="manual">Manual — one time for all rows</option>
              <option value="auto">Auto Schedule — 9:00 AM–5:00 PM Asia/Kolkata</option>
            </select>
          </FormField>
          <FormField v-if="settings.time_mode === 'uploaded'" label="Fallback time for rows without one" required hint="Rows with a blank follow-up time in the file are scheduled at this time instead. A value that is not a valid clock time is flagged as a problem you must fix."><input v-model="settings.fallback_time" type="time" required /></FormField>
          <FormField v-else-if="settings.time_mode === 'manual'" label="Follow-up Time (Asia/Kolkata)" required><input v-model="settings.time" type="time" required /></FormField>
          <p v-if="settings.time_mode === 'auto'" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Each date is scheduled on its own: follow-ups are spaced evenly between 9:00 AM and 5:00 PM (Asia/Kolkata) in source-row order. Rows that land on today start from the next whole minute after now and are never set in the past.</p>
        </div>
        <p v-if="settings.time_mode === 'uploaded' && parsed && parsed.summary && parsed.summary.missingFollowUpTimes > 0" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-700">{{ parsed.summary.missingFollowUpTimes }} rows have no follow-up time in the file — they will use the fallback time above.</p>
        <p v-if="hasTimeColumn && settings.time_mode === 'uploaded'" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Follow-up dates and times come from the mapped file column. Rows without a value fall back to your schedule mode and time.</p>
        <p v-if="settings.mode === 'spread'" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Open leads are distributed evenly in source-row order. Earlier dates receive any remainder: 103 leads over 5 days gives 21, 21, 21, 20, 20. Terminal stages receive no pending follow-up.</p>
        <div class="flex justify-between"><button type="button" class="btn-ghost" :disabled="busy" @click="step = 2">Back to Parsed Review</button><button class="btn" :disabled="busy || previewStale">{{ busy ? 'Preparing final plan…' : 'Final Review' }}</button></div>
      </form>
      <div v-if="step === 5" class="card space-y-4 p-5" aria-live="polite">
        <h2 class="font-semibold">{{ result?.done ? 'Import Result — complete' : 'Import Result — in progress' }}</h2>
        <p>Processing {{ result?.processed || 0 }} / {{ result?.total || (final?.summary?.total ?? 0) }}</p>
        <progress class="w-full" :value="result?.processed || 0" :max="result?.total || (final?.summary?.total ?? 0)" />
        <p class="text-lg">Created: {{ result?.created || 0 }} · Skipped: {{ result?.skipped || 0 }} · Failed: {{ result?.failed || 0 }} · Excluded: {{ result?.excluded || 0 }} · Follow-ups: {{ result?.followUps || 0 }}</p>
        <template v-if="result?.done"><p class="text-sm text-slate-500">Duration: {{ result.duration }} seconds. Open leads without a pending follow-up: {{ result.beforeOpenWithoutPending }} before → {{ result.afterOpenWithoutPending }} after.</p><a class="btn-ghost inline-block" :href="route('leads.import.download', { token: upload.token })">Download skipped / failed CSV</a><button class="btn" :disabled="busy" @click="cancel">Import another file</button></template>
        <button v-else-if="!busy" class="btn" @click="runImport">Retry / resume import</button>
      </div>
      <Modal :show="Boolean(editing)" :title="editing ? `Edit row ${editing.row}` : 'Edit row'" max-width="max-w-lg" @close="closeEdit">
        <div v-if="editing" class="space-y-4">
          <FormField v-for="field in editableFields" :key="field" :label="fieldTitles[field]" :hint="originalHint(editing, field)">
            <input v-if="['first_name', 'middle_name', 'last_name', 'mobile_number', 'email'].includes(field)" v-model="editForm[field]" type="text" />
            <select v-else-if="field === 'project'" v-model="editForm.project"><option value="">No specific project</option><option v-for="p in options.projects" :key="p.id" :value="p.name">{{ p.name }}</option></select>
            <select v-else-if="field === 'source'" v-model="editForm.source"><option value="">Select source</option><option v-for="(label, key) in options.sources" :key="key" :value="key">{{ label }}</option></select>
            <select v-else-if="field === 'stage'" v-model="editForm.stage"><option value="">Select stage</option><option v-for="(label, key) in options.stages" :key="key" :value="key">{{ label }}</option></select>
            <input v-else v-model="editForm.created_at" type="text" placeholder="YYYY-MM-DD HH:mm:ss" />
          </FormField>
        </div>
        <template #footer>
          <button type="button" class="btn-ghost" @click="closeEdit">Cancel</button>
          <button v-if="editing && rowHasEdits(editing)" type="button" class="btn-ghost" @click="resetRow(editing)">Reset to parsed value</button>
          <button type="button" class="btn" :disabled="busy" @click="saveEdit">Save edits</button>
        </template>
      </Modal>
      <Modal :show="Boolean(viewOriginal)" :title="viewOriginal ? `Original row ${viewOriginal.row}` : 'Original row'" max-width="max-w-lg" @close="viewOriginal = null">
        <div v-if="viewOriginal" class="divide-y divide-slate-100 text-sm">
          <p class="mb-2 text-slate-500">The raw cell values from your file for this row, exactly as read.</p>
          <div v-for="(value, header) in viewOriginal.raw" :key="header" class="flex items-start justify-between gap-3 py-2">
            <span class="font-medium">{{ header }}</span><span class="whitespace-pre-wrap text-right text-slate-700">{{ value || '—' }}</span>
          </div>
        </div>
      </Modal>
    <Modal :show="Boolean(excluding)" title="Exclude this row from the import?" max-width="max-w-md" @close="excluding = null">
        <p class="text-sm text-slate-600">This will not delete the original file or any existing CRM data. You can restore the row later from Review Parsed Data.</p>
        <template #footer>
          <button type="button" class="btn-ghost" @click="excluding = null">Cancel</button>
          <button type="button" class="btn" :disabled="busy" @click="confirmExclude">Exclude</button>
        </template>
      </Modal>
    </div>
  </AppLayout>
</template>
