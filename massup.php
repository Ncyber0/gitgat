<?php
/**
 * Mass Upload Multi-Domain v3
 * - Chunked upload (512KB/chunk)
 * - Server-side background worker (anti-timeout)
 * - Mass path: 1 file → banyak domain × banyak sub-path
 * - Auto-create folder (mkdir -p)
 * - Hasil job disimpan ke file .txt (reports/)
 */

$STORAGE_DIR = __DIR__ . '/massup_data';
$CHUNKS_DIR  = $STORAGE_DIR . '/chunks';
$JOBS_DIR    = $STORAGE_DIR . '/jobs';
$LOCK_DIR    = $STORAGE_DIR . '/locks';
$REPORTS_DIR = $STORAGE_DIR . '/reports';

foreach ([$STORAGE_DIR, $CHUNKS_DIR, $JOBS_DIR, $LOCK_DIR, $REPORTS_DIR] as $d) @mkdir($d, 0755, true);
if (!file_exists($STORAGE_DIR . '/.htaccess')) {
    @file_put_contents($STORAGE_DIR . '/.htaccess',
        "Order deny,allow\nDeny from all\nRequire all denied\n");
}
if (mt_rand(1, 20) === 1) cleanupOldFiles($STORAGE_DIR);

// ============================================================
// CLI WORKER
// ============================================================
if (php_sapi_name() === 'cli') {
    $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $argv[1] ?? '');
    if ($jobId) { set_time_limit(0); ignore_user_abort(true); processJobFull($jobId); }
    exit;
}

// ============================================================
// WEB REQUEST
// ============================================================
set_time_limit(60);
ignore_user_abort(true);

if ($a = $_REQUEST['action'] ?? '') { handleApiAction($a); exit; }

function handleApiAction($action) {
    switch ($action) {
        case 'upload_chunk': return handleUploadChunk();
        case 'start_job':    return handleStartJob();
        case 'status':       return handleStatus();
        case 'worker':       return handleWorkerWeb();
        case 'report':       return handleDownloadReport();   // <-- BARU
        default: jsonResponse(['error' => 'Unknown action'], 400);
    }
}

// ---------- 1. TERIMA CHUNK ----------
function handleUploadChunk() {
    global $CHUNKS_DIR;
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = parseIniSize(ini_get('post_max_size'));
    if ($max > 0 && $len > $max) jsonResponse(['error' => 'Chunk melebihi post_max_size'], 413);

    $fileId      = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['file_id'] ?? '');
    $chunkIndex  = (int)($_POST['chunk_index'] ?? -1);
    $totalChunks = (int)($_POST['total_chunks'] ?? 0);
    if (!$fileId || $chunkIndex < 0 || $totalChunks < 1) jsonResponse(['error' => 'Parameter tidak valid'], 400);
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) jsonResponse(['error' => 'Upload chunk gagal'], 400);

    $dir = $CHUNKS_DIR . '/' . $fileId;
    @mkdir($dir, 0755, true);
    $dest = $dir . '/' . sprintf('%06d.part', $chunkIndex);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $dest)) jsonResponse(['error' => 'Gagal simpan chunk'], 500);

    jsonResponse(['ok' => true, 'received' => count(glob($dir . '/*.part')), 'total' => $totalChunks]);
}

