<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Http\Responses\CursorPage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B12: the bell icon. Scoped to the caller throughout — a notification names
 * its notifiable, and nobody reads anyone else's.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $query = $user->notifications()->getQuery();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $page = $query->cursorPaginate(CursorPage::perPage($request));

        $response = CursorPage::json($page, NotificationResource::class);

        // `unread_count` is the badge, and it counts the whole inbox rather
        // than the page: a bell showing "3" because that is what fitted on
        // screen would be worse than no bell.
        /** @var array<string, mixed> $body */
        $body = $response->getData(true);
        $body['unread_count'] = $user->unreadNotifications()->count();

        return response()->json($body);
    }

    public function markRead(Request $request): JsonResponse
    {
        $user = $this->user($request);

        /** @var list<string> $ids */
        $ids = $request->input('ids', []);

        $query = $user->unreadNotifications();

        // "Empty = mark all", per openapi. The distinction matters: an
        // explicit empty list is the "mark everything read" button, and
        // treating it as "mark nothing" would leave that button doing nothing.
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $marked = $query->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Marked.',
            'marked' => $marked,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
