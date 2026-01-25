<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('db_table_exists')) {
    function db_table_exists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute([':table' => $tableName]);
            $cache[$tableName] = (bool) $stmt->fetchColumn();
            return $cache[$tableName];
        } catch (Throwable $exception) {
            // Fallback per ambienti che non consentono l'accesso a information_schema
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            $cache[$tableName] = false;
            return false;
        }

        try {
            $pdo->query('SELECT 1 FROM `' . $tableName . '` LIMIT 1');
            $cache[$tableName] = true;
        } catch (Throwable $exception) {
            $cache[$tableName] = false;
        }
        return $cache[$tableName];
    }
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(PDO $pdo, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . ':' . $columnName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
            $stmt->execute([
                ':table' => $tableName,
                ':column' => $columnName,
            ]);
            $cache[$key] = (bool) $stmt->fetchColumn();
            return $cache[$key];
        } catch (Throwable $exception) {
            // Fallback per ambienti che non consentono l'accesso a information_schema
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
            $cache[$key] = false;
            return false;
        }

        try {
            $pdo->query('SELECT `' . $columnName . '` FROM `' . $tableName . '` LIMIT 1');
            $cache[$key] = true;
        } catch (Throwable $exception) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

function global_search_type_meta(): array
{
    return [
        'cliente' => ['label' => 'Clienti', 'icon' => 'fa-user'],
        'pratica' => ['label' => 'Pratiche CAF/Patronato', 'icon' => 'fa-folder-open'],
        'opportunita' => ['label' => 'Opportunità', 'icon' => 'fa-briefcase'],
        'contratto' => ['label' => 'Contratti energia', 'icon' => 'fa-file-contract'],
        'fattura' => ['label' => 'Entrate/Uscite', 'icon' => 'fa-receipt'],
        'documento' => ['label' => 'Documenti', 'icon' => 'fa-file-lines'],
        'appuntamento' => ['label' => 'Appuntamenti', 'icon' => 'fa-calendar-check'],
        'aci' => ['label' => 'Pratiche ACI', 'icon' => 'fa-car'],
        'anpr' => ['label' => 'Pratiche ANPR', 'icon' => 'fa-id-card'],
        'cie' => ['label' => 'Prenotazioni CIE', 'icon' => 'fa-id-card-clip'],
        'digitale' => ['label' => 'Servizi digitali', 'icon' => 'fa-shield-halved'],
        'fedelta' => ['label' => 'Movimenti fedeltà', 'icon' => 'fa-star'],
        'curriculum' => ['label' => 'Curriculum', 'icon' => 'fa-file-signature'],
        'spedizione' => ['label' => 'Spedizioni', 'icon' => 'fa-truck'],
        'brt_spedizione' => ['label' => 'Spedizioni BRT', 'icon' => 'fa-truck-fast'],
        'brt_manifest' => ['label' => 'Manifest BRT', 'icon' => 'fa-list-check'],
        'telegramma' => ['label' => 'Telegrammi', 'icon' => 'fa-paper-plane'],
        'visura' => ['label' => 'Visure CR', 'icon' => 'fa-building'],
        'posta' => ['label' => 'Posta telematica', 'icon' => 'fa-envelope-open-text'],
        'pickup' => ['label' => 'Logistica pickup', 'icon' => 'fa-box'],
        'pickup_report' => ['label' => 'Segnalazioni pickup', 'icon' => 'fa-triangle-exclamation'],
        'iliad' => ['label' => 'Credenziali Iliad', 'icon' => 'fa-sim-card'],
        'campagna_email' => ['label' => 'Campagne email', 'icon' => 'fa-envelope-circle-check'],
        'iscritto_email' => ['label' => 'Iscritti email', 'icon' => 'fa-user-check'],
        'report' => ['label' => 'Report', 'icon' => 'fa-chart-line'],
        'utente' => ['label' => 'Utenti', 'icon' => 'fa-user-gear'],
        'notifica' => ['label' => 'Notifiche', 'icon' => 'fa-bell'],
        'ticket' => ['label' => 'Ticket', 'icon' => 'fa-life-ring'],
    ];
}

