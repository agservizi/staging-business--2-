<?php
declare(strict_types=1);

namespace App\Services\Certi;

final class CertificateCatalog
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private const FIELDSETS = [
        'address' => [
            'title' => 'Indirizzo di residenza',
            'description' => 'Dati necessari per certificati di residenza attuale o storico.',
            'fields' => [
                ['name' => 'indirizzo', 'label' => 'Indirizzo', 'type' => 'text', 'placeholder' => 'Via / Piazza', 'required' => true],
                ['name' => 'numero_civico', 'label' => 'Numero civico', 'type' => 'text', 'placeholder' => 'es. 12B', 'required' => true],
            ],
            'required_fields' => ['indirizzo', 'numero_civico'],
        ],
        'period' => [
            'title' => 'Periodo di riferimento',
            'description' => 'Indica l’intervallo temporale per la versione storica del certificato.',
            'fields' => [
                ['name' => 'periodo_dal', 'label' => 'Dal', 'type' => 'date', 'required' => true],
                ['name' => 'periodo_al', 'label' => 'Al', 'type' => 'date', 'required' => false],
            ],
            'required_fields' => ['periodo_dal'],
        ],
        'family' => [
            'title' => 'Opzioni stato di famiglia',
            'description' => 'Parametri per nucleo familiare corrente o storico.',
            'fields' => [
                [
                    'name' => 'nucleo_familiare',
                    'label' => 'Nucleo familiare',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'attuale', 'label' => 'Nucleo attuale'],
                        ['value' => 'storico', 'label' => 'Nucleo precedente'],
                    ],
                ],
                ['name' => 'includi_indirizzo', 'label' => 'Includi indirizzo completo', 'type' => 'checkbox', 'required' => false],
            ],
            'required_fields' => ['nucleo_familiare'],
        ],
        'family_detail' => [
            'title' => 'Dettaglio rapporti',
            'description' => 'Impostazioni aggiuntive per certificati con rapporti di parentela.',
            'fields' => [
                ['name' => 'dettaglio_parentela', 'label' => 'Mostra rapporti di parentela', 'type' => 'checkbox', 'required' => false],
            ],
            'required_fields' => [],
        ],
        'civil_status' => [
            'title' => 'Stato civile',
            'description' => 'Specificare lo stato civile richiesto e note opzionali.',
            'fields' => [
                [
                    'name' => 'stato_civile_richiesto',
                    'label' => 'Stato civile',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'celibe_nubile', 'label' => 'Celibe / Nubile'],
                        ['value' => 'coniugato', 'label' => 'Coniugato/a'],
                        ['value' => 'vedovo', 'label' => 'Vedovo/a'],
                        ['value' => 'divorziato', 'label' => 'Divorziato/a'],
                    ],
                ],
                ['name' => 'note_stato_civile', 'label' => 'Note per l’ufficiale', 'type' => 'textarea', 'required' => false],
            ],
            'required_fields' => ['stato_civile_richiesto'],
        ],
        'event' => [
            'title' => 'Dati evento',
            'description' => 'Data e comune dell’atto (nascita, morte, matrimonio).',
            'fields' => [
                ['name' => 'data_evento', 'label' => 'Data evento', 'type' => 'date', 'required' => true],
                ['name' => 'comune_evento', 'label' => 'Comune evento', 'type' => 'text', 'required' => true, 'placeholder' => 'Comune trascrizione atto'],
            ],
            'required_fields' => ['data_evento', 'comune_evento'],
        ],
        'citizenship' => [
            'title' => 'Cittadinanza',
            'description' => 'Dati richiesti per certificato di cittadinanza.',
            'fields' => [
                ['name' => 'stato_cittadinanza', 'label' => 'Stato di appartenenza', 'type' => 'text', 'required' => true],
                [
                    'name' => 'tipo_atto',
                    'label' => 'Tipo atto',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'certificato', 'label' => 'Certificato'],
                        ['value' => 'estratto', 'label' => 'Estratto'],
                        ['value' => 'attestazione', 'label' => 'Attestazione'],
                    ],
                ],
            ],
            'required_fields' => ['stato_cittadinanza'],
        ],
        'contact' => [
            'title' => 'Contatti per esistenza in vita',
            'description' => 'Recapiti utili alla verifica dello stato in vita.',
            'fields' => [
                ['name' => 'recapito_telefono', 'label' => 'Telefono', 'type' => 'text', 'required' => false, 'placeholder' => '+39 ...'],
                ['name' => 'recapito_email', 'label' => 'Email', 'type' => 'text', 'required' => false, 'placeholder' => 'nome@dominio.it'],
            ],
            'required_fields' => [],
        ],
        'partner' => [
            'title' => 'Dati convivente',
            'description' => 'Anagrafica richiesta per la convivenza di fatto.',
            'fields' => [
                ['name' => 'nome_convivente', 'label' => 'Nome', 'type' => 'text', 'required' => true],
                ['name' => 'cognome_convivente', 'label' => 'Cognome', 'type' => 'text', 'required' => true],
                ['name' => 'cf_convivente', 'label' => 'Codice fiscale', 'type' => 'text', 'required' => true],
            ],
            'required_fields' => ['nome_convivente', 'cognome_convivente', 'cf_convivente'],
        ],
        'relation' => [
            'title' => 'Stato convivenza',
            'description' => 'Informazioni ufficiali sulla convivenza registrata.',
            'fields' => [
                [
                    'name' => 'stato_relazione',
                    'label' => 'Stato relazione',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'attiva', 'label' => 'Attiva'],
                        ['value' => 'sospesa', 'label' => 'Sospesa'],
                        ['value' => 'cessata', 'label' => 'Cessata'],
                    ],
                ],
                ['name' => 'data_registrazione_convivenza', 'label' => 'Data registrazione', 'type' => 'date', 'required' => true],
            ],
            'required_fields' => ['stato_relazione', 'data_registrazione_convivenza'],
        ],
        'aire' => [
            'title' => 'Iscrizione AIRE',
            'description' => 'Dati esteri per i residenti all’estero.',
            'fields' => [
                ['name' => 'stato_estero', 'label' => 'Stato estero di iscrizione', 'type' => 'text', 'required' => true],
                ['name' => 'indirizzo_estero', 'label' => 'Indirizzo estero', 'type' => 'textarea', 'required' => true],
            ],
            'required_fields' => ['stato_estero', 'indirizzo_estero'],
        ],
    ];

    /**
     * @var array<string,array<string,mixed>>
     */
    private const DEFINITIONS = [
        'comunale' => [
            'label' => 'Certificati anagrafici',
            'default_intestatario' => 'persona',
            'provider' => 'docuengine',
            'allowed_intestatario' => ['persona'],
            'subcategories' => [
                'residenza' => [
                    'label' => 'Residenza',
                    'certificates' => [
                        'certificato_residenza' => [
                            'label' => 'Certificato di residenza',
                            'tooltip' => 'Attesta la residenza attuale.',
                            'fieldsets' => [
                                ['key' => 'address'],
                            ],
                            'required_fields' => ['indirizzo', 'numero_civico'],
                        ],
                        'certificato_residenza_storico' => [
                            'label' => 'Certificato di residenza storico',
                            'tooltip' => 'Riporta gli indirizzi precedenti e il periodo.',
                            'fieldsets' => [
                                ['key' => 'address'],
                                ['key' => 'period'],
                            ],
                            'required_fields' => ['indirizzo', 'numero_civico', 'periodo_dal'],
                        ],
                    ],
                ],
                'stato_famiglia' => [
                    'label' => 'Stato di famiglia',
                    'certificates' => [
                        'certificato_stato_famiglia' => [
                            'label' => 'Certificato di stato di famiglia',
                            'tooltip' => 'Composizione del nucleo familiare attuale.',
                            'fieldsets' => [
                                ['key' => 'family'],
                            ],
                            'required_fields' => ['nucleo_familiare'],
                        ],
                        'certificato_stato_famiglia_parentela' => [
                            'label' => 'Certificato di stato di famiglia con rapporti di parentela',
                            'tooltip' => 'Include i rapporti di parentela tra i componenti.',
                            'fieldsets' => [
                                ['key' => 'family'],
                                ['key' => 'family_detail'],
                            ],
                            'required_fields' => ['nucleo_familiare'],
                        ],
                        'certificato_stato_famiglia_storico' => [
                            'label' => 'Certificato di stato di famiglia storico',
                            'tooltip' => 'Nucleo familiare riferito ad una data passata.',
                            'fieldsets' => [
                                ['key' => 'family'],
                                ['key' => 'period'],
                            ],
                            'required_fields' => ['nucleo_familiare', 'periodo_dal'],
                        ],
                    ],
                ],
                'stato_civile' => [
                    'label' => 'Stato civile',
                    'certificates' => [
                        'certificato_stato_civile' => [
                            'label' => 'Certificato di stato civile',
                            'tooltip' => 'Indica lo stato civile attuale.',
                            'fieldsets' => [
                                ['key' => 'civil_status'],
                            ],
                            'required_fields' => ['stato_civile_richiesto'],
                        ],
                        'certificato_stato_libero' => [
                            'label' => 'Certificato di stato libero',
                            'tooltip' => 'Attesta l’assenza di vincoli matrimoniali.',
                            'fieldsets' => [
                                ['key' => 'civil_status'],
                            ],
                            'required_fields' => ['stato_civile_richiesto'],
                        ],
                        'certificato_stato_civile_storico' => [
                            'label' => 'Certificato di stato civile storico',
                            'tooltip' => 'Riporta gli stati civili precedenti.',
                            'fieldsets' => [
                                ['key' => 'civil_status'],
                                ['key' => 'period'],
                            ],
                            'required_fields' => ['stato_civile_richiesto', 'periodo_dal'],
                        ],
                    ],
                ],
                'eventi_vitali' => [
                    'label' => 'Eventi di stato civile',
                    'certificates' => [
                        'certificato_nascita' => [
                            'label' => 'Certificato di nascita',
                            'tooltip' => 'Estratto dell’atto di nascita.',
                            'fieldsets' => [
                                ['key' => 'event', 'options' => ['title' => 'Dati nascita']],
                            ],
                            'required_fields' => ['data_evento', 'comune_evento'],
                        ],
                        'certificato_morte' => [
                            'label' => 'Certificato di morte',
                            'tooltip' => 'Riporta data e luogo del decesso.',
                            'fieldsets' => [
                                ['key' => 'event', 'options' => ['title' => 'Dati decesso']],
                            ],
                            'required_fields' => ['data_evento', 'comune_evento'],
                        ],
                        'certificato_matrimonio' => [
                            'label' => 'Certificato di matrimonio',
                            'tooltip' => 'Dettaglio dell’atto di matrimonio.',
                            'fieldsets' => [
                                ['key' => 'event', 'options' => ['title' => 'Dati matrimonio']],
                            ],
                            'required_fields' => ['data_evento', 'comune_evento'],
                        ],
                    ],
                ],
                'cittadinanza' => [
                    'label' => 'Cittadinanza',
                    'certificates' => [
                        'certificato_cittadinanza' => [
                            'label' => 'Certificato di cittadinanza',
                            'tooltip' => 'Attesta lo stato di cittadinanza.',
                            'fieldsets' => [
                                ['key' => 'citizenship'],
                            ],
                            'required_fields' => ['stato_cittadinanza'],
                        ],
                    ],
                ],
                'esistenza' => [
                    'label' => 'Esistenza in vita',
                    'certificates' => [
                        'certificato_esistenza_vita' => [
                            'label' => 'Certificato di esistenza in vita',
                            'tooltip' => 'Conferma lo stato in vita del soggetto.',
                            'fieldsets' => [
                                ['key' => 'contact'],
                            ],
                            'required_fields' => [],
                        ],
                    ],
                ],
                'convivenza' => [
                    'label' => 'Convivenza di fatto',
                    'certificates' => [
                        'certificato_convivenza_fatto' => [
                            'label' => 'Certificato di convivenza di fatto',
                            'tooltip' => 'Richiede i dati del convivente e lo stato della relazione.',
                            'fieldsets' => [
                                ['key' => 'partner'],
                                ['key' => 'relation'],
                            ],
                            'required_fields' => ['nome_convivente', 'cognome_convivente', 'cf_convivente', 'stato_relazione', 'data_registrazione_convivenza'],
                        ],
                    ],
                ],
                'aire' => [
                    'label' => 'Iscrizione AIRE',
                    'certificates' => [
                        'certificato_aire' => [
                            'label' => 'Certificato AIRE',
                            'tooltip' => 'Dati per i residenti italiani all’estero.',
                            'fieldsets' => [
                                ['key' => 'aire'],
                            ],
                            'required_fields' => ['stato_estero', 'indirizzo_estero'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public static function fieldsets(): array
    {
        return self::FIELDSETS;
    }

    public static function anprSchema(): array
    {
        return [
            'categories' => self::DEFINITIONS,
            'fieldsets' => self::FIELDSETS,
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function labels(string $category): array
    {
        $labels = [];
        if (!isset(self::DEFINITIONS[$category]['subcategories'])) {
            return $labels;
        }

        foreach (self::DEFINITIONS[$category]['subcategories'] as $subcategory) {
            foreach ($subcategory['certificates'] as $type => $definition) {
                $labels[$type] = $definition['label'];
            }
        }

        return $labels;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function certificate(string $category, string $certificate): ?array
    {
        $flattened = self::certificatesForCategory($category);
        return $flattened[$certificate] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function certificatesForCategory(string $category): array
    {
        $result = [];
        if (!isset(self::DEFINITIONS[$category]['subcategories'])) {
            return $result;
        }

        $categoryData = self::DEFINITIONS[$category];
        $fallbackAllowed = $categoryData['allowed_intestatario'] ?? ['persona'];

        foreach ($categoryData['subcategories'] as $subcategoryKey => $subcategory) {
            foreach ($subcategory['certificates'] as $id => $definition) {
                $allowed = $definition['allowed_intestatario'] ?? $fallbackAllowed;
                $result[$id] = $definition + [
                    'subcategory' => $subcategoryKey,
                    'allowed_intestatario' => $allowed,
                ];
            }
        }

        return $result;
    }

    public static function certificateProfile(string $category, string $subcategory, string $certificate): ?array
    {
        $categoryData = self::DEFINITIONS[$category] ?? null;
        if ($categoryData === null) {
            return null;
        }

        $subcategoryData = $categoryData['subcategories'][$subcategory] ?? null;
        if ($subcategoryData === null) {
            return null;
        }

        $certificateData = $subcategoryData['certificates'][$certificate] ?? null;
        if ($certificateData === null) {
            return null;
        }

        $allowed = $certificateData['allowed_intestatario'] ?? ($categoryData['allowed_intestatario'] ?? ['persona']);

        return $certificateData + [
            'category' => $category,
            'subcategory' => $subcategory,
            'category_label' => $categoryData['label'],
            'subcategory_label' => $subcategoryData['label'],
            'provider' => $categoryData['provider'],
            'allowed_intestatario' => $allowed,
        ];
    }

    /**
     * @return array<int,array{key:string,options:array<string,mixed>}> 
     */
    public static function certificateFieldsets(string $category, string $subcategory, string $certificate): array
    {
        $profile = self::certificateProfile($category, $subcategory, $certificate);
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

    public static function requiredFields(string $category, string $subcategory, string $certificate): array
    {
        $profile = self::certificateProfile($category, $subcategory, $certificate);
        if ($profile === null) {
            return [];
        }

        return $profile['required_fields'] ?? [];
    }
}
