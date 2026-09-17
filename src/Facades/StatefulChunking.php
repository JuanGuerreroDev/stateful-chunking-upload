<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Facades;

use Illuminate\Support\Facades\Facade;
use Juanoecr\StatefulChunkingUpload\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\StagedFileDTO;
use Juanoecr\StatefulChunkingUpload\Routing\ChunkUploadRoutes;

/**
 * @method static string generateToken(string $sessionId, string $tempPath, string $fileName, int $fileSize, string $hash, ?string $disk = null, ?int $ttl = null)
 * @method static StagedFileDTO resolveToken(string $uploadToken)
 *
 * @see StatefulChunkingService
 */
final class StatefulChunking extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatefulChunkingService::class;
    }

    /**
     * Estampa los cinco endpoints de chunking en el grupo de rutas actual, heredando su
     * prefijo, middleware y nombre. Permite exponer la herramienta en cualquier superficie
     * (admin web, api con token, o varias a la vez) sin redefinir cada endpoint.
     *
     * @param  array{rate_limiting?: bool}  $options
     */
    public static function routes(array $options = []): void
    {
        ChunkUploadRoutes::register($options);
    }
}
