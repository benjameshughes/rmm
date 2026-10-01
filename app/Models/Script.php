<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Script extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'platform',
        'script_type',
        'script_content',
        'is_system',
        'timeout_seconds',
        'requires_admin',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'requires_admin' => 'boolean',
            'timeout_seconds' => 'integer',
            'category' => ScriptCategory::class,
            'platform' => ScriptPlatform::class,
            'script_type' => ScriptType::class,
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public static function findSystem(string $slug): self
    {
        return self::query()->system()->where('slug', $slug)->firstOrFail();
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserCreated($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeForPlatform($query, ScriptPlatform $platform)
    {
        return $query->whereIn('platform', [$platform, ScriptPlatform::All]);
    }

    public function scopeByCategory($query, ScriptCategory $category)
    {
        return $query->where('category', $category);
    }
}
