<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Repositories;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events\ChunkSessionExpired;

final class CacheStateRepository implements StateRepositoryInterface
{
    private function getStoreName(): ?string
    {
        $store = config('stateful-chunking-upload.cache_store')
            ?: config('stateful-chunking-upload.driver')
            ?: config('cache.default');

        return is_string($store) ? $store : null;
    }

    private function sessionKey(string $sessionId): string
    {
        return sprintf('chunk_session:%s', $sessionId);
    }

    private function lockKey(string $sessionId): string
    {
        return sprintf('chunk_session_lock:%s', $sessionId);
    }

    private function fingerprintKey(string $fingerprint): string
    {
        return sprintf('chunk_fingerprint:%s', $fingerprint);
    }

    public function saveSession(ChunkSession $session): void
    {
        $store = Cache::store($this->getStoreName());
        $ttl = max(1, $session->remainingTtl());

        $sessionData = [
            'session_id' => $session->sessionId->value,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'uploaded_bytes' => $session->uploadedBytes,
            'total_chunks' => $session->totalChunks,
            'chunk_size_bytes' => $session->chunkSizeBytes,
            'total_hash' => $session->totalHash->value,
            'fingerprint' => $session->fingerprint,
            'status' => $session->status->value,
            'chunks_map' => $session->chunksMap,
            'created_at' => $session->createdAt,
            'expires_at' => $session->expiresAt,
            'owner_id' => $session->ownerId?->value,
        ];

        $store->put($this->sessionKey($session->sessionId->value), $sessionData, $ttl);

        if (! empty($session->fingerprint)) {
            $store->put($this->fingerprintKey($session->fingerprint), $session->sessionId->value, $ttl);
        }
    }

    public function getSession(string $sessionId): ?ChunkSession
    {
        $store = Cache::store($this->getStoreName());
        $sessionData = $store->get($this->sessionKey($sessionId));

        if (! is_array($sessionData)) {
            return null;
        }

        $chunksMap = [];
        if (isset($sessionData['chunks_map']) && is_array($sessionData['chunks_map'])) {
            foreach ($sessionData['chunks_map'] as $idx => $st) {
                $chunksMap[(int) $idx] = is_string($st) || is_numeric($st) ? (string) $st : 'pending';
            }
        }

        $rawSessionId = isset($sessionData['session_id']) && is_string($sessionData['session_id']) ? $sessionData['session_id'] : '';
        $rawFileName = isset($sessionData['file_name']) && is_string($sessionData['file_name']) ? $sessionData['file_name'] : '';
        $rawFileSize = isset($sessionData['file_size']) && is_numeric($sessionData['file_size']) ? (int) $sessionData['file_size'] : 0;
        $rawUploadedBytes = isset($sessionData['uploaded_bytes']) && is_numeric($sessionData['uploaded_bytes']) ? (int) $sessionData['uploaded_bytes'] : 0;
        $rawTotalChunks = isset($sessionData['total_chunks']) && is_numeric($sessionData['total_chunks']) ? (int) $sessionData['total_chunks'] : 0;
        $rawTotalHash = isset($sessionData['total_hash']) && is_string($sessionData['total_hash']) ? $sessionData['total_hash'] : '';
        $rawFingerprint = isset($sessionData['fingerprint']) && is_string($sessionData['fingerprint']) ? $sessionData['fingerprint'] : '';
        $rawStatus = isset($sessionData['status']) && (is_string($sessionData['status']) || is_int($sessionData['status'])) ? $sessionData['status'] : 'pending';
        $rawCreatedAt = isset($sessionData['created_at']) && is_numeric($sessionData['created_at']) ? (int) $sessionData['created_at'] : 0;
        $rawExpiresAt = isset($sessionData['expires_at']) && is_numeric($sessionData['expires_at']) ? (int) $sessionData['expires_at'] : 0;
        // Rehydrate chunk_size_bytes. saveSession() writes it as a positive int, read back
        // here with the same defensive guards. A session persisted BEFORE this field existed
        // (e.g. mid-upload during a rolling deploy) has no value: it falls back to the server's
        // currently configured chunk size — the same one InitiateChunkRequest bounds
        // total_chunks against — rather than a blind 2 MiB, so the size a client discovers
        // stays the effective one. The literal 2097152 only covers a missing/invalid config.
        $rawChunkSizeBytes = isset($sessionData['chunk_size_bytes'])
            && is_numeric($sessionData['chunk_size_bytes'])
            && (int) $sessionData['chunk_size_bytes'] > 0
                ? (int) $sessionData['chunk_size_bytes']
                : (static function (): int {
                    $configured = config('stateful-chunking-upload.chunk_size_bytes', 2097152);

                    return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 2097152;
                })();
        // A payload whose owner is absent, not a string, or no longer parseable yields
        // no owner — and a session with no owner belongs to nobody, so a corrupted or
        // hand-edited entry fails closed instead of becoming public.
        $rawOwnerId = SessionOwner::tryFromString($sessionData['owner_id'] ?? null);

        $session = new ChunkSession(
            sessionId: SessionId::fromString($rawSessionId),
            fileName: $rawFileName,
            fileSize: $rawFileSize,
            totalChunks: $rawTotalChunks,
            totalHash: ChunkHash::fromString($rawTotalHash),
            fingerprint: $rawFingerprint,
            status: SessionStatus::from($rawStatus),
            chunksMap: $chunksMap,
            createdAt: $rawCreatedAt,
            expiresAt: $rawExpiresAt,
            ownerId: $rawOwnerId,
            uploadedBytes: $rawUploadedBytes,
            chunkSizeBytes: $rawChunkSizeBytes
        );

        if ($session->isExpired()) {
            // Announce the expiry before erasing the state, so listeners still have a
            // session to describe. PurgeExpiredSessionChunks uses this to remove the
            // staging directory that deleteSession() cannot see.
            ChunkSessionExpired::dispatch($session->sessionId->value, $session->totalChunks);

            $this->deleteSession($sessionId);

            return null;
        }

        return $session;
    }

