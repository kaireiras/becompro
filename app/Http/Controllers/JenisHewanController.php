<?php

namespace App\Http\Controllers;

use App\Models\JenisHewan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JenisHewanController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = JenisHewan::with('pasien'); //  Load relationship
            
            //  Filter by pasien if provided
            if ($request->has('id_pasien')) {
                $query->where('id_pasien', $request->id_pasien);
            }
            
            $jenisHewans = $query->get();
            
            Log::info('📦 Jenis Hewan fetched:', ['count' => $jenisHewans->count()]);
            
            return response()->json($jenisHewans);
        } catch (\Exception $e) {
            Log::error('Error fetching jenis hewan:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Gagal memuat data jenis hewan'], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_jenis' => 'required|string|max:255',
            'id_pasien' => 'required|exists:users,id',
        ]);

        try {
            //  Check: Apakah pasien ini sudah punya jenis hewan dengan nama yang sama?
            $exists = JenisHewan::where('id_pasien', $validated['id_pasien'])
                ->where('nama_jenis', $validated['nama_jenis'])
                ->exists();
            
            if ($exists) {
                return response()->json([
                    'message' => "Anda sudah memiliki jenis hewan '{$validated['nama_jenis']}'"
                ], 422);
            }

            $jenisHewan = JenisHewan::create($validated);

            Log::info(' Jenis Hewan created:', ['id' => $jenisHewan->id_jenisHewan]);

            return response()->json([
                'message' => 'Jenis hewan berhasil ditambahkan',
                'data' => $jenisHewan->load('pasien')
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating jenis hewan:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Gagal menambahkan jenis hewan'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'nama_jenis' => 'required|string|max:255',
        ]);

        try {
            $jenisHewan = JenisHewan::findOrFail($id);
            
            //  Security: Pastikan pasien hanya bisa edit jenis hewan miliknya
            if ($request->user()->role === 'patient' && $jenisHewan->id_pasien != $request->user()->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $jenisHewan->update($validated);

            Log::info(' Jenis Hewan updated:', ['id' => $id]);

            return response()->json([
                'message' => 'Jenis hewan berhasil diupdate',
                'data' => $jenisHewan->load('pasien')
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating jenis hewan:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Gagal mengupdate jenis hewan'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $jenisHewan = JenisHewan::findOrFail($id);
            
            //  Check: Apakah masih ada hewan yang menggunakan jenis ini?
            if ($jenisHewan->hewans()->count() > 0) {
                return response()->json([
                    'message' => 'Tidak dapat menghapus jenis hewan yang masih memiliki data hewan'
                ], 422);
            }

            $jenisHewan->delete();

            Log::info(' Jenis Hewan deleted:', ['id' => $id]);

            return response()->json([
                'message' => 'Jenis hewan berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting jenis hewan:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Gagal menghapus jenis hewan'], 500);
        }
    }
}