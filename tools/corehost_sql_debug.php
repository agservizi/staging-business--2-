<?php
require_once __DIR__ . '/corehost_sql_split.php';
$c = file_get_contents('c:/Users/carva/Downloads/u427445037_coresuitebusin.sql');
$a = corehost_split_sql($c);
$s = $a[101];
echo substr($s, 0, 200) . PHP_EOL;
echo 'table=' . (preg_match('/^(INSERT|CREATE|ALTER|DROP)\s+.*`login_audit`/i', $s) ? 'yes' : 'no') . PHP_EOL;
echo 'loose=' . (preg_match('/INSERT INTO `login_audit`/i', $s) ? 'yes' : 'no') . PHP_EOL;
