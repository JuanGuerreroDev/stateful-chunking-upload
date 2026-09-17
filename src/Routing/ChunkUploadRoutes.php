<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Routing;

use Illuminate\Support\Facades\Route;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Controllers\ChunkUploadController;

/**
 * Registra los cinco endpoints de chunking RELATIVOS al grupo de rutas actual, heredando
 * su prefijo, middleware y nombre. Así el consumidor coloca la herramienta donde la
 * necesite —web+auth de admin, api+sanctum, o varias superficies a la vez— sin redefinir
 * cada endpoint ni pelear con el auto-registro de un único grupo.
 *
 * Uso (patrón Passport::routes() / Horizon):
 *
 *     Route::middleware('craftable-pro-middlewares')->prefix('admin/media')->group(function () {
 *         \Juanoecr\StatefulChunkingUpload\Facades\StatefulChunking::routes();
 *     });
 *
 * @param  array{rate_limiting?: bool}  $options
 */
final class ChunkUploadRoutes
{
    private const SESSION_ID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * @param  array{rate_limiting?: bool}  $options  rate_limiting=true aplica los throttle
     *                                                del paquete por operación (requiere que los limitadores estén registrados).
     */
    public static function register(array $options = []): void
    {
        $rateLimiting = (bool) ($options['rate_limiting'] ?? false);
        $throttle = static fn (string $op): array => $rateLimiting
            ? ['throttle:stateful-chunking-upload.'.$op]
            : [];

        Route::post('initiate', [ChunkUploadController::class, 'initiate'])
            ->middleware($throttle('initiate'))
            ->name('initiate');

        Route::post('upload', [ChunkUploadController::class, 'upload'])
            ->middleware($throttle('upload'))
            ->name('upload');

        Route::get('status/{sessionId}', [ChunkUploadController::class, 'status'])
            ->where('sessionId', self::SESSION_ID_PATTERN)
            ->middleware($throttle('status'))
            ->name('status');

        Route::post('complete', [ChunkUploadController::class, 'complete'])
            ->middleware($throttle('complete'))
            ->name('complete');

        Route::delete('cancel/{sessionId}', [ChunkUploadController::class, 'cancel'])
            ->where('sessionId', self::SESSION_ID_PATTERN)
            ->middleware($throttle('cancel'))
            ->name('cancel');
    }
}
