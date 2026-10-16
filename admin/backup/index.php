<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/backup.php';

// require_role() runs require_auth(): the session is re-checked against the
// database on every request, so a suspended or demoted admin loses access to
// the full-database export immediately instead of when the session expires.
require_role('admin');
$user = current_user();

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$message = '';
$error = '';
$success = false;

if ($_POST && ($_POST['action'] ?? '') === 'export') {
    // BUGFIX: this page never called csrf_verify() on either form, unlike
    // every other POST handler in the codebase — a real gap on what is
    // arguably the single most sensitive admin action (full data
    // export/import). Checked per-branch (rather than once up top) since
    // the export branch exits via export_backup_to_download() before this
    // file's own HTML even starts rendering.
    csrf_verify();
    export_backup_to_download();
}

if ($_FILES && isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
    csrf_verify();
    $tempFile = $_FILES['backup_file']['tmp_name'];
    $json = file_get_contents($tempFile);
    $backup = json_decode($json, true);

    if (!$backup) {
        $error = 'Invalid JSON file';
    } else {
        $validation = validate_backup_structure($backup);
        if (!$validation['valid']) {
            $error = 'Backup validation failed: ' . implode(', ', $validation['errors']);
        } else {
            // Check import mode
            $mode = $_POST['import_mode'] ?? 'merge';
            $result = import_backup($backup, ['mode' => $mode]);

            if ($result['success']) {
                $message = 'Backup imported successfully. Records: ' . 
                    implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($result['imported']), $result['imported']));
                $success = true;
            } else {
                $error = $result['error'] ?? 'Unknown import error';
                if (!empty($result['errors'])) {
                    $error .= '. Details: ' . implode('; ', array_slice($result['errors'], 0, 3));
                }
            }
        }
    }
}

// Get current system stats
$stats = [
    'users' => count(get_all_users()),
    'stocks' => count(get_stocks()),
    'orders' => count(get_procurement_orders()),
    'warehouses' => count(get_all_warehouses()),
    'documents' => count(array_merge(...array_map(fn($u) => get_documents($u['id']), get_all_users()))),
];
$lastBackup = @filemtime(__DIR__ . '/../../uploads/.last_backup')
    ? date('Y-m-d H:i:s', filemtime(__DIR__ . '/../../uploads/.last_backup'))
    : 'Never';
