<?php
require __DIR__ . '/bootstrap.php';

$status = $_REQUEST['status'] ?? 'active';
$scope = $_REQUEST['scope'] ?? 'all';

if (!in_array($status, ['active', 'archived'], true)) {
    $status = 'active';
}

if ($scope === 'selected') {
    $idsRaw = $_REQUEST['ids'] ?? '';
    if (is_array($idsRaw)) {
        $ids = [];
        foreach ($idsRaw as $value) {
            $value = trim((string)$value);
            if ($value !== '' && ctype_digit($value)) {
                $ids[] = $value;
            }
        }
    } else {
        $ids = [];
        foreach (explode(',', (string)$idsRaw) as $value) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value)) {
                $ids[] = $value;
            }
        }
    }
    $rows = fetchListingsByIds($pdo, $ids);
} else {
    $filters = parseListingFilters($_REQUEST);
    $rows = fetchAllListings($pdo, $status, $filters);
}

$filename = sprintf('fortnite-%s-%s.csv', $status, date('Ymd-His'));
$csv = exportListingsAsCsv($rows);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($csv));
echo $csv;
exit;
