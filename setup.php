<?php
/**
 * FML Telegram - central Telegram notification service for GLPI.
 */

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Telegram\ConfigPage;
use GlpiPlugin\Telegram\NativeEvents;

define('PLUGIN_TELEGRAM_VERSION', '0.2.88');
define('PLUGIN_TELEGRAM_MIN_GLPI_VERSION', '11.0.11');
define('PLUGIN_TELEGRAM_MAX_GLPI_VERSION', '11.1.0');

/**
 * Register the HMAC-authenticated Windows agent endpoint as stateless.
 *
 * GLPI 11 otherwise starts a browser session and redirects anonymous
 * requests to the login page before the endpoint can validate its HMAC.
 */
function plugin_telegram_boot(): void
{
    \Glpi\Http\SessionManager::registerPluginStatelessPath(
        'telegram',
        '#^/front/ad-agent\.php$#'
    );
}

function plugin_init_telegram()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['telegram'] = true;

    // This endpoint authenticates requests with its own per-agent HMAC.
    // GLPI 11 defaults legacy front scripts to authenticated-only (404 for
    // anonymous callers), so explicitly allow the script to reach HMAC auth.
    Firewall::addPluginStrategyForLegacyScripts(
        'telegram',
        '#^/front/ad-agent\.php$#',
        Firewall::STRATEGY_NO_CHECK
    );
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['telegram'] = [
        'plugins' => ConfigPage::class,
    ];
    $PLUGIN_HOOKS[Hooks::POST_INVENTORY]['telegram'] = [NativeEvents::class, 'inventoryReceived'];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['telegram'] = [
        'Ticket' => [NativeEvents::class, 'ticketAdded'],
        'Ticket_User' => [NativeEvents::class, 'ticketActorAdded'],
        'Group_Ticket' => [NativeEvents::class, 'ticketGroupAdded'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['telegram'] = [
        'Ticket' => [NativeEvents::class, 'ticketUpdated'],
    ];
}

function plugin_version_telegram()
{
    return [
        'name'           => 'FML Telegram',
        'version'        => PLUGIN_TELEGRAM_VERSION,
        'author'         => 'Franklin Mosquera',
        'license'        => 'Proprietary',
        'homepage'       => 'https://github.com/fmosquera01/telegram',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_TELEGRAM_MIN_GLPI_VERSION,
                'max' => PLUGIN_TELEGRAM_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => '8.2',
                'exts' => [
                    'curl',
                    'sodium',
                    'mbstring',
                ],
            ],
        ],
    ];
}

function plugin_telegram_check_prerequisites()
{
    if (!defined('GLPI_VERSION')) {
        return false;
    }

    return version_compare(GLPI_VERSION, PLUGIN_TELEGRAM_MIN_GLPI_VERSION, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_TELEGRAM_MAX_GLPI_VERSION, '<')
        && version_compare(PHP_VERSION, '8.2.0', '>=')
        && extension_loaded('curl')
        && extension_loaded('sodium')
        && extension_loaded('mbstring');
}

function plugin_telegram_check_config($verbose = false)
{
    return true;
}
