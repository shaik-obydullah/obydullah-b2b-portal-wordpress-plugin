<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Plugin
{
    private static $booted = false;

    public static function boot()
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        add_action('obbp_expire_quotes', ['OBBP_Workflow', 'expire_quotes']);
        OBBP_Catalog::init();
        new OBBP_Admin();
        new OBBP_Portal();
        new OBBP_CSV();
    }
}
