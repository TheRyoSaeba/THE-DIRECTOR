<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class UnixTimestamp implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        return $value ? Carbon::createFromTimestamp($value) : null;
    }

    public function set($model, string $key, $value, array $attributes): ?int
    {
        return $value instanceof Carbon ? $value->getTimestamp() : $value;
    }
}
