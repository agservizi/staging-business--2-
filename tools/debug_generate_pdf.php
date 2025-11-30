<?php
require_once __DIR__ . '/../vendor/autoload.php';

$className = '\Mpdf\Mpdf';
if (!class_exists($className)) {
	fwrite(STDERR, "Libreria mPDF non disponibile." . PHP_EOL);
	exit(1);
}

$pdf = new $className();
$pdf->AddPage();
$pdf->SetFont('DejaVu Sans', 'B', 16);
$pdf->Cell(0, 10, 'Test PDF Output', 0, 1);
$pdf->SetFont('DejaVu Sans', '', 12);
$pdf->Cell(0, 10, 'If you can read this, mPDF output works.', 0, 1);
$file = __DIR__ . '/../backups/test_debug.pdf';
$pdf->Output($file, 'F');

echo 'Generated: ' . $file . PHP_EOL;
