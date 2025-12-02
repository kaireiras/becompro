<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkController extends Controller
{
    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        Log::info('🔐 Forgot password request received', [
            'email' => $request->email
        ]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Check if user exists
        $user = \App\Models\User::where('email', $request->email)->first();
        
        if (!$user) {
            Log::warning('❌ User not found', ['email' => $request->email]);
            
            throw ValidationException::withMessages([
                'email' => ['Email tidak terdaftar dalam sistem.'],
            ]);
        }

        Log::info('✅ User found', [
            'id' => $user->id,
            'name' => $user->username,
            'email' => $user->email
        ]);

        // Send reset link
        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );

            Log::info('📧 Password reset link status', [
                'status' => $status,
                'is_sent' => $status == Password::RESET_LINK_SENT
            ]);

            if ($status != Password::RESET_LINK_SENT) {
                Log::error('❌ Failed to send reset link', [
                    'status' => $status
                ]);

                throw ValidationException::withMessages([
                    'email' => [__($status)],
                ]);
            }

            Log::info('✅ Password reset email sent successfully');

            return response()->json([
                'message' => 'Link reset password telah dikirim ke email Anda.',
                'status' => __($status)
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Exception sending reset link', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
