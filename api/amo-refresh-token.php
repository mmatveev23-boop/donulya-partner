<?php
/** Cron: auto-refresh amoCRM token every 12 hours
 *  Add to crontab: 0 */12 * * * php /home/maksim/partners/api/amo-refresh-token.php
 */
require_once __DIR__ . '/amo-helper.php';

$token = amo_get_token();
if ($token) {
    echo date('c') . " Token refreshed OK\n";
} else {
    echo date('c') . " ERROR: Token refresh failed!\n";
}
