<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the two avatar kinds.
 *
 * The api reads the object and writes the bytes because a pre-signed URL is
 * signed against the internal endpoint and no bucket is browser-reachable
 * (v3 §7, 2026-08-14). Both are authenticated: an avatar is a face attached to
 * a name, and the rest of the directory is behind a token too.
 *
 * A missing object is a 404 rather than a placeholder image — the client
 * already knows how to draw initials, and inventing a body here would make
 * "no avatar" indistinguishable from "avatar we lost".
 */
class AvatarStreamController extends Controller
{
    public function __construct(private readonly AvatarStorageService $avatars) {}

    public function user(Request $request, User $user): Response
    {
        return $this->stream($user->avatar_path);
    }

    public function organization(Request $request, Organization $organization): Response
    {
        return $this->stream($organization->avatar_path);
    }

    private function stream(?string $path): Response
    {
        $bytes = $path === null ? null : $this->avatars->read($path);

        abort_if($bytes === null, Response::HTTP_NOT_FOUND);

        return response($bytes, Response::HTTP_OK, [
            'Content-Type' => $this->avatars->mimeType((string) $path),
            'Content-Length' => (string) strlen($bytes),
            // Private: the bytes are behind a token, so a shared cache must
            // not hold them. The key changes on replacement, so a client may
            // hold its own copy for a while.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
