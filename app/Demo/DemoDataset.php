<?php
declare(strict_types=1);

namespace App\Demo;

final class DemoDataset
{
    private const PASSWORD_HASH = '$2y$12$LzxxfL5pGXD1Kgud/sqzL.pzFrf.k9DeBDD5.4mOBR3p5dOjblphy';

    /**
     * @return array<int, string>
     */
    public static function truncateOrder(): array
    {
        return [
            'remember_tokens',
            'password_resets',
            'log_attivita',
            'ticket_messages',
            'tickets',
            'email_campaign_events',
            'email_campaign_recipients',
            'email_campaigns',
            'email_templates',
            'email_list_subscribers',
            'email_lists',
            'email_subscribers',
            'document_tag_map',
            'document_versions',
            'documents',
            'document_tags',
            'office_document_revisions',
            'office_documents',
            'office_spreadsheet_revisions',
            'office_spreadsheet_presets',
            'office_spreadsheets',
            'curriculum_experiences',
            'curriculum_education',
            'curriculum_languages',
            'curriculum_skills',
            'curriculum',
            'fedelta_movimenti',
            'servizi_appuntamenti',
            'servizi_web_progetti',
            'entrate_uscite',
            'energia_contratti_allegati',
            'energia_contratti',
            'anpr_pratiche',
            'spedizioni',
            'brt_shipments',
            'brt_orm_requests',
            'daily_financial_reports',
            'opportunity_files',
            'opportunities',
            'opportunity_offers',
            'opportunity_providers',
            'ai_conversations',
            'ai_user_preferences',
            'configurazioni',
            'clienti',
            'users',
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>|array<string, array<int, array<string, int|float|string|null>>>
     */
    public static function data(): array
    {
        return [
            'configurazioni' => self::configurations(),
            'users' => self::users(),
            'opportunity_providers' => self::opportunityProviders(),
            'opportunity_offers' => self::opportunityOffers(),
            'opportunities' => self::opportunities(),
            'opportunity_files' => self::opportunityFiles(),
            'clienti' => self::clients(),
            'entrate_uscite' => self::ledgerMovements(),
            'servizi_web_progetti' => self::webProjects(),
            'servizi_appuntamenti' => self::appointments(),
            'fedelta_movimenti' => self::loyaltyMovements(),
            'daily_financial_reports' => self::dailyReports(),
            'curriculum' => self::curricula(),
            'curriculum_experiences' => self::curriculumExperiences(),
            'curriculum_education' => self::curriculumEducation(),
            'curriculum_languages' => self::curriculumLanguages(),
            'curriculum_skills' => self::curriculumSkills(),
            'spedizioni' => self::shipments(),
            'documents' => self::documents(),
            'document_versions' => self::documentVersions(),
            'document_tags' => self::documentTags(),
            'document_tag_map' => self::documentTagMap(),
            'office_documents' => self::officeDocuments(),
            'office_document_revisions' => self::officeDocumentRevisions(),
            'office_spreadsheets' => self::officeSpreadsheets(),
            'office_spreadsheet_revisions' => self::officeSpreadsheetRevisions(),
            'office_spreadsheet_presets' => self::officeSpreadsheetPresets(),
            'tickets' => self::tickets(),
            'ticket_messages' => self::ticketMessages(),
            'email_subscribers' => self::emailSubscribers(),
            'email_lists' => self::emailLists(),
            'email_list_subscribers' => self::emailListSubscribers(),
            'email_templates' => self::emailTemplates(),
            'email_campaigns' => self::emailCampaigns(),
            'email_campaign_recipients' => self::emailCampaignRecipients(),
            'email_campaign_events' => self::emailCampaignEvents(),
            'energia_contratti' => self::energyContracts(),
            'energia_contratti_allegati' => self::energyAttachments(),
            'anpr_pratiche' => self::anprCases(),
            'ai_conversations' => self::aiConversations(),
            'ai_user_preferences' => self::aiPreferences(),
            'brt_shipments' => self::brtShipments(),
            'brt_orm_requests' => self::brtOrmRequests(),
        ];
    }

    private static function configurations(): array
    {
        return [
            ['chiave' => 'ragione_sociale', 'valore' => 'Coresuite Demo Experience SRL', 'updated_at' => '2025-11-01 09:00:00'],
            ['chiave' => 'indirizzo', 'valore' => 'Via Immaginazione 15, Milano', 'updated_at' => '2025-11-01 09:00:00'],
            ['chiave' => 'telefono', 'valore' => '+39 02 4000 9000', 'updated_at' => '2025-11-01 09:00:00'],
            ['chiave' => 'email', 'valore' => 'demo@coresuite.it', 'updated_at' => '2025-11-01 09:00:00'],
            ['chiave' => 'ui_theme', 'valore' => 'navy', 'updated_at' => '2025-11-01 09:00:00'],
        ];
    }

    private static function users(): array
    {
        return [
            [
                'id' => 1,
                'username' => 'demo.admin',
                'email' => 'demo.admin@coresuite.it',
                'nome' => 'Laura',
                'cognome' => 'Bianchi',
                'password' => self::PASSWORD_HASH,
                'mfa_secret' => null,
                'mfa_enabled' => 0,
                'mfa_recovery_codes' => null,
                'mfa_enabled_at' => null,
                'ruolo' => 'Admin',
                'theme_preference' => 'dark',
                'last_login_at' => '2025-12-05 08:30:00',
                'created_at' => '2025-10-15 09:00:00',
                'updated_at' => '2025-12-05 08:30:00',
            ],
            [
                'id' => 2,
                'username' => 'demo.manager',
                'email' => 'demo.manager@coresuite.it',
                'nome' => 'Giorgio',
                'cognome' => 'Conti',
                'password' => self::PASSWORD_HASH,
                'mfa_secret' => null,
                'mfa_enabled' => 0,
                'mfa_recovery_codes' => null,
                'mfa_enabled_at' => null,
                'ruolo' => 'Manager',
                'theme_preference' => 'dark',
                'last_login_at' => '2025-12-04 17:45:00',
                'created_at' => '2025-10-20 09:10:00',
                'updated_at' => '2025-12-04 17:45:00',
            ],
            [
                'id' => 3,
                'username' => 'demo.operatore',
                'email' => 'demo.operatore@coresuite.it',
                'nome' => 'Chiara',
                'cognome' => 'Romano',
                'password' => self::PASSWORD_HASH,
                'mfa_secret' => null,
                'mfa_enabled' => 0,
                'mfa_recovery_codes' => null,
                'mfa_enabled_at' => null,
                'ruolo' => 'Operatore',
                'theme_preference' => 'light',
                'last_login_at' => '2025-12-03 11:00:00',
                'created_at' => '2025-10-22 08:30:00',
                'updated_at' => '2025-12-03 11:00:00',
            ],
            [
                'id' => 4,
                'username' => 'demo.collaboratore',
                'email' => 'demo.collaboratore@coresuite.it',
                'nome' => 'Mauro',
                'cognome' => 'Leoni',
                'password' => self::PASSWORD_HASH,
                'mfa_secret' => null,
                'mfa_enabled' => 0,
                'mfa_recovery_codes' => null,
                'mfa_enabled_at' => null,
                'ruolo' => 'Collaboratore',
                'theme_preference' => 'dark',
                'last_login_at' => '2025-12-02 14:15:00',
                'created_at' => '2025-10-25 10:00:00',
                'updated_at' => '2025-12-02 14:15:00',
            ],
        ];
    }

    private static function opportunityProviders(): array
    {
        return [
            [
                'id' => 1,
                'category' => 'luce',
                'name' => 'EnerNext Utilities',
                'slug' => 'enernext',
                'active' => 1,
                'ordering' => 10,
                'default_commission' => '85.00',
                'metadata' => json_encode(['channels' => ['desk', 'door-to-door']], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-11-15 09:00:00',
                'updated_at' => '2025-11-20 10:15:00',
            ],
            [
                'id' => 2,
                'category' => 'gas',
                'name' => 'BlueGrid Energy',
                'slug' => 'bluegrid',
                'active' => 1,
                'ordering' => 20,
                'default_commission' => '110.00',
                'metadata' => json_encode(['channels' => ['phone']], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-11-18 09:00:00',
                'updated_at' => '2025-11-24 16:35:00',
            ],
        ];
    }

    private static function opportunityOffers(): array
    {
        return [
            [
                'id' => 1,
                'provider_id' => 1,
                'name' => 'EnerNext Business Flat',
                'slug' => 'enernext-business-flat',
                'commission' => '95.00',
                'active' => 1,
                'ordering' => 10,
                'metadata' => json_encode(['notes' => 'Tariffa fissa 12 mesi'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-11-15 09:30:00',
                'updated_at' => '2025-11-20 10:15:00',
            ],
            [
                'id' => 2,
                'provider_id' => 2,
                'name' => 'BlueGrid Flex Gas',
                'slug' => 'bluegrid-flex-gas',
                'commission' => '120.00',
                'active' => 1,
                'ordering' => 20,
                'metadata' => json_encode(['notes' => 'Canone indicizzato PSV'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-11-19 11:00:00',
                'updated_at' => '2025-11-24 16:35:00',
            ],
        ];
    }

    private static function opportunities(): array
    {
        return [
            [
                'id' => 1,
                'code' => 'OPP10231',
                'category' => 'luce',
                'status_code' => 'in_verifica',
                'provider_id' => 1,
                'offer_id' => 1,
                'provider_label' => 'EnerNext Utilities',
                'offer_label' => 'EnerNext Business Flat',
                'collaborator_id' => 4,
                'managed_by' => 2,
                'commission_amount' => '95.00',
                'customer_first_name' => 'Giulio',
                'customer_last_name' => 'Parisi',
                'customer_tax_code' => 'PRSGLI90A15F205K',
                'customer_birth_date' => '1990-01-15',
                'customer_birth_place' => 'Milano',
                'customer_phone' => '+39 333 2223333',
                'customer_email' => 'giulio.parisi@example.com',
                'customer_address' => 'Via Sile 18',
                'customer_city' => 'Milano',
                'customer_postal_code' => '20145',
                'customer_province' => 'MI',
                'document_type' => 'CI',
                'document_number' => 'AA1234567',
                'document_issued_by' => 'Comune Milano',
                'document_issued_at' => '2019-03-20',
                'document_expires_at' => '2029-03-20',
                'telefonia_current_operator' => null,
                'telefonia_line_number' => null,
                'luce_pod' => 'IT001E123456789',
                'gas_pdr' => null,
                'payment_method' => 'iban',
                'payment_iban' => 'IT60X0542811101000000123456',
                'payment_holder_is_customer' => 1,
                'payment_holder_first_name' => null,
                'payment_holder_last_name' => null,
                'payment_holder_tax_code' => null,
                'additional_notes' => 'Richiesta cambio potenza a 6kW.',
                'admin_notes' => 'Attendere ultimo estratto conto.',
                'contract_code' => 'ENX-2025-0021',
                'client_code' => 'CLI-4521',
                'last_status_change' => '2025-11-28 14:10:00',
                'metadata' => json_encode(['channel' => 'desk'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-11-25 09:20:00',
                'updated_at' => '2025-11-28 14:10:00',
            ],
            [
                'id' => 2,
                'code' => 'OPP10252',
                'category' => 'gas',
                'status_code' => 'attivato',
                'provider_id' => 2,
                'offer_id' => 2,
                'provider_label' => 'BlueGrid Energy',
                'offer_label' => 'BlueGrid Flex Gas',
                'collaborator_id' => 4,
                'managed_by' => 1,
                'commission_amount' => '120.00',
                'customer_first_name' => 'Valeria',
                'customer_last_name' => 'Neri',
                'customer_tax_code' => 'NERVLR88R52G702Z',
                'customer_birth_date' => '1988-10-12',
                'customer_birth_place' => 'Torino',
                'customer_phone' => '+39 348 9988776',
                'customer_email' => 'valeria.neri@example.com',
                'customer_address' => 'Via Madonnina 4',
                'customer_city' => 'Torino',
                'customer_postal_code' => '10121',
                'customer_province' => 'TO',
                'document_type' => 'CI',
                'document_number' => 'AZ9876543',
                'document_issued_by' => 'Comune Torino',
                'document_issued_at' => '2018-06-10',
                'document_expires_at' => '2028-06-10',
                'telefonia_current_operator' => null,
                'telefonia_line_number' => null,
                'luce_pod' => null,
                'gas_pdr' => 'IT001G654321098',
                'payment_method' => 'iban',
                'payment_iban' => 'IT30H0306901904100000000345',
                'payment_holder_is_customer' => 0,
                'payment_holder_first_name' => 'Luca',
                'payment_holder_last_name' => 'Neri',
                'payment_holder_tax_code' => 'NERLCU60D10L219X',
                'additional_notes' => 'Cliente vuole fatturazione elettronica.',
                'admin_notes' => 'Contratto firmato digitalmente.',
                'contract_code' => 'BLG-2025-0088',
                'client_code' => 'CLI-4790',
                'last_status_change' => '2025-12-02 11:40:00',
                'metadata' => json_encode(['channel' => 'partner'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-11-27 10:45:00',
                'updated_at' => '2025-12-02 11:40:00',
            ],
        ];
    }

    private static function opportunityFiles(): array
    {
        return [
            [
                'id' => 1,
                'opportunity_id' => 1,
                'original_name' => 'bolletta-ottobre.pdf',
                'stored_name' => 'opp10231_bolletta.pdf',
                'file_path' => 'uploads/demo/opportunities/opp10231_bolletta.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 248000,
                'checksum' => 'f3af4b9badb4c54b32f3cbf4fa161be210d7c8c5f6c331fb82f1fd2b9a33aa90',
                'uploaded_by' => 3,
                'created_at' => '2025-11-25 09:25:00',
            ],
            [
                'id' => 2,
                'opportunity_id' => 2,
                'original_name' => 'mandato-firma.pdf',
                'stored_name' => 'opp10252_mandato.pdf',
                'file_path' => 'uploads/demo/opportunities/opp10252_mandato.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 198400,
                'checksum' => '7d34e17aa23f2fb2dd91cc305ba8b7d8215c6b859abcf6c647baa9e884e4d010',
                'uploaded_by' => 4,
                'created_at' => '2025-11-27 11:00:00',
            ],
        ];
    }

    private static function clients(): array
    {
        return [
            [
                'id' => 1,
                'ragione_sociale' => 'Atlas Living SRL',
                'nome' => 'Sara',
                'cognome' => 'Greco',
                'cf_piva' => 'IT09274560158',
                'email' => 'sara.greco@atlasliving.it',
                'telefono' => '+39 02 5566770',
                'indirizzo' => 'Via Valtellina 26, Milano',
                'note' => 'Cliente storico per servizi energia e documentale.',
                'created_at' => '2025-09-20 10:00:00',
                'updated_at' => '2025-11-30 09:15:00',
            ],
            [
                'id' => 2,
                'ragione_sociale' => 'Boreal Consulting',
                'nome' => 'Marta',
                'cognome' => 'Villa',
                'cf_piva' => 'IT07233450964',
                'email' => 'marta.villa@borealconsulting.it',
                'telefono' => '+39 011 9988770',
                'indirizzo' => 'Corso Francia 10, Torino',
                'note' => 'Interessata al programma fedeltà.',
                'created_at' => '2025-09-25 09:00:00',
                'updated_at' => '2025-11-27 16:10:00',
            ],
            [
                'id' => 3,
                'ragione_sociale' => 'Studio Ferri',
                'nome' => 'Davide',
                'cognome' => 'Ferri',
                'cf_piva' => 'FRRDVD85E10F205R',
                'email' => 'davide.ferri@example.com',
                'telefono' => '+39 348 2211990',
                'indirizzo' => 'Via degli Olmetti 4, Roma',
                'note' => '',
                'created_at' => '2025-10-01 08:30:00',
                'updated_at' => '2025-12-01 08:45:00',
            ],
            [
                'id' => 4,
                'ragione_sociale' => 'Linea Verde SAS',
                'nome' => 'Paolo',
                'cognome' => 'Longo',
                'cf_piva' => 'IT01528710992',
                'email' => 'paolo.longo@lineaverde.eu',
                'telefono' => '+39 06 7788110',
                'indirizzo' => 'Via dei Gelsi 90, Roma',
                'note' => 'Gestione logistica e pickup PUDO.',
                'created_at' => '2025-10-05 11:40:00',
                'updated_at' => '2025-11-29 17:05:00',
            ],
            [
                'id' => 5,
                'ragione_sociale' => 'Indaco Digital Lab',
                'nome' => 'Elena',
                'cognome' => 'Moretti',
                'cf_piva' => 'IT03099870167',
                'email' => 'elena.moretti@indacolab.it',
                'telefono' => '+39 0331 882211',
                'indirizzo' => 'Via per Samarate 12, Varese',
                'note' => 'Cliente web e marketing automation.',
                'created_at' => '2025-10-10 09:20:00',
                'updated_at' => '2025-11-26 15:30:00',
            ],
        ];
    }

    private static function ledgerMovements(): array
    {
        return [
            [
                'id' => 1,
                'cliente_id' => 1,
                'tipo_movimento' => 'Entrata',
                'descrizione' => 'Consulenza energia premium',
                'listino_voce' => 'Energia Premium',
                'listino_costo_rivenditore' => '350.00',
                'listino_costo_cliente' => '650.00',
                'listino_margine' => '300.00',
                'riferimento' => 'EN-2025-1101',
                'metodo' => 'Bonifico',
                'stato' => 'Completato',
                'importo' => '650.00',
                'quantita' => 1,
                'prezzo_unitario' => '650.00',
                'data_scadenza' => '2025-11-05',
                'data_pagamento' => '2025-11-04',
                'note' => 'Pagamento anticipato.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'created_at' => '2025-11-01 10:00:00',
                'updated_at' => '2025-11-04 12:00:00',
            ],
            [
                'id' => 2,
                'cliente_id' => 2,
                'tipo_movimento' => 'Entrata',
                'descrizione' => 'Campagna email automation',
                'listino_voce' => 'Email Marketing',
                'listino_costo_rivenditore' => '180.00',
                'listino_costo_cliente' => '420.00',
                'listino_margine' => '240.00',
                'riferimento' => 'EM-2025-1120',
                'metodo' => 'Carta',
                'stato' => 'In lavorazione',
                'importo' => '420.00',
                'quantita' => 1,
                'prezzo_unitario' => '420.00',
                'data_scadenza' => '2025-12-10',
                'data_pagamento' => null,
                'note' => 'Attesa contenuti definitivi.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'created_at' => '2025-11-20 12:30:00',
                'updated_at' => '2025-11-28 08:15:00',
            ],
            [
                'id' => 3,
                'cliente_id' => 3,
                'tipo_movimento' => 'Uscita',
                'descrizione' => 'Canone software terze parti',
                'listino_voce' => 'Licenze esterne',
                'listino_costo_rivenditore' => null,
                'listino_costo_cliente' => null,
                'listino_margine' => null,
                'riferimento' => 'OUT-2025-1115',
                'metodo' => 'Bonifico',
                'stato' => 'Completato',
                'importo' => '210.00',
                'quantita' => 1,
                'prezzo_unitario' => '210.00',
                'data_scadenza' => '2025-11-18',
                'data_pagamento' => '2025-11-17',
                'note' => 'Costo infrastruttura demo.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'created_at' => '2025-11-15 09:40:00',
                'updated_at' => '2025-11-17 10:05:00',
            ],
            [
                'id' => 4,
                'cliente_id' => 4,
                'tipo_movimento' => 'Entrata',
                'descrizione' => 'Spedizioni BRT novembre',
                'listino_voce' => 'Logistica',
                'listino_costo_rivenditore' => '75.00',
                'listino_costo_cliente' => '210.00',
                'listino_margine' => '135.00',
                'riferimento' => 'BRT-2025-1103',
                'metodo' => 'RID',
                'stato' => 'In attesa',
                'importo' => '210.00',
                'quantita' => 1,
                'prezzo_unitario' => '210.00',
                'data_scadenza' => '2025-12-03',
                'data_pagamento' => null,
                'note' => 'Da riconciliare con estratto conto BRT.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'created_at' => '2025-11-29 15:00:00',
                'updated_at' => '2025-12-01 09:10:00',
            ],
            [
                'id' => 5,
                'cliente_id' => 5,
                'tipo_movimento' => 'Entrata',
                'descrizione' => 'Sito e-commerce Indaco',
                'listino_voce' => 'Web design',
                'listino_costo_rivenditore' => '900.00',
                'listino_costo_cliente' => '1900.00',
                'listino_margine' => '1000.00',
                'riferimento' => 'WEB-2025-1002',
                'metodo' => 'Bonifico',
                'stato' => 'Completato',
                'importo' => '1900.00',
                'quantita' => 1,
                'prezzo_unitario' => '1900.00',
                'data_scadenza' => '2025-10-30',
                'data_pagamento' => '2025-10-29',
                'note' => 'Acconto ricevuto.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'created_at' => '2025-10-15 14:00:00',
                'updated_at' => '2025-10-29 09:00:00',
            ],
            [
                'id' => 6,
                'cliente_id' => 2,
                'tipo_movimento' => 'Uscita',
                'descrizione' => 'Servizi fotografici branding',
                'listino_voce' => 'Partner esterno',
                'listino_costo_rivenditore' => null,
                'listino_costo_cliente' => null,
                'listino_margine' => null,
                'riferimento' => 'OUT-2025-1202',
                'metodo' => 'Carta',
                'stato' => 'In lavorazione',
                'importo' => '320.00',
                'quantita' => 1,
                'prezzo_unitario' => '320.00',
                'data_scadenza' => '2025-12-18',
                'data_pagamento' => null,
                'note' => 'Prevista approvazione manager.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'created_at' => '2025-12-02 09:30:00',
                'updated_at' => '2025-12-05 08:55:00',
            ],
        ];
    }

    private static function webProjects(): array
    {
        return [
            [
                'id' => 1,
                'codice' => 'WEB-INDACO-01',
                'cliente_id' => 5,
                'tipo_servizio' => 'E-commerce',
                'titolo' => 'Store Indaco Digital',
                'descrizione' => 'Realizzazione store Shopify e pacchetto branding.',
                'include_domini' => 1,
                'include_email_professionali' => 0,
                'include_hosting' => 1,
                'include_stampa' => 0,
                'stato' => 'in_lavorazione',
                'preventivo_numero' => 'PRV-2025-0912',
                'preventivo_importo' => '5200.00',
                'ordine_numero' => 'ORD-2025-1004',
                'ordine_importo' => '5200.00',
                'consegna_prevista' => '2026-01-12',
                'note_interne' => 'Integrazione hostinger in corso.',
                'allegato_path' => 'uploads/demo/web/indaco_brief.pdf',
                'allegato_hash' => '3c0f7fc41dee9a335bcbb2b5e3a9edbaaf6ff25d7d254949015135c8db05d495',
                'allegato_caricato_at' => '2025-11-12 10:00:00',
                'created_at' => '2025-10-14 09:00:00',
                'updated_at' => '2025-12-03 15:00:00',
            ],
            [
                'id' => 2,
                'codice' => 'WEB-ATLAS-02',
                'cliente_id' => 1,
                'tipo_servizio' => 'Sito vetrina',
                'titolo' => 'Rebranding sito Atlas',
                'descrizione' => 'Landing page corporate con area clienti.',
                'include_domini' => 0,
                'include_email_professionali' => 1,
                'include_hosting' => 1,
                'include_stampa' => 1,
                'stato' => 'in_attesa_cliente',
                'preventivo_numero' => 'PRV-2025-0708',
                'preventivo_importo' => '3100.00',
                'ordine_numero' => null,
                'ordine_importo' => null,
                'consegna_prevista' => '2025-12-20',
                'note_interne' => 'In attesa palette brand.',
                'allegato_path' => null,
                'allegato_hash' => null,
                'allegato_caricato_at' => null,
                'created_at' => '2025-09-28 14:00:00',
                'updated_at' => '2025-11-30 09:30:00',
            ],
        ];
    }

    private static function appointments(): array
    {
        return [
            [
                'id' => 1,
                'cliente_id' => 1,
                'titolo' => 'Allineamento dashboard direzionale',
                'tipo_servizio' => 'Consulenza',
                'responsabile' => 'Laura Bianchi',
                'luogo' => 'Videocall Teams',
                'stato' => 'Confermato',
                'data_inizio' => '2025-12-05 10:00:00',
                'data_fine' => '2025-12-05 11:00:00',
                'reminder_sent_at' => '2025-12-04 10:00:00',
                'google_event_id' => 'demo-event-1',
                'google_event_synced_at' => '2025-12-04 10:00:00',
                'google_event_sync_error' => null,
                'note' => 'Mostrare nuova vista ordini.',
                'created_at' => '2025-12-01 08:00:00',
                'updated_at' => '2025-12-04 10:00:00',
            ],
            [
                'id' => 2,
                'cliente_id' => 2,
                'titolo' => 'Workshop marketing automation',
                'tipo_servizio' => 'Formazione',
                'responsabile' => 'Giorgio Conti',
                'luogo' => 'Sede cliente',
                'stato' => 'Programmato',
                'data_inizio' => '2025-12-06 09:30:00',
                'data_fine' => '2025-12-06 12:00:00',
                'reminder_sent_at' => null,
                'google_event_id' => null,
                'google_event_synced_at' => null,
                'google_event_sync_error' => null,
                'note' => 'Portare materiale stampato.',
                'created_at' => '2025-11-28 15:00:00',
                'updated_at' => '2025-11-28 15:00:00',
            ],
            [
                'id' => 3,
                'cliente_id' => 4,
                'titolo' => 'Sessione di pickup avanzati',
                'tipo_servizio' => 'Logistica',
                'responsabile' => 'Chiara Romano',
                'luogo' => 'Hub Tiburtina',
                'stato' => 'In corso',
                'data_inizio' => '2025-12-04 15:00:00',
                'data_fine' => '2025-12-04 16:30:00',
                'reminder_sent_at' => '2025-12-03 15:00:00',
                'google_event_id' => 'demo-event-2',
                'google_event_synced_at' => '2025-12-03 15:00:00',
                'google_event_sync_error' => null,
                'note' => 'Verificare nuove etichette BRT.',
                'created_at' => '2025-11-25 09:00:00',
                'updated_at' => '2025-12-04 15:05:00',
            ],
            [
                'id' => 4,
                'cliente_id' => 5,
                'titolo' => 'Kickoff campagna black friday',
                'tipo_servizio' => 'Marketing',
                'responsabile' => 'Giorgio Conti',
                'luogo' => 'Videocall Meet',
                'stato' => 'Completato',
                'data_inizio' => '2025-11-15 10:00:00',
                'data_fine' => '2025-11-15 11:30:00',
                'reminder_sent_at' => '2025-11-14 10:00:00',
                'google_event_id' => 'demo-event-0',
                'google_event_synced_at' => '2025-11-14 10:00:00',
                'google_event_sync_error' => null,
                'note' => 'Decisione palette campagne.',
                'created_at' => '2025-11-05 13:00:00',
                'updated_at' => '2025-11-15 11:30:00',
            ],
        ];
    }

    private static function loyaltyMovements(): array
    {
        return [
            [
                'id' => 1,
                'cliente_id' => 2,
                'tipo_movimento' => 'Accredito',
                'descrizione' => 'Referral evento Torino',
                'punti' => 120,
                'saldo_post_movimento' => 420,
                'ricompensa' => null,
                'operatore' => 'Chiara Romano',
                'note' => null,
                'data_movimento' => '2025-11-18 09:00:00',
                'created_at' => '2025-11-18 09:00:00',
                'updated_at' => '2025-11-18 09:00:00',
            ],
            [
                'id' => 2,
                'cliente_id' => 2,
                'tipo_movimento' => 'Riscatto',
                'descrizione' => 'Voucher formazione',
                'punti' => -200,
                'saldo_post_movimento' => 220,
                'ricompensa' => 'Voucher consulenza 2h',
                'operatore' => 'Giorgio Conti',
                'note' => 'Applicato su progetto 2026.',
                'data_movimento' => '2025-12-01 10:15:00',
                'created_at' => '2025-12-01 10:15:00',
                'updated_at' => '2025-12-01 10:15:00',
            ],
            [
                'id' => 3,
                'cliente_id' => 1,
                'tipo_movimento' => 'Accredito',
                'descrizione' => 'Abbonamento Office Suite',
                'punti' => 80,
                'saldo_post_movimento' => 80,
                'ricompensa' => null,
                'operatore' => 'Laura Bianchi',
                'note' => null,
                'data_movimento' => '2025-12-03 16:40:00',
                'created_at' => '2025-12-03 16:40:00',
                'updated_at' => '2025-12-03 16:40:00',
            ],
        ];
    }

    private static function dailyReports(): array
    {
        return [
            [
                'id' => 1,
                'report_date' => '2025-11-30',
                'total_entrate' => '1860.00',
                'total_uscite' => '210.00',
                'saldo' => '1650.00',
                'file_path' => 'storage/demo/reports/daily-report-2025-11-30.pdf',
                'generated_at' => '2025-12-01 07:05:00',
                'created_at' => '2025-12-01 07:05:00',
                'updated_at' => '2025-12-01 07:05:00',
            ],
            [
                'id' => 2,
                'report_date' => '2025-12-01',
                'total_entrate' => '420.00',
                'total_uscite' => '0.00',
                'saldo' => '420.00',
                'file_path' => 'storage/demo/reports/daily-report-2025-12-01.pdf',
                'generated_at' => '2025-12-02 07:05:00',
                'created_at' => '2025-12-02 07:05:00',
                'updated_at' => '2025-12-02 07:05:00',
            ],
            [
                'id' => 3,
                'report_date' => '2025-12-02',
                'total_entrate' => '0.00',
                'total_uscite' => '320.00',
                'saldo' => '-320.00',
                'file_path' => 'storage/demo/reports/daily-report-2025-12-02.pdf',
                'generated_at' => '2025-12-03 07:05:00',
                'created_at' => '2025-12-03 07:05:00',
                'updated_at' => '2025-12-03 07:05:00',
            ],
        ];
    }

    private static function curricula(): array
    {
        return [
            [
                'id' => 1,
                'cliente_id' => 3,
                'titolo' => 'CV Project Manager',
                'professional_summary' => 'Project manager orientato al digitale con focus energy-tech.',
                'key_competences' => 'Leadership, gestione stakeholder, energy compliance.',
                'digital_competences' => 'Suite Google, PowerBI, strumenti AI assistant.',
                'driving_license' => 'B',
                'additional_information' => 'Disponibile a trasferte settimanali.',
                'status' => 'Pubblicato',
                'last_generated_at' => '2025-11-22 14:00:00',
                'generated_file' => 'uploads/demo/cv/cv_davide_ferri.pdf',
                'created_at' => '2025-11-10 09:00:00',
                'updated_at' => '2025-11-22 14:00:00',
            ],
            [
                'id' => 2,
                'cliente_id' => 2,
                'titolo' => 'CV Marketing Specialist',
                'professional_summary' => 'Esperta CRM e loyalty per PMI del nord Italia.',
                'key_competences' => 'Automation, CRM, copywriting.',
                'digital_competences' => 'HubSpot, Mailmodo, Canvas pro.',
                'driving_license' => 'B',
                'additional_information' => 'Coordina team distribuiti.',
                'status' => 'Bozza',
                'last_generated_at' => null,
                'generated_file' => null,
                'created_at' => '2025-11-25 10:00:00',
                'updated_at' => '2025-12-02 12:30:00',
            ],
        ];
    }

    private static function curriculumExperiences(): array
    {
        return [
            [
                'id' => 1,
                'curriculum_id' => 1,
                'role_title' => 'Project Manager',
                'employer' => 'NextGrid Solutions',
                'city' => 'Milano',
                'country' => 'Italia',
                'start_date' => '2022-01-01',
                'end_date' => null,
                'is_current' => 1,
                'description' => 'Coordinamento rollout energy cloud per PMI.',
                'ordering' => 1,
            ],
            [
                'id' => 2,
                'curriculum_id' => 1,
                'role_title' => 'Consultant',
                'employer' => 'Studio Ferri',
                'city' => 'Roma',
                'country' => 'Italia',
                'start_date' => '2018-03-01',
                'end_date' => '2021-12-31',
                'is_current' => 0,
                'description' => 'Progetti ERP e automazione documentale.',
                'ordering' => 2,
            ],
            [
                'id' => 3,
                'curriculum_id' => 2,
                'role_title' => 'CRM Specialist',
                'employer' => 'Boreal Consulting',
                'city' => 'Torino',
                'country' => 'Italia',
                'start_date' => '2021-06-01',
                'end_date' => null,
                'is_current' => 1,
                'description' => 'Gestione campagne e segmentazioni B2B.',
                'ordering' => 1,
            ],
        ];
    }

    private static function curriculumEducation(): array
    {
        return [
            [
                'id' => 1,
                'curriculum_id' => 1,
                'title' => 'Ingegneria Gestionale',
                'institution' => 'Politecnico di Milano',
                'city' => 'Milano',
                'country' => 'Italia',
                'start_date' => '2008-09-01',
                'end_date' => '2013-03-01',
                'qualification_level' => 'Laurea Magistrale',
                'description' => null,
                'ordering' => 1,
            ],
            [
                'id' => 2,
                'curriculum_id' => 2,
                'title' => 'Economia & Marketing',
                'institution' => 'Università di Torino',
                'city' => 'Torino',
                'country' => 'Italia',
                'start_date' => '2010-09-01',
                'end_date' => '2015-07-01',
                'qualification_level' => 'Laurea',
                'description' => null,
                'ordering' => 1,
            ],
        ];
    }

    private static function curriculumLanguages(): array
    {
        return [
            [
                'id' => 1,
                'curriculum_id' => 1,
                'language' => 'Inglese',
                'overall_level' => 'C1',
                'listening' => 'C1',
                'reading' => 'C1',
                'interaction' => 'C1',
                'production' => 'B2',
                'writing' => 'C1',
                'certification' => 'IELTS 7.5',
            ],
            [
                'id' => 2,
                'curriculum_id' => 2,
                'language' => 'Francese',
                'overall_level' => 'B2',
                'listening' => 'B2',
                'reading' => 'B2',
                'interaction' => 'B1',
                'production' => 'B1',
                'writing' => 'B1',
                'certification' => null,
            ],
        ];
    }

    private static function curriculumSkills(): array
    {
        return [
            [
                'id' => 1,
                'curriculum_id' => 1,
                'category' => 'Gestionale',
                'skill' => 'Budgeting avanzato',
                'level' => 'Esperto',
                'description' => null,
                'ordering' => 1,
            ],
            [
                'id' => 2,
                'curriculum_id' => 2,
                'category' => 'Marketing',
                'skill' => 'Automazioni CRM',
                'level' => 'Avanzato',
                'description' => null,
                'ordering' => 1,
            ],
        ];
    }

    private static function shipments(): array
    {
        return [
            [
                'id' => 1,
                'cliente_id' => 4,
                'tipo_spedizione' => 'BRT Express',
                'mittente' => 'Linea Verde SAS',
                'destinatario' => 'Planet Store',
                'tracking_number' => 'BRT0123456781',
                'stato' => 'In corso',
                'note' => 'Ritiro completato alle 10:30.',
                'created_at' => '2025-11-30 08:30:00',
                'updated_at' => '2025-12-01 09:45:00',
            ],
            [
                'id' => 2,
                'cliente_id' => 4,
                'tipo_spedizione' => 'BRT Economy',
                'mittente' => 'Linea Verde SAS',
                'destinatario' => 'Retail Hub',
                'tracking_number' => 'BRT0123456782',
                'stato' => 'Registrato',
                'note' => 'In attesa di conferma peso.',
                'created_at' => '2025-12-02 11:10:00',
                'updated_at' => '2025-12-02 11:10:00',
            ],
            [
                'id' => 3,
                'cliente_id' => 1,
                'tipo_spedizione' => 'Pickup locker',
                'mittente' => 'Atlas Living SRL',
                'destinatario' => 'Filiale Roma',
                'tracking_number' => 'BRT0123456783',
                'stato' => 'Completato',
                'note' => 'Documento archiviato.',
                'created_at' => '2025-11-18 09:00:00',
                'updated_at' => '2025-11-20 10:00:00',
            ],
        ];
    }

    private static function documents(): array
    {
        return [
            [
                'id' => 1,
                'titolo' => 'Contratto energia Atlas',
                'descrizione' => 'Versione firmata digitalmente.',
                'cliente_id' => 1,
                'modulo' => 'Energia',
                'stato' => 'Approvato',
                'owner_id' => 1,
                'created_at' => '2025-11-25 10:00:00',
                'updated_at' => '2025-11-28 12:00:00',
            ],
            [
                'id' => 2,
                'titolo' => 'Checklist onboarding Indaco',
                'descrizione' => 'Documento condiviso con marketing.',
                'cliente_id' => 5,
                'modulo' => 'Onboarding',
                'stato' => 'Bozza',
                'owner_id' => 2,
                'created_at' => '2025-11-15 15:30:00',
                'updated_at' => '2025-12-01 11:15:00',
            ],
        ];
    }

    private static function documentVersions(): array
    {
        return [
            [
                'id' => 1,
                'document_id' => 1,
                'versione' => 1,
                'file_name' => 'contratto-energia-v1.docx',
                'file_path' => 'uploads/demo/documents/contratto-energia-v1.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => 182000,
                'uploaded_by' => 1,
                'created_at' => '2025-11-25 10:00:00',
            ],
            [
                'id' => 2,
                'document_id' => 2,
                'versione' => 2,
                'file_name' => 'checklist-indaco-v2.pdf',
                'file_path' => 'uploads/demo/documents/checklist-indaco-v2.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 96000,
                'uploaded_by' => 2,
                'created_at' => '2025-12-01 11:15:00',
            ],
        ];
    }

    private static function documentTags(): array
    {
        return [
            ['id' => 1, 'nome' => 'Energia', 'created_at' => '2025-11-10 09:00:00'],
            ['id' => 2, 'nome' => 'Onboarding', 'created_at' => '2025-11-10 09:05:00'],
        ];
    }

    private static function documentTagMap(): array
    {
        return [
            ['document_id' => 1, 'tag_id' => 1, 'created_at' => '2025-11-25 10:05:00'],
            ['document_id' => 2, 'tag_id' => 2, 'created_at' => '2025-11-15 15:35:00'],
        ];
    }

    private static function officeDocuments(): array
    {
        return [
            [
                'id' => 1,
                'uuid' => '9f71a7f6-41c0-4a38-9414-15a4d9bde210',
                'titolo' => 'Piano trimestrale servizi',
                'slug' => 'piano-trimestrale-servizi',
                'categoria' => 'Strategia',
                'stato' => 'published',
                'owner_id' => 1,
                'cliente_id' => null,
                'tags' => json_encode(['dashboard', 'roadmap'], JSON_UNESCAPED_UNICODE),
                'notes' => 'Aggiornare KPI ogni mese.',
                'current_version' => 2,
                'created_at' => '2025-11-01 09:00:00',
                'updated_at' => '2025-12-03 08:45:00',
            ],
            [
                'id' => 2,
                'uuid' => 'b1d9c784-2e6d-4586-8dcb-7e43d1e1c789',
                'titolo' => 'Checklist onboarding Indaco',
                'slug' => 'checklist-onboarding-indaco',
                'categoria' => 'Operativo',
                'stato' => 'review',
                'owner_id' => 2,
                'cliente_id' => 5,
                'tags' => json_encode(['onboarding', 'marketing'], JSON_UNESCAPED_UNICODE),
                'notes' => 'Includere nuova sezione automazioni.',
                'current_version' => 1,
                'created_at' => '2025-11-14 14:30:00',
                'updated_at' => '2025-11-30 17:10:00',
            ],
        ];
    }

    private static function officeDocumentRevisions(): array
    {
        return [
            [
                'id' => 1,
                'document_id' => 1,
                'versione' => 1,
                'titolo_snapshot' => 'Piano trimestrale servizi v1',
                'contenuto' => 'Versione iniziale del piano demo.',
                'metadata' => json_encode(['owner' => 'Laura'], JSON_UNESCAPED_UNICODE),
                'commento' => 'Bozza',
                'created_by' => 1,
                'created_at' => '2025-11-01 09:05:00',
            ],
            [
                'id' => 2,
                'document_id' => 1,
                'versione' => 2,
                'titolo_snapshot' => 'Piano trimestrale servizi v2',
                'contenuto' => 'Aggiornamento KPI Q4.',
                'metadata' => json_encode(['owner' => 'Laura'], JSON_UNESCAPED_UNICODE),
                'commento' => 'Aggiunti target pickup.',
                'created_by' => 1,
                'created_at' => '2025-12-03 08:45:00',
            ],
            [
                'id' => 3,
                'document_id' => 2,
                'versione' => 1,
                'titolo_snapshot' => 'Checklist onboarding Indaco',
                'contenuto' => 'Sequenza attività onboarding.',
                'metadata' => json_encode(['owner' => 'Giorgio'], JSON_UNESCAPED_UNICODE),
                'commento' => 'Prima release',
                'created_by' => 2,
                'created_at' => '2025-11-30 17:10:00',
            ],
        ];
    }

    private static function officeSpreadsheets(): array
    {
        return [
            [
                'id' => 1,
                'uuid' => 'd8a7b6d9-2391-4ad2-8e82-5b3f90d5c9f7',
                'titolo' => 'Tracker email marketing',
                'slug' => 'tracker-email-marketing',
                'owner_id' => 2,
                'categoria' => 'Marketing',
                'stato' => 'published',
                'tags' => json_encode(['email', 'campaign'], JSON_UNESCAPED_UNICODE),
                'current_version' => 2,
                'created_at' => '2025-11-05 09:00:00',
                'updated_at' => '2025-12-02 16:00:00',
            ],
        ];
    }

    private static function officeSpreadsheetRevisions(): array
    {
        return [
            [
                'id' => 1,
                'spreadsheet_id' => 1,
                'versione' => 1,
                'titolo_snapshot' => 'Tracker email marketing',
                'grid_state' => json_encode(['rows' => 20], JSON_UNESCAPED_UNICODE),
                'metadata' => json_encode(['columns' => ['Campagna', 'Status']], JSON_UNESCAPED_UNICODE),
                'commento' => 'Setup iniziale',
                'created_by' => 2,
                'created_at' => '2025-11-05 09:05:00',
            ],
            [
                'id' => 2,
                'spreadsheet_id' => 1,
                'versione' => 2,
                'titolo_snapshot' => 'Tracker email marketing',
                'grid_state' => json_encode(['rows' => 40], JSON_UNESCAPED_UNICODE),
                'metadata' => json_encode(['columns' => ['Campagna', 'Status', 'CTR']], JSON_UNESCAPED_UNICODE),
                'commento' => 'Aggiunti KPI',
                'created_by' => 2,
                'created_at' => '2025-12-02 16:00:00',
            ],
        ];
    }

    private static function officeSpreadsheetPresets(): array
    {
        return [
            [
                'id' => 1,
                'spreadsheet_id' => 1,
                'name' => 'Campagne attive',
                'owner_id' => 2,
                'visibility' => 'role',
                'allowed_roles' => 'Admin,Manager',
                'filters' => json_encode(['status' => ['scheduled', 'sending']], JSON_UNESCAPED_UNICODE),
                'columns' => json_encode(['Campagna', 'Status', 'Scheduled'], JSON_UNESCAPED_UNICODE),
                'tags' => json_encode(['default'], JSON_UNESCAPED_UNICODE),
                'created_by' => 2,
                'updated_by' => 2,
                'created_at' => '2025-12-02 16:05:00',
                'updated_at' => '2025-12-02 16:05:00',
            ],
        ];
    }

    private static function tickets(): array
    {
        return [
            [
                'id' => 1,
                'codice' => 'TCK-1045',
                'customer_id' => 1,
                'customer_name' => 'Atlas Living SRL',
                'customer_email' => 'support@atlasliving.it',
                'customer_phone' => '+39 02 5566770',
                'subject' => 'Errore sincronizzazione Google Calendar',
                'type' => 'TECH',
                'priority' => 'HIGH',
                'status' => 'IN_PROGRESS',
                'channel' => 'PORTAL',
                    'assigned_to' => 3,
                'tags' => json_encode(['calendar', 'energia'], JSON_UNESCAPED_UNICODE),
                'sla_due_at' => '2025-12-05 18:00:00',
                'created_by' => 1,
                'last_message_at' => '2025-12-04 11:20:00',
                'created_at' => '2025-12-03 09:00:00',
                'updated_at' => '2025-12-04 11:20:00',
            ],
            [
                'id' => 2,
                'codice' => 'TCK-1050',
                'customer_id' => 5,
                'customer_name' => 'Indaco Digital Lab',
                'customer_email' => 'helpdesk@indacolab.it',
                'customer_phone' => '+39 0331 882211',
                'subject' => 'Richiesta nuove dashboard marketing',
                'type' => 'SUPPORT',
                'priority' => 'MEDIUM',
                'status' => 'OPEN',
                'channel' => 'EMAIL',
                'assigned_to' => 2,
                'tags' => json_encode(['dashboard'], JSON_UNESCAPED_UNICODE),
                'sla_due_at' => '2025-12-07 18:00:00',
                'created_by' => 2,
                'last_message_at' => '2025-12-04 09:40:00',
                'created_at' => '2025-12-04 09:30:00',
                'updated_at' => '2025-12-04 09:40:00',
            ],
            [
                'id' => 3,
                'codice' => 'TCK-1033',
                'customer_id' => 4,
                'customer_name' => 'Linea Verde SAS',
                'customer_email' => 'logistica@lineaverde.eu',
                'customer_phone' => '+39 06 7788110',
                'subject' => 'Conferma manifesti BRT',
                'type' => 'ADMIN',
                'priority' => 'LOW',
                'status' => 'WAITING_CLIENT',
                'channel' => 'INTERNAL',
                'assigned_to' => 1,
                'tags' => json_encode(['brt', 'manifest'], JSON_UNESCAPED_UNICODE),
                'sla_due_at' => '2025-12-06 12:00:00',
                'created_by' => 1,
                'last_message_at' => '2025-12-03 13:15:00',
                'created_at' => '2025-12-02 16:20:00',
                'updated_at' => '2025-12-03 13:15:00',
            ],
        ];
    }

    private static function ticketMessages(): array
    {
        return [
            [
                'id' => 1,
                'ticket_id' => 1,
                'author_id' => 3,
                'author_name' => 'Chiara Romano',
                'body' => 'Ho riallineato il token Google Calendar. Monitoriamo nelle prossime ore.',
                'attachments' => null,
                'is_internal' => 0,
                'visibility' => 'customer',
                'status_snapshot' => 'IN_PROGRESS',
                'notified_client' => 1,
                'notified_admin' => 0,
                'created_at' => '2025-12-04 11:20:00',
                'updated_at' => '2025-12-04 11:20:00',
            ],
            [
                'id' => 2,
                'ticket_id' => 2,
                'author_id' => 2,
                'author_name' => 'Giorgio Conti',
                'body' => 'Stiamo preparando 3 layout demo per le dashboard richieste.',
                'attachments' => null,
                'is_internal' => 0,
                'visibility' => 'customer',
                'status_snapshot' => 'OPEN',
                'notified_client' => 1,
                'notified_admin' => 0,
                'created_at' => '2025-12-04 09:40:00',
                'updated_at' => '2025-12-04 09:40:00',
            ],
            [
                'id' => 3,
                'ticket_id' => 3,
                'author_id' => 1,
                'author_name' => 'Laura Bianchi',
                'body' => 'Manifest ufficiale inviato per approvazione cliente.',
                'attachments' => json_encode(['manifests' => ['manifesto-demo.pdf']], JSON_UNESCAPED_UNICODE),
                'is_internal' => 0,
                'visibility' => 'customer',
                'status_snapshot' => 'WAITING_CLIENT',
                'notified_client' => 1,
                'notified_admin' => 0,
                'created_at' => '2025-12-03 13:15:00',
                'updated_at' => '2025-12-03 13:15:00',
            ],
            [
                'id' => 4,
                'ticket_id' => 1,
                'author_id' => null,
                'author_name' => 'Atlas Living SRL',
                'body' => 'Grazie, confermo che la sincronizzazione è tornata operativa.',
                'attachments' => null,
                'is_internal' => 0,
                'visibility' => 'customer',
                'status_snapshot' => 'IN_PROGRESS',
                'notified_client' => 0,
                'notified_admin' => 1,
                'created_at' => '2025-12-04 11:10:00',
                'updated_at' => '2025-12-04 11:10:00',
            ],
        ];
    }

    private static function emailSubscribers(): array
    {
        return [
            [
                'id' => 1,
                'email' => 'cliente1@demo.coresuite.it',
                'first_name' => 'Luca',
                'last_name' => 'Marin',
                'tags' => json_encode(['energia'], JSON_UNESCAPED_UNICODE),
                'status' => 'active',
                'source' => 'manual',
                'last_engagement_at' => '2025-11-29 12:00:00',
                'unsubscribed_at' => null,
                'created_at' => '2025-10-10 09:00:00',
                'updated_at' => '2025-11-29 12:00:00',
            ],
            [
                'id' => 2,
                'email' => 'cliente2@demo.coresuite.it',
                'first_name' => 'Serena',
                'last_name' => 'Costa',
                'tags' => json_encode(['marketing'], JSON_UNESCAPED_UNICODE),
                'status' => 'active',
                'source' => 'form',
                'last_engagement_at' => '2025-11-25 09:30:00',
                'unsubscribed_at' => null,
                'created_at' => '2025-10-15 09:00:00',
                'updated_at' => '2025-11-25 09:30:00',
            ],
            [
                'id' => 3,
                'email' => 'cliente3@demo.coresuite.it',
                'first_name' => 'Andrea',
                'last_name' => 'Santi',
                'tags' => json_encode(['logistica'], JSON_UNESCAPED_UNICODE),
                'status' => 'active',
                'source' => 'manual',
                'last_engagement_at' => '2025-11-30 17:00:00',
                'unsubscribed_at' => null,
                'created_at' => '2025-10-20 09:00:00',
                'updated_at' => '2025-11-30 17:00:00',
            ],
            [
                'id' => 4,
                'email' => 'cliente4@demo.coresuite.it',
                'first_name' => 'Paola',
                'last_name' => 'Testa',
                'tags' => json_encode(['anpr'], JSON_UNESCAPED_UNICODE),
                'status' => 'active',
                'source' => 'import',
                'last_engagement_at' => null,
                'unsubscribed_at' => null,
                'created_at' => '2025-11-01 09:00:00',
                'updated_at' => '2025-11-01 09:00:00',
            ],
        ];
    }

    private static function emailLists(): array
    {
        return [
            ['id' => 1, 'name' => 'Clienti energia', 'description' => 'Clienti interessati a servizi energia', 'created_at' => '2025-10-12 10:00:00', 'updated_at' => '2025-10-12 10:00:00'],
            ['id' => 2, 'name' => 'Marketing automation', 'description' => 'Lead per automation', 'created_at' => '2025-10-18 10:00:00', 'updated_at' => '2025-10-18 10:00:00'],
        ];
    }

    private static function emailListSubscribers(): array
    {
        return [
            ['list_id' => 1, 'subscriber_id' => 1, 'status' => 'active', 'subscribed_at' => '2025-10-12 10:05:00', 'unsubscribed_at' => null],
            ['list_id' => 1, 'subscriber_id' => 3, 'status' => 'active', 'subscribed_at' => '2025-10-20 10:05:00', 'unsubscribed_at' => null],
            ['list_id' => 2, 'subscriber_id' => 2, 'status' => 'active', 'subscribed_at' => '2025-10-18 11:05:00', 'unsubscribed_at' => null],
            ['list_id' => 2, 'subscriber_id' => 4, 'status' => 'active', 'subscribed_at' => '2025-11-01 11:05:00', 'unsubscribed_at' => null],
        ];
    }

    private static function emailTemplates(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Template newsletter energia',
                'subject' => 'Novità energia intelligente',
                'preheader' => 'Dashboard e automazioni per il tuo business',
                'html' => '<h1>Energia smart</h1><p>Scopri le novità del mese.</p>',
                'created_by' => 2,
                'created_at' => '2025-11-01 09:00:00',
                'updated_at' => '2025-11-15 10:00:00',
            ],
        ];
    }

    private static function emailCampaigns(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Energia Insights Dicembre',
                'subject' => '3 trend per ridurre i costi nel 2026',
                'from_name' => 'Team Energia Demo',
                'from_email' => 'energia@demo.coresuite.it',
                'reply_to' => 'energia@demo.coresuite.it',
                'template_id' => 1,
                'content_html' => '<p>Benvenuto nella newsletter energia.</p>',
                'content_plain' => 'Benvenuto nella newsletter energia.',
                'audience_type' => 'list',
                'audience_filters' => json_encode(['list_id' => 1], JSON_UNESCAPED_UNICODE),
                'status' => 'scheduled',
                'scheduled_at' => '2025-12-06 09:00:00',
                'sent_at' => null,
                'metrics_summary' => json_encode(['expected' => 350], JSON_UNESCAPED_UNICODE),
                'last_error' => null,
                'created_by' => 2,
                'created_at' => '2025-12-02 10:30:00',
                'updated_at' => '2025-12-04 12:00:00',
            ],
            [
                'id' => 2,
                'name' => 'Automation Playbook 2025',
                'subject' => 'Nuovi modelli email ready-to-use',
                'from_name' => 'Marketing Demo',
                'from_email' => 'marketing@demo.coresuite.it',
                'reply_to' => 'marketing@demo.coresuite.it',
                'template_id' => 1,
                'content_html' => '<p>Automation story e KPI.</p>',
                'content_plain' => 'Automation story e KPI.',
                'audience_type' => 'manual',
                'audience_filters' => json_encode(['tags' => ['marketing']], JSON_UNESCAPED_UNICODE),
                'status' => 'sent',
                'scheduled_at' => '2025-11-25 09:00:00',
                'sent_at' => '2025-11-25 09:00:00',
                'metrics_summary' => json_encode(['open_rate' => 0.56, 'click_rate' => 0.12], JSON_UNESCAPED_UNICODE),
                'last_error' => null,
                'created_by' => 2,
                'created_at' => '2025-11-20 08:00:00',
                'updated_at' => '2025-11-25 10:30:00',
            ],
        ];
    }

    private static function emailCampaignRecipients(): array
    {
        return [
            [
                'id' => 1,
                'campaign_id' => 2,
                'subscriber_id' => 2,
                'email' => 'cliente2@demo.coresuite.it',
                'first_name' => 'Serena',
                'last_name' => 'Costa',
                'status' => 'sent',
                'sent_at' => '2025-11-25 09:00:00',
                'last_error' => null,
                'opens' => 2,
                'last_open_at' => '2025-11-25 13:00:00',
                'clicks' => 1,
                'last_click_at' => '2025-11-25 13:05:00',
                'unsubscribe_token' => 'demo-token-rcp-1',
                'created_at' => '2025-11-20 08:05:00',
            ],
            [
                'id' => 2,
                'campaign_id' => 2,
                'subscriber_id' => 4,
                'email' => 'cliente4@demo.coresuite.it',
                'first_name' => 'Paola',
                'last_name' => 'Testa',
                'status' => 'sent',
                'sent_at' => '2025-11-25 09:00:00',
                'last_error' => null,
                'opens' => 1,
                'last_open_at' => '2025-11-25 10:00:00',
                'clicks' => 0,
                'last_click_at' => null,
                'unsubscribe_token' => 'demo-token-rcp-2',
                'created_at' => '2025-11-20 08:06:00',
            ],
            [
                'id' => 3,
                'campaign_id' => 1,
                'subscriber_id' => 1,
                'email' => 'cliente1@demo.coresuite.it',
                'first_name' => 'Luca',
                'last_name' => 'Marin',
                'status' => 'pending',
                'sent_at' => null,
                'last_error' => null,
                'opens' => 0,
                'last_open_at' => null,
                'clicks' => 0,
                'last_click_at' => null,
                'unsubscribe_token' => 'demo-token-rcp-3',
                'created_at' => '2025-12-02 10:35:00',
            ],
            [
                'id' => 4,
                'campaign_id' => 1,
                'subscriber_id' => 3,
                'email' => 'cliente3@demo.coresuite.it',
                'first_name' => 'Andrea',
                'last_name' => 'Santi',
                'status' => 'pending',
                'sent_at' => null,
                'last_error' => null,
                'opens' => 0,
                'last_open_at' => null,
                'clicks' => 0,
                'last_click_at' => null,
                'unsubscribe_token' => 'demo-token-rcp-4',
                'created_at' => '2025-12-02 10:36:00',
            ],
            [
                'id' => 5,
                'campaign_id' => 1,
                'subscriber_id' => 4,
                'email' => 'cliente4@demo.coresuite.it',
                'first_name' => 'Paola',
                'last_name' => 'Testa',
                'status' => 'pending',
                'sent_at' => null,
                'last_error' => null,
                'opens' => 0,
                'last_open_at' => null,
                'clicks' => 0,
                'last_click_at' => null,
                'unsubscribe_token' => 'demo-token-rcp-5',
                'created_at' => '2025-12-02 10:37:00',
            ],
        ];
    }

    private static function emailCampaignEvents(): array
    {
        return [
            [
                'id' => 1,
                'campaign_id' => 2,
                'recipient_id' => 1,
                'event_type' => 'open',
                'meta' => json_encode(['ip' => '5.170.12.1'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => '2025-11-25 13:00:00',
            ],
            [
                'id' => 2,
                'campaign_id' => 2,
                'recipient_id' => 1,
                'event_type' => 'click',
                'meta' => json_encode(['link' => 'https://demo.coresuite.it/case-study'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => '2025-11-25 13:05:00',
            ],
            [
                'id' => 3,
                'campaign_id' => 2,
                'recipient_id' => 2,
                'event_type' => 'open',
                'meta' => null,
                'occurred_at' => '2025-11-25 10:00:00',
            ],
        ];
    }

    private static function energyContracts(): array
    {
        return [
            [
                'id' => 1,
                'cliente_id' => 1,
                'contract_code' => 'ENX-CL-2025-01',
                'nominativo' => 'Sara Greco',
                'codice_fiscale' => 'GRCFNC85L41F205B',
                'email' => 'sara.greco@atlasliving.it',
                'telefono' => '+39 02 5566770',
                'fornitura' => 'luce',
                'operazione' => 'Switch',
                'note' => 'Upgrade potenza 6kW',
                'stato' => 'Registrato',
                'email_sent_at' => '2025-11-26 11:00:00',
                'reminder_sent_at' => null,
                'last_reminder_subject' => null,
                'created_by' => 3,
                'created_at' => '2025-11-25 09:00:00',
                'updated_at' => '2025-11-26 11:00:00',
            ],
            [
                'id' => 2,
                'cliente_id' => 2,
                'contract_code' => 'ENX-CL-2025-02',
                'nominativo' => 'Marta Villa',
                'codice_fiscale' => 'VLLMRT90P54L219Q',
                'email' => 'marta.villa@borealconsulting.it',
                'telefono' => '+39 011 9988770',
                'fornitura' => 'gas',
                'operazione' => 'Subentro',
                'note' => 'Attesa firma amministratore.',
                'stato' => 'Email inviata',
                'email_sent_at' => '2025-11-29 15:00:00',
                'reminder_sent_at' => '2025-12-02 10:00:00',
                'last_reminder_subject' => 'Richiamo documenti gas',
                'created_by' => 3,
                'created_at' => '2025-11-28 08:00:00',
                'updated_at' => '2025-12-02 10:00:00',
            ],
        ];
    }

    private static function energyAttachments(): array
    {
        return [
            [
                'id' => 1,
                'contratto_id' => 1,
                'file_name' => 'documento-identita.pdf',
                'file_path' => 'uploads/demo/energia/documento-identita.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 184000,
                'created_at' => '2025-11-25 09:10:00',
            ],
            [
                'id' => 2,
                'contratto_id' => 2,
                'file_name' => 'visura-camerale.pdf',
                'file_path' => 'uploads/demo/energia/visura-camerale.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 220000,
                'created_at' => '2025-11-29 15:05:00',
            ],
        ];
    }

    private static function anprCases(): array
    {
        return [
            [
                'id' => 1,
                'pratica_code' => 'ANPR-2025-014',
                'cliente_id' => 3,
                'tipo_pratica' => 'Certificato residenza',
                'stato' => 'In lavorazione',
                'note_interne' => 'In attesa pagamento bollo.',
                'certificato_path' => null,
                'certificato_hash' => null,
                'certificato_caricato_at' => null,
                'operatore_id' => 3,
                'created_at' => '2025-11-30 10:00:00',
                'updated_at' => '2025-12-01 08:40:00',
            ],
            [
                'id' => 2,
                'pratica_code' => 'ANPR-2025-015',
                'cliente_id' => 1,
                'tipo_pratica' => 'Certificato stato di famiglia',
                'stato' => 'Completato',
                'note_interne' => 'Scaricato e inviato via mail.',
                'certificato_path' => 'uploads/demo/anpr/stato-famiglia.pdf',
                'certificato_hash' => '1e8c8a11695f4a8cb97faee6b3c415c44c2aaca5c03c0bf1a6e665e9a2c8fbcf',
                'certificato_caricato_at' => '2025-11-29 12:10:00',
                'operatore_id' => 3,
                'created_at' => '2025-11-28 09:30:00',
                'updated_at' => '2025-11-29 12:10:00',
            ],
        ];
    }

    private static function aiConversations(): array
    {
        return [
            [
                'id' => 1,
                'user_id' => 1,
                'session_id' => 'demo-session-001',
                'question' => 'Mostrami i clienti con margine > 200€',
                'answer' => 'Nel trimestre corrente 3 clienti hanno generato un margine superiore a 200€.',
                'context' => 'dashboard',
                'created_at' => '2025-12-03 09:15:00',
            ],
        ];
    }

    private static function aiPreferences(): array
    {
        return [
            [
                'id' => 1,
                'user_id' => 1,
                'preference_key' => 'assistant_tone',
                'preference_value' => 'analitico',
                'updated_at' => '2025-11-30 09:00:00',
            ],
            [
                'id' => 2,
                'user_id' => 2,
                'preference_key' => 'assistant_language',
                'preference_value' => 'it-IT',
                'updated_at' => '2025-11-30 10:00:00',
            ],
        ];
    }

    private static function brtShipments(): array
    {
        return [
            [
                'id' => 1,
                'sender_customer_code' => '1222463',
                'numeric_sender_reference' => 78541001,
                'alphanumeric_sender_reference' => 'LV-2025-001',
                'departure_depot' => '122',
                'arrival_depot' => '045',
                'arrival_terminal' => 'MI2',
                'parcel_number_from' => '001',
                'parcel_number_to' => '001',
                'parcel_id' => 'PRC-0001',
                'number_of_parcels' => 1,
                'weight_kg' => '4.500',
                'volume_m3' => '0.012',
                'consignee_name' => 'Planet Store',
                'consignee_address' => 'Via Appia 40',
                'consignee_zip' => '00179',
                'consignee_city' => 'Roma',
                'consignee_province' => 'RM',
                'consignee_country' => 'IT',
                'consignee_phone' => '+39 06 1234567',
                'consignee_email' => 'magazzino@planetstore.it',
                'status' => 'confirmed',
                'execution_code' => 0,
                'execution_code_description' => null,
                'execution_message' => null,
                'label_path' => 'uploads/demo/brt/labels/LV-2025-001.pdf',
                'request_payload' => '{}',
                'response_payload' => '{}',
                'confirmed_at' => '2025-11-30 09:10:00',
                'deleted_at' => null,
                'last_tracking_payload' => null,
                'last_tracking_at' => null,
                'created_at' => '2025-11-30 09:00:00',
                'updated_at' => '2025-11-30 09:10:00',
            ],
            [
                'id' => 2,
                'sender_customer_code' => '1222463',
                'numeric_sender_reference' => 78541002,
                'alphanumeric_sender_reference' => 'LV-2025-002',
                'departure_depot' => '122',
                'arrival_depot' => '007',
                'arrival_terminal' => 'MI1',
                'parcel_number_from' => '001',
                'parcel_number_to' => '003',
                'parcel_id' => 'PRC-0002',
                'number_of_parcels' => 3,
                'weight_kg' => '18.000',
                'volume_m3' => '0.045',
                'consignee_name' => 'Retail Hub',
                'consignee_address' => 'Via Milano 5',
                'consignee_zip' => '20019',
                'consignee_city' => 'Settimo Milanese',
                'consignee_province' => 'MI',
                'consignee_country' => 'IT',
                'consignee_phone' => '+39 02 4455660',
                'consignee_email' => 'hub@retail.it',
                'status' => 'created',
                'execution_code' => 0,
                'execution_code_description' => null,
                'execution_message' => null,
                'label_path' => null,
                'request_payload' => '{}',
                'response_payload' => null,
                'confirmed_at' => null,
                'deleted_at' => null,
                'last_tracking_payload' => null,
                'last_tracking_at' => null,
                'created_at' => '2025-12-02 11:00:00',
                'updated_at' => '2025-12-02 11:00:00',
            ],
            [
                'id' => 3,
                'sender_customer_code' => '1222463',
                'numeric_sender_reference' => 78540990,
                'alphanumeric_sender_reference' => 'AT-2025-014',
                'departure_depot' => '122',
                'arrival_depot' => '041',
                'arrival_terminal' => 'MI4',
                'parcel_number_from' => '001',
                'parcel_number_to' => '001',
                'parcel_id' => 'PRC-0003',
                'number_of_parcels' => 1,
                'weight_kg' => '2.100',
                'volume_m3' => '0.008',
                'consignee_name' => 'Filiale Roma Atlas',
                'consignee_address' => 'Via delle Vigne 22',
                'consignee_zip' => '00148',
                'consignee_city' => 'Roma',
                'consignee_province' => 'RM',
                'consignee_country' => 'IT',
                'consignee_phone' => '+39 06 6655778',
                'consignee_email' => 'roma@atlasliving.it',
                'status' => 'delivered',
                'execution_code' => 0,
                'execution_code_description' => null,
                'execution_message' => null,
                'label_path' => 'uploads/demo/brt/labels/AT-2025-014.pdf',
                'request_payload' => '{}',
                'response_payload' => '{}',
                'confirmed_at' => '2025-11-20 09:30:00',
                'deleted_at' => null,
                'last_tracking_payload' => json_encode(['status' => 'DELIVERED'], JSON_UNESCAPED_UNICODE),
                'last_tracking_at' => '2025-11-21 12:00:00',
                'created_at' => '2025-11-18 09:00:00',
                'updated_at' => '2025-11-21 12:00:00',
            ],
        ];
    }

    private static function brtOrmRequests(): array
    {
        return [
            [
                'id' => 1,
                'reservation_number' => 'ORM-2025-001',
                'status' => 'completed',
                'collection_date' => '2025-11-30',
                'payer_type' => 'sender',
                'parcels' => 3,
                'weight_kg' => '18.000',
                'request_payload' => json_encode(['pickup' => 'Linea Verde'], JSON_UNESCAPED_UNICODE),
                'response_payload' => json_encode(['status' => 'OK'], JSON_UNESCAPED_UNICODE),
                'errors_payload' => null,
                'created_at' => '2025-11-29 18:00:00',
                'updated_at' => '2025-11-30 08:00:00',
            ],
        ];
    }
}
