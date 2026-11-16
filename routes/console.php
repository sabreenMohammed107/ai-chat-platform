<?php

use App\Jobs\SyncKnowledgeSource;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

// The platform owner's account: php artisan chat:make-admin you@example.com "Your Name"
Artisan::command('chat:make-admin {email} {name=Admin}', function (string $email, string $name) {
    $password = Str::password(16, symbols: false);
    $user = User::firstOrNew(['email' => $email]);
    $user->forceFill(['name' => $name, 'password' => $password, 'is_admin' => true, 'tenant_id' => null])->save();
    $this->info("Admin {$email} is ready. Password: {$password}");
    $this->line('Sign in at '.url('/admin/login').' and keep the password somewhere safe.');
})->purpose('Create (or reset) the platform admin account');

// Shared hosting has no long-running worker: every minute, the scheduler works through the queue and stops
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')->everyMinute()->withoutOverlapping();

// Websites change: read them again every week
Schedule::call(function () {
    KnowledgeSource::where('type', 'website')->whereIn('status', ['ready', 'failed'])
        ->each(fn (KnowledgeSource $source) => SyncKnowledgeSource::dispatch($source));
})->weeklyOn(5, '03:00')->name('resync-websites');
