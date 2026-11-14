<?php

declare(strict_types=1);

namespace App\DTOs\Scripts;

use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Support\Collection;

/**
 * One category's worth of scripts, as the library and the Run Script pickers list them.
 */
final readonly class ScriptGroup
{
    /**
     * @param  Collection<int, Script>  $scripts
     */
    public function __construct(
        public ScriptCategory $category,
        public Collection $scripts,
    ) {}
}