    public function findSessionByFingerprint(string $fingerprint): ?ChunkSession
    {
        if (trim($fingerprint) === '') {
            return null;
        }

        $store = Cache::store($this->getStoreName());
        $sessionId = $store->get($this->fingerprintKey($fingerprint));

        if (! is_string($sessionId) && ! is_numeric($sessionId)) {
            return null;
        }

        return $this->getSession((string) $sessionId);
    }

    public function updateChunkStatus(
        string $sessionId,
        int $chunkIndex,
        string $status,
        ?int $chunkBytes = null,
        ?int $byteBudget = null
    ): void {
        $this->withSessionLock($sessionId, function () use ($sessionId, $chunkIndex, $status, $chunkBytes, $byteBudget): void {
            $session = $this->getSession($sessionId);
            if (! $session) {
                return;
            }

            if ($status === 'completed') {
                $alreadyCompleted = ($session->chunksMap[$chunkIndex] ?? null) === 'completed';

                // Re-verify the budget against the state just read under the lock, not
                // the snapshot the caller decided on. This is the only place where the
                // read and the increment are in the same critical section, so it is the
                // only place the check actually holds (AF-007). Throwing before any
                // mutation leaves the session exactly as it was; the caller rolls the
                // written chunk file back.
                if (! $alreadyCompleted && $chunkBytes !== null && $byteBudget !== null) {
                    $session->assertWithinBudget($chunkBytes, $byteBudget);
                }

                $session->markChunkCompleted($chunkIndex);

                // Count bytes only on the first completion of a chunk, so idempotent
                // re-uploads never inflate the cumulative total.
                if (! $alreadyCompleted && $chunkBytes !== null) {
                    $session->recordUploadedBytes($chunkBytes);
                }
            } else {
                $session->chunksMap[$chunkIndex] = $status;
            }

            $this->saveSession($session);
        });
    }

    public function withSessionLock(string $sessionId, callable $callback): mixed
    {
        $store = Cache::store($this->getStoreName());

        if ($store->getStore() instanceof LockProvider) {
            /** @var Repository&LockProvider $storeWithLock */
            $storeWithLock = $store;

            return $storeWithLock->lock($this->lockKey($sessionId), 10)->block(5, $callback);
        }

        return $this->executeWithFallbackFileLock($sessionId, $callback);
    }

    private function getFallbackLockPath(string $sessionId): string
    {
        return sprintf('%s/chunk_lock_%s.lock', sys_get_temp_dir(), md5($sessionId));
    }

    private function executeWithFallbackFileLock(string $sessionId, callable $callback): mixed
    {
        $lockPath = $this->getFallbackLockPath($sessionId);
        $fp = fopen($lockPath, 'c+');

        if (! $fp) {
            return $callback();
        }

        try {
            $startTime = microtime(true);
            $locked = false;

            while ((microtime(true) - $startTime) < 5.0) {
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                usleep(25000);
            }

            if (! $locked) {
                flock($fp, LOCK_EX);
            }

            return $callback();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function deleteSession(string $sessionId): void
    {
        $store = Cache::store($this->getStoreName());
        $sessionData = $store->get($this->sessionKey($sessionId));

        if (is_array($sessionData) && isset($sessionData['fingerprint']) && is_string($sessionData['fingerprint']) && ! empty($sessionData['fingerprint'])) {
            $store->forget($this->fingerprintKey($sessionData['fingerprint']));
        }

        $store->forget($this->sessionKey($sessionId));

        $lockPath = $this->getFallbackLockPath($sessionId);
        if (file_exists($lockPath)) {
            @unlink($lockPath);
        }
    }
}
