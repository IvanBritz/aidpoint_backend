<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\SystemRole;
use App\Models\FinancialAid;
use App\Models\AidRequest;
use App\Models\Disbursement;
use App\Models\Liquidation;
use App\Models\LiquidationReceipt;
use Illuminate\Support\Facades\Hash;

class LiquidationApprovalDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get system roles
        $beneficiaryRole = SystemRole::where('name', 'beneficiary')->first();
        $caseworkerRole = SystemRole::where('name', 'caseworker')->first();
        $financeRole = SystemRole::where('name', 'finance')->first();
        $directorRole = SystemRole::where('name', 'director')->first();

        if (!$beneficiaryRole || !$caseworkerRole || !$financeRole || !$directorRole) {
            $this->command->error('Required system roles not found. Make sure roles are seeded first.');
            return;
        }

        // Create a demo financial aid facility if none exists
        $facility = FinancialAid::first();
        if (!$facility) {
            $facility = FinancialAid::create([
                'facility_name' => 'Demo Financial Aid Center',
                'facility_address' => '123 Demo Street, Demo City',
                'contact_person' => 'Demo Administrator',
                'contact_number' => '+63 912 345 6789',
                'email_address' => 'demo@financialaid.com',
                'status' => 'approved',
                'user_id' => 1, // Assuming first user is admin
            ]);
        }

        // Create demo users if they don't exist
        $demoUsers = [
            [
                'role' => $caseworkerRole,
                'firstname' => 'Alice',
                'lastname' => 'Johnson',
                'email' => 'caseworker@demo.com',
            ],
            [
                'role' => $financeRole,
                'firstname' => 'Bob',
                'lastname' => 'Smith',
                'email' => 'finance@demo.com',
            ],
            [
                'role' => $directorRole,
                'firstname' => 'Carol',
                'lastname' => 'Williams',
                'email' => 'director@demo.com',
            ],
            [
                'role' => $beneficiaryRole,
                'firstname' => 'David',
                'lastname' => 'Brown',
                'email' => 'beneficiary@demo.com',
            ],
        ];

        $createdUsers = [];
        foreach ($demoUsers as $userData) {
            $existing = User::where('email', $userData['email'])->first();
            if (!$existing) {
                $user = User::create([
                    'firstname' => $userData['firstname'],
                    'lastname' => $userData['lastname'],
                    'email' => $userData['email'],
                    'password' => Hash::make('password123'),
                    'systemrole_id' => $userData['role']->id,
                    'financial_aid_id' => $facility->id,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
                $createdUsers[$userData['role']->name] = $user;
                $this->command->info("Created {$userData['role']->name}: {$user->email}");
            } else {
                $createdUsers[$userData['role']->name] = $existing;
                $this->command->info("Using existing {$userData['role']->name}: {$existing->email}");
            }
        }

        // Assign beneficiary to caseworker
        if (isset($createdUsers['beneficiary']) && isset($createdUsers['caseworker'])) {
            $createdUsers['beneficiary']->update([
                'caseworker_id' => $createdUsers['caseworker']->id
            ]);
            $this->command->info("Assigned beneficiary to caseworker");
        }

        // Create demo aid request and disbursement if beneficiary exists
        if (isset($createdUsers['beneficiary'])) {
            $beneficiary = $createdUsers['beneficiary'];
            
            // Check if aid request already exists for this user
            $aidRequest = AidRequest::where('beneficiary_id', $beneficiary->id)->first();
            if (!$aidRequest) {
                $aidRequest = AidRequest::create([
                    'beneficiary_id' => $beneficiary->id,
                    'fund_type' => 'cola',
                    'amount' => 5000.00,
                    'month' => now()->month,
                    'year' => now()->year,
                    'purpose' => 'Demo COLA request for liquidation approval testing',
                    'status' => 'approved',
                    'stage' => 'done',
                    'reviewed_by' => $createdUsers['caseworker']->id ?? 1,
                    'reviewed_at' => now()->subDays(7),
                    'review_notes' => 'Demo caseworker approval',
                    'finance_decision' => 'approved',
                    'finance_reviewed_by' => $createdUsers['finance']->id ?? 1,
                    'finance_reviewed_at' => now()->subDays(5),
                    'finance_notes' => 'Demo finance approval',
                    'director_decision' => 'approved',
                    'director_reviewed_by' => $createdUsers['director']->id ?? 1,
                    'director_reviewed_at' => now()->subDays(3),
                    'director_notes' => 'Demo director approval',
                ]);
                $this->command->info("Created demo aid request");
            }

            // Create disbursement if it doesn't exist
            $disbursement = Disbursement::where('aid_request_id', $aidRequest->id)->first();
            if (!$disbursement) {
                $disbursement = Disbursement::create([
                    'aid_request_id' => $aidRequest->id,
                    'amount' => $aidRequest->amount,
                    'status' => 'beneficiary_received',
                    'reference_no' => 'DEMO-' . now()->format('Ymd') . '-001',
                    'finance_disbursed_by' => $createdUsers['finance']->id ?? 1,
                    'finance_disbursed_at' => now()->subDays(2),
                    'caseworker_received_by' => $createdUsers['caseworker']->id ?? 1,
                    'caseworker_received_at' => now()->subDays(1),
                    'caseworker_disbursed_by' => $createdUsers['caseworker']->id ?? 1,
                    'caseworker_disbursed_at' => now()->subHours(18),
                    'beneficiary_received_at' => now()->subHours(12),
                    'fully_liquidated' => false,
                    'liquidated_amount' => 0,
                    'remaining_to_liquidate' => $aidRequest->amount,
                ]);
                $this->command->info("Created demo disbursement");
            }

            // Create a demo liquidation ready for approval workflow
            $liquidation = Liquidation::where('disbursement_id', $disbursement->id)
                ->where('status', 'pending_caseworker_approval')
                ->first();
            if (!$liquidation) {
                $liquidation = Liquidation::create([
                    'disbursement_id' => $disbursement->id,
                    'beneficiary_id' => $beneficiary->id,
                    'liquidation_date' => now()->toDateString(),
                    'disbursement_type' => 'cola',
                    'or_invoice_no' => 'DEMO-RECEIPT-001',
                    'total_disbursed_amount' => 1000.00,
                    'total_receipt_amount' => 1000.00,
                    'remaining_amount' => 0,
                    'is_complete' => true,
                    'completed_at' => now()->subHours(2),
                    'description' => 'Demo liquidation for testing multi-level approval workflow',
                    'status' => 'pending_caseworker_approval',
                ]);

                // Create a demo receipt
                LiquidationReceipt::create([
                    'liquidation_id' => $liquidation->id,
                    'receipt_amount' => 1000.00,
                    'receipt_number' => 'RECEIPT-001',
                    'receipt_date' => now()->subDays(1)->toDateString(),
                    'description' => 'Demo receipt for COLA expenses',
                    'verification_status' => 'pending',
                    'original_filename' => 'demo_receipt.pdf',
                    'stored_filename' => 'demo_receipt_' . time() . '.pdf',
                    'file_path' => 'liquidation-receipts/demo/demo_receipt.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 1024000,
                    'uploaded_by' => $beneficiary->id,
                    'uploaded_at' => now()->subHours(2),
                ]);

                $this->command->info("Created demo liquidation ready for caseworker approval");
                
                // Create additional liquidations at different approval stages
                $this->createDemoLiquidation($disbursement, $beneficiary, $createdUsers, 'pending_finance_approval', 'Approved by caseworker, pending finance review');
                $this->createDemoLiquidation($disbursement, $beneficiary, $createdUsers, 'pending_director_approval', 'Approved by finance, pending director review');
                $this->createDemoLiquidation($disbursement, $beneficiary, $createdUsers, 'approved', 'Fully approved by all levels');
                $this->createDemoLiquidation($disbursement, $beneficiary, $createdUsers, 'rejected', 'Rejected by caseworker for missing documentation');
            }
        }

        $this->command->info('Demo liquidation approval workflow data created successfully!');
        $this->command->info('');
        $this->command->info('Demo Accounts Created:');
        $this->command->info('- Caseworker: caseworker@demo.com (password: password123)');
        $this->command->info('- Finance: finance@demo.com (password: password123)');
        $this->command->info('- Director: director@demo.com (password: password123)');
        $this->command->info('- Beneficiary: beneficiary@demo.com (password: password123)');
        $this->command->info('');
        $this->command->info('You can now test the multi-level approval workflow!');
    }

    private function createDemoLiquidation($disbursement, $beneficiary, $createdUsers, $status, $description)
    {
        $liquidation = Liquidation::create([
            'disbursement_id' => $disbursement->id,
            'beneficiary_id' => $beneficiary->id,
            'liquidation_date' => now()->subDays(rand(1, 10))->toDateString(),
            'disbursement_type' => 'cola',
            'or_invoice_no' => 'DEMO-' . strtoupper(str_replace('pending_', '', $status)) . '-' . rand(100, 999),
            'total_disbursed_amount' => 1000.00,
            'total_receipt_amount' => 1000.00,
            'remaining_amount' => 0,
            'is_complete' => true,
            'completed_at' => now()->subHours(rand(12, 72)),
            'description' => $description,
            'status' => $status,
        ]);

        // Create demo receipt
        LiquidationReceipt::create([
            'liquidation_id' => $liquidation->id,
            'receipt_amount' => 1000.00,
            'receipt_number' => 'RECEIPT-' . $liquidation->id,
            'receipt_date' => now()->subDays(rand(1, 5))->toDateString(),
            'description' => 'Demo receipt for ' . $status . ' liquidation',
            'verification_status' => 'pending',
            'original_filename' => 'demo_receipt_' . $liquidation->id . '.pdf',
            'stored_filename' => 'demo_receipt_' . $liquidation->id . '_' . time() . '.pdf',
            'file_path' => 'liquidation-receipts/demo/demo_receipt_' . $liquidation->id . '.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024000,
            'uploaded_by' => $beneficiary->id,
            'uploaded_at' => now()->subHours(rand(12, 72)),
        ]);

        // Set approval fields based on status
        switch ($status) {
            case 'pending_finance_approval':
                $liquidation->update([
                    'caseworker_approved_by' => $createdUsers['caseworker']->id ?? null,
                    'caseworker_notes' => 'Approved by caseworker',
                    'caseworker_approved_at' => now()->subHours(rand(12, 48)),
                ]);
                break;
            case 'pending_director_approval':
                $liquidation->update([
                    'caseworker_approved_by' => $createdUsers['caseworker']->id ?? null,
                    'caseworker_notes' => 'Approved by caseworker',
                    'caseworker_approved_at' => now()->subHours(rand(48, 96)),
                    'finance_approved_by' => $createdUsers['finance']->id ?? null,
                    'finance_notes' => 'Approved by finance',
                    'finance_approved_at' => now()->subHours(rand(12, 48)),
                ]);
                break;
            case 'approved':
                $liquidation->update([
                    'caseworker_approved_by' => $createdUsers['caseworker']->id ?? null,
                    'caseworker_notes' => 'Approved by caseworker',
                    'caseworker_approved_at' => now()->subHours(rand(72, 120)),
                    'finance_approved_by' => $createdUsers['finance']->id ?? null,
                    'finance_notes' => 'Approved by finance',
                    'finance_approved_at' => now()->subHours(rand(48, 72)),
                    'director_approved_by' => $createdUsers['director']->id ?? null,
                    'director_notes' => 'Approved by director',
                    'director_approved_at' => now()->subHours(rand(12, 48)),
                ]);
                break;
            case 'rejected':
                $liquidation->update([
                    'caseworker_approved_by' => $createdUsers['caseworker']->id ?? null,
                    'caseworker_notes' => 'Rejected due to missing documentation',
                    'caseworker_approved_at' => now()->subHours(rand(12, 48)),
                    'rejected_at_level' => 'caseworker',
                    'rejection_reason' => 'Missing required receipts and documentation',
                ]);
                break;
        }
    }
}
