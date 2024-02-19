<?php

namespace App\Helpers\Classes;

use App\Models\Setting;

class Helper
{
    public static function setting(string $key, $default = null)
    {
        $setting = Setting::query()->first();

        return $setting->getAttribute($key) ?? $default;
    }

    public static function appIsDemo(): bool
    {
        return config('app.status') == 'Demo';
    }

    public static function appIsNotDemo(): bool
    {
        return config('app.status') != 'Demo';
    }
}