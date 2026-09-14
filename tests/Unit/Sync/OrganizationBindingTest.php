<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Sync\OrganizationBinding;
use WHMCS\Module\Addon\Uptimeify\Sync\OrganizationMismatchException;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;

/** Round 2, D9: the installation is bound to one Uptimeify organization, verified once per run. */
final class OrganizationBindingTest extends DbTestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new Settings();
    }

    private function binding(FakeHttpClient $http, int $retries = 0): OrganizationBinding
    {
        return new OrganizationBinding($this->settings, new Client($http, 'https://api.test', 'wsm_x'), new RateLimitGuard(static fn (int $s) => null, $retries));
    }

    private static function org(string $publicId, string $name): FakeHttpClient
    {
        return (new FakeHttpClient())->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => $publicId, 'name' => $name, 'status' => 'active']);
    }

    public function testAnUnboundInstallationIsBoundOnTheFirstRun(): void
    {
        $http = self::org('o1', 'Acme Org');
        $this->binding($http)->verify();
        self::assertSame('o1', $this->settings->organizationPublicId());
        self::assertSame('Acme Org', $this->settings->organizationName());
        self::assertCount(1, $http->requests(), 'one cheap request');
    }

    public function testTheBoundOrganizationPassesAndClearsAnEarlierMismatch(): void
    {
        $this->settings->set('organization_public_id', 'o1');
        $this->settings->setJson('organization_mismatch', ['publicId' => 'o2', 'name' => 'Other']);
        $this->binding(self::org('o1', 'Acme Org'))->verify();
        self::assertNull($this->settings->organizationMismatch());
        self::assertSame('o1', $this->settings->organizationPublicId());
    }

    public function testAnotherOrganizationIsRecordedLoggedOnceAndStopsTheRun(): void
    {
        $this->settings->set('organization_public_id', 'o1');
        $this->settings->set('organization_name', 'Acme Org');
        foreach ([1, 2] as $_) {
            try {
                $this->binding(self::org('o2', 'Other Org'))->verify();
                self::fail('expected OrganizationMismatchException');
            } catch (OrganizationMismatchException $e) {
                self::assertSame(['o1', 'o2', 'Other Org'], [$e->boundPublicId, $e->publicId, $e->name]);
            }
        }
        self::assertSame(['publicId' => 'o2', 'name' => 'Other Org'], $this->settings->organizationMismatch());
        self::assertSame('o1', $this->settings->organizationPublicId(), 'the binding never moves by itself');
        self::assertSame('Acme Org', $this->settings->organizationName());
        $lines = array_filter($GLOBALS['uptimeify_test']['activity'], static fn (array $a): bool => str_contains($a[0], 'another organization'));
        self::assertCount(1, $lines, 'logged when it starts, not on every run');
    }

    public function testAResponseWithoutPublicIdIsAnInvalidResponse(): void
    {
        $this->settings->set('organization_public_id', 'o1');
        $http = (new FakeHttpClient())->on('GET', '/api/organization', 200, ['id' => 7, 'name' => 'Acme Org']);
        try {
            $this->binding($http)->verify();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(Client::INVALID_RESPONSE_CODE, $e->errorCode);
        }
        self::assertNull($this->settings->organizationMismatch());
    }

    public function testTheGuardIsUsedSoAnInteractiveGuardNeverSleeps(): void
    {
        $this->settings->set('organization_public_id', 'o1');
        $http = (new FakeHttpClient())->on('GET', '/api/organization', 429, ['statusMessage' => 'Too Many Requests'], ['Retry-After' => '30']);
        $this->expectException(RateLimitedException::class);
        $this->binding($http)->verify();
    }
}
