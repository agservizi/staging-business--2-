<?php
declare(strict_types=1);

namespace App\Services\Curriculum;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

class CurriculumBuilderService
{
    private PDO $pdo;
    private string $storagePath;

    public function __construct(PDO $pdo, string $rootPath)
    {
        $this->pdo = $pdo;
        $this->storagePath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'curriculum';
    }

    public function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storagePath) && !mkdir($concurrentDirectory = $this->storagePath, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Impossibile creare la directory per i curriculum.');
        }
    }

    public function buildEuropass(int $curriculumId): array
    {
        $curriculum = $this->loadCurriculum($curriculumId);
        $sections = $this->loadSections($curriculumId);

        $this->ensureStorageDirectory();

        $fileName = sprintf('cv_europass_%s.pdf', $curriculumId . '_' . (new DateTimeImmutable())->format('YmdHis'));
        $fullPath = $this->storagePath . DIRECTORY_SEPARATOR . $fileName;

        $pdf = $this->createPdfInstance();
        $pdf->SetMargins(16.0, 20.0, 16.0);
        $pdf->SetAutoPageBreak(true, 20.0);
        $pdf->SetDisplayMode('fullwidth');

    $pdf->WriteHTML('<style>' . $this->buildStylesheet() . '</style>' . $this->buildDocumentHtml($curriculum, $sections));

        $pdf->Output($fullPath, 'F');

        return [
            'relative_path' => 'assets/uploads/curriculum/' . $fileName,
            'full_path' => $fullPath,
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    private function loadCurriculum(int $curriculumId): array
    {
        $stmt = $this->pdo->prepare('SELECT cv.*, c.nome, c.cognome, c.email, c.telefono, c.indirizzo, c.ragione_sociale
            FROM curriculum cv
            LEFT JOIN clienti c ON c.id = cv.cliente_id
            WHERE cv.id = :id');
        $stmt->execute([':id' => $curriculumId]);
        $curriculum = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$curriculum) {
            throw new RuntimeException('Curriculum non trovato.');
        }

        return $curriculum;
    }

    /**
     * @return array{experiences:array<int,array<string,mixed>>,education:array<int,array<string,mixed>>,languages:array<int,array<string,mixed>>,skills:array<int,array<string,mixed>>}
     */
    private function loadSections(int $curriculumId): array
    {
        $sections = [
            'experiences' => $this->fetchSection('curriculum_experiences', $curriculumId),
            'education' => $this->fetchSection('curriculum_education', $curriculumId),
            'languages' => $this->fetchSection('curriculum_languages', $curriculumId),
            'skills' => $this->fetchSection('curriculum_skills', $curriculumId),
        ];

        return $sections;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchSection(string $table, int $curriculumId): array
    {
        $column = $table === 'curriculum_languages' ? 'language' : 'ordering';
        $order = $table === 'curriculum_languages' ? 'ASC' : 'ASC';
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE curriculum_id = :id ORDER BY {$column} {$order}");
        $stmt->execute([':id' => $curriculumId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildStylesheet(): string
    {
        return <<<CSS
body {
    font-family: 'DejaVu Sans', sans-serif;
    background: #ffffff;
    color: #1f232b;
    font-size: 10.5pt;
    line-height: 1.55;
}

.cv-container {
    width: 100%;
}

.cv-header {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 28pt;
    border-bottom: 1pt solid #d9dce2;
}

.cv-header td {
    padding: 0 0 14pt 0;
    vertical-align: bottom;
}

.cv-photo-cell {
    width: 120pt;
    padding-right: 24pt;
}

.cv-photo {
    width: 115pt;
    height: 145pt;
    border: 1.2pt solid #b8bcc4;
    background: #f6f7f9;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26pt;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #2f333a;
}

.cv-header-details {
    padding-right: 0;
}

.cv-name {
    margin: 0;
    font-size: 22pt;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: #1f232b;
}

.cv-role {
    margin-top: 4pt;
    font-size: 11pt;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #4a4f57;
}

.cv-summary {
    margin-top: 12pt;
    font-size: 10pt;
    color: #3d4149;
}

.cv-meta-line {
    margin-top: 12pt;
    font-size: 8.5pt;
    color: #5d626b;
}

.cv-meta-line span {
    display: inline-block;
    margin-right: 14pt;
}

.cv-body {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.cv-body td {
    vertical-align: top;
    padding-top: 0;
}

.cv-sidebar {
    width: 33%;
    padding-right: 26pt;
    border-right: 1pt solid #d9dce2;
}

.cv-main {
    padding-left: 26pt;
}

.cv-side-group {
    margin-bottom: 22pt;
    page-break-inside: avoid;
}

.cv-side-group:last-child {
    margin-bottom: 0;
}

.cv-side-group h3 {
    margin: 0 0 8pt 0;
    font-size: 10pt;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #3b3f46;
}

.cv-contact-list,
.cv-strength-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.cv-contact-list li {
    margin-bottom: 8pt;
    font-size: 9.5pt;
    color: #2f333a;
}

.cv-contact-label {
    display: block;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 1pt;
    color: #3b3f46;
}

.cv-skill-chip {
    font-size: 9pt;
    font-weight: 600;
    color: #2f333a;
    margin-bottom: 6pt;
}

.cv-skill-chip-note {
    font-weight: 400;
    color: #656a72;
    margin-left: 4pt;
}

.cv-side-note {
    font-size: 8.5pt;
    color: #656a72;
    margin: 4pt 0 8pt 0;
}

.cv-language-item {
    margin-bottom: 12pt;
}

.cv-language-item:last-child {
    margin-bottom: 0;
}

.cv-language-item h4 {
    margin: 0 0 4pt 0;
    font-size: 9.5pt;
    color: #2f333a;
}

.cv-language-level {
    font-size: 8.5pt;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #2f333a;
}

.cv-language-abilities {
    font-size: 8.5pt;
    color: #656a72;
    margin-top: 4pt;
}

.cv-strength-list li {
    margin-bottom: 10pt;
    font-size: 9pt;
    color: #2f333a;
}

.cv-strength-list li strong {
    display: block;
    margin-bottom: 2pt;
}

.cv-section {
    margin-bottom: 28pt;
    page-break-inside: avoid;
}

.cv-section:last-child {
    margin-bottom: 0;
}

.cv-section h2 {
    margin: 0 0 12pt 0;
    font-size: 12pt;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #1f232b;
}

.cv-section p {
    margin: 0 0 10pt 0;
    color: #2f333a;
}

.cv-entry {
    margin-bottom: 18pt;
}

.cv-entry:last-child {
    margin-bottom: 0;
}

.cv-entry-title {
    margin: 0 0 4pt 0;
    font-size: 11pt;
    font-weight: 600;
    color: #1f232b;
}

.cv-entry-meta {
    font-size: 9pt;
    color: #5d626b;
    margin-bottom: 6pt;
}


.cv-section-subheading {
    font-size: 9pt;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #3b3f46;
    margin: 0 0 6pt 0;
}

.cv-list {
    padding-left: 12pt;
    margin: 0;
}

.cv-list li {
    margin-bottom: 12pt;
    color: #2f333a;
}

.cv-skill-line {
    display: flex;
    flex-wrap: wrap;
    gap: 6pt;
    font-size: 9.5pt;
    font-weight: 600;
    color: #1f232b;
}

.cv-skill-line .cv-skill-name {
    font-weight: 600;
}

.cv-skill-line .cv-skill-level {
    font-weight: 500;
    color: #5d626b;
}

.cv-subtext {
    font-size: 8.5pt;
    color: #656a72;
    margin-top: 6pt;
}

.cv-skill-description {
    margin-top: 6pt;
}

.cv-footer {
    margin-top: 32pt;
    padding-top: 12pt;
    border-top: 1pt solid #d9dce2;
    font-size: 8.5pt;
    color: #5d626b;
    text-align: right;
}

@page {
    margin: 12mm 14mm;
}
CSS;
    }

    private function buildDocumentHtml(array $curriculum, array $sections): string
    {
        $experiences = $sections['experiences'] ?? [];
        $education = $sections['education'] ?? [];
        $languages = $sections['languages'] ?? [];
        $skills = $sections['skills'] ?? [];

        $summaryBlocks = array_filter([
            !empty($curriculum['key_competences']) ? 'Competenze chiave: ' . (string) $curriculum['key_competences'] : null,
            !empty($curriculum['digital_competences']) ? 'Competenze digitali: ' . (string) $curriculum['digital_competences'] : null,
            !empty($curriculum['driving_license']) ? 'Patenti: ' . (string) $curriculum['driving_license'] : null,
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

        $strengths = array_filter([
            [
                'label' => 'Competenze chiave',
                'value' => trim((string) ($curriculum['key_competences'] ?? '')),
            ],
            [
                'label' => 'Competenze digitali',
                'value' => trim((string) ($curriculum['digital_competences'] ?? '')),
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

        $cefrScoreMap = $this->cefrScores();

        $firstName = trim((string) ($curriculum['nome'] ?? ''));
        $lastName = trim((string) ($curriculum['cognome'] ?? ''));
        $initialsParts = [];
        if ($firstName !== '') {
            $initialsParts[] = strtoupper($this->utf8Substring($firstName, 0, 1));
        }
        if ($lastName !== '') {
            $initialsParts[] = strtoupper($this->utf8Substring($lastName, 0, 1));
        }
        $photoInitials = $initialsParts ? implode('', array_slice($initialsParts, 0, 2)) : 'CV';

        $summarySource = '';
        if (!empty($curriculum['professional_summary'])) {
            $summarySource = trim((string) $curriculum['professional_summary']);
        } elseif (!empty($curriculum['key_competences'])) {
            $summarySource = trim((string) $curriculum['key_competences']);
        }
        $heroSummary = $summarySource !== '' ? $this->truncate($summarySource, 360) : '';

        $status = (string) ($curriculum['status'] ?? 'Bozza');

        $clientDisplay = trim(($curriculum['cognome'] ?? '') . ' ' . ($curriculum['nome'] ?? ''));
        if ($clientDisplay === '' && !empty($curriculum['ragione_sociale'])) {
            $clientDisplay = (string) $curriculum['ragione_sociale'];
        }
        if ($clientDisplay === '') {
            $clientId = isset($curriculum['cliente_id']) ? (int) $curriculum['cliente_id'] : 0;
            $clientDisplay = 'Cliente #' . ($clientId > 0 ? $clientId : 'N/D');
        }

        $createdAtText = $this->formatDateTime($curriculum['created_at'] ?? null, 'd/m/Y');
        $updatedAtText = $this->formatDateTime($curriculum['updated_at'] ?? null, 'd/m/Y H:i');
        $generatedAtText = $this->formatDateTime($curriculum['last_generated_at'] ?? null, 'd/m/Y H:i', 'Mai');

        $contactItems = [];
        if (!empty($curriculum['indirizzo'])) {
            $contactItems[] = ['label' => 'Indirizzo', 'value' => (string) $curriculum['indirizzo']];
        }
        if (!empty($curriculum['telefono'])) {
            $contactItems[] = ['label' => 'Telefono', 'value' => (string) $curriculum['telefono']];
        }
        if (!empty($curriculum['email'])) {
            $contactItems[] = ['label' => 'E-mail', 'value' => (string) $curriculum['email']];
        }
        if (!empty($curriculum['ragione_sociale'])) {
            $contactItems[] = ['label' => 'Cliente', 'value' => (string) $curriculum['ragione_sociale']];
        }

        $skillsFlat = [];
        foreach ($skillsByCategory as $category => $skillItems) {
            foreach ($skillItems as $skill) {
                $name = trim((string) ($skill['skill'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $level = trim((string) ($skill['level'] ?? ''));
                $description = trim((string) ($skill['description'] ?? ''));
                $skillsFlat[] = [
                    'name' => $name,
                    'level' => $level,
                    'description' => $description,
                    'percent' => $this->mapSkillLevelToPercent($level, $description),
                ];
            }
        }

        $skillsHighlights = array_slice($skillsFlat, 0, 6);

        $certificationItems = [];
        if (!empty($curriculum['driving_license'])) {
            $certificationItems[] = 'Patenti: ' . (string) $curriculum['driving_license'];
        }
        foreach ($languages as $language) {
            if (!empty($language['certification']) && !empty($language['language'])) {
                $certificationItems[] = sprintf('Lingua %s — %s', (string) $language['language'], (string) $language['certification']);
            }
        }

        $escape = fn (?string $value): string => $this->escape($value);

        ob_start();
        ?>
        <div class="cv-container">
            <table class="cv-header">
                <tr>
                    <td class="cv-photo-cell">
                        <div class="cv-photo"><span><?php echo $escape($photoInitials); ?></span></div>
                    </td>
                    <td class="cv-header-details">
                        <div class="cv-name"><?php echo $escape($clientDisplay); ?></div>
                        <div class="cv-role"><?php echo $escape(!empty($curriculum['titolo']) ? (string) $curriculum['titolo'] : 'Curriculum Vitae Europass'); ?></div>
                        <?php if ($heroSummary !== ''): ?>
                            <p class="cv-summary"><?php echo nl2br($escape($heroSummary)); ?></p>
                        <?php endif; ?>
                        <div class="cv-meta-line">
                            <span>Creato: <?php echo $escape($createdAtText !== '' ? $createdAtText : 'N/D'); ?></span>
                            <span>Aggiornato: <?php echo $escape($updatedAtText !== '' ? $updatedAtText : 'N/D'); ?></span>
                        </div>
                    </td>
                </tr>
            </table>
            <table class="cv-body">
                <tr>
                    <td class="cv-sidebar">
                        <?php if ($contactItems): ?>
                            <div class="cv-side-group">
                                <h3>Contatti</h3>
                                <ul class="cv-contact-list">
                                    <?php foreach ($contactItems as $item): ?>
                                        <li>
                                            <span class="cv-contact-label"><?php echo $escape($item['label']); ?></span>
                                            <?php echo $escape($item['value']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($skillsHighlights): ?>
                            <div class="cv-side-group">
                                <h3>Competenze principali</h3>
                                <?php foreach ($skillsHighlights as $highlight): ?>
                                    <div class="cv-skill-chip">
                                        <?php echo $escape($highlight['name']); ?>
                                        <?php if ($highlight['percent'] > 0): ?>
                                            <span class="cv-skill-chip-note"><?php echo $highlight['percent']; ?>%</span>
                                        <?php elseif ($highlight['level'] !== ''): ?>
                                            <span class="cv-skill-chip-note"><?php echo $escape($highlight['level']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($highlight['description'] !== ''): ?>
                                        <div class="cv-side-note">
                                            <?php echo nl2br($escape($highlight['description'])); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($languages): ?>
                            <div class="cv-side-group">
                                <h3>Lingue</h3>
                                <?php foreach ($languages as $language): ?>
                                    <?php
                                    $overallLevel = trim((string) ($language['overall_level'] ?? ''));
                                    $abilityParts = [];
                                    foreach ($languageAbilities as $key => $label) {
                                        if (!empty($language[$key])) {
                                            $abilityParts[] = $label . ': ' . (string) $language[$key];
                                        }
                                    }
                                    ?>
                                    <div class="cv-language-item">
                                        <h4><?php echo $escape((string) ($language['language'] ?? '')); ?></h4>
                                        <?php if ($overallLevel !== ''): ?>
                                            <div class="cv-language-level"><?php echo $escape($overallLevel); ?></div>
                                        <?php endif; ?>
                                        <?php if ($abilityParts): ?>
                                            <div class="cv-language-abilities"><?php echo $escape(implode(' • ', $abilityParts)); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($language['certification'])): ?>
                                            <div class="cv-language-abilities">Certificazione: <?php echo $escape((string) $language['certification']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($strengths): ?>
                            <div class="cv-side-group">
                                <h3>Punti di forza</h3>
                                <ul class="cv-strength-list">
                                    <?php foreach ($strengths as $strength): ?>
                                        <li><strong><?php echo $escape($strength['label']); ?></strong><?php echo nl2br($escape($strength['value'])); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($certificationItems): ?>
                            <div class="cv-side-group">
                                <h3>Certificazioni</h3>
                                <ul class="cv-strength-list">
                                    <?php foreach ($certificationItems as $item): ?>
                                        <li><?php echo nl2br($escape($item)); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="cv-main">
                        <div class="cv-section">
                            <h2>Profilo professionale</h2>
                            <?php if (!empty($curriculum['professional_summary'])): ?>
                                <p><?php echo nl2br($escape((string) $curriculum['professional_summary'])); ?></p>
                            <?php else: ?>
                                <p>Profilo non ancora compilato.</p>
                            <?php endif; ?>
                        </div>
                        <div class="cv-section">
                            <h2>Esperienza professionale</h2>
                            <?php if ($experiences): ?>
                                <?php foreach ($experiences as $experience): ?>
                                    <?php
                                    $experienceTitle = $this->joinWithEmDash([
                                        $experience['role_title'] ?? '',
                                        $experience['employer'] ?? '',
                                    ]);
                                    $experienceLocation = $this->joinWithComma([
                                        $experience['city'] ?? '',
                                        $experience['country'] ?? '',
                                    ]);
                                    ?>
                                    <div class="cv-entry">
                                        <div class="cv-entry-title"><?php echo $escape($experienceTitle !== '' ? $experienceTitle : 'Esperienza'); ?></div>
                                        <div class="cv-entry-meta"><?php echo $escape($this->formatTimelinePeriod($experience['start_date'] ?? null, $experience['end_date'] ?? null, (bool) ($experience['is_current'] ?? 0))); ?><?php if ($experienceLocation !== ''): ?> • <?php echo $escape($experienceLocation); ?><?php endif; ?></div>
                                        <?php if (!empty($experience['description'])): ?>
                                            <p><?php echo nl2br($escape((string) $experience['description'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>Nessuna esperienza registrata.</p>
                            <?php endif; ?>
                        </div>
                        <div class="cv-section">
                            <h2>Istruzione</h2>
                            <?php if ($education): ?>
                                <?php foreach ($education as $item): ?>
                                    <?php
                                    $educationTitle = $this->joinWithEmDash([
                                        $item['title'] ?? '',
                                        $item['institution'] ?? '',
                                    ]);
                                    $educationLocation = $this->joinWithComma([
                                        $item['city'] ?? '',
                                        $item['country'] ?? '',
                                    ]);
                                    ?>
                                    <div class="cv-entry">
                                        <div class="cv-entry-title"><?php echo $escape($educationTitle !== '' ? $educationTitle : 'Percorso formativo'); ?></div>
                                        <div class="cv-entry-meta"><?php echo $escape($this->formatTimelinePeriod($item['start_date'] ?? null, $item['end_date'] ?? null, false)); ?><?php if ($educationLocation !== ''): ?> • <?php echo $escape($educationLocation); ?><?php endif; ?></div>
                                        <?php if (!empty($item['qualification_level'])): ?>
                                            <div class="cv-subtext"><strong>Livello:</strong> <?php echo $escape((string) $item['qualification_level']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['description'])): ?>
                                            <p><?php echo nl2br($escape((string) $item['description'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>Nessun percorso formativo registrato.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($skillsByCategory): ?>
                            <div class="cv-section">
                                <h2>Competenze personali</h2>
                                <?php $lastSkillCategory = array_key_last($skillsByCategory); ?>
                                <?php foreach ($skillsByCategory as $category => $skillItems): ?>
                                    <div class="cv-section-subheading"><?php echo $escape($category); ?></div>
                                    <ul class="cv-list">
                                        <?php foreach ($skillItems as $skill): ?>
                                            <li>
                                                <div class="cv-skill-line">
                                                    <span class="cv-skill-name"><?php echo $escape((string) ($skill['skill'] ?? '')); ?></span>
                                                    <?php if (!empty($skill['level'])): ?>
                                                        <span class="cv-skill-level">— <?php echo $escape((string) $skill['level']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($skill['description'])): ?>
                                                    <div class="cv-subtext cv-skill-description"><?php echo nl2br($escape((string) $skill['description'])); ?></div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($category !== $lastSkillCategory): ?>
                                        <div class="cv-divider"></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($curriculum['additional_information']) || $summaryBlocks): ?>
                            <div class="cv-section">
                                <h2>Ulteriori informazioni</h2>
                                <?php if (!empty($curriculum['additional_information'])): ?>
                                    <p><?php echo nl2br($escape((string) $curriculum['additional_information'])); ?></p>
                                <?php endif; ?>
                                <?php if ($summaryBlocks): ?>
                                    <ul class="cv-list">
                                        <?php foreach ($summaryBlocks as $block): ?>
                                            <li><?php echo nl2br($escape($block)); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="cv-footer"></div>
                    </td>
                </tr>
            </table>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function escape(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function truncate(string $value, int $length = 200): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = max($length, 24);

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $length) {
                return $value;
            }
            return rtrim((string) mb_substr($value, 0, $length - 3)) . '...';
        }

        if (strlen($value) <= $length) {
            return $value;
        }

        return rtrim(substr($value, 0, $length - 3)) . '...';
    }

    private function formatDateTime(?string $value, string $format = 'd/m/Y H:i', string $fallback = ''): string
    {
        if (!$value) {
            return $fallback;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return $fallback;
        }

        return $date->format($format);
    }

    private function formatTimelinePeriod(?string $start, ?string $end, bool $isCurrent): string
    {
        $startFormatted = $this->formatMonthYear($start);
        $endFormatted = $isCurrent ? 'Presente' : $this->formatMonthYear($end);

        if ($startFormatted === '' && $endFormatted === '') {
            return 'Periodo non indicato';
        }
        if ($startFormatted === '') {
            return 'Fino a ' . $endFormatted;
        }
        if ($endFormatted === '') {
            return 'Dal ' . $startFormatted;
        }

        return $startFormatted . ' — ' . $endFormatted;
    }

    private function formatMonthYear(?string $value): string
    {
        if (!$value) {
            return '';
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return '';
        }

        return $date->format('m/Y');
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'Pubblicato' => '#28a745',
            'Archiviato' => '#6c757d',
            default => '#ffc107',
        };
    }

    private function cefrScores(): array
    {
        return [
            'Madrelingua' => 100,
            'C2' => 95,
            'C1' => 85,
            'B2' => 75,
            'B1' => 60,
            'A2' => 45,
            'A1' => 30,
        ];
    }

    private function utf8Substring(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length);
        }

        return substr($value, $start, $length);
    }

    private function joinWithEmDash(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return implode(' — ', $clean);
    }

    private function joinWithComma(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return implode(', ', $clean);
    }

    private function mapSkillLevelToPercent(?string $level, ?string $description = null): int
    {
        $candidates = [];
        if ($level !== null) {
            $candidates[] = $level;
        }
        if ($description !== null) {
            $candidates[] = $description;
        }

        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text === '') {
                continue;
            }
            if (preg_match('/(\d{1,3})\s*%/', $text, $matches) === 1) {
                return max(0, min(100, (int) $matches[1]));
            }
            if (preg_match('/(\d{1,3})/', $text, $matches) === 1) {
                $value = (int) $matches[1];
                if ($value > 0 && $value <= 100) {
                    return $value;
                }
            }
        }

        $text = trim((string) ($level ?? ''));
        if ($text === '') {
            return 0;
        }

        if (function_exists('mb_strtolower')) {
            $normalized = mb_strtolower($text, 'UTF-8');
        } else {
            $normalized = strtolower($text);
        }

        $map = [
            'principiante' => 25,
            'beginner' => 25,
            'base' => 35,
            'elementare' => 35,
            'basso' => 35,
            'intermedio' => 55,
            'intermediate' => 55,
            'buono' => 65,
            'good' => 65,
            'ottimo' => 75,
            'avanzato' => 80,
            'advanced' => 80,
            'esperto' => 90,
            'expert' => 90,
            'specialist' => 90,
        ];

        foreach ($map as $needle => $percent) {
            if (str_contains($normalized, $needle)) {
                return $percent;
            }
        }

        return 0;
    }

    private function createPdfInstance()
    {
        $className = '\\Mpdf\\Mpdf';
        if (!class_exists($className)) {
            throw new RuntimeException('Libreria mPDF non disponibile.');
        }

        return new $className([
            'format' => 'A4',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 18,
            'margin_bottom' => 18,
        ]);
    }

}
