<?php

namespace App\Services;

use App\Models\Liquidation;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class LiquidationSummaryService
{
    /**
     * Generate a liquidation summary PDF
     */
    public function generateSummaryPdf(Liquidation $liquidation)
    {
        // Load relationships
        $liquidation->load([
            'beneficiary:id,firstname,middlename,lastname,email',
            'receipts' => function ($query) {
                $query->orderBy('receipt_date');
            },
            'caseworkerApprover:id,firstname,lastname',
            'financeApprover:id,firstname,lastname', 
            'directorApprover:id,firstname,lastname'
        ]);

        $data = [
            'liquidation' => $liquidation,
            'participant_name' => $this->getParticipantName($liquidation),
            'formatted_receipts' => $this->formatReceiptsForSummary($liquidation),
            'total_cash_advance' => $liquidation->total_disbursed_amount,
            'total_expenses' => $liquidation->total_receipt_amount,
            'amount_to_return' => max(0, $liquidation->total_disbursed_amount - $liquidation->total_receipt_amount),
            'generated_date' => Carbon::now()->format('F d, Y'),
            'submitted_by' => $this->getApproverName($liquidation->caseworkerApprover, 'CASEWORKER'),
            'checked_by' => $this->getApproverName($liquidation->financeApprover, 'FINANCE'),
            'approved_by' => $this->getApproverName($liquidation->directorApprover, 'DIRECTOR'),
            'submitted_date' => $liquidation->caseworker_approved_at ? $liquidation->caseworker_approved_at->format('F d, Y') : null,
            'checked_date' => $liquidation->finance_approved_at ? $liquidation->finance_approved_at->format('F d, Y') : null,
            'approved_date' => $liquidation->director_approved_at ? $liquidation->director_approved_at->format('F d, Y') : null,
        ];

        $pdf = PDF::loadView('pdf.liquidation-summary', $data);
        $pdf->setPaper('A4', 'portrait');
        
        return $pdf;
    }

    /**
     * Get formatted participant name
     */
    private function getParticipantName(Liquidation $liquidation): string
    {
        $beneficiary = $liquidation->beneficiary;
        if (!$beneficiary) {
            return 'N/A';
        }

        $name = strtoupper(trim(
            $beneficiary->lastname . ', ' . 
            $beneficiary->firstname . 
            ($beneficiary->middlename ? ' ' . $beneficiary->middlename : '')
        ));

        return $name;
    }

    /**
     * Format receipts for summary display
     */
    private function formatReceiptsForSummary(Liquidation $liquidation): array
    {
        $receipts = [];
        
        foreach ($liquidation->receipts as $receipt) {
            $receipts[] = [
                'date' => Carbon::parse($receipt->receipt_date)->format('F j, Y'),
                'particulars' => $this->determineReceiptParticular($receipt),
                'or_invoice_no' => $receipt->receipt_number ?: 'N/A',
                'amount' => $receipt->receipt_amount,
                'formatted_amount' => '(' . number_format($receipt->receipt_amount, 2) . ')',
            ];
        }

        return $receipts;
    }

    /**
     * Determine receipt particular based on description or amount patterns
     */
    private function determineReceiptParticular($receipt): string
    {
        if (!empty($receipt->description)) {
            $description = strtoupper($receipt->description);
            
            // Common patterns from the template
            if (strpos($description, 'TUITION') !== false) {
                return 'TUITION';
            }
            if (strpos($description, 'TRANSPORTATION') !== false || strpos($description, 'TRANSPORT') !== false) {
                return 'TRANSPORTATION';
            }
            if (strpos($description, 'MEAL') !== false || strpos($description, 'FOOD') !== false) {
                return 'MEALS';
            }
            if (strpos($description, 'LOAD') !== false || strpos($description, 'PREPAID') !== false) {
                return 'OTHERS(load)';
            }
            if (strpos($description, 'DISCOUNT') !== false) {
                return 'SCHOOL DISCOUNT';
            }
            
            return strtoupper($receipt->description);
        }

        // Default categorization based on amount ranges (this is just an example)
        $amount = floatval($receipt->receipt_amount);
        if ($amount >= 2000) {
            return 'TUITION';
        } elseif ($amount >= 500) {
            return 'TRANSPORTATION';
        } elseif ($amount >= 100) {
            return 'MEALS';
        } else {
            return 'OTHERS';
        }
    }

    /**
     * Get approver name with fallback
     */
    private function getApproverName($approver, $defaultRole): string
    {
        if ($approver) {
            return strtoupper($approver->firstname . ' ' . $approver->lastname);
        }
        
        return $defaultRole . ' NAME';
    }

    /**
     * Get suggested filename for the PDF
     */
    public function getSuggestedFilename(Liquidation $liquidation): string
    {
        $participantName = str_replace([',', ' '], ['', '_'], $this->getParticipantName($liquidation));
        $date = Carbon::now()->format('Y-m-d');
        
        return "Liquidation_Summary_{$participantName}_{$liquidation->id}_{$date}.pdf";
    }
}