// ---------- 2. MULAI JOB ----------
function handleStartJob() {
    global $CHUNKS_DIR, $JOBS_DIR;

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $domainsInput = trim($input['domains'] ?? '');
    $basePath     = rtrim(trim($input['base_path'] ?? ''), '/');
    $mode         = ($input['mode'] ?? 'text') === 'file' ? 'file' : 'text';
    $fileId       = preg_replace('/[^a-zA-Z0-9_]/', '', $input['file_id'] ?? '');
    $fileName     = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($input['file_name'] ?? 'upload.txt'));
    $textContent  = (string)($input['file_content'] ?? '');
    $createDir    = !empty($input['create_dir']);
    $subPathsRaw  = (string)($input['sub_paths'] ?? '');

    if (!$domainsInput) jsonResponse(['error' => 'Domain kosong'], 400);
    if (!$basePath) $basePath = $_SERVER['DOCUMENT_ROOT'] ?? '';

    // Parse domain
    $domains = array_values(array_unique(array_filter(array_map(function ($d) {
        $d = preg_replace('#^https?://#', '', trim($d));
        $d = preg_replace('#^www\.#', '', $d);
        return rtrim($d, '/');
    }, preg_split('/[\n,]+/', $domainsInput)))));

    if (!$domains) jsonResponse(['error' => 'Tidak ada domain valid'], 400);
    if (!$fileName) $fileName = 'upload.txt';

    // Parse sub-paths
    $subPaths = [];
    foreach (preg_split('/[\n,]+/', $subPathsRaw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $norm = normalizeSubPath($line);
        if ($norm === null) jsonResponse(['error' => 'Sub path tidak valid: ' . $line], 400);
        if ($norm === '') continue;
        $subPaths[] = $norm;
    }
    if (empty($subPaths)) $subPaths = [''];   // '' = upload ke root domain
    $subPaths = array_values(array_unique($subPaths));

    // Merge chunk (kalau mode file)
    $jobId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $mergedName = null;
    $totalSize  = 0;

    if ($mode === 'file') {
        if (!$fileId) jsonResponse(['error' => 'File belum diupload'], 400);
        $dir = $CHUNKS_DIR . '/' . $fileId;
        if (!is_dir($dir)) jsonResponse(['error' => 'Chunk tidak ditemukan'], 400);

        $parts = glob($dir . '/*.part');
        sort($parts);
        if (!$parts) jsonResponse(['error' => 'Chunk kosong'], 400);

        $mergedName = $jobId . '.bin';
        $out = fopen($JOBS_DIR . '/' . $mergedName, 'wb');
        if (!$out) jsonResponse(['error' => 'Gagal buat file merged'], 500);
        foreach ($parts as $p) {
            $in = fopen($p, 'rb');
            if ($in) { $totalSize += stream_copy_to_stream($in, $out); fclose($in); }
            @unlink($p);
        }
        fclose($out);
        @rmdir($dir);
    } else {
        $totalSize = strlen($textContent);
    }

    // Bangun task matrix: domain × sub_path
    $tasks = [];
    foreach ($domains as $d) {
        foreach ($subPaths as $sp) {
            $tasks[] = ['domain' => $d, 'sub_path' => $sp];
        }
    }

    $job = [
        'id'           => $jobId,
        'created_at'   => time(),
        'status'       => 'pending',
        'mode'         => $mode,
        'base_path'    => $basePath,
        'file_name'    => $fileName,
        'merged_path'  => $mergedName,
        'text_content' => ($mode === 'text') ? $textContent : null,
        'total_size'   => $totalSize,
        'create_dir'   => $createDir,
        'tasks'        => $tasks,
        'progress'     => [
            'total'   => count($tasks),
            'done'    => 0,
            'success' => 0,
            'failed'  => 0,
            'current' => null,
            'results' => [],
        ],
        'started_at'   => null,
        'finished_at'  => null,
        'error'        => null,
    ];

    if (!writeJob($jobId, $job)) jsonResponse(['error' => 'Gagal simpan job'], 500);

    // Simpan report awal (.txt)
    saveResultsToTxt($jobId, $job);

    $spawn = spawnBackgroundWorker($jobId);

    jsonResponse([
        'ok'         => true,
        'job_id'     => $jobId,
        'spawned'    => $spawn,
        'total'      => count($tasks),
        'domains'    => count($domains),
        'sub_paths'  => $subPaths,
        'size'       => $totalSize,
        'report_url' => '?action=report&job_id=' . $jobId,
    ]);
}

// ---------- 3. STATUS POLLING ----------
function handleStatus() {
    $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['job_id'] ?? '');
    $job = readJob($jobId);
    if (!$job) jsonResponse(['error' => 'Job tidak ditemukan'], 404);

    if (in_array($job['status'], ['pending', 'running'], true)) {
        $lock = tryAcquireLock($jobId);
        if ($lock !== false) {
            processWorkerBatch($jobId, 5, 12);
            releaseLock($lock);
            $job = readJob($jobId);
        }
    }

    unset($job['text_content'], $job['merged_path']);
    jsonResponse(['ok' => true, 'job' => $job]);
}

// ---------- 4. WORKER WEB FALLBACK ----------
function handleWorkerWeb() {
    $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['job_id'] ?? '');
    if (!$jobId) jsonResponse(['error' => 'Missing job_id'], 400);

    $lock = tryAcquireLock($jobId);
    if ($lock === false) jsonResponse(['ok' => true, 'note' => 'Worker lain sedang jalan']);
    processJobFull($jobId);
    releaseLock($lock);
    jsonResponse(['ok' => true]);
}

