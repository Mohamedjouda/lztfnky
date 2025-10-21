<?php
$metrics = getDashboardMetrics($pdo);
$tokenPresent = getApiToken($pdo) !== null;
?>
<section>
    <h2>Dashboard</h2>
    <div class="cards">
        <div class="card">
            <h3>Active Listings</h3>
            <p><?php echo (int)($metrics['active'] ?? 0); ?></p>
        </div>
        <div class="card">
            <h3>Archived Listings</h3>
            <p><?php echo (int)($metrics['archived'] ?? 0); ?></p>
        </div>
        <div class="card">
            <h3>Last Fetch</h3>
            <p><?php echo $metrics['last_fetch'] ? htmlspecialchars($metrics['last_fetch']) : 'Never'; ?></p>
        </div>
        <div class="card">
            <h3>API Token</h3>
            <p><?php echo $tokenPresent ? 'Configured' : 'Missing'; ?></p>
        </div>
    </div>
</section>
<section>
    <h2>Quick Actions</h2>
    <p>The fetch worker syncs listings using the saved API token. Run it manually if you want to start syncing immediately:</p>
    <pre><code>php fetch_worker.php</code></pre>
    <p>Token status: <strong><?php echo $tokenPresent ? 'Ready' : 'Missing (set it on the Sources page)'; ?></strong></p>
</section>
