<?php

namespace App\Http\Controllers;

use App\Models\ReminderLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Models\ReminderVaksinasi;
use Illuminate\Support\Facades\Log;
use App\Models\Notification;

class ReminderVaksinasiController extends Controller
{
    public function sendScheduledVaksinasi(){
        try{
            $now = Carbon::now();
            $reminders = [];

            $upcomingVaksinasi = ReminderVaksinasi::with(['hewan.pasien', 'jenisVaksin'])
                ->whereBetween('tanggal_vaksin', [
                    $now->copy()->startOfDay(),
                    $now->copy()->addDays(3)->endOfDay()
                ])
                ->get();
            
            Log::info('checking vacctination reminders',[
                'date'=>$now->format('Y-m-d'),
                'found'=>$upcomingVaksinasi->count()
            ]);

            foreach ($upcomingVaksinasi as $vaksinasi){
                $daysUntil = $now->startOfDay()->diffInDays($vaksinasi->tanggal_vaksin, false);

                $reminderType = null;
                if($daysUntil==3){
                    $reminderType = '3_days_sebelum';
                } elseif ($daysUntil==1){
                    $reminderType = '1_day_before';
                } elseif ($daysUntil==0){
                    $reminderType = 'same_day';
                }
                if(!$reminderType)continue;

                $alreadySent = ReminderLog::where('id_vaksinasi', $vaksinasi->id_vaksinasi)
                    ->where('reminder_type', $reminderType)
                    ->where('status', 'sent')
                    ->exists();
                
                if($alreadySent){
                    Log::info('reminder already sent', [
                        'id_vaksinasi'=> $vaksinasi->id_vaksinasi,
                        'type'=> $reminderType
                    ]);
                    continue;
                }

                $sent = $this->sendWhatsAppReminder($vaksinasi, $reminderType);

                if($sent){
                    ReminderLog::create([
                        'id_vaksinasi'=>$vaksinasi->id_vaksinasi,
                        'reminder_type'=>$reminderType,
                        'sent_at'=> now(),
                        'status'=>'sent',
                        'phone_number'=> $vaksinasi->hewan->pasien->phone_number ?? null,
                    ]);

                    Notification::create([
                        'id_vaksinasi' => $vaksinasi->id_vaksinasi,
                        'id_pasien' => $vaksinasi->id_pasien,
                        'recipient' => $vaksinasi->hewan->pasien->phone_number ?? 'N/A',
                        'channel' => 'wa',
                        'waktu_kirim' => now(),
                        'tipe' => 'vaksinasi',
                        'status' => 'sent',
                        'reminder_type' => $reminderType,
                        'error_message' => null,             
                    ]);

                    if($reminderType === 'same_day'){
                        $interval = $vaksinasi->jenisVaksin->interval ?? null;
                        if($interval){
                            ReminderVaksinasi::create([
                                'id_hewan'        => $vaksinasi->id_hewan,
                                'id_pasien'       => $vaksinasi->id_pasien,
                                'id_jenis_vaksin' => $vaksinasi->id_jenis_vaksin,
                                'tanggal_vaksin'  => Carbon::parse($vaksinasi->tanggal_vaksin)
                                                        ->addMonths($interval)->toDateString(),
                            ]);
                        }
                    }

                    $reminders[] = [
                        'id_vaksinasi' => $vaksinasi->id_vaksinasi,
                        'nama_vaksin' => $vaksinasi->jenisVaksin->nama_vaksin ?? '-',
                        'type' => $reminderType,
                        'sent_to' => $vaksinasi->hewan->pasien->phone_number ?? 'N/A',
                    ];
                }
            }
            Log::info('✅ Vaccination reminders sent', [
                'count' => count($reminders)
            ]);

            return response()->json([
                'message' => 'Vaccination reminders sent successfully',
                'sent_count' => count($reminders),
                'details' => $reminders
            ]);
        } catch(\Exception $e){
            Log::error('error sending vaccination reinders: ' . $e->getMessage());
            return response()->json([
                'message'=>'failed to send reminders',
                'error'=> $e->getMessage()
            ], 500);        
        }
    }

