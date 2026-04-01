<?php

namespace Aliha\AiAltSubscriptionHelper;

class Installer
{
    public static function activate()
    {
        if (!wp_next_scheduled('atg_every_twelve_hours')) {
            wp_schedule_event(time(), 'twicedaily', 'atg_every_twelve_hours');
        }
    }

    public static function deactivate()
    {
        $timestamp = wp_next_scheduled('atg_every_twelve_hours');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'atg_every_twelve_hours');
        }
    }
}