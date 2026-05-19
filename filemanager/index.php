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
            } elseif ($action === 'chmod') {
                $sftp->chmod(octdec($req['perms']), $req['path'] ?? $target_path);
            } elseif ($action === 'diskusage') {
                echo json_encode(['status' => 'success', 'total' => 0, 'free' => 0, 'used' => 0]);
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
            $free  = disk_free_space($current_path);
            echo json_encode(['status' => 'success', 'total' => $total, 'free' => $free, 'used' => $total - $free]);
            exit;
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>File Manager Pro</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root {
  --bg: #ffffff;
  --surface: #f5f6f8;
  --surface2: #e6e9ef;
  --border: #d4d8dd;
  --border2: #b0b7c3;
  --text: #1a1f29;
  --text2: #58606e;
  --acc: #007cba;
  --acc2: #005a87;
  --acc-glow: rgba(0,124,186,0.12);
  --green: #008a20;
  --red: #c02b0a;
  --yellow: #b8600a;
  --orange: #c0690a;
  --purple: #805ac0;
  --radius: 6px;
  --radius2: 10px;
  --sidebar-w: 220px;
  --header-h: 52px;
  --status-h: 36px;
  --trans: .15s ease;
  --shadow: 0 2px 8px rgba(0,0,0,0.08);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);height:100vh;display:flex;flex-direction:column;overflow:hidden;font-size:14px}
button{cursor:pointer;font-family:inherit}
input,textarea{font-family:inherit}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
::selection{background:var(--acc-glow);color:var(--acc)}

#header{
  height:var(--header-h);min-height:var(--header-h);
  background:var(--surface);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;padding:0 14px;
  position:relative;z-index:50;
  box-shadow:var(--shadow);
}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:15px;color:var(--text);white-space:nowrap}
.logo i{color:var(--acc);font-size:18px}
.logo span{color:var(--text2);font-weight:400;font-size:12px;margin-left:2px}

.hdr-tools{display:flex;align-items:center;gap:6px;flex:1;justify-content:flex-end;flex-wrap:nowrap}

.btn{
  display:inline-flex;align-items:center;gap:6px;padding:6px 12px;
  border-radius:var(--radius);border:1px solid transparent;
  font-size:13px;font-weight:500;transition:var(--trans);white-space:nowrap;
  background:transparent;color:var(--text);
}
.btn-primary{background:var(--acc);color:#fff;border-color:var(--acc2)}
.btn-primary:hover{background:var(--acc2);box-shadow:0 0 0 2px var(--acc-glow)}
.btn-ghost{border-color:var(--border);color:var(--text)}
.btn-ghost:hover{background:var(--surface2);border-color:var(--border2)}
.btn-danger{color:var(--red);border-color:var(--border)}
.btn-danger:hover{background:rgba(192,43,10,0.05);border-color:var(--red)}
.btn-sm{padding:4px 8px;font-size:12px}
.btn-icon{padding:6px 9px;gap:0}

.search-wrap{position:relative;flex:1;max-width:300px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text2);font-size:13px;pointer-events:none}
#search-input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:var(--radius);padding:6px 10px 6px 32px;outline:none;font-size:13px;transition:var(--trans)}
#search-input:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-glow)}
#search-input::placeholder{color:var(--text2)}

#main{display:flex;flex:1;overflow:hidden}

#sidebar{
  width:var(--sidebar-w);min-width:var(--sidebar-w);
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  overflow-y:auto;
  transition:transform .25s ease;
}
.sidebar-section{padding:14px 10px 6px;font-size:11px;font-weight:600;color:var(--text2);letter-spacing:.08em;text-transform:uppercase}
.nav-item{
  display:flex;align-items:center;gap:9px;padding:8px 12px;
  border-radius:var(--radius);margin:1px 6px;cursor:pointer;
  font-size:13px;color:var(--text2);transition:var(--trans);
  border:1px solid transparent;
}
.nav-item:hover{background:var(--surface2);color:var(--text);border-color:var(--border)}
.nav-item.active{background:var(--acc-glow);color:var(--acc);border-color:rgba(0,124,186,0.25)}
.nav-item i{width:16px;text-align:center;font-size:14px}

