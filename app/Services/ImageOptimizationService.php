<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;

class ImageOptimizationService
{
    protected array $sizes = [
        'thumbnail' => 150,  // Tiny preview
        'small' => 320,      // Mobile list view
        'medium' => 640,     // Mobile detail view
        'large' => 1024,     // Desktop view
    ];
    
    /**
     * Generate responsive images in multiple sizes
     * 
     * @param string $imagePath Path to the original image
     * @param string $folder Folder to store images (e.g., 'songs', 'artists')
     * @return array URLs for different sizes
     */
    public function generateResponsiveImages(string $imagePath, string $folder = 'songs'): array
    {
        $urls = [];
        
        try {
            // Get the original image
            $originalImage = Image::make(Storage::disk('local')->get($imagePath));
            $filename = pathinfo($imagePath, PATHINFO_FILENAME);
            $uniqueId = Str::random(8);
            
            foreach ($this->sizes as $sizeName => $width) {
                // Create resized image
                $resized = clone $originalImage;
                $resized->resize($width, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize(); // Don't upscale smaller images
                });
                
                // Save as WebP (better compression, 30% smaller than JPEG)
                $webpFilename = "{$folder}/{$uniqueId}_{$sizeName}.webp";
                
                // Use DigitalOcean Spaces if configured, otherwise local
                $disk = config('filesystems.default') === 'digitalocean' ? 'digitalocean' : 'public';
                
                Storage::disk($disk)->put(
                    $webpFilename,
                    (string) $resized->encode('webp', 80)
                );
                
                $urls[$sizeName] = Storage::disk($disk)->url($webpFilename);
            }
            
            return $urls;
            
        } catch (\Exception $e) {
            \Log::error('Image optimization failed: ' . $e->getMessage());
            
            // Return original image for all sizes as fallback
            $originalUrl = Storage::url($imagePath);
            return array_fill_keys(array_keys($this->sizes), $originalUrl);
        }
    }
    
    /**
     * Generate placeholder (blurred tiny image for lazy loading)
     * Returns base64 encoded data URI
     * 
     * @param string $imagePath
     * @return string Base64 data URI
     */
    public function generatePlaceholder(string $imagePath): string
    {
        try {
            $image = Image::make(Storage::disk('local')->get($imagePath));
            
            // Create tiny blurred version (20px wide)
            $placeholder = $image->resize(20, null, function ($constraint) {
                $constraint->aspectRatio();
            })->blur(10);
            
            // Convert to base64 data URI
            return 'data:image/jpeg;base64,' . base64_encode($placeholder->encode('jpg', 50));
            
        } catch (\Exception $e) {
            \Log::error('Placeholder generation failed: ' . $e->getMessage());
            
            // Return default SVG placeholder
            return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"%3E%3Crect fill="%23333" width="400" height="300"/%3E%3C/svg%3E';
        }
    }
    
    /**
     * Optimize existing image (for already uploaded songs)
     * 
     * @param string $existingPath Path to existing image
     * @param string $folder Folder name
     * @return array
     */
    public function optimizeExisting(string $existingPath, string $folder = 'songs'): array
    {
        $urls = $this->generateResponsiveImages($existingPath, $folder);
        $placeholder = $this->generatePlaceholder($existingPath);
        
        return [
            'urls' => $urls,
            'placeholder' => $placeholder,
        ];
    }
    
    /**
     * Batch optimize images (for migrations/commands)
     * 
     * @param array $imagePaths
     * @param string $folder
     * @return array Results
     */
    public function batchOptimize(array $imagePaths, string $folder = 'songs'): array
    {
        $results = [];
        
        foreach ($imagePaths as $path) {
            try {
                $results[$path] = $this->optimizeExisting($path, $folder);
            } catch (\Exception $e) {
                $results[$path] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
}
