<?php

namespace Aliha\AiAltSubscriptionHelper;

class AiAltTextHelper
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function run()
    {
        new \Aliha\AiAltSubscriptionHelper\Schedule();
        new \Aliha\AiAltSubscriptionHelper\Subscription();
    }

}