// ---------- 5. DOWNLOAD REPORT (.txt) ----------  <-- BARU
function handleDownloadReport() {
    $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['job_id'] ?? '');
    if (!$jobId) jsonResponse(['error' => 'Missing job_id'], 400);

    $path = reportPath($jobId);

    // Kalau belum ada / terhapus → generate ulang dari job JSON
    if (!file_exists($path)) {
        $job = readJob($jobId);
        if (!$job) jsonResponse(['error' => 'Job tidak ditemukan'], 404);
        saveResultsToTxt($jobId, $job);
    }
    if (!file_exists($path)) jsonResponse(['error' => 'Report tidak ditemukan'], 404);

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="massup-report-' . $jobId . '.txt"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

// ============================================================
// WORKER
// ============================================================
function processJobFull($jobId) {
    set_time_limit(0);
    ignore_user_abort(true);
    for ($i = 0; $i < 10000; $i++) {
        $job = readJob($jobId);
        if (!$job || in_array($job['status'], ['completed', 'failed'], true)) return;
        processWorkerBatch($jobId, 10, 20);
    }
}

function processWorkerBatch($jobId, $maxTasks = 5, $timeLimit = 15) {
    global $JOBS_DIR;
    set_time_limit($timeLimit + 10);

    $job = readJob($jobId);
    if (!$job || in_array($job['status'], ['completed', 'failed'], true)) return;

    $start = time();

    if ($job['status'] === 'pending') {
        $job['status'] = 'running';
        $job['started_at'] = time();
        writeJob($jobId, $job);
    }

    // Sumber data
    $streamPath = null;
    $textData   = null;
    if ($job['mode'] === 'file') {
        $streamPath = $JOBS_DIR . '/' . $job['merged_path'];
        if (!file_exists($streamPath)) {
            $job['status'] = 'failed';
            $job['error'] = 'File sumber hilang';
            writeJob($jobId, $job);
            saveResultsToTxt($jobId, $job);
            return;
        }
    } else {
        $textData = $job['text_content'];
    }

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $tasks     = $job['tasks'];
    $total     = count($tasks);
    $processed = 0;

    while ($job['progress']['done'] < $total && $processed < $maxTasks) {
        $idx    = $job['progress']['done'];
        $task   = $tasks[$idx];
        $domain = $task['domain'];
        $subPath = $task['sub_path'];

        $domainPath = $job['base_path'] . '/' . $domain;
        $targetDir  = ($subPath !== '') ? ($domainPath . '/' . $subPath) : $domainPath;
        $uploadPath = $targetDir . '/' . $job['file_name'];

        $result = [
            'domain'      => $domain,
            'sub_path'    => $subPath,
            'target_dir'  => $targetDir,
            'upload_path' => $uploadPath,
            'success'     => false,
            'message'     => '',
            'url'         => buildUrl($protocol, $domain, $subPath, $job['file_name']),
        ];

        if (!is_dir($domainPath)) {
            $result['message'] = '❌ Direktori domain tidak ditemukan: ' . $domainPath;
        } else {
            // Auto-create target dir
            if (!is_dir($targetDir)) {
                if (!empty($job['create_dir'])) {
                    if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        $result['message'] = '❌ Gagal buat folder: ' . $targetDir;
                    }
                } else {
                    $result['message'] = '❌ Folder tidak ditemukan (aktifkan "Buat folder otomatis")';
                }
            }

            if ($result['message'] === '') {
                if (!is_writable($targetDir)) {
                    $result['message'] = '❌ Tidak writable (chmod 755): ' . $targetDir;
                } elseif ($job['mode'] === 'file') {
                    $src = @fopen($streamPath, 'rb');
                    $dst = @fopen($uploadPath, 'wb');
                    if ($src && $dst) {
                        $bytes = stream_copy_to_stream($src, $dst);
                        fclose($src); fclose($dst);
                        if ($bytes !== false) {
                            $result['success'] = true;
                            $result['message'] = '✅ Berhasil (' . formatBytes($bytes) . ')';
                        } else {
                            $result['message'] = '⚠️ Gagal menulis';
                        }
                    } else {
                        if ($src) fclose($src);
                        if ($dst) fclose($dst);
                        $result['message'] = '⚠️ Tidak bisa buka file';
                    }
                } else {
                    $bytes = @file_put_contents($uploadPath, $textData);
                    if ($bytes !== false) {
                        $result['success'] = true;
                        $result['message'] = '✅ Berhasil (' . formatBytes($bytes) . ')';
                    } else {
                        $result['message'] = '⚠️ Gagal menulis';
                    }
                }
            }
        }

        $job['progress']['done']++;
        if ($result['success']) $job['progress']['success']++;
        else                    $job['progress']['failed']++;
        $job['progress']['current'] = $domain . ($subPath ? ' / ' . $subPath : '');
        $job['progress']['results'][] = $result;
        $processed++;

        if (time() - $start > $timeLimit) break;
    }

    if ($job['progress']['done'] >= $total) {
        $job['status'] = 'completed';
        $job['finished_at'] = time();
        if ($job['merged_path']) {
            @unlink($JOBS_DIR . '/' . $job['merged_path']);
            $job['merged_path'] = null;
        }
    }

    writeJob($jobId, $job);
    saveResultsToTxt($jobId, $job);   // <-- selalu update report .txt
}

