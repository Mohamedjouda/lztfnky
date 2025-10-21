<?php
/**
 * Miscellaneous helper functions shared by the UI layer and the background worker.
 */

function ensureDatabaseSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(128) PRIMARY KEY,
        v TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS listings (
        item_id BIGINT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        url VARCHAR(255) NULL,
        price DECIMAL(12,2) NULL,
        currency VARCHAR(12) NULL,
        fortnite_level INT NULL,
        vbucks INT NULL,
        skins_count INT NULL,
        pickaxes_count INT NULL,
        dances_count INT NULL,
        gliders_count INT NULL,
        skins_json MEDIUMTEXT NULL,
        pickaxes_json MEDIUMTEXT NULL,
        dances_json MEDIUMTEXT NULL,
        gliders_json MEDIUMTEXT NULL,
        images_skins_json MEDIUMTEXT NULL,
        images_pickaxes_json MEDIUMTEXT NULL,
        images_dances_json MEDIUMTEXT NULL,
        images_gliders_json MEDIUMTEXT NULL,
        seller_username VARCHAR(255) NULL,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    ensureIndex($pdo, 'listings', 'idx_archived', 'archived');
    ensureIndex($pdo, 'listings', 'idx_price', 'price');
    ensureIndex($pdo, 'listings', 'idx_currency', 'currency');
    ensureIndex($pdo, 'listings', 'idx_title', 'title');
}

function ensureIndex(PDO $pdo, string $table, string $indexName, string $column): void
{
    $stmt = $pdo->prepare('SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index');
    $stmt->execute([
        ':table' => $table,
        ':index' => $indexName,
    ]);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('CREATE INDEX %s ON %s (%s)', $indexName, $table, $column));
    }
}

function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT v FROM settings WHERE k = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return $default;
    }
    return $value;
}

