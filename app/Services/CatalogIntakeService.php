<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CatalogSubmission;
use App\Models\CatalogSubmissionItem;
use App\Models\Genre;
use App\Models\Song;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogIntakeService
{
    private const REQUIRED_COLUMNS = [
        'audio_filename',
        'artist_name',
        'song_title',
    ];

    public function __construct(
        private readonly PlaceholderArtistService $placeholderArtistService,
        private readonly SongSlugService $songSlugService,
    ) {}

    public function createSubmission(
        User $uploader,
        UploadedFile $csvFile,
        array $audioFiles,
        array $coverFiles = [],
        ?string $sourceName = null,
        ?string $notes = null,
    ): CatalogSubmission {
        $rows = $this->parseCsv($csvFile);
        $audioMap = $this->mapFilesByOriginalName($audioFiles, 'audio_files');
        $coverMap = $this->mapFilesByOriginalName($coverFiles, 'cover_files');
        $this->guardAgainstDuplicateRowAudioFilenames($rows);

        $submission = CatalogSubmission::create([
            'uploader_user_id' => $uploader->id,
            'status' => 'processing',
            'source_name' => $sourceName,
            'csv_original_name' => $csvFile->getClientOriginalName(),
            'notes' => $notes,
            'total_items' => count($rows),
            'processed_items' => 0,
            'failed_items' => 0,
            'submitted_at' => now(),
            'metadata' => [
                'required_columns' => self::REQUIRED_COLUMNS,
            ],
        ]);

        $processedCount = 0;
        $failedCount = 0;

        foreach ($rows as $row) {
            $item = CatalogSubmissionItem::create([
                'catalog_submission_id' => $submission->id,
                'artist_name' => $row['artist_name'] ?? null,
                'song_title' => $row['song_title'] ?? null,
                'audio_filename' => $row['audio_filename'] ?? null,
                'cover_filename' => $row['cover_filename'] ?? null,
                'phone_number' => $row['phone_number'] ?? null,
                'email' => $row['email'] ?? null,
                'external_reference' => $row['national_id'] ?? $row['external_reference'] ?? null,
                'genre' => $row['genre'] ?? null,
                'release_date' => $row['release_date'] ?? null,
                'featured_artists' => $row['featured_artists'] ?? null,
                'notes' => $row['notes'] ?? null,
                'status' => 'pending',
                'row_payload' => $row,
            ]);

            $errors = $this->validateRow($row, $audioMap);
            if ($errors !== []) {
                $item->update([
                    'status' => 'failed',
                    'validation_errors' => $errors,
                ]);
                $failedCount++;

                continue;
            }

            try {
                DB::transaction(function () use ($uploader, $row, $audioMap, $coverMap, $item, &$processedCount) {
                    $placeholderArtist = $this->placeholderArtistService->findOrCreate(
                        $row['artist_name'],
                        $uploader,
                        [
                            'primary_genre_id' => $this->resolveGenreId($row['genre'] ?? null),
                        ]
                    );

                    $audioFile = $audioMap[$row['audio_filename']];
                    $coverFile = ! empty($row['cover_filename']) ? ($coverMap[$row['cover_filename']] ?? null) : null;

                    $audioPath = $this->storeFile($audioFile, 'songs/audio');
                    $artworkPath = $coverFile ? $this->storeFile($coverFile, 'songs/artwork') : null;

                    $song = Song::create([
                        'title' => $row['song_title'],
                        'slug' => $this->songSlugService->generateUniqueSlug($row['song_title']),
                        'artist_id' => $placeholderArtist->id,
                        'user_id' => $uploader->id,
                        'primary_genre_id' => $this->resolveGenreId($row['genre'] ?? null),
                        'status' => 'pending',
                        'visibility' => 'public',
                        'is_free' => true,
                        'is_downloadable' => true,
                        'is_streamable' => true,
                        'is_claimable' => true,
                        'source_type' => 'catalog_submission',
                        'source_submission_item_id' => $item->id,
                        'audio_file_original' => $audioPath,
                        'audio_file_320' => $audioPath,
                        'artwork' => $artworkPath,
                        'file_format' => $audioFile->getClientOriginalExtension(),
                        'file_size_bytes' => $audioFile->getSize(),
                        'processing_status' => ['status' => 'completed', 'progress' => 100],
                        'featured_artists' => $this->parseFeaturedArtists($row['featured_artists'] ?? null),
                        'release_date' => $row['release_date'] ?? null,
                        'description' => $row['notes'] ?? null,
                        'duration_seconds' => 0,
                    ]);

                    $placeholderArtist->increment('total_songs_count');

                    $item->update([
                        'status' => 'materialized',
                        'artist_id' => $placeholderArtist->id,
                        'song_id' => $song->id,
                        'validation_errors' => null,
                    ]);

                    $processedCount++;
                });
            } catch (\Throwable $exception) {
                $item->update([
                    'status' => 'failed',
                    'validation_errors' => [
                        'row' => [$exception->getMessage()],
                    ],
                ]);
                $failedCount++;
            }
        }

        $submission->update([
            'status' => $processedCount > 0 && $failedCount === 0 ? 'processed' : ($processedCount > 0 ? 'partial' : 'failed'),
            'processed_items' => $processedCount,
            'failed_items' => $failedCount,
            'processed_at' => now(),
        ]);

        AuditLog::logActivity($uploader->id, 'catalog_submission_created', [
            'submission_id' => $submission->id,
            'total_items' => $submission->total_items,
            'processed_items' => $processedCount,
            'failed_items' => $failedCount,
        ]);

        return $submission->fresh(['items.artist', 'items.song']);
    }

    private function parseCsv(UploadedFile $csvFile): array
    {
        $csvPath = $csvFile->getRealPath() ?: $csvFile->getPathname();
        $handle = fopen($csvPath, 'r');
        if (! $handle) {
            throw ValidationException::withMessages([
                'csv_file' => ['The CSV file could not be read.'],
            ]);
        }

        $headerRow = fgetcsv($handle);
        if (! $headerRow) {
            fclose($handle);
            throw ValidationException::withMessages([
                'csv_file' => ['The CSV file is empty.'],
            ]);
        }

        $headers = array_map(function ($header) {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);

            return trim((string) $header);
        }, $headerRow);

        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $headers));
        if ($missingColumns !== []) {
            fclose($handle);
            throw ValidationException::withMessages([
                'csv_file' => ['Missing required CSV columns: '.implode(', ', $missingColumns)],
            ]);
        }

        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $mappedRow = [];
            foreach ($headers as $index => $header) {
                $mappedRow[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
            }

            $mappedRow['_row_number'] = $rowNumber;
            $rows[] = $mappedRow;
        }

        fclose($handle);

        return $rows;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapFilesByOriginalName(array $files, string $field): array
    {
        $map = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $name = $file->getClientOriginalName();
            if (isset($map[$name])) {
                throw ValidationException::withMessages([
                    $field => ["Duplicate uploaded filename detected: {$name}"],
                ]);
            }

            $map[$name] = $file;
        }

        return $map;
    }

    private function guardAgainstDuplicateRowAudioFilenames(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $audioFilename = $row['audio_filename'] ?? null;
            if (! $audioFilename) {
                continue;
            }

            if (isset($seen[$audioFilename])) {
                throw ValidationException::withMessages([
                    'csv_file' => ["Duplicate audio_filename '{$audioFilename}' found in CSV rows {$seen[$audioFilename]} and {$row['_row_number']}."],
                ]);
            }

            $seen[$audioFilename] = $row['_row_number'];
        }
    }

    private function validateRow(array $row, array $audioMap): array
    {
        $errors = [];

        if (empty($row['artist_name'])) {
            $errors['artist_name'][] = 'Artist name is required.';
        }

        if (empty($row['song_title'])) {
            $errors['song_title'][] = 'Song title is required.';
        }

        if (empty($row['audio_filename'])) {
            $errors['audio_filename'][] = 'Audio filename is required.';
        } elseif (! isset($audioMap[$row['audio_filename']])) {
            $errors['audio_filename'][] = 'No uploaded audio file matches this row.';
        }

        return $errors;
    }

    private function resolveGenreId(?string $genreValue): ?int
    {
        if (! $genreValue) {
            return null;
        }

        if (is_numeric($genreValue)) {
            return (int) $genreValue;
        }

        return Genre::query()
            ->where('name', $genreValue)
            ->orWhere('slug', Str::slug($genreValue))
            ->value('id');
    }

    private function parseFeaturedArtists(?string $featuredArtists): ?array
    {
        if (! $featuredArtists) {
            return null;
        }

        $artists = array_values(array_filter(array_map('trim', explode(',', $featuredArtists))));

        return $artists === [] ? null : $artists;
    }

    private function uploadDisk()
    {
        $disk = config('filesystems.default', 'local');

        return Storage::disk($disk === 'local' ? 'public' : $disk);
    }

    private function storeFile(UploadedFile $file, string $directory): string
    {
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid().($extension ? '.'.$extension : '');
        $path = trim($directory, '/').'/'.$fileName;

        $this->uploadDisk()->put($path, fopen($file->getPathname(), 'r'));

        return $path;
    }
}
