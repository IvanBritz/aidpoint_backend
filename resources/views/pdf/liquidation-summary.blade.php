<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liquidation Summary</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 20px;
            line-height: 1.2;
        }
        
        .header {
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 20px;
            text-decoration: underline;
        }
        
        .participant-info {
            margin-bottom: 15px;
        }
        
        .participant-name {
            display: inline-block;
            min-width: 300px;
            border-bottom: 1px solid #000;
            text-align: center;
            font-weight: bold;
        }
        
        .advance-info {
            margin-bottom: 20px;
        }
        
        .advance-amount {
            display: inline-block;
            min-width: 150px;
            border-bottom: 1px solid #000;
            text-align: center;
        }
        
        .expenses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: fixed; /* enforce consistent column widths */
        }
        
        .expenses-table th,
        .expenses-table td {
            border: 1px solid #000;
            padding: 4px;
            text-align: left;
            vertical-align: top;
            word-break: break-word; /* avoid overflow in PDF renderer */
        }
        
        .expenses-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        
        /* Column widths must sum to 100% for perfect alignment */
        .date-col { width: 14%; white-space: nowrap; }
        .particulars-col { width: 46%; }
        .invoice-col { width: 26%; }
        .amount-col { width: 14%; text-align: right; white-space: nowrap; }
        
        .total-row {
            font-weight: bold;
        }
        
        .amount-to-return {
            margin: 20px 0;
            font-weight: bold;
        }
        
        .signatures {
            margin-top: 40px;
        }
        
        .signature-row {
            margin-bottom: 20px;
        }
        
        .signature-line {
            display: inline-block;
            min-width: 200px;
            border-bottom: 1px solid #000;
            text-align: center;
            margin-right: 50px;
        }
        
        .signature-date {
            display: inline-block;
            min-width: 120px;
            border-bottom: 1px solid #000;
            text-align: center;
        }
        
        .amount-negative {
            color: #000;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .underline {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="header">
        LIQUIDATION SUMMARY
    </div>
    
    <div class="participant-info">
        <strong>Name of Participant:</strong> 
        <span class="participant-name">{{ $participant_name }}</span>
    </div>
    
    <div class="advance-info">
        <strong>TOTAL CASH ADVANCE ref CASH VOUCHER NO.</strong> 
        <span class="advance-amount">{{ number_format($total_cash_advance, 2) }}</span>
        <br><br>
        <strong>LESS: EXPENSES</strong>
    </div>
    
    <table class="expenses-table">
        <thead>
            <tr>
                <th class="date-col">DATE</th>
                <th class="particulars-col">PARTICULARS</th>
                <th class="invoice-col">OR No./Invoice No.</th>
                <th class="amount-col">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($formatted_receipts as $receipt)
            <tr>
                <td class="date-col">{{ $receipt['date'] }}</td>
                <td class="particulars-col">{{ $receipt['particulars'] }}</td>
                <td class="invoice-col">{{ $receipt['or_invoice_no'] }}</td>
                <td class="amount-col amount-negative">{{ $receipt['formatted_amount'] }}</td>
            </tr>
            @endforeach
            
            @if(count($formatted_receipts) < 10)
                @for($i = count($formatted_receipts); $i < 10; $i++)
                <tr>
                    <td class="date-col">&nbsp;</td>
                    <td class="particulars-col">&nbsp;</td>
                    <td class="invoice-col">&nbsp;</td>
                    <td class="amount-col">&nbsp;</td>
                </tr>
                @endfor
            @endif
            
            <tr class="total-row">
                <td colspan="3" class="text-center">TOTAL EXPENSES</td>
                <td class="amount-col amount-negative">({{ number_format($total_expenses, 2) }})</td>
            </tr>
        </tbody>
    </table>
    
    <div class="amount-to-return">
        <strong>AMOUNT TO BE RETURNED/REIMBURSED:</strong>
        @if($amount_to_return > 0)
            <span class="underline">{{ number_format($amount_to_return, 2) }}</span>
        @else
            <span class="underline">0.00</span>
        @endif
    </div>
    
    <div class="signatures">
        <div class="signature-row">
            <strong>Submitted by:</strong> 
            <span class="signature-line">{{ $submitted_by }}</span>
            <strong>Date:</strong> 
            <span class="signature-date">{{ $submitted_date ?: $generated_date }}</span>
        </div>
        
        <div class="signature-row">
            <strong>Checked by:</strong> 
            <span class="signature-line">{{ $checked_by }}</span>
            <strong>Date:</strong> 
            <span class="signature-date">{{ $checked_date ?: $generated_date }}</span>
        </div>
        
        <div class="signature-row">
            <strong>Approved by:</strong> 
            <span class="signature-line">{{ $approved_by }}</span>
            <strong>Date:</strong> 
            <span class="signature-date">{{ $approved_date ?: $generated_date }}</span>
        </div>
    </div>
</body>
</html>