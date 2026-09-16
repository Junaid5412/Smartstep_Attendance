<?php
/**
 * Work areas: draw a circle or polygon on the map and save it as a geofence.
 */

require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? null;

if ($action) {
    attCheckCsrf();
    attRequireManage();

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] === 'polygon' ? 'polygon' : 'circle';
        $description = trim($_POST['description'] ?? '');
        $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#2563eb';

        $centerLat = $centerLng = $radius = null;
        $polygon = null;
        $errors = [];

        if ($name === '') {
            $errors[] = 'Give the work area a name.';
        }

        if ($type === 'circle') {
            $centerLat = is_numeric($_POST['center_lat'] ?? null) ? (float)$_POST['center_lat'] : null;
            $centerLng = is_numeric($_POST['center_lng'] ?? null) ? (float)$_POST['center_lng'] : null;
            $radius = (int)($_POST['radius_m'] ?? 0);

            if (!attValidCoords($centerLat, $centerLng)) {
                $errors[] = 'Place the circle on the map first.';
            }
            if ($radius < 20 || $radius > 50000) {
                $errors[] = 'Radius must be between 20 m and 50 km.';
            }
        } else {
            $decoded = json_decode($_POST['polygon'] ?? '', true);
            $points = [];
            if (is_array($decoded)) {
                foreach ($decoded as $point) {
                    $lat = $point['lat'] ?? $point[0] ?? null;
                    $lng = $point['lng'] ?? $point[1] ?? null;
                    if (attValidCoords($lat, $lng)) {
                        $points[] = [round((float)$lat, 7), round((float)$lng, 7)];
                    }
                }
            }
            if (count($points) < 3) {
                $errors[] = 'Draw a shape with at least three corners.';
            }
            // Store the centroid too: the live map needs somewhere to centre on
            // without having to average the polygon in JavaScript every time.
            if ($points) {
                $centerLat = round(array_sum(array_column($points, 0)) / count($points), 7);
                $centerLng = round(array_sum(array_column($points, 1)) / count($points), 7);
                $polygon = json_encode($points);
            }
        }

        if ($errors) {
            attFlash('error', implode(' ', $errors));
        } elseif ($id > 0) {
            attDB()->prepare("
                UPDATE att_geofences
                SET name = ?, description = ?, type = ?, center_lat = ?, center_lng = ?,
                    radius_m = ?, polygon = ?, color = ?
                WHERE id = ?
            ")->execute([$name, $description ?: null, $type, $centerLat, $centerLng, $radius, $polygon, $color, $id]);
            attAudit('geofence_updated', 'att_geofence', $id, $name, 'admin', (int)$attAdmin['id']);
            attFlash('success', 'Work area "' . $name . '" updated.');
        } else {
            attDB()->prepare("
                INSERT INTO att_geofences
                    (name, description, type, center_lat, center_lng, radius_m, polygon, color, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$name, $description ?: null, $type, $centerLat, $centerLng, $radius, $polygon, $color, (int)$attAdmin['id']]);
            attAudit('geofence_created', 'att_geofence', (int)attDB()->lastInsertId(), $name, 'admin', (int)$attAdmin['id']);
            attFlash('success', 'Work area "' . $name . '" created.');
        }

        attRedirect('geofences.php');
    }

    if ($action === 'archive') {
        $id = (int)$_POST['id'];

        $inUse = attDB()->prepare("SELECT COUNT(*) FROM att_employee_settings WHERE geofence_id = ?");
        $inUse->execute([$id]);
        $count = (int)$inUse->fetchColumn();

        if ($count > 0) {
            attFlash('error', 'Cannot archive: ' . $count . ' employee(s) are still assigned to this area. ' .
                'Reassign them first.');
        } else {
            // Archived rather than deleted: past attendance rows reference it.
            attDB()->prepare("UPDATE att_geofences SET is_active = 0 WHERE id = ?")->execute([$id]);
            attAudit('geofence_archived', 'att_geofence', $id, null, 'admin', (int)$attAdmin['id']);
            attFlash('success', 'Work area archived.');
        }
        attRedirect('geofences.php');
    }
}

