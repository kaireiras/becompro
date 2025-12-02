<?php

namespace App\Http\Controllers;

use App\Models\JenisHewan;
use Illuminate\Http\Request;

class JenisHewanController extends Controller
{
    public function index()
    {
       // ✅ Load relasi hewans dengan pasien
        $jenisHewans = JenisHewan::with(['hewans.pasien'])->get();
        
        // ✅ Transform untuk frontend
        $formatted = $jenisHewans->map(function($jenis) {
            return [
                'id' => $jenis->id_jenisHewan,
                'nama_jenis' => $jenis->nama_jenis,
                'pemilik' => $jenis->hewans->map(function($hewan) {
                    return [
                        'id_pemilik' => $hewan->pasien->id,
                        'nama_pemilik' => $hewan->pasien->username,
                        'id_hewan' => $hewan->id_hewan,
                        'nama_hewan' => $hewan->nama_hewan,
                    ];
                })->unique('id_pemilik')->values()
            ];
        });
        
        return response()->json($formatted);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama_jenis' => 'required|required:jenis_hewan',
        ]);

        $jenisHewan = JenisHewan::create($data);
        return response()->json($jenisHewan, 201);
    }

    public function show(JenisHewan $jenisHewan)
    {
        return response()->json($jenisHewan->load('hewans'));
    }

    public function update(Request $request, JenisHewan $jenisHewan)
    {
        $data = $request->validate([
            'nama_jenis' => 'required|unique:jenis_hewan,nama_jenis,' . $jenisHewan->id_jenisHewan . ',id_jenisHewan',
        ]);

        $jenisHewan->update($data);
        return response()->json($jenisHewan);
    }

    public function destroy(JenisHewan $jenisHewan)
    {
        if ($jenisHewan->hewans()->count() > 0) {
            return response()->json([
                'message' => 'Tidak dapat menghapus jenis hewan yang masih digunakan'
            ], 422);
        }
        
        $jenisHewan->delete();
        return response()->json(['message' => 'Jenis Hewan deleted']);
    }
}