<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Services\AvatarStorageService;
use Illuminate\Foundation\Http\FormRequest;

/** The multipart body of both avatar uploads. */
class UploadAvatarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimetypes:'.implode(',', AvatarStorageService::ALLOWED_MIME_TYPES),
                // Laravel counts kilobytes here; the service owns the byte value.
                'max:'.(AvatarStorageService::MAX_FILE_BYTES / 1024),
            ],
        ];
    }
}
