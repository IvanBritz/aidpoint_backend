<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisbursementRoutesAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guest_cannot_access_disbursement_endpoints(): void
    {
        $this->getJson('/api/disbursements/finance/ready')->assertStatus(401);
        $this->postJson('/api/aid-requests/1/disburse', [])->assertStatus(401);
        $this->postJson('/api/disbursements/1/caseworker-receive', [])->assertStatus(401);
        $this->postJson('/api/disbursements/1/caseworker-disburse', [])->assertStatus(401);
        $this->postJson('/api/disbursements/1/beneficiary-receive', [])->assertStatus(401);
        $this->getJson('/api/my-disbursements')->assertStatus(401);
    }
}