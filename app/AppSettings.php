<?php

namespace App;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppSettings
{
    /** @return array<string, string|null> */
    public function values(): array
    {
        if (! Schema::hasTable('app_settings')) {
            return [];
        }

        return Cache::remember('app_settings.values', 60, fn (): array => DB::table('app_settings')->pluck('value', 'key')->all());
    }

    public function telegramEnabled(): bool
    {
        return ($this->values()['telegram_enabled'] ?? '1') === '1';
    }

    /** @param array<string, string|null> $values */
    public function save(array $values): void
    {
        $rows = [];
        $timestamp = now();
        foreach ($values as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value, 'created_at' => $timestamp, 'updated_at' => $timestamp];
        }
        if ($rows !== []) {
            DB::table('app_settings')->upsert($rows, ['key'], ['value', 'updated_at']);
            Cache::forget('app_settings.values');
        }
    }
}
