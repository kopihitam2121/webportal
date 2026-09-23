<?php
session_start();
require "db.php";

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$isSuper = ($username === 'wira' || $username === 'wahid' || strtolower($_SESSION['role'] ?? '') === 'superadmin');

// ====== BULAN TERPILIH ======
date_default_timezone_set('Asia/Jakarta');
$currentMonth = date('Y-m');
$selectedMonth = $_GET['month'] ?? $currentMonth;

// validasi format YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = $currentMonth;
}

// ====== LIST BULAN (dropdown) ======
$monthList = [];

// bulan unik yang sudah ada di DB
$resMonth = $conn->query("SELECT DISTINCT ym FROM retail_target WHERE ym IS NOT NULL AND ym <> '' ORDER BY ym DESC");
if ($resMonth) {
    while ($r = $resMonth->fetch_assoc()) {
        $monthList[] = $r['ym'];
    }
}

// tambah range +/- 12 bulan
for ($i = -12; $i <= 12; $i++) {
    $monthList[] = date('Y-m', strtotime("$currentMonth-01 $i month"));
}
$monthList = array_values(array_unique($monthList));
rsort($monthList);

if (!in_array($selectedMonth, $monthList)) {
    $selectedMonth = $currentMonth;
}

// ====== HANDLE UPDATE (UPSERT PER BULAN) ======
if (isset($_POST['update_target']) && $isSuper) {
    $tenant = $_POST['edit_tenant'] ?? '';
    $ym     = $_POST['ym'] ?? $selectedMonth;

    $tenant = trim($tenant);

    $target = (int)($_POST['edit_target'] ?? 0);

    // insentif AUTO: target x 0,5%
    $insentif = (int) round($target * 0.005);

    if ($tenant !== '' && preg_match('/^\d{4}-\d{2}$/', $ym)) {
        // INSERT jika belum ada, UPDATE kalau sudah ada (butuh UNIQUE tenant,ym)
        $stmt = $conn->prepare("
            INSERT INTO retail_target (tenant, ym, target, insentif)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                target = VALUES(target),
                insentif = VALUES(insentif)
        ");
        $stmt->bind_param("ssii", $tenant, $ym, $target, $insentif);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: 'Data berhasil disimpan untuk bulan $ym',
                        showConfirmButton: false,
                        timer: 1500
                    }).then(() => {
                        window.location.href = 'retail_target.php?month=' + encodeURIComponent('$ym');
                    });
                });
            </script>";
        } else {
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire('Gagal', 'Terjadi kesalahan saat menyimpan data', 'error');
                });
            </script>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="img/srt2.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Retail Target - SRT Corporation</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    body { background-color: #f3f9f8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    header {
        background: linear-gradient(90deg, #009688, #20c997);
        color: white;
        padding: 14px 25px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    }
    header img { height: 45px; width: auto; margin-right: 10px; }
    header h3 { margin: 0; font-weight: 600; font-size: 22px; letter-spacing: 0.5px; }
    .table-container { padding: 30px; }
    thead { background-color: #009688; color: white; font-weight: bold; }
    tbody tr:hover { background-color: #e0f2f1; transition: 0.2s; }
    td.left-align { text-align: left; padding-left: 20px; }
    .card { border: none; border-radius: 15px; box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
    .btn-edit { background-color: #009688; color: white; border: none; padding: 6px 12px; border-radius: 8px; font-size: 14px; transition: 0.2s; }
    .btn-edit:hover { background-color: #00796b; color: #fff; }
    .total-row { background-color: #c8e6c9; font-weight: bold; }
    .month-select { padding: 6px 10px; border-radius: 10px; border: none; }
</style>
</head>
<body>

<header>
    <div style="display:flex; align-items:center;">
        <img src="img/srt4.png" alt="SRT Logo">
        <div>
            <h3 style="margin-bottom:2px;">SRT Retail Target</h3>
            <div style="font-size:13px; opacity:0.95;">
                Bulan:
                <form method="get" style="display:inline;">
                    <select name="month" class="month-select" onchange="this.form.submit()">
                        <?php foreach($monthList as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= ($m==$selectedMonth?'selected':'') ?>>
                                <?= strftime("%B %Y", strtotime($m.'-01')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
    </div>
    <a href="dashboard.php" class="btn btn-light">Kembali</a>
</header>

<div class="table-container">
    <div class="card p-3">
        <div class="table-responsive">
            <table class="table table-striped align-middle text-center">
                <thead>
                    <tr>
                        <th>No</th>
                        <th style="text-align:left;">Tenant</th>
                        <th>Target</th>
                        <th>Insentif</th>
                        <?php if ($isSuper): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // ====== FILTER tenant mulai Maret 2025 dan seterusnya ======
                    $excludeTenants = [
                        'PAPIMART BALI DOMESTIK',
                        'PAPIMART BALI INTERNASIONAL',
                        'LATTE STORY A5',
                        'LATTE STORY A6'
                    ];

                    // Ambil semua tenant selalu tampil, lalu join data per bulan
                    // Filter exclude jika selectedMonth >= 2025-03
                    $stmt = $conn->prepare("
                        SELECT t.tenant,
                               COALESCE(rt.target, 0) AS target,
                               COALESCE(rt.insentif, 0) AS insentif
                        FROM (
                            SELECT DISTINCT tenant
                            FROM retail_target
                            WHERE tenant IS NOT NULL AND tenant <> ''
                              AND (
                                    ? < '2025-03'
                                    OR tenant NOT IN (
                                        'PAPIMART BALI DOMESTIK',
                                        'PAPIMART BALI INTERNASIONAL',
                                        'LATTE STORY A5',
                                        'LATTE STORY A6'
                                    )
                              )
                        ) t
                        LEFT JOIN retail_target rt
                            ON rt.tenant = t.tenant AND rt.ym = ?
                        ORDER BY t.tenant ASC
                    ");
                    $stmt->bind_param("ss", $selectedMonth, $selectedMonth);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    $totalTarget = 0;
                    $totalInsentif = 0;
                    $no = 1;

                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $tenant = htmlspecialchars($row['tenant']);
                            $target = (int)$row['target'];
                            $insentif = (int)$row['insentif'];

                            $totalTarget += $target;
                            $totalInsentif += $insentif;

                            echo "<tr>
                                    <td>{$no}</td>
                                    <td class='left-align'>{$tenant}</td>
                                    <td>Rp " . number_format($target, 0, ',', '.') . "</td>
                                    <td>Rp " . number_format($insentif, 0, ',', '.') . "</td>";

                            if ($isSuper) {
                                $btnText = ($target > 0 || $insentif > 0) ? "Edit" : "Tambah";
                                echo "<td>
                                        <button type='button' class='btn-edit'
                                            onclick='openEditModal(\"{$tenant}\", {$target})'>
                                            {$btnText}
                                        </button>
                                      </td>";
                            }
                            echo "</tr>";
                            $no++;
                        }
                    } else {
                        echo "<tr><td colspan='".($isSuper ? 5 : 4)."'>Belum ada data tenant</td></tr>";
                    }

                    $stmt->close();
                    ?>
                </tbody>
                <tfoot>
                    <tr class="total-row text-center">
                        <td colspan="2">TOTAL</td>
                        <td>Rp <?= number_format($totalTarget, 0, ',', '.') ?></td>
                        <td>Rp <?= number_format($totalInsentif, 0, ',', '.') ?></td>
                        <?php if ($isSuper): ?><td>-</td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<center><div class="copyright">&copy; 2025 SRTCorporationgroup, All Rights Reserved.</div></center>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header" style="background-color:#009688;color:white;">
        <h5 class="modal-title">Edit Retail Target</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="editForm">
      <div class="modal-body">
            <input type="hidden" name="ym" id="ym" value="<?= htmlspecialchars($selectedMonth) ?>">
            <div class="mb-3">
                <label>Tenant</label>
                <input type="text" name="edit_tenant" id="edit_tenant" class="form-control" readonly>
            </div>
            <div class="mb-3">
                <label>Target</label>
                <input type="number" name="edit_target" id="edit_target" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Insentif</label>
                <input type="number" name="edit_insentif" id="edit_insentif" class="form-control" readonly>
                <small class="text-muted">Otomatis: Target × 0,5%</small>
            </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <?php if ($isSuper): ?>
            <button type="submit" name="update_target" class="btn btn-success">Simpan</button>
        <?php endif; ?>
      </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let editModal = new bootstrap.Modal(document.getElementById('editModal'));

function calcInsentifFromTarget(target) {
    target = parseInt(target || 0, 10);
    return Math.round(target * 0.005);
}

function openEditModal(tenant, target) {
    document.getElementById('edit_tenant').value = tenant;
    document.getElementById('edit_target').value = target;
    document.getElementById('edit_insentif').value = calcInsentifFromTarget(target);
    editModal.show();
}

// auto update insentif saat target diketik
document.getElementById('edit_target').addEventListener('input', function() {
    document.getElementById('edit_insentif').value = calcInsentifFromTarget(this.value);
});
</script>

</body>
</html>
