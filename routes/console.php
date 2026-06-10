<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use App\Models\Product;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('cleanup:orphan-images', function () {
    $disk = Storage::disk('public');
    $productDir = 'products';
    $delete = $this->option('delete');

    $storedPaths = Product::whereNotNull('image')
        ->pluck('image')
        ->flip();

    if (! $disk->exists($productDir)) {
        $this->warn("Directory '{$productDir}' does not exist on the public disk.");
        return 0;
    }

    $allFiles = collect($disk->files($productDir));
    $orphaned = $allFiles->reject(fn ($file) => isset($storedPaths[$file]));

    if ($orphaned->isEmpty()) {
        $this->info('No orphaned files found.');
        return 0;
    }

    $this->warn(sprintf('Found %d orphaned file(s):', $orphaned->count()));

    foreach ($orphaned as $file) {
        if ($delete) {
            $disk->delete($file);
            $this->line("  [DELETED] {$file}");
        } else {
            $this->line("  {$file}");
        }
    }

    if ($delete) {
        $this->info(sprintf('Deleted %d orphaned file(s).', $orphaned->count()));
    } else {
        $this->line('');
        $this->info('Run with --delete to remove orphaned files.');
    }

    return 0;
})->purpose('List and optionally delete product images not referenced in the database')->option('delete', null, null, 'Delete orphaned files');
