<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\ReminderVaksinasiController;
use Illuminate\Support\Facades\Log;


class SendVaksinReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-vaksin-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
            $this->info('🔔 Starting vaccination reminder process...');
        
        try {
            // ✅ Call controller method
            $controller = app(ReminderVaksinasiController::class);
            $result = $controller->sendScheduledVaksinasi();
            
            // ✅ Get response data
            $data = $result->getData();
            
            $this->info("✅ Reminders sent successfully!");
            $this->info("📊 Total reminders sent: {$data->sent_count}");
            
            // ✅ Show details if any
            if (!empty($data->details)) {
                $this->table(
                    ['Vaksinasi ID', 'Jenis Vaksin', 'Type', 'Sent To'],
                    collect($data->details)->map(fn($item) => [
                        $item['vaksinasi_id'],
                        $item['jenis_vaksin'],
                        $item['type'],
                        $item['sent_to']
                    ])
                );
            } else {
                $this->warn('⚠️ No reminders to send today.');
            }
            
            Log::info('✅ Vaccination reminders sent via command', [
                'count' => $data->sent_count
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Error sending reminders: ' . $e->getMessage());
            Log::error('Error in reminder:vaksin command: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    
    }
}
