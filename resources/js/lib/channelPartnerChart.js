// Use the date-filtered, SQL-aggregated table rows without altering their order or metrics.
export function summarizeChannelPartners(rows, topN = 15) {
  const ranked = rows.filter(row => row.key !== '__none__' && row.total > 0)
    .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label) || String(a.key).localeCompare(String(b.key)))
  const selected = ranked.slice(0, topN)
  const others = ranked.slice(topN).reduce((sum, row) => sum + row.total, 0)
  if (others > 0) selected.push({ key: '__others__', label: 'Others', total: others })

  const total = rows.reduce((sum, row) => sum + row.total, 0)
  const unassigned = rows.find(row => row.key === '__none__')?.total ?? 0
  return { rows: selected, unassigned, share: total > 0 ? unassigned / total * 100 : 0 }
}

export const partnerLabel = label => {
  const characters = Array.from(label)
  return characters.length > 22 ? `${characters.slice(0, 21).join('')}…` : label
}

export const partnerColor = (row, index) => row.key === '__others__' ? '#94a3b8'
  : ['#0F766E', '#2F6FB0', '#8145A8', '#C2711A', '#1E7A45', '#B23A38', '#5B58B8', '#B4881B'][index % 8]

export function channelPartnerConfig(rows, shape) {
  const bar = shape === 'bar'
  return {
    type: shape,
    data: {
      labels: rows.map(row => row.label),
      datasets: [{ label: 'Leads', data: rows.map(row => row.total),
        backgroundColor: rows.map(partnerColor), borderWidth: 0,
        ...(bar ? { borderRadius: 4, maxBarThickness: 24 } : {}),
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      ...(bar ? {
        indexAxis: 'y',
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Leads' } },
          y: { grid: { display: false }, ticks: { autoSkip: false, minRotation: 0, maxRotation: 0,
            callback: function (value) { return partnerLabel(this.getLabelForValue(value)) },
          } },
        },
      } : { cutout: '58%' }),
      plugins: {
        // A wrapping HTML legend below the doughnut leaves room for the ring on tablets.
        legend: { display: false },
        tooltip: { callbacks: {
          title: items => items[0]?.label ?? '',
          label: context => {
            const count = bar ? context.parsed.x : context.parsed
            return ` ${count.toLocaleString()} leads`
          },
        } },
      },
    },
  }
}
