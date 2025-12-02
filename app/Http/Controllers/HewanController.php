<?php

namespace App\Http\Controllers;

use App\Models\Hewan;
use App\Models\JenisHewan;
use Illuminate\Http\Request;

class HewanController extends Controller
{
    public function index()
    {
        return response()->json(Hewan::with(['pasien', 'jenisHewan'])->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_pasien' => 'required|exists:users,id',
            'id_jenisHewan' => 'required|exists:jenis_hewan,id_jenisHewan',
            'nama_hewan' => 'required',
            'tanggal_lahir_hewan' => 'nullable|date',
        ]);

        $hewan = Hewan::create($data);
        return response()->json($hewan->load(['pasien', 'jenisHewan']), 201);
    }

    public function show(Hewan $hewan)
    {
        return response()->json($hewan->load(['pasien', 'jenisHewan']));
    }

    public function update(Request $request, Hewan $hewan)
    {
        $data = $request->validate([
            'id_pasien' => 'required|exists:users,id',
            'id_jenisHewan' => 'required|exists:jenis_hewan,id_jenisHewan',
            'nama_hewan' => 'required',
            'tanggal_lahir_hewan' => 'nullable|date',
        ]);

        $hewan->update($data);
        return response()->json($hewan->load(['pasien', 'jenisHewan']));
    }

    public function destroy(Hewan $hewan)
    {
        $hewan->delete();
        return response()->json(['message' => 'Hewan deleted']);
    }
}