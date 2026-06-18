<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;

Route::get('/', function () {
    return response()->json([
        'message' => 'ChattoShop API is running'
    ]);
});

Route::get('/mail-test', function () {
    return [
        'mailer' => config('mail.default'),
        'host' => config('mail.mailers.smtp.host'),
        'port' => config('mail.mailers.smtp.port'),
        'encryption' => config('mail.mailers.smtp.encryption'),
        'username' => config('mail.mailers.smtp.username'),
        'from' => config('mail.from.address'),
        'from_name' => config('mail.from.name'),
    ];
});

Route::get('/smtp-test', function () {
    try {
        $connection = fsockopen('smtp.gmail.com', 465, $errno, $errstr, 15);

        if (!$connection) {
            return [
                'success' => false,
                'errno' => $errno,
                'error' => $errstr,
            ];
        }

        fclose($connection);

        return [
            'success' => true,
            'message' => 'SMTP reachable'
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
});