<?php
// admin/cottages.php — Manage Cottages + Single Thumbnail
require_once 'auth.php';
$page_title = 'Manage Cottages';
$db  = getDB();
$msg = '';
$edit_cottage = null;
$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────────
// TOGGLE AVAILABILITY
// ─────────────────────────────────────────────
if (isset($_GET['toggle'])) {
    $db->prepare("UPDATE cottages SET is_available = 1 - is_available WHERE id = ?")
       ->execute([(int)$_GET['toggle']]);
    header('Location: cottages.php'); exit;
}

// ─────────────────────────────────────────────
// DELETE THUMBNAIL ONLY
// ─────────────────────────────────────────────
if (isset($_GET['del_thumb'])) {
    $cid = (int)$_GET['del_thumb'];
    $row = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? ORDER BY sort_order ASC LIMIT 1");
    $row->execute([$cid]);
    $photo = $row->fetch();
    if ($photo) {
        $path = '../uploads/cottages/' . $photo['filename'];
        if (file_exists($path)) @unlink($path);
        $db->prepare("DELETE FROM cottage_images WHERE cottage_id=?")->execute([$cid]);
    }
    header("Location: cottages.php?action=edit&id=$cid&saved=1"); exit;
}

// ─────────────────────────────────────────────
// DELETE COTTAGE
// ─────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $chk = $db->prepare("SELECT COUNT(*) FROM reservations WHERE cottage_id=? AND status!='Cancelled'");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) {
        $msg = 'error:Cannot delete — this cottage has active reservations.';
    } else {
        // Delete thumbnail from disk
        $img = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? LIMIT 1");
        $img->execute([$id]);
        $photo = $img->fetch();
        if ($photo) {
            $path = '../uploads/cottages/' . $photo['filename'];
            if (file_exists($path)) @unlink($path);
        }
        $db->prepare("DELETE FROM cottage_images WHERE cottage_id=?")->execute([$id]);
        $db->prepare("DELETE FROM cottages WHERE id=?")->execute([$id]);
        $msg = 'success:Cottage deleted successfully.';
    }
}

// ─────────────────────────────────────────────
// LOAD FOR EDIT
// ─────────────────────────────────────────────
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM cottages WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $edit_cottage = $stmt->fetch();
}

// ─────────────────────────────────────────────
// SAVE — ADD / EDIT + SINGLE THUMBNAIL UPLOAD
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = clean($_POST['name']           ?? '');
    $category    = clean($_POST['category']       ?? 'Bahay Kubo');
    $description = clean($_POST['description']    ?? '');
    $price       = (float)($_POST['price_per_night'] ?? 0);
    $capacity    = (int)($_POST['capacity']       ?? 1);
    $amenities   = clean($_POST['amenities']      ?? '');
    $cottage_id  = (int)($_POST['cottage_id']     ?? 0);

    if (!$name || !$price || !$capacity) {
        $msg = 'error:Please fill in all required fields (Name, Price, Capacity).';
    } else {
        if ($cottage_id) {
            $db->prepare("UPDATE cottages SET name=?,category=?,description=?,price_per_night=?,capacity=?,amenities=? WHERE id=?")
               ->execute([$name,$category,$description,$price,$capacity,$amenities,$cottage_id]);
            $msg = 'success:Cottage updated successfully.';
        } else {
            $db->prepare("INSERT INTO cottages (name,category,description,price_per_night,capacity,amenities) VALUES (?,?,?,?,?,?)")
               ->execute([$name,$category,$description,$price,$capacity,$amenities]);
            $cottage_id = $db->lastInsertId();
            $msg = 'success:Cottage added successfully.';
        }

        // ── SINGLE THUMBNAIL UPLOAD ──
        if (!empty($_FILES['thumbnail']['name']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $allowed   = ['image/jpeg','image/png','image/gif','image/webp'];
            $max_size  = 5 * 1024 * 1024; // 5MB
            $file_type = $_FILES['thumbnail']['type'];
            $file_size = $_FILES['thumbnail']['size'];

            if (in_array($file_type, $allowed) && $file_size <= $max_size) {
                $upload_dir = '../uploads/cottages/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                // Delete old thumbnail first
                $old = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? LIMIT 1");
                $old->execute([$cottage_id]);
                $old_photo = $old->fetch();
                if ($old_photo) {
                    $old_path = $upload_dir . $old_photo['filename'];
                    if (file_exists($old_path)) @unlink($old_path);
                    $db->prepare("DELETE FROM cottage_images WHERE cottage_id=?")->execute([$cottage_id]);
                }

                // Save new thumbnail
                $ext      = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
                $ext      = in_array($ext,['jpg','jpeg','png','gif','webp']) ? $ext : 'jpg';
                $filename = 'cottage_' . $cottage_id . '_thumb_' . time() . '.' . $ext;
                $dest     = $upload_dir . $filename;

                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dest)) {
                    $db->prepare("INSERT INTO cottage_images (cottage_id,filename,label,sort_order) VALUES (?,?,'Thumbnail',0)")
                       ->execute([$cottage_id, $filename]);
                    $msg = str_replace('successfully.', 'successfully with thumbnail.', $msg);
                }
            } else {
                $msg = str_replace('success:', 'error:', $msg);
                $msg = 'error:File must be JPG/PNG/GIF/WEBP and under 5MB.';
            }
        }

        header("Location: cottages.php?action=edit&id=$cottage_id&saved=1"); exit;
    }
}

