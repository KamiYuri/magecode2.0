<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `{data, meta}` envelope for cursor-paginated listings.
 *
 * Laravel's own paginated resource response emits `{data, links, meta}` with a
 * `path` and no `has_more`, which is not the `CursorMeta` schema in
 * openapi.yml — so the envelope is built here instead, in one place, rather
 * than bent into shape at every call site.
 */
final class CursorPage
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    /** Values outside the documented 1..100 range are clamped, never rejected. */
    public static function perPage(Request $request): int
    {
        $requested = $request->integer('per_page');

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * @template TModel of Model
     *
     * @param  CursorPaginator<int, TModel>  $page
     * @param  class-string<JsonResource>  $resource
     */
    public static function json(CursorPaginator $page, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($page->items()),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }
}
