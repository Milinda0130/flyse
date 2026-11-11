<?php

namespace App\Services;

/** Lookup in the shared airport list (resources/data/airports.json, also used by the React forms). */
final class Airports
{
    /** @return array<string, array{code: string, city: string, name: string, country: string}> */
    public static function all(): array
    {
        return once(fn () => collect(json_decode((string) file_get_contents(resource_path('data/airports.json')), true) ?: [])->keyBy('code')->all());
    }

    public static function find(?string $code): ?array
    {
        return $code ? (self::all()[strtoupper($code)] ?? null) : null;
    }

    public static function name(?string $code): ?string
    {
        return self::find($code)['name'] ?? null;
    }

    public static function city(?string $code): ?string
    {
        return self::find($code)['city'] ?? null;
    }
}
