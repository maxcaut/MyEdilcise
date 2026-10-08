<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    DB::table('telegram_accounts')->where('draft_expires_at', '<=', now())->update([
        'draft' => null, 'draft_expires_at' => null,
    ]);
    DB::table('telegram_accounts')->where('link_expires_at', '<=', now())->update([
        'link_hash' => null, 'link_expires_at' => null,
    ]);
})->hourly()->name('telegram-expired-drafts')->withoutOverlapping();
