<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('admin:create {email} {--name=Organizer}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email address.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('This user already exists.');

        return 1;
    }
    $password = $this->secret('Password (at least 12 characters)');
    if (! is_string($password) || strlen($password) < 12) {
        $this->error('Use at least 12 characters.');

        return 1;
    }
    User::create(['email' => $email, 'name' => $this->option('name'), 'password' => Hash::make($password)]);
    $this->info('Organizer created.');

    return 0;
})->purpose('Create a private organizer login without public registration');

Artisan::command('demo:seed', function () {
    if (! app()->environment('local')) {
        $this->error('Demo credentials are only allowed in the local environment.');

        return 1;
    }
    $user = User::firstOrCreate(['email' => 'organizer@example.test'], [
        'name' => 'Demo Organizer',
        'password' => Hash::make('local-demo-photos-2026'),
    ]);
    $this->info($user->wasRecentlyCreated ? 'Local demo organizer created. See README for credentials.' : 'Existing demo organizer preserved.');

    return 0;
})->purpose('Create a disposable local-only demo organizer');

Schedule::command('exports:prune')->hourly()->withoutOverlapping();

Schedule::command('photos:prune')->hourly()->withoutOverlapping();

Schedule::command('uploads:prune')->hourly()->withoutOverlapping();
