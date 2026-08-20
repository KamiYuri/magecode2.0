<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProgrammingLanguageResource;
use App\Models\ProgrammingLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /programming-languages` — the reference list behind F6's language
 * selector and the Monaco mode the editor opens with.
 *
 * Unauthenticated, as openapi declares (`security: []`): there is nothing of
 * anyone's in it, and the register and first-time-setup screens are drawn
 * before a token exists. The Judge0, Dolos and CodeQL identifiers stay
 * server-side -- `ProgrammingLanguageResource` is the subset that leaves.
 */
class ProgrammingLanguageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $languages = ProgrammingLanguage::query()->orderBy('name')->get();

        return response()->json([
            'data' => ProgrammingLanguageResource::collection($languages)->resolve($request),
        ]);
    }
}
