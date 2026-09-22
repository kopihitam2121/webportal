<?php
session_start();
if(!isset($_SESSION['username'])){
    header("Location: login.php");
    exit;
}

require 'db.php'; // koneksi $conn

// Ambil semua data
$sql = "SELECT * FROM data_karyawan ORDER BY nama ASC";
$result = $conn->query($sql);
if(!$result){
    die("Query error: " . $conn->error);
}

// Cek role user
$role = $_SESSION['role'] ?? 'user'; 
$isSuperAdmin = ($role === 'superadmin');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1024, initial-scale=0.5, minimum-scale=0.25, maximum-scale=5.0, user-scalable=yes">
<title>Database Karyawan</title>

<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">

<style>
body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f6f9; padding: 20px; }
h2 { text-align: center; margin-bottom: 25px; color: #2c3e50; }
.top-bar { display: flex; justify-content: space-between; margin-bottom: 15px; }
.btn { padding: 10px 18px; border-radius: 6px; text-decoration: none; color: #fff; font-size: 14px; font-weight: bold; }
.btn-dashboard { background: #007bff; }
.btn-dashboard:hover { background: #0056b3; }
.btn-export { background: #28a745; }
.btn-export:hover { background: #1e7e34; }
table.dataTable thead th { background: #16a085; color: #fff; }
td[contenteditable="true"] { background: #fff8dc; border: 1px dashed #ccc; }
footer { text-align:center; margin-top:20px; color:#555; font-size:13px; }

/* Context menu hapus */
#contextMenu {
    display: none;
    position: absolute;
    z-index: 1000;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}
#contextMenu ul { list-style: none; padding: 5px 0; margin: 0; }
#contextMenu ul li { padding: 8px 20px; cursor: pointer; }
#contextMenu ul li:hover { background: #f0f0f0; }
</style>
</head>
<body>

<div class="top-bar">
    <a href="dashboard.php" class="btn btn-dashboard">Kembali</a>
    <a href="#" id="downloadExcel" class="btn btn-export"><i class="fas fa-file-excel"></i> Download Excel</a>
</div>

<h2>Data Karyawan Minimarket SRT 2025</h2>

<table id="tabelKaryawan" class="display nowrap" style="width:100%">
    <thead>
        <tr>
            <th>NIK</th><th>Nama</th><th>Gender</th><th>Tgl Masuk PT</th><th>Masa Kerja</th>
            <th>Tempat Lahir</th><th>Tgl Lahir</th><th>Domisili</th><th>Alamat KTP</th>
            <th>Agama</th><th>Status Kawin</th><th>PTKP</th><th>Email</th><th>No Tlp</th>
            <th>Rekening</th><th>Toko</th><th>Pas Ban</th><th>Nama PT</th>
            <th>Nama Ibu</th><th>Nama Ayah</th><th>Pendidikan</th><th>Gol Darah</th>
        </tr>
    </thead>
    <tbody>
        <?php while($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['nik']); ?></td>
            <?php $editable = $isSuperAdmin ? 'contenteditable="true"' : ''; ?>
            <td <?= $editable ?> data-nik="<?= $row['nik'] ?>" data-kolom="nama"><?= htmlspecialchars($row['nama']); ?></td>
            <td <?= $editable ?> data-nik="<?= $row['nik'] ?>" data-kolom="gender"><?= htmlspecialchars($row['gender']); ?></td>
            <?php
            $tglMasuk = new DateTime($row['tgl_masuk_pt']);
            $sekarang = new DateTime();
            $diff = $tglMasuk->diff($sekarang);
            $masaKerja = $diff->y . " Tahun, " . $diff->m . " Bulan, " . $diff->d . " Hari";
            ?>
            <td <?= $editable ?> data-nik="<?= $row['nik'] ?>" data-kolom="tgl_masuk_pt"><?= htmlspecialchars($row['tgl_masuk_pt']); ?></td>
            <td><?= $masaKerja; ?></td>
            <?php
            $skip = ['nik','nama','gender','tgl_masuk_pt'];
            foreach($row as $kolom => $nilai){
                if(in_array($kolom,$skip)) continue;
                echo '<td '.($isSuperAdmin?'contenteditable="true"':'').' data-nik="'.$row['nik'].'" data-kolom="'.$kolom.'">'.htmlspecialchars($nilai).'</td>';
            }
            ?>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<!-- Context menu hapus -->
<div id="contextMenu">
    <ul>
        <li id="hapusRow">Hapus Baris</li>
    </ul>
</div>

<footer>
    <i class="fas fa-copyright"></i> <?= date('Y'); ?> SRT Corp. All rights reserved.
</footer>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function(){
    var table = $('#tabelKaryawan').DataTable({
        pageLength: 10,
        lengthMenu: [5,10,25,50],
        scrollX: true,
        dom: 'Bfrtip',
        buttons: ['excelHtml5','print']
    });
    new $.fn.dataTable.FixedHeader(table);

    <?php if($isSuperAdmin): ?>
    // inline edit via blur
    $('#tabelKaryawan').on('blur','td[contenteditable="true"]',function(){
        let nik=$(this).data('nik');
        let kolom=$(this).data('kolom');
        let nilai=$(this).text().trim();
        $.post('inline_update_karyawan.php',{nik:nik,kolom:kolom,nilai:nilai},function(res){
            console.log(res);
        });
    });

    $('#tabelKaryawan').on('keydown','td[contenteditable="true"]',function(e){
        if(e.key==='Enter'){ e.preventDefault(); $(this).blur(); }
    });

    // context menu hapus
    var selectedRow = null;
    $('#tabelKaryawan tbody').on('contextmenu','tr',function(e){
        e.preventDefault();
        selectedRow = $(this);
        $('#contextMenu').css({top:e.pageY+"px",left:e.pageX+"px"}).show();
    });
    $(document).click(function(){ $('#contextMenu').hide(); });

    $('#hapusRow').on('click',function(){
        if(!selectedRow) return;
        let nik = selectedRow.find('td:eq(0)').text().trim();
        Swal.fire({
            title:'Yakin ingin menghapus?',
            text:'Data karyawan akan hilang permanen!',
            icon:'warning',
            showCancelButton:true,
            confirmButtonColor:'#d33',
            cancelButtonColor:'#3085d6',
            confirmButtonText:'Ya, hapus!',
            cancelButtonText:'Batal'
        }).then((result)=>{
            if(result.isConfirmed){
                $.post('delete_karyawan.php',{nik:nik},function(res){
                    if(res.success){
                        Swal.fire('Terhapus!','Data karyawan telah dihapus.','success');
                        table.row(selectedRow).remove().draw(false);
                        selectedRow=null;
                    }else{
                        Swal.fire('Gagal!',res.message,'error');
                    }
                },'json');
            }
        });
        $('#contextMenu').hide();
    });
    <?php endif; ?>

    // Download Excel
    $('#downloadExcel').on('click',function(e){
        e.preventDefault();
        var html='<table border="1"><tr>';
        $('#tabelKaryawan thead th').each(function(){ html+='<th>'+$(this).text()+'</th>'; });
        html+='</tr>';
        table.rows().every(function(){
            html+='<tr>';
            $(this.node()).find('td').each(function(){ html+='<td>'+$(this).text()+'</td>'; });
            html+='</tr>';
        });
        html+='</table>';
        var blob=new Blob([html],{type:'application/vnd.ms-excel'});
        var url=URL.createObjectURL(blob);
        var a=document.createElement('a');
        a.href=url; a.download='data_karyawan.xls';
        document.body.appendChild(a); a.click();
        document.body.removeChild(a); URL.revokeObjectURL(url);
    });
});
</script>
</body>
</html>
