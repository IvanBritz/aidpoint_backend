<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fund Allocation and Management Report</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11px; color: #111827; margin: 0; padding: 24pt; }
        .header { text-align: center; margin-bottom: 16pt; }
        .title { font-size: 18px; font-weight: 800; color: #374151; letter-spacing: 0.5px; }
        .sub { font-size: 10px; color: #6b7280; margin-top: 4px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #e5e7eb; font-size: 9px; color: #374151; }
        .meta { display: table; width: 100%; margin: 12pt 0 18pt 0; }
        .meta .col { display: table-cell; width: 50%; vertical-align: top; }
        .meta .label { font-size: 9px; color: #6b7280; text-transform: uppercase; font-weight: 700; margin-bottom: 3px; }
        .meta .value { font-size: 11px; font-weight: 700; color: #111827; }
        .cards { display: table; width: 100%; table-layout: fixed; margin-bottom: 14pt; }
        .card { display: table-cell; padding: 10pt; border: 1px solid #e5e7eb; border-radius: 8px; }
        .card + .card { padding-left: 12pt; }
        .card-title { font-size: 9px; color: #6b7280; text-transform: uppercase; font-weight: 700; }
        .card-value { font-size: 16px; font-weight: 800; color: #1f2937; margin-top: 6px; }
        .section { margin-top: 16pt; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; page-break-inside: avoid; }
        .section-header { background: #f9fafb; padding: 8pt 12pt; border-bottom: 1px solid #e5e7eb; }
        .section-title { font-size: 12px; font-weight: 800; color: #111827; }
        .section-body { padding: 10pt 12pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 6pt 8pt; font-size: 10px; }
        th { background: #f3f4f6; color: #374151; text-transform: uppercase; font-weight: 800; font-size: 9px; }
        .text-right { text-align: right; }
        .muted { color: #6b7280; font-size: 9px; }
        .group-title { font-weight: 800; margin-top: 8pt; margin-bottom: 4pt; color: #374151; }
        .footer { text-align: center; margin-top: 16pt; border-top: 1px solid #e5e7eb; padding-top: 10pt; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Fund Allocation and Management Report</div>
        <div class="sub">{{ $facility['center_name'] ?? 'N/A' }} — Center ID: {{ $facility['center_id'] ?? 'N/A' }}</div>
        <div class="sub">Generated on {{ $generated_at }} by {{ $generated_by }}</div>
    </div>

    <div class="cards">
        <div class="card">
            <div class="card-title">Total Allocated</div>
            <div class="card-value">₱{{ number_format($summary['total_allocated'] ?? 0, 2) }}</div>
        </div>
        <div class="card">
            <div class="card-title">Total Utilized</div>
            <div class="card-value">₱{{ number_format($summary['total_utilized'] ?? 0, 2) }}</div>
        </div>
        <div class="card">
            <div class="card-title">Total Remaining</div>
            <div class="card-value">₱{{ number_format($summary['total_remaining'] ?? 0, 2) }}</div>
        </div>
        <div class="card">
            <div class="card-title">Fund Allocations</div>
            <div class="card-value">{{ count($allocations ?? []) }}</div>
        </div>
    </div>

    <div class="section">
        <div class="section-header"><div class="section-title">Fund Allocation Summary (by Type)</div></div>
        <div class="section-body">
            <table>
                <thead>
                    <tr>
                        <th>Fund Type</th>
                        <th class="text-right">Allocated</th>
                        <th class="text-right">Utilized</th>
                        <th class="text-right">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    @php $types = ['tuition' => 'Tuition Assistance', 'cola' => 'COLA', 'other' => 'Other Assistance']; @endphp
                    @foreach($types as $key => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="text-right">₱{{ number_format($summary['fund_types'][$key]['allocated'] ?? 0, 2) }}</td>
                            <td class="text-right">₱{{ number_format($summary['fund_types'][$key]['utilized'] ?? 0, 2) }}</td>
                            <td class="text-right">₱{{ number_format($summary['fund_types'][$key]['remaining'] ?? 0, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="muted" style="margin-top:6pt;">Sponsors: {{ $sponsor_count }}</div>
        </div>
    </div>

    <div class="section">
        <div class="section-header"><div class="section-title">Fund Management Report (per Sponsor)</div></div>
        <div class="section-body">
            <table>
                <thead>
                    <tr>
                        <th>Fund Type</th>
                        <th>Sponsor</th>
                        <th>Description</th>
                        <th class="text-right">Allocated</th>
                        <th class="text-right">Utilized</th>
                        <th class="text-right">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($allocations as $a)
                        <tr>
                            <td>{{ strtoupper($a->fund_type) }}</td>
                            <td>{{ $a->sponsor_name }}</td>
                            <td>{{ $a->description ?? '—' }}</td>
                            <td class="text-right">₱{{ number_format((float)$a->allocated_amount, 2) }}</td>
                            <td class="text-right">₱{{ number_format((float)$a->utilized_amount, 2) }}</td>
                            <td class="text-right">₱{{ number_format((float)$a->remaining_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="muted">No fund allocations recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="footer">This is a system-generated document from AidPoint.</div>
</body>
</html>