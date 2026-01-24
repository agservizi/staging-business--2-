<?php
declare(strict_types=1);

use Mpdf\Mpdf;
use Mpdf\MpdfException;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
	require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$praticaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($praticaId <= 0) {
	add_flash('warning', 'Pratica non valida.');
	header('Location: index.php');
	exit;
}

$pratica = aci_get_pratica($pdo, $praticaId);
if (!$pratica) {
	add_flash('warning', 'Pratica non trovata.');
	header('Location: index.php');
	exit;
}

$isCompleted = strcasecmp(trim((string) ($pratica['stato'] ?? '')), 'Completata') === 0;
if (!$isCompleted) {
	add_flash('warning', 'Il documento cliente è disponibile solo per pratiche completate.');
	header('Location: view.php?id=' . (int) $praticaId);
	exit;
}

try {
	$mpdf = new Mpdf([
		'format' => 'A4',
		'margin_top' => 16,
		'margin_bottom' => 18,
		'margin_left' => 15,
		'margin_right' => 15,
	]);
} catch (MpdfException $exception) {
	add_flash('warning', 'Impossibile inizializzare la libreria PDF: ' . $exception->getMessage());
	header('Location: view.php?id=' . (int) $praticaId);
	exit;
}

$clienteLabelParts = array_filter([
	$pratica['ragione_sociale'] ?? null,
	trim(($pratica['cognome'] ?? '') . ' ' . ($pratica['nome'] ?? '')) ?: null,
]);
$clienteLabel = $clienteLabelParts ? implode(' - ', $clienteLabelParts) : '—';

$intestatarioLabel = '—';
if ((int) ($pratica['persona_giuridica'] ?? 0) === 1) {
	$intestatarioLabel = $pratica['intestatario_ragione_sociale'] ?? '—';
} else {
	$nome = trim((string) ($pratica['intestatario_nome'] ?? ''));
	$cognome = trim((string) ($pratica['intestatario_cognome'] ?? ''));
	$intestatarioLabel = trim($nome . ' ' . $cognome) !== '' ? trim($nome . ' ' . $cognome) : ($pratica['intestatario'] ?? '—');
}

$protocollo = trim((string) ($pratica['protocollo'] ?? '')) !== '' ? (string) $pratica['protocollo'] : '—';
$tipoPratica = (string) ($pratica['tipo_pratica'] ?? '');
$stato = (string) ($pratica['stato'] ?? '');
$totale = (float) ($pratica['totale'] ?? ($pratica['costo'] ?? 0));
$generatedAt = format_datetime_locale(date('Y-m-d H:i:s'));
$chiusura = format_date_locale($pratica['data_chiusura'] ?? null);
if ($chiusura === '') {
	$chiusura = format_date_locale(date('Y-m-d'));
}

$styles = '<style>
	body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #111; }
	.header { text-align: center; margin-bottom: 18px; }
	.header .title { font-size: 18px; font-weight: bold; margin: 0; text-transform: uppercase; }
	.meta { text-align: center; color: #555; margin-bottom: 18px; }
	.section { margin-bottom: 14px; }
	.section-title { font-weight: bold; text-transform: uppercase; font-size: 11px; margin-bottom: 6px; color: #111; }
	table.details { width: 100%; border-collapse: collapse; }
	table.details td { padding: 6px 8px; border: 1px solid #d1d5db; vertical-align: top; font-size: 11px; }
	.signature { margin-top: 32px; display: flex; justify-content: space-between; }
	.signature div { width: 45%; text-align: center; }
	.muted { color: #555; }
</style>';

$html = $styles;
$html .= '<div class="header">'
	. '<div class="title">Ricevuta pratica ACI</div>'
	. '<div class="muted">Documento di completamento pratica</div>'
	. '</div>';

$html .= '<div class="meta">Documento generato il ' . sanitize_output($generatedAt) . '</div>';

$html .= '<div class="section">'
	. '<div class="section-title">Dettagli pratica</div>'
	. '<table class="details">'
	. '<tr>'
	. '<td><strong>ID pratica</strong><br>#' . (int) $pratica['id'] . '</td>'
	. '<td><strong>Protocollo</strong><br>' . sanitize_output($protocollo) . '</td>'
	. '<td><strong>Stato</strong><br>' . sanitize_output($stato) . '</td>'
	. '</tr>'
	. '<tr>'
	. '<td><strong>Tipo pratica</strong><br>' . sanitize_output($tipoPratica) . '</td>'
	. '<td><strong>Data chiusura</strong><br>' . sanitize_output($chiusura) . '</td>'
	. '<td><strong>Totale</strong><br>' . sanitize_output(format_currency($totale)) . '</td>'
	. '</tr>'
	. '</table>'
	. '</div>';

$html .= '<div class="section">'
	. '<div class="section-title">Cliente e intestatario</div>'
	. '<table class="details">'
	. '<tr>'
	. '<td><strong>Cliente</strong><br>' . sanitize_output($clienteLabel) . '</td>'
	. '<td><strong>Intestatario</strong><br>' . sanitize_output((string) $intestatarioLabel) . '</td>'
	. '</tr>'
	. '</table>'
	. '</div>';

$html .= '<div class="section">'
	. '<div class="section-title">Veicolo</div>'
	. '<table class="details">'
	. '<tr>'
	. '<td><strong>Targa</strong><br>' . sanitize_output($pratica['targa'] ?? '—') . '</td>'
	. '<td><strong>Marca</strong><br>' . sanitize_output($pratica['veicolo_marca'] ?? '—') . '</td>'
	. '<td><strong>Modello</strong><br>' . sanitize_output($pratica['veicolo_modello'] ?? '—') . '</td>'
	. '</tr>'
	. '<tr>'
	. '<td><strong>Telaio</strong><br>' . sanitize_output($pratica['telaio'] ?: '—') . '</td>'
	. '<td><strong>Anno immatr.</strong><br>' . sanitize_output($pratica['veicolo_anno_immatricolazione'] ?? '—') . '</td>'
	. '<td><strong>Alimentazione</strong><br>' . sanitize_output($pratica['veicolo_alimentazione'] ?? '—') . '</td>'
	. '</tr>'
	. '</table>'
	. '</div>';

$html .= '<div class="signature">'
	. '<div>_______________________________<br>Firma cliente</div>'
	. '<div>_______________________________<br>Firma operatore</div>'
	. '</div>';

try {
	$mpdf->WriteHTML($html);
	$filename = sprintf('ricevuta_pratica_aci_%d.pdf', $praticaId);
	$mpdf->Output($filename, 'I');
	exit;
} catch (MpdfException $exception) {
	add_flash('warning', 'Impossibile generare il documento: ' . $exception->getMessage());
	header('Location: view.php?id=' . (int) $praticaId);
	exit;
}
