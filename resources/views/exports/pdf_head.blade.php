<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $type }} Export</title>
    <style>
        @page {
            size: A4 {{ $landscape ? 'landscape' : 'portrait' }};
            margin: 14mm 12mm 14mm 12mm;
        }

        @php
            $n    = count($columns);
            $body = $n >= 15 ? '7px'  : ($n >= 12 ? '7.5px' : ($n >= 10 ? '8px' : '9px'));
            $cell = $n >= 15 ? '6px'  : ($n >= 12 ? '6.5px' : ($n >= 10 ? '7px' : '8px'));
        @endphp

        * { box-sizing: border-box; }

        html, body { margin: 0; padding: 0; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: {{ $body }};
            color: #0f172a;
            line-height: 1.35;
        }

        /* Shaligram CRM title/header */
        .header {
            border-bottom: 3px solid #0f766e;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .header .brand {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .header .brand span {
            color: #0f766e;
        }
        .header .sub {
            font-size: 9px;
            color: #64748b;
            margin-top: 2px;
        }

        .meta { margin-bottom: 10px; }
        .meta table { width: 100%; border-collapse: collapse; }
        .meta td {
            font-size: 9px;
            padding: 2px 0;
            vertical-align: top;
        }
        .meta .k { color: #64748b; width: 110px; }
        .meta .v { color: #0f172a; }

        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        /*
         | thead is what dompdf repeats at the top of every page the table
         | spills onto — the one header, printed wherever this PDF turns its
         | page.
         */
        table.data thead tr {
            background: #0f766e;
            color: #fff;
        }
        table.data th {
            font-size: {{ $cell }};
            font-weight: 700;
            text-align: left;
            padding: 4px 3px;
            border: 1px solid #0f766e;
            word-wrap: break-word;
        }
        table.data td {
            padding: 3px;
            border: 1px solid #cbd5e1;
            font-size: {{ $cell }};
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        table.data tr:nth-child(even) td {
            background: #f8fafc;
        }
        /* a row never splits across pages, so a row's text stays together */
        table.data tr { page-break-inside: avoid; }

        /* page numbers, repeated on every page by dompdf */
        .page-num {
            position: fixed;
            bottom: 5mm;
            right: 12mm;
            font-size: 7px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

    <div class="page-num">{PAGE_NUM} / {PAGE_COUNT}</div>

    <div class="header">
        <div class="brand">Shaligram <span>CRM</span></div>
        <div class="sub">Export · {{ $type }}</div>
    </div>

    <div class="meta">
        <table>
            <tr>
                <td class="k">Generated</td>
                <td class="v">{{ $generated }}</td>
            </tr>
            <tr>
                <td class="k">Total rows</td>
                <td class="v">{{ number_format($count) }}</td>
            </tr>
            @if ($totalPages > 1)
                <tr>
                    <td class="k">This file</td>
                    <td class="v">
                        Rows {{ number_format($rangeStart) }}&ndash;{{ number_format($rangeEnd) }} of {{ number_format($count) }}
                        (page {{ $page }} of {{ $totalPages }})
                    </td>
                </tr>
            @endif
            @foreach ($filters as $filter)
                <tr>
                    <td class="k">{{ $filter['label'] }}</td>
                    <td class="v">{{ $filter['value'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>