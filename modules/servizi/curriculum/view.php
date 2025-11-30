<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    add_flash('warning', 'Curriculum non trovato.');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT cv.*, c.nome, c.cognome, c.email, c.telefono, c.ragione_sociale
    FROM curriculum cv
    LEFT JOIN clienti c ON c.id = cv.cliente_id
    WHERE cv.id = :id');
$stmt->execute([':id' => $id]);
$curriculum = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$curriculum) {
    add_flash('warning', 'Curriculum non trovato.');
    header('Location: index.php');
    exit;
}

$sections = [];

$sections['experiences'] = $pdo->prepare('SELECT * FROM curriculum_experiences WHERE curriculum_id = :id ORDER BY ordering ASC, id ASC');
$sections['experiences']->execute([':id' => $id]);
$experiences = $sections['experiences']->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sections['education'] = $pdo->prepare('SELECT * FROM curriculum_education WHERE curriculum_id = :id ORDER BY ordering ASC, id ASC');
$sections['education']->execute([':id' => $id]);
$education = $sections['education']->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sections['languages'] = $pdo->prepare('SELECT * FROM curriculum_languages WHERE curriculum_id = :id ORDER BY language ASC, id ASC');
$sections['languages']->execute([':id' => $id]);
$languages = $sections['languages']->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sections['skills'] = $pdo->prepare('SELECT * FROM curriculum_skills WHERE curriculum_id = :id ORDER BY ordering ASC, id ASC');
$sections['skills']->execute([':id' => $id]);
$skills = $sections['skills']->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Dettaglio curriculum';
$clientDisplay = trim(($curriculum['cognome'] ?? '') . ' ' . ($curriculum['nome'] ?? ''));
if ($clientDisplay === '') {
    $clientDisplay = $curriculum['ragione_sociale'] ?? 'Cliente #' . (int) $curriculum['cliente_id'];
}

$summaryBlocks = array_filter([
    $curriculum['key_competences'] ? 'Competenze chiave: ' . (string) $curriculum['key_competences'] : null,
    $curriculum['digital_competences'] ? 'Competenze digitali: ' . (string) $curriculum['digital_competences'] : null,
    $curriculum['driving_license'] ? 'Patente: ' . (string) $curriculum['driving_license'] : null,
    $curriculum['additional_information'] ? (string) $curriculum['additional_information'] : null,
]);

$skillsByCategory = [];
foreach ($skills as $skill) {
    $category = trim((string) ($skill['category'] ?? ''));
    if ($category === '') {
        $category = 'Competenze trasversali';
    }
    $skillsByCategory[$category][] = $skill;
}
if ($skillsByCategory) {
    ksort($skillsByCategory);
}

$cefrScoreMap = [
    'Madrelingua' => 100,
    'C2' => 95,
    'C1' => 85,
    'B2' => 75,
    'B1' => 60,
    'A2' => 45,
    'A1' => 30,
];

$firstName = trim((string) ($curriculum['nome'] ?? ''));
$lastName = trim((string) ($curriculum['cognome'] ?? ''));
$initialsParts = [];
if ($firstName !== '') {
    $initialsParts[] = strtoupper(function_exists('mb_substr') ? (string) mb_substr($firstName, 0, 1) : substr($firstName, 0, 1));
}
if ($lastName !== '') {
    $initialsParts[] = strtoupper(function_exists('mb_substr') ? (string) mb_substr($lastName, 0, 1) : substr($lastName, 0, 1));
}
$photoInitials = $initialsParts ? implode('', array_slice($initialsParts, 0, 2)) : 'CV';

$truncate = static function (string $value, int $length = 180): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $length = max($length, 20);
    if (function_exists('mb_strlen')) {
        if (mb_strlen($value) <= $length) {
            return $value;
        }
    return rtrim((string) mb_substr($value, 0, $length - 3)) . '...';
    }
    if (strlen($value) <= $length) {
        return $value;
    }
    return rtrim(substr($value, 0, $length - 3)) . '...';
};

$heroSummary = $truncate((string) ($curriculum['professional_summary'] ?? '')); 
if ($heroSummary === '') {
    $heroSummary = $truncate((string) ($curriculum['key_competences'] ?? ''));
}

$statusClass = 'bg-warning text-dark';
if (($curriculum['status'] ?? '') === 'Pubblicato') {
    $statusClass = 'bg-success';
} elseif (($curriculum['status'] ?? '') === 'Archiviato') {
    $statusClass = 'bg-secondary';
}

