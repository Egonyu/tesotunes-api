<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoodController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(["success" => true, "data" => null, "message" => "Not implemented yet."]);
    }
}