// ============================================================
// REPORT .TXT
// ============================================================
function reportPath($jobId) {
    global $REPORTS_DIR;
    return $REPORTS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $jobId) . '.txt';
}

function buildReportText($job) {
    $sep  = str_repeat('=', 78);
    $sep2 = str_repeat('-', 78);
    $L    = [];

    $created  = !empty($job['created_at'])  ? date('Y-m-d H:i:s', $job['created_at'])  : '-';
    $started  = !empty($job['started_at'])  ? date('Y-m-d H:i:s', $job['started_at'])  : '-';
    $finished = !empty($job['finished_at']) ? date('Y-m-d H:i:s', $job['finished_at']) : '-';

    $total = (int)($job['progress']['total'] ?? 0);
    $done  = (int)($job['progress']['done'] ?? 0);

    $L[] = $sep;
    $L[] = ' MASS UPLOAD REPORT';
    $L[] = $sep;
    $L[] = 'Job ID      : ' . $job['id'];
    $L[] = 'Status      : ' . strtoupper($job['status']);
    $L[] = 'Mode        : ' . $job['mode'];
    $L[] = 'Dibuat      : ' . $created;
    $L[] = 'Mulai       : ' . $started;
    $L[] = 'Selesai     : ' . $finished;
    $L[] = 'Base Path   : ' . $job['base_path'];
    $L[] = 'Nama File   : ' . $job['file_name'];
    $L[] = 'Ukuran      : ' . formatBytes((int)$job['total_size']);
    $L[] = 'Buat Folder : ' . (!empty($job['create_dir']) ? 'Ya' : 'Tidak');
    $L[] = '';
    $L[] = 'Total Task  : ' . $total;
    $L[] = 'Sukses      : ' . (int)$job['progress']['success'];
    $L[] = 'Gagal       : ' . (int)$job['progress']['failed'];
    $L[] = 'Belum       : ' . max(0, $total - $done);
    if (!empty($job['error'])) $L[] = 'Error       : ' . $job['error'];
    $L[] = '';

    // Ringkasan URL yang sukses
    $okUrls = [];
    foreach (($job['progress']['results'] ?? []) as $r) {
        if (!empty($r['success'])) $okUrls[] = $r['url'];
    }
    if ($okUrls) {
        $L[] = $sep2;
        $L[] = ' DAFTAR URL BERHASIL (' . count($okUrls) . ')';
        $L[] = $sep2;
        foreach ($okUrls as $u) $L[] = $u;
        $L[] = '';
    }

    $L[] = $sep2;
    $L[] = ' DETAIL PER TASK';
    $L[] = $sep2;

    $no = 1;
    foreach (($job['progress']['results'] ?? []) as $r) {
        $L[] = sprintf('[%d] %s', $no++, !empty($r['success']) ? 'SUCCESS' : 'FAILED');
        $L[] = '    Domain    : ' . $r['domain'];
        $L[] = '    Sub Path  : ' . ($r['sub_path'] !== '' ? $r['sub_path'] : '(root)');
        $L[] = '    Folder    : ' . $r['target_dir'];
        $L[] = '    File      : ' . $r['upload_path'];
        $L[] = '    URL       : ' . $r['url'];
        $L[] = '    Pesan     : ' . $r['message'];
        $L[] = $sep2;
    }
    if ($no === 1) {
        $L[] = '(belum ada hasil)';
        $L[] = $sep2;
    }

    $L[] = 'Dibuat oleh Mass Upload v3 — ' . date('Y-m-d H:i:s');
    $L[] = $sep;

    return implode("\r\n", $L) . "\r\n";
}

