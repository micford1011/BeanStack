<?php
require_once 'config.php';
require_login();

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard','products','transactions','alerts','reports','users','settings'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';
if ($page === 'users') require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_product') {
            require_admin();
            $id = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['product_code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $category = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
            $unit = trim($_POST['unit'] ?? 'pcs');
            $stock = max(0, (float)($_POST['current_stock'] ?? 0));
            $minimum = max(0, (float)($_POST['minimum_stock'] ?? 0));
            $cost = max(0, (float)($_POST['unit_cost'] ?? 0));
            if ($code === '' || $name === '') throw new RuntimeException('Product code and name are required.');
            if ($id) {
                $stmt = $pdo->prepare("UPDATE products SET product_code=?,name=?,category_id=?,unit=?,current_stock=?,minimum_stock=?,unit_cost=? WHERE id=?");
                $stmt->execute([$code,$name,$category,$unit,$stock,$minimum,$cost,$id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (product_code,name,category_id,unit,current_stock,minimum_stock,unit_cost) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$code,$name,$category,$unit,$stock,$minimum,$cost]);
            }
            flash('Ingredient saved successfully.');
            redirect('index.php?page=products');
        }
        if ($action === 'delete_product') {
            require_admin();
            $id = (int)$_POST['id'];
            $check = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE product_id=?");
            $check->execute([$id]);
            if ((int)$check->fetchColumn() > 0) throw new RuntimeException('This item has transaction history and cannot be deleted. You can keep it in the inventory instead.');
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            flash('Ingredient deleted.');
            redirect('index.php?page=products');
        }
        if ($action === 'transaction') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $type = $_POST['transaction_type'] ?? '';
            $quantity = (float)($_POST['quantity'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');
            if (!in_array($type, ['Stock In','Stock Out','Waste'], true) || $quantity <= 0) throw new RuntimeException('Choose a transaction type and enter a quantity greater than zero.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id=? FOR UPDATE");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) throw new RuntimeException('Ingredient not found.');
            if ($type !== 'Stock In' && $quantity > (float)$product['current_stock']) throw new RuntimeException('Not enough stock available for this transaction.');
            $newStock = $type === 'Stock In' ? (float)$product['current_stock'] + $quantity : (float)$product['current_stock'] - $quantity;
            $pdo->prepare("UPDATE products SET current_stock=? WHERE id=?")->execute([$newStock,$productId]);
            $pdo->prepare("INSERT INTO inventory_transactions (product_id,user_id,transaction_type,quantity,remarks) VALUES (?,?,?,?,?)")
                ->execute([$productId,$_SESSION['user_id'],$type,$quantity,$remarks]);
            $pdo->commit();
            flash('Inventory transaction recorded.');
            redirect('index.php?page=transactions');
        }
        if ($action === 'save_user') {
            require_admin();
            $name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            if ($name === '' || $username === '' || strlen($password) < 8) throw new RuntimeException('Enter a name, username, and password with at least 8 characters.');
            $stmt = $pdo->prepare("INSERT INTO users (full_name,username,password_hash,role) VALUES (?,?,?,?)");
            $stmt->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role]);
            flash('User account created.');
            redirect('index.php?page=users');
        }
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt->execute([$_SESSION['user_id']]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) throw new RuntimeException('Current password is incorrect.');
            if (strlen($new) < 8) throw new RuntimeException('New password must be at least 8 characters.');
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$_SESSION['user_id']]);
            flash('Password updated successfully.');
            redirect('index.php?page=settings');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex instanceof PDOException ? 'Unable to save. Check for duplicate product codes or usernames.' : $ex->getMessage(), 'danger');
        redirect('index.php?page=' . $page);
    }
}

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalValue = (float)$pdo->query("SELECT COALESCE(SUM(current_stock * unit_cost),0) FROM products")->fetchColumn();
$lowCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE current_stock <= minimum_stock")->fetchColumn();
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$products = $pdo->query("SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.name")->fetchAll();
$lowItems = $pdo->query("SELECT * FROM products WHERE current_stock <= minimum_stock ORDER BY current_stock ASC LIMIT 8")->fetchAll();
$recent = $pdo->query("SELECT t.*,p.name AS product_name,p.unit,u.full_name FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC LIMIT 7")->fetchAll();
$transactions = $pdo->query("SELECT t.*,p.name AS product_name,p.unit,u.full_name FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC LIMIT 100")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$users = is_admin() ? $pdo->query("SELECT id,full_name,username,role,created_at FROM users ORDER BY created_at DESC")->fetchAll() : [];
$stockIn = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_transactions WHERE transaction_type='Stock In' AND created_at >= CURDATE() - INTERVAL 7 DAY")->fetchColumn();
$stockOut = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_transactions WHERE transaction_type IN ('Stock Out','Waste') AND created_at >= CURDATE() - INTERVAL 7 DAY")->fetchColumn();
$chartRows = $pdo->query("SELECT DATE(created_at) AS day, SUM(CASE WHEN transaction_type='Stock In' THEN quantity ELSE 0 END) AS stock_in, SUM(CASE WHEN transaction_type IN ('Stock Out','Waste') THEN quantity ELSE 0 END) AS stock_out FROM inventory_transactions WHERE created_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(created_at) ORDER BY day")->fetchAll();
$chart = [];
for ($i=6; $i>=0; $i--) { $day=date('Y-m-d',strtotime("-$i days")); $chart[$day]=['in'=>0,'out'=>0]; }
foreach ($chartRows as $r) { if (isset($chart[$r['day']])) {$chart[$r['day']]['in']=(float)$r['stock_in'];$chart[$r['day']]['out']=(float)$r['stock_out'];} }
$chartLabels = array_map(fn($d)=>date('D',strtotime($d)),array_keys($chart));
$chartIn = array_column(array_values($chart),'in');
$chartOut = array_column(array_values($chart),'out');
$titles = ['dashboard'=>'Dashboard','products'=>'Products / Ingredients','transactions'=>'Stock In / Stock Out','alerts'=>'Low Stock Alerts','reports'=>'Inventory Reports','users'=>'User Management','settings'=>'Settings'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($titles[$page]) ?> | BeanStack</title><link rel="stylesheet" href="assets/style.css"><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script defer src="assets/app.js"></script></head>
<body>
<div class="app-shell">
<aside class="sidebar" id="sidebar">
  <a class="brand" href="index.php"><span class="brand-cup">☕</span><strong>BeanStack</strong><small>Inventory Management System</small></a>
  <div class="side-label">WORKSPACE</div>
  <nav class="nav-links">
    <a class="<?= $page==='dashboard'?'active':'' ?>" href="index.php"><span>⌂</span>Dashboard</a>
    <a class="<?= $page==='products'?'active':'' ?>" href="index.php?page=products"><span>▦</span>Products / Ingredients</a>
    <a class="<?= $page==='transactions'?'active':'' ?>" href="index.php?page=transactions"><span>⇄</span>Stock In / Out</a>
    <a class="<?= $page==='alerts'?'active':'' ?>" href="index.php?page=alerts"><span>♟</span>Low Stock Alerts <?php if($lowCount): ?><i class="nav-count"><?= $lowCount ?></i><?php endif; ?></a>
    <a class="<?= $page==='reports'?'active':'' ?>" href="index.php?page=reports"><span>▥</span>Reports</a>
    <?php if(is_admin()): ?><a class="<?= $page==='users'?'active':'' ?>" href="index.php?page=users"><span>♙</span>Users</a><?php endif; ?>
  </nav>
  <div class="sidebar-bottom"><div class="leaf-decoration">❧</div><a class="<?= $page==='settings'?'active':'' ?>" href="index.php?page=settings"><span>⚙</span>Settings</a><div class="sidebar-user"><div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'],0,1))) ?></div><div><strong><?= e($_SESSION['full_name']) ?></strong><small><?= e(ucfirst($_SESSION['role'])) ?></small></div><a class="logout" href="logout.php" title="Sign out">↗</a></div></div>
</aside>
<main class="main-area">
<header class="topbar"><button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">☰</button><div class="breadcrumbs">Café Marieta <span>/</span> <?= e($titles[$page]) ?></div><div class="top-user"><div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'],0,1))) ?></div><div><strong><?= e($_SESSION['full_name']) ?></strong><small><?= e(ucfirst($_SESSION['role'])) ?></small></div></div></header>
<div class="page-content">
<?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?><button class="alert-close" data-dismiss>×</button></div><?php endif; ?>

<?php if($page==='dashboard'): ?>
<section class="hero-banner"><div><span class="eyebrow light">CAFÉ MARIETA · DAILY INVENTORY</span><h1>Good <?= (date('G')<12?'morning':(date('G')<18?'afternoon':'evening')) ?>, <?= e(explode(' ',$_SESSION['full_name'])[0]) ?>!</h1><p>Here's your inventory overview for today.</p></div><div class="hero-coffee">☕</div></section>
<div class="stat-grid">
 <article class="stat-card"><div class="stat-icon">◉</div><div><span>Total Products</span><strong><?= $totalProducts ?></strong><small class="positive">Ingredients and supplies</small></div></article>
 <article class="stat-card"><div class="stat-icon">▣</div><div><span>Total Stock Value</span><strong>₱<?= number_format($totalValue,2) ?></strong><small class="positive">Based on current quantity</small></div></article>
 <article class="stat-card"><div class="stat-icon danger-icon">⚠</div><div><span>Low Stock Items</span><strong><?= $lowCount ?></strong><small class="<?= $lowCount?'negative':'positive' ?>"><?= $lowCount?'Restocking may be needed':'Stock levels look good' ?></small></div></article>
 <article class="stat-card"><div class="stat-icon">⇄</div><div><span>Today's Transactions</span><strong><?= $todayCount ?></strong><small class="muted">Stock movements recorded</small></div></article>
</div>
<div class="dashboard-grid"><section class="panel chart-panel"><div class="panel-heading"><div><h2>Stock Overview</h2><p>Incoming and outgoing quantities · last 7 days</p></div><span class="legend"><i class="legend-in"></i> Stock In <i class="legend-out"></i> Stock Out</span></div><div class="chart-wrap"><canvas id="stockChart"></canvas></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Low Stock Alerts</h2><p>Items at or below minimum level</p></div><a class="text-link" href="index.php?page=alerts">View all →</a></div><?php if(!$lowItems): ?><div class="empty-state">All ingredients are above their minimum levels.</div><?php else: ?><div class="mini-table"><?php foreach(array_slice($lowItems,0,5) as $p): ?><div class="mini-row"><span class="warning-dot">!</span><div class="mini-name"><strong><?= e($p['name']) ?></strong><small>Minimum: <?= e($p['minimum_stock']) ?> <?= e($p['unit']) ?></small></div><b class="<?= (float)$p['current_stock']<=0?'negative':'' ?>"><?= e($p['current_stock']) ?></b></div><?php endforeach; ?></div><?php endif; ?></section>
<section class="panel activity-panel"><div class="panel-heading"><div><h2>Recent Activity</h2><p>Latest inventory movements</p></div><a class="text-link" href="index.php?page=transactions">View all →</a></div><?php if(!$recent): ?><div class="empty-state">No transactions recorded yet.</div><?php else: ?><div class="activity-list"><?php foreach(array_slice($recent,0,5) as $t): ?><div class="activity-item"><div class="activity-icon <?= $t['transaction_type']==='Stock In'?'activity-in':'activity-out' ?>"><?= $t['transaction_type']==='Stock In'?'↙':'↗' ?></div><div><strong><?= e($t['transaction_type']) ?> · <?= e($t['product_name']) ?></strong><small><?= e(date('M d, Y · h:i A',strtotime($t['created_at']))) ?> · <?= e($t['full_name']??'Unknown user') ?></small></div></div><?php endforeach; ?></div><?php endif; ?></section></div>

<?php elseif($page==='products'): ?>
<div class="page-heading"><div><span class="eyebrow">INVENTORY CATALOG</span><h1>Products / Ingredients</h1><p>Manage the ingredients and supplies used by Café Marieta.</p></div><?php if(is_admin()): ?><button class="btn" data-modal-open="productModal">＋ Add Product</button><?php endif; ?></div>
<section class="panel table-panel"><div class="table-toolbar"><div class="search-box">⌕ <input id="tableSearch" placeholder="Search product or category..."></div><span class="muted"><?= count($products) ?> items</span></div><div class="table-scroll"><table id="dataTable"><thead><tr><th>Code</th><th>Product / Ingredient</th><th>Category</th><th>Unit</th><th>Current Stock</th><th>Min. Level</th><th>Status</th><?php if(is_admin()): ?><th>Actions</th><?php endif; ?></tr></thead><tbody><?php foreach($products as $p): $low=(float)$p['current_stock']<=(float)$p['minimum_stock']; ?><tr><td class="code"><?= e($p['product_code']) ?></td><td><strong><?= e($p['name']) ?></strong></td><td><?= e($p['category']??'Uncategorized') ?></td><td><?= e($p['unit']) ?></td><td><?= e($p['current_stock']) ?></td><td><?= e($p['minimum_stock']) ?></td><td><span class="status <?= $low?'status-low':'status-ok' ?>"><?= (float)$p['current_stock']<=0?'Out of stock':($low?'Low stock':'In stock') ?></span></td><?php if(is_admin()): ?><td><div class="action-group"><button class="icon-button edit-product" title="Edit" data-product="<?= e(json_encode($p,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>">✎</button><form method="post" onsubmit="return confirm('Delete this ingredient?')"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="icon-button" title="Delete">⌫</button></form></div></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>
<?php if(is_admin()): ?><div class="modal-backdrop" id="productModal"><div class="modal-card"><div class="modal-heading"><div><span class="eyebrow">CATALOG ENTRY</span><h2 id="productModalTitle">Add product</h2></div><button class="icon-button" data-modal-close>×</button></div><form method="post" class="form-grid" id="productForm"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" id="productId"><div><label>Product code</label><input name="product_code" id="productCode" required placeholder="e.g. P009"></div><div><label>Product name</label><input name="name" id="productName" required placeholder="Ingredient name"></div><div><label>Category</label><select name="category_id" id="productCategory"><option value="">Choose category</option><?php foreach($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div><div><label>Unit</label><select name="unit" id="productUnit"><?php foreach(['kg','g','liter','ml','bottle','can','pcs','pack','box'] as $u): ?><option><?= $u ?></option><?php endforeach; ?></select></div><div><label>Current stock</label><input type="number" min="0" step="0.01" name="current_stock" id="productStock" value="0" required></div><div><label>Minimum stock</label><input type="number" min="0" step="0.01" name="minimum_stock" id="productMinimum" value="0" required></div><div class="full-field"><label>Unit cost (₱)</label><input type="number" min="0" step="0.01" name="unit_cost" id="productCost" value="0" required></div><div class="modal-actions full-field"><button type="button" class="btn btn-outline" data-modal-close>Cancel</button><button class="btn">Save product</button></div></form></div></div><?php endif; ?>

<?php elseif($page==='transactions'): ?>
<div class="page-heading"><div><span class="eyebrow">STOCK MOVEMENT LOG</span><h1>Stock In / Stock Out</h1><p>Keep a clear, searchable record of inventory movement.</p></div><button class="btn" data-modal-open="transactionModal">＋ New Transaction</button></div>
<div class="quick-stats"><div class="quick-stat"><span>Stock In · 7 days</span><strong><?= number_format($stockIn,2) ?></strong></div><div class="quick-stat"><span>Stock Out / Waste · 7 days</span><strong><?= number_format($stockOut,2) ?></strong></div><div class="quick-stat"><span>Total logged transactions</span><strong><?= count($transactions) ?></strong></div></div>
<section class="panel table-panel"><div class="table-toolbar"><div class="search-box">⌕ <input id="tableSearch" placeholder="Search item, type, staff, remarks..."></div><span class="muted">Latest 100 records</span></div><div class="table-scroll"><table id="dataTable"><thead><tr><th>Date & Time</th><th>Type</th><th>Item</th><th>Quantity</th><th>Remarks</th><th>Recorded by</th></tr></thead><tbody><?php foreach($transactions as $t): ?><tr><td><?= e(date('M d, Y · h:i A',strtotime($t['created_at']))) ?></td><td><span class="status <?= $t['transaction_type']==='Stock In'?'status-ok':'status-low' ?>"><?= e($t['transaction_type']) ?></span></td><td><strong><?= e($t['product_name']) ?></strong></td><td><?= e($t['quantity']) ?> <?= e($t['unit']) ?></td><td><?= e($t['remarks']?:'—') ?></td><td><?= e($t['full_name']??'Unknown') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<div class="modal-backdrop" id="transactionModal"><div class="modal-card"><div class="modal-heading"><div><span class="eyebrow">UPDATE INVENTORY</span><h2>New transaction</h2></div><button class="icon-button" data-modal-close>×</button></div><form method="post" class="form-grid"><input type="hidden" name="action" value="transaction"><div class="full-field"><label>Ingredient</label><select name="product_id" required><?php foreach($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> · available <?= e($p['current_stock']) ?> <?= e($p['unit']) ?></option><?php endforeach; ?></select></div><div><label>Transaction type</label><select name="transaction_type" required><option>Stock In</option><option>Stock Out</option><option>Waste</option></select></div><div><label>Quantity</label><input name="quantity" type="number" min="0.01" step="0.01" required></div><div class="full-field"><label>Remarks</label><input name="remarks" maxlength="255" placeholder="e.g. Supplier delivery, daily usage, damaged item"></div><p class="form-note full-field">Stock levels update automatically when the transaction is saved.</p><div class="modal-actions full-field"><button type="button" class="btn btn-outline" data-modal-close>Cancel</button><button class="btn">Save transaction</button></div></form></div></div>

<?php elseif($page==='alerts'): ?>
<div class="page-heading"><div><span class="eyebrow">ATTENTION REQUIRED</span><h1>Low Stock Alerts</h1><p>Items that may need restocking before the next café shift.</p></div><a class="btn btn-outline" href="index.php?page=products">View all ingredients</a></div>
<section class="panel table-panel"><div class="table-toolbar"><div><h2>Stock level check</h2><p class="muted">Items are flagged when current stock is at or below the minimum level.</p></div><span class="status status-low"><?= $lowCount ?> alerts</span></div><div class="table-scroll"><table><thead><tr><th>Item</th><th>Current Stock</th><th>Minimum Level</th><th>Shortage</th><th>Status</th><th>Next step</th></tr></thead><tbody><?php foreach($lowItems as $p): ?><tr><td><strong><?= e($p['name']) ?></strong><small class="table-sub"><?= e($p['product_code']) ?></small></td><td><?= e($p['current_stock']) ?> <?= e($p['unit']) ?></td><td><?= e($p['minimum_stock']) ?> <?= e($p['unit']) ?></td><td><?= e(max(0,(float)$p['minimum_stock']-(float)$p['current_stock'])) ?> <?= e($p['unit']) ?></td><td><span class="status <?= (float)$p['current_stock']<=0?'status-low':'status-warning' ?>"><?= (float)$p['current_stock']<=0?'Out of stock':'Restock soon' ?></span></td><td><button class="btn btn-small" data-modal-open="transactionModal" data-stockin="<?= $p['id'] ?>">＋ Stock in</button></td></tr><?php endforeach; ?><?php if(!$lowItems): ?><tr><td colspan="6" class="empty-cell">No low-stock items. Everything is above its minimum level.</td></tr><?php endif; ?></tbody></table></div></section>
<div class="modal-backdrop" id="transactionModal"><div class="modal-card"><div class="modal-heading"><div><span class="eyebrow">RESTOCK ITEM</span><h2>Record incoming stock</h2></div><button class="icon-button" data-modal-close>×</button></div><form method="post" class="form-grid"><input type="hidden" name="action" value="transaction"><input type="hidden" name="transaction_type" value="Stock In"><div class="full-field"><label>Ingredient</label><select name="product_id" id="alertProduct" required><?php foreach($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> · available <?= e($p['current_stock']) ?> <?= e($p['unit']) ?></option><?php endforeach; ?></select></div><div class="full-field"><label>Quantity received</label><input name="quantity" type="number" min="0.01" step="0.01" required></div><div class="full-field"><label>Remarks</label><input name="remarks" placeholder="Supplier delivery / restock"></div><div class="modal-actions full-field"><button type="button" class="btn btn-outline" data-modal-close>Cancel</button><button class="btn">Save stock in</button></div></form></div></div>

<?php elseif($page==='reports'): ?>
<div class="page-heading"><div><span class="eyebrow">INVENTORY INSIGHTS</span><h1>Inventory Reports</h1><p>A snapshot of stock value, movement, and ingredient levels.</p></div><button class="btn btn-outline" onclick="window.print()">⇩ Print / Save PDF</button></div>
<div class="stat-grid report-stats"><article class="stat-card"><div class="stat-icon">▣</div><div><span>Total Stock Value</span><strong>₱<?= number_format($totalValue,2) ?></strong><small class="muted">Estimated at recorded unit cost</small></div></article><article class="stat-card"><div class="stat-icon">▦</div><div><span>Products Tracked</span><strong><?= $totalProducts ?></strong><small class="muted">All categories</small></div></article><article class="stat-card"><div class="stat-icon danger-icon">⚠</div><div><span>Needs Attention</span><strong><?= $lowCount ?></strong><small class="negative">Low / out of stock</small></div></article><article class="stat-card"><div class="stat-icon">⇄</div><div><span>Transactions · 7 days</span><strong><?= number_format($stockIn+$stockOut,2) ?></strong><small class="muted">Total units moved</small></div></article></div>
<div class="dashboard-grid report-grid"><section class="panel chart-panel"><div class="panel-heading"><div><h2>Stock Movement</h2><p>Quantity moved over the last 7 days</p></div></div><div class="chart-wrap"><canvas id="stockChart"></canvas></div></section><section class="panel"><div class="panel-heading"><div><h2>Stock by Category</h2><p>Current quantity grouped by category</p></div></div><div class="chart-wrap"><canvas id="categoryChart"></canvas></div></section></div>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Inventory Valuation</h2><p>Current quantity multiplied by unit cost.</p></div></div><div class="table-scroll"><table><thead><tr><th>Ingredient</th><th>Category</th><th>Current Stock</th><th>Unit Cost</th><th>Stock Value</th><th>Status</th></tr></thead><tbody><?php foreach($products as $p): ?><tr><td><strong><?= e($p['name']) ?></strong></td><td><?= e($p['category']??'Uncategorized') ?></td><td><?= e($p['current_stock']) ?> <?= e($p['unit']) ?></td><td>₱<?= number_format((float)$p['unit_cost'],2) ?></td><td><strong>₱<?= number_format((float)$p['current_stock']*(float)$p['unit_cost'],2) ?></strong></td><td><span class="status <?= (float)$p['current_stock']<=(float)$p['minimum_stock']?'status-low':'status-ok' ?>"><?= (float)$p['current_stock']<=(float)$p['minimum_stock']?'Low stock':'In stock' ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif($page==='users'): ?>
<div class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>User Management</h1><p>Create accounts and assign staff or administrator access.</p></div><button class="btn" data-modal-open="userModal">＋ Add User</button></div>
<div class="permission-note"><strong>Role permissions</strong><span><b>Administrator:</b> manage ingredients, users, reports, and all inventory activity.</span><span><b>Staff:</b> view ingredients, record stock movements, and view low-stock alerts and reports.</span></div>
<section class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Created</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?= e($u['full_name']) ?></strong></td><td><?= e($u['username']) ?></td><td><span class="status <?= $u['role']==='admin'?'status-admin':'status-ok' ?>"><?= e(ucfirst($u['role'])) ?></span></td><td><?= e(date('M d, Y',strtotime($u['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<div class="modal-backdrop" id="userModal"><div class="modal-card"><div class="modal-heading"><div><span class="eyebrow">NEW ACCOUNT</span><h2>Add a user</h2></div><button class="icon-button" data-modal-close>×</button></div><form method="post" class="form-grid"><input type="hidden" name="action" value="save_user"><div class="full-field"><label>Full name</label><input name="full_name" required></div><div class="full-field"><label>Username</label><input name="username" required></div><div class="full-field"><label>Temporary password (8+ characters)</label><input type="password" name="password" minlength="8" required></div><div class="full-field"><label>Role</label><select name="role"><option value="staff">Staff</option><option value="admin">Administrator</option></select></div><div class="modal-actions full-field"><button type="button" class="btn btn-outline" data-modal-close>Cancel</button><button class="btn">Create account</button></div></form></div></div>

<?php elseif($page==='settings'): ?>
<div class="page-heading"><div><span class="eyebrow">ACCOUNT PREFERENCES</span><h1>Settings</h1><p>Manage your sign-in credentials and account details.</p></div></div>
<div class="settings-grid"><section class="panel settings-profile"><div class="large-avatar"><?= e(strtoupper(substr($_SESSION['full_name'],0,1))) ?></div><h2><?= e($_SESSION['full_name']) ?></h2><p><?= e($_SESSION['username']) ?></p><span class="status <?= is_admin()?'status-admin':'status-ok' ?>"><?= e(ucfirst($_SESSION['role'])) ?></span><hr><p class="muted">BeanStack · Café Marieta Inventory System</p></section><section class="panel"><div class="panel-heading"><div><h2>Change password</h2><p>Use at least 8 characters.</p></div></div><form method="post" class="stack-form"><input type="hidden" name="action" value="change_password"><label>Current password</label><input type="password" name="current_password" required><label>New password</label><input type="password" name="new_password" minlength="8" required><button class="btn">Update password</button></form></section></div>
<?php endif; ?>
<footer class="footer"><span>© <?= date('Y') ?> BeanStack · Café Marieta</span><span>Inventory made clearer, one cup at a time.</span></footer>
</div></main></div>
<script>
window.beanStackData = {
 labels: <?= json_encode($chartLabels) ?>,
 stockIn: <?= json_encode($chartIn) ?>,
 stockOut: <?= json_encode($chartOut) ?>,
 categoryLabels: <?= json_encode(array_column($pdo->query("SELECT c.name, COALESCE(SUM(p.current_stock),0) AS total FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id,c.name ORDER BY total DESC")->fetchAll(),'name')) ?>,
 categoryValues: <?= json_encode(array_map('floatval',array_column($pdo->query("SELECT c.name, COALESCE(SUM(p.current_stock),0) AS total FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id,c.name ORDER BY total DESC")->fetchAll(),'total'))) ?>
};
</script></body></html>
