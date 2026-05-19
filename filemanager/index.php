<?php
session_start();
$base_dir = realpath('/var/www/localhost/htdocs');
require_once __DIR__ . '/vendor/autoload.php';

function get_absolute_path($path) {
    global $base_dir;
    $path = str_replace(['../', '..\\'], '', $path);
    $real = realpath($base_dir . '/' . ltrim($path, '/'));
    if ($real === false && !file_exists($base_dir . '/' . ltrim($path, '/'))) {
        return $base_dir . '/' . ltrim($path, '/');
    }
    if ($real !== false && strpos($real, $base_dir) !== 0) return $base_dir;
    return $real !== false ? $real : $base_dir . '/' . ltrim($path, '/');
}

function get_relative_path($path) {
    global $base_dir;
    return ltrim(substr($path, strlen($base_dir)), '/');
}

function format_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function get_mime($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'json' => 'application/json', 'php' => 'text/plain',
        'xml' => 'text/xml', 'md' => 'text/plain', 'sh' => 'text/plain', 'zip' => 'application/zip',
        'tar' => 'application/x-tar', 'gz' => 'application/gzip', 'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function sftp_connect() {
    if (!class_exists('phpseclib3\Net\SFTP')) return false;
    if (empty($_SESSION['sftp'])) return false;
    $cfg = $_SESSION['sftp'];
    try {
        $sftp = new phpseclib3\Net\SFTP($cfg['host'], $cfg['port'] ?? 22);
        if (!$sftp->login($cfg['user'], $cfg['pass'])) return false;
        return $sftp;
    } catch (Exception $e) {
        return false;
    }
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $dir = isset($_GET['dir']) ? $_GET['dir'] : '';
    $is_sftp = !empty($_SESSION['sftp_active']);
    
    // --- TERMINAL LOGIC START ---
    if ($action === 'terminal') {
        $req = json_decode(file_get_contents('php://input'), true) ?: [];
        $cwd = $_SESSION['term_cwd'] ?? $base_dir;
        $cmd = trim($req['cmd'] ?? '');
        if (!$cmd) { echo json_encode(['out' => '', 'cwd' => $cwd]); exit; }
        
        $escaped_cwd = escapeshellarg($cwd);
        $full = "cd {$escaped_cwd} 2>/dev/null; {$cmd}; printf '__PWD__%s' \"\$(pwd)\"";
        $raw = shell_exec($full . ' 2>&1') ?? '';
        $pos = strrpos($raw, '__PWD__');
        
        if ($pos !== false) {
            $out = substr($raw, 0, $pos);
            $_SESSION['term_cwd'] = trim(substr($raw, $pos + 7));
        } else {
            $out = $raw;
        }
        echo json_encode(['out' => htmlspecialchars($out), 'cwd' => $_SESSION['term_cwd']]);
        exit;
    }
    // --- TERMINAL LOGIC END ---

    if ($action === 'sftp_connect') {
        $req = json_decode(file_get_contents('php://input'), true) ?: [];
        $_SESSION['sftp'] = [
            'host' => $req['host'],
            'port' => $req['port'] ?? 22,
            'user' => $req['user'],
            'pass' => $req['pass']
        ];
        $sftp = sftp_connect();
        if ($sftp) {
            $_SESSION['sftp_active'] = true;
            $_SESSION['sftp_cwd'] = $sftp->pwd();
            echo json_encode(['status' => 'success', 'cwd' => $_SESSION['sftp_cwd']]);
        } else {
            unset($_SESSION['sftp']);
            echo json_encode(['status' => 'error', 'message' => 'Connection failed']);
        }
        exit;
    }
    if ($action === 'sftp_disconnect') {
        unset($_SESSION['sftp'], $_SESSION['sftp_active'], $_SESSION['sftp_cwd']);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($is_sftp) {
        $sftp = sftp_connect();
        if (!$sftp) {
            echo json_encode(['status' => 'error', 'message' => 'SFTP connection lost']);
            exit;
        }
        $remote_cwd = $_SESSION['sftp_cwd'] ?? '/';
        $target_path = $remote_cwd . '/' . ltrim($dir, '/');
        try {
            if ($action === 'list') {
                $list = $sftp->rawlist($remote_cwd);
                $items = [];
                if ($list) {
                    foreach ($list as $name => $attrs) {
                        if ($name === '.' || $name === '..') continue;
                        $is_dir = $attrs['type'] == NET_SFTP_TYPE_DIRECTORY;
                        $items[] = [
                            'name' => $name,
                            'is_dir' => $is_dir,
                            'size' => $is_dir ? 0 : $attrs['size'],
                            'perms' => substr(sprintf('%o', $attrs['permissions'] ?? 0), -4),
                            'mtime' => $attrs['mtime'],
                            'ext' => $is_dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                            'mime' => $is_dir ? 'folder' : get_mime($name),
                        ];
                    }
                }
                usort($items, function ($a, $b) {
                    if ($a['is_dir'] === $b['is_dir']) return strcasecmp($a['name'], $b['name']);
                    return $a['is_dir'] ? -1 : 1;
                });
                echo json_encode(['status' => 'success', 'path' => $remote_cwd, 'items' => $items]);
                exit;
            }
            if ($action === 'download') {
                $req = json_decode(file_get_contents('php://input'), true) ?: [];
                $file = $req['path'] ?? $target_path;
                if ($sftp->is_file($file)) {
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                    header('Content-Length: ' . $sftp->size($file));
                    echo $sftp->get($file);
                }
                exit;
            }
            if ($action === 'preview') {
                $req = json_decode(file_get_contents('php://input'), true) ?: [];
                $file = $req['path'] ?? $target_path;
                if ($sftp->is_file($file)) {
                    $mime = get_mime($file);
                    header('Content-Type: ' . $mime);
                    echo $sftp->get($file);
                }
                exit;
            }
            if ($action === 'mkdir') {
                $sftp->mkdir($target_path . '/' . basename($req['name'] ?? 'newfolder'), 0755, true);
            } elseif ($action === 'mkfile') {
                $sftp->put($target_path . '/' . basename($req['name'] ?? 'newfile.txt'), '');
            } elseif ($action === 'delete') {
                foreach ((array)($req['paths'] ?? []) as $p) {
                    $full = $sftp->is_file($p) ? $p : $remote_cwd . '/' . ltrim($p, '/');
                    if ($sftp->is_dir($full)) $sftp->delete($full, true); else $sftp->delete($full);
                }
            } elseif ($action === 'rename') {
                $sftp->rename($remote_cwd . '/' . basename($req['old']), $remote_cwd . '/' . basename($req['new']));
            } elseif ($action === 'move' || $action === 'copy') {
                foreach ((array)($req['paths'] ?? []) as $p) {
                    $src = $remote_cwd . '/' . basename($p);
                    $dst = $remote_cwd . '/' . basename($req['dst']) . '/' . basename($p);
                    if ($action === 'move') $sftp->rename($src, $dst);
                    else {
                        $content = $sftp->get($src);
                        $sftp->put($dst, $content);
                    }
                }
            } elseif ($action === 'read') {
                echo json_encode(['status' => 'success', 'content' => $sftp->get($req['path'] ?? $target_path), 'mime' => get_mime($req['path'] ?? $target_path)]);
                exit;
            } elseif ($action === 'save') {
                $sftp->put($req['path'] ?? $target_path, $req['content']);
            } elseif ($action === 'zip') {
                $paths = (array)($req['paths'] ?? []);
                $zipName = $req['name'] ?? (basename($paths[0]) . '.zip');
                $tmpFile = tempnam(sys_get_temp_dir(), 'sftpzip');
                $zip = new ZipArchive();
                if ($zip->open($tmpFile, ZipArchive::CREATE) === true) {
                    foreach ($paths as $p) {
                        $localName = basename($p);
                        if ($sftp->is_dir($p)) {
                            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('php://temp'));
                        } else {
                            $zip->addFromString($localName, $sftp->get($p));
                        }
                    }
                    $zip->close();
                    $sftp->put($remote_cwd . '/' . $zipName, file_get_contents($tmpFile));
                    unlink($tmpFile);
                }
            } elseif ($action === 'unzip') {
                $tmpFile = tempnam(sys_get_temp_dir(), 'sftpunzip');
                file_put_contents($tmpFile, $sftp->get($req['path'] ?? $target_path));
                $zip = new ZipArchive();
                if ($zip->open($tmpFile) === true) {
                    $dest = dirname($req['path'] ?? $target_path);
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (substr($name, -1) === '/') continue;
                        $sftp->put($dest . '/' . $name, $zip->getFromIndex($i));
                    }
                    $zip->close();
                }
                unlink($tmpFile);
            } elseif ($action === 'chmod') {
                $sftp->chmod(octdec($req['perms']), $req['path'] ?? $target_path);
            } elseif ($action === 'diskusage') {
                $stat = $sftp->stat($remote_cwd);
                $total = $sftp->size($remote_cwd) * 10;
                $free = 0;
                echo json_encode(['status' => 'success', 'total' => $total, 'free' => $free, 'used' => $total - $free]);
                exit;
            }
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    $current_path = get_absolute_path($dir);
    try {
        if ($action === 'list') {
            $items = [];
            if (is_dir($current_path)) {
                $files = scandir($current_path);
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $full = $current_path . '/' . $f;
                    $is_dir = is_dir($full);
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    $items[] = [
                        'name' => $f,
                        'is_dir' => $is_dir,
                        'size' => $is_dir ? 0 : filesize($full),
                        'perms' => substr(sprintf('%o', fileperms($full)), -4),
                        'mtime' => filemtime($full),
                        'ext' => $ext,
                        'mime' => $is_dir ? 'folder' : get_mime($full),
                    ];
                }
            }
            usort($items, function ($a, $b) {
                if ($a['is_dir'] === $b['is_dir']) return strcasecmp($a['name'], $b['name']);
                return $a['is_dir'] ? -1 : 1;
            });
            echo json_encode(['status' => 'success', 'path' => get_relative_path($current_path), 'items' => $items]);
            exit;
        }
        if ($action === 'upload' && !empty($_FILES['files'])) {
            $uploaded = [];
            foreach ($_FILES['files']['name'] as $key => $name) {
                $dest = $current_path . '/' . basename($name);
                if (move_uploaded_file($_FILES['files']['tmp_name'][$key], $dest)) {
                    $uploaded[] = $name;
                }
            }
            echo json_encode(['status' => 'success', 'uploaded' => $uploaded]);
            exit;
        }
        $req = json_decode(file_get_contents('php://input'), true);
        if (!$req) $req = [];
        if ($action === 'mkdir') {
            $path = $current_path . '/' . basename($req['name']);
            mkdir($path, 0755, true);
        } elseif ($action === 'mkfile') {
            file_put_contents($current_path . '/' . basename($req['name']), '');
        } elseif ($action === 'delete') {
            foreach ((array)$req['paths'] as $p) {
                $target = get_absolute_path($p);
                if (is_dir($target)) shell_exec("rm -rf " . escapeshellarg($target));
                else unlink($target);
            }
        } elseif ($action === 'rename') {
            rename(get_absolute_path($req['old']), get_absolute_path($req['new']));
        } elseif ($action === 'move' || $action === 'copy') {
            foreach ((array)$req['paths'] as $p) {
                $src = get_absolute_path($p);
                $dst = get_absolute_path($req['dst']) . '/' . basename($src);
                if ($action === 'move') rename($src, $dst);
                else shell_exec("cp -r " . escapeshellarg($src) . " " . escapeshellarg($dst));
            }
        } elseif ($action === 'read') {
            $target = get_absolute_path($req['path']);
            echo json_encode(['status' => 'success', 'content' => file_get_contents($target), 'mime' => get_mime($target)]);
            exit;
        } elseif ($action === 'save') {
            $target = get_absolute_path($req['path']);
            file_put_contents($target, $req['content']);
        } elseif ($action === 'zip') {
            $paths = (array)$req['paths'];
            $zipName = $req['name'] ?? (basename($paths[0]) . '.zip');
            $zipPath = $current_path . '/' . $zipName;
            $args = implode(' ', array_map(fn($p) => escapeshellarg(basename(get_absolute_path($p))), $paths));
            shell_exec("cd " . escapeshellarg($current_path) . " && zip -r " . escapeshellarg($zipPath) . " " . $args);
        } elseif ($action === 'unzip') {
            $target = get_absolute_path($req['path']);
            $dest = dirname($target);
            shell_exec("unzip -o " . escapeshellarg($target) . " -d " . escapeshellarg($dest));
        } elseif ($action === 'search') {
            $query = strtolower($req['query'] ?? '');
            $results = [];
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($current_path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                if (strpos(strtolower($file->getFilename()), $query) !== false) {
                    $rel = get_relative_path($file->getPathname());
                    $results[] = ['name' => $file->getFilename(), 'path' => $rel, 'is_dir' => $file->isDir(), 'size' => $file->isDir() ? 0 : $file->getSize(), 'mtime' => $file->getMTime(), 'ext' => strtolower($file->getExtension())];
                    if (count($results) >= 100) break;
                }
            }
            echo json_encode(['status' => 'success', 'results' => $results]);
            exit;
        } elseif ($action === 'chmod') {
            $target = get_absolute_path($req['path']);
            chmod($target, octdec($req['perms']));
        } elseif ($action === 'diskusage') {
            $total = disk_total_space($current_path);
            $free = disk_free_space($current_path);
            echo json_encode(['status' => 'success', 'total' => $total, 'free' => $free, 'used' => $total - $free]);
            exit;
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>File Manager Pro</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@latest/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f8f9fa; font-size: 0.9rem; }
  .sidebar { width: 240px; background: #fff; border-right: 1px solid #dee2e6; min-height: 100vh; }
  .sidebar .nav-link { color: #495057; padding: 0.5rem 1rem; border-radius: 0.375rem; margin: 0.125rem 0.5rem; }
  .sidebar .nav-link.active { background: #e9ecef; color: #0d6efd; }
  .sidebar .nav-link:hover { background: #f1f3f5; }
  .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
  .grid-view { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.5rem; }
  .list-view .file-row { display: grid; grid-template-columns: 2rem 2rem 1fr 6rem 5rem 5rem 5rem; align-items: center; padding: 0.4rem 0.75rem; border-bottom: 1px solid #eee; }
  .list-view .file-row:hover { background: #f8f9fa; }
  .file-card { cursor: pointer; transition: all 0.15s; border: 1px solid #dee2e6; border-radius: 0.375rem; background: #fff; }
  .file-card:hover { transform: translateY(-2px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1); }
  .file-card.selected { border-color: #0d6efd; background: #e7f1ff; }
  .offcanvas-lg { width: 240px; }
  @media (max-width: 991.98px) { .sidebar { display: none; } }
  .toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 1060; }
  .breadcrumb-item + .breadcrumb-item::before { content: "›"; }
  #drop-overlay { display: none; position: absolute; inset: 0; background: rgba(13,110,253,0.05); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
  .modal-xl-custom { max-width: 90vw; }
</style>
</head>
<body class="d-flex">
<div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="sidebarOffcanvas">
  <div class="offcanvas-header border-bottom"><h5 class="offcanvas-title">File Manager</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div>
  <div class="offcanvas-body p-0" id="sidebar-content-mobile"></div>
</div>
<div class="sidebar d-none d-lg-flex flex-column" id="sidebar-desktop">
  <div class="p-3 border-bottom text-center"><i class="fa-solid fa-folder-tree text-primary me-1"></i> <strong>File Manager</strong></div>
  <div class="flex-grow-1" id="sidebar-content-desktop"></div>
  <div class="p-2 border-top small text-muted" id="disk-info"></div>
</div>
<div class="main-content">
  <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3 py-2">
    <div class="container-fluid p-0">
      <button class="btn btn-outline-secondary d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas"><i class="fa-solid fa-bars"></i></button>
      <span class="navbar-brand mb-0 h1 me-auto d-none d-sm-inline">File Manager Pro</span>
      <form class="d-flex me-2 flex-grow-1" onsubmit="event.preventDefault(); searchFiles(this.search.value)"><input class="form-control me-2" type="search" name="search" placeholder="Search files..."></form>
      <button class="btn btn-primary me-1" onclick="openUpload()"><i class="fa-solid fa-cloud-arrow-up"></i> <span class="d-none d-md-inline">Upload</span></button>
      <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#terminalModal" id="btn-terminal-open"><i class="fa-solid fa-terminal"></i> <span class="d-none d-md-inline">Terminal</span></button>
    </div>
  </nav>
  <div class="bg-light border-bottom px-3 py-1 d-flex align-items-center flex-wrap">
    <div class="btn-group btn-group-sm me-2">
      <button class="btn btn-outline-secondary" onclick="navUp()"><i class="fa-solid fa-arrow-up"></i></button>
      <button class="btn btn-outline-secondary" onclick="load()"><i class="fa-solid fa-arrows-rotate"></i></button>
    </div>
    <div class="btn-group btn-group-sm me-2">
      <button class="btn btn-outline-secondary" onclick="promptAction('mkdir')"><i class="fa-solid fa-folder-plus"></i> Folder</button>
      <button class="btn btn-outline-secondary" onclick="promptAction('mkfile')"><i class="fa-solid fa-file-plus"></i> File</button>
    </div>
    <div class="btn-group btn-group-sm me-2" id="sel-group">
      <button class="btn btn-outline-secondary" onclick="toggleMultiSelect()" id="sel-btn"><i class="fa-solid fa-check-double"></i> Select</button>
      <button class="btn btn-outline-danger" onclick="deleteSelected()" style="display:none" id="del-btn"><i class="fa-solid fa-trash"></i> <span id="sel-count">0</span></button>
      <button class="btn btn-outline-secondary" onclick="zipSelected()" style="display:none" id="zip-btn"><i class="fa-solid fa-file-zipper"></i></button>
      <button class="btn btn-outline-secondary" onclick="clearSelection()" style="display:none" id="clear-sel-btn"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="ms-auto">
      <div class="btn-group btn-group-sm">
        <button class="btn btn-outline-secondary active" onclick="setView('grid')" id="vbtn-grid"><i class="fa-solid fa-grip"></i></button>
        <button class="btn btn-outline-secondary" onclick="setView('list')" id="vbtn-list"><i class="fa-solid fa-list"></i></button>
      </div>
    </div>
  </div>
  <nav aria-label="breadcrumb" class="px-3 py-1 bg-white border-bottom"><ol class="breadcrumb mb-0" id="breadcrumb"></ol></nav>
  <div id="file-area" class="flex-grow-1 overflow-auto p-3 position-relative" ondragover="event.preventDefault(); document.getElementById('drop-overlay').style.display='flex'" ondragleave="document.getElementById('drop-overlay').style.display='none'" ondrop="handleDrop(event)">
    <div id="drop-overlay"><div class="text-center text-primary fs-3"><i class="fa-solid fa-cloud-arrow-up fa-3x"></i><br>Drop files to upload</div></div>
    <div id="file-list" class="grid-view"></div>
  </div>
  <div class="bg-light border-top px-3 py-1 small d-flex justify-content-between" id="statusbar"><span id="sb-items">–</span><span id="sb-path" class="font-monospace text-muted">/</span></div>
</div>

<!-- NATIVE TERMINAL MODAL -->
<div class="modal fade" id="terminalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content" style="background-color: #1e1e1e; border: 1px solid #333;">
      <div class="modal-header border-bottom-0">
        <h5 class="modal-title text-light"><i class="fa-solid fa-terminal"></i> Web Terminal</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3 font-monospace" style="height: 60vh; overflow-y: auto; color: #00ff00;" id="term-body" onclick="document.getElementById('term-input').focus()">
         <div id="term-output" style="white-space: pre-wrap; word-wrap: break-word;">Alpine Server Terminal - Ready.<br></div>
         <div class="d-flex mt-2">
            <span id="term-prompt" class="me-2 text-info">$ /var/www/localhost/htdocs</span>
            <input type="text" id="term-input" style="flex: 1; background: transparent; color: #00ff00; border: none; outline: none; box-shadow: none; font-family: monospace;" autocomplete="off">
         </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="sftpModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-network-wired"></i> SFTP Connection</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Host</label><input type="text" class="form-control" id="sftp-host"></div>
        <div class="mb-3"><label class="form-label">Port</label><input type="number" class="form-control" id="sftp-port" value="22"></div>
        <div class="mb-3"><label class="form-label">Username</label><input type="text" class="form-control" id="sftp-user"></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" id="sftp-pass"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" onclick="sftpConnect()">Connect</button></div>
    </div>
  </div>
</div>
<div class="modal fade" id="promptModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="prompt-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" class="form-control" id="prompt-input" onkeydown="if(event.key==='Enter')promptConfirm()"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" onclick="promptConfirm()">OK</button></div></div></div>
</div>
<div class="modal fade" id="renameModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Rename</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" class="form-control" id="rename-input" onkeydown="if(event.key==='Enter')doRename()"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" onclick="doRename()">Rename</button></div></div></div>
</div>
<div class="modal fade" id="editorModal" tabindex="-1">
  <div class="modal-dialog modal-xl-custom"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="editor-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><textarea id="code-editor" class="form-control font-monospace" rows="25"></textarea></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary" onclick="saveFile()"><i class="fa-solid fa-floppy-disk"></i> Save</button></div></div></div>
</div>
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Upload Files</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="upload-drop" class="border rounded p-4 text-center" onclick="document.getElementById('file-input').click()" ondragover="event.preventDefault(); this.classList.add('bg-light')" ondragleave="this.classList.remove('bg-light')" ondrop="handleUploadDrop(event)"><i class="fa-solid fa-cloud-arrow-up fa-2x"></i><p class="mt-2">Click or drag files here</p></div><input type="file" id="file-input" multiple style="display:none" onchange="uploadFilesChanged(this.files)"><div id="upload-list" class="mt-2"></div></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="upload-btn" onclick="doUpload()" disabled>Upload</button></div></div></div>
</div>
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-xl-custom"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="preview-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="preview-content" style="min-height: 60vh;"></div></div></div>
</div>
<div class="toast-container" id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPath = '', currentItems = [], currentView = 'grid', multiSelect = false, selected = new Set(), sortField = 'name', sortAsc = true, remoteMode = false, remoteCwd = '/', editingPath = '', pendingFiles = [], renameTarget = '', currentPreviewPath = '';

function api(action, data = {}, isUpload = false) {
  const url = `?api=${action}&dir=${encodeURIComponent(remoteMode ? remoteCwd : currentPath)}`;
  if (action === 'list') return fetch(url).then(r => r.json());
  const opts = { method: 'POST' };
  if (isUpload) {
    const fd = new FormData();
    for (const f of data) fd.append('files[]', f);
    opts.body = fd;
  } else {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(data);
  }
  return fetch(url, opts).then(r => r.json());
}

async function load(path) {
  if (remoteMode) {
    if (path !== undefined) remoteCwd = path;
    const res = await api('list');
    if (res.status !== 'success') return;
    remoteCwd = res.path;
    currentItems = res.items;
  } else {
    if (path !== undefined) currentPath = path;
    selected.clear();
    updateSelectionUI();
    const res = await api('list');
    if (res.status !== 'success') return;
    currentPath = res.path;
    currentItems = res.items;
  }
  renderBreadcrumb();
  render();
  updateStatusBar();
  if (!remoteMode) loadDiskUsage();
}

async function loadDiskUsage() {
  const d = await api('diskusage');
  if (d.status !== 'success') return;
  const pct = Math.round(d.used / d.total * 100);
  document.getElementById('disk-info').innerHTML = `<i class="fa-solid fa-hard-drive"></i> Disk: ${pct}%<br><small>${fmtSize(d.used)} / ${fmtSize(d.total)}</small>`;
}

function fmtSize(b) { return b >= 1073741824 ? (b/1073741824).toFixed(2)+' GB' : b >= 1048576 ? (b/1048576).toFixed(2)+' MB' : b >= 1024 ? (b/1024).toFixed(1)+' KB' : b+' B'; }

function getIcon(item) {
  if (item.is_dir) return '<i class="fa-solid fa-folder text-warning"></i>';
  const ext = (item.ext || '').toLowerCase();
  const map = { php:'fa-brands fa-php text-indigo', html:'fa-brands fa-html5 text-danger', htm:'fa-brands fa-html5 text-danger', css:'fa-brands fa-css3-alt text-primary', js:'fa-brands fa-js text-warning', json:'fa-solid fa-brackets-curly text-success', md:'fa-brands fa-markdown', txt:'fa-solid fa-file-lines', pdf:'fa-solid fa-file-pdf text-danger', zip:'fa-solid fa-file-zipper text-orange', tar:'fa-solid fa-file-zipper', gz:'fa-solid fa-file-zipper', mp4:'fa-solid fa-film text-purple', mp3:'fa-solid fa-music text-pink', wav:'fa-solid fa-music' };
  return `<i class="${map[ext] || 'fa-solid fa-file'}"></i>`;
}

function render(items) {
  const list = document.getElementById('file-list');
  const sorted = [...(items || currentItems)].sort((a,b) => {
    if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
    let av = a[sortField], bv = b[sortField];
    if (typeof av === 'string') { av = av.toLowerCase(); bv = bv.toLowerCase(); }
    return sortAsc ? (av < bv ? -1 : 1) : (av > bv ? -1 : 1);
  });
  list.className = currentView === 'grid' ? 'grid-view' : 'list-view';
  list.innerHTML = '';
  if (!sorted.length) { list.innerHTML = '<div class="text-center text-muted py-5"><i class="fa-solid fa-folder-open fa-3x"></i><p>Empty folder</p></div>'; return; }
  sorted.forEach(item => {
    const fullRelPath = remoteMode ? remoteCwd + '/' + item.name : (currentPath ? currentPath + '/' + item.name : item.name);
    if (currentView === 'grid') {
      const card = document.createElement('div');
      card.className = 'file-card p-3 text-center' + (selected.has(fullRelPath) ? ' selected' : '');
      card.dataset.path = fullRelPath;
      card.innerHTML = `<div class="mb-2">${getIcon(item)}</div><div class="small text-truncate">${escHtml(item.name)}</div><div class="small text-muted">${item.is_dir ? '—' : fmtSize(item.size)}</div>`;
      card.onclick = e => handleClick(e, item, fullRelPath, card);
      card.ondblclick = () => handleDblClick(item, fullRelPath);
      card.oncontextmenu = e => showCtx(e, item, fullRelPath);
      list.appendChild(card);
    } else {
      const row = document.createElement('div');
      row.className = 'file-row' + (selected.has(fullRelPath) ? ' selected bg-light' : '');
      row.dataset.path = fullRelPath;
      row.innerHTML = `<div><input type="checkbox" ${selected.has(fullRelPath)?'checked':''} onclick="event.stopPropagation(); toggleSelect('${escJs(fullRelPath)}', this.parentElement.parentElement)"></div><div>${getIcon(item)}</div><div class="text-truncate">${escHtml(item.name)}</div><div class="small text-muted">${item.is_dir ? '—' : fmtSize(item.size)}</div><div class="small text-muted">${item.perms}</div><div class="small text-muted">${new Date(item.mtime*1000).toLocaleDateString()}</div><div class="small text-muted">${item.ext ? item.ext.toUpperCase() : 'Folder'}</div>`;
      row.onclick = e => { if (e.target.tagName !== 'INPUT') handleClick(e, item, fullRelPath, row); };
      row.ondblclick = () => handleDblClick(item, fullRelPath);
      row.oncontextmenu = e => showCtx(e, item, fullRelPath);
      list.appendChild(row);
    }
  });
}

function handleClick(e, item, path, el) {
  if (multiSelect || e.ctrlKey || e.metaKey) {
    toggleSelect(path, el);
  } else if (e.shiftKey && selected.size > 0) {
    rangeSelect(path);
  } else {
    selected.clear();
    document.querySelectorAll('.file-card.selected, .file-row.selected').forEach(x => x.classList.remove('selected'));
    toggleSelect(path, el);
  }
  updateSelectionUI();
}
document.getElementById('file-area').addEventListener('click', function(e) {
  if (e.target === this || e.target.id === 'drop-overlay' || e.target.closest('#drop-overlay')) {
    selected.clear();
    document.querySelectorAll('.file-card.selected, .file-row.selected').forEach(x => x.classList.remove('selected'));
    updateSelectionUI();
  }
});

function toggleSelect(path, el) {
  if (selected.has(path)) { selected.delete(path); el && el.classList.remove('selected'); if (el) { const cb = el.querySelector('input[type=checkbox]'); if (cb) cb.checked = false; } }
  else { selected.add(path); el && el.classList.add('selected'); if (el) { const cb = el.querySelector('input[type=checkbox]'); if (cb) cb.checked = true; } }
  updateSelectionUI();
}
function rangeSelect(path) {
  const items = Array.from(document.querySelectorAll('.file-card, .file-row'));
  const paths = items.map(i => i.dataset.path);
  const lastSel = [...selected].pop();
  const a = paths.indexOf(lastSel), b = paths.indexOf(path);
  if (a < 0 || b < 0) return;
  const [s, e] = a < b ? [a,b] : [b,a];
  paths.slice(s, e+1).forEach(p => { selected.add(p); const el = document.querySelector(`[data-path="${CSS.escape(p)}"]`); el && el.classList.add('selected'); });
  updateSelectionUI();
}
function updateSelectionUI() {
  const n = selected.size;
  document.getElementById('del-btn').style.display = n ? '' : 'none';
  document.getElementById('zip-btn').style.display = n ? '' : 'none';
  document.getElementById('clear-sel-btn').style.display = n ? '' : 'none';
  document.getElementById('sel-count').textContent = n;
}
function clearSelection() { selected.clear(); document.querySelectorAll('.file-card.selected, .file-row.selected').forEach(x => x.classList.remove('selected')); updateSelectionUI(); }
function toggleMultiSelect() {
  multiSelect = !multiSelect;
  const btn = document.getElementById('sel-btn');
  btn.classList.toggle('active', multiSelect);
  if (!multiSelect) { selected.clear(); render(); updateSelectionUI(); }
}
function handleDblClick(item, path) {
  if (item.is_dir) { load(path); return; }
  const ext = (item.ext || '').toLowerCase();
  const images = ['jpg','jpeg','png','gif','webp','svg'];
  const text = ['txt','html','htm','css','js','json','php','xml','md','sh','sql','py','rb','go'];
  const video = ['mp4','webm','ogg'];
  const audio = ['mp3','wav','ogg','m4a'];
  if (images.includes(ext) || video.includes(ext) || audio.includes(ext)) openPreview(item, path);
  else if (text.includes(ext) || item.size < 2*1024*1024) openEditor(path, item.name);
  else openPreview(item, path);
}
function navUp() {
  const parts = (remoteMode ? remoteCwd : currentPath).split('/').filter(p => p);
  parts.pop();
  load(parts.join('/'));
}
function renderBreadcrumb() {
  const bc = document.getElementById('breadcrumb');
  const base = remoteMode ? remoteCwd : currentPath;
  const parts = base.split('/').filter(p => p);
  let html = '<li class="breadcrumb-item"><a href="#" onclick="loadLocalRoot()"><i class="fa-solid fa-house"></i></a></li>';
  let built = '';
  parts.forEach((p, i) => {
    built += (built ? '/' : '') + p;
    html += `<li class="breadcrumb-item${i===parts.length-1?' active':''}"><a href="#" onclick="load('${escHtml(built)}')">${escHtml(p)}</a></li>`;
  });
  bc.innerHTML = html || '<li class="breadcrumb-item active">/</li>';
  document.getElementById('sb-path').textContent = '/' + base;
}
function updateStatusBar() {
  const dirs = currentItems.filter(i => i.is_dir).length;
  const files = currentItems.length - dirs;
  document.getElementById('sb-items').textContent = `${dirs} folder${dirs!==1?'s':''}, ${files} file${files!==1?'s':''}`;
}
function showCtx(e, item, path) {
  e.preventDefault(); e.stopPropagation();
  if (!selected.has(path)) {
    selected.clear();
    document.querySelectorAll('.file-card.selected, .file-row.selected').forEach(x => x.classList.remove('selected'));
    selected.add(path);
    const el = document.querySelector(`[data-path="${CSS.escape(path)}"]`);
    el && el.classList.add('selected');
    updateSelectionUI();
  }
  const menu = document.getElementById('ctx-menu');
  menu.innerHTML = `
    ${!item.is_dir ? `<a class="dropdown-item" onclick="openEditor('${escJs(path)}','${escJs(item.name)}')"><i class="fa-solid fa-pen me-2"></i>Edit</a>` : ''}
    ${!item.is_dir ? `<a class="dropdown-item" onclick="openPreview(currentItemByPath('${escJs(path)}'),'${escJs(path)}')"><i class="fa-solid fa-eye me-2"></i>Preview</a>` : ''}
    ${!item.is_dir ? `<a class="dropdown-item" onclick="downloadFile('${escJs(path)}')"><i class="fa-solid fa-download me-2"></i>Download</a>` : ''}
    <a class="dropdown-item" onclick="openRename('${escJs(path)}','${escJs(item.name)}')"><i class="fa-solid fa-pen-to-square me-2"></i>Rename</a>
    <a class="dropdown-item" onclick="openMoveCopyModal('copy')"><i class="fa-solid fa-copy me-2"></i>Copy to…</a>
    <a class="dropdown-item" onclick="openMoveCopyModal('move')"><i class="fa-solid fa-scissors me-2"></i>Move to…</a>
    <a class="dropdown-item" onclick="${item.ext==='zip' ? `doUnzip('${escJs(path)}')` : `zipSelected()`}"><i class="fa-solid fa-file-zipper me-2"></i>${item.ext==='zip'?'Extract':'Compress'}</a>
    <a class="dropdown-item" onclick="openChmod('${escJs(path)}','${escJs(item.perms)}')"><i class="fa-solid fa-shield-halved me-2"></i>Permissions</a>
    <a class="dropdown-item text-danger" onclick="deleteSelected()"><i class="fa-solid fa-trash me-2"></i>Delete</a>`;
  const ctx = document.getElementById('ctx-menu');
  ctx.style.display = 'block';
  ctx.style.left = e.clientX + 'px';
  ctx.style.top = e.clientY + 'px';
}
document.addEventListener('click', () => { document.getElementById('ctx-menu').style.display = 'none'; });
function currentItemByPath(path) {
  const name = path.split('/').pop();
  return currentItems.find(i => i.name === name) || { name, ext: '', is_dir: false, size: 0, mtime: 0, perms: '0644' };
}
function promptAction(action) {
  const modal = new bootstrap.Modal('#promptModal');
  document.getElementById('prompt-input').value = '';
  if (action === 'mkdir') {
    document.getElementById('prompt-title').innerHTML = '<i class="fa-solid fa-folder-plus me-2"></i>New Folder';
    window._promptAction = async () => {
      const name = document.getElementById('prompt-input').value.trim();
      if (!name) return;
      await api('mkdir', { name });
      modal.hide(); load(); showToast('Folder created', 'success');
    };
  } else {
    document.getElementById('prompt-title').innerHTML = '<i class="fa-solid fa-file-plus me-2"></i>New File';
    window._promptAction = async () => {
      const name = document.getElementById('prompt-input').value.trim();
      if (!name) return;
      await api('mkfile', { name });
      modal.hide(); load(); showToast('File created', 'success');
    };
  }
  modal.show();
  setTimeout(() => document.getElementById('prompt-input').focus(), 100);
}
function promptConfirm() { window._promptAction && window._promptAction(); }
async function deleteSelected() {
  if (!selected.size) return;
  if (!confirm(`Delete ${selected.size} item(s)?`)) return;
  await api('delete', { paths: [...selected] });
  selected.clear(); load(); showToast('Deleted', 'success');
}
function openRename(path, name) {
  renameTarget = path;
  document.getElementById('rename-input').value = name;
  new bootstrap.Modal('#renameModal').show();
}
async function doRename() {
  const newName = document.getElementById('rename-input').value.trim();
  if (!newName) return;
  const dir = renameTarget.split('/').slice(0, -1).join('/');
  const newPath = (dir ? dir + '/' : '') + newName;
  await api('rename', { old: renameTarget, new: newPath });
  bootstrap.Modal.getInstance('#renameModal').hide();
  load(); showToast('Renamed', 'success');
}
function openMoveCopyModal(mode) {
  if (!selected.size) return;
  const dst = prompt('Destination (relative):', currentPath);
  if (!dst) return;
  api(mode, { paths: [...selected], dst }).then(r => {
    if (r.status === 'success') { selected.clear(); load(); showToast(mode === 'move' ? 'Moved' : 'Copied', 'success'); }
  });
}
async function zipSelected() {
  if (!selected.size) return;
  const name = prompt('ZIP name:', [...selected][0].split('/').pop() + '.zip');
  if (!name) return;
  await api('zip', { paths: [...selected], name });
  load(); showToast('ZIP created', 'success');
}
async function doUnzip(path) {
  await api('unzip', { path });
  load(); showToast('Extracted', 'success');
}
function openChmod(path, perms) { /* implement if needed */ }
async function openEditor(path, name) {
  const res = await api('read', { path });
  if (res.status !== 'success') return;
  editingPath = path;
  document.getElementById('code-editor').value = res.content;
  document.getElementById('editor-title').innerText = name || path.split('/').pop();
  new bootstrap.Modal('#editorModal').show();
}
async function saveFile() {
  const content = document.getElementById('code-editor').value;
  await api('save', { path: editingPath, content });
  bootstrap.Modal.getInstance('#editorModal').hide();
  showToast('Saved', 'success');
}
function openPreview(item, path) {
  currentPreviewPath = path;
  const ext = (item.ext || '').toLowerCase();
  const wrap = document.getElementById('preview-content');
  document.getElementById('preview-title').innerText = item.name;
  wrap.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div></div>';
  fetch(`?api=preview`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ path }) })
    .then(r => r.blob())
    .then(blob => {
      const url = URL.createObjectURL(blob);
      const images = ['jpg','jpeg','png','gif','webp','svg'];
      const video = ['mp4','webm','ogg'];
      const audio = ['mp3','wav','ogg','m4a'];
      if (images.includes(ext)) wrap.innerHTML = `<img src="${url}" class="img-fluid">`;
      else if (video.includes(ext)) wrap.innerHTML = `<video controls class="w-100"><source src="${url}"></video>`;
      else if (audio.includes(ext)) wrap.innerHTML = `<audio controls class="w-100"><source src="${url}"></audio>`;
      else wrap.innerHTML = `<pre class="p-3">${escHtml(blob.text())}</pre>`;
    });
  new bootstrap.Modal('#previewModal').show();
}
function downloadFile(path) {
  fetch(`?api=download`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ path }) })
    .then(r => r.blob())
    .then(b => { const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = path.split('/').pop(); a.click(); });
}
function openUpload() { pendingFiles = []; renderUploadList(); new bootstrap.Modal('#uploadModal').show(); }
function uploadFilesChanged(files) { pendingFiles = [...pendingFiles, ...files]; renderUploadList(); }
function renderUploadList() {
  const ul = document.getElementById('upload-list');
  ul.innerHTML = pendingFiles.map(f => `<div class="d-flex align-items-center gap-2 border p-2"><i class="fa-solid fa-file"></i> ${escHtml(f.name)} <span class="ms-auto text-muted">${fmtSize(f.size)}</span></div>`).join('');
  document.getElementById('upload-btn').disabled = pendingFiles.length === 0;
}
async function doUpload() {
  if (!pendingFiles.length) return;
  const res = await api('upload', pendingFiles, true);
  if (res.status === 'success') showToast('Uploaded', 'success');
  else showToast('Upload failed', 'error');
  pendingFiles = [];
  bootstrap.Modal.getInstance('#uploadModal').hide();
  load();
}
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('drop-overlay').style.display = 'none';
  pendingFiles = [...e.dataTransfer.files];
  if (pendingFiles.length) { renderUploadList(); new bootstrap.Modal('#uploadModal').show(); }
}
function handleUploadDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('bg-light');
  pendingFiles = [...e.dataTransfer.files];
  renderUploadList();
}
function searchFiles(q) { /* implement search */ }
function setView(v) {
  currentView = v;
  document.getElementById('vbtn-grid').classList.toggle('active', v === 'grid');
  document.getElementById('vbtn-list').classList.toggle('active', v === 'list');
  render();
}
function showToast(msg, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-bg-${type} border-0`;
  toast.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  document.getElementById('toast-container').appendChild(toast);
  new bootstrap.Toast(toast).show();
  setTimeout(() => toast.remove(), 5000);
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escJs(s) { return String(s).replace(/'/g,"\\'").replace(/\\/g,'\\\\'); }
function renderSidebar(containerId) {
  const html = `
    <div class="nav flex-column px-2 py-2">
      <a class="nav-link ${!remoteMode?'active':''}" onclick="loadLocalRoot()"><i class="fa-solid fa-house me-2"></i> Local (htdocs)</a>
      <a class="nav-link ${remoteMode?'active':''}" onclick="remoteMode ? loadRemote() : promptSFTP()"><i class="fa-solid fa-network-wired me-2"></i> ${remoteMode ? 'Remote SFTP' : 'Connect SFTP'}</a>
      <a class="nav-link" data-bs-toggle="modal" data-bs-target="#terminalModal" onclick="setTimeout(()=>document.getElementById('term-input').focus(),500)"><i class="fa-solid fa-terminal me-2"></i> Terminal</a>
      <hr>
      <a class="nav-link" onclick="load()"><i class="fa-solid fa-rotate-left me-2"></i> Refresh</a>
      <a class="nav-link" onclick="promptAction('mkdir')"><i class="fa-solid fa-folder-plus me-2"></i> New Folder</a>
      <a class="nav-link" onclick="promptAction('mkfile')"><i class="fa-solid fa-file-circle-plus me-2"></i> New File</a>
      <hr>
      <a class="nav-link ${currentView==='grid'?'active':''}" onclick="setView('grid')"><i class="fa-solid fa-grip me-2"></i> Grid View</a>
      <a class="nav-link ${currentView==='list'?'active':''}" onclick="setView('list')"><i class="fa-solid fa-list me-2"></i> List View</a>
    </div>`;
  document.getElementById(containerId).innerHTML = html;
}
function promptSFTP() { new bootstrap.Modal('#sftpModal').show(); }
async function sftpConnect() {
  const host = document.getElementById('sftp-host').value.trim();
  const port = document.getElementById('sftp-port').value;
  const user = document.getElementById('sftp-user').value.trim();
  const pass = document.getElementById('sftp-pass').value;
  if (!host || !user) return;
  const res = await fetch('?api=sftp_connect&dir=', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host, port, user, pass }) }).then(r => r.json());
  if (res.status === 'success') {
    remoteMode = true;
    remoteCwd = res.cwd || '/';
    bootstrap.Modal.getInstance('#sftpModal').hide();
    renderSidebar('sidebar-content-desktop');
    renderSidebar('sidebar-content-mobile');
    load();
  } else alert('SFTP connection failed: ' + (res.message || ''));
}
function loadLocalRoot() {
  if (remoteMode) {
    fetch('?api=sftp_disconnect&dir=').then(() => {
      remoteMode = false;
      renderSidebar('sidebar-content-desktop');
      renderSidebar('sidebar-content-mobile');
      load('');
    });
  } else load('');
}

// --- TERMINAL JAVASCRIPT LOGIC ---
document.getElementById('btn-terminal-open').addEventListener('click', function() {
    setTimeout(() => document.getElementById('term-input').focus(), 500);
});

document.getElementById('term-input').addEventListener('keypress', function(e) {
  if (e.key === 'Enter') {
    const cmd = this.value.trim();
    if (!cmd) return;
    this.value = '';
    
    const outDiv = document.getElementById('term-output');
    const promptTxt = document.getElementById('term-prompt').textContent;
    outDiv.innerHTML += `\n<span class="text-info">${promptTxt}</span> ${escHtml(cmd)}\n`;
    
    fetch('?api=terminal', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({cmd: cmd})
    })
    .then(r => r.json())
    .then(data => {
        if (data.out) outDiv.innerHTML += data.out;
        document.getElementById('term-prompt').textContent = '$ ' + data.cwd;
        const body = document.getElementById('term-body');
        body.scrollTop = body.scrollHeight; 
    })
    .catch(err => outDiv.innerHTML += '\n<span class="text-danger">Error executing command.</span>\n');
  }
});
// --------------------------------

renderSidebar('sidebar-content-desktop');
renderSidebar('sidebar-content-mobile');
load('');
</script>
<div class="dropdown-menu" id="ctx-menu" style="position:fixed; z-index:1055;"></div>
</body>
</html>
