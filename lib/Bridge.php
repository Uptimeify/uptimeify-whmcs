<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify;

/**
 * Every WHMCS global the module touches goes through here, so tests stub one file
 * and an undocumented WHMCS API change breaks one file.
 */
final class Bridge
{
    public const MODULE = 'uptimeify';

    /** The WHMCS user permission for "view products & services" (GetPermissionsList). */
    public const PRODUCTS_PERMISSION = 'products';

    public static function encrypt(string $plain): string
    {
        $result = localAPI('EncryptPassword', ['password2' => $plain]);
        if (($result['result'] ?? '') !== 'success' || !isset($result['password'])) {
            throw new \RuntimeException('WHMCS EncryptPassword failed: ' . ($result['message'] ?? 'unknown'));
        }
        return (string) $result['password'];
    }

    public static function decrypt(string $cipher): string
    {
        $result = localAPI('DecryptPassword', ['password2' => $cipher]);
        if (($result['result'] ?? '') !== 'success' || !isset($result['password'])) {
            throw new \RuntimeException('WHMCS DecryptPassword failed: ' . ($result['message'] ?? 'unknown'));
        }
        return (string) $result['password'];
    }

    /** @param array<int,string> $replaceVars */
    public static function logModuleCall(string $action, mixed $request, mixed $response, array $replaceVars = []): void
    {
        logModuleCall(self::MODULE, $action, $request, $response, '', $replaceVars);
    }

    public static function logActivity(string $message, int $clientId = 0): void
    {
        logActivity('Uptimeify: ' . $message, $clientId);
    }

    public static function checkAdminToken(): void
    {
        check_token('WHMCS.admin.default');
    }

    /**
     * The hidden CSRF field for admin forms. `generate_token()` takes an output type ('form', 'link', 'plain'), unlike
     * `check_token()`, which takes the namespace; WHMCS declares no return type, so anything but a non-empty field fails
     * loudly instead of rendering forms whose every POST would be rejected.
     */
    public static function adminTokenField(): string
    {
        $field = generate_token('form');
        if (!is_string($field) || !str_contains($field, 'token')) {
            throw new \RuntimeException('WHMCS generate_token(\'form\') returned no CSRF token field');
        }
        return $field;
    }

    public static function currentClientId(): ?int
    {
        $client = (new \WHMCS\Authentication\CurrentUser())->client();
        return $client === null ? null : (int) $client->id;
    }

    /**
     * Whether the logged-in WHMCS user may see the products and services of the logged-in client account (round 2, D10):
     * the account owner, an admin acting on behalf of the client after "Login as Owner", or a user whose permissions for
     * THIS client include `products`.
     *
     * Documented WHMCS 8.0+ pieces only (developers.whmcs.com, classdocs.whmcs.com): `CurrentUser::client()`, `user()`,
     * `isMasqueradingAdmin()` ("authenticated as an Admin but also acting on behalf of the client, having performed a
     * Login as Owner"), `WHMCS\User\Client::isOwnedBy(User): bool`, and the local API `GetUserPermissions`
     * (`user_id`, `client_id`: "Provide the permissions of a user for a client"). `Client::hasPermission()` is NOT used:
     * it takes no user, so it cannot answer for a sub-user.
     *
     * The documented GetUserPermissions response lists only `result`; a `permissions` list (or comma-separated string,
     * the format UpdateUserPermissions takes) is required here, so any other shape denies (sandbox smoke test). Fails
     * closed: no client, no user, an error, an unknown shape or any exception means no permission. The owner check runs
     * first and `isMasqueradingAdmin()` only where the WHMCS build has it, so neither can lock the owner out.
     */
    public static function currentUserCanViewProducts(): bool
    {
        try {
            $current = new \WHMCS\Authentication\CurrentUser();
            $client = $current->client();
            if ($client === null) {
                return false;
            }
            $user = $current->user();
            if ($user !== null && $client->isOwnedBy($user)) {
                return true;
            }
            if (self::isMasqueradingAdmin($current)) {
                return true;
            }
            if ($user === null) {
                return false;
            }
            $userId = (int) $user->id;
            $clientId = (int) $client->id;
            if ($userId <= 0 || $clientId <= 0) {
                return false;
            }
            $result = localAPI('GetUserPermissions', ['user_id' => $userId, 'client_id' => $clientId]);
            return is_array($result) && ($result['result'] ?? null) === 'success'
                && in_array(self::PRODUCTS_PERMISSION, self::permissionList($result['permissions'] ?? null), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** "Login as Owner" (CurrentUser::isMasqueradingAdmin()); false on a WHMCS build without that method. */
    private static function isMasqueradingAdmin(object $currentUser): bool
    {
        return method_exists($currentUser, 'isMasqueradingAdmin') && $currentUser->isMasqueradingAdmin() === true;
    }

    /** @return list<string> permission names from a list of strings or a comma-separated string; [] for anything else */
    private static function permissionList(mixed $permissions): array
    {
        if (is_string($permissions)) {
            $permissions = explode(',', $permissions);
        }
        if (!is_array($permissions) || !array_is_list($permissions)) {
            return [];
        }
        $out = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                return [];
            }
            $out[] = trim($permission);
        }
        return $out;
    }

    public static function adminLanguage(): string
    {
        return (string) ($_SESSION['adminlang'] ?? 'english');
    }

    public static function clientLanguage(): string
    {
        return (string) ($_SESSION['Language'] ?? 'english');
    }
}
