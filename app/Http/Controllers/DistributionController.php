<?php

namespace App\Http\Controllers;

class DistributionController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(['success' => true, 'data' => null, 'message' => 'Not implemented yet.']);
    }
}
