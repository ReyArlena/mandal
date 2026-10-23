<?php

namespace App\Filament\Resources\Servers\Concerns;

trait FormatsBytes
{
    public function bytes(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                $number = number_format($bytes, $unit === 'B' ? 0 : 1, '.', '');

                return (str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number).' '.$unit;
            }

            $bytes /= 1024;
        }

        return '0 B';
    }
}
