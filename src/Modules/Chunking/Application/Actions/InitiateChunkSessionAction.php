<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events\ChunkSessionInitiated;

final class InitiateChunkSessionAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository
    ) {}

    public function handle(InitiateSessionDTO $dto): ChunkSession
    {
        if (! empty($dto->fingerprint)) {
            $existing = $this->repository->findSessionByFingerprint($dto->fingerprint);

            if ($existing !== null && $this->isResumable($existing, $dto)) {
                return $existing;
            }
        }

        $rawTtl = config('stateful-chunking-upload.session_ttl', 21600);
        $ttl = is_numeric($rawTtl) ? (int) $rawTtl : 21600;

        // Capture the effective chunk size at initiate. This is the same value
        // InitiateChunkRequest bounds total_chunks against, so recording it here keeps
        // the session's stored size and the accepted total_chunks derived from one
        // reading of the config.
        $rawChunkSize = config('stateful-chunking-upload.chunk_size_bytes', 2097152);
        $chunkSizeBytes = is_numeric($rawChunkSize) && (int) $rawChunkSize > 0 ? (int) $rawChunkSize : 2097152;

        $now = time();

        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: $dto->fileName,
            fileSize: $dto->fileSize,
            totalChunks: $dto->totalChunks,
            totalHash: $dto->totalHash,
            fingerprint: $dto->fingerprint,
            createdAt: $now,
            expiresAt: $now + $ttl,
            ownerId: $dto->ownerId,
            chunkSizeBytes: $chunkSizeBytes
        );

        $this->repository->saveSession($session);

        ChunkSessionInitiated::dispatch($session);

        return $session;
    }

    /**
     * Whether an existing session is a resumption of *this* request, rather than merely
     * something filed under the same fingerprint.
     *
     * Resume used to require only that the fingerprint matched and the caller owned the
     * session, which made the fingerprint — a value the client picks freely — enough to
     * be handed a session describing a different file. A client that derives one
     * fingerprint per user or per batch instead of per file, the ordinary mistake in a
     * multi-file uploader, got 201 "Session initiated successfully" carrying the
     * *previous* file's name, size and hash. Its chunks then overwrote the first file's
     * by index, and /complete failed integrity verification for both. Two files lost,
     * with the error attributed to the wrong one (AF-005).
     *
     * So every part of the declaration has to agree, and the session has to still be
     * accepting chunks. A fingerprint that now identifies a different file is not the
     * same fingerprint, and the right answer is a new session — never a silent rebind.
     */
    private function isResumable(ChunkSession $existing, InitiateSessionDTO $dto): bool
    {
        // Ownership first: an unowned session belongs to nobody, so it is never resumable
        // over a fingerprint match alone (AF-006).
        if (! $existing->isOwnedBy($dto->ownerId)) {
            return false;
        }

        // A session that has completed, failed or been cancelled has nothing to resume.
        if (! in_array($existing->status, [SessionStatus::PENDING, SessionStatus::UPLOADING], true)) {
            return false;
        }

        return $existing->fileName === $dto->fileName
            && $existing->fileSize === $dto->fileSize
            && $existing->totalChunks === $dto->totalChunks
            && $existing->totalHash->value === $dto->totalHash->value;
    }
}
