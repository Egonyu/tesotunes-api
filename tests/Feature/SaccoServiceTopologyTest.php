<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SaccoServiceTopologyTest extends TestCase
{
    #[Test]
    public function legacy_member_sacco_controller_has_been_retired(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/SaccoApiController.php'));
    }

    #[Test]
    public function canonical_sacco_services_exist(): void
    {
        $this->assertTrue(class_exists(\App\Services\Sacco\SaccoLoanService::class));
        $this->assertTrue(class_exists(\App\Services\Sacco\SaccoMembershipService::class));
        $this->assertTrue(class_exists(\App\Services\Sacco\SaccoAccountService::class));
        $this->assertTrue(class_exists(\App\Services\Sacco\SaccoCreditScoreService::class));
        $this->assertTrue(class_exists(\App\Services\Sacco\SaccoInterestService::class));
    }
}
