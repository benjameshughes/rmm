<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class SyncSystemScripts
{
    /**
     * Upsert every configured system script from its file and remove system
     * scripts that are no longer configured. User scripts are never touched.
     *
     * @return int The number of system scripts now in place
     */
    public function __invoke(): int
    {
        $definitions = collect(config('scripts.system'));

        DB::transaction(function () use ($definitions): void {
            $definitions->each(fn (array $definition, string $slug) => $this->sync($slug, $definition));
            $this->removeUnconfigured($definitions->keys());
        });

        return $definitions->count();
    }

    /**
     * @param  array{name: string, description: string, category: string, platform: string, file: string, includes?: array<int, string>, internal?: bool, timeout_seconds: int, requires_admin: bool, parameters?: array<int, array{name: string, label: string, type: string, required?: bool, default?: ?string, options?: array<int, string>}>}  $definition
     */
    private function sync(string $slug, array $definition): void
    {
        Script::query()->updateOrCreate(['slug' => $slug], [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'category' => ScriptCategory::from($definition['category']),
            'platform' => ScriptPlatform::from($definition['platform']),
            'script_type' => $this->scriptType($definition['file']),
            'script_content' => $this->content($slug, [...($definition['includes'] ?? []), $definition['file']]),
            'timeout_seconds' => $definition['timeout_seconds'],
            'requires_admin' => $definition['requires_admin'],
            'parameters' => $definition['parameters'] ?? [],
            'is_system' => true,
            'is_internal' => $definition['internal'] ?? false,
        ]);
    }

    /**
     * The script's own file, with any shared includes in front of it in order.
     *
     * @param  array<int, string>  $files
     */
    private function content(string $slug, array $files): string
    {
        return collect($files)
            ->map(function (string $file) use ($slug): string {
                $path = config('scripts.path').DIRECTORY_SEPARATOR.$file;

                throw_unless(File::exists($path), RuntimeException::class, "System script '{$slug}' points at a missing file: {$file}");

                return File::get($path);
            })
            ->implode("\n");
    }

    private function scriptType(string $file): ScriptType
    {
        $type = config('scripts.types.'.File::extension($file));

        throw_unless($type, RuntimeException::class, "No script type is configured for {$file}");

        return ScriptType::from($type);
    }

    /**
     * @param  Collection<int, string>  $configuredSlugs
     */
    private function removeUnconfigured(Collection $configuredSlugs): void
    {
        Script::query()
            ->system()
            ->where(fn (Builder $query) => $query->whereNull('slug')->orWhereNotIn('slug', $configuredSlugs))
            ->delete();
    }
}
