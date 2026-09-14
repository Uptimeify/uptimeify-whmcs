<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Bridge;

final class BridgeTest extends DbTestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['adminlang'], $_SESSION['Language']);
        parent::tearDown();
    }

    public function testEncryptIsReversibleAndNeverPlain(): void
    {
        $cipher = Bridge::encrypt('wsm_test-token');
        self::assertNotSame('wsm_test-token', $cipher);
        self::assertSame('wsm_test-token', Bridge::decrypt($cipher));
        self::assertSame(['EncryptPassword', 'DecryptPassword'], array_column($GLOBALS['uptimeify_test']['localApi'], 0));
    }

    public function testLoggingGoesThroughRecorders(): void
    {
        Bridge::logModuleCall('GET /api/organization', ['a' => 1], ['b' => 2], ['wsm_test-token']);
        Bridge::logActivity('hello', 5);
        Bridge::checkAdminToken();
        self::assertSame('uptimeify', $GLOBALS['uptimeify_test']['moduleCalls'][0]['module']);
        self::assertSame(['wsm_test-token'], $GLOBALS['uptimeify_test']['moduleCalls'][0]['replaceVars']);
        self::assertSame([['Uptimeify: hello', 5]], $GLOBALS['uptimeify_test']['activity']);
        self::assertSame(1, $GLOBALS['uptimeify_test']['tokenChecked']);
        self::assertStringContainsString('name="token"', Bridge::adminTokenField());
    }

    public function testAdminTokenFieldAsksWhmcsForTheFormOutputType(): void
    {
        self::assertSame('<input type="hidden" name="token" value="test-token">', Bridge::adminTokenField());
        self::assertSame(['form'], $GLOBALS['uptimeify_test']['tokenTypes'], 'generate_token takes an output type, not a namespace');
    }

    public function testAdminTokenFieldFailsLoudlyWithoutAHiddenField(): void
    {
        $GLOBALS['uptimeify_test']['tokenOutput'] = '';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('generate_token');
        Bridge::adminTokenField();
    }

    public function testRecorderIsResetBetweenTests(): void
    {
        self::assertSame(0, $GLOBALS['uptimeify_test']['tokenChecked']);
        self::assertTrue($GLOBALS['uptimeify_test']['tokenValid']);
        self::assertSame([], $GLOBALS['uptimeify_test']['tokenTypes']);
        self::assertNull($GLOBALS['uptimeify_test']['tokenOutput']);
        self::assertSame([], $GLOBALS['uptimeify_test']['moduleCalls']);
        self::assertSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testCurrentClientAndLanguages(): void
    {
        self::assertNull(Bridge::currentClientId());
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        self::assertSame(5, Bridge::currentClientId());
        self::assertSame('english', Bridge::adminLanguage());
        $_SESSION['adminlang'] = 'german';
        $_SESSION['Language'] = 'german';
        self::assertSame('german', Bridge::adminLanguage());
        self::assertSame('german', Bridge::clientLanguage());
    }

    public function testCurrentUserMayViewProductsOnlyAsOwnerOrWithTheProductsPermissionForThatClient(): void
    {
        self::assertFalse(Bridge::currentUserCanViewProducts(), 'not logged in');
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        self::assertTrue(Bridge::currentUserCanViewProducts(), 'the account owner');
        $GLOBALS['uptimeify_test']['userPermissions'] = ['invoices', 'tickets'];
        self::assertFalse(Bridge::currentUserCanViewProducts(), 'sub-user without products, although Client::hasPermission() says yes');
        self::assertSame(['GetUserPermissions', ['user_id' => 501, 'client_id' => 5]], end($GLOBALS['uptimeify_test']['localApi']), 'the permissions of this user for this client');
        $GLOBALS['uptimeify_test']['userPermissions'] = ['invoices', 'products'];
        self::assertTrue(Bridge::currentUserCanViewProducts());
    }

    public function testAnAdminAfterLoginAsOwnerPasses(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['userPermissions'] = [];
        $GLOBALS['uptimeify_test']['masquerading'] = true;
        self::assertTrue(Bridge::currentUserCanViewProducts(), 'isMasqueradingAdmin(): acting on behalf of the client after "Login as Owner"');
    }

    public function testTheOwnerPassesWithoutAskingForLoginAsOwner(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        self::assertTrue(Bridge::currentUserCanViewProducts());
        self::assertSame(0, $GLOBALS['uptimeify_test']['masqueradingChecked'], 'isOwnedBy() first, so a WHMCS build without isMasqueradingAdmin() never locks the owner out');
    }

    public function testLoginAsOwnerCheckIsSkippedWhenWhmcsHasNoSuchMethod(): void
    {
        $check = new \ReflectionMethod(Bridge::class, 'isMasqueradingAdmin');
        self::assertFalse($check->invoke(null, new \stdClass()), 'no method: not an admin, the sub-user check still decides');
        self::assertTrue($check->invoke(null, new class () {
            public function isMasqueradingAdmin(): bool
            {
                return true;
            }
        }));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function permissionResponses(): iterable
    {
        yield 'list with products' => [['result' => 'success', 'permissions' => ['products']], true];
        yield 'comma separated with products' => [['result' => 'success', 'permissions' => 'invoices, products'], true];
        yield 'list without products' => [['result' => 'success', 'permissions' => ['productsso', 'manageproducts']], false];
        yield 'documented response without a list' => [['result' => 'success'], false];
        yield 'error' => [['result' => 'error', 'message' => 'User is not associated with client', 'permissions' => ['products']], false];
        yield 'unknown shape' => [['result' => 'success', 'permissions' => ['products' => false]], false];
    }

    #[DataProvider('permissionResponses')]
    public function testSubUserPermissionResponsesFailClosed(mixed $response, bool $allowed): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['userPermissions'] = ['invoices'];
        $GLOBALS['uptimeify_test']['permissionsResponse'] = $response;
        self::assertSame($allowed, Bridge::currentUserCanViewProducts());
    }

    public function testMissingUserOrAnyErrorDenies(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['noUser'] = true;
        self::assertFalse(Bridge::currentUserCanViewProducts(), 'a client without a user');
        $GLOBALS['uptimeify_test']['noUser'] = false;
        $GLOBALS['uptimeify_test']['permissionError'] = true;
        self::assertFalse(Bridge::currentUserCanViewProducts(), 'fails closed');
    }

    public function testWhmcsCapsuleQueriesTheSqliteTables(): void
    {
        $id = $this->insert('tblclients', ['firstname' => 'Ada', 'email' => 'ada@example.test']);
        self::assertSame('Ada', Capsule::table('tblclients')->where('id', $id)->value('firstname'));
        foreach (['tblclients', 'tblhosting', 'tbldomains', 'tblservers', 'tbladdonmodules'] as $table) {
            self::assertTrue(Capsule::schema()->hasTable($table), $table);
        }
    }
}
