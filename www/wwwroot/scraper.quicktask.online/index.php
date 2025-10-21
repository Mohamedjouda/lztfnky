<?php
require __DIR__ . '/bootstrap.php';

$page = $_GET['page'] ?? 'dashboard';
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'archive' || $action === 'restore') {
        $ids = [];
        foreach ($_POST['selected'] ?? [] as $rawId) {
            $rawId = trim((string)$rawId);
            if ($rawId !== '' && ctype_digit($rawId)) {
                $ids[] = $rawId;
            }
        }
        if (!$ids) {
            $messages[] = 'Select at least one listing before performing bulk actions.';
        } else {
            $targetStatus = $action === 'archive' ? 'archived' : 'active';
            updateListingStatus($pdo, $ids, $targetStatus);
            $messages[] = sprintf('Updated %d listing(s).', count($ids));
        }
        $page = $action === 'archive' ? 'active' : 'archive';
    } elseif ($action === 'save_token') {
        $token = trim($_POST['token'] ?? '');
        if ($token === '') {
            $messages[] = 'Token cleared. The fetch worker will not run until a new token is saved.';
            saveApiToken($pdo, '');
        } else {
            saveApiToken($pdo, $token);
            $messages[] = 'Token saved successfully.';
        }
        $page = 'sources';
    }
}

function renderHeader(string $currentPage): void
{
    $navItems = [
        'dashboard' => 'Dashboard',
        'active' => 'Active Listings',
        'archive' => 'Archive',
        'sources' => 'Sources',
    ];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Fortnite Listing Monitor</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
    <header class="top-bar">
        <h1>Fortnite Listing Monitor</h1>
        <nav>
            <ul>
                <?php foreach ($navItems as $slug => $label): ?>
                    <li><a href="?page=<?php echo htmlspecialchars($slug); ?>" class="<?php echo $slug === $currentPage ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </header>
    <main class="container">
    <?php
}

function renderFooter(): void
{
    ?>
    </main>
    <footer class="footer">
        <p>Auto fetch worker script located at <code>fetch_worker.php</code>. Run it via CLI for continuous syncing.</p>
    </footer>
    <script src="assets/script.js"></script>
    </body>
    </html>
    <?php
}

renderHeader($page);

if (!empty($messages)) {
    echo '<div class="alert">' . implode('<br>', array_map('htmlspecialchars', $messages)) . '</div>';
}

switch ($page) {
    case 'dashboard':
        require __DIR__ . '/pages/dashboard.php';
        break;
    case 'active':
        require __DIR__ . '/pages/active.php';
        break;
    case 'archive':
        require __DIR__ . '/pages/archive.php';
        break;
    case 'sources':
        require __DIR__ . '/pages/sources.php';
        break;
    default:
        echo '<p>Page not found.</p>';
        break;
}

renderFooter();
