<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities;

use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\ChunkIndexOutOfBoundsException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\UploadBudgetExceededException;

final class ChunkSession
{
    /**
     * @param  array<int, string>  $chunksMap  Status per chunk index (e.g. [0 => 'completed', 1 => 'pending'])
     */
    public function __construct(
        public readonly SessionId $sessionId,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly int $totalChunks,
        public readonly ChunkHash $totalHash,
        public readonly string $fingerprint,
        public SessionStatus $status = SessionStatus::PENDING,
        public array $chunksMap = [],
        public int $createdAt = 0,
        public int $expiresAt = 0,
        public ?SessionOwner $ownerId = null,
        public int $uploadedBytes = 0,
        // The chunk size (bytes) this session was chunked with, captured at initiate
        // from the server config. Recorded on the session — rather than re-read from
        // config each time — so a client discovers the size the session actually uses
        // even if an operator changes the config while the upload is in flight. The
        // 2 MiB default mirrors the config default and only covers legacy sessions
        // rehydrated without the field; the initiate path always passes the real value.
        public readonly int $chunkSizeBytes = 2097152
    ) {
        if (empty($this->chunksMap)) {
            for ($i = 0; $i < $totalChunks; $i++) {
                $this->chunksMap[$i] = 'pending';
            }
        }

        $now = time();
        $this->createdAt = $this->createdAt > 0 ? $this->createdAt : $now;
        $this->expiresAt = $this->expiresAt > 0 ? $this->expiresAt : ($this->createdAt + 21600);
    }

    /**
     * Guard the aggregate's core invariant: a chunk index must fall within the
     * session's declared [0, totalChunks) range. Enforcing it on the root (rather
     * than in the calling Action) keeps invalid states unreachable no matter which
     * use case drives the session.
     */
    public function assertChunkIndexWithinBounds(int $chunkIndex): void
    {
        if ($chunkIndex < 0 || $chunkIndex >= $this->totalChunks) {
            throw new ChunkIndexOutOfBoundsException(
                sprintf('Chunk index %d out of bounds (totalChunks=%d).', $chunkIndex, $this->totalChunks),
                ['session_id' => $this->sessionId->value, 'chunk_index' => $chunkIndex, 'total_chunks' => $this->totalChunks]
            );
        }
    }

    /**
     * The maximum number of bytes this session may ever hold on disk, derived from
     * the declared file size plus one chunk of rounding slack, and never above the
     * configured hard cap. This is what turns file_size from a self-reported number
     * into an enforced limit.
     */
    public function byteBudget(int $chunkSizeBytes, int $maxFileSizeBytes): int
    {
        return min($maxFileSizeBytes, $this->fileSize + $chunkSizeBytes);
    }

    /**
     * Defense-in-depth for storage amplification: reject a chunk whose bytes would push
     * the session's cumulative upload past the budget its declared file_size allows.
     * Enforced on the aggregate root so no use case can bypass it.
     *
     * The budget is passed in rather than derived here, because this same check has to
     * run twice against two different reads of the session: once as an early exit before
     * the bytes touch disk, and once inside the critical section that increments the
     * counter. Deciding only outside that lock was a check-then-act race (AF-007) — N
     * concurrent uploads of distinct indices all read one uploadedBytes snapshot and all
     * passed, overshooting by up to (N-1) chunks. Pair it with {@see self::byteBudget()}.
     */
    public function assertWithinBudget(int $incomingBytes, int $budget): void
    {
        if ($this->uploadedBytes + $incomingBytes > $budget) {
            throw new UploadBudgetExceededException(
                sprintf(
                    'Cumulative upload (%d + %d bytes) exceeds session budget of %d bytes.',
                    $this->uploadedBytes,
                    $incomingBytes,
                    $budget
                ),
                [
                    'session_id' => $this->sessionId->value,
                    'uploaded_bytes' => $this->uploadedBytes,
                    'incoming_bytes' => $incomingBytes,
                    'budget' => $budget,
                ]
            );
        }
    }

    /**
     * Whether $candidate is this session's owner.
     *
     * Fails closed on both sides of the comparison. A session with no owner belongs to
     * nobody rather than to everybody, and a caller with no identity owns nothing. That
     * asymmetry is the whole of AF-006: the guard used to read a null owner as "there is
     * nothing here to protect", which made any session created programmatically without
     * an owner readable, completable and cancellable by anyone who knew its id.
     *
     * The decision lives on the aggregate so every use case gets the same answer — the
     * HTTP guard and fingerprint reuse are two callers that once disagreed.
     */
    public function isOwnedBy(?SessionOwner $candidate): bool
    {
        return $candidate !== null && $this->ownerId?->equals($candidate) === true;
    }

    public function recordUploadedBytes(int $bytes): void
    {
        $this->uploadedBytes += max(0, $bytes);
    }

    public function markChunkCompleted(int $chunkIndex): void
    {
        $this->chunksMap[$chunkIndex] = 'completed';
        $this->status = SessionStatus::UPLOADING;

        if ($this->isComplete()) {
            $this->status = SessionStatus::COMPLETED;
        }
    }

    public function markChunkFailed(int $chunkIndex): void
    {
        $this->chunksMap[$chunkIndex] = 'failed';
    }

    public function isComplete(): bool
    {
        foreach ($this->chunksMap as $status) {
            if ($status !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, int>
     */
    public function getPendingChunkIndices(): array
    {
        $pending = [];
        foreach ($this->chunksMap as $index => $status) {
            if ($status !== 'completed') {
                $pending[] = (int) $index;
            }
        }

        return $pending;
    }

    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    public function remainingTtl(): int
    {
        return max(0, $this->expiresAt - time());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId->value,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'uploaded_bytes' => $this->uploadedBytes,
            'total_chunks' => $this->totalChunks,
            'chunk_size_bytes' => $this->chunkSizeBytes,
            'total_hash' => $this->totalHash->value,
            'fingerprint' => $this->fingerprint,
            'owner_id' => $this->ownerId?->value,
            'status' => $this->status->value,
            'chunks_map' => $this->chunksMap,
            'pending_chunks' => $this->getPendingChunkIndices(),
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'is_expired' => $this->isExpired(),
            'remaining_ttl' => $this->remainingTtl(),
        ];
    }
}
