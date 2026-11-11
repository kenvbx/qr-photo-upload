<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.sqlite.database'), '/e2e.sqlite')) {
    throw new RuntimeException('Browser fixtures require a dedicated e2e SQLite database.');
}
User::create(['name' => 'Demo Organizer', 'email' => 'organizer@example.test', 'password' => Hash::make('local-demo-photos-2026')]);
