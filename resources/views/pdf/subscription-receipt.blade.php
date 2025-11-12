<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $app_name }} — Subscription Receipt</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif; 
            color: #1a1a1a; 
            font-size: 10px; 
            line-height: 1.4;
            padding: 0;
            background: #ffffff;
        }
        .page-wrapper {
            padding: 30pt 40pt;
        }
        .top-header {
            text-align: center;
            margin-bottom: 20pt;
            padding-bottom: 15pt;
            border-bottom: 3px solid #667eea;
        }
        .website-name {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 6px;
            letter-spacing: 1px;
        }
        .doc-title { 
            font-size: 14px; 
            font-weight: 600; 
            color: #374151;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .meta-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12pt 16pt;
            border-radius: 8px;
            margin-bottom: 18pt;
            display: table;
            width: 100%;
        }
        .meta-col {
            display: table-cell;
            width: 50%;
            vertical-align: middle;
        }
        .meta-label {
            font-size: 8px;
            color: rgba(255,255,255,0.85);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .meta-value {
            font-size: 11px;
            color: #ffffff;
            font-weight: 700;
        }
        .content-grid {
            display: table;
            width: 100%;
            margin-bottom: 15pt;
        }
        .content-col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 10pt;
        }
        .content-col:last-child {
            padding-right: 0;
            padding-left: 10pt;
        }
        .section {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 15pt;
            page-break-inside: avoid;
        }
        .section-header { 
            background: #f3f4f6;
            padding: 8pt 12pt;
            border-bottom: 2px solid #d1d5db;
        }
        .section-title { 
            font-size: 10px; 
            font-weight: 700; 
            color: #1f2937; 
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .section-body {
            padding: 12pt;
        }
        .info-row {
            margin-bottom: 10px;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-size: 8px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }
        .info-value {
            font-size: 10px;
            color: #1f2937;
            font-weight: 600;
        }
        .badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        .highlight-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
            padding: 12pt;
            border-radius: 6px;
            margin-bottom: 12px;
            text-align: center;
        }
        .plan-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .price-highlight {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            margin-top: 4px;
        }
        .footer { 
            text-align: center; 
            margin-top: 20pt; 
            padding-top: 15pt;
            border-top: 2px solid #e5e7eb;
        }
        .footer-text {
            font-size: 9px; 
            color: #6b7280;
            margin-bottom: 4px;
        }
        .footer-url {
            font-size: 10px;
            color: #667eea;
            font-weight: 700;
        }
        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 8pt 0;
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="top-header">
            <div class="website-name">AIDPOINT</div>
            <div class="doc-title">Subscription Receipt</div>
        </div>

        <div class="meta-bar">
            <div class="meta-col">
                <div class="meta-label">Reference No</div>
                <div class="meta-value">{{ $formatted_ref_no ?? $receipt_no }}</div>
            </div>
            <div class="meta-col" style="text-align: right;">
                <div class="meta-label">Transaction Date</div>
                <div class="meta-value">{{ $transaction_date->format('F j, Y - H:i') }}</div>
            </div>
        </div>

        <div class="highlight-box">
            <div class="info-label">Subscription Plan</div>
            <div class="plan-name">{{ $plan->plan_name ?? 'N/A' }}</div>
            <div class="info-label">Amount Paid</div>
            <div class="price-highlight">₱{{ number_format($plan->price ?? 0, 2) }}</div>
        </div>

        <div class="content-grid">
            <div class="content-col">
                <div class="section">
                    <div class="section-header">
                        <div class="section-title">Subscriber Information</div>
                    </div>
                    <div class="section-body">
                        <div class="info-row">
                            <div class="info-label">Full Name</div>
                            <div class="info-value">{{ trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) }}</div>
                        </div>
                        <div class="divider"></div>
                        <div class="info-row">
                            <div class="info-label">Email Address</div>
                            <div class="info-value">{{ $user->email ?? 'N/A' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-col">
                <div class="section">
                    <div class="section-header">
                        <div class="section-title">Subscription Details</div>
                    </div>
                    <div class="section-body">
                        <div class="info-row">
                            <div class="info-label">Duration</div>
                            <div class="info-value">{{ (int)($plan->duration_in_months ?? 0) }} month(s)</div>
                        </div>
                        <div class="divider"></div>
                        <div class="info-row">
                            <div class="info-label">Reference No</div>
                            <div class="info-value">{{ $formatted_ref_no ?? $receipt_no }}</div>
                        </div>
                        <div class="divider"></div>
                        <div class="info-row">
                            <div class="info-label">Payment Method</div>
                            <div class="info-value">{{ $transaction->payment_method ?? 'N/A' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title">Subscription Period</div>
            </div>
            <div class="section-body">
                <div class="info-value" style="font-size: 11px; text-align: center; font-weight: 700; color: #667eea;">
                    {{ \Carbon\Carbon::parse($subscription->start_date ?? now())->format('F j, Y') }} - {{ \Carbon\Carbon::parse($subscription->end_date ?? now())->format('F j, Y') }}
                </div>
            </div>
        </div>

        <div class="footer">
            <div class="footer-text">Thank you for your subscription to {{ $app_name }}</div>
            <div class="footer-url">AIDPOINT</div>
        </div>
    </div>
</body>
</html>