// ─────────────────────────────────────────────
// FETCH ALL COTTAGES WITH THUMBNAIL
// ─────────────────────────────────────────────
$cottages = $db->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM reservations r WHERE r.cottage_id=c.id AND r.status='Confirmed') AS active_bookings,
        (SELECT filename FROM cottage_images ci WHERE ci.cottage_id=c.id ORDER BY sort_order ASC LIMIT 1) AS thumb
    FROM cottages c
    ORDER BY FIELD(c.category,'Bahay Kubo','Open Cottage','Kubo Premium'), c.name ASC
")->fetchAll();

// Fetch current thumbnail for edit
$edit_thumb = null;
if ($edit_cottage) {
    $ts = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? ORDER BY sort_order ASC LIMIT 1");
    $ts->execute([$edit_cottage['id']]);
    $edit_thumb = $ts->fetchColumn();
}

// Type map for placeholders
$type_map = ['Bahay Kubo'=>'bahay_kubo','Open Cottage'=>'open_cottage','Kubo Premium'=>'kubo_premium'];

[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['',''];
if (isset($_GET['saved'])) { $msg_type = 'success'; $msg_text = 'Cottage saved successfully.'; }

include 'partials/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - S-Five Inland Resort</title>
    <link rel="stylesheet" href="admin.css">
</head>
<?php if ($msg_text): ?>
<div class="alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
<?php endif; ?>

<!-- ══════════ ADD / EDIT FORM ══════════ -->
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="margin-bottom:2rem;">
    <div class="card-header">
        <h3><?= $action==='edit' ? '✏️ Edit — '.htmlspecialchars($edit_cottage['name']??'') : '➕ Add New Cottage' ?></h3>
        <a href="cottages.php" class="btn-sm">← Back</a>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="admin-form">
            <input type="hidden" name="cottage_id" value="<?= $edit_cottage['id'] ?? '' ?>">

            <!-- Basic Info -->
            <div class="form-row-2">
                <div class="form-group">
                    <label>Cottage Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Bahay Kubo 1"
                           value="<?= htmlspecialchars($edit_cottage['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category">
                        <?php foreach (['Bahay Kubo','Open Cottage','Kubo Premium'] as $cat): ?>
                        <option value="<?= $cat ?>" <?= ($edit_cottage['category'] ?? 'Bahay Kubo')===$cat?'selected':'' ?>>
                            <?= $cat ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="Describe the cottage..."><?= htmlspecialchars($edit_cottage['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Price per Night (₱) *</label>
                    <input type="number" name="price_per_night" required min="1" step="0.01"
                           value="<?= $edit_cottage['price_per_night'] ?? '' ?>" placeholder="800">
                </div>
                <div class="form-group">
                    <label>Max Capacity (guests) *</label>
                    <input type="number" name="capacity" required min="1" max="100"
                           value="<?= $edit_cottage['capacity'] ?? '' ?>" placeholder="4">
                </div>
            </div>

            <div class="form-group">
                <label>Amenities <small>(comma-separated)</small></label>
                <input type="text" name="amenities" placeholder="Electric Fan, Veranda, Mosquito net"
                       value="<?= htmlspecialchars($edit_cottage['amenities'] ?? '') ?>">
            </div>

            <!-- ── SINGLE THUMBNAIL UPLOAD ── -->
            <div class="form-group">
                <label>Cottage Thumbnail Photo</label>

                <?php if ($edit_thumb): ?>
                <!-- Show current thumbnail -->
                <div class="current-thumb-wrap">
                    <div class="current-thumb">
                        <img src="../uploads/cottages/<?= htmlspecialchars($edit_thumb) ?>"
                             alt="Current thumbnail" class="thumb-preview-img">
                        <div class="thumb-label">Current Photo</div>
                    </div>
                    <a href="cottages.php?del_thumb=<?= $edit_cottage['id'] ?>"
                       class="btn-del-thumb"
                       onclick="return confirm('Remove this thumbnail?')">🗑️ Remove Photo</a>
                </div>
                <p class="thumb-hint">Upload a new photo below to replace the current one:</p>
                <?php else: ?>
                <!-- No thumbnail yet — show placeholder preview -->
                <?php
                    $prev_type = $type_map[$edit_cottage['category'] ?? 'Bahay Kubo'] ?? 'bahay_kubo';
                    $prev_name = urlencode($edit_cottage['name'] ?? 'Cottage');
                ?>
                <div class="current-thumb-wrap">
                    <div class="current-thumb placeholder-thumb">
                        <img src="../images/cottage_placeholder.php?type=<?= $prev_type ?>&name=<?= $prev_name ?>&n=<?= $edit_cottage['id'] ?? 1 ?>"
                             alt="Placeholder" class="thumb-preview-img">
                        <div class="thumb-label">Auto Placeholder</div>
                    </div>
                </div>
                <p class="thumb-hint">No photo uploaded yet. Upload one below:</p>
                <?php endif; ?>

                <!-- File input -->
                <div class="file-upload-box" id="fileUploadBox">
                    <input type="file" name="thumbnail" id="thumbnailInput" accept="image/jpeg,image/png,image/gif,image/webp"
                           onchange="previewThumb(this)">
                    <div class="file-upload-ui" onclick="document.getElementById('thumbnailInput').click()">
                        <span class="upload-icon">📷</span>
                        <p><strong>Click to upload</strong> or drag & drop</p>
                        <small>JPG, PNG, GIF, WEBP — max 5MB</small>
                    </div>
                    <!-- Live preview -->
                    <div class="new-thumb-preview" id="newThumbPreview" style="display:none;">
                        <img id="newThumbImg" src="" alt="New photo preview">
                        <div class="thumb-label">New Photo (preview)</div>
                        <button type="button" onclick="clearThumb()" class="clear-thumb-btn">✕ Cancel</button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-save">
                <?= $action==='edit' ? '💾 Save Changes' : '➕ Add Cottage' ?>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ COTTAGES LIST ══════════ -->
<div class="card">
    <div class="card-header">
        <h3>All Cottages <span class="count-badge"><?= count($cottages) ?></span></h3>
        <?php if ($action !== 'add'): ?>
        <a href="cottages.php?action=add" class="btn-add">➕ Add Cottage</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="cottages-admin-grid">
            <?php foreach ($cottages as $c):
                $img_type = $type_map[$c['category']] ?? 'bahay_kubo';
                $thumb_src = $c['thumb']
                    ? '../uploads/cottages/' . $c['thumb']
                    : '../images/cottage_placeholder.php?type='.$img_type.'&name='.urlencode($c['name']).'&n='.$c['id'];
            ?>
            <div class="cottage-admin-card <?= !$c['is_available'] ? 'unavailable' : '' ?>">

                <!-- SINGLE THUMBNAIL -->
                <div class="cac-thumb-wrap">
                    <img src="<?= $thumb_src ?>"
                         alt="<?= htmlspecialchars($c['name']) ?>"
                         class="cac-thumb-img">
                    <?php if (!$c['thumb']): ?>
                    <span class="cac-placeholder-tag">Placeholder</span>
                    <?php endif; ?>
                </div>

                <div class="cac-body">
                    <div class="cac-header">
                        <div>
                            <span class="cac-category"><?= $c['category'] ?></span>
                            <h4><?= htmlspecialchars($c['name']) ?></h4>
                        </div>
                        <span class="badge badge-<?= $c['is_available']?'confirmed':'cancelled' ?>">
                            <?= $c['is_available'] ? '' : 'Hidden' ?>
                        </span>
                    </div>

                    <div class="cac-meta">
                        <span>₱<?= number_format($c['price_per_night'],0) ?>/night</span>
                        <span>👥 <?= $c['capacity'] ?> guests max</span>
                        <span>📋 <?= $c['active_bookings'] ?> booking(s)</span>
                    </div>

                    <?php if ($c['amenities']): ?>
                    <div class="cac-amenities">
                        <?php foreach (array_slice(explode(',', $c['amenities']), 0, 3) as $a): ?>
                        <span class="tag-sm"><?= trim($a) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="cac-actions">
                        <a href="cottages.php?action=edit&id=<?= $c['id'] ?>" class="btn-sm">✏️ Edit</a>
                        <a href="cottages.php?toggle=<?= $c['id'] ?>"
                           class="btn-sm btn-sm-neutral"
                           onclick="return confirm('Toggle availability for <?= htmlspecialchars($c['name']) ?>?')">
                            <?= $c['is_available'] ? '🙈 Hide' : '👁️ Show' ?>
                        </a>
                        <a href="cottages.php?delete=<?= $c['id'] ?>"
                           class="btn-sm btn-sm-reject"
                           onclick="return confirm('Delete <?= htmlspecialchars($c['name']) ?>? This cannot be undone.')">
                            🗑️ Delete
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($cottages)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:3rem;color:#aaa;">
                No cottages yet. <a href="cottages.php?action=add" style="color:#2d6a4f;font-weight:700;">Add your first one →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</html>
<!-- ══════════ THUMBNAIL STYLES ══════════ -->
<style>
/* Card thumbnail */
.cac-thumb-wrap {
    position: relative;
    width: 100%;
    height: 160px;
    overflow: hidden;
    border-radius: 10px 10px 0 0;
    background: #1a3a2e;
    flex-shrink: 0;
}
.cac-thumb-img {
    width: 100%; height: 100%;
    object-fit: cover;
    object-position: center;
    display: block;
    transition: transform 0.3s ease;
}
.cottage-admin-card:hover .cac-thumb-img { transform: scale(1.04); }
.cac-placeholder-tag {
    position: absolute; bottom: 6px; right: 8px;
    background: rgba(0,0,0,0.55); color: #f4d58d;
    font-size: 0.65rem; font-weight: 700;
    padding: 2px 8px; border-radius: 50px;
    letter-spacing: 0.05em;
}
.cottage-admin-card.unavailable .cac-thumb-img { filter: grayscale(50%) brightness(0.8); }

/* Restructure card to stack vertically */
.cottage-admin-card {
    border: 1.5px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #fff;
}
.cottage-admin-card:hover { border-color: #2d6a4f; box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.cac-body { padding: 1rem; flex: 1; display: flex; flex-direction: column; gap: 0.5rem; }
.cac-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.cac-category { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #2d6a4f; display: block; margin-bottom: 0.15rem; }
.cac-header h4 { font-size: 0.95rem; color: #1a3a2e; margin: 0; }
.cac-meta { display: flex; flex-wrap: wrap; gap: 0.4rem; font-size: 0.78rem; color: #666; }
.cac-amenities { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.tag-sm { background: #f4f6f4; color: #555; font-size: 0.68rem; padding: 2px 7px; border-radius: 4px; }
.cac-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: auto; padding-top: 0.5rem; }

/* Upload area */
.current-thumb-wrap { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 0.75rem; flex-wrap: wrap; }
.current-thumb { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; }
.thumb-preview-img { width: 120px; height: 80px; object-fit: cover; border-radius: 8px; border: 2px solid #e5e8e5; display: block; }
.thumb-label { font-size: 0.7rem; color: #888; text-align: center; }
.btn-del-thumb { background: #f8d7da; color: #842029; border: 1.5px solid #f5c6cb; padding: 0.4rem 0.85rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all 0.2s; text-decoration: none; display: inline-block; }
.btn-del-thumb:hover { background: #842029; color: #fff; border-color: #842029; }
.thumb-hint { font-size: 0.8rem; color: #888; margin-bottom: 0.5rem; }

.file-upload-box { position: relative; border: 2px dashed #d0d5d0; border-radius: 10px; overflow: hidden; transition: border-color 0.2s; }
.file-upload-box:hover { border-color: #2d6a4f; }
.file-upload-box input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; z-index: 2; }
.file-upload-ui { padding: 1.5rem; text-align: center; cursor: pointer; }
.upload-icon { font-size: 2rem; display: block; margin-bottom: 0.4rem; }
.file-upload-ui p { font-size: 0.9rem; color: #444; margin: 0 0 0.25rem; }
.file-upload-ui small { font-size: 0.75rem; color: #aaa; }

.new-thumb-preview { position: relative; padding: 1rem; text-align: center; background: #f0faf5; }
.new-thumb-preview img { width: 180px; height: 120px; object-fit: cover; border-radius: 8px; border: 2px solid #2d6a4f; }
.clear-thumb-btn { display: block; margin: 0.5rem auto 0; background: none; border: 1px solid #ccc; border-radius: 6px; padding: 0.3rem 0.8rem; font-size: 0.78rem; color: #666; cursor: pointer; }
.clear-thumb-btn:hover { background: #f8d7da; border-color: #f5c6cb; color: #842029; }
</style>

<script>
function previewThumb(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('newThumbImg').src = e.target.result;
        document.getElementById('newThumbPreview').style.display = 'block';
        document.querySelector('.file-upload-ui').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

function clearThumb() {
    document.getElementById('thumbnailInput').value = '';
    document.getElementById('newThumbPreview').style.display = 'none';
    document.querySelector('.file-upload-ui').style.display = 'block';
}

// Drag over highlight
const box = document.getElementById('fileUploadBox');
if (box) {
    box.addEventListener('dragover', () => box.style.borderColor = '#2d6a4f');
    box.addEventListener('dragleave', () => box.style.borderColor = '');
}
</script>

<?php include 'partials/footer.php'; ?>
