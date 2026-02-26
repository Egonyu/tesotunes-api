<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Standardised pagination helper for API controllers.
 *
 * Usage: $perPage = $this->getPerPage($request);
 *        $query->paginate($perPage);
 */
trait HasPagination
{
    /**
     * Get a safe, capped per_page value from the request.
     *
     * @param  int  $default  Items per page when not specified (default: 20)
     * @param  int  $max  Maximum allowed per page (default: 100)
     */
    protected function getPerPage(Request $request, int $default = 20, int $max = 100): int
    {
        return min(max($request->integer('per_page', $default), 1), $max);
    }
}
