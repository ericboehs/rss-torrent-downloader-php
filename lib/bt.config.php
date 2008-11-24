<?php

define('BT_IMPLICIT_CACHE', 	true);						// Caching is performed implicitly for all functions which require network activity
define('BT_CACHE_PATH', 		'/tmp/php_bt_tracker');		// Location of cached data
define('BT_CACHE_DURATION',		3600);						// Duration to store cached data

// No need to configure anything below here

if(BT_IMPLICIT_CACHE) {
	if(!@is_dir(BT_CACHE_PATH) || !@is_writable(BT_CACHE_PATH))
		trigger_error("Misconfiguration in bt.config.php caching is enabled, but " . BT_CACHE_PATH . " isn't writeable", E_USER_ERROR);
}
?>