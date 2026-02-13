<?php

namespace App\Http\Controllers\Api\Podcast;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use Illuminate\Http\Request;

class AnalyticsApiController extends Controller
{
    public function trackListen(Episode $episode)
    {
        return response()->json(['success' => true]);
    }

    public function trackDownload(Episode $episode)
    {
        return response()->json(['success' => true]);
    }

    public function trackSkip(Episode $episode)
    {
        return response()->json(['success' => true]);
    }
}
