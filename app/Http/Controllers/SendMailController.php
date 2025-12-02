<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mail;
use App\Mail\SendMail;


class SendMailController extends Controller
{
    public function index(){
        $content = [
            'name'=> 'namanya',
            'subject' => 'subject',
            'body' => 'isi'

        ];
    }
}