function saveResultsToTxt($jobId, $job) {
    if (!$job) return false;
    $path = reportPath($jobId);
    $txt  = buildReportText($job);
    $tmp  = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $txt, LOCK_EX) === false) return false;
    return @rename($tmp, $path);
}

// ============================================================
// SPAWN
// ============================================================
function spawnBackgroundWorker($jobId) {
    $php    = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $script = __FILE__;

    if (function_exists('exec') && !isFunctionDisabled('exec')) {
        $cmd = sprintf('nohup %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php), escapeshellarg($script), escapeshellarg($jobId));
        @exec($cmd, $out, $ret);
        if ($ret === 0) return 'exec';
    }
    if (function_exists('popen') && !isFunctionDisabled('popen')) {
        $cmd = sprintf('%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php), escapeshellarg($script), escapeshellarg($jobId));
        @popen($cmd, 'r');
        return 'popen';
    }
    return 'client-poll';
}

// ============================================================
// HELPERS
// ============================================================
function isFunctionDisabled($fn) {
    $dis = ini_get('disable_functions');
    return $dis && in_array($fn, array_map('trim', explode(',', $dis)));
}

function normalizeSubPath($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    if ($p === '') return '';
    $segments = [];
    foreach (explode('/', $p) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $seg = preg_replace('/[^a-zA-Z0-9._\-]/', '', $seg);
        if ($seg === '') continue;
        $segments[] = $seg;
    }
    return implode('/', $segments);
}

function buildUrl($protocol, $domain, $subPath, $fileName) {
    $webRoots = ['public_html', 'public', 'htdocs', 'www', 'wwwroot', 'httpdocs'];
    $urlPath  = $subPath;
    foreach ($webRoots as $wr) {
        if ($urlPath === $wr) { $urlPath = ''; break; }
        if (strpos($urlPath, $wr . '/') === 0) {
            $urlPath = substr($urlPath, strlen($wr) + 1);
            break;
        }
    }
    return $protocol . $domain . '/' . ($urlPath !== '' ? $urlPath . '/' : '') . $fileName;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function getJobPath($id) { global $JOBS_DIR; return $JOBS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $id) . '.json'; }
function readJob($id) { $f = getJobPath($id); return file_exists($f) ? (json_decode((string)@file_get_contents($f), true) ?: null) : null; }
function writeJob($id, $data) {
    $f = getJobPath($id); $tmp = $f . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode($data)) === false) return false;
    return @rename($tmp, $f);
}
function tryAcquireLock($id) {
    global $LOCK_DIR;
    $file = $LOCK_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $id) . '.lock';
    $fp = @fopen($file, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return false; }
    return ['fp' => $fp];
}
function releaseLock($lock) {
    if ($lock && isset($lock['fp'])) { flock($lock['fp'], LOCK_UN); fclose($lock['fp']); }
}
function parseIniSize($val) {
    $val = trim((string)$val);
    if ($val === '' || $val === '-1') return -1;
    $num = (int)$val;
    switch (strtolower($val[strlen($val) - 1])) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}
