<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SystemInfo;
use App\Models\SocialMedia;

class SystemInfoController extends Controller
{
    public function index(){
        try{
            $systemInfo = SystemInfo::first();
            if(!$systemInfo){
                return response()->json([
                    'error' => 'system info not found'
                ], 404);
            }

            $socialMedia = SocialMedia::orderBy('order')->get();
            return response()->json([
                'systemInfo' => $systemInfo->formatted_data,
                'socialMedia' => $socialMedia->map(function($item) {
                    return [
                        'id' => $item->id,
                        'platform' => $item->platform,
                        'url' => $item->url,
                        'order' => $item->order,
                    ];
                }),
            ]);
        } catch (\Exception $e){
            \Log::error('error fetching system'. $e->getMessage());
            return response()->json(['error'=> $e->getMessage()], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'clinicName' => 'sometimes|string|max:255',
                'address' => 'sometimes|string',
                'phone' => 'sometimes|string|max:20',
                'email' => 'sometimes|email|max:255',
                'fotoCard' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
                'deskripsiHero' => 'sometimes|string',
                'judulVideoEdukasi' => 'sometimes|string|max:255',
                'deskripsiVideoEdukasi' => 'sometimes|string',
                'aboutUs' => 'sometimes|string',
                'judulLayananTersedia' => 'sometimes|string|max:255',
                'judulPromoTersedia' => 'sometimes|string|max:255',
                'deskripsiArtikel' => 'sometimes|string',
                'judulFooter' => 'sometimes|string|max:255',
                'operatingHours' => 'sometimes|string|max:255',
            ]);

            // Convert camelCase ke snake_case
            $data = [
                'clinic_name' => $validated['clinicName'] ?? null,
                'address' => $validated['address'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'foto_card' => $validated['fotoCard'] ?? null,
                'deskripsi_hero' => $validated['deskripsiHero'] ?? null,
                'judul_video_edukasi' => $validated['judulVideoEdukasi'] ?? null,
                'deskripsi_video_edukasi' => $validated['deskripsiVideoEdukasi'] ?? null,
                'about_us' => $validated['aboutUs'] ?? null,
                'judul_layanan_tersedia' => $validated['judulLayananTersedia'] ?? null,
                'judul_promo_tersedia' => $validated['judulPromoTersedia'] ?? null,
                'deskripsi_artikel' => $validated['deskripsiArtikel'] ?? null,
                'judul_footer' => $validated['judulFooter'] ?? null,
                'operating_hours' => $validated['operatingHours'] ?? null,
            ];

            // Remove null values
            $data = array_filter($data, fn($value) => $value !== null);

            $systemInfo = SystemInfo::first();

            if (!$systemInfo) {
                // Create jika belum ada
                $systemInfo = SystemInfo::create($data);
            } else {
                // Update jika sudah ada
                $systemInfo->update($data);
            }

            return response()->json([
                'message' => 'System info berhasil diupdate',
                'data' => $systemInfo->formatted_data,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error updating system info: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to update system info',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getSocialMedia()
    {
        $socialMedia = SocialMedia::orderBy('order')->get();
        return response()->json($socialMedia);
    }

    // ✅ Add Social Media
    public function storeSocialMedia(Request $request)
    {
        try {
            $validated = $request->validate([
                'platform' => 'required|in:facebook,instagram,twitter,youtube',
                'url' => 'required|url|max:255',
                'order' => 'nullable|integer',
            ]);

            // Set order otomatis jika tidak ada
            if (!isset($validated['order'])) {
                $maxOrder = SocialMedia::max('order') ?? 0;
                $validated['order'] = $maxOrder + 1;
            }

            $socialMedia = SocialMedia::create($validated);

            return response()->json([
                'message' => 'Social media berhasil ditambahkan',
                'data' => $socialMedia,
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error adding social media: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to add social media',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ✅ Update Social Media - Parameter disesuaikan dengan route {social_media}
    public function updateSocialMedia(Request $request, $social_media)
    {
        try {
        $socialMediaModel = SocialMedia::findOrFail($social_media);

        // ✅ Lebih flexible validation
        $validated = $request->validate([
            'platform' => 'sometimes|in:facebook,instagram,twitter,youtube',
            'url' => 'sometimes|string|max:500', // ✅ Ganti 'url' jadi 'string'
            'order' => 'sometimes|integer',
        ]);

        // ✅ Manual validate URL format
        if (isset($validated['url'])) {
            if (!filter_var($validated['url'], FILTER_VALIDATE_URL)) {
                return response()->json([
                    'error' => 'Invalid URL format',
                    'message' => 'URL harus berformat valid (contoh: https://instagram.com/...)'
                ], 422);
            }
        }

        $socialMediaModel->update($validated);

        return response()->json([
            'message' => 'Social media berhasil diupdate',
            'data' => $socialMediaModel,
        ]);

    } catch (\Exception $e) {
        \Log::error('Error updating social media: ' . $e->getMessage());
        return response()->json([
            'error' => 'Failed to update social media',
            'message' => $e->getMessage()
        ], 500);
    }
    }

    // ✅ Delete Social Media - Parameter disesuaikan dengan route {social_media}
    public function deleteSocialMedia($social_media)
    {
        try {
            $socialMediaModel = SocialMedia::findOrFail($social_media);
            $socialMediaModel->delete();
            return response()->json(['message' => 'Social media berhasil dihapus']);
        } catch (\Exception $e) {
            \Log::error('Error deleting social media: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
