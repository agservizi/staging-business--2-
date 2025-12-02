<?php
declare(strict_types=1);

namespace App\Services\Certi;

final class CameraliCatalog
{
	/**
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $fieldsetCache = null;

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function baseFieldsets(): array
	{
		$yearOptions = self::buildYearOptions();

		return [
			'visura_storica' => [
				'title' => 'Opzioni visura storica',
				'description' => 'Configura la profondità storica e gli eventi cessati.',
				'fields' => [
					[
						'name' => 'includi_eventi_cessati',
						'label' => 'Includi eventi cessati',
						'type' => 'checkbox',
						'required' => false,
					],
				],
				'required_fields' => [],
			],
			'bilancio_documento' => [
				'title' => 'Parametri bilancio',
				'description' => 'Seleziona anno e formato del bilancio ufficiale.',
				'fields' => [
					[
						'name' => 'tipo_bilancio',
						'label' => 'Tipo bilancio',
						'type' => 'select',
						'required' => true,
						'options' => [
							['value' => 'ultimo', 'label' => 'Ultimo disponibile'],
							['value' => 'storico', 'label' => 'Storico (specificare anno)'],
						],
					],
					[
						'name' => 'anno_bilancio',
						'label' => 'Anno bilancio',
						'type' => 'select',
						'required' => true,
						'options' => $yearOptions,
					],
					[
						'name' => 'formato_bilancio',
						'label' => 'Formato file',
						'type' => 'select',
						'required' => true,
						'options' => [
							['value' => 'pdf', 'label' => 'PDF ufficiale'],
							['value' => 'xbrl', 'label' => 'XBRL'],
							['value' => 'zip', 'label' => 'Pacchetto ZIP completo'],
						],
					],
				],
				'required_fields' => ['tipo_bilancio', 'anno_bilancio', 'formato_bilancio'],
			],
			'atti_depositati' => [
				'title' => 'Parametri atti depositati',
				'description' => 'Indica anno e tipologia di atto depositato.',
				'fields' => [
					[
						'name' => 'anno_deposito',
						'label' => 'Anno deposito',
						'type' => 'select',
						'required' => true,
						'options' => $yearOptions,
					],
					[
						'name' => 'tipo_atto',
						'label' => 'Tipo di atto',
						'type' => 'select',
						'required' => true,
						'options' => [
							['value' => 'costituzione', 'label' => 'Atto di costituzione'],
							['value' => 'variazione', 'label' => 'Variazione dati'],
							['value' => 'cessione_quote', 'label' => 'Trasferimento / cessione quote'],
							['value' => 'fusione', 'label' => 'Fusione / incorporazione'],
							['value' => 'scissione', 'label' => 'Scissione'],
							['value' => 'altro', 'label' => 'Altro atto depositato'],
						],
					],
				],
				'required_fields' => ['anno_deposito', 'tipo_atto'],
			],
			'elenco_soci' => [
				'title' => 'Opzioni elenco soci',
				'description' => 'Definisci il livello di dettaglio richiesto.',
				'fields' => [
					[
						'name' => 'includi_storico_soci',
						'label' => 'Includi storicità soci',
						'type' => 'checkbox',
						'required' => false,
					],
				],
				'required_fields' => [],
			],
			'pec_visura' => [
				'title' => 'Invio PEC',
				'description' => 'Specificare il destinatario PEC per ricezione immediata.',
				'fields' => [
					[
						'name' => 'destinatario_pec',
						'label' => 'PEC destinatario',
						'type' => 'email',
						'placeholder' => 'pec@impresa.it',
						'required' => true,
					],
				],
				'required_fields' => ['destinatario_pec'],
			],
			'motivazione_certificato' => [
				'title' => 'Motivazione richiesta',
				'description' => 'Motivo ufficiale richiesto dalla CCIAA.',
				'fields' => [
					[
						'name' => 'motivazione_richiesta',
						'label' => 'Motivo utilizzo',
						'type' => 'select',
						'required' => true,
						'options' => [
							['value' => 'appalto_pubblico', 'label' => 'Appalto / gara pubblica'],
							['value' => 'gara_privata', 'label' => 'Gara o qualifica privata'],
							['value' => 'uso_privato', 'label' => 'Uso privato / banca'],
							['value' => 'internazionale', 'label' => 'Utilizzo estero'],
							['value' => 'altro', 'label' => 'Altro utilizzo'],
						],
					],
				],
				'required_fields' => ['motivazione_richiesta'],
			],
			'lingua_opzioni' => [
				'title' => 'Lingua documento',
				'description' => 'Richiedi il documento anche in lingua inglese.',
				'fields' => [
					[
						'name' => 'lingua_documento',
						'label' => 'Lingua',
						'type' => 'select',
						'required' => false,
						'options' => [
							['value' => 'it', 'label' => 'Italiano'],
							['value' => 'en', 'label' => 'Inglese'],
						],
					],
				],
				'required_fields' => [],
			],
			'rating_legalita' => [
				'title' => 'Rating di legalità',
				'description' => 'Parametri richiesti da AGCM per attestare il rating.',
				'fields' => [
					[
						'name' => 'richiedi_evidenza_rating',
						'label' => 'Richiedi evidenza rating AGCM',
						'type' => 'checkbox',
						'required' => false,
					],
					[
						'name' => 'protocollo_rating',
						'label' => 'Numero protocollo (opzionale)',
						'type' => 'text',
						'placeholder' => 'AGCM/0000/2025',
						'required' => false,
					],
				],
				'required_fields' => [],
			],
			'assetti_societari' => [
				'title' => 'Assetti societari',
				'description' => 'Configura il dettaglio di partecipazioni e legali rappresentanti.',
				'fields' => [
					[
						'name' => 'includi_partecipazioni',
						'label' => 'Mostra catena partecipativa',
						'type' => 'checkbox',
						'required' => false,
					],
					[
						'name' => 'includi_cariche',
						'label' => 'Includi elenco cariche in essere',
						'type' => 'checkbox',
						'required' => false,
					],
				],
				'required_fields' => [],
			],
			'fascicolo_impresa' => [
				'title' => 'Composizione fascicolo',
				'description' => 'Seleziona i documenti da includere nel fascicolo impresa.',
				'fields' => [
					[
						'name' => 'fascicolo_includi_visure',
						'label' => 'Allega visura ordinaria',
						'type' => 'checkbox',
						'required' => false,
					],
					[
						'name' => 'fascicolo_includi_bilanci',
						'label' => 'Allega ultimi bilanci',
						'type' => 'checkbox',
						'required' => false,
					],
					[
						'name' => 'fascicolo_includi_atti',
						'label' => 'Allega atti principali',
						'type' => 'checkbox',
						'required' => false,
					],
				],
				'required_fields' => [],
			],
			'antimafia' => [
				'title' => 'Dettagli antimafia',
				'description' => 'Compilare i dati richiesti dalla Prefettura.',
				'fields' => [
					[
						'name' => 'prefettura_competente',
						'label' => 'Prefettura competente',
						'type' => 'text',
						'required' => true,
					],
					[
						'name' => 'uso_previsto',
						'label' => 'Uso previsto del certificato',
						'type' => 'textarea',
						'required' => true,
					],
				],
				'required_fields' => ['prefettura_competente', 'uso_previsto'],
			],
		];
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fieldsets(): array
	{
		if (self::$fieldsetCache === null) {
			self::$fieldsetCache = self::baseFieldsets();
		}

		return self::$fieldsetCache;
	}

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private const DEFINITIONS = [
		'visure' => [
			'label' => 'Visure camerali',
			'description' => 'Documenti ufficiali aggiornati dal Registro Imprese.',
			'certificates' => [
				'visura_ordinaria_societa' => [
					'label' => 'Visura ordinaria società',
					'tooltip' => 'Riporta la fotografia aggiornata della società iscritta al Registro Imprese.',
					'fieldsets' => [],
					'required_fields' => [],
				],
				'visura_storica_societa' => [
					'label' => 'Visura storica società',
					'tooltip' => 'Includi l’evoluzione storica dell’impresa con i principali eventi.',
					'fieldsets' => ['visura_storica'],
					'required_fields' => [],
				],
				'visura_ordinaria_impresa_individuale' => [
					'label' => 'Visura ordinaria impresa individuale',
					'tooltip' => 'Quadro aggiornato per ditte individuali.',
					'fieldsets' => [],
					'required_fields' => [],
				],
				'visura_storica_impresa_individuale' => [
					'label' => 'Visura storica impresa individuale',
					'tooltip' => 'Cronistoria dell’impresa individuale con eventi cessati.',
					'fieldsets' => ['visura_storica'],
					'required_fields' => [],
				],
				'visura_artigiana' => [
					'label' => 'Visura artigiana',
					'tooltip' => 'Informazioni ufficiali per imprese artigiane.',
					'fieldsets' => [],
					'required_fields' => [],
				],
				'visura_ordinaria_con_pec' => [
					'label' => 'Visura ordinaria con PEC',
					'tooltip' => 'Visura inviata direttamente alla PEC indicata.',
					'fieldsets' => ['pec_visura'],
					'required_fields' => ['destinatario_pec'],
				],
				'visura_assetti_societari' => [
					'label' => 'Visura assetti societari',
					'tooltip' => 'Dettaglia assetti proprietari, cariche e partecipazioni.',
					'fieldsets' => ['assetti_societari'],
					'required_fields' => [],
				],
				'visura_qualifica_artigiana' => [
					'label' => 'Visura qualifica artigiana',
					'tooltip' => 'Attesta la qualifica artigiana presso l’albo provinciale.',
					'fieldsets' => [],
					'required_fields' => [],
				],
				'visura_inglese' => [
					'label' => 'Visura camerale in inglese',
					'tooltip' => 'Versione bilingue per uso internazionale.',
					'fieldsets' => ['lingua_opzioni'],
					'required_fields' => [],
				],
			],
		],
		'certificati' => [
			'label' => 'Certificati camerali ufficiali',
			'description' => 'Certificati CCIAA con motivazione obbligatoria.',
			'certificates' => [
				'certificato_cciaa_ordinario' => [
					'label' => 'Certificato CCIAA ordinario',
					'tooltip' => 'Documento ufficiale con le informazioni legali vigenti.',
					'fieldsets' => ['motivazione_certificato', 'lingua_opzioni'],
					'required_fields' => ['motivazione_richiesta'],
				],
				'certificato_cciaa_storico' => [
					'label' => 'Certificato CCIAA storico',
					'tooltip' => 'Riporta le variazioni intervenute nel tempo.',
					'fieldsets' => ['motivazione_certificato', 'visura_storica'],
					'required_fields' => ['motivazione_richiesta'],
				],
				'certificato_iscrizione_cciaa' => [
					'label' => 'Certificato di iscrizione',
					'tooltip' => 'Attesta l’iscrizione al Registro Imprese.',
					'fieldsets' => ['motivazione_certificato'],
					'required_fields' => ['motivazione_richiesta'],
				],
				'certificato_non_iscrizione' => [
					'label' => 'Certificato di non iscrizione',
					'tooltip' => 'Dimostra la mancata iscrizione in CCIAA.',
					'fieldsets' => ['motivazione_certificato'],
					'required_fields' => ['motivazione_richiesta'],
				],
				'certificato_vigenza' => [
					'label' => 'Certificato di vigenza',
					'tooltip' => 'Certifica lo stato di attività dell’impresa.',
					'fieldsets' => ['motivazione_certificato'],
					'required_fields' => ['motivazione_richiesta'],
				],
				'certificato_iscrizione_antimafia' => [
					'label' => 'Certificato iscrizione antimafia',
					'tooltip' => 'Richiesto per appalti pubblici con obbligo prefettizio.',
					'fieldsets' => ['motivazione_certificato', 'antimafia'],
					'required_fields' => ['motivazione_richiesta', 'prefettura_competente', 'uso_previsto'],
				],
			],
		],
		'documenti' => [
			'label' => 'Documenti aggiuntivi',
			'description' => 'Estratti complementari e dossier ufficiali.',
			'certificates' => [
				'stato_imprese' => [
					'label' => 'Stato imprese',
					'tooltip' => 'Panoramica sulle imprese attive collegate.',
					'fieldsets' => [],
					'required_fields' => [],
				],
				'elenco_soci' => [
					'label' => 'Elenco soci',
					'tooltip' => 'Elenco aggiornato dei soci e delle quote.',
					'fieldsets' => ['elenco_soci'],
					'required_fields' => [],
				],
				'bilanci_ufficiali' => [
					'label' => 'Bilanci ufficiali',
					'tooltip' => 'Bilanci depositati (ultimo anno o storico).',
					'fieldsets' => ['bilancio_documento'],
					'required_fields' => ['tipo_bilancio', 'anno_bilancio', 'formato_bilancio'],
				],
				'atti_depositati' => [
					'label' => 'Atti depositati',
					'tooltip' => 'Singoli atti notarili depositati in CCIAA.',
					'fieldsets' => ['atti_depositati'],
					'required_fields' => ['anno_deposito', 'tipo_atto'],
				],
				'trasferimenti_quote' => [
					'label' => 'Trasferimenti quote / variazioni',
					'tooltip' => 'Storico dei trasferimenti quota e variazioni societarie.',
					'fieldsets' => ['atti_depositati'],
					'required_fields' => ['anno_deposito', 'tipo_atto'],
				],
				'fascicolo_impresa' => [
					'label' => 'Fascicolo impresa completo',
					'tooltip' => 'Raccolta completa di visure, bilanci e atti.',
					'fieldsets' => ['fascicolo_impresa'],
					'required_fields' => [],
				],
				'rating_legalita' => [
					'label' => 'Rating di legalità',
					'tooltip' => 'Richiesta attestazione rating AGCM.',
					'fieldsets' => ['rating_legalita'],
					'required_fields' => [],
				],
			],
		],
	];

	public static function schema(): array
	{
		return [
			'categories' => self::DEFINITIONS,
			'fieldsets' => self::fieldsets(),
		];
	}

	public static function categories(): array
	{
		return self::DEFINITIONS;
	}

	public static function certificates(string $category): array
	{
		return self::DEFINITIONS[$category]['certificates'] ?? [];
	}

	public static function certificate(string $category, string $certificate): ?array
	{
		$certificates = self::certificates($category);
		if (!isset($certificates[$certificate])) {
			return null;
		}

		return $certificates[$certificate] + [
			'category' => $category,
			'category_label' => self::DEFINITIONS[$category]['label'] ?? ucfirst($category),
		];
	}

	/**
	 * @return array<int,array{key:string,options:array<string,mixed>}> 
	 */
	public static function certificateFieldsets(string $category, string $certificate): array
	{
		$profile = self::certificate($category, $certificate);
		if ($profile === null) {
			return [];
		}

		$entries = $profile['fieldsets'] ?? [];
		$normalized = [];
		foreach ($entries as $entry) {
			if (is_string($entry)) {
				$normalized[] = ['key' => $entry, 'options' => []];
				continue;
			}
			$normalized[] = [
				'key' => $entry['key'] ?? '',
				'options' => $entry['options'] ?? [],
			];
		}

		return $normalized;
	}

	public static function requiredFields(string $category, string $certificate): array
	{
		$profile = self::certificate($category, $certificate);
		if ($profile === null) {
			return [];
		}

		return $profile['required_fields'] ?? [];
	}

	/**
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function buildYearOptions(int $years = 10): array
	{
		$options = [];
		$current = (int) date('Y');
		for ($i = 0; $i < $years; $i++) {
			$year = (string) ($current - $i);
			$options[] = ['value' => $year, 'label' => $year];
		}

		return $options;
	}
}
