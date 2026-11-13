<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Uploaded files are untrusted: the MIME type sniffed from the bytes must be one we accept for the
 * file's extension, so an executable or script renamed to .pdf/.txt is rejected.
 */
class ContentMatchesExtension implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The upload failed.');

            return;
        }

        $ext = strtolower($value->getClientOriginalExtension());
        $allowed = config('audira.documents.mime_map')[$ext] ?? [];
        $sniffed = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath()));

        if (! in_array($sniffed, $allowed, true)) {
            $fail("The file content ({$sniffed}) does not match a .{$ext} file.");
        }
    }
}
