<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\Mail;
use App\Mail\TestEmail;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

Route::get('/auth/google/redirect', function(Request $request){
    return Socialite::driver("google")->redirect();
});

Route::get('/auth/google/callback', function(){
    $googleUser = Socialite::driver("google")->user();

    $user = User::updateOrCreate(
        ['google_id'=>$googleUser->getId()],[
            'username'=>$googleUser -> getName(),
            'email'=>$googleUser->getEmail(),
            'password'=>bcrypt(Str::random(12)),
        ]
    );
    // dd($user);

    Auth::login($user);
    return redirect(config("app.frontend_url"). "/dashboardAdmin");
    
});

Route::get('/test-mail', function () {
    $data = ['message' => 'Ini percobaan kirim email Laravel menggunakan Gmail SMTP.'];
    \Mail::to('rakaiahmadmaulana@gmail.com')->send(new \App\Mail\TestEmail($data));
    return '✅ Email percobaan dikirim!';
});


// Route::post('/forgot-password', function(Request $request){
//     $request -> validate(['email'=> 'required|email']);

//     $status = Password::sendResetLink(
//         $request -> only('email')
//     );

//     return $status == Password::RESET_LINK_SENT
//         ? response()->json(['message' => 'link reset telah dikirim ke email anda'])
//         : response()->json(['message'=>'email tidak ditemukan'], 422);
// });

