<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function extractCloudinaryPublicId(string $url): string
    {
        preg_match('#/v\d+/(.+)$#', $url, $matches);
        $publicIdWithExt = $matches[1] ?? '';

        return preg_replace('/\.\w+$/', '', $publicIdWithExt);
    }
}
