<?php

namespace App\Http\Controllers;

class DistributionWebhookController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(['success' => true, 'data' => null, 'message' => 'Not implemented yet.']);
    }
}
