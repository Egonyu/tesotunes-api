<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ISRCController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(["success" => true, "data" => null, "message" => "Not implemented yet."]);
    }
}
