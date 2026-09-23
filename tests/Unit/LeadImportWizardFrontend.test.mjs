import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { computed, effectScope, reactive, ref, watch } from 'vue'
import { parse } from '@vue/compiler-sfc'

const { descriptor } = parse(readFileSync(new URL('../../resources/js/Pages/Leads/Import/Index.vue', import.meta.url), 'utf8'))
const script = descriptor.scriptSetup.content.replace(/^import .*$/gm, '')
const setup = new Function('ref', 'reactive', 'computed', 'watch', 'defineProps', 'axios', 'route', `${script}
  return { step, file, upload, mapping, parsed, final, busy, error, overrides, editing, editForm,
    settings, selectedSheet, previewStale, applyParsed, openEdit, saveEdit, resetRow,
    uploadFile, changeSheet, setMapping, previewParsed, previewFinal }
`)

function wizard(t, respond = () => ({})) {
  const requests = []
  const scope = effectScope()
  const state = scope.run(() => setup(ref, reactive, computed, watch,
    () => ({ options: { today: '2026-09-23' } }),
    { post: async (route, body) => {
      requests.push({ route, body: body instanceof FormData ? body : JSON.parse(JSON.stringify(body)) })
      return { data: respond(route, body) }
    } }, route => route))
  t.after(() => scope.stop())
  return { ...state, requests }
}

async function settled(state) {
  for (let attempt = 0; attempt < 20 && state.busy.value; attempt++) {
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(state.busy.value, false)
  assert.equal(state.error.value, '')
}

test('fixed-time payload omits meaningless file-time fields and starts without a default', async t => {
  const state = wizard(t)
  state.upload.value = { token: 'test' }
  state.mapping.full_name = 'Name'
  state.mapping.mobile_number = 'Mobile'
  state.applyParsed({ summary: {}, overrides: {} })
  assert.equal(state.settings.time, '')
  state.settings.time = '10:00'
  state.previewFinal()
  await settled(state)
  const { body } = state.requests.at(-1)
  assert.equal(body.defaults.time, '10:00')
  assert.equal('time_source' in body.defaults, false)
  assert.equal('fallback_time' in body.defaults, false)
  assert.deepEqual(body.mapping, { full_name: 'Name', mobile_number: 'Mobile' })
})

test('successive edits retain all corrections including an explicit blank; reset is explicit', async t => {
  const state = wizard(t, (route, body) => ({ summary: {}, overrides: body.overrides }))
  state.upload.value = { token: 'test' }
  state.applyParsed({ summary: {}, overrides: {} })
  const row = { row: 2, attributes: { first_name: 'ADVOCATE', middle_name: 'DPAK', last_name: 'RATHOD' } }
  for (const [field, value] of [['first_name', 'DPAK'], ['last_name', 'Rathod'], ['middle_name', '']]) {
    state.openEdit(row)
    state.editForm[field] = value
    state.saveEdit()
    await settled(state)
    row.attributes[field] = value
  }
  assert.deepEqual(state.requests.at(-1).body.overrides, {
    2: { first_name: 'DPAK', last_name: 'Rathod', middle_name: '' },
  })
  state.resetRow(row)
  await settled(state)
  assert.deepEqual(state.requests.at(-1).body.reset_rows, [2])
  assert.deepEqual(state.requests.at(-1).body.overrides, {})
})

test('ambiguous upload waits for explicit worksheet confirmation', async t => {
  const metadata = { token: 'test', sheet: 'First', sheets: ['First', 'Second'], sheetAmbiguous: true, guessedMapping: { full_name: 'Name', mobile_number: 'Mobile' } }
  const state = wizard(t, route => route.endsWith('upload') ? metadata : route.endsWith('sheet')
    ? { ...metadata, sheet: 'Second', sheetAmbiguous: false } : { summary: {}, overrides: {} })
  state.file.value = new Blob(['file'])
  state.uploadFile()
  await settled(state)
  assert.equal(state.step.value, 1)
  assert.equal(state.selectedSheet.value, '')
  assert.equal(state.requests.length, 1)
  state.changeSheet()
  assert.equal(state.requests.length, 1)
  state.selectedSheet.value = 'Second'
  state.changeSheet()
  await settled(state)
  assert.equal(state.requests[1].body.sheet, 'Second')
  assert.equal(state.step.value, 2)
})

test('a single clear worksheet proceeds automatically', async t => {
  const state = wizard(t, route => route.endsWith('upload')
    ? { token: 'test', sheet: 'Leads', sheetAmbiguous: false, guessedMapping: { full_name: 'Name', mobile_number: 'Mobile' } }
    : { summary: {}, overrides: {} })
  state.file.value = new Blob(['file'])
  state.uploadFile()
  await settled(state)
  assert.equal(state.step.value, 2)
  assert.equal(state.requests[1].route, 'leads.import.preview')
})

test('mapping changes block final review until the new mapping is previewed', async t => {
  const state = wizard(t, () => ({ summary: {}, overrides: {} }))
  state.upload.value = { token: 'test' }
  state.mapping.full_name = 'Name'
  state.mapping.mobile_number = 'Mobile'
  state.applyParsed({ summary: {}, overrides: {} })
  state.final.value = { plan_id: 'old' }
  state.setMapping('Alternate Name', 'full_name')
  assert.equal(state.previewStale.value, true)
  assert.equal(state.final.value, null)
  state.previewFinal()
  assert.equal(state.requests.length, 0)
  state.previewParsed()
  await settled(state)
  assert.equal(state.previewStale.value, false)
  state.settings.time = '10:00'
  state.previewFinal()
  await settled(state)
  assert.equal(state.requests.at(-1).body.mapping.full_name, 'Alternate Name')
})
