<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Integration;

use League\Flysystem\Config;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Flysystem adapter tests with the option `useSinglePathTransaction`.
 *
 * With this option, the adapter puts each part of a path into a Google Drive query (name = '...').
 */
class SinglePathTransactionTest extends GoogleDriveTestCase
{
    protected static function adapterOptions(): array
    {
        return ['useSinglePathTransaction' => true];
    }

    /**
     * A single quote ends the string literal of a Google Drive query. Without the escape, Google
     * Drive rejects the query.
     */
    #[Test]
    public function checking_a_path_with_a_single_quote(): void
    {
        $adapter = $this->drive();

        $this->assertFalse($adapter->fileExists("it's missing.txt"));
        $this->assertFalse($adapter->fileExists("some dir's/it's missing.txt"));
        $this->assertFalse($adapter->directoryExists("some dir's"));
    }

    #[Test]
    public function checking_a_path_with_query_syntax(): void
    {
        $adapter = $this->drive();
        $adapter->write('file.txt', 'contents', new Config());

        // Without the escape, this name changes the query to "name = 'x' or name contains 'file'".
        $this->assertFalse($adapter->fileExists("x' or name contains 'file"));
        $this->assertTrue($adapter->fileExists('file.txt'));
    }
}
