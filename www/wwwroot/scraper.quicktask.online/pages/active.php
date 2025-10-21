<?php
$filters = parseListingFilters($_GET);
$perPage = getPerPage($_GET);
$currentPage = isset($_GET['pagination']) ? max(1, (int)$_GET['pagination']) : 1;
$result = getListings($pdo, 'active', $filters, $currentPage, $perPage);
$data = $result['data'];
$total = $result['total'];
$totalPages = max(1, (int)ceil($total / $perPage));
$queryParams = $_GET;
$filterQuery = $queryParams;
unset($filterQuery['page'], $filterQuery['pagination']);
$filterQueryString = http_build_query($filterQuery);
$exportAllUrl = 'export.php?status=active&scope=all' . ($filterQueryString ? '&' . $filterQueryString : '');
?>
<section>
    <h2>Active Listings</h2>
    <form method="get" class="filters">
        <input type="hidden" name="page" value="active">
        <div class="filter-grid">
            <label>Title
                <input type="text" name="title" value="<?php echo htmlspecialchars($filters['title']); ?>">
            </label>
            <label>Currency
                <input type="text" name="currency" value="<?php echo htmlspecialchars($filters['currency']); ?>" placeholder="usd">
            </label>
            <label>Min Price
                <input type="number" step="0.01" name="min_price" value="<?php echo htmlspecialchars((string)$filters['min_price']); ?>">
            </label>
            <label>Max Price
                <input type="number" step="0.01" name="max_price" value="<?php echo htmlspecialchars((string)$filters['max_price']); ?>">
            </label>
            <label>Min Skins
                <input type="number" name="min_skins" value="<?php echo htmlspecialchars((string)$filters['min_skins']); ?>">
            </label>
            <label>Max Skins
                <input type="number" name="max_skins" value="<?php echo htmlspecialchars((string)$filters['max_skins']); ?>">
            </label>
            <label>Rows per page
                <select name="per_page">
                    <?php foreach ([25, 50, 100, 200] as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo $option === $perPage ? 'selected' : ''; ?>><?php echo $option; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="filter-actions">
            <button type="submit">Apply Filters</button>
            <a class="button" href="?page=active">Reset</a>
        </div>
    </form>

    <form method="post" id="listings-form" class="table-form">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="page" value="active">
        <table class="data-table selectable">
            <thead>
            <tr>
                <th><input type="checkbox" id="select-all"></th>
                <th>Item ID</th>
                <th>Title</th>
                <th>URL</th>
                <th>Price</th>
                <th>Currency</th>
                <th>Level</th>
                <th>V-Bucks</th>
                <th>Skins</th>
                <th>Pickaxes</th>
                <th>Emotes</th>
                <th>Gliders</th>
                <th>Skins List</th>
                <th>Pickaxes List</th>
                <th>Emotes List</th>
                <th>Gliders List</th>
                <th>Skin Imgs</th>
                <th>Pickaxe Imgs</th>
                <th>Emote Imgs</th>
                <th>Glider Imgs</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($data)): ?>
                <tr><td colspan="20" class="empty">No listings found.</td></tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td><input type="checkbox" name="selected[]" value="<?php echo (int)$row['item_id']; ?>"></td>
                        <td><?php echo htmlspecialchars($row['item_id']); ?></td>
                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                        <td><a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank" rel="noopener">Open</a></td>
                        <td><?php echo htmlspecialchars($row['price']); ?></td>
                        <td><?php echo htmlspecialchars($row['currency']); ?></td>
                        <td><?php echo htmlspecialchars($row['fortnite_level']); ?></td>
                        <td><?php echo htmlspecialchars($row['vbucks']); ?></td>
                        <td><?php echo htmlspecialchars($row['skins_count']); ?></td>
                        <td><?php echo htmlspecialchars($row['pickaxes_count']); ?></td>
                        <td><?php echo htmlspecialchars($row['dances_count']); ?></td>
                        <td><?php echo htmlspecialchars($row['gliders_count']); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['skins_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['pickaxes_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['dances_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['gliders_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['images_skins_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['images_pickaxes_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['images_dances_json'])); ?></td>
                        <td><?php echo htmlspecialchars(jsonToList($row['images_gliders_json'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <div class="table-actions">
            <button type="submit" class="button primary" data-action="archive">Send to archive</button>
            <button type="button" class="button" data-export="selected" data-status="active">Export selected</button>
            <a class="button" href="<?php echo htmlspecialchars($exportAllUrl); ?>">Export all</a>
        </div>
    </form>

    <form method="post" id="export-form" action="export.php" class="hidden">
        <input type="hidden" name="status" value="active">
        <input type="hidden" name="scope" value="selected">
        <input type="hidden" name="ids" id="export-ids" value="">
    </form>

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
            $params = array_merge($queryParams, ['page' => 'active', 'pagination' => $i]);
            $url = '?' . http_build_query(array_merge($params, ['page' => 'active', 'pagination' => $i]));
            ?>
            <a href="<?php echo htmlspecialchars(updatePageParam(array_merge($filterQuery, ['page' => 'active']), $i)); ?>" class="<?php echo $i === $currentPage ? 'current' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
</section>
