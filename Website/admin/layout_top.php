<?php
/**
 * Shared page chrome. A page sets $pageTitle (and optionally $pageSubtitle,
 * $needsMap, $headExtra) before including this.
 */
if (!defined('ATT_NAME')) {
    die('Direct access not permitted');
}

$attNav = [
    'index.php'      => ['Dashboard', '▦'],
    'live-map.php'   => ['Live Map', '◉'],
    'register.php'   => ['Attendance', '☑'],
    'routes.php'     => ['Routes', '⇢'],
    'trips.php'      => ['Outside Trips', '⚑'],
    'employees.php'  => ['Employees', '☺'],
    'monitors.php'   => ['Monitors', '⚐'],
    'drivers.php'    => ['Drivers', '⛟'],
    'geofences.php'  => ['Work Areas', '⬡'],
    'devices.php'    => ['Devices', '▭'],
    'security.php'   => ['Security', '⚿'],
    'whatsapp.php'   => ['WhatsApp', '✆'],
    'app-version.php' => ['App Version', '⬆'],
    'settings.php'   => ['Settings', '⚙'],
];
$attCurrent = basename($_SERVER['PHP_SELF']);
$flash = attFlash(null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? e($pageTitle) . ' · ' : ''; ?><?php echo e(ATT_NAME); ?></title>
<link rel="stylesheet" href="<?php echo ATT_ASSETS_URL; ?>/admin.css?v=<?php echo ATT_VERSION; ?>">
<?php if (!empty($needsMap)): ?>
<link rel="stylesheet" href="<?php echo ATT_ASSETS_URL; ?>/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="<?php echo ATT_ASSETS_URL; ?>/vendor/leaflet-draw/leaflet.draw.css">
<?php endif; ?>
<?php echo $headExtra ?? ''; ?>
<script>
window.ATT = {
    url: <?php echo json_encode(ATT_URL); ?>,
    csrf: <?php echo json_encode(attCsrfToken()); ?>,
    tileUrl: <?php echo json_encode($attTiles['tile_url']); ?>,
    tileAttribution: <?php echo json_encode($attTiles['attribution']); ?>,
    tileMaxZoom: <?php echo (int)$attTiles['max_zoom']; ?>,
    tileProvider: <?php echo json_encode($attTiles['provider']); ?>,
    tileFilter: <?php echo json_encode($attTiles['filter']); ?>,
    canManage: <?php echo $attCanManage ? 'true' : 'false'; ?>
};
</script>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">SST</span>
            <span class="brand-text">Attendance</span>
            <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="attNav">
                <span></span><span></span><span></span>
            </button>
        </div>
        <nav id="attNav">
            <?php foreach ($attNav as $file => $item): ?>
                <a href="<?php echo e($file); ?>" class="<?php echo $attCurrent === $file ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo $item[1]; ?></span>
                    <span><?php echo e($item[0]); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <a href="<?php echo e(ATT_ERP_URL); ?>/dashboard.php" class="back-erp">← Back to ERP</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1><?php echo e($pageTitle ?? 'Attendance'); ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p class="subtitle"><?php echo e($pageSubtitle); ?></p>
                <?php endif; ?>
            </div>
            <div class="topbar-right">
                <?php if (!$attCanManage): ?>
                    <span class="badge muted" title="Your role can view but not change attendance data">View only</span>
                <?php endif; ?>
                <div class="who">
                    <strong><?php echo e(trim($attAdmin['first_name'] . ' ' . $attAdmin['last_name'])); ?></strong>
                    <small><?php echo e($attAdmin['role_display_name']); ?></small>
                </div>
                <a class="btn ghost" href="logout.php">Sign out</a>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert <?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>

        <div class="content">
