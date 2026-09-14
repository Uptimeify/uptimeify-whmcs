<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

final class CustomerMapTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
    }

    public function testSetGetOverwriteRemove(): void
    {
        $m = new CustomerMap();
        self::assertNull($m->get(1));
        $m->set(1, 'cust_a', 'starter', 'matched_email');
        self::assertSame('cust_a', $m->get(1)['customer_public_id']);
        self::assertSame('matched_email', $m->get(1)['source']);
        $m->set(1, 'cust_b', null, 'manual');
        self::assertSame('cust_b', $m->get(1)['customer_public_id']);
        self::assertSame(1, $m->clientIdFor('cust_b'));
        self::assertCount(1, $m->all());
        $m->remove(1);
        self::assertNull($m->get(1));
    }

    public function testRowShapeAllKeyedByClientAndUnknownCustomer(): void
    {
        $m = new CustomerMap();
        $m->set(9, 'cust_x', 'pro', 'created');
        $m->set(4, 'cust_y', null, 'matched_id');
        $row = $m->get(9);
        self::assertNotNull($row);
        self::assertSame(9, $row['whmcs_client_id']);
        self::assertSame('pro', $row['package_type']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['updated_at']);
        self::assertArrayHasKey('package_type', $m->get(4) ?? []);
        self::assertNull($m->get(4)['package_type']);
        self::assertEqualsCanonicalizing([4, 9], array_keys($m->all()));
        self::assertSame('cust_y', $m->all()[4]['customer_public_id']);
        self::assertNull($m->clientIdFor('nope'));
        self::assertSame([], $m->clientIdsFor('nope'));
        $m->set(2, 'cust_x', null, 'manual');
        self::assertSame([2, 9], $m->clientIdsFor('cust_x'));
    }
}