$geofences = attGeofenceList(true);
$archived = attDB()->query("
    SELECT id, name, type FROM att_geofences WHERE is_active = 0 ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$mapData = array_map('attGeofencePayload', $geofences);

$pageTitle = 'Work Areas';
$pageSubtitle = 'Geofences used to validate check-in and detect trips outside';
$needsMap = true;
include __DIR__ . '/layout_top.php';
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Draw a work area</h2>
            <p>Pick circle or polygon, draw on the map, then name it and save.</p>
        </div>
        <?php if ($attCanManage): ?>
            <button class="btn ghost small" type="button" id="clearDraw">Clear drawing</button>
        <?php endif; ?>
    </div>
    <div class="card-body tight">
        <div id="fenceMap" class="map"></div>
    </div>
    <div class="map-legend">
        <span><span class="dot inside"></span> saved area</span>
        <span><span class="dot outside"></span> being drawn</span>
        <span>Use the toolbar on the left of the map to draw a circle or a polygon.</span>
    </div>
</div>

<?php if ($attCanManage): ?>
<div class="card">
    <div class="card-head">
        <div>
            <h2 id="formHeading">New work area</h2>
            <p>The shape below is filled in from what you drew on the map.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="post" id="fenceForm">
            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="f_id" value="0">
            <input type="hidden" name="polygon" id="f_polygon">

            <div class="grid two">
                <div class="field">
                    <label for="f_name">Area name</label>
                    <input type="text" id="f_name" name="name" required placeholder="e.g. Al Sadd Site A">
                </div>
                <div class="field">
                    <label for="f_description">Description <span class="opt">(optional)</span></label>
                    <input type="text" id="f_description" name="description" placeholder="Building, gate, client name…">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="f_type">Shape</label>
                    <select id="f_type" name="type">
                        <option value="circle">Circle (centre + radius)</option>
                        <option value="polygon">Polygon (exact boundary)</option>
                    </select>
                </div>
                <div class="field" id="radiusField">
                    <label for="f_radius">Radius (metres)</label>
                    <input type="number" id="f_radius" name="radius_m" min="20" max="50000" value="150">
                    <div class="help">Editing this redraws the circle on the map.</div>
                </div>
                <div class="field">
                    <label for="f_color">Map colour</label>
                    <input type="color" id="f_color" name="color" value="#2563eb" style="height:38px;padding:4px">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="f_lat">Centre latitude</label>
                    <input type="text" id="f_lat" name="center_lat" readonly placeholder="draw on the map">
                </div>
                <div class="field">
                    <label for="f_lng">Centre longitude</label>
                    <input type="text" id="f_lng" name="center_lng" readonly placeholder="draw on the map">
                </div>
            </div>

            <button class="btn" type="submit">Save work area</button>
            <button class="btn ghost" type="button" id="resetForm">Cancel edit</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Saved work areas</h2>
            <p><?php echo count($geofences); ?> active</p>
        </div>
    </div>
    <div class="card-body tight">
        <?php if (!$geofences): ?>
            <div class="empty">No work areas yet. Draw one on the map above.</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Name</th><th>Shape</th><th>Size</th><th class="num">Assigned</th><th>Centre</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($geofences as $fence): ?>
                <tr>
                    <td class="who-cell">
                        <strong>
                            <span class="dot" style="background:<?php echo e($fence['color']); ?>"></span>
                            <?php echo e($fence['name']); ?>
                        </strong>
                        <?php if ($fence['description']): ?><small><?php echo e($fence['description']); ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge muted"><?php echo e($fence['type']); ?></span></td>
                    <td>
                        <?php if ($fence['type'] === 'circle'): ?>
                            <?php echo number_format((int)$fence['radius_m']); ?> m radius
                        <?php else: ?>
                            <?php echo count(attPolygonPoints($fence)); ?> corners
                        <?php endif; ?>
                    </td>
                    <td class="num"><?php echo (int)$fence['assigned_count']; ?></td>
                    <td><small><?php echo e(round((float)$fence['center_lat'], 5) . ', ' . round((float)$fence['center_lng'], 5)); ?></small></td>
                    <td style="white-space:nowrap">
                        <button class="btn ghost small" type="button"
                                data-edit-fence="<?php echo (int)$fence['id']; ?>">Edit</button>
                        <?php if ($attCanManage): ?>
                        <form method="post" style="display:inline"
                              data-confirm="Archive &quot;<?php echo e($fence['name']); ?>&quot;? It will no longer be available for assignment.">
                            <input type="hidden" name="csrf" value="<?php echo e(attCsrfToken()); ?>">
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="id" value="<?php echo (int)$fence['id']; ?>">
                            <button class="btn danger small" type="submit">Archive</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($archived): ?>
<div class="card">
    <div class="card-head"><div><h2>Archived</h2><p>Kept because past attendance records point at them.</p></div></div>
    <div class="card-body">
        <?php foreach ($archived as $fence): ?>
            <span class="badge muted" style="margin:0 6px 6px 0"><?php echo e($fence['name']); ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
window.ATT_FENCES = <?php echo json_encode($mapData, JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php
$footExtra = '<script src="' . ATT_ASSETS_URL . '/geofence-editor.js?v=' . ATT_VERSION . '"></script>';
include __DIR__ . '/layout_bottom.php';
?>
