<?php

declare(strict_types=1);

namespace Tests\Ebay\Token;

use App\Ebay\Token\FileTokenStore;
use App\Ebay\Token\OAuthToken;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for storing eBay tokens in a private JSON file.
 */
#[CoversClass(FileTokenStore::class)]
final class FileTokenStoreTest extends TestCase
{
    /**
     * Temporary directory for this test.
     */
    private string $directory;

    /**
     * Token file path inside a not-yet-created subdirectory.
     */
    private string $path;

    /**
     * Create an empty temporary directory.
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ebay-token-test-' . bin2hex(random_bytes(6));
        $this->path = $this->directory . '/nested/token.json';
    }

    /**
     * Remove the temporary directory.
     */
    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir($this->directory . '/nested');
        @rmdir($this->directory);
    }

    /**
     * Loading before anything is saved returns null.
     */
    public function testLoadReturnsNullWhenNoFile(): void
    {
        $this->assertNull((new FileTokenStore($this->path))->load());
    }

    /**
     * A saved token is loaded back, creating the directory as needed.
     */
    public function testSaveThenLoad(): void
    {
        $store = new FileTokenStore($this->path);
        $token = new OAuthToken(
            'access',
            new DateTimeImmutable('2026-11-10T14:00:00+00:00'),
            'refresh',
            new DateTimeImmutable('2028-05-10T12:00:00+00:00')
        );

        $store->save($token);

        $this->assertEquals($token, $store->load());
    }

    /**
     * The token file is readable by its owner only.
     */
    public function testFileIsOwnerOnly(): void
    {
        $store = new FileTokenStore($this->path);
        $now = new DateTimeImmutable();
        $store->save(new OAuthToken('a', $now, 'r', $now));

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
        $this->assertSame([], glob($this->directory . '/nested/*.tmp'));
    }

    /**
     * A corrupt file is treated as no token rather than an error.
     */
    public function testLoadReturnsNullForCorruptFile(): void
    {
        mkdir($this->directory . '/nested', 0700, true);
        file_put_contents($this->path, '{"access_token": "only"}');

        $this->assertNull((new FileTokenStore($this->path))->load());

        file_put_contents($this->path, 'not json');
        $this->assertNull((new FileTokenStore($this->path))->load());
    }

    /**
     * clear() deletes the file, and is safe to call when there is none.
     */
    public function testClear(): void
    {
        $store = new FileTokenStore($this->path);
        $now = new DateTimeImmutable();
        $store->save(new OAuthToken('a', $now, 'r', $now));

        $store->clear();
        $store->clear();

        $this->assertFileDoesNotExist($this->path);
        $this->assertNull($store->load());
    }
}
