<?php

declare(strict_types=1);

namespace Tests\Ebay\Token;

use App\Ebay\Token\OAuthToken;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Tests for OAuth token expiry rules and serialisation.
 */
#[CoversClass(OAuthToken::class)]
final class OAuthTokenTest extends TestCase
{
    /**
     * Access token expiries relative to "now" and whether the token is still usable.
     *
     * @return array<string, array{string, bool}>
     */
    public static function accessExpiryProvider(): array
    {
        return [
            'an hour left' => ['+1 hour', true],
            'two minutes left' => ['+2 minutes', true],
            'within the 60s margin' => ['+30 seconds', false],
            'expired' => ['-1 minute', false],
        ];
    }

    /**
     * The access token counts as expired 60 seconds early.
     */
    #[DataProvider('accessExpiryProvider')]
    public function testAccessTokenValidity(string $expiry, bool $valid): void
    {
        $now = new DateTimeImmutable('2026-11-10 12:00:00');
        $token = new OAuthToken('access', $now->modify($expiry), 'refresh', $now->modify('+1 year'));

        $this->assertSame($valid, $token->hasValidAccessToken($now));
    }

    /**
     * The refresh token is valid until its expiry, and never when empty.
     */
    public function testRefreshTokenValidity(): void
    {
        $now = new DateTimeImmutable('2026-11-10 12:00:00');

        $this->assertTrue((new OAuthToken('a', $now, 'r', $now->modify('+1 day')))->hasValidRefreshToken($now));
        $this->assertFalse((new OAuthToken('a', $now, 'r', $now->modify('-1 day')))->hasValidRefreshToken($now));
        $this->assertFalse((new OAuthToken('a', $now, '', $now->modify('+1 day')))->hasValidRefreshToken($now));
    }

    /**
     * Replacing the access token keeps the refresh token; expiring it forces a refresh.
     */
    public function testWithAccessTokenAndWithExpiredAccessToken(): void
    {
        $now = new DateTimeImmutable('2026-11-10 12:00:00');
        $token = new OAuthToken('old', $now, 'refresh', $now->modify('+1 year'));

        $renewed = $token->withAccessToken('new', $now->modify('+2 hours'));
        $this->assertSame('new', $renewed->accessToken);
        $this->assertSame('refresh', $renewed->refreshToken);
        $this->assertTrue($renewed->hasValidAccessToken($now));

        $expired = $renewed->withExpiredAccessToken();
        $this->assertFalse($expired->hasValidAccessToken($now));
        $this->assertSame('refresh', $expired->refreshToken);
    }

    /**
     * A token survives a toArray()/fromArray() round trip.
     */
    public function testArrayRoundTrip(): void
    {
        $token = new OAuthToken(
            'access',
            new DateTimeImmutable('2026-11-10T14:00:00+00:00'),
            'refresh',
            new DateTimeImmutable('2028-05-10T12:00:00+00:00')
        );

        $this->assertEquals($token, OAuthToken::fromArray($token->toArray()));
    }

    /**
     * Malformed stored data is rejected.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedProvider(): array
    {
        $valid = [
            'access_token' => 'a',
            'access_token_expires_at' => '2026-11-10T14:00:00+00:00',
            'refresh_token' => 'r',
            'refresh_token_expires_at' => '2028-05-10T12:00:00+00:00',
        ];

        return [
            'missing refresh token' => [array_diff_key($valid, ['refresh_token' => true])],
            'bad date' => [['access_token_expires_at' => 'tomorrow'] + $valid],
            'non-string token' => [['access_token' => 42] + $valid],
        ];
    }

    /**
     * fromArray() throws for missing or malformed fields.
     *
     * @param array<string, mixed> $data
     */
    #[DataProvider('malformedProvider')]
    public function testFromArrayRejectsMalformedData(array $data): void
    {
        $this->expectException(UnexpectedValueException::class);

        OAuthToken::fromArray($data);
    }
}
