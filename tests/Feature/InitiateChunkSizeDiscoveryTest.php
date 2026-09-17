<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * The client learns the server's chunk size from /initiate — even when it guessed wrong.
 *
 * `total_chunks` is bounded against the server's configured chunk size, so a client that
 * assumes the wrong size is rejected with 422 before it can read a successful response. To
 * make the chunk size discoverable in one round trip, that 422 carries `chunk_size_bytes`,
 * so the client can rebuild its plan and retry — the zero-config path that keeps the size
 * declared only once, on the server.
 */
class InitiateChunkSizeDiscoveryTest extends TestCase
{
    private const VALID_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking-upload.rate_limits.enabled', false);
        RateLimiter::clear('stateful-chunking-upload.initiate');
    }

    public function test_total_chunks_mismatch_surfaces_the_configured_chunk_size(): void
    {
        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'guess.bin',
            'file_size' => 1,
            'total_chunks' => 999,   // impossible for a 1-byte file at any real chunk size
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'discover_default_'.uniqid(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('total_chunks');
        $this->assertSame(2097152, $response->json('chunk_size_bytes'));
    }

    public function test_client_can_learn_a_non_default_chunk_size_from_the_rejection(): void
    {
        // Real-world scenario: the server runs 12 MiB chunks, the client still assumes its
        // 2 MiB default. For a one-server-chunk file the client computes 6 chunks; the server
        // expects 1. The rejection must carry the real size so the client can self-correct.
        $serverChunkSize = 12 * 1024 * 1024; // 12 MiB, a multiple of the 256 KB requirement
        Config::set('stateful-chunking-upload.chunk_size_bytes', $serverChunkSize);

        $clientAssumedChunks = (int) ceil($serverChunkSize / 2097152); // 6, as the client would send

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'bigvideo.mp4',
            'file_size' => $serverChunkSize,
            'total_chunks' => $clientAssumedChunks,
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'discover_custom_'.uniqid(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('total_chunks');
        $this->assertSame($serverChunkSize, $response->json('chunk_size_bytes'));
    }

    public function test_a_correct_request_still_succeeds_and_echoes_the_chunk_size(): void
    {
        $serverChunkSize = 12 * 1024 * 1024;
        Config::set('stateful-chunking-upload.chunk_size_bytes', $serverChunkSize);

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'bigvideo.mp4',
            'file_size' => $serverChunkSize,
            'total_chunks' => 1, // correct once the client knows the real size
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'discover_ok_'.uniqid(),
        ]);

        $response->assertStatus(201);
        $this->assertSame($serverChunkSize, $response->json('data.chunk_size_bytes'));
    }
}
