<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppSettings
{
    /** @return array<string, string|null> */
    public function values(): array
    {
        return Schema::hasTable('app_settings')
            ? DB::table('app_settings')->pluck('value', 'key')->all()
            : [];
    }

    public function telegramEnabled(): bool
    {
        return ($this->values()['telegram_enabled'] ?? '1') === '1';
    }

    /** @param array<string, string|null> $values */
    public function save(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                DB::table('app_settings')->upsert([
                    'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                ], ['key'], ['value', 'updated_at']);
            }
        });
    }
}