function formatBytes($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}
function cleanupOldFiles($dir) {
    $now = time(); $max = 86400;
    foreach (glob($dir . '/chunks/*', GLOB_ONLYDIR) as $d) {
        if (is_dir($d) && ($now - filemtime($d)) > $max) {
            array_map('unlink', glob($d . '/*') ?: []);
            @rmdir($d);
        }
    }
    foreach (glob($dir . '/jobs/*.json') as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if ($j && ($now - ($j['created_at'] ?? 0)) > $max) {
            if (!empty($j['merged_path'])) @unlink($dir . '/jobs/' . $j['merged_path']);
            @unlink($f);
            @unlink(reportPath($j['id'] ?? ''));
        }
    }
    // bersihkan report lama
    foreach (glob($dir . '/reports/*.txt') as $f) {
        if (($now - filemtime($f)) > $max) @unlink($f);
    }
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mass Upload — Chunked + Mass Path</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,#0a0e27,#1a1f35);min-height:100vh;padding:20px;color:#fff}
.container{max-width:1400px;margin:0 auto}
.header{text-align:center;margin-bottom:30px}
h1{font-size:30px;font-weight:600;margin-bottom:8px}
.subtitle{color:#8892b0;font-size:14px}
.card{background:rgba(30,35,55,.95);border-radius:20px;padding:30px;margin-bottom:25px;border:1px solid rgba(255,255,255,.1)}
.form-group{margin-bottom:22px}
label{display:block;color:#ccd6f6;margin-bottom:10px;font-weight:500}
input[type=text],textarea,select,input[type=file]{width:100%;padding:15px;background:#1e2438;border:2px solid #2d3548;border-radius:10px;color:#fff;font-size:14px;transition:.3s}
input[type=file]{padding:12px;cursor:pointer}
input[type=text]:focus,textarea:focus,select:focus{outline:none;border-color:#64ffda;background:#252d45}
textarea{font-family:monospace;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.upload-option{display:flex;gap:20px;margin-bottom:20px;padding:15px;background:rgba(100,255,218,.05);border-radius:10px;flex-wrap:wrap}
.upload-option label{display:flex;align-items:center;gap:8px;cursor:pointer;margin:0}
.upload-option input[type=radio]{width:auto;transform:scale(1.2)}
button{background:linear-gradient(135deg,#64ffda,#4cd9b8);color:#0a0e27;border:0;padding:14px 34px;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;transition:.3s}
button:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 10px 25px rgba(100,255,218,.3)}
button:disabled{opacity:.5;cursor:not-allowed}
.current-info{background:rgba(100,255,218,.05);padding:15px;border-radius:10px;margin-bottom:20px;font-size:13px;line-height:1.8}
code{color:#64ffda;background:rgba(0,0,0,.3);padding:2px 6px;border-radius:4px;font-size:12px}
.progress-bar{width:100%;height:26px;background:#1e2438;border-radius:13px;overflow:hidden;margin:12px 0}
.progress-bar>div{height:100%;background:linear-gradient(90deg,#64ffda,#4cd9b8);color:#0a0e27;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;transition:width .3s ease;width:0}
.job-status{color:#ccd6f6;margin:12px 0;font-size:14px}
.results-table{overflow-x:auto;max-height:500px;overflow-y:auto;margin-top:15px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:11px;text-align:left;border-bottom:1px solid #2d3548;vertical-align:top}
th{background:#1e2438;color:#64ffda;position:sticky;top:0;z-index:10}
td{color:#ccd6f6}
.status-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600}
.status-success{background:rgba(100,255,218,.2);color:#64ffda}
.status-error{background:rgba(255,85,85,.2);color:#f55}
.test-link{color:#64ffda;text-decoration:none;word-break:break-all}
.test-link:hover{text-decoration:underline}
small{color:#8892b0;font-size:11px}
.checkbox-row{display:flex;align-items:center;gap:10px;padding:12px;background:rgba(100,255,218,.05);border-radius:10px}
.checkbox-row input[type=checkbox]{width:auto;transform:scale(1.3);cursor:pointer}
.checkbox-row label{margin:0;cursor:pointer}
.example{background:#0a0e27;padding:12px;border-radius:8px;font-family:monospace;font-size:12px;margin-top:10px;color:#8892b0;overflow-x:auto;white-space:pre}
.btn-download{display:inline-block;margin-top:6px;padding:12px 26px;border-radius:10px;background:linear-gradient(135deg,#4cd9b8,#2ea88f);color:#0a0e27;font-weight:700;font-size:14px;text-decoration:none;transition:.3s}
.btn-download:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(76,217,184,.3)}
@media(max-width:768px){.row{grid-template-columns:1fr}.card{padding:20px}th,td{padding:8px;font-size:11px}}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📤 Mass Upload — Chunked + Mass Path</h1>
        <div class="subtitle">1 file → banyak domain × banyak sub-path, anti-timeout</div>
    </div>

    <div class="current-info">
        <strong>📂 Info Server:</strong><br>
        Document Root: <code><?= htmlspecialchars($docRoot) ?></code><br>
        Chunk Size: <code>512 KB</code> &middot;
        Post Max: <code><?= htmlspecialchars(ini_get('post_max_size')) ?></code> &middot;
        Upload Max: <code><?= htmlspecialchars(ini_get('upload_max_filesize')) ?></code>
    </div>

    <div id="chunkPanel" class="card" style="display:none">
        <h3 style="color:#64ffda;margin-bottom:15px">📤 Mengunggah File (Chunked)</h3>
        <div class="progress-bar"><div id="chunkBar">0%</div></div>
        <div id="chunkStatus" style="color:#8892b0;font-size:13px"></div>
    </div>

    <div id="jobPanel" class="card" style="display:none">
        <h3 style="color:#64ffda;margin-bottom:15px">📊 Progress Upload</h3>
        <div class="progress-bar"><div id="jobBar">0%</div></div>
        <div id="jobStatus" class="job-status"></div>
        <div id="reportWrap"></div>
        <div class="results-table">
            <table>
                <thead><tr><th>Domain</th><th>Sub Path</th><th>Status</th><th>Akses</th></tr></thead>
                <tbody id="resultsBody"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <form id="uploadForm">
            <div class="row">
                <div class="form-group">
                    <label>📁 Base Path:</label>
                    <input type="text" id="base_path" placeholder="/home/u431156499/domains/"
                           value="<?= htmlspecialchars($docRoot) ?>">
                    <small>Folder yang berisi domain-domain Anda</small>
                </div>
                <div class="form-group">
                    <label>📋 Daftar Domain (pisahkan baris baru):</label>
                    <textarea id="domains" rows="5" placeholder="domain1.com&#10;domain2.com&#10;domain3.com" required></textarea>
                </div>
            </div>

            <div class="form-group">
                <label>📂 Sub Path dalam Domain (satu per baris — MASS PATH):</label>
                <textarea id="sub_paths" rows="3" placeholder="public_html&#10;public_html/uploads&#10;public_html/backup">public_html</textarea>
                <small>Masing-masing path akan dibuatkan folder-nya di setiap domain. Kosongkan untuk upload ke root domain.</small>
                <div class="example">Contoh hasil:
/home/u431156499/domains/domain1.com/public_html/upload.txt
/home/u431156499/domains/domain1.com/public_html/uploads/upload.txt
/home/u431156499/domains/domain2.com/public_html/upload.txt
/home/u431156499/domains/domain2.com/public_html/uploads/upload.txt</div>
            </div>

            <div class="checkbox-row form-group">
                <input type="checkbox" id="create_dir" checked>
                <label for="create_dir">🛠️ Buat folder otomatis jika belum ada (mkdir -p)</label>
            </div>

            <div class="upload-option">
                <label><input type="radio" name="upload_type" value="text" checked> 📝 Text Content</label>
                <label><input type="radio" name="upload_type" value="file"> 📎 Upload File</label>
            </div>

            <div id="text_upload_section">
                <div class="form-group">
                    <label>📄 Nama File:</label>
                    <input type="text" id="file_name" value="upload.txt">
                </div>
                <div class="form-group">
                    <label>✏️ Konten:</label>
                    <textarea id="file_content" rows="8" placeholder="Isi konten file..."></textarea>
                </div>
            </div>

            <div id="file_upload_section" style="display:none">
                <div class="form-group">
                    <label>📎 Pilih File:</label>
                    <input type="file" id="upload_file">
                    <small>Chunk size: 512 KB &middot; File besar tetap aman (streamed)</small>
                </div>
            </div>

            <button type="submit" id="submitBtn">🚀 Upload ke Semua Domain × Semua Path</button>
        </form>
    </div>
</div>

<script>
const CHUNK_SIZE = 512 * 1024;
const API = '?';

document.querySelectorAll('input[name="upload_type"]').forEach(r =>
    r.addEventListener('change', toggleUploadType));
document.getElementById('uploadForm').addEventListener('submit', async e => {
    e.preventDefault(); await handleSubmit();
});

function toggleUploadType() {
    const t = document.querySelector('input[name="upload_type"]:checked').value;
    document.getElementById('text_upload_section').style.display = t === 'text' ? 'block' : 'none';
    document.getElementById('file_upload_section').style.display = t === 'file' ? 'block' : 'none';
}

async function handleSubmit() {
    const btn = document.getElementById('submitBtn');
    const domains = document.getElementById('domains').value.trim();
    if (!domains) { alert('Isi daftar domain!'); return; }
    btn.disabled = true;

    try {
        const mode = document.querySelector('input[name="upload_type"]:checked').value;
        const payload = {
            domains:      domains,
            base_path:    document.getElementById('base_path').value.trim(),
            sub_paths:    document.getElementById('sub_paths').value,
            create_dir:   document.getElementById('create_dir').checked ? 1 : 0,
            mode:         mode
        };

        if (mode === 'file') {
            const file = document.getElementById('upload_file').files[0];
            if (!file) { alert('Pilih file!'); return; }
            document.getElementById('chunkPanel').style.display = 'block';
            payload.file_id   = await uploadChunked(file);
            payload.file_name = file.name;
        } else {
            payload.file_name    = document.getElementById('file_name').value || 'upload.txt';
            payload.file_content = document.getElementById('file_content').value;
        }

        const res = await fetch(API + 'action=start_job', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        document.getElementById('chunkPanel').style.display = 'none';
        document.getElementById('jobPanel').style.display = 'block';
        document.getElementById('jobPanel').scrollIntoView({behavior: 'smooth'});

        await pollJob(data.job_id);
    } catch (err) {
        console.error(err); alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
    }
}

async function uploadChunked(file) {
    const total  = Math.ceil(file.size / CHUNK_SIZE);
    const fileId = 'f' + Date.now() + Math.random().toString(36).substr(2, 8);
    for (let i = 0; i < total; i++) {
        const start = i * CHUNK_SIZE;
        const end   = Math.min(start + CHUNK_SIZE, file.size);
        const fd = new FormData();
        fd.append('chunk', file.slice(start, end));
        fd.append('file_id', fileId);
        fd.append('chunk_index', i);
        fd.append('total_chunks', total);
        const res = await fetch(API + 'action=upload_chunk', {method: 'POST', body: fd});
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const pct = Math.round(((i + 1) / total) * 100);
        document.getElementById('chunkBar').style.width = pct + '%';
        document.getElementById('chunkBar').textContent = pct + '%';
        document.getElementById('chunkStatus').textContent =
            `Chunk ${i + 1}/${total} — ${fmt(end)} / ${fmt(file.size)}`;
    }
    return fileId;
}

async function pollJob(jobId) {
    while (true) {
        const res  = await fetch(API + 'action=status&job_id=' + encodeURIComponent(jobId));
        const data = await res.json();
        if (data.error) { alert(data.error); break; }
        renderJob(data.job);
        if (data.job.status === 'completed' || data.job.status === 'failed') break;
        await new Promise(r => setTimeout(r, 1500));
    }
}

function renderJob(job) {
    const total = job.progress.total, done = job.progress.done;
    const pct = total ? Math.round(done / total * 100) : 0;
    const bar = document.getElementById('jobBar');
    bar.style.width = pct + '%';
    bar.textContent = pct + '%';

    let txt = '';
    if (job.status === 'pending')        txt = '⏳ Menunggu worker dimulai...';
    else if (job.status === 'running')   txt = `⚙️ Memproses: ${job.progress.current || '...'} (${done}/${total})`;
    else if (job.status === 'completed') txt = `✅ Selesai! ${job.progress.success} sukses, ${job.progress.failed} gagal (dari ${total} task)`;
    else if (job.status === 'failed')    txt = '❌ Gagal: ' + (job.error || 'unknown');
    document.getElementById('jobStatus').textContent = txt;

    // Tombol download hasil .txt
    const rw = document.getElementById('reportWrap');
    if (done > 0) {
        const label = (job.status === 'completed' || job.status === 'failed')
            ? '⬇️ Download Hasil (.txt)'
            : '⬇️ Download Hasil Sementara (.txt)';
        rw.innerHTML = `<a class="btn-download" href="${API}action=report&job_id=${encodeURIComponent(job.id)}">${label}</a>`;
    } else {
        rw.innerHTML = '';
    }

    const tb = document.getElementById('resultsBody');
    tb.innerHTML = '';
    job.progress.results.forEach(r => {
        const sub = r.sub_path
            ? `<strong>${esc(r.sub_path)}</strong>`
            : '<em style="color:#8892b0">(root)</em>';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${esc(r.domain)}</strong></td>
            <td>${sub}<br><small>${esc(r.target_dir || '')}</small></td>
            <td>
                <span class="status-badge ${r.success ? 'status-success' : 'status-error'}">
                    ${r.success ? '✅ SUCCESS' : '❌ FAILED'}
                </span><br><small>${esc(r.message)}</small>
            </td>
            <td>${r.success ? `<a href="${esc(r.url)}" target="_blank" class="test-link">🔗 Buka</a><br><small>${esc(r.url)}</small>` : '-'}</td>`;
        tb.appendChild(tr);
    });
}

function fmt(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    return (b / 1073741824).toFixed(2) + ' GB';
}
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>
</body>
</html>