function global_search(PDO $pdo, string $term, array $options = []): array
{
    $term = trim($term);
    $term = preg_replace('/\s+/', ' ', $term) ?? $term;
    $limit = (int) ($options['limit'] ?? 8);
    $limit = max(1, min(60, $limit));

    $role = (string) ($options['role'] ?? ($_SESSION['role'] ?? ''));
    $userId = (int) ($options['userId'] ?? ($_SESSION['user_id'] ?? 0));
    $userEmail = (string) ($options['userEmail'] ?? ($_SESSION['email'] ?? ''));

    $typeFilter = $options['types'] ?? [];
    if (is_string($typeFilter)) {
        $typeFilter = array_filter(array_map('trim', explode(',', $typeFilter)));
    }
    $typeFilter = array_map('strtolower', array_filter((array) $typeFilter));

    $minLength = 2;
    $warnings = [];
    $isClienteRole = $role === 'Cliente';
    $hasClientScope = !$isClienteRole || $userEmail !== '';
    if ($isClienteRole && !$hasClientScope) {
        $warnings[] = 'Profilo cliente non associato a un indirizzo email.';
    }

    $canSeeClients = current_user_has_capability('clients.manage', 'clients.view', 'clients.view_self');
    $canManageUsers = current_user_has_capability('users.manage');
    $canSeeTickets = current_user_has_capability('tickets.manage', 'tickets.view_self');
    $canSeeEmailMarketing = current_user_has_capability('email.marketing.manage', 'email.marketing.view');
    $canSeeServices = current_user_has_capability('services.manage');
    $canSeeDocuments = current_user_has_capability('services.manage', 'clients.manage', 'clients.view');
    $canSeeReports = current_user_has_capability('reports.view');
    $canSeeNotifications = $userId > 0;

    $allowedTypes = [];
    if ($canSeeClients && db_table_exists($pdo, 'clienti')) {
        $allowedTypes[] = 'cliente';
    }
    if ($canSeeServices && db_table_exists($pdo, 'pratiche')) {
        $allowedTypes[] = 'pratica';
    }
    if ($canSeeServices && db_table_exists($pdo, 'opportunities')) {
        $allowedTypes[] = 'opportunita';
    }
    if ($canSeeServices && db_table_exists($pdo, 'energia_contratti')) {
        $allowedTypes[] = 'contratto';
    }
    if ($canSeeServices && db_table_exists($pdo, 'entrate_uscite')) {
        $allowedTypes[] = 'fattura';
    }
    if ($canSeeDocuments && db_table_exists($pdo, 'documents')) {
        $allowedTypes[] = 'documento';
    }
    if ($canSeeServices && db_table_exists($pdo, 'servizi_appuntamenti')) {
        $allowedTypes[] = 'appuntamento';
    }
    if ($canSeeServices && db_table_exists($pdo, 'servizi_aci_pratiche')) {
        $allowedTypes[] = 'aci';
    }
    if ($canSeeServices && db_table_exists($pdo, 'anpr_pratiche')) {
        $allowedTypes[] = 'anpr';
    }
    if ($canSeeServices && db_table_exists($pdo, 'cie_prenotazioni')) {
        $allowedTypes[] = 'cie';
    }
    if ($canSeeServices && db_table_exists($pdo, 'servizi_digitali')) {
        $allowedTypes[] = 'digitale';
    }
    if ($canSeeServices && db_table_exists($pdo, 'fedelta_movimenti')) {
        $allowedTypes[] = 'fedelta';
    }
    if ($canSeeServices && db_table_exists($pdo, 'curriculum')) {
        $allowedTypes[] = 'curriculum';
    }
    if ($canSeeServices && db_table_exists($pdo, 'spedizioni')) {
        $allowedTypes[] = 'spedizione';
    }
    if ($canSeeServices && db_table_exists($pdo, 'brt_shipments')) {
        $allowedTypes[] = 'brt_spedizione';
    }
    if ($canSeeServices && db_table_exists($pdo, 'brt_manifests')) {
        $allowedTypes[] = 'brt_manifest';
    }
    if ($canSeeServices && db_table_exists($pdo, 'posta_telematica_messages')) {
        $allowedTypes[] = 'posta';
    }
    if ($canSeeServices && db_table_exists($pdo, 'posta_telematica_pec_messages')) {
        $allowedTypes[] = 'posta';
    }
    if ($canSeeServices && db_table_exists($pdo, 'servizi_telegrammi')) {
        $allowedTypes[] = 'telegramma';
    }
    if ($canSeeServices && db_table_exists($pdo, 'servizi_visure_cr_pratiche')) {
        $allowedTypes[] = 'visura';
    }
    if ($canSeeServices && db_table_exists($pdo, 'pickup_packages')) {
        $allowedTypes[] = 'pickup';
    }
    if ($canSeeServices && db_table_exists($pdo, 'pickup_customer_reports')) {
        $allowedTypes[] = 'pickup_report';
    }
    if ($canSeeServices && db_table_exists($pdo, 'iliad_credentials')) {
        $allowedTypes[] = 'iliad';
    }
    if ($canSeeEmailMarketing && db_table_exists($pdo, 'email_campaigns')) {
        $allowedTypes[] = 'campagna_email';
    }
    if ($canSeeEmailMarketing && db_table_exists($pdo, 'email_subscribers')) {
        $allowedTypes[] = 'iscritto_email';
    }
    if ($canSeeReports && db_table_exists($pdo, 'daily_financial_reports')) {
        $allowedTypes[] = 'report';
    }
    if ($canManageUsers && db_table_exists($pdo, 'users')) {
        $allowedTypes[] = 'utente';
    }
    if ($canSeeTickets && db_table_exists($pdo, 'tickets')) {
        $allowedTypes[] = 'ticket';
    }
    if ($canSeeNotifications && db_table_exists($pdo, 'notifications')) {
        $allowedTypes[] = 'notifica';
    }

    $allowedTypes = array_values(array_unique($allowedTypes));

    if ($term === '' || mb_strlen($term) < $minLength || !$allowedTypes) {
        return [
            'query' => $term,
            'items' => [],
            'groups' => [],
            'warnings' => $warnings,
            'allowedTypes' => $allowedTypes,
        ];
    }

    $likeTerm = '%' . $term . '%';
    $likeStart = $term . '%';
    $perTypeLimit = min(10, $limit);

    $items = [];

    $hasClientCompanyName = db_column_exists($pdo, 'clienti', 'ragione_sociale');
    $hasClientEmail = db_column_exists($pdo, 'clienti', 'email');
    $hasClientPhone = db_column_exists($pdo, 'clienti', 'telefono');
    $hasClientCfPiva = db_column_exists($pdo, 'clienti', 'cf_piva');
    $hasClientUpdatedAt = db_column_exists($pdo, 'clienti', 'updated_at');
    $clientBaseNameExpression = "TRIM(CONCAT_WS(' ', c.nome, c.cognome))";
    $clientReverseNameExpression = "TRIM(CONCAT_WS(' ', c.cognome, c.nome))";
    $clientDisplayParts = [];
    if ($hasClientCompanyName) {
        $clientDisplayParts[] = "NULLIF(TRIM(c.ragione_sociale), '')";
    }
    $clientDisplayParts[] = "NULLIF($clientBaseNameExpression, '')";
    if ($hasClientEmail) {
        $clientDisplayParts[] = "NULLIF(c.email, '')";
    }
    if ($hasClientCfPiva) {
        $clientDisplayParts[] = 'c.cf_piva';
    }
    $clientDisplayExpression = 'COALESCE(' . implode(', ', $clientDisplayParts) . ')';

    if ($canSeeClients && $hasClientScope && (empty($typeFilter) || in_array('cliente', $typeFilter, true))) {
        try {
            $clientSelect = ['c.id', 'c.nome', 'c.cognome'];
            if ($hasClientCompanyName) {
                $clientSelect[] = 'c.ragione_sociale';
            }
            if ($hasClientEmail) {
                $clientSelect[] = 'c.email';
            }
            if ($hasClientPhone) {
                $clientSelect[] = 'c.telefono';
            }
            if ($hasClientCfPiva) {
                $clientSelect[] = 'c.cf_piva';
            }
            if ($hasClientUpdatedAt) {
                $clientSelect[] = 'c.updated_at';
            }

            $clientStartParts = [
                'c.nome LIKE :start',
                'c.cognome LIKE :start',
                $clientBaseNameExpression . ' LIKE :start',
                $clientReverseNameExpression . ' LIKE :start',
            ];
            $clientTermParts = [
                'c.nome LIKE :term',
                'c.cognome LIKE :term',
                $clientBaseNameExpression . ' LIKE :term',
                $clientReverseNameExpression . ' LIKE :term',
            ];
            if ($hasClientCompanyName) {
                $clientStartParts[] = 'c.ragione_sociale LIKE :start';
                $clientTermParts[] = 'c.ragione_sociale LIKE :term';
            }
            if ($hasClientEmail) {
                $clientStartParts[] = 'c.email LIKE :start';
                $clientTermParts[] = 'c.email LIKE :term';
            }
            if ($hasClientCfPiva) {
                $clientStartParts[] = 'c.cf_piva LIKE :start';
                $clientTermParts[] = 'c.cf_piva LIKE :term';
            }

            $clientTokenConditions = [];
            $clientTokenParams = [];
            $tokens = array_values(array_filter(explode(' ', $term), static fn(string $value): bool => $value !== ''));
            if (count($tokens) > 1) {
                foreach ($tokens as $index => $token) {
                    $param = ':token' . $index;
                    $clientTokenParams[$param] = '%' . $token . '%';
                    $tokenParts = [
                        'c.nome LIKE ' . $param,
                        'c.cognome LIKE ' . $param,
                        $clientBaseNameExpression . ' LIKE ' . $param,
                        $clientReverseNameExpression . ' LIKE ' . $param,
                    ];
                    if ($hasClientCompanyName) {
                        $tokenParts[] = 'c.ragione_sociale LIKE ' . $param;
                    }
                    if ($hasClientEmail) {
                        $tokenParts[] = 'c.email LIKE ' . $param;
                    }
                    if ($hasClientCfPiva) {
                        $tokenParts[] = 'c.cf_piva LIKE ' . $param;
                    }
                    $clientTokenConditions[] = '(' . implode(' OR ', $tokenParts) . ')';
                }
            }

            $clientWhere = $clientTokenConditions
                ? implode(' AND ', $clientTokenConditions)
                : '(' . implode(' OR ', $clientTermParts) . ')';

            $clientSql = "SELECT " . implode(', ', $clientSelect) . ",
                    CASE
                        WHEN " . implode(' OR ', $clientStartParts) . " THEN 3
                        WHEN " . implode(' OR ', $clientTermParts) . " THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM clienti c
                WHERE " . $clientWhere;
            $params = array_merge([':term' => $likeTerm, ':start' => $likeStart], $clientTokenParams);
            if ($isClienteRole && $userEmail !== '') {
                $clientSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $clientSql .= ' ORDER BY relevance_score DESC, c.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($clientSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['ragione_sociale'] ?? ''));
                if ($title === '') {
                    $title = trim(trim((string) ($row['nome'] ?? '')) . ' ' . trim((string) ($row['cognome'] ?? '')));
                }
                if ($title === '') {
                    $title = (string) ($row['email'] ?? '');
                }
                if ($title === '') {
                    $title = 'Cliente #' . (int) $row['id'];
                }
                $subtitleParts = array_filter([
                    $row['email'] ?? null,
                    $row['telefono'] ?? null,
                    $row['cf_piva'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'cliente',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/clienti/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => null,
                    'icon' => 'fa-user',
                    'badge' => 'Cliente',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca clienti temporaneamente non disponibile.';
            error_log('Global search clients failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('pratica', $typeFilter, true)) && db_table_exists($pdo, 'pratiche')) {
        try {
            $praticaSql = "SELECT p.id, p.titolo, p.categoria, p.stato, p.tracking_code, p.data_aggiornamento,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN p.titolo LIKE :start OR p.tracking_code LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN p.titolo LIKE :term OR p.tracking_code LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM pratiche p
                LEFT JOIN clienti c ON c.id = p.cliente_id
                WHERE (p.titolo LIKE :term OR p.tracking_code LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $praticaSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $praticaSql .= ' ORDER BY relevance_score DESC, p.data_aggiornamento DESC LIMIT :limit';
            $stmt = $pdo->prepare($praticaSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = (string) ($row['titolo'] ?? 'Pratica #' . (int) $row['id']);
                $subtitleParts = array_filter([
                    $row['tracking_code'] ? ('Tracking: ' . $row['tracking_code']) : null,
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                    $row['categoria'] ? ('Categoria: ' . $row['categoria']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'pratica',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/caf-patronato/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['data_aggiornamento'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-folder-open',
                    'badge' => 'Pratica',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca pratiche temporaneamente non disponibile.';
            error_log('Global search pratiche failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('contratto', $typeFilter, true)) && db_table_exists($pdo, 'energia_contratti')) {
        try {
            $contrattiSql = "SELECT ec.id, ec.contract_code, ec.nominativo, ec.codice_fiscale, ec.email, ec.telefono, ec.fornitura, ec.stato, ec.updated_at,
                    CASE
                        WHEN ec.contract_code LIKE :start OR ec.nominativo LIKE :start OR ec.email LIKE :start THEN 3
                        WHEN ec.contract_code LIKE :term OR ec.nominativo LIKE :term OR ec.email LIKE :term OR ec.codice_fiscale LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM energia_contratti ec
                WHERE (ec.contract_code LIKE :term OR ec.nominativo LIKE :term OR ec.email LIKE :term OR ec.codice_fiscale LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $contrattiSql .= ' AND ec.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $contrattiSql .= ' ORDER BY relevance_score DESC, ec.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($contrattiSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $code = $row['contract_code'] ? ('#' . $row['contract_code']) : ('#' . (int) $row['id']);
                $title = trim($row['nominativo'] ?? '') !== '' ? $row['nominativo'] : ('Contratto ' . $code);
                $subtitleParts = array_filter([
                    $row['email'] ?? null,
                    $row['fornitura'] ? ('Fornitura: ' . $row['fornitura']) : null,
                    $row['codice_fiscale'] ? ('CF: ' . $row['codice_fiscale']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'contratto',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/energia/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-file-contract',
                    'badge' => 'Contratto',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca contratti temporaneamente non disponibile.';
            error_log('Global search contratti failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && (empty($typeFilter) || in_array('fattura', $typeFilter, true)) && db_table_exists($pdo, 'entrate_uscite')) {
        try {
            $fattureSql = "SELECT eu.id, eu.descrizione, eu.riferimento, eu.tipo_movimento, eu.stato, eu.data_scadenza, eu.updated_at,
                    CASE
                        WHEN eu.descrizione LIKE :start OR eu.riferimento LIKE :start THEN 3
                        WHEN eu.descrizione LIKE :term OR eu.riferimento LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM entrate_uscite eu
                WHERE (eu.descrizione LIKE :term OR eu.riferimento LIKE :term)
                ORDER BY relevance_score DESC, eu.updated_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($fattureSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['descrizione'] ?? ''));
                if ($title === '') {
                    $title = 'Fattura #' . (int) $row['id'];
                }
                $subtitleParts = array_filter([
                    $row['riferimento'] ? ('Rif: ' . $row['riferimento']) : null,
                    $row['tipo_movimento'] ? ('Tipo: ' . $row['tipo_movimento']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'fattura',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/entrate-uscite/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-receipt',
                    'badge' => 'Fattura',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca fatture temporaneamente non disponibile.';
            error_log('Global search fatture failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeDocuments && (empty($typeFilter) || in_array('documento', $typeFilter, true)) && db_table_exists($pdo, 'documents')) {
        try {
            $documentsSql = "SELECT d.id, d.titolo, d.descrizione, d.modulo, d.stato, d.updated_at,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN d.titolo LIKE :start OR d.descrizione LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN d.titolo LIKE :term OR d.descrizione LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM documents d
                LEFT JOIN clienti c ON c.id = d.cliente_id
                WHERE (d.titolo LIKE :term OR d.descrizione LIKE :term OR $clientDisplayExpression LIKE :term)
                ORDER BY relevance_score DESC, d.updated_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($documentsSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = (string) ($row['titolo'] ?? 'Documento #' . (int) $row['id']);
                $subtitleParts = array_filter([
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                    $row['modulo'] ? ('Modulo: ' . $row['modulo']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'documento',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/documenti/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-file-lines',
                    'badge' => 'Documento',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca documenti temporaneamente non disponibile.';
            error_log('Global search documents failed: ' . $exception->getMessage());
        }
    }

    if ($canManageUsers && (empty($typeFilter) || in_array('utente', $typeFilter, true))) {
        try {
            $usersSql = "SELECT id, username, email, ruolo, nome, cognome, created_at,
                    CASE
                        WHEN username LIKE :start OR email LIKE :start OR nome LIKE :start OR cognome LIKE :start THEN 3
                        WHEN username LIKE :term OR email LIKE :term OR nome LIKE :term OR cognome LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM users
                WHERE (username LIKE :term OR email LIKE :term OR nome LIKE :term OR cognome LIKE :term)
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($usersSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $displayName = format_user_display_name($row['username'] ?? '', $row['email'] ?? null, $row['nome'] ?? null, $row['cognome'] ?? null);
                $subtitleParts = array_filter([
                    $row['username'] ?? null,
                    $row['email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'utente',
                    'title' => $displayName,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/impostazioni/users.php') . '#user-row-' . (int) $row['id'],
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['ruolo'] ?? null,
                    'icon' => 'fa-user-gear',
                    'badge' => 'Utente',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca utenti temporaneamente non disponibile.';
            error_log('Global search users failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeTickets && $hasClientScope && (empty($typeFilter) || in_array('ticket', $typeFilter, true)) && db_table_exists($pdo, 'tickets')) {
        try {
            $ticketsSql = "SELECT id, codice, subject, status, customer_name, customer_email, updated_at,
                    CASE
                        WHEN codice LIKE :start OR subject LIKE :start OR customer_name LIKE :start OR customer_email LIKE :start THEN 3
                        WHEN codice LIKE :term OR subject LIKE :term OR customer_name LIKE :term OR customer_email LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM tickets
                WHERE (codice LIKE :term OR subject LIKE :term OR customer_name LIKE :term OR customer_email LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $ticketsSql .= ' AND customer_email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $ticketsSql .= ' ORDER BY relevance_score DESC, updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($ticketsSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $code = $row['codice'] ?? ('TCK' . $row['id']);
                $title = trim((string) ($row['subject'] ?? ''));
                if ($title === '') {
                    $title = 'Ticket #' . $code;
                }
                $subtitleParts = array_filter([
                    $row['customer_name'] ? ('Cliente: ' . $row['customer_name']) : null,
                    $row['customer_email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'ticket',
                    'title' => sprintf('#%s · %s', $code, $title),
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/ticket/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-life-ring',
                    'badge' => 'Ticket',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca ticket temporaneamente non disponibile.';
            error_log('Global search tickets failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeNotifications && (empty($typeFilter) || in_array('notifica', $typeFilter, true)) && db_table_exists($pdo, 'notifications')) {
        try {
            $canViewBug = notification_can_view_bug($role) ? 1 : 0;
            $filters = '((user_id = :user_id) OR (user_id IS NULL AND role = :role))';
            if (!$canViewBug) {
                $filters .= " AND type <> 'bug'";
            }
            $notifSql = "SELECT id, title, message, type, created_at,
                    CASE
                        WHEN title LIKE :start OR message LIKE :start THEN 3
                        WHEN title LIKE :term OR message LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM notifications
                WHERE {$filters} AND (title LIKE :term OR message LIKE :term)
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($notifSql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':role', $role, PDO::PARAM_STR);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(5, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'notifica',
                    'title' => (string) ($row['title'] ?? 'Notifica'),
                    'subtitle' => (string) ($row['message'] ?? ''),
                    'url' => base_url('modules/impostazioni/notifications.php'),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['type'] ?? null,
                    'icon' => 'fa-bell',
                    'badge' => 'Notifica',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca notifiche temporaneamente non disponibile.';
            error_log('Global search notifications failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeEmailMarketing && (empty($typeFilter) || in_array('campagna_email', $typeFilter, true)) && db_table_exists($pdo, 'email_campaigns')) {
        try {
            $campaignSql = "SELECT id, name, subject, status, scheduled_at, updated_at,
                    CASE
                        WHEN name LIKE :start OR subject LIKE :start THEN 3
                        WHEN name LIKE :term OR subject LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM email_campaigns
                WHERE (name LIKE :term OR subject LIKE :term)
                ORDER BY relevance_score DESC, updated_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($campaignSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(8, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['name'] ?? ''));
                if ($title === '') {
                    $title = 'Campagna #' . (int) $row['id'];
                }
                $subtitleParts = array_filter([
                    $row['subject'] ?? null,
                    $row['status'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'campagna_email',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/email-marketing/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-envelope-circle-check',
                    'badge' => 'Campagna',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca campagne email temporaneamente non disponibile.';
            error_log('Global search campaigns failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeEmailMarketing && (empty($typeFilter) || in_array('iscritto_email', $typeFilter, true)) && db_table_exists($pdo, 'email_subscribers')) {
        try {
            $subscriberSql = "SELECT id, email, first_name, last_name, status, created_at,
                    CASE
                        WHEN email LIKE :start OR first_name LIKE :start OR last_name LIKE :start THEN 3
                        WHEN email LIKE :term OR first_name LIKE :term OR last_name LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM email_subscribers
                WHERE (email LIKE :term OR first_name LIKE :term OR last_name LIKE :term)
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($subscriberSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(8, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                $subtitleParts = array_filter([
                    $fullName !== '' ? $fullName : null,
                    $row['status'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'iscritto_email',
                    'title' => (string) ($row['email'] ?? 'Iscritto #' . (int) $row['id']),
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/email-marketing/subscribers.php') . '#subscriber-row-' . (int) $row['id'],
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-user-check',
                    'badge' => 'Iscritto',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca iscritti email temporaneamente non disponibile.';
            error_log('Global search subscribers failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('opportunita', $typeFilter, true)) && db_table_exists($pdo, 'opportunities')) {
        try {
            $opSql = "SELECT id, code, status_code, category, customer_first_name, customer_last_name, customer_email, customer_phone, updated_at,
                    CASE
                        WHEN code LIKE :start OR customer_first_name LIKE :start OR customer_last_name LIKE :start OR customer_email LIKE :start THEN 3
                        WHEN code LIKE :term OR customer_first_name LIKE :term OR customer_last_name LIKE :term OR customer_email LIKE :term OR customer_phone LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM opportunities
                WHERE (code LIKE :term OR customer_first_name LIKE :term OR customer_last_name LIKE :term OR customer_email LIKE :term OR customer_phone LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $opSql .= ' AND customer_email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $opSql .= ' ORDER BY relevance_score DESC, updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($opSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = trim((string) ($row['customer_first_name'] ?? '') . ' ' . (string) ($row['customer_last_name'] ?? ''));
                $title = trim((string) ($row['code'] ?? ''));
                $title = $title !== '' ? ('Opportunità ' . $title) : 'Opportunità #' . (int) $row['id'];
                if ($name !== '') {
                    $title .= ' · ' . $name;
                }
                $subtitleParts = array_filter([
                    $row['customer_email'] ?? null,
                    $row['customer_phone'] ?? null,
                    $row['category'] ? ('Categoria: ' . $row['category']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'opportunita',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/opportunities/detail.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['status_code'] ?? null,
                    'icon' => 'fa-briefcase',
                    'badge' => 'Opportunità',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca opportunità temporaneamente non disponibile.';
            error_log('Global search opportunities failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('appuntamento', $typeFilter, true)) && db_table_exists($pdo, 'servizi_appuntamenti')) {
        try {
            $appSql = "SELECT a.id, a.titolo, a.tipo_servizio, a.stato, a.data_inizio, a.updated_at,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN a.titolo LIKE :start OR a.tipo_servizio LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN a.titolo LIKE :term OR a.tipo_servizio LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM servizi_appuntamenti a
                LEFT JOIN clienti c ON c.id = a.cliente_id
                WHERE (a.titolo LIKE :term OR a.tipo_servizio LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $appSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $appSql .= ' ORDER BY relevance_score DESC, a.data_inizio DESC LIMIT :limit';
            $stmt = $pdo->prepare($appSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = (string) ($row['titolo'] ?? 'Appuntamento #' . (int) $row['id']);
                $subtitleParts = array_filter([
                    $row['tipo_servizio'] ? ('Servizio: ' . $row['tipo_servizio']) : null,
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'appuntamento',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/appuntamenti/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['data_inizio'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-calendar-check',
                    'badge' => 'Appuntamento',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca appuntamenti temporaneamente non disponibile.';
            error_log('Global search appointments failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('aci', $typeFilter, true)) && db_table_exists($pdo, 'servizi_aci_pratiche')) {
        try {
            $aciSql = "SELECT p.id, p.tipo_pratica, p.stato, p.targa, p.intestatario, p.protocollo, p.updated_at,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN p.tipo_pratica LIKE :start OR p.targa LIKE :start OR p.intestatario LIKE :start OR p.protocollo LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN p.tipo_pratica LIKE :term OR p.targa LIKE :term OR p.intestatario LIKE :term OR p.protocollo LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM servizi_aci_pratiche p
                LEFT JOIN clienti c ON c.id = p.cliente_id
                WHERE (p.tipo_pratica LIKE :term OR p.targa LIKE :term OR p.intestatario LIKE :term OR p.protocollo LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $aciSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $aciSql .= ' ORDER BY relevance_score DESC, p.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($aciSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'ACI · ' . ($row['tipo_pratica'] ?? 'Pratica');
                $subtitleParts = array_filter([
                    $row['targa'] ? ('Targa: ' . $row['targa']) : null,
                    $row['intestatario'] ? ('Intestatario: ' . $row['intestatario']) : null,
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'aci',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/aci/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-car',
                    'badge' => 'ACI',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca pratiche ACI temporaneamente non disponibile.';
            error_log('Global search ACI failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('anpr', $typeFilter, true)) && db_table_exists($pdo, 'anpr_pratiche')) {
        try {
            $anprSql = "SELECT a.id, a.pratica_code, a.tipo_pratica, a.stato, a.updated_at,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN a.pratica_code LIKE :start OR a.tipo_pratica LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN a.pratica_code LIKE :term OR a.tipo_pratica LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM anpr_pratiche a
                LEFT JOIN clienti c ON c.id = a.cliente_id
                WHERE (a.pratica_code LIKE :term OR a.tipo_pratica LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $anprSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $anprSql .= ' ORDER BY relevance_score DESC, a.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($anprSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'ANPR #' . ($row['pratica_code'] ?? (int) $row['id']);
                $subtitleParts = array_filter([
                    $row['tipo_pratica'] ? ('Tipo: ' . $row['tipo_pratica']) : null,
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'anpr',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/anpr/view_request.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-id-card',
                    'badge' => 'ANPR',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca pratiche ANPR temporaneamente non disponibile.';
            error_log('Global search ANPR failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('cie', $typeFilter, true)) && db_table_exists($pdo, 'cie_prenotazioni')) {
        try {
            $cieSql = "SELECT c.id, c.prenotazione_code, c.cittadino_nome, c.cittadino_cognome, c.cittadino_email, c.stato, c.updated_at,
                    CASE
                        WHEN c.prenotazione_code LIKE :start OR c.cittadino_nome LIKE :start OR c.cittadino_cognome LIKE :start OR c.cittadino_email LIKE :start THEN 3
                        WHEN c.prenotazione_code LIKE :term OR c.cittadino_nome LIKE :term OR c.cittadino_cognome LIKE :term OR c.cittadino_email LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM cie_prenotazioni c
                WHERE (c.prenotazione_code LIKE :term OR c.cittadino_nome LIKE :term OR c.cittadino_cognome LIKE :term OR c.cittadino_email LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $cieSql .= ' AND (c.cittadino_email = :user_email)';
                $params[':user_email'] = $userEmail;
            }
            $cieSql .= ' ORDER BY relevance_score DESC, c.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($cieSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fullName = trim((string) ($row['cittadino_nome'] ?? '') . ' ' . (string) ($row['cittadino_cognome'] ?? ''));
                $title = 'CIE #' . ($row['prenotazione_code'] ?? (int) $row['id']);
                if ($fullName !== '') {
                    $title .= ' · ' . $fullName;
                }
                $subtitleParts = array_filter([
                    $row['cittadino_email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'cie',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/cie/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-id-card-clip',
                    'badge' => 'CIE',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca prenotazioni CIE temporaneamente non disponibile.';
            error_log('Global search CIE failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('digitale', $typeFilter, true)) && db_table_exists($pdo, 'servizi_digitali')) {
        try {
            $digitaliSql = "SELECT d.id, d.tipo, d.stato, d.updated_at,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN d.tipo LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN d.tipo LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM servizi_digitali d
                LEFT JOIN clienti c ON c.id = d.cliente_id
                WHERE (d.tipo LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $digitaliSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $digitaliSql .= ' ORDER BY relevance_score DESC, d.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($digitaliSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Servizio digitale · ' . ($row['tipo'] ?? '');
                $subtitleParts = array_filter([
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'digitale',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/digitali/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-shield-halved',
                    'badge' => 'Digitale',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca servizi digitali temporaneamente non disponibile.';
            error_log('Global search digitali failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('fedelta', $typeFilter, true)) && db_table_exists($pdo, 'fedelta_movimenti')) {
        try {
            $fedeltaSql = "SELECT f.id, f.tipo_movimento, f.descrizione, f.punti, f.data_movimento,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN f.descrizione LIKE :start OR f.tipo_movimento LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN f.descrizione LIKE :term OR f.tipo_movimento LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM fedelta_movimenti f
                LEFT JOIN clienti c ON c.id = f.cliente_id
                WHERE (f.descrizione LIKE :term OR f.tipo_movimento LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $fedeltaSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $fedeltaSql .= ' ORDER BY relevance_score DESC, f.data_movimento DESC LIMIT :limit';
            $stmt = $pdo->prepare($fedeltaSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Fedeltà · ' . ($row['tipo_movimento'] ?? 'Movimento');
                $subtitleParts = array_filter([
                    $row['descrizione'] ?? null,
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'fedelta',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/fedelta/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['data_movimento'] ?? ''),
                    'status' => null,
                    'icon' => 'fa-star',
                    'badge' => 'Fedeltà',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca fedeltà temporaneamente non disponibile.';
            error_log('Global search fedelta failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('curriculum', $typeFilter, true)) && db_table_exists($pdo, 'curriculum')) {
        try {
            $cvSql = "SELECT cv.id, cv.titolo, cv.status, cv.updated_at,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN cv.titolo LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN cv.titolo LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM curriculum cv
                LEFT JOIN clienti c ON c.id = cv.cliente_id
                WHERE (cv.titolo LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $cvSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $cvSql .= ' ORDER BY relevance_score DESC, cv.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($cvSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = (string) ($row['titolo'] ?? 'Curriculum #' . (int) $row['id']);
                $subtitleParts = array_filter([
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'curriculum',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/curriculum/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-file-signature',
                    'badge' => 'Curriculum',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca curriculum temporaneamente non disponibile.';
            error_log('Global search curriculum failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('spedizione', $typeFilter, true)) && db_table_exists($pdo, 'spedizioni')) {
        try {
            $spedSql = "SELECT s.id, s.tipo_spedizione, s.destinatario, s.tracking_number, s.stato, s.updated_at, s.cliente_id,
                    $clientDisplayExpression AS cliente,
                    CASE
                        WHEN s.destinatario LIKE :start OR s.tracking_number LIKE :start OR s.tipo_spedizione LIKE :start OR $clientDisplayExpression LIKE :start THEN 3
                        WHEN s.destinatario LIKE :term OR s.tracking_number LIKE :term OR s.tipo_spedizione LIKE :term OR $clientDisplayExpression LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM spedizioni s
                LEFT JOIN clienti c ON c.id = s.cliente_id
                WHERE (s.destinatario LIKE :term OR s.tracking_number LIKE :term OR s.tipo_spedizione LIKE :term OR $clientDisplayExpression LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $spedSql .= ' AND c.email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $spedSql .= ' ORDER BY relevance_score DESC, s.updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($spedSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Spedizione ' . ($row['tracking_number'] ?? ('#' . (int) $row['id']));
                $subtitleParts = array_filter([
                    $row['destinatario'] ? ('Destinatario: ' . $row['destinatario']) : null,
                    $row['cliente'] ? ('Cliente: ' . $row['cliente']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'spedizione',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => $row['cliente_id'] ? base_url('modules/clienti/view.php?id=' . (int) $row['cliente_id']) : base_url('modules/clienti/index.php'),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-truck',
                    'badge' => 'Spedizione',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca spedizioni temporaneamente non disponibile.';
            error_log('Global search spedizioni failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('brt_spedizione', $typeFilter, true)) && db_table_exists($pdo, 'brt_shipments')) {
        try {
            $brtSql = "SELECT id, alphanumeric_sender_reference, numeric_sender_reference, consignee_name, consignee_email, status, updated_at,
                    CASE
                        WHEN alphanumeric_sender_reference LIKE :start OR consignee_name LIKE :start OR consignee_email LIKE :start THEN 3
                        WHEN alphanumeric_sender_reference LIKE :term OR consignee_name LIKE :term OR consignee_email LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM brt_shipments
                WHERE (alphanumeric_sender_reference LIKE :term OR consignee_name LIKE :term OR consignee_email LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $brtSql .= ' AND consignee_email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $brtSql .= ' ORDER BY relevance_score DESC, updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($brtSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $reference = $row['alphanumeric_sender_reference'] ?? ('#' . (int) $row['numeric_sender_reference']);
                $title = 'BRT ' . $reference;
                $subtitleParts = array_filter([
                    $row['consignee_name'] ?? null,
                    $row['consignee_email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'brt_spedizione',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/brt/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-truck-fast',
                    'badge' => 'BRT',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca spedizioni BRT temporaneamente non disponibile.';
            error_log('Global search brt shipments failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && (empty($typeFilter) || in_array('brt_manifest', $typeFilter, true)) && db_table_exists($pdo, 'brt_manifests')) {
        try {
            $manifestSql = "SELECT id, reference, generated_at, shipments_count,
                    CASE
                        WHEN reference LIKE :start THEN 3
                        WHEN reference LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM brt_manifests
                WHERE reference LIKE :term
                ORDER BY relevance_score DESC, generated_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($manifestSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(8, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Manifest BRT ' . ($row['reference'] ?? ('#' . (int) $row['id']));
                $subtitleParts = array_filter([
                    $row['shipments_count'] ? ('Spedizioni: ' . $row['shipments_count']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'brt_manifest',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/brt/manifest.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['generated_at'] ?? ''),
                    'status' => null,
                    'icon' => 'fa-list-check',
                    'badge' => 'Manifest',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca manifest BRT temporaneamente non disponibile.';
            error_log('Global search brt manifests failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('posta', $typeFilter, true)) && db_table_exists($pdo, 'posta_telematica_messages')) {
        try {
            $postaSql = "SELECT id, recipient_email, subject, status, created_at,
                    CASE
                        WHEN recipient_email LIKE :start OR subject LIKE :start THEN 3
                        WHEN recipient_email LIKE :term OR subject LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM posta_telematica_messages
                WHERE (recipient_email LIKE :term OR subject LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $postaSql .= ' AND recipient_email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $postaSql .= ' ORDER BY relevance_score DESC, created_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($postaSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = (string) ($row['subject'] ?? 'Invio #' . (int) $row['id']);
                $subtitleParts = array_filter([
                    $row['recipient_email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'posta',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/posta-telematica/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-envelope-open-text',
                    'badge' => 'Posta',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca posta telematica temporaneamente non disponibile.';
            error_log('Global search posta telematica failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && $hasClientScope && (empty($typeFilter) || in_array('telegramma', $typeFilter, true)) && db_table_exists($pdo, 'servizi_telegrammi')) {
        try {
            $telegrammiSql = "SELECT id, telegramma_id, riferimento, stato, created_at,
                    CASE
                        WHEN telegramma_id LIKE :start OR riferimento LIKE :start THEN 3
                        WHEN telegramma_id LIKE :term OR riferimento LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM servizi_telegrammi
                WHERE (telegramma_id LIKE :term OR riferimento LIKE :term)
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($telegrammiSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Telegramma ' . ($row['telegramma_id'] ?? ('#' . (int) $row['id']));
                $subtitleParts = array_filter([
                    $row['riferimento'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'telegramma',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/telegrammi/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-paper-plane',
                    'badge' => 'Telegramma',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca telegrammi temporaneamente non disponibile.';
            error_log('Global search telegrammi failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && (empty($typeFilter) || in_array('visura', $typeFilter, true)) && db_table_exists($pdo, 'servizi_visure_cr_pratiche')) {
        try {
            $visuraSql = "SELECT id, tipo_visura, stato, ragione_sociale, email, email_aziendale, updated_at,
                    CASE
                        WHEN ragione_sociale LIKE :start OR email LIKE :start OR email_aziendale LIKE :start THEN 3
                        WHEN ragione_sociale LIKE :term OR email LIKE :term OR email_aziendale LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM servizi_visure_cr_pratiche
                WHERE (ragione_sociale LIKE :term OR email LIKE :term OR email_aziendale LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $visuraSql .= ' AND (email = :user_email OR email_aziendale = :user_email)';
                $params[':user_email'] = $userEmail;
            }
            $visuraSql .= ' ORDER BY relevance_score DESC, updated_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($visuraSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Visura ' . ($row['ragione_sociale'] ?? ('#' . (int) $row['id']));
                $subtitleParts = array_filter([
                    $row['email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'visura',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/visure-cr/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['updated_at'] ?? ''),
                    'status' => $row['stato'] ?? null,
                    'icon' => 'fa-building',
                    'badge' => 'Visura',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca visure temporaneamente non disponibile.';
            error_log('Global search visure failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && (empty($typeFilter) || in_array('pickup', $typeFilter, true)) && db_table_exists($pdo, 'pickup_packages')) {
        try {
            $pickupSql = "SELECT id, tracking, customer_name, customer_email, status, created_at,
                    CASE
                        WHEN tracking LIKE :start OR customer_name LIKE :start OR customer_email LIKE :start THEN 3
                        WHEN tracking LIKE :term OR customer_name LIKE :term OR customer_email LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM pickup_packages
                WHERE (tracking LIKE :term OR customer_name LIKE :term OR customer_email LIKE :term)";
            $params = [':term' => $likeTerm, ':start' => $likeStart];
            if ($isClienteRole && $userEmail !== '') {
                $pickupSql .= ' AND customer_email = :user_email';
                $params[':user_email'] = $userEmail;
            }
            $pickupSql .= ' ORDER BY relevance_score DESC, created_at DESC LIMIT :limit';
            $stmt = $pdo->prepare($pickupSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perTypeLimit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Pickup ' . ($row['tracking'] ?? ('#' . (int) $row['id']));
                $subtitleParts = array_filter([
                    $row['customer_name'] ?? null,
                    $row['customer_email'] ?? null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'pickup',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/servizi/logistici/view.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-box',
                    'badge' => 'Pickup',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca pickup temporaneamente non disponibile.';
            error_log('Global search pickup failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && (empty($typeFilter) || in_array('pickup_report', $typeFilter, true)) && db_table_exists($pdo, 'pickup_customer_reports')) {
        try {
            $reportSql = "SELECT id, tracking_code, status, created_at,
                    CASE
                        WHEN tracking_code LIKE :start THEN 3
                        WHEN tracking_code LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM pickup_customer_reports
                WHERE tracking_code LIKE :term
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($reportSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(8, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Segnalazione ' . ($row['tracking_code'] ?? ('#' . (int) $row['id']));
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'pickup_report',
                    'title' => $title,
                    'subtitle' => '',
                    'url' => base_url('modules/servizi/logistici/report.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => $row['status'] ?? null,
                    'icon' => 'fa-triangle-exclamation',
                    'badge' => 'Segnalazione',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca segnalazioni pickup temporaneamente non disponibile.';
            error_log('Global search pickup reports failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeServices && (empty($typeFilter) || in_array('iliad', $typeFilter, true)) && db_table_exists($pdo, 'iliad_credentials')) {
        try {
            $iliadSql = "SELECT id, fibra_id, mobile_id, created_at,
                    CASE
                        WHEN fibra_id LIKE :start OR mobile_id LIKE :start THEN 3
                        WHEN fibra_id LIKE :term OR mobile_id LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM iliad_credentials
                WHERE (fibra_id LIKE :term OR mobile_id LIKE :term)
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($iliadSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(8, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Credenziali Iliad #' . (int) $row['id'];
                $subtitleParts = array_filter([
                    $row['fibra_id'] ? ('Fibra: ' . $row['fibra_id']) : null,
                    $row['mobile_id'] ? ('Mobile: ' . $row['mobile_id']) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'iliad',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/iliad/generate_pdf.php?id=' . (int) $row['id']),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => null,
                    'icon' => 'fa-sim-card',
                    'badge' => 'Iliad',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca credenziali Iliad temporaneamente non disponibile.';
            error_log('Global search iliad failed: ' . $exception->getMessage());
        }
    }

    if ($canSeeReports && (empty($typeFilter) || in_array('report', $typeFilter, true)) && db_table_exists($pdo, 'daily_financial_reports')) {
        try {
            $reportSql = "SELECT id, report_date, total_entrate, total_uscite, saldo, created_at,
                    CASE
                        WHEN report_date LIKE :start THEN 3
                        WHEN report_date LIKE :term THEN 2
                        ELSE 1
                    END AS relevance_score
                FROM daily_financial_reports
                WHERE report_date LIKE :term
                ORDER BY report_date DESC
                LIMIT :limit";
            $stmt = $pdo->prepare($reportSql);
            $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
            $stmt->bindValue(':start', $likeStart, PDO::PARAM_STR);
            $stmt->bindValue(':limit', min(8, $perTypeLimit), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = 'Report ' . (string) ($row['report_date'] ?? ('#' . (int) $row['id']));
                $subtitleParts = array_filter([
                    isset($row['saldo']) ? ('Saldo: ' . format_currency((float) $row['saldo'])) : null,
                ]);
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'report',
                    'title' => $title,
                    'subtitle' => implode(' • ', $subtitleParts),
                    'url' => base_url('modules/report/download_daily_report.php?date=' . urlencode((string) ($row['report_date'] ?? ''))),
                    'relevance_score' => (int) ($row['relevance_score'] ?? 1),
                    'date' => (string) ($row['created_at'] ?? ''),
                    'status' => null,
                    'icon' => 'fa-chart-line',
                    'badge' => 'Report',
                ];
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Ricerca report temporaneamente non disponibile.';
            error_log('Global search report failed: ' . $exception->getMessage());
        }
    }

    usort($items, static function (array $a, array $b): int {
        $score = ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        if ($score !== 0) {
            return $score;
        }
        $dateA = strtotime((string) ($a['date'] ?? '')) ?: 0;
        $dateB = strtotime((string) ($b['date'] ?? '')) ?: 0;
        return $dateB <=> $dateA;
    });

    $items = array_slice($items, 0, $limit);

    $groups = [];
    foreach ($items as $item) {
        $groups[$item['type']][] = $item;
    }

    return [
        'query' => $term,
        'items' => $items,
        'groups' => $groups,
        'warnings' => $warnings,
        'allowedTypes' => $allowedTypes,
    ];
}
