<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates the shape of an initiate request. Authentication is deliberately absent:
 * it is the host application's concern, declared through
 * `stateful-chunking-upload.routes.middleware` (for example `['api', 'auth:sanctum']`), which
 * gates every endpoint uniformly with the app's own guard.
 */
final class InitiateChunkRequest extends FormRequest
{
    /**
     * The server-configured chunk size in bytes, sanitised. Single source of truth for
     * the `total_chunks` bounds (see {@see rules()}) and the discovery hint echoed on a
     * validation failure (see {@see failedValidation()}).
     */
    private function configuredChunkSizeBytes(): int
    {
        $raw = config('stateful-chunking-upload.chunk_size_bytes', 2097152);

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 2097152;
    }

    /**
     * Echo `chunk_size_bytes` alongside the standard validation errors.
     *
     * `total_chunks` is bounded against the server's chunk size, so a client that guessed
     * a different size (its 2 MiB default vs. a 12 MiB server, say) is rejected here before
     * it ever sees a successful response carrying the real value. Surfacing the size on the
     * failure lets the client learn it, rebuild its plan and retry initiate once — the
     * zero-config path that stops the consumer hardcoding the chunk size on the front end.
     * The `message`/`errors` shape is preserved so existing clients (and
     * `assertJsonValidationErrors`) keep working.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->messages(),
            'chunk_size_bytes' => $this->configuredChunkSizeBytes(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rawMaxFileSize = config('stateful-chunking-upload.max_file_size_bytes', 10737418240);
        $maxFileSize = is_numeric($rawMaxFileSize) ? (int) $rawMaxFileSize : 10737418240;

        $rawMaxChunks = config('stateful-chunking-upload.max_total_chunks', 10000);
        $maxChunks = is_numeric($rawMaxChunks) ? (int) $rawMaxChunks : 10000;

        $rawForbiddenExts = config('stateful-chunking-upload.forbidden_extensions', [
            'php', 'phar', 'phtml', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'inc', 'hphp', 'ctp',
            'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb', 'vbs', 'vbe', 'ps1',
            'asp', 'aspx', 'cer', 'asa', 'asax', 'cfm', 'cfc', 'jsp', 'jspx', 'shtml', 'shtm',
            'htaccess', 'htpasswd', 'user.ini',
        ]);
        $forbiddenExts = is_array($rawForbiddenExts)
            ? array_map(fn (mixed $ext): string => strtolower(trim(is_string($ext) ? $ext : '')), $rawForbiddenExts)
            : ['php', 'phar', 'phtml', 'sh', 'exe', 'bat', 'cgi', 'pl'];

        $rawAllowedExts = config('stateful-chunking-upload.allowed_extensions');
        $allowedExts = is_array($rawAllowedExts) && count($rawAllowedExts) > 0
            ? array_map(fn (mixed $ext): string => strtolower(trim(is_string($ext) ? $ext : '')), $rawAllowedExts)
            : null;

        return [
            'file_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._-]+$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($forbiddenExts, $allowedExts): void {
                    $strValue = is_string($value) ? $value : '';

                    // 1. Block dot-files (e.g., .htaccess, .env)
                    if (str_starts_with($strValue, '.')) {
                        $fail('Filenames starting with a dot are forbidden.');

                        return;
                    }

                    // 2. Block trailing dots or spaces (Windows normalization bypass)
                    if (str_ends_with($strValue, '.') || str_ends_with($strValue, ' ')) {
                        $fail('Filenames with trailing dots or spaces are forbidden.');

                        return;
                    }

                    $segments = explode('.', $strValue);

                    // 3. Must contain at least one dot separating name and extension
                    if (count($segments) < 2 || end($segments) === '') {
                        $fail('The filename must contain a valid extension.');

                        return;
                    }

                    $finalExtension = strtolower((string) end($segments));

                    // 4. Enforce whitelist if configured
                    if ($allowedExts !== null) {
                        if (! in_array($finalExtension, $allowedExts, true)) {
                            $fail("The file extension .{$finalExtension} is not allowed.");

                            return;
                        }
                    }

                    // 5. Multi-segment inspection (double extension prevention)
                    // Check every segment after the root stem against forbidden extensions
                    foreach (array_slice($segments, 1) as $segment) {
                        $cleanSegment = strtolower(trim($segment));
                        if (in_array($cleanSegment, $forbiddenExts, true)) {
                            $fail("The file extension or component .{$cleanSegment} is forbidden for uploads.");

                            return;
                        }
                    }
                },
            ],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.$maxFileSize],
            'total_chunks' => (function () use ($maxChunks): array {
                // Derive the chunk count the declared file_size actually implies, using the
                // server-configured chunk size. Both the lower AND upper bound are pinned to
                // this value: the lower bound guarantees enough chunks to hold the file, and
                // the upper bound stops a client from claiming far more chunks than the file
                // needs. Without the upper bound, a 1-byte file could declare max_total_chunks
                // and stage gigabytes of oversized chunks on disk (storage-amplification DoS),
                // since max_file_size_bytes only caps the *declared* size, never the bytes
                // actually written. The +1 absorbs off-by-one rounding on the final chunk.
                $chunkSizeBytes = $this->configuredChunkSizeBytes();

                $fileSizeInput = $this->input('file_size');
                $expectedChunks = is_numeric($fileSizeInput) && (int) $fileSizeInput > 0
                    ? max(1, (int) ceil((int) $fileSizeInput / $chunkSizeBytes))
                    : 1;

                $upperBound = min($maxChunks, $expectedChunks + 1);

                return [
                    'required',
                    'integer',
                    'min:'.$expectedChunks,
                    'max:'.$upperBound,
                ];
            })(),
            'total_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
        ];
    }
}