function getSettingRow(PDO $pdo, string $key): ?array
{
    $stmt = $pdo->prepare('SELECT k, v, updated_at FROM settings WHERE k = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (k, v) VALUES (:key, :value) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function getApiToken(PDO $pdo): ?string
{
    $token = trim((string)getSetting($pdo, 'api_token', ''));
    return $token !== '' ? $token : null;
}

function saveApiToken(PDO $pdo, string $token): void
{
    if (trim($token) === '') {
        $stmt = $pdo->prepare('DELETE FROM settings WHERE k = :key');
        $stmt->execute([':key' => 'api_token']);
        return;
    }

    setSetting($pdo, 'api_token', trim($token));
}

function runFetchOnce(PDO $pdo, array $config, ?callable $logger = null): array
{
    $summary = [
        'imported' => 0,
        'token_present' => false,
        'errors' => [],
    ];

    $token = getApiToken($pdo);
    if (!$token) {
        if ($logger) {
            $logger('No API token stored. Set it on the Sources page before running the worker.');
        }
        return $summary;
    }

    $summary['token_present'] = true;

    try {
        $summary['imported'] = importListingsFromApi($pdo, $config, $token, $logger);
        setSetting($pdo, 'last_fetch_at', date('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        $summary['errors'][] = $e->getMessage();
        if ($logger) {
            $logger('Error during fetch: ' . $e->getMessage());
        }
    }

    return $summary;
}

function runFetchLoop(PDO $pdo, array $config, ?callable $logger = null): void
{
    $interval = (int)($config['fetch']['interval_seconds'] ?? 600);
    if ($interval < 60) {
        $interval = 60; // Avoid hammering the API.
    }

    if ($logger) {
        $logger(sprintf('Starting fetch loop. Interval: %d seconds.', $interval));
    }

    while (true) {
        $cycleStarted = microtime(true);
        $summary = runFetchOnce($pdo, $config, $logger);
        if ($logger) {
            $logger(sprintf('Cycle completed. Token present: %s, Imported: %d, Errors: %d', $summary['token_present'] ? 'yes' : 'no', $summary['imported'], count($summary['errors'])));
        }

        $elapsed = microtime(true) - $cycleStarted;
        $sleep = max(0, $interval - (int)$elapsed);
        if ($logger) {
            $logger(sprintf('Sleeping for %d seconds...', $sleep));
        }
        sleep($sleep);
    }
}

function importListingsFromApi(PDO $pdo, array $config, string $token, ?callable $logger = null): int
{
    $baseUrl = rtrim($config['api']['base_url'], '/');
    $endpoint = $config['api']['endpoint'];
    $filters = $config['api']['default_filters'] ?? [];
    $timeout = $config['api']['timeout'] ?? 300;
    $maxPages = $config['fetch']['max_pages'] ?? null;
    $delay = (int)($config['fetch']['page_delay_microseconds'] ?? 250000);

    $page = 1;
    $imported = 0;
    $hasNext = true;

    while ($hasNext) {
        if ($maxPages !== null && $page > $maxPages) {
            if ($logger) {
                $logger(sprintf('Reached configured max pages (%d); stopping pagination.', $maxPages));
            }
            break;
        }

        $query = array_merge($filters, [
            'page' => $page,
        ]);

        $url = $baseUrl . $endpoint . '?' . http_build_query($query);
        if ($logger) {
            $logger(sprintf('Fetching page %d from %s', $page, $url));
        }

        $response = httpRequestJson($url, $timeout, $token);

        if (!is_array($response)) {
            throw new RuntimeException('Unexpected response structure.');
        }

        $items = $response['items'] ?? [];
        foreach ($items as $item) {
            upsertListing($pdo, $item);
            $imported++;
        }

        $hasNext = !empty($response['hasNextPage']);
        $page++;

        if ($hasNext && $delay > 0) {
            usleep($delay);
        }
    }

    return $imported;
}

function httpRequestJson(string $url, int $timeout, string $token): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException('HTTP ' . $code . ': ' . $response, $code);
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Failed to decode JSON: ' . json_last_error_msg());
    }

    return $decoded;
}

function upsertListing(PDO $pdo, array $item): void
{
    $fortnite = mapFortniteMetadata($item);

    $stmt = $pdo->prepare('INSERT INTO listings (
        item_id, title, url, price, currency, fortnite_level, vbucks, skins_count,
        pickaxes_count, dances_count, gliders_count, skins_json, pickaxes_json,
        dances_json, gliders_json, images_skins_json, images_pickaxes_json,
        images_dances_json, images_gliders_json, seller_username, archived
    ) VALUES (
        :item_id, :title, :url, :price, :currency, :fortnite_level, :vbucks, :skins_count,
        :pickaxes_count, :dances_count, :gliders_count, :skins_json, :pickaxes_json,
        :dances_json, :gliders_json, :images_skins_json, :images_pickaxes_json,
        :images_dances_json, :images_gliders_json, :seller_username, 0
    ) ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        url = VALUES(url),
        price = VALUES(price),
        currency = VALUES(currency),
        fortnite_level = VALUES(fortnite_level),
        vbucks = VALUES(vbucks),
        skins_count = VALUES(skins_count),
        pickaxes_count = VALUES(pickaxes_count),
        dances_count = VALUES(dances_count),
        gliders_count = VALUES(gliders_count),
        skins_json = VALUES(skins_json),
        pickaxes_json = VALUES(pickaxes_json),
        dances_json = VALUES(dances_json),
        gliders_json = VALUES(gliders_json),
        images_skins_json = VALUES(images_skins_json),
        images_pickaxes_json = VALUES(images_pickaxes_json),
        images_dances_json = VALUES(images_dances_json),
        images_gliders_json = VALUES(images_gliders_json),
        seller_username = VALUES(seller_username),
        archived = 0,
        updated_at = CURRENT_TIMESTAMP');

    $stmt->execute([
        'item_id' => $item['item_id'] ?? 0,
        'title' => $item['title'] ?? 'Untitled listing',
        'url' => buildListingUrl($item),
        'price' => $item['price'] ?? null,
        'currency' => $item['price_currency'] ?? null,
        'fortnite_level' => $item['fortnite_level'] ?? null,
        'vbucks' => $item['fortnite_balance'] ?? null,
        'skins_count' => $item['fortnite_skin_count'] ?? null,
        'pickaxes_count' => $item['fortnite_pickaxe_count'] ?? null,
        'dances_count' => $item['fortnite_dance_count'] ?? null,
        'gliders_count' => $item['fortnite_glider_count'] ?? null,
        'skins_json' => $fortnite['skins_json'],
        'pickaxes_json' => $fortnite['pickaxes_json'],
        'dances_json' => $fortnite['dances_json'],
        'gliders_json' => $fortnite['gliders_json'],
        'images_skins_json' => $fortnite['images_skins_json'],
        'images_pickaxes_json' => $fortnite['images_pickaxes_json'],
        'images_dances_json' => $fortnite['images_dances_json'],
        'images_gliders_json' => $fortnite['images_gliders_json'],
        'seller_username' => $item['seller']['username'] ?? null,
    ]);
}

function mapFortniteMetadata(array $item): array
{
    $skins = $item['fortniteSkins'] ?? [];
    $pickaxes = $item['fortnitePickaxe'] ?? [];
    $dances = $item['fortniteDance'] ?? [];
    $gliders = $item['fortniteGliders'] ?? [];

    return [
        'skins_json' => json_encode(array_column_safe($skins, 'title'), JSON_UNESCAPED_UNICODE),
        'pickaxes_json' => json_encode(array_column_safe($pickaxes, 'title'), JSON_UNESCAPED_UNICODE),
        'dances_json' => json_encode(array_column_safe($dances, 'title'), JSON_UNESCAPED_UNICODE),
        'gliders_json' => json_encode(array_column_safe($gliders, 'title'), JSON_UNESCAPED_UNICODE),
        'images_skins_json' => json_encode(array_column_safe($skins, 'image'), JSON_UNESCAPED_UNICODE),
        'images_pickaxes_json' => json_encode(array_column_safe($pickaxes, 'image'), JSON_UNESCAPED_UNICODE),
        'images_dances_json' => json_encode(array_column_safe($dances, 'image'), JSON_UNESCAPED_UNICODE),
        'images_gliders_json' => json_encode(array_column_safe($gliders, 'image'), JSON_UNESCAPED_UNICODE),
    ];
}

function array_column_safe(array $rows, string $column): array
{
    $values = [];
    foreach ($rows as $row) {
        if (isset($row[$column]) && $row[$column] !== '' && $row[$column] !== null) {
            $values[] = $row[$column];
        }
    }
    return $values;
}

function buildListingUrl(array $item): string
{
    $itemId = $item['item_id'] ?? null;
    if ($itemId) {
        return 'https://lzt.market/item/' . $itemId;
    }
    return 'https://lzt.market/fortnite';
}

function getDashboardMetrics(PDO $pdo): array
{
    $stats = [
        'active' => 0,
        'archived' => 0,
        'last_fetch' => getSetting($pdo, 'last_fetch_at'),
    ];

    $stmt = $pdo->query('SELECT archived, COUNT(*) as total FROM listings GROUP BY archived');
    foreach ($stmt as $row) {
        $key = ((int)$row['archived'] === 1) ? 'archived' : 'active';
        $stats[$key] = (int)$row['total'];
    }

    return $stats;
}

function getListings(PDO $pdo, string $status, array $filters, int $page, int $perPage): array
{
    [$where, $params] = buildListingsWhereClause($status, $filters);

    $offset = ($page - 1) * $perPage;
    $query = 'SELECT * FROM listings ' . $where . ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM listings ' . $where);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    return [
        'data' => $data,
        'total' => $total,
    ];
}

function buildListingsWhereClause(string $status, array $filters): array
{
    $archived = $status === 'archived' ? 1 : 0;
    $clauses = ['WHERE archived = :archived'];
    $params = [':archived' => $archived];

    if (!empty($filters['title'])) {
        $clauses[] = 'AND title LIKE :title';
        $params[':title'] = '%' . $filters['title'] . '%';
    }

    if (!empty($filters['currency'])) {
        $clauses[] = 'AND currency = :currency';
        $params[':currency'] = $filters['currency'];
    }

    if ($filters['min_price'] !== null) {
        $clauses[] = 'AND price >= :min_price';
        $params[':min_price'] = $filters['min_price'];
    }

    if ($filters['max_price'] !== null) {
        $clauses[] = 'AND price <= :max_price';
        $params[':max_price'] = $filters['max_price'];
    }

    if ($filters['min_skins'] !== null) {
        $clauses[] = 'AND skins_count >= :min_skins';
        $params[':min_skins'] = $filters['min_skins'];
    }

    if ($filters['max_skins'] !== null) {
        $clauses[] = 'AND skins_count <= :max_skins';
        $params[':max_skins'] = $filters['max_skins'];
    }

    return [implode(' ', $clauses), $params];
}

function parseListingFilters(array $input): array
{
    return [
        'title' => trim($input['title'] ?? ''),
        'currency' => trim($input['currency'] ?? ''),
        'min_price' => $input['min_price'] !== '' ? (float)$input['min_price'] : null,
        'max_price' => $input['max_price'] !== '' ? (float)$input['max_price'] : null,
        'min_skins' => $input['min_skins'] !== '' ? (int)$input['min_skins'] : null,
        'max_skins' => $input['max_skins'] !== '' ? (int)$input['max_skins'] : null,
    ];
}

function updateListingStatus(PDO $pdo, array $itemIds, string $status): void
{
    if (!$itemIds) {
        return;
    }

    $archived = $status === 'archived' ? 1 : 0;
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare(sprintf('UPDATE listings SET archived = ?, updated_at = CURRENT_TIMESTAMP WHERE item_id IN (%s)', $placeholders));
    $params = array_map(static fn($value) => (string)$value, $itemIds);
    array_unshift($params, $archived);
    $stmt->execute($params);
}

function fetchListingsByIds(PDO $pdo, array $itemIds): array
{
    if (!$itemIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare(sprintf('SELECT * FROM listings WHERE item_id IN (%s)', $placeholders));
    $stmt->execute(array_map(static fn($value) => (string)$value, $itemIds));
    return $stmt->fetchAll();
}

function fetchAllListings(PDO $pdo, string $status, array $filters): array
{
    [$where, $params] = buildListingsWhereClause($status, $filters);
    $stmt = $pdo->prepare('SELECT * FROM listings ' . $where . ' ORDER BY updated_at DESC');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function exportListingsAsCsv(array $rows): string
{
    $headers = [
        'Item ID','Title','URL','Price','Currency','Level','V-Bucks','Skins','Pickaxes','Emotes','Gliders',
        'Skins List','Pickaxes List','Emotes List','Gliders List','Skin Imgs','Pickaxe Imgs','Emote Imgs','Glider Imgs'
    ];

    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, $headers);

    foreach ($rows as $row) {
        fputcsv($fh, [
            $row['item_id'] ?? '',
            $row['title'] ?? '',
            $row['url'] ?? '',
            $row['price'] ?? '',
            $row['currency'] ?? '',
            $row['fortnite_level'] ?? '',
            $row['vbucks'] ?? '',
            $row['skins_count'] ?? '',
            $row['pickaxes_count'] ?? '',
            $row['dances_count'] ?? '',
            $row['gliders_count'] ?? '',
            $row['skins_json'] ?? '',
            $row['pickaxes_json'] ?? '',
            $row['dances_json'] ?? '',
            $row['gliders_json'] ?? '',
            $row['images_skins_json'] ?? '',
            $row['images_pickaxes_json'] ?? '',
            $row['images_dances_json'] ?? '',
            $row['images_gliders_json'] ?? '',
        ]);
    }

    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);

    return $csv;
}

function getPerPage(array $input, int $default = 25): int
{
    $allowed = [25, 50, 100, 200];
    $value = isset($input['per_page']) ? (int)$input['per_page'] : $default;
    if (!in_array($value, $allowed, true)) {
        $value = $default;
    }
    return $value;
}

function jsonToList(?string $json): string
{
    if (!$json) {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $json;
    }
    return implode(', ', $data);
}

function updatePageParam(array $queryParams, int $pageNumber): string
{
    $params = $queryParams;
    $params['pagination'] = $pageNumber;
    if (empty($params['page'])) {
        $params['page'] = 'active';
    }
    return '?' . http_build_query($params);
}

function maskToken(string $token): string
{
    $length = strlen($token);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }
    return substr($token, 0, 4) . str_repeat('*', $length - 8) . substr($token, -4);
}
