<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

use App\Services\Curriculum\CurriculumBuilderService;

const DEMO_EMAIL = 'demo.curriculum@example.com';
const DEMO_TITOLO = 'Curriculum Europass Demo';

try {
    $pdo->beginTransaction();

    $clientStmt = $pdo->prepare('SELECT id FROM clienti WHERE email = :email LIMIT 1');
    $clientStmt->execute([':email' => DEMO_EMAIL]);
    $clientId = (int) $clientStmt->fetchColumn();

    if ($clientId <= 0) {
        $insertClient = $pdo->prepare('INSERT INTO clienti (ragione_sociale, nome, cognome, email, telefono, indirizzo) VALUES (:ragione_sociale, :nome, :cognome, :email, :telefono, :indirizzo)');
        $insertClient->execute([
            ':ragione_sociale' => 'Demo Consulting S.r.l.',
            ':nome' => 'Giulia',
            ':cognome' => 'Rossi',
            ':email' => DEMO_EMAIL,
            ':telefono' => '+39 081 1234567',
            ':indirizzo' => 'Via Roma 10, 80100 Napoli',
        ]);
        $clientId = (int) $pdo->lastInsertId();
    }

    $cvStmt = $pdo->prepare('SELECT id FROM curriculum WHERE cliente_id = :cliente_id AND titolo = :titolo LIMIT 1');
    $cvStmt->execute([
        ':cliente_id' => $clientId,
        ':titolo' => DEMO_TITOLO,
    ]);
    $curriculumId = (int) $cvStmt->fetchColumn();

    if ($curriculumId > 0) {
        $updateCv = $pdo->prepare('UPDATE curriculum SET professional_summary = :professional_summary, key_competences = :key_competences, digital_competences = :digital_competences, driving_license = :driving_license, additional_information = :additional_information, status = :status WHERE id = :id');
        $updateCv->execute([
            ':professional_summary' => 'Consulente commerciale con 8 anni di esperienza nello sviluppo di nuovi mercati e nella gestione di team multidisciplinari.',
            ':key_competences' => 'Vendita consulenziale, gestione portfolio clienti, analisi KPI.',
            ':digital_competences' => 'CRM Salesforce, Microsoft 365, Google Workspace, HubSpot Marketing.',
            ':driving_license' => 'Patente B',
            ':additional_information' => 'Disponibilità a trasferte in Italia ed Europa. Volontaria CRI dal 2015.',
            ':status' => 'Pubblicato',
            ':id' => $curriculumId,
        ]);
    } else {
        $insertCv = $pdo->prepare('INSERT INTO curriculum (cliente_id, titolo, professional_summary, key_competences, digital_competences, driving_license, additional_information, status) VALUES (:cliente_id, :titolo, :professional_summary, :key_competences, :digital_competences, :driving_license, :additional_information, :status)');
        $insertCv->execute([
            ':cliente_id' => $clientId,
            ':titolo' => DEMO_TITOLO,
            ':professional_summary' => 'Consulente commerciale con 8 anni di esperienza nello sviluppo di nuovi mercati e nella gestione di team multidisciplinari.',
            ':key_competences' => 'Vendita consulenziale, gestione portfolio clienti, analisi KPI.',
            ':digital_competences' => 'CRM Salesforce, Microsoft 365, Google Workspace, HubSpot Marketing.',
            ':driving_license' => 'Patente B',
            ':additional_information' => 'Disponibilità a trasferte in Italia ed Europa. Volontaria CRI dal 2015.',
            ':status' => 'Pubblicato',
        ]);
        $curriculumId = (int) $pdo->lastInsertId();
    }

    $deleteSection = static function (\PDO $pdo, int $cvId, string $table): void {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE curriculum_id = :id");
        $stmt->execute([':id' => $cvId]);
    };

    $deleteSection($pdo, $curriculumId, 'curriculum_experiences');
    $deleteSection($pdo, $curriculumId, 'curriculum_education');
    $deleteSection($pdo, $curriculumId, 'curriculum_languages');
    $deleteSection($pdo, $curriculumId, 'curriculum_skills');

    $experienceInsert = $pdo->prepare('INSERT INTO curriculum_experiences (curriculum_id, role_title, employer, city, country, start_date, end_date, is_current, description, ordering) VALUES (:curriculum_id, :role_title, :employer, :city, :country, :start_date, :end_date, :is_current, :description, :ordering)');

    $experiences = [
        [
            'role_title' => 'Sales Manager Italia',
            'employer' => 'Demo Consulting S.r.l.',
            'city' => 'Napoli',
            'country' => 'Italia',
            'start_date' => '2021-01-01',
            'end_date' => null,
            'is_current' => 1,
            'description' => 'Coordinamento di un team di 8 account, definizione budget annuale, sviluppo partnership con nuovi fornitori.',
        ],
        [
            'role_title' => 'Account Executive',
            'employer' => 'Tech4Business S.p.A.',
            'city' => 'Milano',
            'country' => 'Italia',
            'start_date' => '2017-03-01',
            'end_date' => '2020-12-01',
            'is_current' => 0,
            'description' => 'Gestione portafoglio clienti PMI, upselling servizi digitali, formazione commerciale junior.',
        ],
    ];

    foreach ($experiences as $index => $experience) {
        $experienceInsert->execute([
            ':curriculum_id' => $curriculumId,
            ':role_title' => $experience['role_title'],
            ':employer' => $experience['employer'],
            ':city' => $experience['city'],
            ':country' => $experience['country'],
            ':start_date' => $experience['start_date'],
            ':end_date' => $experience['is_current'] ? null : $experience['end_date'],
            ':is_current' => $experience['is_current'],
            ':description' => $experience['description'],
            ':ordering' => $index,
        ]);
    }

    $educationInsert = $pdo->prepare('INSERT INTO curriculum_education (curriculum_id, title, institution, city, country, start_date, end_date, qualification_level, description, ordering) VALUES (:curriculum_id, :title, :institution, :city, :country, :start_date, :end_date, :qualification_level, :description, :ordering)');

    $educationEntries = [
        [
            'title' => 'Laurea Triennale in Economia Aziendale',
            'institution' => 'Università degli Studi di Napoli Federico II',
            'city' => 'Napoli',
            'country' => 'Italia',
            'start_date' => '2013-10-01',
            'end_date' => '2016-07-15',
            'qualification_level' => 'Livello EQF 6',
            'description' => 'Percorso focalizzato su marketing e management con tesi su strategie omnicanale.',
        ],
        [
            'title' => 'Master Executive in Digital Marketing',
            'institution' => 'Sole24ORE Business School',
            'city' => 'Roma',
            'country' => 'Italia',
            'start_date' => '2018-01-10',
            'end_date' => '2018-12-10',
            'qualification_level' => 'Certificazione post laurea',
            'description' => 'Approfondimento tattiche di lead generation, automation e data analytics.',
        ],
    ];

    foreach ($educationEntries as $index => $education) {
        $educationInsert->execute([
            ':curriculum_id' => $curriculumId,
            ':title' => $education['title'],
            ':institution' => $education['institution'],
            ':city' => $education['city'],
            ':country' => $education['country'],
            ':start_date' => $education['start_date'],
            ':end_date' => $education['end_date'],
            ':qualification_level' => $education['qualification_level'],
            ':description' => $education['description'],
            ':ordering' => $index,
        ]);
    }

    $languageInsert = $pdo->prepare('INSERT INTO curriculum_languages (curriculum_id, language, overall_level, listening, reading, interaction, production, writing, certification) VALUES (:curriculum_id, :language, :overall_level, :listening, :reading, :interaction, :production, :writing, :certification)');

    $languages = [
        [
            'language' => 'Inglese',
            'overall_level' => 'C1',
            'listening' => 'C1',
            'reading' => 'C1',
            'interaction' => 'C1',
            'production' => 'B2',
            'writing' => 'B2',
            'certification' => 'IELTS Academic 7.5 (2023)',
        ],
        [
            'language' => 'Spagnolo',
            'overall_level' => 'B2',
            'listening' => 'B2',
            'reading' => 'B2',
            'interaction' => 'B1',
            'production' => 'B1',
            'writing' => 'B1',
            'certification' => '',
        ],
    ];

    foreach ($languages as $language) {
        $languageInsert->execute([
            ':curriculum_id' => $curriculumId,
            ':language' => $language['language'],
            ':overall_level' => $language['overall_level'],
            ':listening' => $language['listening'],
            ':reading' => $language['reading'],
            ':interaction' => $language['interaction'],
            ':production' => $language['production'],
            ':writing' => $language['writing'],
            ':certification' => $language['certification'] !== '' ? $language['certification'] : null,
        ]);
    }

    $skillsInsert = $pdo->prepare('INSERT INTO curriculum_skills (curriculum_id, category, skill, level, description, ordering) VALUES (:curriculum_id, :category, :skill, :level, :description, :ordering)');

    $skills = [
        [
            'category' => 'Competenze comunicative',
            'skill' => 'Public speaking e presentazioni commerciali',
            'level' => 'Avanzato',
            'description' => 'Gestione workshop e demo prodotto per platee fino a 60 persone.',
        ],
        [
            'category' => 'Competenze digitali',
            'skill' => 'Marketing automation',
            'level' => 'Esperto',
            'description' => 'Progettazione funnel lead nurturing su HubSpot con incremento conversioni +28%.',
        ],
        [
            'category' => 'Competenze organizzative',
            'skill' => 'Leadership di team commerciali',
            'level' => 'Avanzato',
            'description' => 'Coordinamento team cross-funzionali, definizione OKR e coaching individuale.',
        ],
    ];

    foreach ($skills as $index => $skill) {
        $skillsInsert->execute([
            ':curriculum_id' => $curriculumId,
            ':category' => $skill['category'],
            ':skill' => $skill['skill'],
            ':level' => $skill['level'],
            ':description' => $skill['description'],
            ':ordering' => $index,
        ]);
    }

    $pdo->commit();

    $builder = new CurriculumBuilderService($pdo, realpath(__DIR__ . '/..') ?: __DIR__ . '/..');
    $result = $builder->buildEuropass($curriculumId);

    $updateGenerated = $pdo->prepare('UPDATE curriculum SET generated_file = :generated_file, last_generated_at = :last_generated_at, status = :status WHERE id = :id');
    $updateGenerated->execute([
        ':generated_file' => $result['relative_path'],
        ':last_generated_at' => $result['generated_at'],
        ':status' => 'Pubblicato',
        ':id' => $curriculumId,
    ]);

    echo "Curriculum demo pronto.\n";
    echo 'Cliente ID: ' . $clientId . "\n";
    echo 'Curriculum ID: ' . $curriculumId . "\n";
    echo 'PDF generato in: ' . $result['relative_path'] . "\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Errore durante la creazione del curriculum demo: ' . $exception->getMessage() . "\n");
    exit(1);
}
