<?php

namespace App\Services\Uploads;

use App\Helpers\StorageHelper;
use App\Models\Artist;
use App\Models\MediaUploadSession;
use App\Models\User;
use Aws\S3\PostObjectV4;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelAwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SongMultipartUploadService
{
    private const MIN_MULTIPART_PART_SIZE_BYTES = 5 * 1024 * 1024;

    public function createAudioSession(
        User $user,
        Artist $artist,
        string $filename,
        ?string $contentType,
        int $sizeBytes,
        string $extension
    ): MediaUploadSession {
        $uuid = (string) Str::uuid();
        $partSizeBytes = $this->partSizeFor($sizeBytes);
        $totalParts = (int) ceil($sizeBytes / $partSizeBytes);

        return MediaUploadSession::create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'kind' => 'audio',
            'original_filename' => $filename,
            'content_type' => $contentType,
            'file_extension' => $extension,
            'size_bytes' => $sizeBytes,
            'part_size_bytes' => $partSizeBytes,
            'total_parts' => max($totalParts, 1),
            'disk' => StorageHelper::mediaDisk(),
            'target_key' => sprintf('songs/audio/direct/%d/%s.%s', $user->id, $uuid, $extension),
            'chunk_prefix' => sprintf('songs/audio/chunks/%d/%s', $user->id, $uuid),
            'status' => 'initiated',
            'metadata' => [
                'content_type' => $contentType,
            ],
            'expires_at' => now()->addHours($this->sessionTtlHours()),
        ]);
    }

    public function buildPartUploadTarget(MediaUploadSession $session, int $partNumber): array
    {
        $this->assertSessionIsActive($session);

        if ($partNumber < 1 || $partNumber > $session->total_parts) {
            throw new RuntimeException('Invalid upload part requested.');
        }

        $key = $this->chunkKey($session, $partNumber);
        $maxBytes = $this->expectedPartSizeBytes($session, $partNumber);

        if (app()->environment('testing')) {
            $session->forceFill(['status' => 'uploading'])->save();

            return [
                'disk' => $session->disk,
                'method' => 'POST',
                'key' => $key,
                'part_number' => $partNumber,
                'upload_url' => "https://example.test/direct-upload/{$key}",
                'fields' => [
                    'key' => $key,
                ],
                'expected_size_bytes' => $maxBytes,
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ];
        }

        $disk = $this->disk($session);
        $client = $this->clientFor($disk);
        $bucket = $this->bucketFor($session);
        $formInputs = [
            'key' => $key,
            'success_action_status' => '201',
        ];
        $conditions = [
            ['bucket' => $bucket],
            ['key' => $key],
            ['success_action_status' => '201'],
            ['content-length-range', 1, $maxBytes],
        ];

        $postObject = new PostObjectV4(
            $client,
            $bucket,
            $formInputs,
            $conditions,
            '+30 minutes'
        );

        $session->forceFill(['status' => 'uploading'])->save();

        return [
            'disk' => $session->disk,
            'method' => 'POST',
            'key' => $key,
            'part_number' => $partNumber,
            'upload_url' => Arr::get($postObject->getFormAttributes(), 'action'),
            'fields' => $postObject->getFormInputs(),
            'expected_size_bytes' => $maxBytes,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];
    }

    public function completeSession(MediaUploadSession $session): array
    {
        $this->assertSessionIsActive($session);

        if ($session->isCompleted()) {
            return [
                'key' => $session->target_key,
                'size_bytes' => $session->size_bytes,
                'original_filename' => $session->original_filename,
            ];
        }

        $session->forceFill(['status' => 'completing', 'last_error' => null])->save();

        try {
            if (app()->environment('testing')) {
                $this->composeTestingObject($session);
            } else {
                $this->composeCloudObject($session);
            }

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'key' => $session->target_key,
                'size_bytes' => $session->size_bytes,
                'original_filename' => $session->original_filename,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Song multipart upload session completion failed', [
                'session_uuid' => $session->uuid,
                'user_id' => $session->user_id,
                'error' => $exception->getMessage(),
            ]);

            $session->forceFill([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    public function abortSession(MediaUploadSession $session): void
    {
        if ($session->aborted_at !== null) {
            return;
        }

        $this->deleteChunkObjects($session);

        $session->forceFill([
            'status' => 'aborted',
            'aborted_at' => now(),
        ])->save();
    }

    public function markConsumed(MediaUploadSession $session): void
    {
        $session->forceFill([
            'status' => 'consumed',
            'consumed_at' => now(),
        ])->save();
    }

    public function chunkKey(MediaUploadSession $session, int $partNumber): string
    {
        return sprintf('%s/part-%04d', trim($session->chunk_prefix, '/'), $partNumber);
    }

    private function composeTestingObject(MediaUploadSession $session): void
    {
        $content = '';
        $totalBytes = 0;
        $disk = $this->disk($session);

        foreach (range(1, $session->total_parts) as $partNumber) {
            $key = $this->chunkKey($session, $partNumber);
            if (! $disk->exists($key)) {
                throw new RuntimeException("Upload chunk {$partNumber} is missing from cloud storage.");
            }

            $chunk = (string) $disk->get($key);
            $chunkBytes = strlen($chunk);
            $expectedMax = $this->expectedPartSizeBytes($session, $partNumber);
            if ($chunkBytes < 1 || $chunkBytes > $expectedMax) {
                throw new RuntimeException("Upload chunk {$partNumber} has an invalid size.");
            }

            $content .= $chunk;
            $totalBytes += $chunkBytes;
        }

        if ($totalBytes !== $session->size_bytes) {
            throw new RuntimeException('Uploaded chunks do not match the expected file size.');
        }

        $disk->put($session->target_key, $content);
        $this->deleteChunkObjects($session);
    }

    private function composeCloudObject(MediaUploadSession $session): void
    {
        $disk = $this->disk($session);
        $bucket = $this->bucketFor($session);
        $client = $this->clientFor($disk);
        $multipartArgs = [
            'Bucket' => $bucket,
            'Key' => $session->target_key,
            'ContentType' => $session->content_type ?: 'application/octet-stream',
        ];

        if ($this->isPublicDisk($session->disk)) {
            $multipartArgs['ACL'] = 'public-read';
        }

        $createResult = $client->createMultipartUpload($multipartArgs);
        $uploadId = (string) $createResult->get('UploadId');
        $parts = [];
        $totalBytes = 0;

        try {
            foreach (range(1, $session->total_parts) as $partNumber) {
                $sourceKey = $this->chunkKey($session, $partNumber);
                if (! $disk->exists($sourceKey)) {
                    throw new RuntimeException("Upload chunk {$partNumber} is missing from cloud storage.");
                }

                $chunkSize = (int) $disk->size($sourceKey);
                $expectedMax = $this->expectedPartSizeBytes($session, $partNumber);
                $expectedMin = $partNumber === $session->total_parts ? 1 : self::MIN_MULTIPART_PART_SIZE_BYTES;
                if ($chunkSize < $expectedMin || $chunkSize > $expectedMax) {
                    throw new RuntimeException("Upload chunk {$partNumber} has an invalid size.");
                }

                $copyResult = $client->uploadPartCopy([
                    'Bucket' => $bucket,
                    'Key' => $session->target_key,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'CopySource' => $this->copySource($bucket, $sourceKey),
                ]);

                $parts[] = [
                    'PartNumber' => $partNumber,
                    'ETag' => (string) Arr::get($copyResult->toArray(), 'CopyPartResult.ETag'),
                ];
                $totalBytes += $chunkSize;
            }

            if ($totalBytes !== $session->size_bytes) {
                throw new RuntimeException('Uploaded chunks do not match the expected file size.');
            }

            $client->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $session->target_key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ],
            ]);
        } catch (\Throwable $exception) {
            $client->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $session->target_key,
                'UploadId' => $uploadId,
            ]);

            throw $exception;
        }

        $this->deleteChunkObjects($session);
    }

    private function deleteChunkObjects(MediaUploadSession $session): void
    {
        $disk = $this->disk($session);
        foreach (range(1, $session->total_parts) as $partNumber) {
            $key = $this->chunkKey($session, $partNumber);
            if ($disk->exists($key)) {
                $disk->delete($key);
            }
        }
    }

    private function expectedPartSizeBytes(MediaUploadSession $session, int $partNumber): int
    {
        if ($partNumber === $session->total_parts) {
            $remainder = $session->size_bytes % $session->part_size_bytes;

            return $remainder === 0 ? $session->part_size_bytes : $remainder;
        }

        return $session->part_size_bytes;
    }

    private function assertSessionIsActive(MediaUploadSession $session): void
    {
        if ($session->isExpired()) {
            throw new RuntimeException('This upload session has expired. Please start the upload again.');
        }

        if (in_array($session->status, ['aborted', 'consumed'], true)) {
            throw new RuntimeException('This upload session is no longer active.');
        }
    }

    private function partSizeFor(int $sizeBytes): int
    {
        $configured = (int) config('music.storage.multipart.part_size_bytes', 8 * 1024 * 1024);
        $partSize = max($configured, self::MIN_MULTIPART_PART_SIZE_BYTES);
        $minimumRequired = (int) ceil($sizeBytes / 1000);

        if ($minimumRequired > $partSize) {
            $partSize = (int) ceil($minimumRequired / (1024 * 1024)) * 1024 * 1024;
        }

        return $partSize;
    }

    private function sessionTtlHours(): int
    {
        return max((int) config('music.storage.multipart.session_ttl_hours', 24), 1);
    }

    private function disk(MediaUploadSession $session): FilesystemAdapter
    {
        return Storage::disk($session->disk);
    }

    private function clientFor(FilesystemAdapter $disk)
    {
        if ($disk instanceof LaravelAwsS3V3Adapter) {
            return $disk->getClient();
        }

        if (method_exists($disk, 'getClient')) {
            return $disk->getClient();
        }

        throw new RuntimeException('Direct cloud uploads are not available for the current storage disk.');
    }

    private function bucketFor(MediaUploadSession $session): string
    {
        return (string) config("filesystems.disks.{$session->disk}.bucket");
    }

    private function copySource(string $bucket, string $key): string
    {
        return rawurlencode($bucket).'/'.str_replace('%2F', '/', rawurlencode($key));
    }

    private function isPublicDisk(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.visibility") === 'public';
    }
}
