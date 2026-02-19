<?php

namespace App\Http\Controllers\API\Payment;

use App\Http\Controllers\Controller;

class SongPurchaseController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(['success' => true, 'data' => null, 'message' => 'Not implemented yet.']);
    }
}
