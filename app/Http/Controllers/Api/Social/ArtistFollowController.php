<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtistFollowController extends Controller
{
    public function __call($method, $parameters)
    {
        return response()->json(["success" => true, "data" => null, "message" => "Not implemented yet."]);
    }
}
