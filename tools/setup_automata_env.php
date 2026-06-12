<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';

if (!is_file($envPath)) {
    fwrite(STDERR, "File .env non trovato.\n");
    exit(1);
}

$content = (string) file_get_contents($envPath);
$vars = [
    'AUTOMATA_BASE_URL' => 'https://automata.coresuite.it',
    'AUTOMATA_MODEL' => 'automata-default',
    'AUTOMATA_FALLBACK_OPENROUTER' => 'true',
];

if (!preg_match('/^AUTOMATA_API_KEY=/m', $content)) {
    $vars['AUTOMATA_API_KEY'] = bin2hex(random_bytes(24));
}

$block = "\n# Automata AI (Coresuite)\n";
foreach ($vars as $key => $value) {
    if (preg_match('/^' . preg_quote($key, '/') . '=/m', $content)) {
        continue;
    }
    $block .= $key . '=' . $value . "\n";
}

if (trim($block) !== '# Automata AI (Coresuite)') {
    file_put_contents($envPath, rtrim($content) . $block);
    fwrite(STDOUT, "Variabili Automata aggiunte a .env\n");
} else {
    fwrite(STDOUT, "Variabili Automata già presenti in .env\n");
}
