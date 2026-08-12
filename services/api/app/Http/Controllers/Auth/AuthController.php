<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstTimeSetupRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const TOKEN_NAME = 'api';

    /**
     * Issues a bearer token. The `login` field accepts a username or an email
     * because students remember one and staff the other.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::where('username', $credentials['login'])
            ->orWhere('email', $credentials['login'])
            ->first();

        // One response for "no such account" and "wrong password" so the
        // endpoint cannot be used to enumerate usernames.
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => __('auth.failed')], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'data' => [
                'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Creates an unverified account. No token is issued: the address has to be
     * confirmed first, which is the whole point of the verification mail.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        event(new Registered($user));

        return response()->json(['data' => new UserResource($user)], JsonResponse::HTTP_CREATED);
    }

    /** Revokes the token that authenticated this request, leaving other sessions alone. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['data' => ['message' => __('Logged out.')]]);
    }

    /**
     * Lets a roster-imported student replace the generated password. Completing
     * this proves they received the mail carrying it, so the address counts as
     * verified.
     */
    public function firstTimeSetup(FirstTimeSetupRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->is_first_time_register) {
            return response()->json(
                ['message' => __('This account has already completed first-time setup.')],
                JsonResponse::HTTP_FORBIDDEN
            );
        }

        $user->fill($request->safe()->only(['first_name', 'last_name']));
        $user->password = $request->validated('password');
        $user->is_first_time_register = false;
        $user->email_verified_at ??= now();
        $user->save();

        return response()->json(['data' => new UserResource($user)]);
    }
}
