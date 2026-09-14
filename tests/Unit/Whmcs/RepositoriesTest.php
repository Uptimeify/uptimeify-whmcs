<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Whmcs;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

final class RepositoriesTest extends DbTestCase
{
    public function testClientDisplayNamePrefersCompany(): void
    {
        $a = $this->insert('tblclients', ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'companyname' => '', 'email' => 'ADA@Example.com']);
        $b = $this->insert('tblclients', ['firstname' => 'Bob', 'lastname' => 'B', 'companyname' => 'Bob Corp', 'email' => 'bob@example.com', 'status' => 'Closed']);
        $repo = new ClientRepository();
        $ada = $repo->find($a);
        self::assertNotNull($ada);
        self::assertSame('Ada Lovelace', $ada->displayName);
        self::assertSame('ada@example.com', $ada->email);
        self::assertSame('Bob Corp', $repo->all()[$b]->displayName);
        self::assertSame('Closed', $repo->all()[$b]->status);
        self::assertNull($repo->find(999));
    }

    public function testClientRowTrimsNamesAndEmail(): void
    {
        $a = $this->insert('tblclients', ['firstname' => '  Ada ', 'lastname' => '', 'companyname' => '   ', 'email' => ' Ada@Example.COM ']);
        $b = $this->insert('tblclients', ['firstname' => '', 'lastname' => 'Lovelace', 'companyname' => ' ACME GmbH ']);
        $c = $this->insert('tblclients', ['email' => ' JÜRGEN@Müller.EXAMPLE ']);
        $repo = new ClientRepository();
        $client = $repo->find($a);
        self::assertNotNull($client);
        self::assertSame($a, $client->id);
        self::assertSame('Ada', $client->displayName);
        self::assertSame('ada@example.com', $client->email);
        self::assertSame('Active', $client->status);
        self::assertSame('ACME GmbH', $repo->find($b)?->displayName);
        self::assertSame('jürgen@müller.example', $repo->find($c)?->email);
        self::assertSame([$a, $b, $c], array_keys($repo->all()));
    }

    public function testHostingActiveFiltersStatusAndEmptyDomain(): void
    {
        $c = $this->insert('tblclients', ['email' => 'x@example.com']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'a.example', 'domainstatus' => 'Active', 'server' => 1]);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'b.example', 'domainstatus' => 'Suspended', 'server' => 1]);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => '', 'domainstatus' => 'Active', 'server' => 2]);
        $repo = new HostingRepository();
        self::assertSame(['a.example'], array_map(fn ($h) => $h->domain, $repo->active()));
        self::assertSame([$c], $repo->activeClientIdsForServer(1));
        self::assertSame([$c], $repo->activeClientIdsForServer(2), 'a hosting without a domain (VPS, dedicated) uses the server IP too');
        self::assertTrue($repo->clientHasHostingOnServer($c, 1, 'Suspended'));
        self::assertTrue($repo->clientHasHostingOnServer($c, 2, 'Active'));
        self::assertFalse($repo->clientHasHostingOnServer($c, 2, 'Suspended'));
        self::assertFalse($repo->clientHasHostingOnServer($c + 1, 1, 'Active'));
        self::assertSame('Suspended', $repo->find(2)?->status);
    }

    public function testHostingRowFieldsAndWhitespaceDomain(): void
    {
        $c = $this->insert('tblclients', ['email' => 'x@example.com']);
        $id = $this->insert('tblhosting', ['userid' => $c, 'domain' => ' shop.example ', 'domainstatus' => 'Active', 'server' => 7]);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => '   ', 'domainstatus' => 'Active', 'server' => 7]);
        $active = (new HostingRepository())->active();
        self::assertCount(1, $active);
        self::assertSame($id, $active[0]->id);
        self::assertSame($c, $active[0]->clientId);
        self::assertSame('shop.example', $active[0]->domain);
        self::assertSame('Active', $active[0]->status);
        self::assertSame(7, $active[0]->serverId);
        self::assertNull((new HostingRepository())->find(999));
    }

    public function testActiveClientIdsForServerAreDistinctSortedAndActiveOnly(): void
    {
        $c1 = $this->insert('tblclients', ['email' => 'one@example.com']);
        $c2 = $this->insert('tblclients', ['email' => 'two@example.com']);
        $c3 = $this->insert('tblclients', ['email' => 'three@example.com']);
        $this->insert('tblhosting', ['userid' => $c2, 'domain' => 'b1.example', 'domainstatus' => 'Active', 'server' => 5]);
        $this->insert('tblhosting', ['userid' => $c1, 'domain' => 'a1.example', 'domainstatus' => 'Active', 'server' => 5]);
        $this->insert('tblhosting', ['userid' => $c2, 'domain' => 'b2.example', 'domainstatus' => 'Active', 'server' => 5]);
        $this->insert('tblhosting', ['userid' => $c3, 'domain' => 'c1.example', 'domainstatus' => 'Terminated', 'server' => 5]);
        $this->insert('tblhosting', ['userid' => $c3, 'domain' => 'c2.example', 'domainstatus' => 'Active', 'server' => 6]);
        self::assertSame([$c1, $c2], (new HostingRepository())->activeClientIdsForServer(5));
    }

    public function testDomainAndServerRepositories(): void
    {
        $c = $this->insert('tblclients', ['email' => 'x@example.com']);
        $this->insert('tbldomains', ['userid' => $c, 'domain' => 'reg.example', 'status' => 'Active', 'expirydate' => '2027-01-31', 'registrar' => 'inwx']);
        $this->insert('tbldomains', ['userid' => $c, 'domain' => 'old.example', 'status' => 'Expired']);
        $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10', 'disabled' => 0]);
        $this->insert('tblservers', ['name' => 'web2', 'ipaddress' => '203.0.113.11', 'disabled' => 1]);
        $this->insert('tblservers', ['name' => 'web3', 'ipaddress' => '', 'disabled' => 0]);
        $domains = (new DomainRepository())->active();
        self::assertCount(1, $domains);
        self::assertSame('2027-01-31', $domains[0]->expiryDate);
        self::assertSame('inwx', $domains[0]->registrar);
        self::assertSame(['web1'], array_map(fn ($s) => $s->name, (new ServerRepository())->active()));
    }

    public function testDomainRowFieldsEmptyExpiryAndEmptyDomain(): void
    {
        $c = $this->insert('tblclients', ['email' => 'x@example.com']);
        $noExpiry = $this->insert('tbldomains', ['userid' => $c, 'domain' => ' NoExpiry.example ', 'status' => 'Active']);
        $zero = $this->insert('tbldomains', ['userid' => $c, 'domain' => 'zero.example', 'status' => 'Active', 'expirydate' => '0000-00-00']);
        $this->insert('tbldomains', ['userid' => $c, 'domain' => '', 'status' => 'Active']);
        $this->insert('tbldomains', ['userid' => $c, 'domain' => '  ', 'status' => 'Active']);
        $repo = new DomainRepository();
        $active = $repo->active();
        self::assertSame([$noExpiry, $zero], array_map(fn ($d) => $d->id, $active));
        self::assertSame($c, $active[0]->clientId);
        self::assertSame('NoExpiry.example', $active[0]->domain);
        self::assertSame('Active', $active[0]->status);
        self::assertNull($active[0]->expiryDate);
        self::assertSame('', $active[0]->registrar);
        self::assertNull($active[1]->expiryDate);
        self::assertSame('zero.example', $repo->find($zero)?->domain);
        self::assertNull($repo->find(999));
    }

    public function testServerRowFields(): void
    {
        $id = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => ' 2001:db8::10 ', 'disabled' => 0]);
        $servers = (new ServerRepository())->active();
        self::assertCount(1, $servers);
        self::assertSame($id, $servers[0]->id);
        self::assertSame('web1', $servers[0]->name);
        self::assertSame('2001:db8::10', $servers[0]->ip);
    }
}
