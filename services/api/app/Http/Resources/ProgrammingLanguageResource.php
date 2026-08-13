<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProgrammingLanguage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `ProgrammingLanguage` schema in openapi.yml — the client-facing subset.
 * The Judge0, Dolos and CodeQL identifiers stay server-side.
 *
 * @mixin ProgrammingLanguage
 */
class ProgrammingLanguageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'monaco_language' => $this->monaco_language,
            'file_extensions' => $this->file_extensions,
        ];
    }
}
