<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Service\Client\LinkedInClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkedInClientTest extends TestCase
{
    public function testBuildAuthorizationUrl(): void
    {
        $client = new LinkedInClient(new MockHttpClient());
        $result = $client->buildAuthorizationUrl(
            'cid',
            'https://api.example.com/oauth/linkedin/return',
            'state-value',
            LinkedInClient::DEFAULT_SCOPES
        );

        self::assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization?', $result['authorization_url']);
        self::assertStringContainsString('client_id=cid', $result['authorization_url']);
        self::assertStringContainsString('state=state-value', $result['authorization_url']);
        self::assertStringContainsString('w_member_social', $result['authorization_url']);
        self::assertSame(LinkedInClient::DEFAULT_SCOPES, $result['scopes']);
    }

    public function testExchangeAuthorizationCodeSuccess(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(json_encode([
                'access_token' => 'at',
                'expires_in' => 3600,
                'refresh_token' => 'rt',
                'scope' => 'openid profile email w_member_social',
            ]), ['http_code' => 200]),
        ]);

        $client = new LinkedInClient($mock);
        $result = $client->exchangeAuthorizationCode('cid', 'secret', 'code', 'https://cb');

        self::assertSame('at', $result['access_token']);
        self::assertArrayNotHasKey('_request_failed', $result);
    }

    public function testExchangeAuthorizationCodeFailure(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'bad code',
            ]), ['http_code' => 400]),
        ]);

        $client = new LinkedInClient($mock);
        $result = $client->exchangeAuthorizationCode('cid', 'secret', 'bad', 'https://cb');

        self::assertTrue($result['_request_failed']);
        self::assertSame('invalid_grant', $result['error']);
    }

    public function testGetUserInfo(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(json_encode([
                'sub' => 'abc',
                'name' => 'Test User',
                'email' => 't@example.com',
            ]), ['http_code' => 200]),
        ]);

        $client = new LinkedInClient($mock);
        $info = $client->getUserInfo('token');

        self::assertSame('abc', $info['sub']);
        self::assertSame('Test User', $info['name']);
    }
}
