<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScriptParameters;
use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Script extends Model
{
    use Auditable;
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
        'is_internal',
        'timeout_seconds',
        'requires_admin',
        'parameters',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_internal' => 'boolean',
            'requires_admin' => 'boolean',
            'timeout_seconds' => 'integer',
            'category' => ScriptCategory::class,
            'platform' => ScriptPlatform::class,
            'script_type' => ScriptType::class,
            'parameters' => ScriptParameters::class,
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

    /**
     * The values the server hands the agent only when it fetches a run of
     * this script, never stored with the command. Only system scripts have any.
     *
     * @return array<int, string>
     */
    public function secretNames(): array
    {
        return $this->is_system ? config("scripts.system.{$this->slug}.secrets", []) : [];
    }

    /**
     * Whether values travel to the agent with a run: parameters, secrets or both.
     */
    public function takesValues(): bool
    {
        return $this->parameters->isNotEmpty() || $this->secretNames() !== [];
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scripts a person may pick to run or schedule: everything but the
     * internal system scripts that only their own feature queues.
     */
    public function scopeRunnableDirectly($query)
    {
        return $query->where('is_internal', false);
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
