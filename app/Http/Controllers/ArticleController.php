<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ArticleController extends Controller
{
    /**
     * Convert image to WebP using native PHP GD
     */
    private function convertToWebP($file, $destinationPath)
    {
        try {
            // check if GD extension is loaded
            if (!extension_loaded('gd')) {
                Log::warning('GD extension not available, storing original file');
                return $file->store($destinationPath, 'public');
            }

            // generate filename
            $filename = time() . '_' . uniqid() . '.webp';
            $storagePath = storage_path('app/public/' . $destinationPath);
            
            // create directory if not exists
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }
            
            $fullPath = $storagePath . '/' . $filename;

            // get image info
            $imageInfo = @getimagesize($file->path());
            if (!$imageInfo) {
                Log::warning('Cannot read image info, storing original file');
                return $file->store($destinationPath, 'public');
            }

            $mimeType = $imageInfo['mime'];

            // create image resource
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

            // get dimensions
            $width = imagesx($image);
            $height = imagesy($image);

            // resize if larger than 1920px
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

            // convert to WebP
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
     * Add image to Media Gallery
     */
    private function addToMediaGallery($imagePath, $title)
    {
        try {
            Media::create([
                'name' => 'Article: ' . $title,
                'category' => 'Foto',
                'image_url' => $imagePath,
                'video_url' => null,
            ]);
            Log::info('Image added to media gallery: ' . $imagePath);
        } catch (\Exception $e) {
            Log::error('Failed to add image to media gallery: ' . $e->getMessage());
        }
    }

    public function index()
    {

        $articles = Article::orderBy('created_at', 'desc')->get();

        $formattedArticles = $articles->map(function ($article) {
            $imageUrl = null;
        
            if ($article->image) {
                if (str_starts_with($article->image, 'http')) {
                    $imageUrl = $article->image;
                } else {
                    $imageUrl = url('storage/' . $article->image);
                }
            }

            return [
                'id' => $article->id,
                'title' => $article->title,
                'content' => $article->content,
                'category' => $article->category,
                'status' =>$article->status,
                'imageUrl' => $imageUrl,
                'created_at' => $article->created_at,
                'updated_at' => $article->updated_at,
            ];
        });

        return response()->json($formattedArticles);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required',
            'category' => 'required',
            'content' => 'required',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:10240',
            'status' => 'required',
        ]);

        if ($request->hasFile('image')) {
            // convert to WebP and store
            $imagePath = $this->convertToWebP($request->file('image'), 'articles');
            $data['image'] = $imagePath;

            // add to Media Gallery
            $this->addToMediaGallery($imagePath, $data['title']);
        }

        $article = Article::create($data);
        return response()->json($article);
    }

    public function update(Request $request, Article $article)
    {
        $data = $request->validate([
            'title' => 'required',
            'category' => 'required',
            'content' => 'required',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:10240',
            'status' => 'required',
        ]);

        if ($request->hasFile('image')) {
            // delete old image
            if ($article->image && Storage::disk('public')->exists($article->image)) {
                Storage::disk('public')->delete($article->image);
            }

            // convert to WebP and store
            $imagePath = $this->convertToWebP($request->file('image'), 'articles');
            $data['image'] = $imagePath;

            // add to Media Gallery
            $this->addToMediaGallery($imagePath, $data['title']);
        }

        $article->update($data);
        return response()->json($article);
    }

    public function destroy(Article $article)
    {
        // Delete image from storage
        if ($article->image && Storage::disk('public')->exists($article->image)) {
            Storage::disk('public')->delete($article->image);
        }

        $article->delete();
        return response()->json(['message' => 'deleted']);
    }
}
