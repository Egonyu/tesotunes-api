<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SaccoIndexController extends Controller
{
    /**
     * GET /api/sacco — Base SACCO route
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'SACCO API',
            'version' => '1.0',
            'endpoints' => [
                'membership' => '/sacco/membership',
                'members' => '/sacco/members',
                'savings' => '/sacco/savings/accounts',
                'loans' => '/sacco/loans',
                'shares' => '/sacco/shares/value',
            ],
        ]);
    }
}
