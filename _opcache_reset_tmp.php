<?php
// TEMPORARY: force OPcache reset so PHP-FPM recompiles updated files from disk.
header('Content-Type: text/plain; charset=utf-8');
echo 'opcache enabled: ' . (function_exists('opcache_reset') ? 'yes' : 'no') . PHP_EOL;
if (function_exists('opcache_reset') && opcache_reset()) {
  echo 'OPCACHE_RESET_OK' . PHP_EOL;
} else {
  echo 'OPCACHE_RESET_FAILED' . PHP_EOL;
}
echo 'restrict_api: ' . (ini_get('opcache.restrict_api') ?: '(empty)') . PHP_EOL;
echo 'validate_timestamps: ' . (ini_get('opcache.validate_timestamps') ?: '(unset)') . PHP_EOL;
echo 'revalidate_freq: ' . (ini_get('opcache.revalidate_freq') ?: '(unset)') . PHP_EOL;