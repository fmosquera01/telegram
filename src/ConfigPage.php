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
            'page'  => '/plugins/telegram/front/config.php',
            'icon'  => 'ti ti-brand-telegram',
            'options' => [
                'config' => [
                    'title' => __('Configuración', 'telegram'),
                    'page'  => '/plugins/telegram/front/config.php',
                ],
            ],
        ];
    }
}
