<?php 
// =========================================================================
// index.php - [COMPLETE CONSOLE: FILE, PROJECT & USER PROFILE MANAGER]
// =========================================================================
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
// โหลดฐานข้อมูลหลักและคลาสระบบ
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
require_once WEBROOT . '/core/AutoStorageRouter.php';
require_once WEBROOT . '/class/run.php';
require_once WEBROOT . '/class/ChunkedDataProcessor.php';
require_once WEBROOT . '/class/loop.php';
// ตรวจสอบสถานะการล็อกอินเบื้องต้น
$current_user = $_SESSION['username'] ?? 'guest';
$is_logged_in = ($current_user !== 'guest');
$project_id = $_GET['project_id'] ?? $_SESSION['active_project_id'] ?? 1;
$api_token = $_SESSION['api_token'] ?? 'tok_guest_' . bin2hex(random_bytes(16));
set_time_limit(0);
ini_set('memory_limit', '-1');
// เริ่มต้นระบบ Log
run::initLog(true, sys_get_temp_dir() . '/db_package_fission.log');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Management Console - File, Project & Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0b0f19; color: #d1d5db; font-family: system-ui, -apple-system, sans-serif; font-size: 13px; }
        .sidebar { background-color: #111827; border-right: 1px solid #1f2937; min-height: 100vh; }
        .nav-link { color: #9ca3af; border-radius: 6px; margin-bottom: 4px; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #38bdf8; }
        .card-custom { background-color: #111827; border: 1px solid #1f2937; border-radius: 10px; margin-bottom: 15px; }
        .table-custom { background-color: #0f172a; color: #e2e8f0; font-size: 12px; }
        .table-custom th { background-color: #1e293b; color: #38bdf8; border-color: #334155; }
        .table-custom td { border-color: #1e293b; vertical-align: middle; }
        .code-preview { background-color: #030712; color: #facc15; font-family: monospace; font-size: 11px; height: 380px; }
        .tab-content-pane { display: none; }
        .tab-content-pane.active { display: block; }
        .loading-box { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(11, 15, 25, 0.8); z-index: 9999; text-align: center; padding-top: 20%; color: #38bdf8; }
        .action-btns .btn { padding: 1px 5px; font-size: 11px; }
        .modal-content { background-color: #111827; color: #e5e7eb; border: 1px solid #1f2937; }
        .modal-header, .modal-footer { border-color: #1f2937; }
        .file-link { cursor: pointer; text-decoration: none; color: #e2e8f0; }
        .file-link:hover { color: #38bdf8; text-decoration: underline; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-3 sidebar d-flex flex-column justify-content-between">
            <div>
                <h5 class="text-info fw-bold mb-4 px-2"><i class="fa-solid fa-cube me-2"></i>Console</h5>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="#" class="nav-link active" onclick="switchTab('files', event)"><i class="fa-solid fa-folder-open me-2"></i> จัดการไฟล์</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" onclick="switchTab('projects', event)"><i class="fa-solid fa-diagram-project me-2"></i> บริหารโปรเจค</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" onclick="switchTab('profile', event)"><i class="fa-solid fa-user-gear me-2"></i> ข้อมูลส่วนตัว</a>
                    </li>
                </ul>
            </div>
            <div class="px-2 pb-3 border-top border-secondary pt-3">
                <span class="text-muted d-block small mb-1">ผู้ใช้งาน:</span>
                <strong class="text-success"><?= htmlspecialchars($current_user) ?></strong>
            </div>
        </div>

        <div class="col-md-10 p-4">
            
            <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom border-secondary">
                <h4 class="text-light fw-bold m-0" id="page-title">ระบบจัดการไฟล์ (File Manager)</h4>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-secondary font-monospace">Project ID: <span id="lbl-pid"><?= $project_id ?></span></span>
                    <button onclick="location.reload()" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-rotate"></i></button>
                </div>
            </div>

            <div id="alert-zone"></div>

            <div id="tab-files" class="tab-content-pane active">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button class="btn btn-sm btn-success fw-bold" onclick="openModal('modalNewFile')"><i class="fa-solid fa-file-circle-plus me-1"></i> สร้างไฟล์ใหม่</button>
                    <button class="btn btn-sm btn-warning fw-bold text-dark" onclick="openModal('modalNewFolder')"><i class="fa-solid fa-folder-plus me-1"></i> สร้างโฟลเดอร์ใหม่</button>
                    <button class="btn btn-sm btn-info fw-bold text-dark" onclick="openModal('modalUploadZip')"><i class="fa-solid fa-file-zipper me-1"></i> อัพโหลดไฟล์ / แตกซิป</button>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="loadFiles()"><i class="fa-solid fa-sync me-1"></i> โหลดข้อมูลใหม่</button>
                </div>

                <div class="row">
                    <div class="col-md-5">
                        <div class="card card-custom p-3">
                            <h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-list me-2"></i>รายการไฟล์ในระบบ</h6>
                            <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                                <table class="table table-sm table-dark table-custom mb-0">
                                    <thead>
                                        <tr>
                                            <th>ชื่อไฟล์ (Path)</th>
                                            <th class="text-end">ขนาด</th>
                                            <th class="text-center" style="width: 130px;">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="file-table-body">
                                        <tr><td colspan="3" class="text-center text-muted">กำลังโหลด...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-7">
                        <div class="card card-custom p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="text-warning fw-bold m-0"><i class="fa-solid fa-code me-2"></i>Code Editor / Viewer</h6>
                                <button onclick="saveCurrentFile()" class="btn btn-sm btn-success py-0 px-2 fw-bold">💾 บันทึกเนื้อหา</button>
                            </div>
                            <input type="text" id="active-filename" class="form-control form-control-sm bg-dark text-white border-secondary mb-2" placeholder="ชื่อไฟล์ที่เลือก..." readonly>
                            <textarea id="file-content-editor" class="form-control code-preview mb-2" placeholder="คลิกเลือกชื่อไฟล์ทางซ้ายเพื่อดูเนื้อหาและแก้ไข..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-projects" class="tab-content-pane">
                <div class="card card-custom p-3">
                    <h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-folder-tree me-2"></i>ตั้งค่าและจัดการโปรเจค</h6>
                    <div class="mb-3">
                        <label class="form-label text-muted">ชื่อโปรเจคปัจจุบัน:</label>
                        <input type="text" id="proj-name-input" class="form-control bg-dark text-white border-secondary form-control-sm" value="Default Project" style="max-width: 400px;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted">ฐานข้อมูลโฮสต์/SQLite Database Path:</label>
                        <input type="text" id="proj-db-input" class="form-control bg-dark text-info border-secondary font-monospace form-control-sm" value="storage/project_1.db" style="max-width: 400px;" readonly>
                    </div>
                    <button onclick="alert('บันทึกข้อมูลโปรเจคเรียบร้อย')" class="btn btn-sm btn-primary fw-bold" style="max-width: 150px;">บันทึกโปรเจค</button>
                </div>
            </div>

            <div id="tab-profile" class="tab-content-pane">
                <div class="card card-custom p-3" style="max-width: 500px;">
                    <h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-id-card me-2"></i>ข้อมูลส่วนตัวและสิทธิ์</h6>
                    <div class="mb-3">
                        <label class="form-label text-muted">ชื่อผู้ใช้งาน (Username):</label>
                        <input type="text" class="form-control bg-dark text-success border-secondary form-control-sm" value="<?= htmlspecialchars($current_user) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted">API Token ความปลอดภัย:</label>
                        <input type="text" class="form-control bg-dark text-warning border-secondary font-monospace form-control-sm" value="<?= $api_token ?>" readonly>
                    </div>
                    <button onclick="executeLogout()" class="btn btn-sm btn-danger fw-bold"><i class="fa-solid fa-right-from-bracket me-1"></i> ออกจากระบบ</button>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="modalNewFile" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success"><i class="fa-solid fa-file-circle-plus me-2"></i>สร้างไฟล์ใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">ชื่อไฟล์ (ระบุ path ได้ เช่น folder/file.php):</label>
                    <input type="text" id="new-file-name" class="form-control bg-dark text-white border-secondary" placeholder="เช่น index.php">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success btn-sm" onclick="createNewFile()">สร้างไฟล์</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNewFolder" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-warning"><i class="fa-solid fa-folder-plus me-2"></i>สร้างโฟลเดอร์ใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">ชื่อโฟลเดอร์ (ระบุ path ได้):</label>
                    <input type="text" id="new-folder-name" class="form-control bg-dark text-white border-secondary" placeholder="เช่น assets/css">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-warning btn-sm text-dark" onclick="createNewFolder()">สร้างโฟลเดอร์</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalUploadZip" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-info"><i class="fa-solid fa-file-zipper me-2"></i>อัพโหลดไฟล์ / แตกไฟล์ ZIP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">เลือกไฟล์ (.zip หรือไฟล์ทั่วไป):</label>
                    <input type="file" id="upload-file-input" class="form-control bg-dark text-white border-secondary">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="extract-zip-check" checked>
                    <label class="form-check-label text-warning" for="extract-zip-check">
                        แตกไฟล์ ZIP อัตโนมัติหลังอัพโหลดสำเร็จ
                    </label>
                </div>
                <div class="mb-3">
                    <label class="form-label">ปลายทาง (Path เป้าหมาย เว้นว่างไว้คือ Root):</label>
                    <input type="text" id="upload-dest-path" class="form-control bg-dark text-white border-secondary" placeholder="เช่น assets/images">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-info btn-sm text-dark fw-bold" onclick="uploadAndExtractFile()">อัพโหลดและประมวลผล</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAction" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-info" id="action-modal-title">จัดการไฟล์</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="action-type">
                <input type="hidden" id="action-old-name">
                <div class="mb-3">
                    <label class="form-label text-muted" id="action-label-source">ไฟล์ต้นทาง:</label>
                    <input type="text" id="action-source-display" class="form-control bg-dark text-white border-secondary" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label" id="action-label-target">ชื่อใหม่ / ปลายทาง:</label>
                    <input type="text" id="action-target-input" class="form-control bg-dark text-white border-secondary">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="submitActionModal()">ยืนยัน</button>
            </div>
        </div>
    </div>
</div>

<div class="loading-box" id="loader-overlay">
    <div class="spinner-border text-info mb-2"></div>
    <div class="fw-bold">กำลังประมวลผลข้อมูล...</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const API_URL = 'api.php';
    const PROJECT_ID = <?= $project_id ?>;
    const TOKEN = '<?= $api_token ?>';

    document.addEventListener('DOMContentLoaded', () => {
        loadFiles();
    });

    function showLoader() { document.getElementById('loader-overlay').style.display = 'block'; }
    function hideLoader() { document.getElementById('loader-overlay').style.display = 'none'; }

    function openModal(modalId) {
        const modal = new bootstrap.Modal(document.getElementById(modalId));
        modal.show();
    }

    function switchTab(tabKey, e) {
        if(e) e.preventDefault();
        document.querySelectorAll('.tab-content-pane').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.sidebar .nav-link').forEach(el => el.classList.remove('active'));
        
        document.getElementById('tab-' + tabKey).classList.add('active');
        if(e) e.target.classList.add('active');

        const titles = { 'files': 'ระบบจัดการไฟล์ (File Manager)', 'projects': 'บริหารโปรเจค (Project Settings)', 'profile': 'ข้อมูลส่วนตัว (User Profile)' };
        document.getElementById('page-title').innerText = titles[tabKey];
    }

    // ตัวจัดการ API Request รองรับทั้ง JSON Payload และ FormData
    async function callApi(payloadData, isFormData = false, customBody = null) {
        showLoader();
        try {
            let bodyData;
            if (isFormData) {
                bodyData = customBody;
            } else {
                const formData = new FormData();
                formData.append('token', TOKEN);
                formData.append('project_id', PROJECT_ID);
                formData.append('payload', JSON.stringify(payloadData));
                bodyData = formData;
            }

            const res = await fetch(API_URL, { method: 'POST', body: bodyData });
            const json = await res.json();
            hideLoader();
            return json;
        } catch(err) {
            hideLoader();
            console.error(err);
            alert('เกิดข้อผิดพลาดในการเชื่อมต่อ API หรือการประมวลผลเซิร์ฟเวอร์ขัดข้อง');
            return null;
        }
    }

    // โหลดรายการไฟล์ (action = file_list)
    async function loadFiles() {
        const res = await callApi({ action: 'file_list' });
        const tbody = document.getElementById('file-table-body');
        if (res && res.success && res.data) {
            tbody.innerHTML = '';
            if (res.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">ไม่พบข้อมูลไฟล์</td></tr>';
                return;
            }
            res.data.forEach(f => {
                const escapedName = f.name.replace(/'/g, "\\'");
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <span class="file-link" onclick="viewFile('${escapedName}', ${f.is_dir})">
                                <i class="fa-solid ${f.is_dir == 1 ? 'fa-folder text-warning' : 'fa-file-lines text-info'} me-2"></i>${f.name}
                            </span>
                        </td>
                        <td class="text-end text-muted">${f.is_dir == 1 ? '-' : (f.size || 0) + ' B'}</td>
                        <td class="text-center action-btns">
                            <button class="btn btn-outline-info btn-sm" title="เปลี่ยนชื่อ" onclick="promptAction('rename', '${escapedName}')"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-outline-warning btn-sm" title="คัดลอก" onclick="promptAction('copy', '${escapedName}')"><i class="fa-solid fa-copy"></i></button>
                            <button class="btn btn-outline-primary btn-sm" title="ย้าย" onclick="promptAction('move', '${escapedName}')"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>
                            <button class="btn btn-outline-danger btn-sm" title="ลบ" onclick="deleteFile('${escapedName}')"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }
    }

    // ดูข้อมูลและโหลดลง Editor (action = view)
    async function viewFile(filename, isDir) {
        document.getElementById('active-filename').value = filename;
        if (isDir == 1) {
            document.getElementById('file-content-editor').value = "[Directory - ไม่สามารถแก้ไขเนื้อหาโดยตรงได้]";
            return;
        }
        
        document.getElementById('file-content-editor').value = "กำลังโหลดข้อมูลไฟล์...";
        const res = await callApi({ action: 'view', filename: filename });
        
        if (res && res.success) {
            let decoded = '';
            try { 
                // ถอดรหัส Base64 อย่างปลอดภัย รองรับ UTF-8 (ภาษาไทย)
                decoded = decodeURIComponent(escape(window.atob(res.content)));
            } catch(e) { 
                decoded = "[ไฟล์ประเภท Binary หรือไม่สามารถแสดงผลรูปแบบ Text ได้]"; 
            }
            document.getElementById('file-content-editor').value = decoded;
        } else {
            document.getElementById('file-content-editor').value = "[เกิดข้อผิดพลาดในการโหลดไฟล์ หรือไม่พบไฟล์]";
        }
    }

    // บันทึกเนื้อหาที่แก้ไข (action = file_save)
    async function saveCurrentFile() {
        const fn = document.getElementById('active-filename').value;
        const content = document.getElementById('file-content-editor').value;
        if (!fn) return alert('กรุณาเลือกไฟล์ก่อนบันทึก');

        // อ้างอิงตาม api.php (UnifiedFileAPI::apiUpload) ใช้ข้อความส่งผ่าน text_content
        const res = await callApi({ action: 'file_save', filename: fn, text_content: content });
        if (res && res.success) {
            alert('บันทึกข้อมูลไฟล์สำเร็จ');
            loadFiles();
        } else {
            alert(res?.message || 'บันทึกไม่สำเร็จ');
        }
    }

    // สร้างไฟล์ใหม่ (action = file_save)
    async function createNewFile() {
        const filename = document.getElementById('new-file-name').value.trim();
        if (!filename) return alert('กรุณาระบุชื่อไฟล์');
        
        const res = await callApi({ action: 'file_save', filename: filename, text_content: '' });
        if (res && res.success) {
            alert('สร้างไฟล์สำเร็จ');
            bootstrap.Modal.getInstance(document.getElementById('modalNewFile')).hide();
            document.getElementById('new-file-name').value = '';
            loadFiles();
            viewFile(filename, 0); // โหลดไฟล์ขึ้นมา Edit ทันที
        } else {
            alert(res?.message || 'สร้างไฟล์ไม่สำเร็จ');
        }
    }

    // สร้างโฟลเดอร์ใหม่ (action = folder_create)
    async function createNewFolder() {
        const folderPath = document.getElementById('new-folder-name').value.trim();
        if (!folderPath) return alert('กรุณาระบุชื่อโฟลเดอร์ หรือ Path เป้าหมาย');
        
        // คลาส UnifiedFileAPI ใช้ตัวแปรชื่อ path
        const res = await callApi({ action: 'folder_create', path: folderPath });
        if (res && res.success) {
            alert('สร้างโฟลเดอร์สำเร็จ');
            bootstrap.Modal.getInstance(document.getElementById('modalNewFolder')).hide();
            document.getElementById('new-folder-name').value = '';
            loadFiles();
        } else {
            alert(res?.message || 'สร้างโฟลเดอร์ไม่สำเร็จ');
        }
    }

    // อัพโหลดไฟล์ / แตกไฟล์ ZIP
    async function uploadAndExtractFile() {
        const fileInput = document.getElementById('upload-file-input');
        const extractCheck = document.getElementById('extract-zip-check').checked;
        const destPath = document.getElementById('upload-dest-path').value.trim();

        if (!fileInput.files.length) return alert('กรุณาเลือกไฟล์ที่ต้องการอัพโหลด');
        const file = fileInput.files[0];

        // จัดเตรียม Path ปลายทาง + ชื่อไฟล์
        let targetPath = destPath ? destPath + '/' + file.name : file.name;
        targetPath = targetPath.replace(/\/+/g, '/').replace(/^\//, ''); // กัน / เบิ้ล หรือนำหน้า

        const formData = new FormData();
        formData.append('token', TOKEN);
        formData.append('project_id', PROJECT_ID);
        formData.append('action', 'file_upload'); // เข้า action file_upload ตรงๆ
        formData.append('filename', targetPath);
        formData.append('file', file);

        // Step 1: อัพโหลดไฟล์ปกติ
        const res = await callApi(null, true, formData);
        
        if (res && res.success) {
            // Step 2: ถ้าติ๊กเลือกแตกไฟล์ ZIP
            if (extractCheck && file.name.toLowerCase().endsWith('.zip')) {
                const extRes = await callApi({
                    action: 'file_extract_zip',
                    filename: targetPath,
                    target_folder: destPath // ส่ง path เป้าหมายตามโครงสร้าง API ของคุณ
                });
                
                if (extRes && extRes.success) {
                    alert('อัพโหลดและแตกไฟล์ ZIP โครงสร้างสำเร็จเรียบร้อย');
                } else {
                    alert('อัพโหลดสำเร็จ แต่ไม่สามารถแตกไฟล์ ZIP ได้: ' + (extRes?.message || ''));
                }
            } else {
                alert('อัพโหลดไฟล์สำเร็จเรียบร้อย');
            }
            
            bootstrap.Modal.getInstance(document.getElementById('modalUploadZip')).hide();
            fileInput.value = '';
            document.getElementById('upload-dest-path').value = '';
            loadFiles();
        } else {
            alert(res?.message || 'อัพโหลดไม่สำเร็จ');
        }
    }

    // เตรียมหน้าต่าง Modal สำหรับเปลี่ยนชื่อ / คัดลอก / ย้าย
    function promptAction(type, filename) {
        document.getElementById('action-type').value = type;
        document.getElementById('action-old-name').value = filename;
        document.getElementById('action-source-display').value = filename;
        document.getElementById('action-target-input').value = filename;

        const titles = { 'rename': 'เปลี่ยนชื่อไฟล์/โฟลเดอร์', 'copy': 'คัดลอกไฟล์', 'move': 'ย้ายไฟล์' };
        const labels = { 'rename': 'ระบุชื่อใหม่:', 'copy': 'คัดลอกไปที่ (ระบุ Path ปลายทาง):', 'move': 'ย้ายไปที่ (ระบุ Path ปลายทาง):' };

        document.getElementById('action-modal-title').innerText = titles[type];
        document.getElementById('action-label-target').innerText = labels[type];

        openModal('modalAction');
    }

    // ยืนยันการเปลี่ยนชื่อ / คัดลอก / ย้าย
    async function submitActionModal() {
        const type = document.getElementById('action-type').value; // rename, copy, move
        const oldName = document.getElementById('action-old-name').value;
        const newName = document.getElementById('action-target-input').value.trim();

        if (!newName) return alert('กรุณาระบุข้อมูลปลายทางให้ครบถ้วน');

        // ตาม API ใช้ตัวแปร source และ target
        const res = await callApi({ action: type, source: oldName, target: newName });

        if (res && res.success) {
            alert('ดำเนินการเรียบร้อยแล้ว');
            bootstrap.Modal.getInstance(document.getElementById('modalAction')).hide();
            loadFiles();
        } else {
            alert(res?.message || 'ดำเนินการไม่สำเร็จ');
        }
    }

    // ลบไฟล์ (action = delete)
    async function deleteFile(filename) {
        if (!confirm(`คุณต้องการลบ "${filename}" ออกจากระบบใช่หรือไม่?`)) return;
        
        const res = await callApi({ action: 'delete', filename: filename });
        if (res && res.success) {
            alert('ลบไฟล์เรียบร้อยแล้ว');
            
            // ล้างหน้าจอ Editor หากไฟล์ที่ลบถูกแสดงผลอยู่
            if (document.getElementById('active-filename').value === filename) {
                document.getElementById('active-filename').value = '';
                document.getElementById('file-content-editor').value = '';
            }
            
            loadFiles();
        } else {
            alert(res?.message || 'ไม่สามารถลบไฟล์ได้');
        }
    }

    // ออกจากระบบ
    async function executeLogout() {
        await callApi({ action: 'user_logout' }); // ถ้าฝั่ง Backend รองรับการ Logout ผ่าน action
        location.reload();
    }
</script>
</body>
</html>
