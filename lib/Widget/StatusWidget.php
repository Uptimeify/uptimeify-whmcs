<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Widget;

use WHMCS\Module\AbstractWidget;
use WHMCS\Module\Addon\Uptimeify\Admin\View;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Lang;

/**
 * Status tile on the WHMCS admin home page (spec §9.2). Reads only the `org` snapshot of the status cache that the
 * reconcile writes; never calls the API, so loading the admin home page stays fast and works while Uptimeify is down.
 *
 * States: not connected (the dashboard link then opens the setup), never synchronised (no or unreadable `org`
 * snapshot), synchronised without monitors, all up, problems (down monitors and/or open incidents; "unknown" when the
 * last reconcile could not load the incidents). WHMCS caches getData() for $cacheExpiry seconds; generateOutput() gets that cached array,
 * so it must cope with any shape.
 */
final class StatusWidget extends AbstractWidget
{
    private const DASHBOARD_LINK = 'addonmodules.php?module=uptimeify&action=dashboard';

    // Untyped like WHMCS' AbstractWidget (a typed redeclaration is a fatal error).
    /** @var string */
    protected $title = 'Uptimeify';

    /** @var string */
    protected $description = 'Monitoring status of the mirrored WHMCS clients';

    /** @var int */
    protected $weight = 150;

    /** @var int */
    protected $columns = 1;

    /** @var bool */
    protected $cache = true;

    /** @var int */
    protected $cacheExpiry = 300;

    /** @var string */
    protected $requiredPermission = '';

    public function __construct()
    {
        // The admin theme may print the title into the widget header unescaped (spec §10: escape output).
        $this->title = View::e(Container::settings()->brandName());
    }

    /** @return array{connected:bool,brand:string,org:array<mixed>|null} */
    public function getData(): array
    {
        $settings = Container::settings();
        return [
            'connected' => $settings->isConnected(),
            'brand' => $settings->brandName(),
            'org' => Container::statusCache()->value('org'),
        ];
    }

    /** @param array<string,mixed> $data */
    public function generateOutput($data): string
    {
        $data = is_array($data) ? $data : [];
        $lang = Lang::load(Bridge::adminLanguage());
        $brand = is_string($data['brand'] ?? null) ? $data['brand'] : 'Uptimeify';
        /** @param array<string,scalar> $vars */
        $t = static fn (string $key, array $vars = []): string => Lang::t($lang, $key, $vars + ['brand' => $brand]);

        if (($data['connected'] ?? false) !== true) {
            return self::box('<p>' . View::e($t('widget.not_connected')) . '</p>', $t('widget.open_dashboard'));
        }
        $org = $data['org'] ?? null;
        $total = is_array($org) ? self::count($org['total'] ?? null) : null;
        if (!is_array($org) || $total === null) {
            return self::box('<p>' . View::e($t('widget.never_synced')) . '</p>', $t('widget.open_dashboard'));
        }
        if ($total === 0) {
            return self::box('<p>' . View::e($t('widget.no_monitors')) . '</p>', $t('widget.open_dashboard'));
        }

        $down = self::count($org['down'] ?? null) ?? 0;
        $paused = self::count($org['paused'] ?? null) ?? 0;
        $incidents = self::count($org['incidents'] ?? null) ?? 0;
        $incidentsAvailable = ($org['incidentsAvailable'] ?? true) !== false;

        if ($down === 0 && $incidentsAvailable && $incidents === 0) {
            [$text, $class, $icon] = [$t('widget.all_ok'), 'text-success', 'fa-check-circle'];
        } else {
            $text = $t('widget.problems', [
                'down' => $t(self::plural('widget.down', $down), ['count' => $down]),
                'incidents' => $incidentsAvailable ? $t(self::plural('widget.incidents', $incidents), ['count' => $incidents]) : $t('widget.incidents_unknown'),
            ]);
            $class = $down > 0 || $incidents > 0 ? 'text-danger' : 'text-warning';
            $icon = 'fa-exclamation-triangle';
        }

        // Only the newest incidents are read (round 2, D2): say so instead of implying the count is exact.
        $incomplete = $incidentsAvailable && ($org['incidentsComplete'] ?? true) === false
            ? '<p class="text-muted small">' . View::e($t('widget.incidents_incomplete')) . '</p>'
            : '';
        return self::box(
            '<h4 class="' . $class . '"><i class="fas ' . $icon . '"></i> ' . View::e($text) . '</h4>'
            . '<p class="text-muted">' . View::e($t(self::plural('widget.totals', $total), ['total' => $total, 'paused' => $paused])) . '</p>'
            . $incomplete,
            $t('widget.open_dashboard'),
        );
    }

    private static function box(string $body, string $linkLabel): string
    {
        return '<div class="widget-content-padded">' . $body
            . '<a class="btn btn-default btn-sm" href="' . View::e(self::DASHBOARD_LINK) . '">' . View::e($linkLabel) . '</a></div>';
    }

    private static function plural(string $key, int $count): string
    {
        return $key . ($count === 1 ? '.one' : '.other');
    }

    /** A non-negative count from the cache, or null when the value is not one (malformed snapshot). */
    private static function count(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
