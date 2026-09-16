import test from 'node:test'
import assert from 'node:assert/strict'
import { channelPartnerConfig, partnerLabel, summarizeChannelPartners } from '../../resources/js/lib/channelPartnerChart.js'

const partners = Array.from({ length: 30 }, (_, i) => ({ key: String(i), label: `Partner ${i}`, total: i + 1 }))
const rows = [...partners, { key: '__none__', label: 'No channel partner', total: 5027 }]

for (const [n, others] of [[10, 210], [15, 120], [25, 15]]) {
  test(`Top ${n} ranks actual partners and sums the remainder without changing table rows`, () => {
    const before = structuredClone(rows)
    const summary = summarizeChannelPartners(rows, n)
    assert.equal(summary.rows.length, n + 1)
    assert.equal(summary.rows[0].total, 30)
    assert.equal(summary.rows[n - 1].total, 31 - n)
    assert.deepEqual(summary.rows.at(-1), { key: '__others__', label: 'Others', total: others })
    assert.equal(summary.unassigned, 5027)
    assert.equal(summary.share.toFixed(1), '91.5')
    assert.deepEqual(rows, before)
    assert.equal(summary.rows.some(row => row.key === '__none__'), false)
  })
}

test('defaults to 15 and recalculates from replacement period rows', () => {
  assert.equal(summarizeChannelPartners(rows).rows.length, 16)
  assert.deepEqual(summarizeChannelPartners([partners[0]]).rows, [partners[0]])
  assert.equal(summarizeChannelPartners([partners[0]]).share, 0)
})

test('empty, unassigned-only and zero-lead periods have no chart categories', () => {
  for (const periodRows of [[], [rows.at(-1)], [{ key: '1', label: 'Inactive', total: 0, booked: 3 }]]) {
    assert.deepEqual(summarizeChannelPartners(periodRows).rows, [])
  }
  assert.equal(summarizeChannelPartners([]).share, 0)
  assert.equal(summarizeChannelPartners([rows.at(-1)]).share, 100)
})

test('uses partner identity rather than name to exclude unassigned leads and resolves ties consistently', () => {
  const summary = summarizeChannelPartners([
    { key: '3', label: 'Zebra', total: 1 },
    { key: '2', label: 'No channel partner', total: 1 },
    { key: '1', label: 'Alpha', total: 1 },
  ])
  assert.deepEqual(summary.rows.map(row => row.key), ['1', '2', '3'])
})

test('both chart modes preserve full tooltip names and counts while bar ticks truncate names', () => {
  const label = 'Shailesh Thakkar Property Consultant'
  for (const mode of ['bar', 'doughnut']) {
    const config = channelPartnerConfig([{ key: '1', label, total: 23 }], mode)
    assert.equal(config.type, mode)
    assert.deepEqual(config.data.labels, [label])
    assert.deepEqual(config.data.datasets[0].data, [23])
    assert.equal(config.options.plugins.tooltip.callbacks.title([{ label }]), label)
    assert.equal(config.options.plugins.tooltip.callbacks.label({ parsed: mode === 'bar' ? { x: 23 } : 23 }), ' 23 leads')
    if (mode === 'bar') {
      assert.equal(config.options.indexAxis, 'y')
      assert.equal(config.options.scales.y.ticks.callback.call({ getLabelForValue: () => label }, 0), 'Shailesh Thakkar Prop…')
    }
  }
  assert.equal(partnerLabel('Ravi Patel'), 'Ravi Patel')
})
