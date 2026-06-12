<?php
declare(strict_types=1);

namespace {
    if (class_exists('\Mpdf\Mpdf', false)) {
        return;
    }

    $autoloadCandidates = [
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        dirname(__DIR__, 4) . '/vendor/autoload.php',
    ];
    foreach ($autoloadCandidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }

    if (class_exists('\Mpdf\Mpdf', false)) {
        return;
    }
}

namespace Mpdf {
    class MpdfException extends \RuntimeException
    {
    }

    class Mpdf
    {
        public function __construct(array $config = [])
        {
        }

        public function SetTitle(string $title): void
        {
        }

        public function SetAuthor(string $author): void
        {
        }

        public function WriteHTML(string $html): void
        {
        }

        public function Output(string $filename = '', string $dest = ''): void
        {
            throw new MpdfException('Libreria mPDF non disponibile. Esegui composer install.');
        }
    }
}

namespace Mpdf\Output {
    class Destination
    {
        public const FILE = 'F';
    }
}
