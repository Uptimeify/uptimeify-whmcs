<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;

final class StatusCacheTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
    }

    public function testSetGetFreshness(): void
    {
        $c = new StatusCache();
        self::assertNull($c->get('org'));
        self::assertFalse($c->isFresh('org', 300));
        $c->set('org', ['total' => 3, 'down' => 1]);
        self::assertSame(['total' => 3, 'down' => 1], $c->value('org'));
        self::assertTrue($c->isFresh('org', 300));
        self::assertFalse($c->isFresh('org', 300, time() + 400));
        $c->set('org', ['total' => 4]);
        self::assertSame(['total' => 4], $c->value('org'));
    }

    public function testClaimReturnsTrueOncePerWindow(): void
    {
        $c = new StatusCache();
        self::assertTrue($c->claim('client_retry:5', 60));
        self::assertFalse($c->claim('client_retry:5', 60));
        self::assertTrue($c->isFresh('client_retry:5', 60));
        \WHMCS\Database\Capsule::table('mod_uptimeify_status_cache')->where('key', 'client_retry:5')->update(['refreshed_at' => '2020-01-01 00:00:00']);
        self::assertTrue($c->claim('client_retry:5', 60), 'window passed');
        self::assertFalse($c->claim('client_retry:5', 60));
    }

    public function testDeleteRemovesOnlyThatKey(): void
    {
        $c = new StatusCache();
        $c->set('client:5', ['a' => 1]);
        $c->set('client:6', ['b' => 2]);
        $c->delete('client:5');
        $c->delete('client:404');
        self::assertNull($c->get('client:5'));
        self::assertSame(['b' => 2], $c->value('client:6'));
    }

    public function testGetShapeAndBrokenJsonYieldsEmptyValue(): void
    {
        $c = new StatusCache();
        self::assertNull($c->value('client:5'));
        $c->set('client:5', ['monitors' => [['name' => 'ä.example']]]);
        $entry = $c->get('client:5');
        self::assertNotNull($entry);
        self::assertSame(['monitors' => [['name' => 'ä.example']]], $entry['value']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['refreshed_at']);
        \WHMCS\Database\Capsule::table('mod_uptimeify_status_cache')->where('key', 'client:5')->update(['value' => '{broken']);
        self::assertSame([], $c->value('client:5'));
    }
}
