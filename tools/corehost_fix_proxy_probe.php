<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$websiteId = 'cmqbz5nuq00ju101cegni8luy';
$appId = 'cmqbzop1t00rk101c788vjnmd';

foreach ([80, 10008] as $p) {
    echo ">>> try proxy port {$p}\n";
    $r = $c->request('PATCH', '/websites/' . $websiteId, [
        'type' => 'REVERSE_PROXY',
        'port' => $p,
        'proxyConfig' => ['target' => "http://corehost-1c788vjnmd:{$p}"],
    ]);
    echo 'PATCH ' . $r['status'] . ' ' . substr($r['raw'], 0, 150) . "\n";
    $c->request('POST', '/websites/' . $websiteId . '/restart');
    sleep(15);
    $ch = curl_init('https://business.coresuite.it/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP {$code} " . substr(strip_tags($body), 0, 80) . "\n\n";
    if ($code === 200) {
        $c->request('PATCH', '/node-apps/' . $appId, ['startCmd' => "php -S 0.0.0.0:{$p} -t ."]);
        exit(0);
    }
}
exit(1);
