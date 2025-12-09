<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Script extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const CATEGORY_POWER = 'power';

    public const CATEGORY_NETWORK = 'network';

    public const CATEGORY_MAINTENANCE = 'maintenance';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_INFO = 'info';

    public const CATEGORY_SERVICES = 'services';

    public const CATEGORY_PROCESSES = 'processes';

    public const CATEGORY_UPDATES = 'updates';

    public const CATEGORY_USER = 'user';

    public const PLATFORM_WINDOWS = 'windows';

    public const PLATFORM_LINUX = 'linux';

    public const PLATFORM_MACOS = 'macos';

    public const PLATFORM_ALL = 'all';

    public const TYPE_POWERSHELL = 'powershell';

    public const TYPE_BASH = 'bash';

    public const TYPE_CMD = 'cmd';

    public const TYPE_SH = 'sh';

    protected $fillable = [
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
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserCreated($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->whereIn('platform', [$platform, self::PLATFORM_ALL]);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
