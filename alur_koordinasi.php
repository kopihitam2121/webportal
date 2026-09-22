<?php
// alur_koordinasi.php
include 'kandidat.php';
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alur Koordinasi Minimarket</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI', Arial, sans-serif;
}

body{
    background:#f7f9fc;
    padding:26px 28px;
    overflow:hidden;
}

.header{
    position:relative;
    margin-bottom:38px;
}

h1{
    text-align:center;
    font-size:31px;
    color:#0f172a;
    letter-spacing:.5px;
}

.btn-kembali{
    position:absolute;
    top:0;
    right:0;
    padding:11px 22px;
    background:#1e293b;
    color:#fff;
    text-decoration:none;
    border-radius:7px;
    font-size:13px;
    font-weight:700;
    box-shadow:4px 4px 0 rgba(0,0,0,.18);
    transition:.25s ease;
}

.btn-kembali:hover{
    background:#334155;
    transform:translateY(-2px);
}

.org-chart{
    width:1482px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    align-items:center;
}

.box{
    border-radius:3px;
    text-align:center;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    color:#111827;
    font-weight:700;
    box-shadow:7px 7px 0 rgba(0,0,0,.15);
    opacity:0;
    transform:scale(.35);
    animation:popIn .7s ease forwards;
}

@keyframes popIn{
    0%{
        opacity:0;
        transform:scale(.35);
    }
    70%{
        opacity:1;
        transform:scale(1.08);
    }
    100%{
        opacity:1;
        transform:scale(1);
    }
}

.name{
    font-size:13px;
    line-height:1.25;
}

.role{
    font-size:10px;
    margin-top:7px;
    font-weight:600;
}

.level-1{
    width:420px;
    min-height:74px;
    background:#d85b5b;
}

.level-2{
    width:320px;
    min-height:70px;
    background:#a3c35c;
}

.level-3{
    width:240px;
    min-height:68px;
    background:#4fb8d3;
}

.level-4{
    width:102px;
    min-height:82px;
    padding:8px 6px;
    background:#f7a046;
}

.level-4 .name{
    font-size:11px;
}

.level-4 .role{
    font-size:8.5px;
}

.line{
    background:#222;
}

.v-line{
    width:2px;
    height:36px;
}

.v-line-small{
    width:2px;
    height:26px;
}

.supervisor-row{
    width:1482px;
    display:grid;
    grid-template-columns:456px 456px 180px 300px;
    column-gap:30px;
    position:relative;
    padding-top:34px;
}

.supervisor-row::before{
    content:"";
    position:absolute;
    top:0;
    left:228px;
    width:1104px;
    height:2px;
    background:#222;
}

.supervisor-row::after{
    content:"";
    position:absolute;
    top:-36px;
    left:741px;
    width:2px;
    height:36px;
    background:#222;
}

.group{
    display:flex;
    flex-direction:column;
    align-items:center;
    position:relative;
}

.group::before{
    content:"";
    position:absolute;
    top:-34px;
    left:50%;
    transform:translateX(-50%);
    width:2px;
    height:34px;
    background:#222;
}

.children-wrap{
    width:100%;
    display:flex;
    flex-direction:column;
    align-items:center;
    margin-top:28px;
}

.child-line{
    width:82%;
    height:2px;
    background:#222;
    position:relative;
    margin-bottom:28px;
}

.child-line::before,
.child-line::after,
.child-line span,
.child-line i{
    content:"";
    position:absolute;
    top:0;
    width:2px;
    height:28px;
    background:#222;
}

.child-line::before{
    left:0;
}

.child-line span{
    left:33.33%;
}

.child-line i{
    left:66.66%;
}

.child-line::after{
    right:0;
}

.staff-row{
    width:100%;
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:18px;
    justify-items:center;
}

.group.adriyan .staff-row{
    grid-template-columns:1fr;
}

.group.adriyan .child-line{
    width:2px;
    height:28px;
    margin-bottom:0;
}

.group.adriyan .child-line::before,
.group.adriyan .child-line::after,
.group.adriyan .child-line span,
.group.adriyan .child-line i{
    display:none;
}

.group.wira .staff-row{
    grid-template-columns:repeat(3, 1fr);
}

.group.wira .child-line{
    width:72%;
}

.group.wira .child-line span{
    left:50%;
    transform:translateX(-50%);
}

.group.wira .child-line i{
    display:none;
}

@media(max-width:1366px){
    .org-chart{
        transform:scale(.88);
        transform-origin:top center;
    }
}

@media(max-width:768px){
    body{
        overflow:auto;
        padding:18px;
    }

    .header{
        padding-top:56px;
    }

    .org-chart{
        width:100%;
        transform:none;
    }

    .level-1,
    .level-2,
    .level-3,
    .level-4{
        width:260px;
        min-height:72px;
    }

    .v-line,
    .v-line-small,
    .supervisor-row::before,
    .supervisor-row::after,
    .group::before,
    .child-line{
        display:none;
    }

    .supervisor-row{
        width:100%;
        display:flex;
        flex-direction:column;
        align-items:center;
        gap:38px;
        padding-top:0;
    }

    .staff-row,
    .group.adriyan .staff-row,
    .group.wira .staff-row{
        grid-template-columns:1fr;
        gap:14px;
    }
}
</style>
</head>

<body>

<div class="header">
    <h1>ALUR KOORDINASI MINIMARKET</h1>
    <a href="javascript:history.back()" class="btn-kembali"> Kembali</a>
</div>

<div class="org-chart">

    <div class="box level-1">
        <div class="name"><?= htmlspecialchars($atasan['nama']); ?></div>
        <div class="role"><?= htmlspecialchars($atasan['jabatan']); ?></div>
    </div>

    <div class="line v-line"></div>

    <div class="box level-2">
        <div class="name"><?= htmlspecialchars($manager['nama']); ?></div>
        <div class="role"><?= htmlspecialchars($manager['jabatan']); ?></div>
    </div>

    <div class="line v-line"></div>

    <div class="supervisor-row">

        <?php foreach($supervisors as $spv): ?>

            <div class="group <?= htmlspecialchars($spv['class']); ?>">

                <div class="box level-3">
                    <div class="name"><?= htmlspecialchars($spv['nama']); ?></div>
                    <div class="role"><?= htmlspecialchars($spv['jabatan']); ?></div>
                </div>

                <div class="line v-line-small"></div>

                <div class="children-wrap">
                    <div class="child-line">
                        <span></span>
                        <i></i>
                    </div>

                    <div class="staff-row">
                        <?php foreach($spv['bawahan'] as $staff): ?>
                            <div class="box level-4">
                                <div class="name"><?= htmlspecialchars($staff['nama']); ?></div>
                                <div class="role"><?= htmlspecialchars($staff['jabatan']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>

</body>
</html>
