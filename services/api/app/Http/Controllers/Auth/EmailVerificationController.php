<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * The link arrives by mail and is clicked in a browser that holds no
     * token, so this route is unauthenticated by design (openapi declares
     * `security: []`). Two things stand in for authentication: the `signed`
     * middleware rejects tampered or expired URLs, and the hash below ties
     * the link to the address it was sent to, so a valid signature for one
     * account cannot verify another.
     */
    public function __invoke(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return response()->json(
                ['message' => __('Invalid verification link.')],
                JsonResponse::HTTP_FORBIDDEN
            );
        }

        // Users click the link more than once; the second click is a no-op
        // rather than an error.
        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['message' => __('Email already verified.')]]);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['data' => ['message' => __('Email verified.')]]);
    }
}
