<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class India
{
    public static function phone(?string $value): string
    {
        $digits = preg_replace('/[\s().-]/', '', trim($value ?? ''));
        if (preg_match('/^[6-9]\d{9}$/', $digits)) {
            $digits = '+91'.$digits;
        } elseif (preg_match('/^0[2-9]\d{9}$/', $digits)) {
            $digits = '+91'.substr($digits, 1);
        } elseif (preg_match('/^91[2-9]\d{9}$/', $digits)) {
            $digits = '+'.$digits;
        }
        if (! preg_match('/^\+91[2-9]\d{9}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => 'Enter an Indian mobile number or a +91 number with its full STD code.']);
        }

        return $digits;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Kolkata');
    }

    public static function display($date, string $format = 'd M Y, h:i A'): string
    {
        return $date ? $date->copy()->timezone('Asia/Kolkata')->format($format) : '—';
    }

    public static function duration(?int $seconds): string
    {
        return $seconds === null ? '—' : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    public static function inputTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, 'Asia/Kolkata')->utc();
    }
}