?>
<!DOCTYPE html>
<html>
<head>
    <title>KAMALIG — Backup Management</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/styles.css')); ?>">
    <style>
        .backup-container {
            max-width: 900px; margin: 40px auto; padding: 20px;
        }
        .backup-card {
            border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0;
            background: #f9f9f9;
        }
        .backup-card h3 {
            margin: 0 0 15px 0; color: #333;
        }
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;
            margin: 15px 0;
        }
        .stat-box {
            background: white; padding: 15px; border-radius: 6px; text-align: center; border: 1px solid #eee;
        }
        .stat-number {
            font-size: 24px; font-weight: bold; color: #2c3e50;
        }
        .stat-label {
            font-size: 12px; color: #7f8c8d; margin-top: 5px; text-transform: uppercase;
        }
        .form-group {
            margin: 15px 0;
        }
        .form-group label {
            display: block; font-weight: bold; margin: 5px 0; color: #333;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;
            font-family: inherit; font-size: 14px;
        }
        .btn {
            padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer;
            font-weight: bold; font-size: 14px; transition: background 0.2s;
        }
        .btn-primary {
            background: #3498db; color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
        .btn-success {
            background: #27ae60; color: white;
        }
        .btn-success:hover {
            background: #229954;
        }
        .btn-danger {
            background: #e74c3c; color: white;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .alert {
            padding: 15px; border-radius: 4px; margin: 15px 0;
        }
        .alert-success {
            background: #d4edda; border: 1px solid #c3e6cb; color: #155724;
        }
        .alert-error {
            background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;
        }
        .alert-info {
            background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460;
        }
        .file-input-wrapper {
            position: relative; overflow: hidden; display: inline-block;
        }
        .file-input-wrapper input[type=file] {
            position: absolute; left: -9999px;
        }
        .file-input-label {
            padding: 10px 20px; background: #3498db; color: white; border-radius: 4px;
            cursor: pointer; font-weight: bold;
        }
        .file-input-name {
            margin-left: 10px; color: #7f8c8d;
        }
    </style>
</head>
<body>
    <nav style="background: #2c3e50; color: white; padding: 15px 20px;">
        <a href="/admin/" style="color: white; text-decoration: none; font-weight: bold;">← Back to Admin</a>
    </nav>

    <div class="backup-container">
        <h1>📦 Backup & Restore</h1>
        <p style="color: #7f8c8d;">Export full system data for migration, archival, or disaster recovery. Import to restore from a previous backup.</p>

        <?php if ($success): ?>
            <div class="alert alert-success">✓ <?php echo htmlspecialchars($message); ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-error">✕ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- System Status -->
        <div class="backup-card">
            <h3>📊 System Status</h3>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['users']; ?></div>
                    <div class="stat-label">Users</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['stocks']; ?></div>
                    <div class="stat-label">Listings</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['orders']; ?></div>
                    <div class="stat-label">Orders</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['warehouses']; ?></div>
                    <div class="stat-label">Warehouses</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['documents']; ?></div>
                    <div class="stat-label">Documents</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $lastBackup; ?></div>
                    <div class="stat-label">Last Backup</div>
                </div>
            </div>
        </div>

        <!-- Export Backup -->
        <div class="backup-card">
            <h3>💾 Export Backup</h3>
            <p>Download a complete snapshot of all system data, including users, inventory, orders, documents, and audit trails.</p>
            <form method="POST" style="display: inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="export">
                <button type="submit" class="btn btn-success">
                    ⬇️ Download Backup (JSON)
                </button>
            </form>
            <div style="margin-top: 15px; padding: 10px; background: white; border-radius: 4px; font-size: 13px; color: #7f8c8d;">
                <strong>What's included:</strong> Users, stocks, inventory history, procurement orders, warehouses, documents, settings, permissions, and complete activity audit trail.
                <br><strong>File format:</strong> Compressed JSON. Size ~<?php echo round(count(get_stocks()) * 2.5 / 1024, 1); ?> MB estimated.
                <br><strong>Restore:</strong> Upload this file using the Import section below.
            </div>
        </div>

        <!-- Import Backup -->
        <div class="backup-card">
            <h3>📥 Import Backup</h3>
            <p>Restore system data from a previously exported backup. Choose how to handle existing records.</p>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Select Backup File (JSON)</label>
                    <div style="display: flex; align-items: center;">
                        <div class="file-input-wrapper">
                            <label class="file-input-label">Choose File</label>
                            <input type="file" name="backup_file" id="backup_file" accept=".json" required>
                        </div>
                        <span class="file-input-name" id="file-name">No file chosen</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Import Mode</label>
                    <select name="import_mode" required>
                        <option value="merge" selected>Merge (Skip existing records, add new)</option>
                        <option value="overwrite">Overwrite (Replace all matching records)</option>
                    </select>
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        <strong>Merge:</strong> Preserves any changes made to existing records since backup was created.<br>
                        <strong>Overwrite:</strong> Restores exact state from backup, losing any newer changes.
                    </small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="confirm_import" required>
                        I understand this will modify system data and have backed up my current database
                    </label>
                </div>

                <input type="hidden" name="action" value="import">
                <button type="submit" class="btn btn-primary">
                    ⬆️ Import Backup
                </button>
            </form>

            <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107; font-size: 13px;">
                <strong>⚠️ Warning:</strong> Importing a backup will modify or add records. Ensure you have a current database backup before proceeding.
                Large imports may take a moment to process — do not close the page.
            </div>
        </div>

        <!-- Technical Info -->
        <div class="backup-card" style="background: #ecf0f1; border-left: 4px solid #95a5a6;">
            <h3>ℹ️ Technical Information</h3>
            <pre style="background: white; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">
<?php
echo "Backup Version: " . BACKUP_VERSION . "\n";
echo "Database: " . (database_available() ? "MySQL" : "Session (Demo Mode)") . "\n";
echo "Export Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "Backup Schema: " . implode(', ', BACKUP_SCHEMA['sections']) . "\n";
echo "Admin: " . htmlspecialchars($user['name'] ?? 'Unknown') . "\n";
?></pre>
        </div>
    </div>

    <script>
        document.getElementById('backup_file').addEventListener('change', function(e) {
            const name = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
            document.getElementById('file-name').textContent = name;
        });
    </script>
</body>
</html>
