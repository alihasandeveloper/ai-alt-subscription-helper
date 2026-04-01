<?php
/**
 * Plugin Name: AI Alt Subscription Helper
 * Description: Custom helper for SureCart subscription automation
 * Version: 1.0
 * Author: Boomdevs LLC
 * Author URI: https://boomdevs.com
 */

if (!defined('ABSPATH')) {
    exit;
}

// Require composer autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

register_activation_hook(__FILE__, ['\Aliha\AiAltSubscriptionHelper\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['\Aliha\AiAltSubscriptionHelper\Installer', 'deactivate']);

/**
 * Initialize the plugin classes
 */
function ai_alt_subscription_helper_init() {
    // error_log('AI Alt Subscription Helper: Initializing...');
    $helper = \Aliha\AiAltSubscriptionHelper\AiAltTextHelper::get_instance();
    $helper->run();
}

ai_alt_subscription_helper_init();