    private function sendWhatsAppReminder($vaksinasi, $reminderType){
        try{
            $pasien = $vaksinasi->hewan->pasien;

            if(!$pasien || !$pasien->phone_number){
                Log::warning('no phone number found', [
                    'id_vaksinasi'=> $vaksinasi->id_vaksinasi,
                    'hewan_id'=> $vaksinasi->id_hewan
                ]);
                return false;
            }
            $phoneNumber = $this->formatPhoneNumber($pasien->phone_number);
            if(!$phoneNumber){
                Log::warning('invalid phone number', [
                    'id_vaksinasi'=>$vaksinasi->id_vaksinasi,
                    'phone'=>$pasien->phone_number
                ]);
                return false;
            }

            $message = $this->generateMessage($vaksinasi, $reminderType);

            $response = Http::withHeaders([
                'apikey'=> env('WHATSAPP_API_KEY'),
                'Content-Type'=> 'application/json',
            ])->post(env('WHATSAPP_API_URL'), [
                'number'=>$phoneNumber,
                'text'=>$message,            
            ]);

            if($response->successful()){
                Log::info('whatsapp sent', [
                    'id_vaksinasi'=> $vaksinasi->id_vaksinasi,
                    'number'=> $phoneNumber,
                    'text'=> $reminderType
                ]);
                return true;
            }else{
                Log::error('whatsapp api failed', [
                    'status'=> $response->status(),
                    'body'=>$response->body()
                ]);
                return false;
            }
        } catch(\Exception $e){
            Log::error('error sending whatsapp: ' . $e->getMessage());
            return false;    
        }
    }

    private function generateMessage($vaksinasi, $reminderType)
    {
        $petName = $vaksinasi->hewan->nama_hewan ?? 'Hewan Anda';
        $ownerName = $vaksinasi->hewan->pasien->name ?? $vaksinasi->hewan->pasien->username ?? 'Pemilik';
        $jenisVaksin = $vaksinasi->jenisVaksin->nama_vaksin ?? '-';
        $date = Carbon::parse($vaksinasi->tanggal_vaksin)->format('d/m/Y');

        $messages = [
            '3_days_sebelum' => "💉 *Reminder Vaksinasi*\n\n" .
                "Halo {$ownerName}! 👋\n\n" .
                "Ini adalah pengingat bahwa {$petName} memiliki jadwal vaksinasi *{$jenisVaksin}* dalam *3 hari*.\n\n" .
                "📅 Tanggal: {$date}\n" .
                "🐾 Hewan: {$petName}\n" .
                "💉 Jenis Vaksin: {$jenisVaksin}\n\n" .
                "Jangan lupa untuk datang ya! Kesehatan hewan peliharaan Anda adalah prioritas kami. 🏥❤️",

            '1_day_before' => "💉 *Reminder Vaksinasi*\n\n" .
                "Halo {$ownerName}! 👋\n\n" .
                "Ini adalah pengingat bahwa {$petName} memiliki jadwal vaksinasi *{$jenisVaksin}* *besok*.\n\n" .
                "📅 Tanggal: {$date}\n" .
                "🐾 Hewan: {$petName}\n" .
                "💉 Jenis Vaksin: {$jenisVaksin}\n\n" .
                "Pastikan {$petName} dalam kondisi sehat dan tidak sedang sakit ya! 🙏",

            'same_day' => "💉 *Reminder Vaksinasi*\n\n" .
                "Halo {$ownerName}! 👋\n\n" .
                "Ini adalah pengingat bahwa {$petName} memiliki jadwal vaksinasi *{$jenisVaksin}* *hari ini*.\n\n" .
                "🐾 Hewan: {$petName}\n" .
                "💉 Jenis Vaksin: {$jenisVaksin}\n\n" .
                "Kami tunggu kedatangan Anda di klinik! Sampai jumpa! 🏥✨",
        ];

        return $messages[$reminderType] ?? $messages['1_day_before'];
    }

        private function formatPhoneNumber($phone)
    {
        // Remove spaces, dashes, and other characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert 08xx to 628xx
        if (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1);
        }

