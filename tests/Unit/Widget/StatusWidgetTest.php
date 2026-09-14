<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Widget;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Widget\StatusWidget;

final class StatusWidgetTest extends DbTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        $s = Container::settings();
        $s->setApiToken('wsm_x');
        $s->setBool('connection_validated', true);
        $s->set('brand_name', 'Acme Monitor');
        $this->http = new FakeHttpClient();
        Container::useHttp($this->http);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Container::reset();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testNeverSyncedAllOkAndProblems(): void
    {
        $w = new StatusWidget();
        self::assertStringContainsString('Not synchronised yet', $w->generateOutput($w->getData()));
        (new StatusCache())->set('org', ['total' => 4, 'up' => 4, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('All Acme Monitor monitors are up', $html);
        self::assertStringContainsString('4 monitors, 0 paused', $html);
        (new StatusCache())->set('org', ['total' => 4, 'up' => 2, 'down' => 2, 'paused' => 0, 'incidents' => 1, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('2 monitors down, 1 open incident', $html);
        self::assertStringNotContainsString('1 open incidents', $html);
        self::assertStringContainsString('addonmodules.php?module=uptimeify&amp;action=dashboard', $html);
        self::assertSame([], $GLOBALS['uptimeify_test']['moduleCalls'], 'widget never calls the API');
        self::assertSame([], $this->http->requests(), 'widget never calls the API');
    }

    public function testPropertiesFollowTheSpec(): void
    {
        $w = new StatusWidget();
        self::assertSame('Acme Monitor', $this->property($w, 'title'));
        self::assertTrue($this->property($w, 'cache'));
        self::assertSame(300, $this->property($w, 'cacheExpiry'));
        self::assertSame(150, $this->property($w, 'weight'));
        self::assertSame(1, $this->property($w, 'columns'));
        self::assertSame('', $this->property($w, 'requiredPermission'));
    }

    public function testDataHoldsOnlyTheOrgSnapshot(): void
    {
        (new StatusCache())->set('org', ['total' => 1, 'down' => 0]);
        (new StatusCache())->set('last_reconcile', ['at' => '2026-09-14 00:00:00']);
        (new StatusCache())->set('client:1', ['totals' => []]);
        $data = (new StatusWidget())->getData();
        self::assertSame(['connected', 'brand', 'org'], array_keys($data));
        self::assertSame(['total' => 1, 'down' => 0], $data['org']);
    }

    public function testSingularAndPluralInEnglishAndGerman(): void
    {
        $w = new StatusWidget();
        (new StatusCache())->set('org', ['total' => 1, 'up' => 0, 'down' => 1, 'paused' => 0, 'incidents' => 2, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('1 monitor down, 2 open incidents', $html);
        self::assertStringContainsString('1 monitor, 0 paused', $html);

        $_SESSION['adminlang'] = 'german';
        self::assertStringContainsString('1 Monitor offline, 2 offene Vorfälle', $html = $w->generateOutput($w->getData()));
        self::assertStringContainsString('1 Monitor, 0 pausiert', $html);
        (new StatusCache())->set('org', ['total' => 5, 'up' => 1, 'down' => 3, 'paused' => 1, 'incidents' => 1, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('3 Monitore offline, 1 offener Vorfall', $html);
        self::assertStringContainsString('5 Monitore, 1 pausiert', $html);
        (new StatusCache())->set('org', ['total' => 5, 'up' => 5, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true]);
        self::assertStringContainsString('Alle Acme Monitor-Monitore sind online', $w->generateOutput($w->getData()));
    }

    public function testOpenIncidentsWithoutDownMonitorsAreAProblem(): void
    {
        $w = new StatusWidget();
        (new StatusCache())->set('org', ['total' => 3, 'up' => 3, 'down' => 0, 'paused' => 0, 'incidents' => 1, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('0 monitors down, 1 open incident', $html);
        self::assertStringContainsString('text-danger', $html);
        self::assertStringNotContainsString('are up', $html);
    }

    public function testIncompleteIncidentWindowAddsAHint(): void
    {
        $w = new StatusWidget();
        (new StatusCache())->set('org', ['total' => 3, 'up' => 3, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true, 'incidentsComplete' => false]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('All Acme Monitor monitors are up', $html);
        self::assertStringContainsString('Open incidents may be incomplete', $html);

        $_SESSION['adminlang'] = 'german';
        self::assertStringContainsString('Offene Vorfälle sind möglicherweise unvollständig', $w->generateOutput($w->getData()));

        (new StatusCache())->set('org', ['total' => 3, 'up' => 3, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true]);
        $_SESSION['adminlang'] = 'english';
        self::assertStringNotContainsString('may be incomplete', $w->generateOutput($w->getData()), 'an older snapshot without the flag counts as complete');
    }

    public function testUnavailableIncidentsAreNotShownAsZero(): void
    {
        $w = new StatusWidget();
        (new StatusCache())->set('org', ['total' => 3, 'up' => 3, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => false]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('0 monitors down, open incidents unknown', $html);
        self::assertStringNotContainsString('are up', $html);
        self::assertStringNotContainsString('0 open incidents', $html);
        self::assertStringContainsString('text-warning', $html);

        (new StatusCache())->set('org', ['total' => 3, 'up' => 1, 'down' => 2, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => false]);
        $_SESSION['adminlang'] = 'german';
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('2 Monitore offline, offene Vorfälle unbekannt', $html);
        self::assertStringContainsString('text-danger', $html);
    }

    public function testSyncedWithoutMonitors(): void
    {
        $w = new StatusWidget();
        (new StatusCache())->set('org', ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('No monitors mirrored yet', $html);
        self::assertStringNotContainsString('Not synchronised yet', $html);
        self::assertStringContainsString('action=dashboard', $html);
    }

    public function testNotConnectedShowsTheConnectHintInsteadOfTheCache(): void
    {
        (new StatusCache())->set('org', ['total' => 4, 'up' => 4, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true]);
        Container::settings()->setBool('connection_validated', false);
        $w = new StatusWidget();
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('Not connected to Acme Monitor', $html);
        self::assertStringContainsString('href="addonmodules.php?module=uptimeify&amp;action=dashboard"', $html);
        self::assertStringNotContainsString('are up', $html);

        $_SESSION['adminlang'] = 'german';
        self::assertStringContainsString('Nicht mit Acme Monitor verbunden', $w->generateOutput($w->getData()));
        self::assertSame([], $this->http->requests());
    }

    public function testMalformedCacheDoesNotBreakTheWidget(): void
    {
        $w = new StatusWidget();
        Capsule::table('mod_uptimeify_status_cache')->insert(['key' => 'org', 'value' => '{not json', 'refreshed_at' => Schema::now()]);
        self::assertStringContainsString('Not synchronised yet', $w->generateOutput($w->getData()));

        Capsule::table('mod_uptimeify_status_cache')->where('key', 'org')->update(['value' => '"just a string"']);
        self::assertStringContainsString('Not synchronised yet', $w->generateOutput($w->getData()));

        Capsule::table('mod_uptimeify_status_cache')->where('key', 'org')->update(['value' => '{"total":"many","down":[1]}']);
        self::assertStringContainsString('Not synchronised yet', $w->generateOutput($w->getData()));

        Capsule::table('mod_uptimeify_status_cache')->where('key', 'org')->update(['value' => '{"total":"2","down":{"a":1},"paused":"x","incidents":null}']);
        $html = $w->generateOutput($w->getData());
        self::assertStringContainsString('All Acme Monitor monitors are up', $html);
        self::assertStringContainsString('2 monitors, 0 paused', $html);

        self::assertStringContainsString('Not synchronised yet', $w->generateOutput(['connected' => true]));
        self::assertStringContainsString('Not connected', $w->generateOutput([]));
    }

    public function testOutputIsEscaped(): void
    {
        Container::settings()->set('brand_name', '<script>x</script> & Co');
        $w = new StatusWidget();
        self::assertSame('&lt;script&gt;x&lt;/script&gt; &amp; Co', $this->property($w, 'title'));
        (new StatusCache())->set('org', ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true]);
        $html = $w->generateOutput($w->getData());
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('All &lt;script&gt;x&lt;/script&gt; &amp; Co monitors are up', $html);
    }

    private function property(StatusWidget $widget, string $name): mixed
    {
        return (new \ReflectionProperty($widget, $name))->getValue($widget);
    }
}
