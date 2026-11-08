<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Key/value application settings stored as JSON in the "settings" table.
 */
class Settings
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return (array) config('proxapps.defaults', []);
    }

    public static function get(string $key): mixed
    {
        $raw = DB::table('settings')->where('key', $key)->value('value');
        if ($raw === null) {
            return static::defaults()[$key] ?? null;
        }

        return json_decode($raw, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $out = static::defaults();
        foreach (DB::table('settings')->get(['key', 'value']) as $row) {
            $out[$row->key] = json_decode($row->value, true);
        }

        return $out;
    }

    public static function put(string $key, mixed $value): void
    {
        DB::table('settings')->upsert(
            [['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            ['key'],
            ['value'],
        );
    }
}
