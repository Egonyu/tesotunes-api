<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SaccoServiceTopologyTest extends TestCase
{
    #[Test]
    public function legacy_service_entrypoints_resolve_to_the_canonical_sacco_service_layer(): void
    {
        $this->assertTrue(is_subclass_of(
            \App\Services\SaccoMembershipService::class,
            \App\Services\Sacco\SaccoMembershipService::class
        ));

        $this->assertTrue(is_subclass_of(
            \App\Services\SaccoLoanService::class,
            \App\Services\Sacco\SaccoLoanService::class
        ));

        $this->assertTrue(is_subclass_of(
            \App\Modules\Sacco\Services\SaccoAccountService::class,
            \App\Services\Sacco\SaccoAccountService::class
        ));
    }

    #[Test]
    public function legacy_member_sacco_controller_has_been_retired(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/SaccoApiController.php'));
    }
}
