<?php

namespace GlpiPlugin\Telegram;

use CommonGLPI;

final class ConfigPage extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('FML Telegram', 'telegram');
    }

    public static function getMenuName($nb = 0): string
    {
        return __('FML Telegram', 'telegram');
    }

    public static function getMenuContent(): array
    {
        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/telegram/front/config.php?section=home',
            'icon'  => 'ti ti-brand-telegram',
            'options' => [
                'home' => [
                    'title' => __('Inicio', 'telegram'),
                    'page'  => '/plugins/telegram/front/config.php?section=home',
                ],
                'notifications' => [
                    'title' => __('Notificaciones', 'telegram'),
                    'page'  => '/plugins/telegram/front/config.php?section=notifications',
                ],
                'queue' => [
                    'title' => __('Cola de salida', 'telegram'),
                    'page'  => '/plugins/telegram/front/config.php?section=queue',
                ],
                'ad' => [
                    'title' => __('Active Directory', 'telegram'),
                    'page'  => '/plugins/telegram/front/config.php?section=ad',
                ],
            ],
        ];
    }
}