$strengths = array_filter([
    [
        'icon' => 'fa-solid fa-star',
        'label' => 'Competenze chiave',
        'value' => trim((string) ($curriculum['key_competences'] ?? '')),
    ],
    [
        'icon' => 'fa-solid fa-microchip',
        'label' => 'Competenze digitali',
        'value' => trim((string) ($curriculum['digital_competences'] ?? '')),
    ],
    [
        'icon' => 'fa-solid fa-id-card',
        'label' => 'Patenti',
        'value' => trim((string) ($curriculum['driving_license'] ?? '')),
    ],
    [
        'icon' => 'fa-solid fa-note-sticky',
        'label' => 'Informazioni aggiuntive',
        'value' => trim((string) ($curriculum['additional_information'] ?? '')),
    ],
], static function (array $item): bool {
    return $item['value'] !== '';
});

$languageAbilities = [
    'listening' => 'Ascolto',
    'reading' => 'Lettura',
    'interaction' => 'Interazione',
    'production' => 'Produzione',
    'writing' => 'Scrittura',
];

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <style>
            .cv-hero {
                border: 0;
                background: linear-gradient(135deg, rgba(255, 193, 7, 0.15), rgba(23, 22, 22, 0.4));
            }

            .cv-photo {
                width: 108px;
                height: 108px;
                border-radius: 18px;
                border: 2px solid rgba(255, 193, 7, 0.4);
                background: rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2rem;
                font-weight: 600;
                color: #ffc107;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            }

            .cv-hero-text {
                max-width: 640px;
            }

            .cv-timeline {
                position: relative;
                border-left: 2px solid rgba(255, 193, 7, 0.45);
                padding-left: 1.5rem;
            }

            .cv-timeline-item {
                position: relative;
                margin-bottom: 1.75rem;
            }

            .cv-timeline-item:last-child {
                margin-bottom: 0;
            }

            .cv-timeline-item::before {
                content: '';
                position: absolute;
                left: -1.7rem;
                top: 0.35rem;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background: #ffc107;
                box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.2);
            }

            .cv-info-block {
                border-radius: 0.85rem;
                border: 1px solid rgba(255, 193, 7, 0.25);
                background-color: rgba(255, 193, 7, 0.05);
                padding: 1.1rem 1.25rem;
                height: 100%;
            }

            .cv-info-block h3 {
                font-size: 0.95rem;
                letter-spacing: 0.08em;
            }

            .cv-language {
                border-radius: 0.85rem;
                border: 1px solid rgba(255, 193, 7, 0.2);
                background-color: rgba(255, 193, 7, 0.06);
                padding: 1.1rem 1.25rem;
                height: 100%;
            }

            .cv-language .progress {
                height: 6px;
                border-radius: 999px;
                background-color: rgba(255, 193, 7, 0.12);
            }

            .cv-language .progress-bar {
                background-color: #ffc107;
            }

            .cv-skill-pill {
                border-radius: 999px;
                border: 1px solid rgba(255, 193, 7, 0.3);
                background-color: rgba(255, 193, 7, 0.05);
                padding: 0.55rem 0.85rem;
                margin-bottom: 0.75rem;
            }
        </style>

        <div class="card cv-hero shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-4 align-items-center">
                    <div class="col-md-auto">
                        <div class="cv-photo text-uppercase" role="img" aria-label="Foto profilo">
                            <span><?php echo sanitize_output($photoInitials); ?></span>
                        </div>
                    </div>
                    <div class="col">
                        <h1 class="h3 mb-1"><?php echo sanitize_output($curriculum['titolo'] ?? 'Curriculum'); ?></h1>
                        <div class="text-muted small d-flex flex-wrap gap-3">
                            <span><i class="fa-solid fa-user me-1"></i><?php echo sanitize_output($clientDisplay); ?></span>
                            <span><i class="fa-solid fa-clock me-1"></i>Aggiornato <?php echo sanitize_output(format_datetime_locale($curriculum['updated_at'] ?? '')); ?></span>
                            <span><i class="fa-solid fa-calendar-plus me-1"></i>Creato <?php echo sanitize_output(format_datetime_locale($curriculum['created_at'] ?? '')); ?></span>
                        </div>
                        <?php if ($heroSummary !== ''): ?>
                            <p class="text-muted mt-3 mb-0 cv-hero-text"><?php echo nl2br(sanitize_output($heroSummary)); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4 col-lg-3">
                        <div class="d-flex flex-column gap-2 align-items-md-end">
                            <span class="badge ag-badge text-uppercase text-white <?php echo $statusClass; ?>"><?php echo sanitize_output($curriculum['status']); ?></span>
                            <div class="small text-muted text-md-end">
                                <?php $lastGenerated = $curriculum['last_generated_at'] ? format_datetime_locale($curriculum['last_generated_at']) : 'Mai'; ?>
                                <span class="d-block"><i class="fa-solid fa-file-pdf me-1"></i>Ultima generazione: <?php echo sanitize_output($lastGenerated); ?></span>
                                <?php if (!empty($curriculum['generated_file'])): ?>
                                    <a class="btn btn-sm btn-outline-warning mt-2" href="../../../<?php echo sanitize_output($curriculum['generated_file']); ?>" target="_blank" rel="noopener">Apri PDF</a>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-md-end mt-2">
                                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left-long me-2"></i>Lista</a>
                                <a class="btn btn-outline-warning" href="wizard.php?id=<?php echo (int) $id; ?>"><i class="fa-solid fa-pen me-2"></i>Modifica</a>
                                <form method="post" action="publish.php" class="d-inline">
                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                                    <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-file-pdf me-2"></i>Genera PDF</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card ag-card shadow-sm mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Profilo professionale</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($curriculum['professional_summary'])): ?>
                            <p class="mb-0 fs-6"><?php echo nl2br(sanitize_output($curriculum['professional_summary'])); ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">Profilo non ancora compilato.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Punti di forza</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($strengths): ?>
                            <div class="row g-3">
                                <?php foreach ($strengths as $strength): ?>
                                    <div class="col-12 col-md-6">
                                        <div class="cv-info-block">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="text-warning"><i class="<?php echo sanitize_output($strength['icon']); ?>"></i></span>
                                                <h3 class="mb-0 text-uppercase small text-muted"><?php echo sanitize_output($strength['label']); ?></h3>
                                            </div>
                                            <p class="mb-0"><?php echo nl2br(sanitize_output($strength['value'])); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Compila le competenze e le informazioni aggiuntive dal wizard per arricchire il profilo.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card ag-card shadow-sm h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Contatti</h2>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-4">
                            <?php if (!empty($curriculum['email'])): ?>
                                <li class="mb-3">
                                    <span class="text-warning"><i class="fa-solid fa-envelope me-2"></i></span>
                                    <a class="link-warning text-decoration-none" href="mailto:<?php echo sanitize_output($curriculum['email']); ?>"><?php echo sanitize_output($curriculum['email']); ?></a>
                                </li>
                            <?php endif; ?>
                            <?php if (!empty($curriculum['telefono'])): ?>
                                <li class="mb-3">
                                    <span class="text-warning"><i class="fa-solid fa-phone me-2"></i></span>
                                    <a class="link-warning text-decoration-none" href="tel:<?php echo sanitize_output($curriculum['telefono']); ?>"><?php echo sanitize_output($curriculum['telefono']); ?></a>
                                </li>
                            <?php endif; ?>
                            <?php if (!empty($curriculum['ragione_sociale'])): ?>
                                <li class="mb-3">
                                    <span class="text-warning"><i class="fa-solid fa-building me-2"></i></span>
                                    <?php echo sanitize_output($curriculum['ragione_sociale']); ?>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <?php if ($summaryBlocks): ?>
                            <div class="small text-muted border-top pt-3">
                                <?php foreach ($summaryBlocks as $block): ?>
                                    <p class="mb-2"><?php echo nl2br(sanitize_output($block)); ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card shadow-sm mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Esperienza professionale</h2>
                <a class="btn btn-sm btn-outline-warning" href="wizard.php?id=<?php echo (int) $id; ?>#experiences-container"><i class="fa-solid fa-circle-plus me-2"></i>Modifica</a>
            </div>
            <div class="card-body">
                <?php if ($experiences): ?>
                    <div class="cv-timeline">
                        <?php foreach ($experiences as $experience): ?>
                            <div class="cv-timeline-item">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                                    <h3 class="h6 mb-0"><?php echo sanitize_output(trim(($experience['role_title'] ?? '') . ' — ' . ($experience['employer'] ?? ''))); ?></h3>
                                    <?php if (!empty($experience['city']) || !empty($experience['country'])): ?>
                                        <span class="badge bg-warning-subtle text-dark text-uppercase small"><?php echo sanitize_output(trim(($experience['city'] ?? '') . ' ' . ($experience['country'] ?? ''))); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mb-2">
                                    <?php
                                    $start = format_date_locale($experience['start_date'] ?? null);
                                    $end = (int) ($experience['is_current'] ?? 0) === 1 ? 'Presente' : format_date_locale($experience['end_date'] ?? null);
                                    $period = trim(($start ?: '') . ' — ' . ($end ?: ''));
                                    ?>
                                    <i class="fa-solid fa-calendar-day me-1"></i><?php echo sanitize_output($period !== '' ? $period : 'Periodo non indicato'); ?>
                                </div>
                                <?php if (!empty($experience['description'])): ?>
                                    <p class="mb-0"><?php echo nl2br(sanitize_output($experience['description'])); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessuna esperienza registrata.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card shadow-sm mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Istruzione e formazione</h2>
                <a class="btn btn-sm btn-outline-warning" href="wizard.php?id=<?php echo (int) $id; ?>#education-container"><i class="fa-solid fa-circle-plus me-2"></i>Modifica</a>
            </div>
            <div class="card-body">
                <?php if ($education): ?>
                    <div class="cv-timeline">
                        <?php foreach ($education as $item): ?>
                            <div class="cv-timeline-item">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                                    <h3 class="h6 mb-0"><?php echo sanitize_output(trim(($item['title'] ?? '') . ' — ' . ($item['institution'] ?? ''))); ?></h3>
                                    <?php if (!empty($item['city']) || !empty($item['country'])): ?>
                                        <span class="badge bg-warning-subtle text-dark text-uppercase small"><?php echo sanitize_output(trim(($item['city'] ?? '') . ' ' . ($item['country'] ?? ''))); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mb-2">
                                    <?php
                                    $start = format_date_locale($item['start_date'] ?? null);
                                    $end = format_date_locale($item['end_date'] ?? null);
                                    $period = trim(($start ?: '') . ' — ' . ($end ?: ''));
                                    ?>
                                    <i class="fa-solid fa-calendar-day me-1"></i><?php echo sanitize_output($period !== '' ? $period : 'Periodo non indicato'); ?>
                                </div>
                                <?php if (!empty($item['qualification_level'])): ?>
                                    <p class="small text-muted mb-1"><i class="fa-solid fa-layer-group me-1"></i>Livello: <?php echo sanitize_output($item['qualification_level']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($item['description'])): ?>
                                    <p class="mb-0"><?php echo nl2br(sanitize_output($item['description'])); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessun percorso formativo registrato.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card shadow-sm mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Competenze linguistiche</h2>
                <a class="btn btn-sm btn-outline-warning" href="wizard.php?id=<?php echo (int) $id; ?>#languages-container"><i class="fa-solid fa-circle-plus me-2"></i>Modifica</a>
            </div>
            <div class="card-body">
                <?php if ($languages): ?>
                    <div class="row g-3">
                        <?php foreach ($languages as $language): ?>
                            <?php
                            $overallLevel = trim((string) ($language['overall_level'] ?? ''));
                            $progressValue = $overallLevel !== '' && isset($cefrScoreMap[$overallLevel]) ? (int) $cefrScoreMap[$overallLevel] : 0;
                            ?>
                            <div class="col-md-6">
                                <div class="cv-language">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h3 class="h6 mb-0"><?php echo sanitize_output($language['language']); ?></h3>
                                        <?php if ($overallLevel !== ''): ?>
                                            <span class="badge bg-warning text-dark text-uppercase"><?php echo sanitize_output($overallLevel); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($progressValue > 0): ?>
                                        <div class="progress mb-3" role="progressbar" aria-valuenow="<?php echo $progressValue; ?>" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar" style="width: <?php echo $progressValue; ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                    <ul class="list-inline small text-muted mb-0">
                                        <?php foreach ($languageAbilities as $key => $label): ?>
                                            <?php if (empty($language[$key])) {
                                                continue;
                                            } ?>
                                            <li class="list-inline-item me-3"><strong><?php echo $label; ?>:</strong> <?php echo sanitize_output($language[$key]); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if (!empty($language['certification'])): ?>
                                        <p class="small text-muted mt-3 mb-0"><i class="fa-solid fa-certificate me-1"></i><?php echo sanitize_output($language['certification']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Competenze linguistiche non inserite.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card shadow-sm mb-5">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Competenze personali</h2>
                <a class="btn btn-sm btn-outline-warning" href="wizard.php?id=<?php echo (int) $id; ?>#skills-container"><i class="fa-solid fa-circle-plus me-2"></i>Modifica</a>
            </div>
            <div class="card-body">
                <?php if ($skillsByCategory): ?>
                    <div class="row g-3">
                        <?php foreach ($skillsByCategory as $category => $skillItems): ?>
                            <div class="col-md-6">
                                <div class="cv-info-block h-100">
                                    <h3 class="mb-3 text-uppercase small text-muted"><?php echo sanitize_output($category); ?></h3>
                                    <?php foreach ($skillItems as $skill): ?>
                                        <div class="cv-skill-pill">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="fw-semibold text-warning"><?php echo sanitize_output($skill['skill']); ?></span>
                                                <?php if (!empty($skill['level'])): ?>
                                                    <span class="badge bg-warning-subtle text-dark text-uppercase"><?php echo sanitize_output($skill['level']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($skill['description'])): ?>
                                                <p class="small text-muted mb-0 mt-2"><?php echo nl2br(sanitize_output($skill['description'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessuna competenza registrata.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
