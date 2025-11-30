<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$statuses = ['Bozza', 'Pubblicato', 'Archiviato'];
$languageLevels = ['Madrelingua', 'C2', 'C1', 'B2', 'B1', 'A2', 'A1'];
$skillLevelOptions = ['Base', 'Intermedio', 'Avanzato', 'Esperto'];
$skillCategories = ['Competenze comunicative', 'Competenze organizzative', 'Competenze digitali', 'Competenze tecniche', 'Altro'];

$clients = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY cognome, nome')->fetchAll(PDO::FETCH_ASSOC);
$clientIds = array_map(
    static function ($client): int {
        return (int) $client['id'];
    },
    $clients
);

$defaultForm = [
    'cliente_id' => '',
    'titolo' => '',
    'status' => 'Bozza',
    'professional_summary' => '',
    'key_competences' => '',
    'digital_competences' => '',
    'driving_license' => '',
    'additional_information' => '',
];

$sectionData = [
    'experiences' => [],
    'education' => [],
    'languages' => [],
    'skills' => [],
];

$errors = [];
$curriculumId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$csrfToken = csrf_token();

$validateDate = static function (string $value): bool {
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$loadSections = static function (PDO $pdoInstance, int $id) {
    if ($id <= 0) {
        return [
            'experiences' => [],
            'education' => [],
            'languages' => [],
            'skills' => [],
        ];
    }

    $sections = [];

    $stmt = $pdoInstance->prepare('SELECT * FROM curriculum_experiences WHERE curriculum_id = :id ORDER BY ordering ASC, id ASC');
    $stmt->execute([':id' => $id]);
    $sections['experiences'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdoInstance->prepare('SELECT * FROM curriculum_education WHERE curriculum_id = :id ORDER BY ordering ASC, id ASC');
    $stmt->execute([':id' => $id]);
    $sections['education'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdoInstance->prepare('SELECT * FROM curriculum_languages WHERE curriculum_id = :id ORDER BY language ASC, id ASC');
    $stmt->execute([':id' => $id]);
    $sections['languages'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdoInstance->prepare('SELECT * FROM curriculum_skills WHERE curriculum_id = :id ORDER BY ordering ASC, id ASC');
    $stmt->execute([':id' => $id]);
    $sections['skills'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $sections;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $curriculumId = (int) ($_POST['id'] ?? 0);
    $formData = $defaultForm;
    $formData['cliente_id'] = (string) ($_POST['cliente_id'] ?? '');
    $formData['titolo'] = trim((string) ($_POST['titolo'] ?? ''));
    $postedStatus = (string) ($_POST['status'] ?? '');
    $formData['status'] = in_array($postedStatus, $statuses, true) ? $postedStatus : 'Bozza';
    $formData['professional_summary'] = trim((string) ($_POST['professional_summary'] ?? ''));
    $formData['key_competences'] = trim((string) ($_POST['key_competences'] ?? ''));
    $formData['digital_competences'] = trim((string) ($_POST['digital_competences'] ?? ''));
    $formData['driving_license'] = trim((string) ($_POST['driving_license'] ?? ''));
    $formData['additional_information'] = trim((string) ($_POST['additional_information'] ?? ''));

    $clienteId = (int) $formData['cliente_id'];
    if ($clienteId <= 0 || !in_array($clienteId, $clientIds, true)) {
        $errors[] = 'Seleziona un cliente valido.';
    }
    if ($formData['titolo'] === '') {
        $errors[] = 'Inserisci un titolo descrittivo per il curriculum.';
    }

    $sectionData = [
        'experiences' => [],
        'education' => [],
        'languages' => [],
        'skills' => [],
    ];

    $experiencesRaw = $_POST['experiences'] ?? [];
    if (is_array($experiencesRaw)) {
        foreach ($experiencesRaw as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = trim((string) ($row['role_title'] ?? ''));
            $employer = trim((string) ($row['employer'] ?? ''));
            $startDate = trim((string) ($row['start_date'] ?? ''));
            $endDate = trim((string) ($row['end_date'] ?? ''));
            $isCurrent = isset($row['is_current']) && (int) $row['is_current'] === 1;
            $description = trim((string) ($row['description'] ?? ''));
            $city = trim((string) ($row['city'] ?? ''));
            $country = trim((string) ($row['country'] ?? ''));
            $hasData = $role !== '' || $employer !== '' || $startDate !== '' || $endDate !== '' || $description !== '' || $city !== '' || $country !== '' || $isCurrent;

            if (!$hasData) {
                continue;
            }
            if ($role === '') {
                $errors[] = 'Indica il ruolo per l\'esperienza n. ' . ($index + 1) . '.';
                continue;
            }
            if ($employer === '') {
                $errors[] = 'Indica il datore di lavoro per l\'esperienza n. ' . ($index + 1) . '.';
                continue;
            }
            if ($startDate === '') {
                $errors[] = 'Specifica la data di inizio per l\'esperienza n. ' . ($index + 1) . '.';
                continue;
            }
            if (!$validateDate($startDate) || ($endDate !== '' && !$validateDate($endDate))) {
                $errors[] = 'Le date inserite per l\'esperienza n. ' . ($index + 1) . ' non sono valide.';
                continue;
            }
            if (!$isCurrent && $endDate !== '') {
                try {
                    $startObj = new DateTimeImmutable($startDate);
                    $endObj = new DateTimeImmutable($endDate);
                    if ($endObj < $startObj) {
                        $errors[] = 'La data di fine non può precedere l\'inizio per l\'esperienza n. ' . ($index + 1) . '.';
                        continue;
                    }
                } catch (Throwable) {
                    $errors[] = 'Formato data non riconosciuto nell\'esperienza n. ' . ($index + 1) . '.';
                    continue;
                }
            }
            $sectionData['experiences'][] = [
                'role_title' => $role,
                'employer' => $employer,
                'city' => $city,
                'country' => $country,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_current' => $isCurrent ? 1 : 0,
                'description' => $description,
            ];
        }
    }

    $educationRaw = $_POST['education'] ?? [];
    if (is_array($educationRaw)) {
        foreach ($educationRaw as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $institution = trim((string) ($row['institution'] ?? ''));
            $startDate = trim((string) ($row['start_date'] ?? ''));
            $endDate = trim((string) ($row['end_date'] ?? ''));
            $city = trim((string) ($row['city'] ?? ''));
            $country = trim((string) ($row['country'] ?? ''));
            $qualification = trim((string) ($row['qualification_level'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $hasData = $title !== '' || $institution !== '' || $startDate !== '' || $endDate !== '' || $qualification !== '' || $description !== '' || $city !== '' || $country !== '';

            if (!$hasData) {
                continue;
            }
            if ($title === '') {
                $errors[] = 'Indica il titolo del percorso formativo n. ' . ($index + 1) . '.';
                continue;
            }
            if ($institution === '') {
                $errors[] = 'Indica l\'istituto per il percorso formativo n. ' . ($index + 1) . '.';
                continue;
            }
            if ($startDate === '') {
                $errors[] = 'Specifica la data di inizio per il percorso formativo n. ' . ($index + 1) . '.';
                continue;
            }
            if (!$validateDate($startDate) || ($endDate !== '' && !$validateDate($endDate))) {
                $errors[] = 'Le date inserite per il percorso formativo n. ' . ($index + 1) . ' non sono valide.';
                continue;
            }
            if ($endDate !== '') {
                try {
                    $startObj = new DateTimeImmutable($startDate);
                    $endObj = new DateTimeImmutable($endDate);
                    if ($endObj < $startObj) {
                        $errors[] = 'La data di fine non può precedere l\'inizio per il percorso formativo n. ' . ($index + 1) . '.';
                        continue;
                    }
                } catch (Throwable) {
                    $errors[] = 'Formato data non riconosciuto nel percorso formativo n. ' . ($index + 1) . '.';
                    continue;
                }
            }
            $sectionData['education'][] = [
                'title' => $title,
                'institution' => $institution,
                'city' => $city,
                'country' => $country,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'qualification_level' => $qualification,
                'description' => $description,
            ];
        }
    }

    $languagesRaw = $_POST['languages'] ?? [];
    if (is_array($languagesRaw)) {
        foreach ($languagesRaw as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $language = trim((string) ($row['language'] ?? ''));
            $overallLevel = in_array(($row['overall_level'] ?? ''), $languageLevels, true) ? (string) $row['overall_level'] : '';
            $listening = in_array(($row['listening'] ?? ''), $languageLevels, true) ? (string) $row['listening'] : '';
            $reading = in_array(($row['reading'] ?? ''), $languageLevels, true) ? (string) $row['reading'] : '';
            $interaction = in_array(($row['interaction'] ?? ''), $languageLevels, true) ? (string) $row['interaction'] : '';
            $production = in_array(($row['production'] ?? ''), $languageLevels, true) ? (string) $row['production'] : '';
            $writing = in_array(($row['writing'] ?? ''), $languageLevels, true) ? (string) $row['writing'] : '';
            $certification = trim((string) ($row['certification'] ?? ''));
            $hasData = $language !== '' || $overallLevel !== '' || $listening !== '' || $reading !== '' || $interaction !== '' || $production !== '' || $writing !== '' || $certification !== '';

            if (!$hasData) {
                continue;
            }
            if ($language === '') {
                $errors[] = 'Indica la lingua per la competenza linguistica n. ' . ($index + 1) . '.';
                continue;
            }
            if ($overallLevel === '') {
                $errors[] = 'Seleziona il livello complessivo per la competenza linguistica n. ' . ($index + 1) . '.';
                continue;
            }
            $sectionData['languages'][] = [
                'language' => $language,
                'overall_level' => $overallLevel,
                'listening' => $listening,
                'reading' => $reading,
                'interaction' => $interaction,
                'production' => $production,
                'writing' => $writing,
                'certification' => $certification,
            ];
        }
    }

    $skillsRaw = $_POST['skills'] ?? [];
    if (is_array($skillsRaw)) {
        foreach ($skillsRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $category = trim((string) ($row['category'] ?? ''));
            $skill = trim((string) ($row['skill'] ?? ''));
            if ($category === '' && $skill === '' && trim((string) ($row['description'] ?? '')) === '') {
                continue;
            }
            $sectionData['skills'][] = [
                'category' => $category !== '' ? $category : 'Altro',
                'skill' => $skill,
                'level' => in_array(($row['level'] ?? ''), $skillLevelOptions, true) ? (string) $row['level'] : '',
                'description' => trim((string) ($row['description'] ?? '')),
            ];
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($curriculumId > 0) {
                $stmt = $pdo->prepare('UPDATE curriculum SET cliente_id = :cliente_id, titolo = :titolo, professional_summary = :professional_summary, key_competences = :key_competences, digital_competences = :digital_competences, driving_license = :driving_license, additional_information = :additional_information, status = :status WHERE id = :id');
                $stmt->execute([
                    ':cliente_id' => $clienteId,
                    ':titolo' => $formData['titolo'],
                    ':professional_summary' => $formData['professional_summary'] !== '' ? $formData['professional_summary'] : null,
                    ':key_competences' => $formData['key_competences'] !== '' ? $formData['key_competences'] : null,
                    ':digital_competences' => $formData['digital_competences'] !== '' ? $formData['digital_competences'] : null,
                    ':driving_license' => $formData['driving_license'] !== '' ? $formData['driving_license'] : null,
                    ':additional_information' => $formData['additional_information'] !== '' ? $formData['additional_information'] : null,
                    ':status' => $formData['status'],
                    ':id' => $curriculumId,
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO curriculum (cliente_id, titolo, professional_summary, key_competences, digital_competences, driving_license, additional_information, status) VALUES (:cliente_id, :titolo, :professional_summary, :key_competences, :digital_competences, :driving_license, :additional_information, :status)');
                $stmt->execute([
                    ':cliente_id' => $clienteId,
                    ':titolo' => $formData['titolo'],
                    ':professional_summary' => $formData['professional_summary'] !== '' ? $formData['professional_summary'] : null,
                    ':key_competences' => $formData['key_competences'] !== '' ? $formData['key_competences'] : null,
                    ':digital_competences' => $formData['digital_competences'] !== '' ? $formData['digital_competences'] : null,
                    ':driving_license' => $formData['driving_license'] !== '' ? $formData['driving_license'] : null,
                    ':additional_information' => $formData['additional_information'] !== '' ? $formData['additional_information'] : null,
                    ':status' => $formData['status'],
                ]);
                $curriculumId = (int) $pdo->lastInsertId();
            }

            $deleteSection = function (string $table) use ($pdo, $curriculumId): bool {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE curriculum_id = :id");
                return $stmt->execute([':id' => $curriculumId]);
            };
            $deleteSection('curriculum_experiences');
            $deleteSection('curriculum_education');
            $deleteSection('curriculum_languages');
            $deleteSection('curriculum_skills');

            if ($sectionData['experiences']) {
                $stmt = $pdo->prepare('INSERT INTO curriculum_experiences (curriculum_id, role_title, employer, city, country, start_date, end_date, is_current, description, ordering) VALUES (:curriculum_id, :role_title, :employer, :city, :country, :start_date, :end_date, :is_current, :description, :ordering)');
                foreach ($sectionData['experiences'] as $index => $experience) {
                    $stmt->execute([
                        ':curriculum_id' => $curriculumId,
                        ':role_title' => $experience['role_title'],
                        ':employer' => $experience['employer'],
                        ':city' => $experience['city'] !== '' ? $experience['city'] : null,
                        ':country' => $experience['country'] !== '' ? $experience['country'] : null,
                        ':start_date' => $experience['start_date'] !== '' ? $experience['start_date'] : null,
                        ':end_date' => $experience['is_current'] ? null : ($experience['end_date'] !== '' ? $experience['end_date'] : null),
                        ':is_current' => $experience['is_current'],
                        ':description' => $experience['description'] !== '' ? $experience['description'] : null,
                        ':ordering' => $index,
                    ]);
                }
            }

            if ($sectionData['education']) {
                $stmt = $pdo->prepare('INSERT INTO curriculum_education (curriculum_id, title, institution, city, country, start_date, end_date, qualification_level, description, ordering) VALUES (:curriculum_id, :title, :institution, :city, :country, :start_date, :end_date, :qualification_level, :description, :ordering)');
                foreach ($sectionData['education'] as $index => $item) {
                    $stmt->execute([
                        ':curriculum_id' => $curriculumId,
                        ':title' => $item['title'],
                        ':institution' => $item['institution'],
                        ':city' => $item['city'] !== '' ? $item['city'] : null,
                        ':country' => $item['country'] !== '' ? $item['country'] : null,
                        ':start_date' => $item['start_date'] !== '' ? $item['start_date'] : null,
                        ':end_date' => $item['end_date'] !== '' ? $item['end_date'] : null,
                        ':qualification_level' => $item['qualification_level'] !== '' ? $item['qualification_level'] : null,
                        ':description' => $item['description'] !== '' ? $item['description'] : null,
                        ':ordering' => $index,
                    ]);
                }
            }

            if ($sectionData['languages']) {
                $stmt = $pdo->prepare('INSERT INTO curriculum_languages (curriculum_id, language, overall_level, listening, reading, interaction, production, writing, certification) VALUES (:curriculum_id, :language, :overall_level, :listening, :reading, :interaction, :production, :writing, :certification)');
                foreach ($sectionData['languages'] as $item) {
                    $stmt->execute([
                        ':curriculum_id' => $curriculumId,
                        ':language' => $item['language'],
                        ':overall_level' => $item['overall_level'] !== '' ? $item['overall_level'] : null,
                        ':listening' => $item['listening'] !== '' ? $item['listening'] : null,
                        ':reading' => $item['reading'] !== '' ? $item['reading'] : null,
                        ':interaction' => $item['interaction'] !== '' ? $item['interaction'] : null,
                        ':production' => $item['production'] !== '' ? $item['production'] : null,
                        ':writing' => $item['writing'] !== '' ? $item['writing'] : null,
                        ':certification' => $item['certification'] !== '' ? $item['certification'] : null,
                    ]);
                }
            }

            if ($sectionData['skills']) {
                $stmt = $pdo->prepare('INSERT INTO curriculum_skills (curriculum_id, category, skill, level, description, ordering) VALUES (:curriculum_id, :category, :skill, :level, :description, :ordering)');
                foreach ($sectionData['skills'] as $index => $item) {
                    $stmt->execute([
                        ':curriculum_id' => $curriculumId,
                        ':category' => $item['category'],
                        ':skill' => $item['skill'],
                        ':level' => $item['level'] !== '' ? $item['level'] : null,
                        ':description' => $item['description'] !== '' ? $item['description'] : null,
                        ':ordering' => $index,
                    ]);
                }
            }

            $pdo->commit();

            add_flash('success', 'Curriculum salvato correttamente.');
            header('Location: wizard.php?id=' . $curriculumId);
            exit;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('Curriculum save failed: ' . $exception->getMessage());
            $errors[] = 'Impossibile salvare il curriculum, riprova tra qualche istante.';
        }
    }
} else {
    if ($curriculumId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM curriculum WHERE id = :id');
        $stmt->execute([':id' => $curriculumId]);
        $curriculum = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$curriculum) {
            add_flash('warning', 'Curriculum non trovato.');
            header('Location: index.php');
            exit;
        }
        $defaultForm = array_merge($defaultForm, [
            'cliente_id' => (string) ($curriculum['cliente_id'] ?? ''),
            'titolo' => (string) ($curriculum['titolo'] ?? ''),
            'status' => in_array(($curriculum['status'] ?? ''), $statuses, true) ? $curriculum['status'] : 'Bozza',
            'professional_summary' => (string) ($curriculum['professional_summary'] ?? ''),
            'key_competences' => (string) ($curriculum['key_competences'] ?? ''),
            'digital_competences' => (string) ($curriculum['digital_competences'] ?? ''),
            'driving_license' => (string) ($curriculum['driving_license'] ?? ''),
            'additional_information' => (string) ($curriculum['additional_information'] ?? ''),
        ]);
        $formData = $defaultForm;
        $sectionData = $loadSections($pdo, $curriculumId);
    } else {
        $formData = $defaultForm;
        $sectionData = [
            'experiences' => [],
            'education' => [],
            'languages' => [],
            'skills' => [],
        ];
    }
}

if (!isset($formData)) {
    $formData = $defaultForm;
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="mb-4">
            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Torna alla lista</a>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h1 class="h4 mb-1"><?php echo $curriculumId > 0 ? 'Modifica curriculum' : 'Nuovo curriculum'; ?></h1>
                        <p class="text-muted mb-0">Compila le sezioni richieste dallo standard Europass e salva il curriculum.</p>
                    </div>
                    <div class="text-muted small">
                        <span class="me-2"><i class="fa-solid fa-floppy-disk"></i> Bozza automatica</span>
                        <span><i class="fa-solid fa-file-pdf"></i> Genera PDF da "Pubblica"</span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo sanitize_output($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $curriculumId; ?>">

                    <section class="mb-5">
                        <h2 class="h5 mb-3">Informazioni principali</h2>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label" for="cliente_id">Cliente</label>
                                <select class="form-select" id="cliente_id" name="cliente_id" required>
                                    <option value="">Seleziona un cliente</option>
                                    <?php foreach ($clients as $client): ?>
                                        <?php
                                        $displayName = trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''));
                                        if ($displayName === '') {
                                            $displayName = $client['ragione_sociale'] ?? 'Cliente #' . (int) $client['id'];
                                        }
                                        ?>
                                        <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) $formData['cliente_id'] === (int) $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo sanitize_output($displayName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="titolo">Titolo curriculum</label>
                                <input class="form-control" id="titolo" name="titolo" type="text" value="<?php echo sanitize_output($formData['titolo']); ?>" placeholder="Es. Consulente commerciale senior" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="status">Stato</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo $formData['status'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="professional_summary">Profilo professionale</label>
                                <textarea class="form-control" id="professional_summary" name="professional_summary" rows="4" placeholder="Sintesi delle principali esperienze, obiettivi e punti di forza."><?php echo sanitize_output($formData['professional_summary']); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="mb-5">
                        <h2 class="h5 mb-3">Competenze e informazioni aggiuntive</h2>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label" for="key_competences">Competenze chiave</label>
                                <textarea class="form-control" id="key_competences" name="key_competences" rows="3" placeholder="Elenco sintetico delle principali competenze professionali."><?php echo sanitize_output($formData['key_competences']); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="digital_competences">Competenze digitali</label>
                                <textarea class="form-control" id="digital_competences" name="digital_competences" rows="3" placeholder="Strumenti digitali, software e certificazioni ICT."><?php echo sanitize_output($formData['digital_competences']); ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="driving_license">Patente</label>
                                <input class="form-control" id="driving_license" name="driving_license" type="text" value="<?php echo sanitize_output($formData['driving_license']); ?>" placeholder="Es. B, C, ADR">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="additional_information">Informazioni aggiuntive</label>
                                <textarea class="form-control" id="additional_information" name="additional_information" rows="3" placeholder="Disponibilità a trasferte, volontariato, hobby rilevanti."><?php echo sanitize_output($formData['additional_information']); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <?php
                    $renderExperiences = static function (array $entries) {
                        ob_start();
                        foreach ($entries as $index => $experience) {
                            ?>
                            <div class="border border-warning-subtle rounded-3 p-3 mb-3 section-item" data-index="<?php echo (int) $index; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h3 class="h6 mb-0">Esperienza professionale</h3>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-remove-section="experiences"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label">Ruolo</label>
                                        <input class="form-control" name="experiences[<?php echo (int) $index; ?>][role_title]" type="text" value="<?php echo sanitize_output($experience['role_title'] ?? ''); ?>" placeholder="Es. Responsabile vendite">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Datore di lavoro</label>
                                        <input class="form-control" name="experiences[<?php echo (int) $index; ?>][employer]" type="text" value="<?php echo sanitize_output($experience['employer'] ?? ''); ?>" placeholder="Azienda / Ente">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">In corso</label>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="experiences[<?php echo (int) $index; ?>][is_current]" value="1" <?php echo !empty($experience['is_current']) ? 'checked' : ''; ?>>
                                            <span class="form-check-label">Attivo</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Città</label>
                                        <input class="form-control" name="experiences[<?php echo (int) $index; ?>][city]" type="text" value="<?php echo sanitize_output($experience['city'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Paese</label>
                                        <input class="form-control" name="experiences[<?php echo (int) $index; ?>][country]" type="text" value="<?php echo sanitize_output($experience['country'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Data inizio</label>
                                        <input class="form-control" name="experiences[<?php echo (int) $index; ?>][start_date]" type="date" value="<?php echo sanitize_output($experience['start_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Data fine</label>
                                        <input class="form-control" name="experiences[<?php echo (int) $index; ?>][end_date]" type="date" value="<?php echo sanitize_output($experience['end_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Descrizione attività</label>
                                        <textarea class="form-control" name="experiences[<?php echo (int) $index; ?>][description]" rows="2" placeholder="Obiettivi raggiunti, responsabilità e risultati."><?php echo sanitize_output($experience['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                        return ob_get_clean();
                    };

                    $renderEducation = static function (array $entries) {
                        ob_start();
                        foreach ($entries as $index => $item) {
                            ?>
                            <div class="border border-warning-subtle rounded-3 p-3 mb-3 section-item" data-index="<?php echo (int) $index; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h3 class="h6 mb-0">Percorso formativo</h3>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-remove-section="education"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Percorso / Titolo</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][title]" type="text" value="<?php echo sanitize_output($item['title'] ?? ''); ?>" placeholder="Es. Laurea in Economia">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Istituto</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][institution]" type="text" value="<?php echo sanitize_output($item['institution'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Città</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][city]" type="text" value="<?php echo sanitize_output($item['city'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Paese</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][country]" type="text" value="<?php echo sanitize_output($item['country'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Inizio</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][start_date]" type="date" value="<?php echo sanitize_output($item['start_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Fine</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][end_date]" type="date" value="<?php echo sanitize_output($item['end_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Livello</label>
                                        <input class="form-control" name="education[<?php echo (int) $index; ?>][qualification_level]" type="text" value="<?php echo sanitize_output($item['qualification_level'] ?? ''); ?>" placeholder="Es. EQF 6">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Descrizione</label>
                                        <textarea class="form-control" name="education[<?php echo (int) $index; ?>][description]" rows="2" placeholder="Competenze acquisite, tesi, risultati."><?php echo sanitize_output($item['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                        return ob_get_clean();
                    };

                    $renderLanguages = static function (array $entries, array $levels) {
                        ob_start();
                        foreach ($entries as $index => $item) {
                            ?>
                            <div class="border border-warning-subtle rounded-3 p-3 mb-3 section-item" data-index="<?php echo (int) $index; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h3 class="h6 mb-0">Competenza linguistica</h3>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-remove-section="languages"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Lingua</label>
                                        <input class="form-control" name="languages[<?php echo (int) $index; ?>][language]" type="text" value="<?php echo sanitize_output($item['language'] ?? ''); ?>" placeholder="Es. Inglese">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Livello complessivo</label>
                                        <select class="form-select" name="languages[<?php echo (int) $index; ?>][overall_level]">
                                            <option value="">Seleziona</option>
                                            <?php foreach ($levels as $level): ?>
                                                <option value="<?php echo $level; ?>" <?php echo ($item['overall_level'] ?? '') === $level ? 'selected' : ''; ?>><?php echo $level; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Certificazione</label>
                                        <input class="form-control" name="languages[<?php echo (int) $index; ?>][certification]" type="text" value="<?php echo sanitize_output($item['certification'] ?? ''); ?>" placeholder="Es. IELTS 7.5">
                                    </div>
                                    <?php
                                    $skills = ['listening' => 'Ascolto', 'reading' => 'Lettura', 'interaction' => 'Interazione', 'production' => 'Produzione', 'writing' => 'Scrittura'];
                                    foreach ($skills as $key => $label): ?>
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $label; ?></label>
                                            <select class="form-select" name="languages[<?php echo (int) $index; ?>][<?php echo $key; ?>]">
                                                <option value="">Seleziona</option>
                                                <?php foreach ($levels as $level): ?>
                                                    <option value="<?php echo $level; ?>" <?php echo ($item[$key] ?? '') === $level ? 'selected' : ''; ?>><?php echo $level; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php
                        }
                        return ob_get_clean();
                    };

                    $renderSkills = static function (array $entries, array $levelOptions, array $categories) {
                        ob_start();
                        foreach ($entries as $index => $item) {
                            ?>
                            <div class="border border-warning-subtle rounded-3 p-3 mb-3 section-item" data-index="<?php echo (int) $index; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h3 class="h6 mb-0">Competenza</h3>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-remove-section="skills"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Categoria</label>
                                        <select class="form-select" name="skills[<?php echo (int) $index; ?>][category]">
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category; ?>" <?php echo ($item['category'] ?? '') === $category ? 'selected' : ''; ?>><?php echo $category; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Competenza</label>
                                        <input class="form-control" name="skills[<?php echo (int) $index; ?>][skill]" type="text" value="<?php echo sanitize_output($item['skill'] ?? ''); ?>" placeholder="Es. Negoziazione">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Livello</label>
                                        <select class="form-select" name="skills[<?php echo (int) $index; ?>][level]">
                                            <option value="">Seleziona</option>
                                            <?php foreach ($levelOptions as $level): ?>
                                                <option value="<?php echo $level; ?>" <?php echo ($item['level'] ?? '') === $level ? 'selected' : ''; ?>><?php echo $level; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Descrizione</label>
                                        <textarea class="form-control" name="skills[<?php echo (int) $index; ?>][description]" rows="2" placeholder="Contestualizza la competenza con esempi concreti."><?php echo sanitize_output($item['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                        return ob_get_clean();
                    };
                    ?>

                    <section class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Esperienza professionale</h2>
                            <button class="btn btn-sm btn-outline-warning" type="button" data-add-section="experiences"><i class="fa-solid fa-circle-plus me-2"></i>Aggiungi esperienza</button>
                        </div>
                        <div id="experiences-container" class="section-container" data-section="experiences">
                            <?php
                            echo $renderExperiences($sectionData['experiences'] ?: [[]]);
                            ?>
                        </div>
                    </section>

                    <section class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Istruzione e formazione</h2>
                            <button class="btn btn-sm btn-outline-warning" type="button" data-add-section="education"><i class="fa-solid fa-circle-plus me-2"></i>Aggiungi formazione</button>
                        </div>
                        <div id="education-container" class="section-container" data-section="education">
                            <?php
                            echo $renderEducation($sectionData['education'] ?: [[]]);
                            ?>
                        </div>
                    </section>

                    <section class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Competenze linguistiche</h2>
                            <button class="btn btn-sm btn-outline-warning" type="button" data-add-section="languages"><i class="fa-solid fa-circle-plus me-2"></i>Aggiungi lingua</button>
                        </div>
                        <div id="languages-container" class="section-container" data-section="languages">
                            <?php
                            echo $renderLanguages($sectionData['languages'] ?: [[]], $languageLevels);
                            ?>
                        </div>
                    </section>

                    <section class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Competenze personali</h2>
                            <button class="btn btn-sm btn-outline-warning" type="button" data-add-section="skills"><i class="fa-solid fa-circle-plus me-2"></i>Aggiungi competenza</button>
                        </div>
                        <div id="skills-container" class="section-container" data-section="skills">
                            <?php
                            echo $renderSkills($sectionData['skills'] ?: [[]], $skillLevelOptions, $skillCategories);
                            ?>
                        </div>
                    </section>

                    <div class="d-flex justify-content-end gap-3">
                        <a class="btn btn-outline-secondary" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit">Salva curriculum</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<template id="template-experiences">
    <?php echo $renderExperiences([['role_title' => '', 'employer' => '', 'city' => '', 'country' => '', 'start_date' => '', 'end_date' => '', 'is_current' => 0, 'description' => '']]); ?>
</template>
<template id="template-education">
    <?php echo $renderEducation([['title' => '', 'institution' => '', 'city' => '', 'country' => '', 'start_date' => '', 'end_date' => '', 'qualification_level' => '', 'description' => '']]); ?>
</template>
<template id="template-languages">
    <?php echo $renderLanguages([['language' => '', 'overall_level' => '', 'certification' => '', 'listening' => '', 'reading' => '', 'interaction' => '', 'production' => '', 'writing' => '']], $languageLevels); ?>
</template>
<template id="template-skills">
    <?php echo $renderSkills([['category' => $skillCategories[0] ?? 'Altro', 'skill' => '', 'level' => '', 'description' => '']], $skillLevelOptions, $skillCategories); ?>
</template>

<script>
    (function () {
        const sectionContainers = document.querySelectorAll('.section-container');
        const templates = {
            experiences: document.getElementById('template-experiences'),
            education: document.getElementById('template-education'),
            languages: document.getElementById('template-languages'),
            skills: document.getElementById('template-skills')
        };

        const regenerateIndexes = (container) => {
            const items = container.querySelectorAll('.section-item');
            items.forEach((item, index) => {
                item.dataset.index = String(index);
                item.querySelectorAll('input, select, textarea').forEach((input) => {
                    const name = input.getAttribute('name');
                    if (!name) {
                        return;
                    }
                    const updated = name.replace(/\[(\d+)\]/, '[' + index + ']');
                    if (updated !== name) {
                        input.setAttribute('name', updated);
                    }
                });
            });
        };

        sectionContainers.forEach((container) => {
            container.addEventListener('click', (event) => {
                const target = event.target.closest('[data-remove-section]');
                if (!target) {
                    return;
                }
                const section = target.getAttribute('data-remove-section');
                if (!section) {
                    return;
                }
                const item = target.closest('.section-item');
                if (item && container.contains(item)) {
                    item.remove();
                    regenerateIndexes(container);
                }
            });
        });

        document.querySelectorAll('[data-add-section]').forEach((button) => {
            button.addEventListener('click', () => {
                const section = button.getAttribute('data-add-section');
                if (!section || !templates[section]) {
                    return;
                }
                const container = document.querySelector('[data-section="' + section + '"]');
                if (!container) {
                    return;
                }
                const fragment = templates[section].content.cloneNode(true);
                const tempDiv = document.createElement('div');
                tempDiv.appendChild(fragment);
                const newItem = tempDiv.querySelector('.section-item');
                if (!newItem) {
                    return;
                }
                container.appendChild(newItem);
                regenerateIndexes(container);
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
