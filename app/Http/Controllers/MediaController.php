<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MediaController extends Controller
{
    /**
     * Get all media with optional filter by days
     */
    public function index(Request $request)
    {
        try {
            $query = Media::query()->orderBy('created_at', 'desc');

            if ($request->has('days') && $request->days !== 'all') {
                $days = (int) $request->days;
                $cutoffDate = Carbon::now()->subDays($days);
                $query->where('created_at', '>=', $cutoffDate);
            }

            $mediaList = $query->get();

            $formattedMedia = $mediaList->map(function ($item) {
                $imageUrl = null;
                
                if ($item->image_url) {
                    // Jika sudah full URL (http/https), gunakan langsung
                    if (str_starts_with($item->image_url, 'http')) {
                        $imageUrl = $item->image_url;
                    } else {
                        // ✅ Jika path relatif (media/xxx.webp), build full URL
                        $imageUrl = url('storage/' . $item->image_url);
                    }
                }

                // ✅ LOG UNTUK DEBUG
                Log::info('Formatting media item', [
                    'id' => $item->id,
                    'name' => $item->name,
                    'image_url_db' => $item->image_url,
                    'imageUrl_response' => $imageUrl,
                    'video_url' => $item->video_url,
                ]);

                return [
                    'id' => $item->id,
                    'timeStamp' => $item->created_at->format('Y-m-d'),
                    'date' => $item->created_at->format('d/m/Y'),
                    'imageUrl' => $imageUrl, // ✅ camelCase
                    'videoUrl' => $item->video_url, // ✅ camelCase
                    'name' => $item->name,
                    'category' => $item->category,
                ];
            });

            Log::info('Sending media response', ['count' => $formattedMedia->count()]);

            return response()->json($formattedMedia);
        } catch (\Exception $e) {
            Log::error('Error in MediaController@index: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to fetch media',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics for filter buttons
     */
    public function statistics()
    {
        try {
            $all = Media::count();
            $last7Days = Media::where('created_at', '>=', Carbon::now()->subDays(7))->count();
            $last14Days = Media::where('created_at', '>=', Carbon::now()->subDays(14))->count();
            $last30Days = Media::where('created_at', '>=', Carbon::now()->subDays(30))->count();
            $last90Days = Media::where('created_at', '>=', Carbon::now()->subDays(90))->count();

            return response()->json([
                'all' => $all,
                '7' => $last7Days,
                '14' => $last14Days,
                '30' => $last30Days,
                '90' => $last90Days,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in MediaController@statistics: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Convert image to WebP using native PHP GD
     */
    private function convertToWebP($file, $destinationPath)
    {
        try {
            // Check if GD extension is loaded
            if (!extension_loaded('gd')) {
                Log::warning('GD extension not available, storing original file');
                return $file->store($destinationPath, 'public');
            }

            // Generate filename
            $filename = time() . '_' . uniqid() . '.webp';
            $storagePath = storage_path('app/public/' . $destinationPath);
            
            // Create directory if not exists
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }
            
            $fullPath = $storagePath . '/' . $filename;

            // Get image info
            $imageInfo = @getimagesize($file->path());
            if (!$imageInfo) {
                Log::warning('Cannot read image info, storing original file');
                return $file->store($destinationPath, 'public');
            }

            $mimeType = $imageInfo['mime'];

            // Create image resource
            $image = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($file->path());
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($file->path());
                    break;
                case 'image/gif':
                    $image = @imagecreatefromgif($file->path());
                    break;
                default:
                    Log::warning('Unsupported image type: ' . $mimeType);
                    return $file->store($destinationPath, 'public');
            }

            if (!$image) {
                Log::warning('Failed to create image resource, storing original file');
                return $file->store($destinationPath, 'public');
            }

            // Get dimensions
            $width = imagesx($image);
            $height = imagesy($image);

            // Resize if larger than 1920px
            $maxWidth = 1920;
            if ($width > $maxWidth) {
                $ratio = $maxWidth / $width;
                $newWidth = $maxWidth;
                $newHeight = (int)($height * $ratio);
                
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }

            // Convert to WebP
            $success = imagewebp($image, $fullPath, 85);
            imagedestroy($image);

            if (!$success) {
                Log::warning('Failed to save WebP, storing original file');
                return $file->store($destinationPath, 'public');
            }

            Log::info('Image converted to WebP successfully: ' . $filename);
            return $destinationPath . '/' . $filename;

        } catch (\Exception $e) {
            Log::error('WebP conversion error: ' . $e->getMessage());
            Log::info('Falling back to original file storage');
            return $file->store($destinationPath, 'public');
        }
    }

    /**
     * Store new media
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'category' => 'required|string',
                'name' => 'nullable|string',
                'video_url' => 'nullable|string',
                'file' => 'nullable|file|mimes:jpg,jpeg,png,gif|max:10240',
            ]);

            $path = null;
            $mediaName = $validated['name'] ?? null;

            if ($request->hasFile('file')) {
                $path = $this->convertToWebP($request->file('file'), 'media');
                
                // ✅ Auto-generate name dari filename jika tidak ada
                if (!$mediaName) {
                    $mediaName = pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME);
                }
            } elseif ($validated['video_url']) {
                // ✅ Auto-generate name untuk video jika tidak ada
                if (!$mediaName) {
                    $mediaName = 'Video - ' . now()->format('d/m/Y H:i');
                }
            }

            // ✅ Fallback name
            if (!$mediaName) {
                $mediaName = 'Media - ' . now()->format('d/m/Y H:i');
            }

            $media = Media::create([
                'name' => $mediaName,
                'category' => $validated['category'],
                'image_url' => $path,
                'video_url' => $validated['video_url'] ?? null,
            ]);

            // ✅ Build response dengan URL lengkap
            $imageUrl = null;
            if ($media->image_url) {
                $imageUrl = url('storage/' . $media->image_url);
            } elseif ($media->video_url) {
                $imageUrl = $media->video_url;
            }

            return response()->json([
                'message' => 'Media uploaded successfully',
                'data' => [
                    'id' => $media->id,
                    'timeStamp' => $media->created_at->format('Y-m-d'),
                    'date' => $media->created_at->format('d/m/Y'),
                    'imageUrl' => $imageUrl, // ✅ camelCase
                    'videoUrl' => $media->video_url, // ✅ camelCase
                    'name' => $media->name,
                    'category' => $media->category,
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error in MediaController@store: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'message' => 'Failed to upload media',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update media
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'category' => 'nullable|string',
                'name' => 'nullable|string',
                'video_url' => 'nullable|string',
                'file' => 'nullable|file|mimes:jpg,jpeg,png,gif|max:10240',
            ]);

            $media = Media::findOrFail($id);

            if ($request->hasFile('file')) {
                // Delete old file
                if ($media->image_url && Storage::disk('public')->exists($media->image_url)) {
                    Storage::disk('public')->delete($media->image_url);
                }

                $path = $this->convertToWebP($request->file('file'), 'media');
                $media->image_url = $path;
            }

            if (isset($validated['name'])) $media->name = $validated['name'];
            if (isset($validated['category'])) $media->category = $validated['category'];
            if (isset($validated['video_url'])) $media->video_url = $validated['video_url'];
            
            $media->save();

            $imageUrl = null;
            if ($media->image_url) {
                $imageUrl = url('storage/' . $media->image_url);
            } elseif ($media->video_url) {
                $imageUrl = $media->video_url;
            }

            return response()->json([
                'message' => 'Media updated successfully',
                'data' => [
                    'id' => $media->id,
                    'timeStamp' => $media->created_at->format('Y-m-d'),
                    'date' => $media->created_at->format('d/m/Y'),
                    'imageUrl' => $imageUrl, // ✅ camelCase
                    'videoUrl' => $media->video_url, // ✅ camelCase
                    'name' => $media->name,
                    'category' => $media->category,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in MediaController@update: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update media',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete media
     */
    public function destroy($id)
    {
        try {
            $media = Media::findOrFail($id);

            if ($media->image_url && Storage::disk('public')->exists($media->image_url)) {
                Storage::disk('public')->delete($media->image_url);
            }

            $media->delete();

            return response()->json(['message' => 'Media deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error in MediaController@destroy: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete media',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
