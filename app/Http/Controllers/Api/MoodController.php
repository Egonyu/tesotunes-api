<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class MoodController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(['success' => true, 'data' => null, 'message' => 'Not implemented yet.']);
    }
}
