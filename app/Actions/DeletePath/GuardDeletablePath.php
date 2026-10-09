<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Exceptions\PathCannotBeDeleted;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The server's guard rails for deleting a path on a Windows PC: one plain,
 * absolute path on a local drive, outside everything config/devices.php
 * protects. The script on the PC applies the same list again.
 */
final class GuardDeletablePath
{
    /**
     * @return string The path normalised: backslashes, an upper-case drive letter, no trailing backslash
     *
     * @throws PathCannotBeDeleted
     */
    public function __invoke(string $path): string
    {
        $refusal = $this->refusal($path);

        throw_if($refusal !== null, $refusal);

        return $this->normalise($path);
    }

    /**
     * Why the path cannot be deleted, without throwing, so a form can show it inline.
     */
    public function refusal(string $path): ?PathCannotBeDeleted
    {
        $path = trim($path);

        if ($path === '') {
            return PathCannotBeDeleted::blank();
        }

        if (Str::startsWith(str_replace('/', '\\', $path), '\\\\')) {
            return PathCannotBeDeleted::unc($path);
        }

        $normalised = $this->normalise($path);
        $segments = $this->segments($normalised);

        return match (true) {
            preg_match('/%[^%]*%|\$\{?env:/i', $path) === 1 => PathCannotBeDeleted::variable($path),
            Str::contains($path, ['*', '?']) => PathCannotBeDeleted::wildcard($path),
            preg_match('/^[A-Za-z]:\\\\/', $normalised) !== 1 => PathCannotBeDeleted::notAbsolute($path),
            preg_match('/["<>|:\x00-\x1F]/', substr($normalised, 2)) === 1 => PathCannotBeDeleted::invalidCharacters($path),
            $segments->contains(fn (string $segment): bool => $segment === '.' || $segment === '..') => PathCannotBeDeleted::traversal($path),
            $segments->contains(fn (string $segment): bool => preg_match('/~\d/', $segment) === 1) => PathCannotBeDeleted::shortName($path),
            $segments->contains(fn (string $segment): bool => Str::endsWith($segment, ['.', ' '])) => PathCannotBeDeleted::trailingDot($path),
            $segments->isEmpty() => PathCannotBeDeleted::driveRoot($normalised),
            $this->isProtected($segments->implode('\\')) => PathCannotBeDeleted::protected($normalised),
            default => null,
        };
    }

    /**
     * Forward slashes become backslashes, runs of them collapse to one, the
     * drive letter is upper-cased and a trailing backslash dropped from
     * anything below a drive root.
     */
    public function normalise(string $path): string
    {
        $path = (string) preg_replace('/\\\\+/', '\\', str_replace('/', '\\', trim($path)));
        $path = preg_match('/^[a-z]:/', $path) === 1 ? ucfirst($path) : $path;

        return strlen($path) > 3 ? rtrim($path, '\\') : $path;
    }

    /**
     * The folder names below the drive root.
     *
     * @return Collection<int, string>
     */
    private function segments(string $normalised): Collection
    {
        return Str::of(substr($normalised, 3))->explode('\\')->reject(fn (string $segment): bool => $segment === '')->values();
    }

    private function isProtected(string $fromDrive): bool
    {
        $protected = config('devices.delete_path.protected');
        $fromDrive = Str::lower($fromDrive);

        return collect($protected['trees'])->contains(fn (string $tree): bool => $this->matches($tree, $fromDrive) || $this->matchesAncestor($tree, $fromDrive))
            || collect([...$protected['exact'], ...$protected['root_files']])->contains(fn (string $pattern): bool => $this->matches($pattern, $fromDrive));
    }

    /**
     * Whether a path below the drive matches a pattern where `*` is one folder name.
     */
    private function matches(string $pattern, string $fromDrive): bool
    {
        $regex = '/^'.str_replace('\\*', '[^\\\\]*', preg_quote(Str::lower($pattern), '/')).'$/';

        return preg_match($regex, $fromDrive) === 1;
    }

    private function matchesAncestor(string $tree, string $fromDrive): bool
    {
        $segments = explode('\\', $fromDrive);

        return collect($segments)->keys()->skip(1)
            ->contains(fn (int $depth): bool => $this->matches($tree, implode('\\', array_slice($segments, 0, $depth))));
    }
}