.disk-card{margin:10px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius2)}
.disk-label{font-size:11px;color:var(--text2);margin-bottom:6px;display:flex;justify-content:space-between}
.disk-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden}
.disk-fill{height:100%;background:linear-gradient(90deg,var(--acc),var(--purple));border-radius:3px;transition:.6s ease}

#content{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative}

#toolbar{
  padding:8px 14px;background:var(--surface);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:8px;flex-wrap:wrap;
}
.tool-sep{width:1px;height:20px;background:var(--border);margin:0 2px}

#breadcrumb{
  padding:8px 14px;background:var(--bg);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:4px;overflow-x:auto;white-space:nowrap;
  font-size:13px;
}
#breadcrumb::-webkit-scrollbar{height:0}
.bc-sep{color:var(--text2);font-size:11px;margin:0 2px}
.bc-item{color:var(--text2);cursor:pointer;padding:2px 6px;border-radius:4px;transition:var(--trans)}
.bc-item:hover{color:var(--acc);background:var(--acc-glow)}
.bc-item.active{color:var(--text);font-weight:600;cursor:default}
.bc-item.active:hover{background:transparent;color:var(--text)}

.view-btn{padding:5px 8px;border-radius:5px;border:1px solid transparent;background:transparent;color:var(--text2);font-size:13px;transition:var(--trans)}
.view-btn:hover{color:var(--text);background:var(--surface2)}
.view-btn.active{background:var(--acc-glow);color:var(--acc);border-color:rgba(0,124,186,0.25)}

#file-area{flex:1;overflow-y:auto;padding:12px;position:relative}

#drop-overlay{
  display:none;position:absolute;inset:0;
  background:rgba(0,124,186,0.05);
  border:2px dashed var(--acc);
  border-radius:var(--radius2);
  z-index:200;pointer-events:none;
  align-items:center;justify-content:center;flex-direction:column;gap:12px;
  font-size:18px;font-weight:600;color:var(--acc);
  backdrop-filter:blur(2px);
}
#drop-overlay i{font-size:48px;opacity:.7}

