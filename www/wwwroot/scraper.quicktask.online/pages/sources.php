<?php
$tokenRow = getSettingRow($pdo, 'api_token');
$currentToken = $tokenRow['v'] ?? '';
$maskedToken = $currentToken !== '' ? maskToken($currentToken) : null;
$updatedAt = $tokenRow['updated_at'] ?? null;
?>
<section>
    <h2>API Token</h2>
    <p>Store your <strong>market</strong>-scoped OAuth token here. The background worker will read it to fetch Fortnite listings automatically.</p>
    <form method="post" class="source-form">
        <input type="hidden" name="action" value="save_token">
        <div class="filter-grid">
            <label>OAuth token
                <input type="text" name="token" value="" placeholder="Paste the token here">
            </label>
        </div>
        <p class="help-text">Leave the field empty and submit to clear the stored token.</p>
        <div class="filter-actions">
            <button type="submit" class="button primary">Save token</button>
        </div>
    </form>
</section>
<section>
    <h2>Current status</h2>
    <?php if ($maskedToken === null): ?>
        <p>No token saved yet. Add one above to enable automated syncing.</p>
    <?php else: ?>
        <ul class="status-list">
            <li><strong>Token:</strong> <?php echo htmlspecialchars($maskedToken); ?></li>
            <?php if ($updatedAt): ?>
                <li><strong>Last updated:</strong> <?php echo htmlspecialchars($updatedAt); ?></li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</section>
<section>
    <h2>How it works</h2>
    <p>The CLI worker (<code>php fetch_worker.php</code>) runs continuously on your server and calls the Fortnite endpoint with the default filters (<code>change_email=yes</code> and <code>smin=50</code>). Each successful run refreshes the <em>Active Listings</em> table and keeps archived rows intact.</p>
</section>
