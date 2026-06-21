<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Masbug\Flysystem\GoogleDriveAdapter;

/**
 * Test-only subclass that exposes protected pure-logic helpers so they can be
 * unit-tested without a live Google Drive connection.
 */
class ExposedGoogleDriveAdapter extends GoogleDriveAdapter
{
    public function callSplitPath(string $path, bool $getParentId = true): array
    {
        return $this->splitPath($path, $getParentId);
    }

    public function callSplitFileExtension(string $name): array
    {
        return $this->splitFileExtension($name);
    }

    public function callSanitizeFilename(string $name): string
    {
        return $this->sanitizeFilename($name);
    }

    public function callIndexString(string $str, string $ch = '/'): array
    {
        return $this->indexString($str, $ch);
    }

    public function callCleanOptParameters(array $params): array
    {
        return $this->cleanOptParameters($params);
    }
}