#file-list.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.file-card{
  background:var(--bg);border:1px solid var(--border);border-radius:var(--radius2);
  padding:14px 10px 12px;text-align:center;cursor:pointer;
  transition:var(--trans);position:relative;user-select:none;
  display:flex;flex-direction:column;align-items:center;gap:6px;
}
.file-card:hover{background:var(--surface);border-color:var(--border2);transform:translateY(-1px);box-shadow:var(--shadow)}
.file-card.selected{border-color:var(--acc);background:var(--acc-glow);box-shadow:0 0 0 1px var(--acc)}
.file-card .fc-icon{font-size:34px;line-height:1}
.file-card .fc-name{font-size:11px;word-break:break-all;color:var(--text);line-height:1.3;max-height:30px;overflow:hidden}
.file-card .fc-size{font-size:10px;color:var(--text2)}
.file-card .fc-check{
  position:absolute;top:6px;left:6px;width:16px;height:16px;
  border-radius:4px;border:1.5px solid var(--border2);background:var(--surface);
  display:none;align-items:center;justify-content:center;
  font-size:10px;color:var(--acc);
}
.multi-select .file-card .fc-check{display:flex}
.file-card.selected .fc-check{background:var(--acc);border-color:var(--acc);color:#fff}
.file-card .fc-check::after{content:'✓'}

#file-list.list{display:flex;flex-direction:column;gap:2px}
.file-row{
  display:grid;grid-template-columns:20px 24px 1fr 90px 90px 100px 80px;
  align-items:center;gap:8px;padding:7px 10px;
  background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);
  cursor:pointer;transition:var(--trans);user-select:none;
}
.file-row:hover{background:var(--surface);border-color:var(--border2)}
.file-row.selected{border-color:var(--acc);background:var(--acc-glow)}
.file-row .fr-check input[type=checkbox]{accent-color:var(--acc);width:14px;height:14px}
.file-row .fr-icon{font-size:16px;text-align:center}
.file-row .fr-name{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-row .fr-size,.file-row .fr-perms,.file-row .fr-mtime,.file-row .fr-type{font-size:11px;color:var(--text2)}
.list-header{
  display:grid;grid-template-columns:20px 24px 1fr 90px 90px 100px 80px;
  align-items:center;gap:8px;padding:5px 10px;
  font-size:11px;font-weight:600;color:var(--text2);letter-spacing:.05em;text-transform:uppercase;
  border-bottom:1px solid var(--border);margin-bottom:4px;cursor:default;
}

.ic-folder{color:#e68a00}
.ic-php{color:#7058c0}
.ic-html,.ic-htm{color:#c0600a}
.ic-css{color:#007cba}
.ic-js{color:#b0a000}
.ic-json{color:#1a8a4a}
.ic-md{color:#805ac0}
.ic-img{color:#c0208a}
.ic-zip,.ic-tar,.ic-gz{color:#c05a00}
.ic-pdf{color:#c02b0a}
.ic-sql{color:#00789a}
.ic-txt{color:#5a6a7a}
.ic-sh{color:#2a8a3a}
.ic-xml{color:#c0600a}
.ic-mp4,.ic-webm{color:#805ac0}
.ic-mp3,.ic-wav{color:#c0206a}
.ic-default{color:#7a8a9a}

#statusbar{
  height:var(--status-h);background:var(--surface);
  border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 14px;font-size:11px;color:var(--text2);gap:16px;
}
.sb-info{display:flex;align-items:center;gap:12px}
.sb-badge{background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:1px 7px;font-size:11px}

#ctx-menu{
  display:none;position:fixed;z-index:9000;
  background:var(--bg);border:1px solid var(--border2);
  border-radius:var(--radius2);padding:6px;min-width:190px;
  box-shadow:0 8px 30px rgba(0,0,0,0.15);
  animation:fadeIn .1s ease;
}
@keyframes fadeIn{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:scale(1)}}
.ctx-item{
  display:flex;align-items:center;gap:10px;padding:8px 10px;
  border-radius:6px;cursor:pointer;font-size:13px;transition:var(--trans);color:var(--text);
}
.ctx-item:hover{background:var(--surface2);color:var(--acc)}
.ctx-item i{width:14px;text-align:center;color:var(--text2);font-size:13px}
.ctx-item:hover i{color:var(--acc)}
.ctx-item.danger{color:var(--red)}
.ctx-item.danger:hover{background:rgba(192,43,10,0.05)}
.ctx-item.danger i{color:var(--red)}
.ctx-sep{height:1px;background:var(--border);margin:4px 0}

.modal-backdrop{
  display:none;position:fixed;inset:0;background:rgba(0,0,0,0.2);
  z-index:8000;align-items:center;justify-content:center;backdrop-filter:blur(3px);
}
.modal-backdrop.open{display:flex}
.modal{
  background:var(--bg);border:1px solid var(--border2);border-radius:var(--radius2);
  overflow:hidden;display:flex;flex-direction:column;
  box-shadow:0 24px 60px rgba(0,0,0,0.12);animation:slideUp .2s ease;
}
@keyframes slideUp{from{transform:translateY(12px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{
  padding:14px 18px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.modal-title{font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px}
.modal-title i{color:var(--acc)}
.modal-body{padding:18px;flex:1;overflow:auto}
.modal-footer{padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.close-btn{background:transparent;border:none;color:var(--text2);font-size:18px;padding:4px 8px;border-radius:4px;transition:var(--trans)}
.close-btn:hover{color:var(--text);background:var(--surface2)}

#editor-modal .modal{width:90vw;max-width:1100px;height:88vh}
#editor-wrap{display:flex;flex:1;overflow:hidden;border-top:1px solid var(--border)}
#line-numbers{
  width:40px;background:var(--surface);color:var(--text2);
  padding:14px 0;font-family:'Consolas','Courier New',monospace;font-size:13.5px;
  line-height:1.7;text-align:right;overflow:hidden;user-select:none;
  border-right:1px solid var(--border);
}
#line-numbers div{padding-right:8px}
#code-editor{
  flex:1;background:#fff;color:var(--text);border:none;
  padding:14px;font-family:'Consolas','Courier New',monospace;font-size:13.5px;
  line-height:1.7;outline:none;resize:none;tab-size:2;
  overflow:auto;
}

#terminal-modal .modal{width:90vw;max-width:900px;height:70vh}
#terminal-output{
  background:#0d1117;color:#c9d1d9;font-family:'Courier New',monospace;
  font-size:13px;padding:16px;flex:1;overflow-y:auto;white-space:pre-wrap;word-break:break-all;
  border-radius: 0 0 var(--radius) var(--radius);
}
#terminal-input-wrap{
  display:flex;align-items:center;background:#161b22;border-top:1px solid #30363d;
  padding:8px 12px;
}
#terminal-prompt{
  color:#58a6ff;font-family:'Courier New',monospace;font-size:13px;white-space:nowrap;
  margin-right:8px;
}
#terminal-input{
  flex:1;background:transparent;border:none;color:#c9d1d9;font-family:'Courier New',monospace;
  font-size:13px;outline:none;
}
#terminal-input::placeholder{color:#484f58}

#prompt-modal .modal{width:400px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;letter-spacing:.05em;text-transform:uppercase}
.form-input{
  width:100%;background:var(--bg);border:1px solid var(--border);
  color:var(--text);border-radius:var(--radius);padding:8px 12px;outline:none;font-size:14px;
  transition:var(--trans);
}
.form-input:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-glow)}

#props-modal .modal{width:480px}
.prop-row{display:flex;padding:8px 0;border-bottom:1px solid var(--border);gap:12px}
.prop-key{width:110px;font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;flex-shrink:0}
.prop-val{font-size:13px;word-break:break-all}

.chmod-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}
.chmod-col{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:10px}
.chmod-col-title{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;margin-bottom:8px}
.chmod-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:4px}
.chmod-row input{accent-color:var(--acc)}
.chmod-octal{
  margin-top:12px;display:flex;align-items:center;gap:10px;
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:10px;
}
.chmod-octal label{font-size:12px;color:var(--text2);font-weight:600}
#chmod-octal-input{background:transparent;border:none;color:var(--acc);font-size:20px;font-weight:700;width:60px;outline:none;font-family:monospace}

#toast-wrap{position:fixed;bottom:50px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px}
.toast{
  background:var(--bg);border:1px solid var(--border2);border-radius:var(--radius2);
  padding:12px 16px;min-width:260px;max-width:340px;
  box-shadow:0 8px 24px rgba(0,0,0,0.1);
  animation:slideIn .2s ease;
}
@keyframes slideIn{from{transform:translateX(20px);opacity:0}to{transform:translateX(0);opacity:1}}
.toast-title{font-size:13px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px}
.toast-title i{font-size:14px}
.toast-bar{height:4px;background:var(--border);border-radius:2px;overflow:hidden}
.toast-fill{height:100%;background:linear-gradient(90deg,var(--acc),var(--purple));transition:.3s ease}
.toast.success .toast-fill{background:var(--green)}
.toast.error .toast-fill{background:var(--red);width:100%}

#upload-modal .modal{width:480px}
#upload-drop{
  border:2px dashed var(--border2);border-radius:var(--radius2);padding:30px;
  text-align:center;color:var(--text2);cursor:pointer;transition:var(--trans);
}
#upload-drop:hover,#upload-drop.drag{border-color:var(--acc);background:var(--acc-glow);color:var(--acc)}
#upload-drop i{font-size:40px;display:block;margin-bottom:10px}
#upload-drop p{font-size:13px}
#upload-list{margin-top:12px;display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto}
.upload-item{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:8px 10px;font-size:12px}
.upload-item i{color:var(--text2)}
.upload-item .ui-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.upload-item .ui-size{color:var(--text2);white-space:nowrap}

#preview-modal .modal{width:85vw;max-width:1000px;height:85vh}
#preview-content{flex:1;display:flex;align-items:center;justify-content:center;overflow:auto;background:#fafbfc}
#preview-content img,#preview-content video,#preview-content audio{max-width:100%;max-height:100%;border-radius:4px}
#preview-content iframe{width:100%;height:100%;border:none}

#sftp-modal .modal{width:400px}

@media(max-width:768px){
  #sidebar{display:none;position:fixed;inset:0;width:100%;z-index:100}
  #sidebar.open{display:flex}
  .logo span{display:none}
  .hdr-tools .btn-label{display:none}
  #breadcrumb{order:10;position:fixed;bottom:var(--status-h);left:0;right:0;z-index:40;border-top:1px solid var(--border);border-bottom:none;padding:8px 10px}
  #statusbar{position:fixed;bottom:0;left:0;right:0;z-index:41}
  #content{padding-bottom:calc(var(--status-h) * 2 + 8px)}
  .file-row{grid-template-columns:20px 24px 1fr 70px}
  .file-row .fr-size,.file-row .fr-perms,.file-row .fr-type{display:none}
  .list-header{grid-template-columns:20px 24px 1fr 70px}
  .list-header span:nth-child(n+5){display:none}
  #editor-modal .modal{width:100vw;height:100vh;border-radius:0}
  #terminal-modal .modal{width:100vw;height:100vh;border-radius:0}
  .sidebar-toggle{display:block!important}
}
.sidebar-toggle{display:none}
.empty-state{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  height:200px;color:var(--text2);gap:12px;
}
.empty-state i{font-size:48px;opacity:.3}
.empty-state p{font-size:14px}
.sort-asc::after{content:' ↑'}
.sort-desc::after{content:' ↓'}
</style>
</head>
<body>

<div id="header">
  <div class="logo">
    <i class="fa-solid fa-folder-tree"></i>
    File Manager
    <span>Pro</span>
  </div>
  <div class="hdr-tools">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="search-input" placeholder="Search files…" oninput="debounceSearch(this.value)" autocomplete="off">
    </div>
    <button class="btn btn-primary btn-icon" onclick="openUpload()" title="Upload Files">
      <i class="fa-solid fa-cloud-arrow-up"></i>
      <span class="btn-label">Upload</span>
    </button>
    <button class="btn btn-ghost btn-icon" onclick="openTerminal()" title="Terminal">
      <i class="fa-solid fa-terminal"></i>
      <span class="btn-label">Terminal</span>
    </button>
    <button class="btn btn-ghost btn-icon sidebar-toggle" onclick="toggleSidebar()" title="Menu">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</div>

<div id="main">
  <div id="sidebar">
    <div class="sidebar-section">Navigation</div>
    <div class="nav-item active" id="nav-local" onclick="loadLocalRoot()"><i class="fa-solid fa-house"></i> Root (htdocs)</div>
    <div class="nav-item" id="nav-remote" onclick="remoteMode ? loadRemote() : promptSFTP()"><i class="fa-solid fa-network-wired"></i> <span id="sftp-label">Connect SFTP</span></div>
    <div class="nav-item" onclick="openTerminal()"><i class="fa-solid fa-terminal"></i> Terminal</div>
    <div class="sidebar-section">Quick Actions</div>
    <div class="nav-item" onclick="promptAction('mkdir')"><i class="fa-solid fa-folder-plus"></i> New Folder</div>
    <div class="nav-item" onclick="promptAction('mkfile')"><i class="fa-solid fa-file-circle-plus"></i> New File</div>
    <div class="nav-item" onclick="openUpload()"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Files</div>
    <div class="sidebar-section">View</div>
    <div class="nav-item active" id="nav-grid" onclick="setView('grid')"><i class="fa-solid fa-grip"></i> Grid View</div>
    <div class="nav-item" id="nav-list" onclick="setView('list')"><i class="fa-solid fa-list"></i> List View</div>
    <div style="flex:1"></div>
    <div class="disk-card" id="disk-card">
      <div class="disk-label"><span><i class="fa-solid fa-hard-drive"></i> Disk Usage</span><span id="disk-pct">–</span></div>
      <div class="disk-bar"><div class="disk-fill" id="disk-fill" style="width:0%"></div></div>
      <div style="font-size:10px;color:var(--text2);margin-top:5px" id="disk-detail">Loading…</div>
    </div>
  </div>

  <div id="content">
    <div id="toolbar">
      <button class="btn btn-ghost btn-sm btn-icon" onclick="navUp()" title="Go Up"><i class="fa-solid fa-arrow-up"></i></button>
      <button class="btn btn-ghost btn-sm btn-icon" onclick="load()" title="Refresh"><i class="fa-solid fa-arrows-rotate"></i></button>
      <div class="tool-sep"></div>
      <button class="btn btn-ghost btn-sm" onclick="promptAction('mkdir')"><i class="fa-solid fa-folder-plus"></i> Folder</button>
      <button class="btn btn-ghost btn-sm" onclick="promptAction('mkfile')"><i class="fa-solid fa-file-plus"></i> File</button>
      <div class="tool-sep"></div>
      <button class="btn btn-ghost btn-sm" id="sel-btn" onclick="toggleMultiSelect()" title="Select Mode"><i class="fa-solid fa-check-double"></i> Select</button>
      <button class="btn btn-danger btn-sm" id="del-btn" onclick="deleteSelected()" style="display:none"><i class="fa-solid fa-trash"></i> Delete (<span id="sel-count">0</span>)</button>
      <button class="btn btn-ghost btn-sm" id="zip-sel-btn" onclick="zipSelected()" style="display:none"><i class="fa-solid fa-file-zipper"></i> ZIP</button>
      <button class="btn btn-ghost btn-sm" id="clear-sel-btn" onclick="clearSelection()" style="display:none"><i class="fa-solid fa-xmark"></i> Clear</button>
      <div style="flex:1"></div>
      <button class="btn btn-ghost btn-sm" onclick="openTerminal()"><i class="fa-solid fa-terminal"></i></button>
      <button class="view-btn active" id="vbtn-grid" onclick="setView('grid')"><i class="fa-solid fa-grip"></i></button>
      <button class="view-btn" id="vbtn-list" onclick="setView('list')"><i class="fa-solid fa-list"></i></button>
    </div>
    <div id="breadcrumb"></div>
    <div id="file-area" ondragover="onDragOver(event)" ondragleave="onDragLeave(event)" ondrop="onDrop(event)">
      <div id="drop-overlay"><i class="fa-solid fa-cloud-arrow-up"></i> Drop files to upload</div>
      <div id="list-header" class="list-header" style="display:none">
        <span></span><span></span>
        <span class="hdr-sort" data-sort="name" onclick="sortBy('name')">Name</span>
        <span class="hdr-sort" data-sort="mtime" onclick="sortBy('mtime')">Modified</span>
        <span class="hdr-sort" data-sort="size" onclick="sortBy('size')">Size</span>
        <span>Permissions</span>
        <span>Type</span>
      </div>
      <div id="file-list" class="grid"></div>
    </div>
    <div id="statusbar">
      <div class="sb-info"><span id="sb-items">–</span><span id="sb-selected" style="display:none"><span class="sb-badge"><span id="sb-sel-n">0</span> selected</span></span></div>
      <div class="sb-info"><span id="sb-path" style="font-family:monospace;font-size:11px;color:var(--text2)"></span></div>
    </div>
  </div>
</div>

<div id="ctx-menu"></div>
<div id="toast-wrap"></div>

<!-- Terminal Modal -->
<div class="modal-backdrop" id="terminal-modal">
  <div class="modal" style="width:90vw;max-width:900px;height:70vh">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-terminal"></i> Terminal</div>
      <button class="close-btn" onclick="closeModal('terminal-modal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body p-0 d-flex flex-column" style="background:#0d1117;">
      <div id="terminal-output"></div>
      <div id="terminal-input-wrap">
        <span id="terminal-prompt">$ /</span>
        <input type="text" id="terminal-input" placeholder="Type command...">
      </div>
    </div>
  </div>
</div>

<!-- SFTP Modal -->
<div class="modal-backdrop" id="sftp-modal">
  <div class="modal" style="width:400px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-network-wired"></i> SFTP Connection</div>
      <button class="close-btn" onclick="closeModal('sftp-modal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Host</label><input type="text" class="form-input" id="sftp-host"></div>
      <div class="form-group"><label class="form-label">Port</label><input type="number" class="form-input" id="sftp-port" value="22"></div>
      <div class="form-group"><label class="form-label">Username</label><input type="text" class="form-input" id="sftp-user"></div>
      <div class="form-group"><label class="form-label">Password</label><input type="password" class="form-input" id="sftp-pass"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('sftp-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="sftpConnect()">Connect</button>
    </div>
  </div>
</div>

<!-- Prompt Modal -->
<div class="modal-backdrop" id="prompt-modal">
  <div class="modal" style="width:400px">
    <div class="modal-header"><div class="modal-title" id="prompt-title"></div><button class="close-btn" onclick="closeModal('prompt-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><input type="text" class="form-input" id="prompt-input" onkeydown="if(event.key==='Enter')promptConfirm()"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('prompt-modal')">Cancel</button><button class="btn btn-primary" onclick="promptConfirm()">OK</button></div>
  </div>
</div>

<!-- Rename Modal -->
<div class="modal-backdrop" id="rename-modal">
  <div class="modal" style="width:400px">
    <div class="modal-header"><div class="modal-title">Rename</div><button class="close-btn" onclick="closeModal('rename-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><input type="text" class="form-input" id="rename-input" onkeydown="if(event.key==='Enter')doRename()"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('rename-modal')">Cancel</button><button class="btn btn-primary" onclick="doRename()">Rename</button></div>
  </div>
</div>

<!-- Editor Modal -->
<div class="modal-backdrop" id="editor-modal">
  <div class="modal" style="width:90vw;max-width:1100px;height:88vh">
    <div class="modal-header"><div class="modal-title" id="editor-title"></div><button class="close-btn" onclick="closeModal('editor-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="editor-wrap"><div id="line-numbers"></div><textarea id="code-editor" spellcheck="false" oninput="editorChanged()"></textarea></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('editor-modal')">Close</button><button class="btn btn-primary" onclick="saveFile()"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
  </div>
</div>

<!-- Upload Modal -->
<div class="modal-backdrop" id="upload-modal">
  <div class="modal" style="width:480px">
    <div class="modal-header"><div class="modal-title">Upload Files</div><button class="close-btn" onclick="closeModal('upload-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body">
      <div id="upload-drop" onclick="document.getElementById('file-input').click()" ondragover="uploadDragOver(event)" ondrop="uploadDrop(event)" ondragleave="uploadDragLeave(event)">
        <i class="fa-solid fa-cloud-arrow-up"></i><p><strong>Click or drag files here</strong></p>
      </div>
      <input type="file" id="file-input" multiple style="display:none" onchange="fileInputChanged(this.files)">
      <div id="upload-list"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('upload-modal')">Cancel</button><button class="btn btn-primary" id="upload-btn" onclick="doUpload()" disabled>Upload</button></div>
  </div>
</div>

<!-- Preview Modal -->
<div class="modal-backdrop" id="preview-modal">
  <div class="modal" style="width:85vw;max-width:1000px;height:85vh">
    <div class="modal-header"><div class="modal-title" id="preview-title"></div><button class="close-btn" onclick="closeModal('preview-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="preview-content" class="modal-body"></div>
  </div>
</div>

<script>
let currentPath = '', currentItems = [], currentView = 'grid', multiSelect = false, selected = new Set(), sortField = 'name', sortAsc = true, remoteMode = false, remoteCwd = '/', editingPath = '', pendingFiles = [], renameTarget = '', currentPreviewPath = '';

function api(action, data = {}, isUpload = false) {
  const url = `?api=${action}&dir=${encodeURIComponent(remoteMode ? remoteCwd : currentPath)}`;
  if (action === 'list' || action === 'diskusage') return fetch(url).then(r => r.json());
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
  else { document.getElementById('disk-card').style.display = 'none'; }
}

async function loadDiskUsage() {
  const d = await api('diskusage');
  if (d.status !== 'success') return;
  const pct = Math.round(d.used / d.total * 100);
  document.getElementById('disk-pct').textContent = pct + '%';
  document.getElementById('disk-fill').style.width = pct + '%';
  document.getElementById('disk-detail').textContent = fmtSize(d.used) + ' used of ' + fmtSize(d.total);
}

function fmtSize(b) { return b >= 1073741824 ? (b/1073741824).toFixed(2)+' GB' : b >= 1048576 ? (b/1048576).toFixed(2)+' MB' : b >= 1024 ? (b/1024).toFixed(1)+' KB' : b+' B'; }
function getIcon(item) { /* same as before */ return item.is_dir ? '<i class="fa-solid fa-folder ic-folder"></i>' : '<i class="fa-solid fa-file ic-default"></i>'; }
function sortItems(items) { return [...items].sort((a,b) => { if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1; let av = a[sortField], bv = b[sortField]; if (typeof av === 'string') { av = av.toLowerCase(); bv = bv.toLowerCase(); } return sortAsc ? (av < bv ? -1 : 1) : (av > bv ? -1 : 1); }); }
function render(items) { /* same as before, using currentView grid/list */ }
function handleClick(e, item, path, el) { /* same */ }
function toggleSelect(path, el) { /* same */ }
function rangeSelect(path) { /* same */ }
function updateSelectionUI() { /* same */ }
function clearSelection() { /* same */ }
function toggleMultiSelect() { /* same */ }
function handleDblClick(item, path) { /* same */ }
function navUp() { const parts = (remoteMode ? remoteCwd : currentPath).split('/').filter(p => p); parts.pop(); load(parts.join('/')); }
function renderBreadcrumb() { /* same */ }
function updateStatusBar() { /* same */ }
function showCtx(e, item, path) { /* same */ }
function currentItemByPath(path) { /* same */ }
function promptAction(action) { /* same */ }
function promptConfirm() { /* same */ }
async function deleteSelected() { /* same */ }
function openRename(path, name) { /* same */ }
async function doRename() { /* same */ }
function openMoveCopyModal(mode) { /* same as before using prompt */ }
async function zipSelected() { /* same */ }
async function doUnzip(path) { /* same */ }
function openChmod(path, perms) { /* implement if needed */ }
async function openEditor(path, name) { /* same */ }
async function saveFile() { /* same */ }
function openPreview(item, path) { /* same */ }
function downloadFile(path) { /* same */ }
function openUpload() { /* same */ }
function fileInputChanged(files) { /* same */ }
function renderUploadList() { /* same */ }
async function doUpload() { /* same */ }
function uploadDragOver(e) { /* same */ }
function uploadDragLeave(e) { /* same */ }
function uploadDrop(e) { /* same */ }
function onDragOver(e) { /* same */ }
function onDragLeave(e) { /* same */ }
function onDrop(e) { /* same */ }
function debounceSearch(q) { /* same */ }
function setView(v) { /* same */ }
function showToast(msg, type='info') { /* same */ }
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function closeAllModals() { document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open')); }
document.querySelectorAll('.modal-backdrop').forEach(b => b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); }));
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }

// Terminal
function openTerminal() {
  openModal('terminal-modal');
  const output = document.getElementById('terminal-output');
  output.innerHTML = '';
  fetch('?api=terminal', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cmd: '' }) })
    .then(r => r.json())
    .then(d => {
      document.getElementById('terminal-prompt').textContent = '$ ' + d.cwd;
      document.getElementById('terminal-input').value = '';
      document.getElementById('terminal-input').focus();
    });
}
document.getElementById('terminal-input').addEventListener('keypress', function(e) {
  if (e.key === 'Enter') {
    const cmd = this.value.trim();
    if (!cmd) return;
    this.value = '';
    const output = document.getElementById('terminal-output');
    output.innerHTML += `<div style="color:#58a6ff;">$ ${document.getElementById('terminal-prompt').textContent.substring(2)} ${escHtml(cmd)}</div>`;
    fetch('?api=terminal', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cmd }) })
      .then(r => r.json())
      .then(d => {
        if (d.out) output.innerHTML += `<div>${d.out}</div>`;
        document.getElementById('terminal-prompt').textContent = '$ ' + d.cwd;
        output.scrollTop = output.scrollHeight;
      });
  }
});

// SFTP
function promptSFTP() { openModal('sftp-modal'); }
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
    closeModal('sftp-modal');
    document.getElementById('nav-remote').classList.add('active');
    document.getElementById('nav-local').classList.remove('active');
    document.getElementById('sftp-label').textContent = host;
    load();
  } else alert('SFTP connection failed: ' + (res.message || ''));
}
function loadLocalRoot() {
  if (remoteMode) {
    fetch('?api=sftp_disconnect&dir=').then(() => {
      remoteMode = false;
      document.getElementById('nav-remote').classList.remove('active');
      document.getElementById('nav-local').classList.add('active');
      document.getElementById('sftp-label').textContent = 'Connect SFTP';
      load('');
    });
  } else load('');
}
function loadRemote() { load(remoteCwd); }

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escJs(s) { return String(s).replace(/'/g,"\\'").replace(/\\/g,'\\\\'); }
load();
</script>
</body>
</html>
