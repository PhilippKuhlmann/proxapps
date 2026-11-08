<?php

namespace App\Http\Controllers;

use App\Services\Catalog\Catalog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppIconController extends Controller
{
    public function __invoke(Catalog $catalog, string $app): BinaryFileResponse
    {
        $item = $catalog->get($app);
        abort_if(! $item || ! $item->iconPath || ! is_file($item->iconPath), 404, 'Kein Icon');

        $type = match (strtolower(pathinfo($item->iconPath, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return response()->file($item->iconPath, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=3600',
            // An SVG served from our origin must not run scripts.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
