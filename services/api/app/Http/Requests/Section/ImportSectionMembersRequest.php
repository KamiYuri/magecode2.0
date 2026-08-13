<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Services\RosterFileReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Throwable;

class ImportSectionMembersRequest extends FormRequest
{
    /** A section roster; a file larger than this is not one. */
    private const MAX_KILOBYTES = 2048;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'extensions:'.implode(',', RosterFileReader::SUPPORTED_EXTENSIONS),
            ],
        ];
    }

    /**
     * A file with no email column is not a roster with bad rows — it is the
     * wrong file, and reporting every row as an error would bury that.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($validator->errors()->isNotEmpty() || ! $file instanceof UploadedFile) {
                    return;
                }

                try {
                    $headers = app(RosterFileReader::class)->headers($file);
                } catch (Throwable) {
                    $validator->errors()->add('file', __('The file could not be read.'));

                    return;
                }

                if (! in_array('email', $headers, true)) {
                    $validator->errors()->add('file', __('The file must have an `email` column.'));
                }
            },
        ];
    }
}
