<?php

namespace App\Http\Middleware;

use App\Services\SecureFileUploadService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SecureFileUploadMiddleware
{
    private SecureFileUploadService $uploadService;

    public function __construct(SecureFileUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only process requests with file uploads
        if (!$request->hasFile('files') && !$request->hasFile('file')) {
            return $next($request);
        }

        // Check upload limits per user
        if (!$this->checkUploadLimits($request)) {
            return response()->json([
                'error' => 'Upload rate limit exceeded. Please wait before uploading more files.'
            ], 429);
        }

        // Validate uploaded files
        $files = $request->file('files') ?: [$request->file('file')];
        $validationErrors = [];

        foreach ($files as $file) {
            if ($file) {
                $validation = $this->uploadService->validateAudioFile($file);

                if (!$validation['valid']) {
                    $validationErrors = array_merge($validationErrors, $validation['errors']);

                    Log::warning('File upload blocked', [
                        'user_id' => auth()->id(),
                        'filename' => $file->getClientOriginalName(),
                        'errors' => $validation['errors'],
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent()
                    ]);
                }
            }
        }

        if (!empty($validationErrors)) {
            return response()->json([
                'error' => 'File validation failed',
                'details' => $validationErrors
            ], 422);
        }

        return $next($request);
    }

    /**
     * Check upload rate limits per user
     */
    private function checkUploadLimits(Request $request): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $cacheKey = "upload_limit_{$user->id}";
        $uploads = cache()->get($cacheKey, 0);

        // Allow 10 files per hour per user
        if ($uploads >= 10) {
            return false;
        }

        // Increment counter
        cache()->put($cacheKey, $uploads + 1, now()->addHour());

        return true;
    }
}