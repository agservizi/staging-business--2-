<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$credential = $iliadService->getCredential($id);

if (!$credential) {
    http_response_code(404);
    echo 'Credenziale non trovata.';
    exit;
}

// Verifica che FPDF sia disponibile
$fpdfPath = __DIR__ . '/../../../lib/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    http_response_code(500);
    echo 'Errore di configurazione: Libreria FPDF mancante. File: ' . $fpdfPath;
    exit;
}

require_once $fpdfPath;

// Stub class for Intelephense if FPDF is not loaded
if (!class_exists('FPDF')) {
    /** @phpstan-ignore-next-line */
    class FPDF {}
}

/**
 * @extends FPDF
 * @method void SetFont(string $family, string $style = '', float $size = 0)
 * @method void Cell(float $w, float $h = 0, string $txt = '', mixed $border = 0, int $ln = 0, string $align = '', bool $fill = false, mixed $link = '')
 * @method void Ln(float $h = null)
 * @method void SetY(float $y)
 * @method void AddPage(string $orientation = '', mixed $size = '', int $rotation = 0)
 * @method void Output(string $dest = '', string $name = '', bool $isUTF8 = false)
 */
class IliadPDF extends FPDF
{
    public function Header()
    {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'Credenziali Iliad', 0, 1, 'C');
        $this->Ln(10);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Generato il ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

/** @var IliadPDF $pdf */
$pdf = new IliadPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

if ($credential['include_fibra']) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Credenziali Fibra', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    if (!empty($credential['fibra_id'])) {
        $pdf->Cell(50, 10, 'ID Fibra:', 0, 0);
        $pdf->Cell(0, 10, $credential['fibra_id'], 0, 1);
    }
    $pdf->Cell(50, 10, 'Password:', 0, 0);
    $pdf->Cell(0, 10, $credential['fibra_password'], 0, 1);
    $pdf->Ln(10);
}

if ($credential['include_mobile']) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Credenziali Mobile', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    if (!empty($credential['mobile_id'])) {
        $pdf->Cell(50, 10, 'ID Mobile:', 0, 0);
        $pdf->Cell(0, 10, $credential['mobile_id'], 0, 1);
    }
    $pdf->Cell(50, 10, 'Password:', 0, 0);
    $pdf->Cell(0, 10, $credential['mobile_password'], 0, 1);
    $pdf->Ln(10);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="credenziali_iliad_' . $id . '.pdf"');
$pdf->Output('D');
exit;