<?php

declare(strict_types=1);

namespace App\Ebay\Token;

use RuntimeException;
use UnexpectedValueException;

/**
 * Stores eBay OAuth tokens in a JSON file readable only by the PHP user.
 *
 * The file must live outside `public/` (the default is `var/ebay/token.json`,
 * which is git-ignored) because the refresh token grants access to the
 * seller's eBay account.
 */
final class FileTokenStore implements TokenStoreInterface
{
    /**
     * Create the store.
     *
     * @param string $path Absolute path of the token file.
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Load the saved token, or null when the file is missing or corrupt.
     */
    public function load(): ?OAuthToken
    {
        if (!is_file($this->path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        if (!is_array($data)) {
            return null;
        }

        try {
            return OAuthToken::fromArray($data);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Save the token atomically, with owner-only file permissions.
     *
     * @throws RuntimeException When the directory or file cannot be written.
     */
    public function save(OAuthToken $token): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create eBay token directory $directory.");
        }

        $temporary = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($token->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write eBay token file $this->path.");
        }

        chmod($temporary, 0600);

        if (!rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write eBay token file $this->path.");
        }
    }

    /**
     * Delete the token file if it exists.
     */
    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
