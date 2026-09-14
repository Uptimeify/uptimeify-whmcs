<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for WHMCS globals. Loaded only in tests (bootstrap) and as PHPStan scanFile.
 *
 * Recorder: $GLOBALS['uptimeify_test'] = [
 *     'hooks' => [hookName => [callable, ...]],
 *     'moduleCalls' => [['module', 'action', 'request', 'response', 'replaceVars'], ...],
 *     'activity' => [[message, clientId], ...],
 *     'localApi' => [[command, values], ...],
 *     'clientId' => ?int (the logged-in client returned by CurrentUser::client()),
 *     'userPermissions' => ?list<string> (the user ↔ client link, like WHMCS' users-to-clients relation: null: the
 *                          logged-in user owns the client; a list: a sub-user with these WHMCS permissions for it,
 *                          e.g. 'products'; GetUserPermissions answers from it),
 *     'permissionsResponse' => mixed (null: GetUserPermissions answers as modelled; anything else is returned as is),
 *     'masquerading' => bool (CurrentUser::isMasqueradingAdmin(): an admin after "Login as Owner"),
 *     'masqueradingChecked' => int (number of CurrentUser::isMasqueradingAdmin() calls),
 *     'noUser' => bool (CurrentUser::user() returns null although a client is logged in),
 *     'permissionError' => bool (CurrentUser::user() throws, like an unexpected WHMCS failure),
 *     'tokenChecked' => int (number of check_token() calls),
 *     'tokenValid' => bool (false: check_token() stops the request like WHMCS does, here by throwing),
 *     'tokenTypes' => list<string> (output types passed to generate_token()),
 *     'tokenOutput' => mixed (null: the normal output for the type; anything else is returned as is),
 * ].
 * The admin and client language are not part of the recorder: Bridge reads them from
 * $_SESSION['adminlang'] and $_SESSION['Language'], so tests set $_SESSION directly.
 */

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    $GLOBALS['uptimeify_test'] = [
        'hooks' => [],
        'moduleCalls' => [],
        'activity' => [],
        'localApi' => [],
        'clientId' => null,
        'userPermissions' => null,
        'permissionsResponse' => null,
        'masquerading' => false,
        'masqueradingChecked' => 0,
        'noUser' => false,
        'permissionError' => false,
        'tokenChecked' => 0,
        'tokenValid' => true,
        'tokenTypes' => [],
        'tokenOutput' => null,
    ];

    if (!function_exists('add_hook')) {
        function add_hook(string $hook, int $priority, callable $fn): void
        {
            $GLOBALS['uptimeify_test']['hooks'][$hook][] = $fn;
        }
    }

    if (!function_exists('logModuleCall')) {
        /** @param array<int,string> $replaceVars */
        function logModuleCall(string $module, string $action, mixed $request, mixed $response, mixed $processed = '', array $replaceVars = []): void
        {
            $GLOBALS['uptimeify_test']['moduleCalls'][] = compact('module', 'action', 'request', 'response', 'replaceVars');
        }
    }

    if (!function_exists('logActivity')) {
        function logActivity(string $message, int $clientId = 0): void
        {
            $GLOBALS['uptimeify_test']['activity'][] = [$message, $clientId];
        }
    }

    if (!function_exists('localAPI')) {
        /**
         * Fake EncryptPassword/DecryptPassword are reversible: enc:<base64>.
         *
         * @param array<string,mixed> $values
         * @return array<string,mixed>
         */
        function localAPI(string $command, array $values = [], ?string $admin = null): array
        {
            $GLOBALS['uptimeify_test']['localApi'][] = [$command, $values];
            return match ($command) {
                'GetUserPermissions' => uptimeify_stub_user_permissions($values),
                'EncryptPassword' => ['result' => 'success', 'password' => 'enc:' . base64_encode((string) $values['password2'])],
                'DecryptPassword' => ['result' => 'success', 'password' => base64_decode(substr((string) $values['password2'], 4))],
                default => ['result' => 'error', 'message' => 'stub: unknown command ' . $command],
            };
        }
    }

    if (!function_exists('uptimeify_stub_user_permissions')) {
        /**
         * GetUserPermissions (WHMCS 8.0+, request `user_id`, `client_id`). The documented response has only `result`; the
         * stub assumes a `permissions` list, which the sandbox smoke test must confirm. A user not linked to the client
         * gets the documented error "User is not associated with client".
         *
         * @param array<string,mixed> $values
         * @return array<string,mixed>
         */
        function uptimeify_stub_user_permissions(array $values): array
        {
            if ($GLOBALS['uptimeify_test']['permissionsResponse'] !== null) {
                return (array) $GLOBALS['uptimeify_test']['permissionsResponse'];
            }
            $clientId = $GLOBALS['uptimeify_test']['clientId'];
            $permissions = $GLOBALS['uptimeify_test']['userPermissions'];
            if ($clientId === null || (int) ($values['client_id'] ?? 0) !== $clientId || (int) ($values['user_id'] ?? 0) !== $clientId * 100 + ($permissions === null ? 0 : 1)) {
                return ['result' => 'error', 'message' => 'User is not associated with client'];
            }
            return ['result' => 'success', 'permissions' => $permissions ?? ['profile', 'contacts', 'products', 'manageproducts', 'productsso', 'domains', 'managedomains', 'invoices', 'quotes', 'tickets', 'affiliates', 'emails', 'orders']];
        }
    }

    if (!function_exists('check_token')) {
        /** WHMCS ends the request on an invalid token; the stub throws instead. */
        function check_token(string $namespace = 'WHMCS.default'): void
        {
            $GLOBALS['uptimeify_test']['tokenChecked']++;
            if ($GLOBALS['uptimeify_test']['tokenValid'] !== true) {
                throw new \RuntimeException('stub: invalid CSRF token');
            }
        }
    }

    if (!function_exists('generate_token')) {
        /**
         * Like WHMCS: the argument is the OUTPUT TYPE ('form' hidden input, 'link' query fragment, 'plain' token),
         * not a namespace. WHMCS does not declare a return type.
         */
        function generate_token(string $type = 'form'): mixed
        {
            $GLOBALS['uptimeify_test']['tokenTypes'][] = $type;
            if ($GLOBALS['uptimeify_test']['tokenOutput'] !== null) {
                return $GLOBALS['uptimeify_test']['tokenOutput'];
            }
            return match ($type) {
                'form' => '<input type="hidden" name="token" value="test-token">',
                'link' => '&token=test-token',
                'plain' => 'test-token',
                default => null,
            };
        }
    }
}

