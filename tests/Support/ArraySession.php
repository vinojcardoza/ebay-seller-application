<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\SessionInterface;

/**
 * In-memory session for tests, recording regenerations and destructions.
 */
final class ArraySession implements SessionInterface
{
    /**
     * Stored values.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Number of regenerate() calls.
     */
    public int $regenerated = 0;

    /**
     * Number of destroy() calls.
     */
    public int $destroyed = 0;

    /**
     * {@inheritDoc}
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function regenerate(): void
    {
        $this->regenerated++;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed++;
    }
}
