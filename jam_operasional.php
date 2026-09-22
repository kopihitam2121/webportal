<?php
session_start();
if(!isset($_SESSION['username'])){
    header("Location: login.php");
    exit;
}

require 'db.php';

$result = $conn->query("SELECT * FROM master_jam_operasional ORDER BY `Store Name` ASC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jam Operasional Store</title>

<link rel="icon" type="image/png" href="img/srt2.png" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

<style>
* {
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: linear-gradient(135deg, #0b1f3a, #102b50, #163b6d);
    margin: 0;
    padding: 20px;
    color: #ffffff;
    min-height: 100vh;
}

h2 {
    text-align: center;
    color: #eaf3ff;
    margin-bottom: 25px;
    font-size: 28px;
    letter-spacing: 0.5px;
}

.btn-back {
    display: inline-block;
    margin-bottom: 20px;
    padding: 10px 18px;
    background: linear-gradient(135deg, #0d2a52, #174a8b);
    color: #fff;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}

.btn-back:hover {
    background: linear-gradient(135deg, #12396d, #1f5fb3);
    transform: translateY(-2px);
}

.table-wrapper {
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(8px);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

/* desktop */
#jamTable {
    width: 100% !important;
    border-collapse: collapse !important;
    background: #ffffff;
    color: #1b2a3a;
    border-radius: 12px;
    overflow: hidden;
}

#jamTable thead th {
    background: linear-gradient(135deg, #0b2a4a, #123d6b);
    color: #ffffff !important;
    text-transform: uppercase;
    font-size: 13px;
    padding: 14px 10px !important;
    border-bottom: none !important;
}

#jamTable tbody td {
    padding: 12px 10px !important;
    border-bottom: 1px solid #e6edf5;
    font-size: 14px;
    word-break: break-word;
}

#jamTable tbody tr:nth-child(even) {
    background: #f4f8fc;
}

#jamTable tbody tr:hover {
    background: #dbeafe !important;
    transition: background 0.2s ease;
}

/* datatables area */
.dataTables_wrapper {
    color: #eaf3ff;
    width: 100%;
}

.dataTables_wrapper .dataTables_filter {
    margin-bottom: 15px;
    text-align: right;
}

.dataTables_wrapper .dataTables_filter label {
    color: #eaf3ff !important;
    font-weight: bold;
}

.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #2f5d91;
    border-radius: 8px;
    padding: 8px 10px;
    background: #f8fbff;
    color: #123;
    outline: none;
    margin-left: 8px;
    max-width: 220px;
}

.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_paginate {
    display: none !important;
}

/* cegah wrapper bawaan datatables bikin lebar */
.dataTables_wrapper .dataTables_scroll,
.dataTables_wrapper .dataTables_scrollBody,
.dataTables_wrapper .dataTables_scrollHead {
    overflow: visible !important;
}

/* mobile: ubah jadi card ke bawah */
@media (max-width: 768px) {
    body {
        padding: 14px;
    }

    h2 {
        font-size: 22px;
        margin-bottom: 18px;
    }

    .btn-back {
        font-size: 13px;
        padding: 9px 14px;
    }

    .table-wrapper {
        padding: 14px;
    }

    #jamTable,
    #jamTable tbody,
    #jamTable tr,
    #jamTable td {
        display: block !important;
        width: 100% !important;
    }

    #jamTable thead {
        display: none !important;
    }

    #jamTable tbody tr {
        margin-bottom: 16px;
        background: #ffffff !important;
        border-radius: 12px;
        padding: 10px 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border: 1px solid #d9e6f2;
    }

    #jamTable tbody td {
        position: relative;
        padding: 10px 10px 10px 46% !important;
        text-align: left !important;
        border-bottom: 1px solid #edf2f7 !important;
        min-height: 42px;
        white-space: normal !important;
    }

    #jamTable tbody td:last-child {
        border-bottom: none !important;
    }

    #jamTable tbody td::before {
        content: attr(data-label);
        position: absolute;
        left: 10px;
        top: 10px;
        width: 40%;
        font-weight: bold;
        color: #0b2a4a;
        text-align: left;
        white-space: normal;
    }

    .dataTables_wrapper .dataTables_filter {
        text-align: left;
    }

    .dataTables_wrapper .dataTables_filter input {
        width: 100%;
        max-width: 100%;
        margin-left: 0;
        margin-top: 8px;
    }
}
</style>
</head>
<body>

<a href="dashboard.php" class="btn-back">
    <i class="fa-solid fa-arrow-left"></i> Kembali
</a>

<h2><i class="fa-regular fa-clock"></i> Jam Operasional Store</h2>

<div class="table-wrapper">
    <table id="jamTable">
        <thead>
            <tr>
                <th>Store Name</th>
                <th>Shift 1</th>
                <th>Shift 2</th>
                <th>Shift 3</th>
                <th>Middle</th>
                <th>Location</th>
                <th>Code</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td data-label="Store Name"><?php echo htmlspecialchars($row['Store Name']); ?></td>
                <td data-label="Shift 1"><?php echo htmlspecialchars($row['Shift 1']); ?></td>
                <td data-label="Shift 2"><?php echo htmlspecialchars($row['Shift 2']); ?></td>
                <td data-label="Shift 3"><?php echo htmlspecialchars($row['Shift 3']); ?></td>
                <td data-label="Middle"><?php echo htmlspecialchars($row['Middle']); ?></td>
                <td data-label="Location"><?php echo htmlspecialchars($row['Location']); ?></td>
                <td data-label="Code"><?php echo htmlspecialchars($row['Code']); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function(){
    $('#jamTable').DataTable({
        paging: false,
        info: false,
        lengthChange: false,
        ordering: true,
        searching: true,
        autoWidth: false,
        responsive: false,
        language: {
            search: "Cari:"
        }
    });
});
</script>

</body>
</html>
