<?php
declare(strict_types=1);

namespace App\Services\Certi;

final class CertificateCatalog
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private const DEFINITIONS = [
        'comunale' => [
            'label' => 'Certificati comunali',
            'default_intestatario' => 'persona',
            'certificates' => [
                'certificato_residenza' => [
                    'label' => 'Certificato di residenza',
                    'provider' => 'docuengine',
                    'allowed_intestatario' => ['persona'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => false,
                    ],
                ],
                'certificato_stato_famiglia' => [
                    'label' => 'Stato di famiglia',
                    'provider' => 'docuengine',
                    'allowed_intestatario' => ['persona'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => false,
                    ],
                ],
                'certificato_stato_civile' => [
                    'label' => 'Stato civile',
                    'provider' => 'docuengine',
                    'allowed_intestatario' => ['persona'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => true,
                        'company_data' => false,
                        'property_data' => false,
                    ],
                ],
                'estratto_nascita' => [
                    'label' => 'Estratto atto di nascita',
                    'provider' => 'docuengine',
                    'allowed_intestatario' => ['persona'],
                    'requirements' => [
                        'birth_data' => true,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => false,
                    ],
                ],
                'estratto_matrimonio' => [
                    'label' => 'Estratto atto di matrimonio',
                    'provider' => 'docuengine',
                    'allowed_intestatario' => ['persona'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => true,
                        'company_data' => false,
                        'property_data' => false,
                    ],
                ],
            ],
        ],
        'camerale' => [
            'label' => 'Certificati camerali',
            'default_intestatario' => 'azienda',
            'certificates' => [
                'visura_ordinaria' => [
                    'label' => 'Visura camerale ordinaria',
                    'provider' => 'visengine',
                    'allowed_intestatario' => ['azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => true,
                        'property_data' => false,
                    ],
                ],
                'visura_storica' => [
                    'label' => 'Visura camerale storica',
                    'provider' => 'visengine',
                    'allowed_intestatario' => ['azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => true,
                        'property_data' => false,
                    ],
                ],
                'assetti_societari' => [
                    'label' => 'Assetti societari',
                    'provider' => 'visengine',
                    'allowed_intestatario' => ['azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => true,
                        'property_data' => false,
                    ],
                ],
                'certificato_cciaa' => [
                    'label' => 'Certificato CCIAA',
                    'provider' => 'visengine',
                    'allowed_intestatario' => ['azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => true,
                        'property_data' => false,
                    ],
                ],
                'atti_ufficiali' => [
                    'label' => 'Atti depositati',
                    'provider' => 'visengine',
                    'allowed_intestatario' => ['azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => true,
                        'property_data' => false,
                    ],
                ],
            ],
        ],
        'catastale' => [
            'label' => 'Documenti catastali',
            'default_intestatario' => 'persona',
            'certificates' => [
                'visura_catastale' => [
                    'label' => 'Visura catastale attuale',
                    'provider' => 'catasto',
                    'allowed_intestatario' => ['persona', 'azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => true,
                    ],
                ],
                'visura_catastale_storica' => [
                    'label' => 'Visura catastale storica',
                    'provider' => 'catasto',
                    'allowed_intestatario' => ['persona', 'azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => true,
                    ],
                ],
                'planimetria' => [
                    'label' => 'Planimetria',
                    'provider' => 'catasto',
                    'allowed_intestatario' => ['persona', 'azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => true,
                    ],
                ],
                'rendita' => [
                    'label' => 'Rendita catastale',
                    'provider' => 'catasto',
                    'allowed_intestatario' => ['persona', 'azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => true,
                    ],
                ],
                'titolarita' => [
                    'label' => 'Titolarità immobili',
                    'provider' => 'catasto',
                    'allowed_intestatario' => ['persona', 'azienda'],
                    'requirements' => [
                        'birth_data' => false,
                        'marriage_data' => false,
                        'company_data' => false,
                        'property_data' => true,
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

    /**
     * @return array<string, array<string,mixed>>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::DEFINITIONS as $categoryKey => $category) {
            $schema[$categoryKey] = [
                'label' => $category['label'],
                'default_intestatario' => $category['default_intestatario'],
                'certificates' => [],
            ];

            foreach ($category['certificates'] as $type => $definition) {
                $schema[$categoryKey]['certificates'][$type] = [
                    'label' => $definition['label'],
                    'provider' => $definition['provider'],
                    'allowed_intestatario' => $definition['allowed_intestatario'],
                    'requirements' => $definition['requirements'],
                ];
            }
        }

        return $schema;
    }

    /**
     * @return array<string,string>
     */
    public static function labels(string $category): array
    {
        $labels = [];
        foreach (self::certificatesForCategory($category) as $type => $definition) {
            $labels[$type] = $definition['label'];
        }

        return $labels;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function certificate(string $category, string $certificate): ?array
    {
        $certificates = self::certificatesForCategory($category);
        return $certificates[$certificate] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function certificatesForCategory(string $category): array
    {
        return self::DEFINITIONS[$category]['certificates'] ?? [];
    }
}
