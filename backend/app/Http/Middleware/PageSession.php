<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

class PageSession extends StartSession
{
    protected function storeCurrentUrl(Request $request, mixed $session): void
    {
        // Private image requests and polling must not become a form's fallback redirect target.
        if ($request->expectsJson() || $request->routeIs('photos.show', 'photos.thumbnail', 'event.logo', 'upload.logo', 'album.photo', 'album.logo', 'exports.download') || in_array($request->header('Sec-Fetch-Dest'), ['image', 'script', 'style', 'font'], true)) {
            return;
        }
        parent::storeCurrentUrl($request, $session);
    }
}