        // Validate length
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            return null;
        }

        return $phone;
    }

    /**
     * ✅ Manual trigger reminder (untuk testing)
     */
    public function sendManualReminder(Request $request)
    {
        $validated = $request->validate([
            'id_vaksinasi' => 'required|exists:reminder_vaksinasi,id_vaksinasi',
            'reminder_type' => 'required|in:3_days_sebelum,1_day_before,same_day',
        ]);

        try {
            $vaksinasi = ReminderVaksinasi::with(['hewan.pasien', 'jenisVaksin'])
                ->findOrFail($validated['id_vaksinasi']);

            $sent = $this->sendWhatsAppReminder($vaksinasi, $validated['reminder_type']);

            if ($sent) {
                ReminderLog::create([
                    'id_vaksinasi' => $vaksinasi->id_vaksinasi,
                    'reminder_type' => $validated['reminder_type'],
                    'sent_at' => now(),
                    'status' => 'sent',
                    'phone_number' => $vaksinasi->hewan->pasien->phone_number ?? null,
                    'is_manual' => true,
                ]);

                return response()->json([
                    'message' => 'Reminder sent successfully',
                    'id_vaksinasi' => $vaksinasi->id_vaksinasi
                ]);
            } else {
                return response()->json([
                    'message' => 'Failed to send reminder'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error sending manual reminder: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send reminder',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Get reminder logs
     */
    public function getReminderLogs(Request $request)
    {
        try {
            $query = ReminderLog::with(['vaksinasi.hewan.pasien'])
                ->orderBy('sent_at', 'desc');

            if ($request->has('id_vaksinasi')) {
                $query->where('id_vaksinasi', $request->id_vaksinasi);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $logs = $query->paginate(20);

            return response()->json($logs);

        } catch (\Exception $e) {
            Log::error('Error fetching reminder logs: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ CRUD Methods untuk Reminder Vaksinasi
     */

    // Get all vaccination reminders
    public function index(Request $request)
    {
        try {
            $query = ReminderVaksinasi::with(['hewan.pasien']);

            // Filter by hewan
            if ($request->has('id_hewan')) {
                $query->where('id_hewan', $request->id_hewan);
            }

            // Filter by date range
            if ($request->has('from_date')) {
                $query->where('tanggal_vaksin', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('tanggal_vaksin', '<=', $request->to_date);
            }

            // Order by date
            $vaksinasi = $query->orderBy('tanggal_vaksin', 'asc')->get();

            return response()->json($vaksinasi);

        } catch (\Exception $e) {
            Log::error('Error fetching vaksinasi: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch vaksinasi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Create new vaccination reminder
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_hewan' => 'required|exists:hewan,id_hewan',
            'id_jenis_vaksin' => 'required|integer|exists:jenis_vaksin,id_vaksinasi',
            'tanggal_vaksin' => 'required|date|after_or_equal:today',
        ]);

        try {
            $hewan = \App\Models\Hewan::findOrFail($validated['id_hewan']);
            $validated['id_pasien'] = $hewan->id_pasien;

            $vaksinasi = ReminderVaksinasi::create($validated);

            Log::info('✅ Vaksinasi reminder created', [
                'id' => $vaksinasi->id_vaksinasi,
                'hewan' => $validated['id_hewan']
            ]);

            return response()->json([
                'message' => 'Vaccination reminder created successfully',
                'data' => $vaksinasi->load('hewan.pasien')
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating vaksinasi: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create vaksinasi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Update vaccination reminder
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'id_hewan' => 'sometimes|exists:hewan,id_hewan',
            'id_jenis_vaksin' => 'sometimes|integer|exists:jenis_vaksin,id_vaksinasi',
            'tanggal_vaksin' => 'sometimes|date|after_or_equal:today',
            'status' => 'sometimes|string|in:Selesai,Dijadwalkan,Terkirim,Terlewat',
            'tanggal_vaksin_aktual' => 'sometimes|nullable|date', // Kapan vaksin sebenarnya dilakukan
            'dilakukan_oleh' => 'sometimes|nullable|string|max:255', // Nama dokter/admin
            'catatan' => 'sometimes|nullable|string', // Catatan vaksinasi
            'jadwal_vaksin_berikutnya' => 'sometimes|nullable|date', // Untuk manual scheduling
            'tipe_jadwal' => 'sometimes|nullable|string|in:automatic,manual,final', // Tipe scheduling
        ]);

        if (($validated['tipe_jadwal'] ?? null) === 'final') {
            $validated['status'] = 'Selesai';
            $validated['jadwal_vaksin_berikutnya'] = null;
        }

        try {
            $vaksinasi = ReminderVaksinasi::with(['hewan', 'jenisVaksin'])
                ->findOrFail($id);

            // Handle saat admin selesai vaksinasi (mark as complete)
            if (isset($validated['tipe_jadwal']) && $validated['tipe_jadwal'] !== null) {
                
                // Set vaksinasi current sebagai Selesai
                $validated['status'] = 'Selesai';
                
                // ============================================
                // FLOW 1: FINAL (tidak perlu booster lagi)
                // ============================================
                if ($validated['tipe_jadwal'] === 'final') {
                    $validated['jadwal_vaksin_berikutnya'] = null;
                    
                    Log::info('✅ Vaksinasi marked as FINAL (no booster needed)', [
                        'id_vaksinasi' => $id,
                        'nama_vaksin' => $vaksinasi->jenisVaksin->nama_vaksin ?? '-',
                        'hewan_id' => $vaksinasi->id_hewan
                    ]);
                }
                
                // ============================================
                // FLOW 2 & 3: AUTOMATIC atau MANUAL (buat jadwal berikutnya)
                // ============================================
                else if (in_array($validated['tipe_jadwal'], ['automatic', 'manual'])) {
                    
                    // Validasi tanggal_vaksin_aktual wajib ada
                    if (!isset($validated['tanggal_vaksin_aktual']) || 
                        is_null($validated['tanggal_vaksin_aktual'])) {
                        return response()->json([
                            'message' => 'Tanggal vaksin aktual harus diisi',
                            'error' => 'tanggal_vaksin_aktual_required'
                        ], 422);
                    }
                    
                    $tanggalAktual = Carbon::parse($validated['tanggal_vaksin_aktual']);
                    $nextVaksinDate = null;
                    
                    // --------
                    // AUTOMATIC: Hitung jadwal berikutnya dari interval vaksin
                    // --------
                    if ($validated['tipe_jadwal'] === 'automatic') {
                        $interval = $vaksinasi->jenisVaksin->interval ?? 12;
                        $nextVaksinDate = $tanggalAktual->copy()->addMonths($interval);
                        $validated['jadwal_vaksin_berikutnya'] = $nextVaksinDate->format('Y-m-d');
                        
                        Log::info('✅ Vaksinasi marked as AUTOMATIC', [
                            'id_vaksinasi' => $id,
                            'interval_bulan' => $interval,
                            'next_schedule' => $nextVaksinDate->format('Y-m-d'),
                            'oleh' => $validated['dilakukan_oleh'] ?? 'N/A'
                        ]);
                    }
                    
                    // --------
                    // MANUAL: User tentukan sendiri kapan jadwal berikutnya
                    // --------
                    else if ($validated['tipe_jadwal'] === 'manual') {
                        
                        if (!isset($validated['jadwal_vaksin_berikutnya']) || 
                            is_null($validated['jadwal_vaksin_berikutnya'])) {
                            return response()->json([
                                'message' => 'Tanggal vaksin berikutnya harus diisi untuk manual scheduling',
                                'error' => 'jadwal_vaksin_berikutnya_required'
                            ], 422);
                        }
                        
                        $nextVaksinDate = Carbon::parse($validated['jadwal_vaksin_berikutnya']);
                        
                        Log::info('✅ Vaksinasi marked as MANUAL with custom date', [
                            'id_vaksinasi' => $id,
                            'custom_next_date' => $nextVaksinDate->format('Y-m-d'),
                            'oleh' => $validated['dilakukan_oleh'] ?? 'N/A'
                        ]);
                    }
                    
                    // Create reminder vaksinasi berikutnya jika ada jadwal
                    if ($nextVaksinDate) {
                        try {
                            ReminderVaksinasi::create([
                                'id_hewan' => $vaksinasi->id_hewan,
                                'id_pasien' => $vaksinasi->id_pasien,
                                'id_jenis_vaksin' => $vaksinasi->id_jenis_vaksin,
                                'tanggal_vaksin' => $nextVaksinDate->format('Y-m-d'),
                                'status' => 'Dijadwalkan',
                            ]);
                            
                            Log::info('✅ Next reminder vaksinasi created', [
                                'id_hewan' => $vaksinasi->id_hewan,
                                'tanggal_vaksin' => $nextVaksinDate->format('Y-m-d'),
                                'jenis_vaksin' => $vaksinasi->jenisVaksin->nama_vaksin ?? '-'
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error creating next reminder vaksinasi: ' . $e->getMessage());
                            // Jangan return error, vaksinasi current tetap ter-update
                        }
                    }
                }
            }

            // Update vaksinasi record
            $vaksinasi->update($validated);

            // ============================================
            // Hitung vaccination statistics untuk hewan
            // ============================================
            $stats = [
                'total_scheduled' => ReminderVaksinasi::where('id_hewan', $vaksinasi->id_hewan)->count(),
                'completed' => ReminderVaksinasi::where('id_hewan', $vaksinasi->id_hewan)
                    ->where('status', 'Selesai')->count(),
                'upcoming' => ReminderVaksinasi::where('id_hewan', $vaksinasi->id_hewan)
                    ->where('status', 'Dijadwalkan')
                    ->where('tanggal_vaksin', '>', now())
                    ->count(),
                'overdue' => ReminderVaksinasi::where('id_hewan', $vaksinasi->id_hewan)
                    ->where('status', 'Dijadwalkan')
                    ->where('tanggal_vaksin', '<', now())
                    ->count(),
            ];

            Log::info('✅ Vaksinasi reminder updated successfully', ['id' => $id]);

            return response()->json([
                'message' => 'Vaccination reminder updated successfully',
                'data' => $vaksinasi->load('hewan.pasien', 'jenisVaksin'),
                'vaccination_stats' => $stats, // Frontend tampilkan "Selesai: 3/5"
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating vaksinasi: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update vaksinasi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Delete vaccination reminder
    public function destroy($id)
    {
        try {
            $vaksinasi = ReminderVaksinasi::findOrFail($id);
            $vaksinasi->delete();

            Log::info('✅ Vaksinasi reminder deleted', ['id' => $id]);

            return response()->json([
                'message' => 'Vaccination reminder deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting vaksinasi: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete vaksinasi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}



