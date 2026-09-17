<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\ChunkingException;

/**
 * Immutable success envelope for the chunking HTTP adapter.
 *
 * Response shaping is a presentation concern, not a controller responsibility, so
 * it lives here: the controller names the outcome ({@see sessionInitiated()},
 * {@see fileReassembled()}, ...) and returns the envelope; Laravel renders it via
 * {@see toResponse()}. This mirrors how the domain's {@see ChunkingException}
 * hierarchy self-renders failures, so both halves of every response — success and
 * error — are built outside the controller.
 *
 * Security: the public projection is an explicit allowlist. It never carries the
 * session's `owner_id` (a caller identifier such as `user:42` / `ip:1.2.3.4`) nor
 * the assembled file's server path, unless `expose_server_paths` is enabled. This
 * keeps the PII / path-disclosure policy in one place rather than scattered across
 * the controller's action methods.
 *
 * ADR: Adopt an immutable response envelope for the Chunking HTTP API.
 * See: docs/decisions/0002-adopt-immutable-response-envelope.md
 */
final class ChunkingResponse implements Responsable
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    private function __construct(
        private readonly ?string $message,
        private readonly ?array $data,
        private readonly int $status,
    ) {}

    public static function sessionInitiated(ChunkSession $session): self
    {
        return new self('Session initiated successfully', self::publicSessionData($session), 201);
    }

    public static function chunkUploaded(ChunkSession $session, int $chunkIndex): self
    {
        return new self(
            sprintf('Chunk %d uploaded successfully', $chunkIndex),
            self::publicSessionData($session),
            200
        );
    }

    public static function sessionStatus(ChunkSession $session): self
    {
        // Status has historically returned only `data`, with no `message` key.
        return new self(null, self::publicSessionData($session), 200);
    }

    /**
     * @param  array<string, mixed>  $result  Reassembly result from ReassembleFileAction.
     */
    public static function fileReassembled(array $result): self
    {
        $data = [
            'session_id' => self::asString($result, 'session_id'),
            'upload_token' => self::asString($result, 'upload_token'),
            'file_name' => self::asString($result, 'file_name'),
            'file_size' => self::asInt($result, 'file_size'),
            'computed_hash' => self::asString($result, 'computed_hash'),
            'verified' => self::asBool($result, 'verified'),
        ];

        // Server paths are withheld by default; the consumer works with the opaque
        // upload_token, never with a real filesystem path (IDOR / path-disclosure).
        if (config('stateful-chunking-upload.expose_server_paths', false)) {
            $data['path'] = self::asString($result, 'path');
            $data['relative_path'] = self::asString($result, 'relative_path');
        }

        return new self('File reassembled successfully', $data, 200);
    }

    public static function sessionCancelled(): self
    {
        return new self('Session cancelled and resources purged', null, 200);
    }

    /**
     * A message-only response for request-shape guards the controller enforces
     * before the domain runs (empty or oversized payloads).
     */
    public static function inputError(string $message, int $status): self
    {
        return new self($message, null, $status);
    }

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        $payload = [];

        if ($this->message !== null) {
            $payload['message'] = $this->message;
        }

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        return new JsonResponse($payload, $this->status);
    }

    /**
     * Client-safe projection of a session. Explicit allowlist: `owner_id` is
     * deliberately absent so a caller identifier is never echoed back.
     *
     * @return array<string, mixed>
     */
    private static function publicSessionData(ChunkSession $session): array
    {
        return [
            'session_id' => $session->sessionId->value,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'uploaded_bytes' => $session->uploadedBytes,
            'total_chunks' => $session->totalChunks,
            // Published so clients self-configure their slicing to the server's chunk
            // size instead of relying on an out-of-band convention. It is the value the
            // session was initiated with, which is what InitiateChunkRequest bounds
            // total_chunks against. Not sensitive: a public granularity constant.
            'chunk_size_bytes' => $session->chunkSizeBytes,
            'total_hash' => $session->totalHash->value,
            'fingerprint' => $session->fingerprint,
            'status' => $session->status->value,
            'chunks_map' => $session->chunksMap,
            'pending_chunks' => $session->getPendingChunkIndices(),
            'created_at' => $session->createdAt,
            'expires_at' => $session->expiresAt,
            'is_expired' => $session->isExpired(),
            'remaining_ttl' => $session->remainingTtl(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function asString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function asInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function asBool(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }
}
