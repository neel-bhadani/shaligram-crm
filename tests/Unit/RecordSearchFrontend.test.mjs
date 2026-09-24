import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'
import { parse } from '@vue/compiler-sfc'

function componentScript(path) {
  return parse(readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8')).descriptor.scriptSetup.content.replace(/^import .*$/gm, '')
}

function exportPage(t) {
  const pending = []
  const timers = new Map()
  const unmount = []
  let timerId = 0
  const schedule = callback => { timers.set(++timerId, callback); return timerId }
  const scope = effectScope()
  const setup = new Function('reactive', 'ref', 'computed', 'watch', 'onMounted', 'onBeforeUnmount', 'defineProps', 'usePage', 'axios', 'route', 'window', 'clearTimeout', `${componentScript('Pages/Exports/Index.vue')}
    return { f, fetchCount, matchingCount, counting, filterPayload, clear }`)
  const state = scope.run(() => setup(reactive, ref, computed, watch, () => {}, fn => unmount.push(fn),
    () => ({ options: { today: '2026-09-24', pdfRowLimit: 1000, isAdmin: true, seeAllLeads: true } }),
    () => ({ props: { auth: { user: {} } } }),
    { post: (url, body) => new Promise((resolve, reject) => pending.push({ url, body, resolve, reject })) },
    name => name, { setTimeout: schedule }, id => timers.delete(id)))
  t.after(() => { unmount.forEach(fn => fn()); scope.stop() })
  return { ...state, pending, unmount, timers }
}

test('export search ignores an old response during debounce and after a newer count', async t => {
  const page = exportPage(t)
  page.f.data_type = 'channel_partners'
  page.f.search = 'old'
  await nextTick()
  const old = page.fetchCount()
  page.f.search = '9876543210'
  await nextTick()
  page.pending[0].resolve({ data: { count: 99 } })
  await old
  assert.equal(page.matchingCount.value, null)
  assert.equal(page.counting.value, true)
  const current = page.fetchCount()
  assert.equal(page.pending[1].body.search, '9876543210')
  page.pending[1].resolve({ data: { count: 1 } })
  await current
  assert.equal(page.matchingCount.value, 1)
  assert.equal(page.counting.value, false)

  const slow = page.fetchCount()
  page.f.search = 'nonexistent'
  await nextTick()
  const latest = page.fetchCount()
  page.pending[3].resolve({ data: { count: 0 } })
  await latest
  page.pending[2].resolve({ data: { count: 44 } })
  await slow
  assert.equal(page.matchingCount.value, 0)
})

test('clearing export search preserves filters and invalid dates discard pending counts', async t => {
  const page = exportPage(t)
  Object.assign(page.f, { data_type: 'channel_partners', search: 'Rahul', type: 'broker', status: 'active' })
  await nextTick()
  const old = page.fetchCount()
  page.f.search = ''
  await nextTick()
  assert.equal(page.filterPayload().search, undefined)
  assert.equal(page.filterPayload().type, 'broker')
  assert.equal(page.filterPayload().status, 'active')
  page.f.from = '2026-09-01'
  await nextTick()
  await page.fetchCount()
  page.pending[0].resolve({ data: { count: 10 } })
  await old
  assert.equal(page.matchingCount.value, null)
  assert.equal(page.counting.value, false)
})

test('unmount cancels export debounce and invalidates an outstanding response', async t => {
  const page = exportPage(t)
  const request = page.fetchCount()
  page.f.search = 'pending'
  await nextTick()
  page.unmount.forEach(fn => fn())
  assert.equal(page.timers.size, 0)
  page.pending[0].resolve({ data: { count: 90 } })
  await request
  assert.equal(page.matchingCount.value, null)
})

test('list filter debounce sends only latest search and is cancelled on clear and unmount', async t => {
  const source = readFileSync(new URL('../../resources/js/composables/useFilterVisit.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replaceAll('export function ', 'function ')
  const timers = new Map()
  const unmount = []
  const requests = []
  let timerId = 0
  const setup = new Function('watch', 'nextTick', 'onUnmounted', 'setTimeout', 'clearTimeout', `${source}; return useDebouncedFilters`)
  const useFilters = setup(watch, nextTick, fn => unmount.push(fn), callback => { timers.set(++timerId, callback); return timerId }, id => timers.delete(id))
  const scope = effectScope()
  t.after(() => scope.stop())
  const fields = reactive({ search: '', stage: 'fresh' })
  const filters = scope.run(() => useFilters(fields, () => requests.push({ ...fields })))
  fields.search = 'R'
  await nextTick()
  fields.search = 'Rahul'
  await nextTick()
  assert.equal(timers.size, 1)
  const callback = [...timers.values()][0]
  timers.clear()
  callback()
  assert.deepEqual(requests, [{ search: 'Rahul', stage: 'fresh' }])
  fields.search = 'queued'
  await nextTick()
  filters.cancel()
  filters.silently(() => { fields.search = '' })
  await nextTick()
  assert.equal(timers.size, 0)
  fields.search = 'leaving'
  await nextTick()
  unmount.forEach(fn => fn())
  assert.equal(timers.size, 0)
})

test('both partner pickers match complete option names with tokens, case, whitespace and literal symbols', () => {
  const source = componentScript('Components/SearchableSelect.vue')
  const setup = new Function('computed', 'nextTick', 'onBeforeUnmount', 'onMounted', 'ref', 'watch', 'defineProps', 'defineEmits', `${source}; return { query, matches }`)
  const options = Array.from({ length: 35 }, (_, i) => ({ value: i, label: `Ordinary Partner ${i}` }))
  options.push({ value: 99, label: 'Rahul Kumar Shah — Shaligram Properties %_\\' })
  const scope = effectScope()
  const state = scope.run(() => setup(computed, nextTick, () => {}, () => {}, ref, watch, () => ({ options }), () => () => {}))
  for (const term of ['RAHUL', 'Kumar', 'Shah', 'rahul shah', '  Shaligram  ', '%', '_', '\\']) {
    state.query.value = term
    assert.deepEqual(state.matches.value.map(o => o.value), [99], term)
  }
  state.query.value = 'nonexistent'
  assert.deepEqual(state.matches.value, [])
  state.query.value = '   '
  assert.equal(state.matches.value.length, 36)
  scope.stop()
})
