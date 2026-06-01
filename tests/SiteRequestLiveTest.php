<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../SiteRequest.php';

/**
 * SiteRequest subclass that overrides ONLY config() and keeps the real execute(),
 * so the request actually goes out over the network. config() returns no 'wp_addr'
 * key on purpose, so the address-pinning path is skipped and the host is resolved
 * via real DNS, with real SSL verification.
 */
final class LiveSiteRequest extends SiteRequest
{
    public const BASE = 'https://github.com/sakilabo/mdgw-wp-php';

    protected static function config(): array
    {
        // Deliberately no 'wp_addr' key (resolve over DNS) and no 'timeout' (defaults to 15s).
        return ['wp_site' => self::BASE];
    }
}

/**
 * Integration test that drives SiteRequest::execute() over the real network.
 * Tagged @group network so it can be excluded offline:  phpunit --exclude-group network
 */
#[Group('network')]
final class SiteRequestLiveTest extends TestCase
{
    public function testExecuteFetchesOverRealNetworkWithoutAddrPin(): void
    {
        $res = LiveSiteRequest::get(LiveSiteRequest::BASE);

        // get() returns a single SiteResponse for a single-URL request.
        $this->assertInstanceOf(SiteResponse::class, $res);

        // On a transport failure (DNS/SSL/connect) status stays 0; on an unfollowed
        // redirect it is 3xx. Surface both in the failure message to aid diagnosis.
        $this->assertTrue(
            $res->success,
            'Expected a 2xx response from ' . LiveSiteRequest::BASE
                . ' but got status=' . $res->status
                . ' (status 0 => transport failure such as DNS/SSL/connect;'
                . ' 3xx => redirect that was not followed).'
        );
    }
}
