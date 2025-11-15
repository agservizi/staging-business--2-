<?php
declare(strict_types=1);

namespace {
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
