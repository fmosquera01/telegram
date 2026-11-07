<?php
/**
 * FML Telegram - central Telegram notification service for GLPI.
 */

use Glpi\Plugin\\Hooks;
use GlpiPlugin\Telegram\\ConfigPage;
use GlpiPlugin\Telegram\\NativeEvents;

define('PLUGIN_TELEGRAM_VERSION', '0.2.0');
define('PLUGIN_TELEGRAM_MIN_GLPI_VERSION', '11.0.11');
define('PLUGIN_TELEGRAM_MAX_GLPI_VERSION', '11.1.0');

function plugin_init_telegram()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['telegram'] = true;
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['telegram'] = [
        'plugins' => ConfigPage::class,
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['telegram'] = [
        'Ticket' => [NativeEvents::class, 'ticketAdded'],
        'Ticket_User' => [NativeEvents::class, 'ticketActorAdded'],
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
            ],
            'extensions' => [
                'curl',
                'sodium',
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
        && extension_loaded('curl')
        && extension_loaded('sodium');
}

function plugin_telegram_check_config($verbose = false)
{
    return true;
}
