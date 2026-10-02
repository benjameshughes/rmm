<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\ScriptParameter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Script parameter definitions as a typed collection. A script without
 * parameters stores null and reads back as an empty collection.
 *
 * @implements CastsAttributes<Collection<int, ScriptParameter>, iterable<int, ScriptParameter|array<string, mixed>>|null>
 */
final class ScriptParameters implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, ScriptParameter>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Collection
    {
        return collect($value === null ? [] : json_decode($value, true))
            ->map(fn (array $parameter): ScriptParameter => ScriptParameter::fromArray($parameter));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $parameters = collect($value ?? [])
            ->map(fn (ScriptParameter|array $parameter): array => ($parameter instanceof ScriptParameter ? $parameter : ScriptParameter::fromArray($parameter))->toArray())
            ->values();

        return $parameters->isEmpty() ? null : $parameters->toJson();
    }
}
