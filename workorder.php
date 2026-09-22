<?php 
session_start();

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$conn = mysqli_connect("202.10.41.120","wira_user","Mtssepatan210300#","appsheet_db");
if(!$conn) die("Koneksi gagal");

$username = $_SESSION['username'] ?? '';
$isWira = ($username === 'wira');

/* ================= DAFTAR TOKO ================= */
$daftarToko = [
    "PAPIMART T2E GATE E3",
    "PAPIMART T2E GATE E4",
    "PAPIMART T2E GATE E5",
    "PAPIMART GATE 18",
    "PAPI COFFEE B5",
    "AMBIL BEKAL YUK D2",
    "AMBIL BEKAL YUK D6",
    "POINT ONE D1",
    "POINT ONE D3",
    "POINT ONE D5",
    "POINT ONE D7",
    "LATTE STORY T2E",
    "LATTE STORY T2F",
    "LATTE STORY T1C",
    "URBAN B4",
    "URBAN B6",
    "URBAN B7",
    "M'MART T3"
];

/* ================= HANDLE POST UPDATE / DELETE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])){
    header('Content-Type: application/json; charset=utf-8');

    $id = intval($_POST['id']);
    $action = $_POST['action'];

    if($action === 'update' && isset($_POST['status'])){
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        if(in_array($status, ['proses','selesai'])){
            mysqli_query($conn, "UPDATE workorder SET status='$status' WHERE id=$id");
        }

        $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status,file_pdf,perihal,deadline,dept,nama_toko,tanggal,petugas FROM workorder WHERE id=$id"));
        echo json_encode([
            'success'   => true,
            'status'    => $r['status'],
            'file_pdf'  => $r['file_pdf'],
            'perihal'   => $r['perihal'],
            'deadline'  => $r['deadline'],
            'dept'      => $r['dept'],
            'nama_toko' => $r['nama_toko'],
            'tanggal'   => $r['tanggal'],
            'petugas'   => $r['petugas']
        ]);
        exit;
    }

    if($action === 'update_deadline' && isset($_POST['deadline'])){
        if($isWira){
            $deadline = mysqli_real_escape_string($conn, $_POST['deadline']);
            mysqli_query($conn, "UPDATE workorder SET deadline='$deadline' WHERE id=$id");
            echo json_encode(['success'=>true,'deadline'=>$deadline]);
        } else {
            echo json_encode(['success'=>false,'msg'=>'Anda tidak diizinkan mengubah deadline.']);
        }
        exit;
    }

    if($action === 'get_detail'){
        $q = mysqli_query($conn, "SELECT * FROM workorder WHERE id=$id");
        $r = mysqli_fetch_assoc($q);

        if($r){
            echo json_encode(['success'=>true, 'data'=>$r]);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'Data tidak ditemukan']);
        }
        exit;
    }

    if($action === 'update_all'){
        $nama_toko = mysqli_real_escape_string($conn, $_POST['nama_toko'] ?? '');
        $perihal   = mysqli_real_escape_string($conn, $_POST['perihal'] ?? '');
        $dept      = mysqli_real_escape_string($conn, $_POST['dept'] ?? '');
        $tanggal   = mysqli_real_escape_string($conn, $_POST['tanggal'] ?? '');
        $deadline  = mysqli_real_escape_string($conn, $_POST['deadline'] ?? '');
        $petugas   = mysqli_real_escape_string($conn, $_POST['petugas'] ?? '');
        $status    = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
        $old_file  = mysqli_real_escape_string($conn, $_POST['old_file'] ?? '');

        if(!in_array($status, ['proses', 'selesai'])){
            $status = 'proses';
        }

        $file_pdf = $old_file;

        if(isset($_FILES['file_pdf']) && $_FILES['file_pdf']['error'] === 0){
            $uploadDir = __DIR__ . '/upload_workorder/';
            if(!is_dir($uploadDir)){
                mkdir($uploadDir, 0777, true);
            }

            $tmpName   = $_FILES['file_pdf']['tmp_name'];
            $fileName  = $_FILES['file_pdf']['name'];
            $fileSize  = $_FILES['file_pdf']['size'];
            $fileExt   = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $newName   = 'wo_' . time() . '_' . rand(1000,9999) . '.' . $fileExt;

            if($fileExt !== 'pdf'){
                echo json_encode(['success'=>false, 'msg'=>'File harus berformat PDF']);
                exit;
            }

            if($fileSize > 5 * 1024 * 1024){
                echo json_encode(['success'=>false, 'msg'=>'Ukuran file maksimal 5MB']);
                exit;
            }

            if(move_uploaded_file($tmpName, $uploadDir . $newName)){
                if(!empty($old_file) && file_exists($uploadDir . $old_file)){
                    @unlink($uploadDir . $old_file);
                }
                $file_pdf = $newName;
            } else {
                echo json_encode(['success'=>false, 'msg'=>'Upload file gagal']);
                exit;
            }
        }

        $sql = "UPDATE workorder SET 
                    nama_toko='$nama_toko',
                    perihal='$perihal',
                    dept='$dept',
                    tanggal='$tanggal',
                    deadline='$deadline',
                    petugas='$petugas',
                    status='$status',
                    file_pdf='$file_pdf'
                WHERE id=$id";

        if(mysqli_query($conn, $sql)){
            echo json_encode([
                'success'   => true,
                'nama_toko' => $nama_toko,
                'perihal'   => $perihal,
                'dept'      => $dept,
                'tanggal'   => $tanggal,
                'deadline'  => $deadline,
                'petugas'   => $petugas,
                'status'    => $status,
                'file_pdf'  => $file_pdf
            ]);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'Gagal update data']);
        }
        exit;
    }

    if($action === 'delete'){
        if($isWira){
            $q = mysqli_query($conn, "SELECT file_pdf FROM workorder WHERE id=$id");
            $r = mysqli_fetch_assoc($q);

            if($r && !empty($r['file_pdf'])){
                $filePath = __DIR__ . '/upload_workorder/' . $r['file_pdf'];
                if(file_exists($filePath)){
                    @unlink($filePath);
                }
            }

            mysqli_query($conn, "DELETE FROM workorder WHERE id=$id");
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false,'msg'=>'Anda tidak diizinkan menghapus data.']);
        }
        exit;
    }
}

/* ================= EXPORT EXCEL PHPSPREADSHEET ================= */
if(isset($_GET['export'])){
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Work Order');

    $lastColumn = 'I';

    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', 'LAPORAN DATA WORK ORDER');

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A2', 'Tanggal Export: ' . date('d-m-Y H:i:s'));

    $headers = ['No', 'Nama Toko', 'Perihal', 'Dept', 'Tanggal', 'Deadline', 'Petugas', 'Status', 'Dokumen'];
    $col = 'A';
    foreach($headers as $header){
        $sheet->setCellValue($col . '4', $header);
        $col++;
    }

    $q = mysqli_query($conn, "SELECT * FROM workorder ORDER BY id DESC");

    $rowNum = 5;
    $no = 1;
    while($r = mysqli_fetch_assoc($q)){
        $sheet->setCellValue('A' . $rowNum, $no);
        $sheet->setCellValue('B' . $rowNum, $r['nama_toko'] ?? '');
        $sheet->setCellValue('C' . $rowNum, $r['perihal'] ?? '');
        $sheet->setCellValue('D' . $rowNum, $r['dept'] ?? '');
        $sheet->setCellValue('E' . $rowNum, $r['tanggal'] ?? '');
        $sheet->setCellValue('F' . $rowNum, $r['deadline'] ?? '');
        $sheet->setCellValue('G' . $rowNum, $r['petugas'] ?? '');
        $sheet->setCellValue('H' . $rowNum, strtoupper($r['status'] ?? ''));
        $sheet->setCellValue('I' . $rowNum, !empty($r['file_pdf']) ? 'Ada File' : '-');

        if(($r['status'] ?? '') === 'selesai'){
            $sheet->getStyle('H' . $rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E2F0D9');
            $sheet->getStyle('H' . $rowNum)->getFont()->getColor()->setARGB('2E7D32');
        } else {
            $sheet->getStyle('H' . $rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FCE4D6');
            $sheet->getStyle('H' . $rowNum)->getFont()->getColor()->setARGB('C65911');
        }

        $rowNum++;
        $no++;
    }

    $lastDataRow = $rowNum - 1;

    $sheet->getStyle("A1:I1")->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle("A2:I2")->getFont()->setSize(11);
    $sheet->getStyle("A4:I4")->getFont()->setBold(true);

    $sheet->getStyle("A1:I1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A2:I2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A4:I4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->getStyle("A1:I1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1F4E78');
    $sheet->getStyle("A1:I1")->getFont()->getColor()->setARGB('FFFFFF');

    $sheet->getStyle("A2:I2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D9EAF7');

    $sheet->getStyle("A4:I4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('2F75B5');
    $sheet->getStyle("A4:I4")->getFont()->getColor()->setARGB('FFFFFF');

    if($lastDataRow >= 5){
        $sheet->getStyle("A4:I{$lastDataRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("A5:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D5:F{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("H5:I{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        for($i = 5; $i <= $lastDataRow; $i++){
            if($i % 2 === 0){
                $sheet->getStyle("A{$i}:I{$i}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('F7FBFF');
            }
        }
    }

    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(28);
    $sheet->getColumnDimension('C')->setWidth(40);
    $sheet->getColumnDimension('D')->setWidth(16);
    $sheet->getColumnDimension('E')->setWidth(14);
    $sheet->getColumnDimension('F')->setWidth(14);
    $sheet->getColumnDimension('G')->setWidth(22);
    $sheet->getColumnDimension('H')->setWidth(14);
    $sheet->getColumnDimension('I')->setWidth(14);

    $filename = 'workorder_' . date('Ymd_His') . '.xlsx';

    if(ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/* ================= DATA ================= */
$data = mysqli_query($conn,"SELECT * FROM workorder ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Data Work Order</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{font-family:Segoe UI,Arial;background:#f4f6f8;padding:20px;}
h2{margin-bottom:10px}
a{text-decoration:none}
.toolbar{margin-bottom:15px;}
.toolbar a{margin-right:10px;padding:6px 12px;background:#3498db;color:#fff;border-radius:6px;font-size:13px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;}
th,td{border:1px solid #ddd;padding:10px;text-align:center;}
th{background:#2c3e50;color:#fff;}
.proses{background:#f39c12;color:#fff;font-weight:600;}
.selesai{background:#27ae60;color:#fff;font-weight:600;}
.btn{padding:5px 10px;border-radius:5px;font-size:12px;color:#fff;cursor:pointer;border:none;}
.btn-proses{background:#f39c12}
.btn-selesai{background:#27ae60}
td.editable{cursor:pointer;background:#fdfd96;}
.swal2-popup .form-control{
    width:100%;
    padding:8px 10px;
    margin:6px 0 12px 0;
    border:1px solid #ccc;
    border-radius:6px;
    box-sizing:border-box;
}
.swal2-popup label{
    display:block;
    text-align:left;
    font-size:13px;
    font-weight:600;
    margin-top:6px;
}
</style>
</head>

<body>

<h2><i class="fa-solid fa-clipboard-list"></i> Data Work Order</h2>

<div class="toolbar">
    <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
    <a href="workorder_form.php"><i class="fa-solid fa-plus"></i> Tambah WO</a>
    <a href="?export=1"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
</div>

<table>
<tr>
    <th>No</th>
    <th>Nama Toko</th>
    <th>Perihal</th>
    <th>Dept</th>
    <th>Tanggal</th>
    <th>Deadline</th>
    <th>Petugas</th>
    <th>Status</th>
    <th>Dokumen</th>
    <?php if($isWira): ?><th>Aksi</th><?php endif; ?>
</tr>

<?php $no=1; while($r=mysqli_fetch_assoc($data)): ?>
<tr 
    data-id="<?= $r['id'] ?>"
    data-nama_toko="<?= htmlspecialchars($r['nama_toko'], ENT_QUOTES) ?>"
    data-perihal="<?= htmlspecialchars($r['perihal'], ENT_QUOTES) ?>"
    data-dept="<?= htmlspecialchars($r['dept'] ?? '', ENT_QUOTES) ?>"
    data-tanggal="<?= htmlspecialchars($r['tanggal'], ENT_QUOTES) ?>"
    data-deadline="<?= htmlspecialchars($r['deadline'], ENT_QUOTES) ?>"
    data-petugas="<?= htmlspecialchars($r['petugas'], ENT_QUOTES) ?>"
    data-status="<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>"
    data-file_pdf="<?= htmlspecialchars($r['file_pdf'], ENT_QUOTES) ?>"
>
    <td><?= $no++ ?></td>
    <td class="nama_toko"><?= htmlspecialchars($r['nama_toko']) ?></td>
    <td class="perihal"><?= htmlspecialchars($r['perihal']) ?></td>
    <td class="dept"><?= htmlspecialchars($r['dept'] ?? '-') ?></td>
    <td class="tanggal"><?= htmlspecialchars($r['tanggal']) ?></td>
    <td class="deadline editable"><?= $r['deadline'] ?: '-' ?></td>
    <td class="petugas"><?= htmlspecialchars($r['petugas']) ?></td>
    <td class="status <?= $r['status'] ?>"><?= strtoupper($r['status']) ?></td>
    <td class="dokumen">
        <?= $r['file_pdf'] ? "<a href='upload_workorder/{$r['file_pdf']}' target='_blank'>Lihat</a>" : "-" ?>
    </td>

<?php if($isWira): ?>
<td>
    <button class="btn btn-proses">Proses</button>
    <button class="btn btn-selesai">Selesai</button>
</td>
<?php endif; ?>

</tr>
<?php endwhile; ?>
</table>

<script>
const daftarToko = <?= json_encode($daftarToko, JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('table tr[data-id]').forEach(row => {
    const id = row.getAttribute('data-id');

    function ajaxUpdate(status){
        fetch('', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({action:'update', id:id, status:status})
        })
        .then(res => res.json())
        .then(data => {
            if(data.success){
                const statusCell = row.querySelector('.status');
                statusCell.textContent = data.status.toUpperCase();
                statusCell.className = 'status ' + data.status;
                row.dataset.status = data.status;

                const dokCell = row.querySelector('.dokumen');
                dokCell.innerHTML = data.file_pdf ? `<a href="upload_workorder/${data.file_pdf}" target="_blank">Lihat</a>` : '-';
                row.dataset.file_pdf = data.file_pdf || '';
            } else {
                Swal.fire('Gagal', 'Update gagal', 'error');
            }
        });
    }

    function bindDeadlineClick(){
        const deadlineCell = row.querySelector('.deadline');
        <?php if($isWira): ?>
        if(deadlineCell){
            deadlineCell.addEventListener('click', () => {
                const current = deadlineCell.textContent === '-' ? '' : deadlineCell.textContent;
                Swal.fire({
                    title:'Ubah Deadline',
                    input:'text',
                    inputLabel:'YYYY-MM-DD',
                    inputValue:current,
                    showCancelButton:true
                }).then(res => {
                    if(res.value){
                        fetch('', {
                            method:'POST',
                            headers:{'Content-Type':'application/x-www-form-urlencoded'},
                            body:new URLSearchParams({action:'update_deadline', id:id, deadline:res.value})
                        })
                        .then(resp => resp.json())
                        .then(data => {
                            if(data.success){
                                deadlineCell.textContent = data.deadline || '-';
                                row.dataset.deadline = data.deadline || '';
                                Swal.fire('Berhasil', 'Deadline berhasil diubah', 'success');
                            } else {
                                Swal.fire('Tidak diizinkan', data.msg, 'error');
                            }
                        });
                    }
                });
            });
        }
        <?php endif; ?>
    }

    function updateRowView(data){
        row.querySelector('.nama_toko').textContent = data.nama_toko || '-';
        row.querySelector('.perihal').textContent   = data.perihal || '-';
        row.querySelector('.dept').textContent      = data.dept || '-';
        row.querySelector('.tanggal').textContent   = data.tanggal || '-';
        row.querySelector('.deadline').textContent  = data.deadline || '-';
        row.querySelector('.petugas').textContent   = data.petugas || '-';

        const statusCell = row.querySelector('.status');
        statusCell.textContent = (data.status || '').toUpperCase();
        statusCell.className = 'status ' + (data.status || '');

        const dokCell = row.querySelector('.dokumen');
        dokCell.innerHTML = data.file_pdf ? `<a href="upload_workorder/${data.file_pdf}" target="_blank">Lihat</a>` : '-';

        row.dataset.nama_toko = data.nama_toko || '';
        row.dataset.perihal   = data.perihal || '';
        row.dataset.dept      = data.dept || '';
        row.dataset.tanggal   = data.tanggal || '';
        row.dataset.deadline  = data.deadline || '';
        row.dataset.petugas   = data.petugas || '';
        row.dataset.status    = data.status || '';
        row.dataset.file_pdf  = data.file_pdf || '';
    }

    function openEditModal(){
        fetch('', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({action:'get_detail', id:id})
        })
        .then(res => res.json())
        .then(resp => {
            if(!resp.success){
                Swal.fire('Gagal', resp.msg || 'Data tidak ditemukan', 'error');
                return;
            }

            const d = resp.data;

            const tokoOptions = daftarToko.map(toko => {
                const selected = toko === d.nama_toko ? 'selected' : '';
                return `<option value="${toko}" ${selected}>${toko}</option>`;
            }).join('');

            Swal.fire({
                title:'Edit Work Order',
                width:700,
                html:`
                    <label>Nama Toko</label>
                    <select id="swal_nama_toko" class="form-control">
                        <option value="">-- Pilih Toko --</option>
                        ${tokoOptions}
                    </select>

                    <label>Perihal</label>
                    <input id="swal_perihal" class="form-control" value="${d.perihal || ''}">

                    <label>Dept</label>
                    <select id="swal_dept" class="form-control">
                        <option value="">-- Pilih Dept --</option>
                        <option value="Sipil" ${d.dept === 'Sipil' ? 'selected' : ''}>Sipil</option>
                        <option value="Maintenance" ${d.dept === 'Maintenance' ? 'selected' : ''}>Maintenance</option>
                    </select>

                    <label>Tanggal</label>
                    <input id="swal_tanggal" type="date" class="form-control" value="${d.tanggal || ''}">

                    <label>Deadline</label>
                    <input id="swal_deadline" type="date" class="form-control" value="${d.deadline || ''}">

                    <label>Petugas</label>
                    <input id="swal_petugas" class="form-control" value="${d.petugas || ''}">

                    <label>Status</label>
                    <select id="swal_status" class="form-control">
                        <option value="proses" ${d.status === 'proses' ? 'selected' : ''}>Proses</option>
                        <option value="selesai" ${d.status === 'selesai' ? 'selected' : ''}>Selesai</option>
                    </select>

                    <label>Dokumen PDF</label>
                    <input id="swal_file_pdf" type="file" class="form-control" accept="application/pdf">
                    ${d.file_pdf ? `<div style="font-size:12px;text-align:left;margin-top:-6px;margin-bottom:10px;">File saat ini: <a href="upload_workorder/${d.file_pdf}" target="_blank">${d.file_pdf}</a></div>` : '<div style="font-size:12px;text-align:left;margin-top:-6px;margin-bottom:10px;">Belum ada file</div>'}
                `,
                showCancelButton:true,
                confirmButtonText:'Simpan',
                focusConfirm:false,
                preConfirm:() => {
                    const namaToko = document.getElementById('swal_nama_toko').value;
                    const perihal  = document.getElementById('swal_perihal').value;
                    const dept     = document.getElementById('swal_dept').value;
                    const tanggal  = document.getElementById('swal_tanggal').value;
                    const deadline = document.getElementById('swal_deadline').value;
                    const petugas  = document.getElementById('swal_petugas').value;
                    const status   = document.getElementById('swal_status').value;

                    if(!namaToko){
                        Swal.showValidationMessage('Nama Toko wajib dipilih');
                        return false;
                    }

                    if(!perihal){
                        Swal.showValidationMessage('Perihal wajib diisi');
                        return false;
                    }

                    if(!dept){
                        Swal.showValidationMessage('Dept wajib dipilih');
                        return false;
                    }

                    const formData = new FormData();
                    formData.append('action', 'update_all');
                    formData.append('id', id);
                    formData.append('nama_toko', namaToko);
                    formData.append('perihal', perihal);
                    formData.append('dept', dept);
                    formData.append('tanggal', tanggal);
                    formData.append('deadline', deadline);
                    formData.append('petugas', petugas);
                    formData.append('status', status);
                    formData.append('old_file', d.file_pdf || '');

                    const fileInput = document.getElementById('swal_file_pdf');
                    if(fileInput.files.length > 0){
                        formData.append('file_pdf', fileInput.files[0]);
                    }

                    return fetch('', {
                        method:'POST',
                        body:formData
                    })
                    .then(response => response.json())
                    .then(result => {
                        if(!result.success){
                            throw new Error(result.msg || 'Gagal update');
                        }
                        return result;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(error.message);
                    });
                }
            }).then(result => {
                if(result.isConfirmed && result.value){
                    updateRowView(result.value);
                    Swal.fire('Berhasil', 'Data berhasil diperbarui', 'success');
                }
            });
        });
    }

    row.addEventListener('contextmenu', function(e){
        e.preventDefault();
        Swal.fire({
            title:'Pilih Aksi',
            showCancelButton:true,
            showDenyButton:true,
            confirmButtonText:'Edit',
            denyButtonText:'Hapus'
        }).then(result => {
            if(result.isConfirmed){
                openEditModal();
            } else if(result.isDenied){
                fetch('', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:new URLSearchParams({action:'delete', id:id})
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success){
                        row.remove();
                        Swal.fire('Terhapus!', 'Data berhasil dihapus.', 'success');
                    } else {
                        Swal.fire('Tidak diizinkan', data.msg || 'Hubungi administrator', 'error');
                    }
                });
            }
        });
    });

    const btnProses = row.querySelector('.btn-proses');
    if(btnProses) btnProses.addEventListener('click', () => ajaxUpdate('proses'));

    const btnSelesai = row.querySelector('.btn-selesai');
    if(btnSelesai) btnSelesai.addEventListener('click', () => ajaxUpdate('selesai'));

    bindDeadlineClick();
});
</script>

</body>
</html>
