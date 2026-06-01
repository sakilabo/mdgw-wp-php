<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../SiteRequest.php';

/**
 * A SiteRequest subclass for tests that overrides config() and execute() to cut out
 * both config.php and the real network. execute() returns a dummy SiteResponse built
 * straight from each received URL, keyed the same as the input. config() returns the
 * swappable $config, so each test can adjust conditions such as wp_site.
 */
final class FakeSiteRequest extends SiteRequest
{
    public const BASE = 'https://example.com';

    /** @var array Config returned by config(). Reset in setUp for each test, overridden as needed */
    public static array $config = ['wp_site' => self::BASE];

    /** @var array<int|string,string> The URLs most recently received by execute() */
    public static array $received = [];

    protected static function config(): array
    {
        return self::$config;
    }

    protected static function execute(array $urls): array
    {
        self::$received = $urls;
        $results = [];
        foreach ($urls as $key => $url) {
            $results[$key] = new SiteResponse(true, 200, "BODY:$url", "X-Url: $url");
        }
        return $results;
    }

    /** Expose the protected resolveHost() so it can be tested directly. */
    public static function exposedResolveHost(string $host): array
    {
        return self::resolveHost($host);
    }
}

/**
 * Tests for SiteRequest::get() behaviour (single/multiple branching, key preservation, SSRF checks).
 * Overriding config() / execute() lets it run without config.php or the real network.
 */
final class SiteRequestTest extends TestCase
{
    protected function setUp(): void
    {
        // Start each test from the default condition (wp_site = BASE)
        FakeSiteRequest::$config = ['wp_site' => FakeSiteRequest::BASE];
        FakeSiteRequest::$received = [];
    }

    public function testSingleUrlReturnsSingleResponse(): void
    {
        $res = FakeSiteRequest::get(FakeSiteRequest::BASE . '/foo');

        $this->assertInstanceOf(SiteResponse::class, $res);
        $this->assertTrue($res->success);
        $this->assertSame('BODY:' . FakeSiteRequest::BASE . '/foo', $res->body);
        // A single URL is passed to execute() as an array with key 0
        $this->assertSame([0 => FakeSiteRequest::BASE . '/foo'], FakeSiteRequest::$received);
    }

    public function testArrayInputReturnsArrayKeyedTheSame(): void
    {
        $res = FakeSiteRequest::get([
            'a' => FakeSiteRequest::BASE . '/1',
            'b' => FakeSiteRequest::BASE . '/2',
        ]);

        $this->assertIsArray($res);
        // The same keys as the input are preserved
        $this->assertSame(['a', 'b'], array_keys($res));
        $this->assertSame('BODY:' . FakeSiteRequest::BASE . '/1', $res['a']->body);
        $this->assertSame('BODY:' . FakeSiteRequest::BASE . '/2', $res['b']->body);
    }

    public function testExactBaseUrlIsInternal(): void
    {
        // A URL that exactly matches wp_site also passes as internal
        $res = FakeSiteRequest::get(FakeSiteRequest::BASE);

        $this->assertTrue($res->success);
        $this->assertSame([0 => FakeSiteRequest::BASE], FakeSiteRequest::$received);
    }

    public function testExternalUrlThrowsBeforeExecute(): void
    {
        $this->assertRejected('https://not-the-site.invalid/x');
    }

    public function testPrefixBoundaryAttackIsRejected(): void
    {
        // A different host that starts with "example.com" (example.com.evil.test) is not treated as internal
        $this->assertRejected('https://example.com.evil.test/x');
    }

    public function testPathPrefixedSiteScopesInternalUrls(): void
    {
        // When wp_site has a path, only URLs under that path are treated as internal
        FakeSiteRequest::$config = ['wp_site' => 'https://example.com/blog'];

        $res = FakeSiteRequest::get('https://example.com/blog/post');
        $this->assertTrue($res->success);

        // Outside the path (/other) is not internal
        $this->assertRejected('https://example.com/other');
    }

    public function testResolveHostReturnsLiteralIpUnchanged(): void
    {
        // A literal IP short-circuits resolution (no DNS lookup), for both IPv4 and IPv6.
        $this->assertSame(['127.0.0.1'], FakeSiteRequest::exposedResolveHost('127.0.0.1'));
        $this->assertSame(['::1'], FakeSiteRequest::exposedResolveHost('::1'));
    }

    /** Asserts that passing $url to get() raises HttpException(400) and never reaches execute(). */
    private function assertRejected(string $url): void
    {
        FakeSiteRequest::$received = [];
        try {
            FakeSiteRequest::get($url);
            $this->fail("Expected HttpException for external URL but none was thrown: $url");
        } catch (HttpException $e) {
            $this->assertSame(HTTP_BAD_REQUEST, $e->getCode());
        }
        // SSRF validation runs before execute(), so it never reaches the network layer
        $this->assertSame([], FakeSiteRequest::$received);
    }
}
