<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;

abstract class DbTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Fresh database: forget the shared Settings instance and any injected transport of the previous test.
        Container::reset();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->createWhmcsTables();
        $GLOBALS['uptimeify_test']['hooks'] = [];
        $GLOBALS['uptimeify_test']['moduleCalls'] = [];
        $GLOBALS['uptimeify_test']['activity'] = [];
        $GLOBALS['uptimeify_test']['localApi'] = [];
        $GLOBALS['uptimeify_test']['clientId'] = null;
        $GLOBALS['uptimeify_test']['userPermissions'] = null;
        $GLOBALS['uptimeify_test']['permissionsResponse'] = null;
        $GLOBALS['uptimeify_test']['masquerading'] = false;
        $GLOBALS['uptimeify_test']['masqueradingChecked'] = 0;
        $GLOBALS['uptimeify_test']['noUser'] = false;
        $GLOBALS['uptimeify_test']['permissionError'] = false;
        $GLOBALS['uptimeify_test']['tokenChecked'] = 0;
        $GLOBALS['uptimeify_test']['tokenValid'] = true;
        $GLOBALS['uptimeify_test']['tokenTypes'] = [];
        $GLOBALS['uptimeify_test']['tokenOutput'] = null;
    }

    private function createWhmcsTables(): void
    {
        $schema = Capsule::schema();
        $schema->create('tbladdonmodules', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('module');
            $t->string('setting');
            $t->text('value');
        });
        $schema->create('tblclients', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('firstname')->default('');
            $t->string('lastname')->default('');
            $t->string('companyname')->default('');
            $t->string('email')->default('');
            $t->string('status')->default('Active');
            $t->string('language')->default('');
        });
        $schema->create('tblhosting', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('userid');
            $t->integer('packageid')->default(0);
            $t->integer('server')->default(0);
            $t->string('domain')->default('');
            $t->string('domainstatus')->default('Active');
        });
        $schema->create('tbldomains', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('userid');
            $t->string('domain')->default('');
            $t->string('status')->default('Active');
            $t->string('registrar')->default('');
            $t->date('expirydate')->nullable();
        });
        $schema->create('tblservers', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name')->default('');
            $t->string('ipaddress')->default('');
            $t->string('hostname')->default('');
            $t->integer('disabled')->default(0);
        });
    }

    /** @param array<string,mixed> $row */
    protected function insert(string $table, array $row): int
    {
        return (int) Capsule::table($table)->insertGetId($row);
    }
}
