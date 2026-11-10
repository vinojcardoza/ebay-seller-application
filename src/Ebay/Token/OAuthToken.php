<?php

declare(strict_types=1);

namespace App\Ebay\Token;

use DateTimeImmutable;
use DateTimeInterface;
use UnexpectedValueException;

/**
 * eBay OAuth user tokens: a short-lived access token (about 2 hours) and a
 * long-lived refresh token (about 18 months) used to get new access tokens.
 */
final readonly class OAuthToken
{
    /**
     * Seconds before expiry at which an access token is treated as expired,
     * so it is never sent just as it runs out.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    /**
     * Create the token.
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public DateTimeImmutable $accessTokenExpiresAt,
        #[\SensitiveParameter]
        public string $refreshToken,
        public DateTimeImmutable $refreshTokenExpiresAt,
    ) {
    }

    /**
     * Whether the access token can still be used at the given time.
     */
    public function hasValidAccessToken(DateTimeImmutable $now): bool
    {
        return $this->accessToken !== ''
            && $now->modify('+' . self::EXPIRY_MARGIN_SECONDS . ' seconds') < $this->accessTokenExpiresAt;
    }

    /**
     * Whether the refresh token can still be used at the given time.
     */
    public function hasValidRefreshToken(DateTimeImmutable $now): bool
    {
        return $this->refreshToken !== '' && $now < $this->refreshTokenExpiresAt;
    }

    /**
     * Copy with a new access token, keeping the refresh token.
     */
    public function withAccessToken(
        #[\SensitiveParameter]
        string $accessToken,
        DateTimeImmutable $expiresAt
    ): self {
        return new self($accessToken, $expiresAt, $this->refreshToken, $this->refreshTokenExpiresAt);
    }

    /**
     * Copy whose access token is already expired, forcing a refresh on next use.
     */
    public function withExpiredAccessToken(): self
    {
        return new self('', new DateTimeImmutable('@0'), $this->refreshToken, $this->refreshTokenExpiresAt);
    }

    /**
     * Serialise for storage.
     *
     * @return array{access_token: string, access_token_expires_at: string, refresh_token: string,
     *     refresh_token_expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt->format(DateTimeInterface::ATOM),
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Restore a token saved with toArray().
     *
     * @param array<string, mixed> $data
     *
     * @throws UnexpectedValueException When a field is missing or malformed.
     */
    public static function fromArray(array $data): self
    {
        $string = static function (string $key) use ($data): string {
            if (!is_string($data[$key] ?? null)) {
                throw new UnexpectedValueException("Stored eBay token is missing \"$key\".");
            }

            return $data[$key];
        };

        $date = static function (string $key) use ($string): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $string($key));

            if ($date === false) {
                throw new UnexpectedValueException("Stored eBay token has an invalid \"$key\".");
            }

            return $date;
        };

        return new self(
            $string('access_token'),
            $date('access_token_expires_at'),
            $string('refresh_token'),
            $date('refresh_token_expires_at'),
        );
    }
}
