<?php
require_once __DIR__ . '/corehost_client.php';
require_once __DIR__ . '/corehost_sql_split.php';
$c = new CoreHostClient();
$id = 'cmqbdh0iw079g6ht49c20gmw3';
$r = $c->request('GET', '/databases/' . $id);
echo json_encode($r['body']['data'] ?? $r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// test single row insert with semicolon in string
$test = "INSERT INTO _semicolon_test (ua) VALUES ('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'); CREATE TABLE IF NOT EXISTS _semicolon_test (ua VARCHAR(255));";
// wrong order - let's do create first
$c->request('POST', '/databases/' . $id . '/query', ['sql' => 'DROP TABLE IF EXISTS _semicolon_test']);
$c->request('POST', '/databases/' . $id . '/query', ['sql' => 'CREATE TABLE _semicolon_test (ua VARCHAR(255))']);
require_once __DIR__ . '/corehost_sql_split.php';
$raw = "INSERT INTO _semicolon_test (ua) VALUES ('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')";
$enc = corehost_encode_sql_for_api($raw);
$r2 = $c->request('POST', '/databases/' . $id . '/query', ['sql' => $enc]);
echo "semicolon test encoded: HTTP {$r2['status']} " . ($r2['body']['message'] ?? 'ok') . PHP_EOL;
$r3 = $c->request('POST', '/databases/' . $id . '/query', ['sql' => 'SELECT ua FROM _semicolon_test']);
echo 'row=' . json_encode($r3['body']['data']['rows'] ?? []) . PHP_EOL;
