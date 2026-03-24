<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BaseResourceCollection extends ResourceCollection
{
    /**
     * @param  array<string, mixed>  $extraMeta
     */
    public function __construct($resource, protected array $extraMeta = [])
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource collection into an array.
     */
    public function toArray(Request $request): array
    {
        return $this->collection->all();
    }

    /**
     * Customize paginator metadata format for API consistency.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        return [
            'meta' => array_merge([
                'current_page' => $paginated['current_page'] ?? 1,
                'last_page' => $paginated['last_page'] ?? 1,
                'per_page' => $paginated['per_page'] ?? 0,
                'total' => $paginated['total'] ?? 0,
            ], $this->extraMeta),
            'links' => [
                'next' => $paginated['next_page_url'] ?? null,
                'prev' => $paginated['prev_page_url'] ?? null,
            ],
        ];
    }
}