namespace WHMCS\Database {
    // A real subclass instead of class_alias: PHPStan only parses scanFiles, it never executes class_alias.
    // The static $instance lives on the Illuminate Manager, so setAsGlobal() and static calls keep working.
    if (!class_exists(Capsule::class, false)) {
        class Capsule extends \Illuminate\Database\Capsule\Manager
        {
        }
    }
}

namespace WHMCS\Module {
    if (!class_exists(AbstractWidget::class, false)) {
        abstract class AbstractWidget
        {
            // Untyped like real WHMCS, so widgets can redeclare them untyped.
            /** @var string */
            protected $title = '';

            /** @var string */
            protected $description = '';

            /** @var int */
            protected $weight = 100;

            /** @var int */
            protected $columns = 1;

            /** @var bool */
            protected $cache = false;

            /** @var int */
            protected $cacheExpiry = 120;

            /** @var string */
            protected $requiredPermission = '';

            /** @return array<string,mixed> */
            abstract public function getData(): array;

            /** @param array<string,mixed> $data */
            abstract public function generateOutput($data): string;
        }
    }
}

namespace WHMCS\User {
    if (!class_exists(User::class, false)) {
        /** The documented parts of WHMCS\User\User the addon uses (WHMCS 8.0+). */
        final class User
        {
            public function __construct(public readonly int $id)
            {
            }
        }
    }

    if (!class_exists(Client::class, false)) {
        /**
         * The documented parts of WHMCS\User\Client (developers.whmcs.com/classes/whmcs/user/client): `isOwnedBy(User): bool`
         * and `hasPermission(string|int): bool`. hasPermission() takes no user ("fulfills the UserInterface" of the client
         * itself), so the stub answers true for every permission: the addon must never rely on it for a sub-user.
         */
        final class Client
        {
            public function __construct(public readonly int $id)
            {
            }

            public function isOwnedBy(User $user): bool
            {
                return $GLOBALS['uptimeify_test']['userPermissions'] === null && $user->id === $this->id * 100;
            }

            public function hasPermission(string|int $permission): bool
            {
                return true;
            }
        }
    }
}

namespace WHMCS\Authentication {
    if (!class_exists(CurrentUser::class, false)) {
        final class CurrentUser
        {
            public function client(): ?\WHMCS\User\Client
            {
                $id = $GLOBALS['uptimeify_test']['clientId'];
                return $id === null ? null : new \WHMCS\User\Client($id);
            }

            /** The owner of client N is user N * 100; a sub-user gets another id. */
            public function user(): ?\WHMCS\User\User
            {
                if ($GLOBALS['uptimeify_test']['permissionError'] === true) {
                    throw new \RuntimeException('stub: user lookup failed');
                }
                $id = $GLOBALS['uptimeify_test']['clientId'];
                if ($id === null || $GLOBALS['uptimeify_test']['noUser'] === true) {
                    return null;
                }
                return new \WHMCS\User\User($GLOBALS['uptimeify_test']['userPermissions'] === null ? $id * 100 : $id * 100 + 1);
            }

            public function isMasqueradingAdmin(): bool
            {
                $GLOBALS['uptimeify_test']['masqueradingChecked']++;
                return $GLOBALS['uptimeify_test']['masquerading'] === true;
            }
        }
    }
}

namespace WHMCS\View\Menu {
    if (!class_exists(Item::class, false)) {
        /**
         * Like WHMCS' menu item: addChild() returns the NEW child and getChild() returns that child (or null), so a
         * test can tell an item added under "Services" from one added at the root.
         */
        final class Item
        {
            /** @var array<string, self> */
            public array $children = [];

            /** @param array<string,mixed> $attributes */
            public function __construct(public readonly string $name = 'root', public readonly array $attributes = [])
            {
            }

            /** @param array<string,mixed> $attributes */
            public function addChild(string $name, array $attributes = []): self
            {
                return $this->children[$name] = new self($name, $attributes);
            }

            public function getChild(string $name): ?self
            {
                return $this->children[$name] ?? null;
            }
        }
    }
}
