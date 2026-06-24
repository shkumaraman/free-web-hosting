<?php
session_start();
$base_dir = realpath('/var/www/localhost/htdocs');
require_once __DIR__ . '/vendor/autoload.php';

function get_absolute_path($path) {
    global $base_dir;
    $realBase = realpath($base_dir);
    $path = str_replace('\\', '/', $path);
    $parts = array_filter(explode('/', $path), 'strlen');
    $safe = [];
    foreach ($parts as $p) {
        if ($p === '..') {
            array_pop($safe);
        } elseif ($p !== '.') {
            $safe[] = $p;
        }
    }
    $finalPath = $realBase . '/' . implode('/', $safe);
    $realFinalPath = realpath($finalPath);
    
    if ($realFinalPath === false) {
        $parentDir = realpath(dirname($finalPath));
        if ($parentDir === false || strpos($parentDir, $realBase) !== 0) {
            return $realBase;
        }
        return rtrim($finalPath, '/');
    }
    
    if (strpos($realFinalPath, $realBase) !== 0) {
        return $realBase;
    }
    return rtrim($realFinalPath, '/');
}

function get_relative_path($path) {
    global $base_dir;
    return ltrim(substr($path, strlen(realpath($base_dir))), '/');
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
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml',
        'pdf'=>'application/pdf','txt'=>'text/plain','html'=>'text/html','htm'=>'text/html',
        'css'=>'text/css','js'=>'application/javascript','json'=>'application/json',
        'php'=>'text/plain','xml'=>'text/xml','md'=>'text/plain','sh'=>'text/plain',
        'zip'=>'application/zip','tar'=>'application/x-tar','gz'=>'application/gzip',
        'mp4'=>'video/mp4','mp3'=>'audio/mpeg','wav'=>'audio/wav',
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

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object)) {
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        rmdir($dir);
    } else {
        if(file_exists($dir)) unlink($dir);
    }
}

function rcopy($src, $dst) {
    if (is_dir($src)) {
        @mkdir($dst);
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    rcopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    } else {
        copy($src, $dst);
    }
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $dir = isset($_GET['dir']) ? $_GET['dir'] : '';
    $is_sftp = !empty($_SESSION['sftp_active']);
    
    $req = json_decode(file_get_contents('php://input'), true) ?: [];
    $current_path = get_absolute_path($dir);

    if ($action === 'terminal') {
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

    if ($action === 'upload_chunk') {
        $fileName = basename($_POST['fileName'] ?? '');
        $chunkIndex = intval($_POST['chunkIndex'] ?? 0);
        $totalChunks = intval($_POST['totalChunks'] ?? 1);
        
        if (!$fileName || !isset($_FILES['file'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid chunk metadata']);
            exit;
        }
        
        $tempUploadDir = sys_get_temp_dir() . '/fm_chunks';
        if (!is_dir($tempUploadDir)) mkdir($tempUploadDir, 0755, true);
        
        $tempFile = $tempUploadDir . '/' . md5($fileName . session_id()) . '.part';
        $out = fopen($tempFile, $chunkIndex === 0 ? 'wb' : 'ab');
        $in = fopen($_FILES['file']['tmp_name'], 'rb');
        
        if ($out && $in) {
            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
            fclose($in); fclose($out);
        }
        
        if ($chunkIndex === $totalChunks - 1) {
            if ($is_sftp) {
                $sftp = sftp_connect();
                $remote_cwd = $_SESSION['sftp_cwd'] ?? '/';
                $dst = $remote_cwd . '/' . ltrim($dir, '/') . '/' . $fileName;
                $sftp->put($dst, $tempFile, phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
                unlink($tempFile);
            } else {
                $targetFile = $current_path . '/' . $fileName;
                rename($tempFile, $targetFile);
            }
            echo json_encode(['status' => 'success', 'completed' => true]);
        } else {
            echo json_encode(['status' => 'success', 'completed' => false]);
        }
        exit;
    }

    if ($action === 'transfer_to_local') {
        $sftp = sftp_connect();
        $localDir = get_absolute_path($req['localDir'] ?? '');
        foreach ((array)($req['paths'] ?? []) as $p) {
            $dst = $localDir . '/' . basename($p);
            $sftp->get($p, $dst);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'transfer_to_sftp') {
        $sftp = sftp_connect();
        $remoteDir = $_SESSION['sftp_cwd'] . '/' . ltrim($req['remoteDir'] ?? '', '/');
        foreach ((array)($req['paths'] ?? []) as $p) {
            $src = get_absolute_path($p);
            $dst = rtrim($remoteDir, '/') . '/' . basename($src);
            if (is_file($src)) {
                $sftp->put($dst, $src, phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
            }
        }
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
            if ($action === 'download' || $action === 'preview') {
                $file = $req['path'] ?? $target_path;
                if ($sftp->is_file($file)) {
                    $mime = $action === 'preview' ? get_mime($file) : 'application/octet-stream';
                    header('Content-Type: ' . $mime);
                    if ($action === 'download') header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                    header('Content-Length: ' . $sftp->size($file));
                    
                    $tmpFile = tempnam(sys_get_temp_dir(), 'sftp_stream');
                    if ($sftp->get($file, $tmpFile)) {
                        readfile($tmpFile);
                        unlink($tmpFile);
                    }
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
                        $tmpCp = tempnam(sys_get_temp_dir(), 'sftp_cp');
                        if ($sftp->get($src, $tmpCp)) {
                            $sftp->put($dst, $tmpCp, phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
                            unlink($tmpCp);
                        }
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

        if ($action === 'mkdir') {
            $path = $current_path . '/' . basename($req['name']);
            mkdir($path, 0755, true);
        } elseif ($action === 'mkfile') {
            file_put_contents($current_path . '/' . basename($req['name']), '');
        } elseif ($action === 'delete') {
            foreach ((array)$req['paths'] as $p) {
                rrmdir(get_absolute_path($p));
            }
        } elseif ($action === 'rename') {
            rename(get_absolute_path($req['old']), get_absolute_path($req['new']));
        } elseif ($action === 'move' || $action === 'copy') {
            foreach ((array)$req['paths'] as $p) {
                $src = get_absolute_path($p);
                $dst = get_absolute_path($req['dst']) . '/' . basename($src);
                if ($action === 'move') rename($src, $dst);
                else rcopy($src, $dst);
            }
        } elseif ($action === 'read') {
            $target = get_absolute_path($req['path']);
            echo json_encode(['status' => 'success', 'content' => file_get_contents($target), 'mime' => get_mime($target)]);
            exit;
        } elseif ($action === 'download' || $action === 'preview') {
            $target = get_absolute_path($req['path'] ?? '');
            if (is_file($target)) {
                $mime = $action === 'preview' ? get_mime($target) : 'application/octet-stream';
                header('Content-Type: ' . $mime);
                if ($action === 'download') header('Content-Disposition: attachment; filename="' . basename($target) . '"');
                header('Content-Length: ' . filesize($target));
                readfile($target);
            }
            exit;
        } elseif ($action === 'save') {
            $target = get_absolute_path($req['path']);
            file_put_contents($target, $req['content']);
        } elseif ($action === 'zip') {
            $paths = (array)$req['paths'];
            $zipName = $req['name'] ?? (basename($paths[0]) . '.zip');
            $zipPath = $current_path . '/' . $zipName;
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                foreach ($paths as $p) {
                    $target = get_absolute_path($p);
                    if (is_dir($target)) {
                        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target), RecursiveIteratorIterator::LEAVES_ONLY);
                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = substr($filePath, strlen($target) + 1);
                                $zip->addFile($filePath, basename($target) . '/' . $relativePath);
                            }
                        }
                    } else {
                        $zip->addFile($target, basename($target));
                    }
                }
                $zip->close();
            }
        } elseif ($action === 'unzip') {
            $target = get_absolute_path($req['path']);
            $dest = dirname($target);
            $zip = new ZipArchive();
            if ($zip->open($target) === TRUE) {
                $zip->extractTo($dest);
                $zip->close();
            }
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@latest/css/all.min.css">
<style>
:root {
  --bg: #ffffff; --surface: #f5f6f8; --surface2: #e6e9ef;
  --border: #d4d8dd; --border2: #b0b7c3; --text: #1a1f29;
  --text2: #58606e; --acc: #007cba; --acc2: #005a87;
  --acc-glow: rgba(0,124,186,0.12); --green: #008a20;
  --red: #c02b0a; --yellow: #b8600a; --orange: #c0690a;
  --purple: #805ac0; --radius: 6px; --radius2: 10px;
  --sidebar-w: 220px; --header-h: 52px; --status-h: 36px;
  --trans: .15s ease; --shadow: 0 2px 8px rgba(0,0,0,0.08);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);height:100vh;display:flex;flex-direction:column;overflow:hidden;font-size:14px}
button{cursor:pointer;font-family:inherit}
input,textarea{font-family:inherit}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
::selection{background:var(--acc-glow);color:var(--acc)}
#header{height:var(--header-h);min-height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;padding:0 14px;position:relative;z-index:50;box-shadow:var(--shadow)}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:15px;color:var(--text);white-space:nowrap}
.logo i{color:var(--acc);font-size:18px}
.logo span{color:var(--text2);font-weight:400;font-size:12px;margin-left:2px}
.hdr-tools{display:flex;align-items:center;gap:6px;flex:1;justify-content:flex-end;flex-wrap:nowrap}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:var(--radius);border:1px solid transparent;font-size:13px;font-weight:500;transition:var(--trans);white-space:nowrap;background:transparent;color:var(--text)}
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
#sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;transition:transform .25s ease}
.sidebar-section{padding:14px 10px 6px;font-size:11px;font-weight:600;color:var(--text2);letter-spacing:.08em;text-transform:uppercase}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 12px;border-radius:var(--radius);margin:1px 6px;cursor:pointer;font-size:13px;color:var(--text2);transition:var(--trans);border:1px solid transparent}
.nav-item:hover{background:var(--surface2);color:var(--text);border-color:var(--border)}
.nav-item.active{background:var(--acc-glow);color:var(--acc);border-color:rgba(0,124,186,0.25)}
.nav-item i{width:16px;text-align:center;font-size:14px}
.disk-card{margin:10px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius2)}
.disk-label{font-size:11px;color:var(--text2);margin-bottom:6px;display:flex;justify-content:space-between}
.disk-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden}
.disk-fill{height:100%;background:linear-gradient(90deg,var(--acc),var(--purple));border-radius:3px;transition:.6s ease}
#content{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative}
#toolbar{padding:8px 14px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tool-sep{width:1px;height:20px;background:var(--border);margin:0 2px}
#breadcrumb{padding:8px 14px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:4px;overflow-x:auto;white-space:nowrap;font-size:13px}
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
#drop-overlay{display:none;position:absolute;inset:0;background:rgba(0,124,186,0.05);border:2px dashed var(--acc);border-radius:var(--radius2);z-index:200;pointer-events:none;align-items:center;justify-content:center;flex-direction:column;gap:12px;font-size:18px;font-weight:600;color:var(--acc);backdrop-filter:blur(2px)}
#drop-overlay i{font-size:48px;opacity:.7}
#file-list.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.file-card{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius2);padding:14px 10px 12px;text-align:center;cursor:pointer;transition:var(--trans);position:relative;user-select:none;display:flex;flex-direction:column;align-items:center;gap:6px}
.file-card:hover{background:var(--surface);border-color:var(--border2);transform:translateY(-1px);box-shadow:var(--shadow)}
.file-card.selected{border-color:var(--acc);background:var(--acc-glow);box-shadow:0 0 0 1px var(--acc)}
.file-card .fc-icon{font-size:34px;line-height:1}
.file-card .fc-name{font-size:11px;word-break:break-all;color:var(--text);line-height:1.3;max-height:30px;overflow:hidden}
.file-card .fc-size{font-size:10px;color:var(--text2)}
.file-card .fc-check{position:absolute;top:6px;left:6px;width:16px;height:16px;border-radius:4px;border:1.5px solid var(--border2);background:var(--surface);display:none;align-items:center;justify-content:center;font-size:10px;color:var(--acc)}
.multi-select .file-card .fc-check{display:flex}
.file-card.selected .fc-check{background:var(--acc);border-color:var(--acc);color:#fff}
.file-card .fc-check::after{content:'✓'}
#file-list.list{display:flex;flex-direction:column;gap:2px}
.file-row{display:grid;grid-template-columns:20px 24px 1fr 90px 90px 100px 80px;align-items:center;gap:8px;padding:7px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;transition:var(--trans);user-select:none}
.file-row:hover{background:var(--surface);border-color:var(--border2)}
.file-row.selected{border-color:var(--acc);background:var(--acc-glow)}
.file-row .fr-check input[type=checkbox]{accent-color:var(--acc);width:14px;height:14px}
.file-row .fr-icon{font-size:16px;text-align:center}
.file-row .fr-name{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-row .fr-size,.file-row .fr-perms,.file-row .fr-mtime,.file-row .fr-type{font-size:11px;color:var(--text2)}
.list-header{display:grid;grid-template-columns:20px 24px 1fr 90px 90px 100px 80px;align-items:center;gap:8px;padding:5px 10px;font-size:11px;font-weight:600;color:var(--text2);letter-spacing:.05em;text-transform:uppercase;border-bottom:1px solid var(--border);margin-bottom:4px;cursor:default}
.ic-folder{color:#e68a00}.ic-php{color:#7058c0}.ic-html,.ic-htm{color:#c0600a}.ic-css{color:#007cba}.ic-js覆{color:#b0a000}.ic-json{color:#1a8a4a}.ic-md{color:#805ac0}.ic-img{color:#c0208a}.ic-zip,.ic-tar,.ic-gz{color:#c05a00}.ic-pdf{color:#c02b0a}.ic-sql{color:#00789a}.ic-txt{color:#5a6a7a}.ic-sh{color:#2a8a3a}.ic-xml{color:#c0600a}.ic-mp4,.ic-webm{color:#805ac0}.ic-mp3,.ic-wav{color:#c0206a}.ic-default{color:#7a8a9a}
#statusbar{height:var(--status-h);background:var(--surface);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 14px;font-size:11px;color:var(--text2);gap:16px}
.sb-info{display:flex;align-items:center;gap:12px}
.sb-badge{background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:1px 7px;font-size:11px}
#ctx-menu{display:none;position:fixed;z-index:9000;background:var(--bg);border:1px solid var(--border2);border-radius:var(--radius2);padding:6px;min-width:190px;box-shadow:0 8px 30px rgba(0,0,0,0.15);animation:fadeIn .1s ease}
@keyframes fadeIn{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:scale(1)}}
.ctx-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:13px;transition:var(--trans);color:var(--text)}
.ctx-item:hover{background:var(--surface2);color:var(--acc)}
.ctx-item i{width:14px;text-align:center;color:var(--text2);font-size:13px}
.ctx-item:hover i{color:var(--acc)}
.ctx-item.danger{color:var(--red)}
.ctx-item.danger:hover{background:rgba(192,43,10,0.05)}
.ctx-item.danger i{color:var(--red)}
.ctx-sep{height:1px;background:var(--border);margin:4px 0}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.2);z-index:8000;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.modal-backdrop.open{display:flex}
.modal{background:var(--bg);border:1px solid var(--border2);border-radius:var(--radius2);overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,0.12);animation:slideUp .2s ease}
@keyframes slideUp{from{transform:translateY(12px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px}
.modal-title i{color:var(--acc)}
.modal-body{padding:18px;flex:1;overflow:auto}
.modal-footer{padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.close-btn{background:transparent;border:none;color:var(--text2);font-size:18px;padding:4px 8px;border-radius:4px;transition:var(--trans)}
.close-btn:hover{color:var(--text);background:var(--surface2)}
#editor-modal .modal{width:90vw;max-width:1100px;height:88vh}
#editor-wrap{display:flex;flex:1;overflow:hidden;border-top:1px solid var(--border)}
#line-numbers{width:40px;background:var(--surface);color:var(--text2);padding:14px 0;font-family:'Consolas','Courier New',monospace;font-size:13.5px;line-height:1.7;text-align:right;overflow:hidden;user-select:none;border-right:1px solid var(--border)}
#line-numbers div{padding-right:8px}
#code-editor{flex:1;background:#fff;color:var(--text);border:none;padding:14px;font-family:'Consolas','Courier New',monospace;font-size:13.5px;line-height:1.7;outline:none;resize:none;tab-size:2;overflow:auto}
#terminal-modal .modal{width:90vw;max-width:900px;height:70vh}
#terminal-output{background:#0d1117;color:#c9d1d9;font-family:'Courier New',monospace;font-size:13px;padding:16px;flex:1;overflow-y:auto;white-space:pre-wrap;word-break:break-all;border-radius: 0 0 var(--radius) var(--radius)}
#terminal-input-wrap{display:flex;align-items:center;background:#161b22;border-top:1px solid #30363d;padding:8px 12px}
#terminal-prompt{color:#58a6ff;font-family:'Courier New',monospace;font-size:13px;white-space:nowrap;margin-right:8px}
#terminal-input{flex:1;background:transparent;border:none;color:#c9d1d9;font-family:'Courier New',monospace;font-size:13px;outline:none}
#terminal-input::placeholder{color:#484f58}
#prompt-modal .modal{width:400px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;letter-spacing:.05em;text-transform:uppercase}
.form-input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:var(--radius);padding:8px 12px;outline:none;font-size:14px;transition:var(--trans)}
.form-input:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-glow)}
#toast-wrap{position:fixed;bottom:50px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--bg);border:1px solid var(--border2);border-radius:var(--radius2);padding:12px 16px;min-width:260px;max-width:340px;box-shadow:0 8px 24px rgba(0,0,0,0.1);animation:slideIn .2s ease}
@keyframes slideIn{from{transform:translateX(20px);opacity:0}to{transform:translateX(0);opacity:1}}
.toast-title{font-size:13px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px}
.toast-title i{font-size:14px}
.toast-bar{height:4px;background:var(--border);border-radius:2px;overflow:hidden}
.toast-fill{height:100%;background:linear-gradient(90deg,var(--acc),var(--purple));transition:.3s ease}
.toast.success .toast-fill{background:var(--green)}
.toast.error .toast-fill{background:var(--red);width:100%}
#upload-modal .modal{width:480px}
#upload-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;border:2px dashed var(--border2);border-radius:var(--radius2);padding:30px;text-align:center;color:var(--text2);cursor:pointer;transition:var(--trans)}
#upload-drop:hover,#upload-drop.drag{border-color:var(--acc);background:var(--acc-glow);color:var(--acc)}
#upload-drop i{font-size:40px;margin-bottom:10px}
#upload-drop p{font-size:13px}
#upload-list{margin-top:12px;display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto}
.upload-item{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:8px 10px;font-size:12px}
.upload-item i{color:var(--text2)}
.upload-item .ui-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.upload-item .ui-size{color:var(--text2);white-space:nowrap}
.upload-item.done{border-color:rgba(0,138,32,.35);background:rgba(0,138,32,.05)}
.upload-item.failed{border-color:rgba(192,43,10,.35);background:rgba(192,43,10,.05)}
.upload-item .ui-status{font-size:11px;color:var(--text2);white-space:nowrap}
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
  .sidebar-toggle{display:inline-flex!important}
}
.sidebar-toggle{display:none}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:200px;color:var(--text2);gap:12px}
.empty-state i{font-size:48px;opacity:.3}
.empty-state p{font-size:14px}
.sort-asc::after{content:' \2191'}
.sort-desc::after{content:' \2193'}
.sidebar-close{display:none;position:absolute;top:10px;right:14px;background:transparent;border:none;color:var(--text2);font-size:24px;cursor:pointer;z-index:200;padding:4px}
@media(max-width:768px){
  .sidebar-close{display:block}
  #sidebar{padding-top:45px}
}
.nav-item:active{transform:scale(0.98)}
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
.spin-animation {
  animation: spin 0.6s cubic-bezier(0.4, 0, 0.2, 1);
  display: inline-block;
}
</style>
</head>
<body>

<div id="header">
  <div class="logo">
    <i class="fa-solid fa-folder-tree"></i> File Manager <span>Pro</span>
  </div>
  <div class="hdr-tools">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="search-input" placeholder="Search files…" oninput="debounceSearch(this.value)" autocomplete="off">
    </div>
    <button class="btn btn-primary btn-icon" onclick="openUpload()" title="Upload Files">
      <i class="fa-solid fa-cloud-arrow-up"></i> <span class="btn-label">Upload</span>
    </button>
    <button class="btn btn-ghost btn-icon" onclick="openTerminal()" title="Terminal">
      <i class="fa-solid fa-terminal"></i> <span class="btn-label">Terminal</span>
    </button>
    <button class="btn btn-ghost btn-icon sidebar-toggle" onclick="toggleSidebar()" title="Menu">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</div>

<div id="main">
  <div id="sidebar">
    <button class="sidebar-close" onclick="toggleSidebar()"><i class="fa-solid fa-xmark"></i></button>
    <div class="sidebar-section">Navigation</div>
    <div class="nav-item active" id="nav-local" onclick="loadLocalRoot()"><i class="fa-solid fa-house"></i> Root (htdocs)</div>
    <div class="nav-item" id="nav-remote" onclick="remoteMode ? loadRemote() : promptSFTP()"><i class="fa-solid fa-network-wired"></i> <span id="sftp-label">Connect SFTP</span></div>
    <div class="sidebar-section">Quick Actions</div>
    <div class="nav-item" onclick="promptAction('mkdir')"><i class="fa-solid fa-folder-plus"></i> New Folder</div>
    <div class="nav-item" onclick="promptAction('mkfile')"><i class="fa-solid fa-file-circle-plus"></i> New File</div>
    <div class="nav-item" onclick="openUpload()"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Files</div>
    <div class="sidebar-section">View</div>
    <div class="nav-item active" id="nav-grid" onclick="setView('grid')"><i class="fa-solid fa-grip"></i> Grid View</div>
    <div class="nav-item" id="nav-list" onclick="setView('list')"><i class="fa-solid fa-list"></i> List View</div>
    
    <div class="sidebar-section">Project Source</div>
    <a href="https://github.com/shkumaraman/free-web-hosting" target="_blank" class="nav-item" style="text-decoration:none; color:var(--purple); font-weight:600;">
      <i class="fa-brands fa-github" style="color:var(--purple);"></i> SHKUMARAMAN
    </a>

    <div style="flex:1"></div>
    <div class="disk-card" id="disk-card">
      <div class="disk-label"><span><i class="fa-solid fa-hard-drive"></i> Disk Usage</span><span id="disk-pct">–</span></div>
      <div class="disk-bar"><div class="disk-fill" id="disk-fill" style="width:0%"></div></div>
      <div style="font-size:10px;color:var(--text2);margin-top:5px" id="disk-detail">Loading…</div>
    </div>
  </div>

  <div id="content">
    <div id="toolbar">
      <button class="btn btn-ghost btn-sm btn-icon" onclick="navUp()"><i class="fa-solid fa-arrow-up"></i></button>
      <button class="btn btn-ghost btn-sm btn-icon" onclick="load()"><i class="fa-solid fa-arrows-rotate"></i></button>
      <div class="tool-sep"></div>
      <button class="btn btn-ghost btn-sm" onclick="promptAction('mkdir')"><i class="fa-solid fa-folder-plus"></i> Folder</button>
      <button class="btn btn-ghost btn-sm" onclick="promptAction('mkfile')"><i class="fa-solid fa-file-plus"></i> File</button>
      <div class="tool-sep"></div>
      <button class="btn btn-ghost btn-sm" id="sel-btn" onclick="toggleMultiSelect()"><i class="fa-solid fa-check-double"></i> Multi-Select</button>
      <button class="btn btn-ghost btn-sm" id="sel-all-btn" onclick="selectAll()"><i class="fa-solid fa-square-check"></i> Select All</button>
      <button class="btn btn-danger btn-sm" id="del-btn" onclick="deleteSelected()" style="display:none"><i class="fa-solid fa-trash"></i> (<span id="sel-count">0</span>)</button>
      <button class="btn btn-ghost btn-sm" id="zip-sel-btn" onclick="zipSelected()" style="display:none"><i class="fa-solid fa-file-zipper"></i></button>
      <button class="btn btn-ghost btn-sm" id="clear-sel-btn" onclick="clearSelection()" style="display:none"><i class="fa-solid fa-xmark"></i></button>
      <div style="flex:1"></div>
      <button class="view-btn active" id="vbtn-grid" onclick="setView('grid')"><i class="fa-solid fa-grip"></i></button>
      <button class="view-btn" id="vbtn-list" onclick="setView('list')"><i class="fa-solid fa-list"></i></button>
    </div>
    <div id="breadcrumb"></div>
    <div id="file-area" ondragover="onDragOver(event)" ondragleave="onDragLeave(event)" ondrop="onDrop(event)">
      <div id="drop-overlay"><i class="fa-solid fa-cloud-arrow-up"></i> Drop files to upload</div>
      <div id="list-header" class="list-header" style="display:none">
        <div><input type="checkbox" id="header-select-all" onclick="toggleSelectAll(this)"></div>
        <div></div>
        <div class="hdr-sort" style="cursor:pointer" data-sort="name" onclick="handleSort('name')">Name</div>
        <div class="hdr-sort" style="cursor:pointer" data-sort="mtime" onclick="handleSort('mtime')">Modified</div>
        <div class="hdr-sort" style="cursor:pointer" data-sort="size" onclick="handleSort('size')">Size</div>
        <div class="hdr-sort" style="cursor:pointer" data-sort="perms" onclick="handleSort('perms')">Perms</div>
        <div class="hdr-sort" style="cursor:pointer" data-sort="ext" onclick="handleSort('ext')">Type</div>
      </div>
      <div id="file-list" class="grid"></div>
    </div>
    <div id="statusbar">
      <div class="sb-info"><span id="sb-items">–</span></div>
      <div class="sb-info"><span id="sb-path" style="font-family:monospace;font-size:11px;color:var(--text2)"></span></div>
    </div>
  </div>
</div>

<div id="ctx-menu"></div>
<div id="toast-wrap"></div>

<div class="modal-backdrop" id="terminal-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-terminal"></i> Terminal</div><button class="close-btn" onclick="closeModal('terminal-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body p-0 d-flex flex-column" style="background:#0d1117;">
      <div id="terminal-output"></div>
      <div id="terminal-input-wrap"><span id="terminal-prompt">$ /</span><input type="text" id="terminal-input" placeholder="Type command..."></div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="sftp-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-network-wired"></i> SFTP Connection</div><button class="close-btn" onclick="closeModal('sftp-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Host</label><input type="text" class="form-input" id="sftp-host"></div>
      <div class="form-group"><label class="form-label">Port</label><input type="number" class="form-input" id="sftp-port" value="22"></div>
      <div class="form-group"><label class="form-label">Username</label><input type="text" class="form-input" id="sftp-user"></div>
      <div class="form-group"><label class="form-label">Password</label><input type="password" class="form-input" id="sftp-pass"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('sftp-modal')">Cancel</button><button class="btn btn-primary" onclick="sftpConnect()">Connect</button></div>
  </div>
</div>

<div class="modal-backdrop" id="prompt-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title" id="prompt-title"></div><button class="close-btn" onclick="closeModal('prompt-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><input type="text" class="form-input" id="prompt-input" onkeydown="if(event.key==='Enter')promptConfirm()"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('prompt-modal')">Cancel</button><button class="btn btn-primary" onclick="promptConfirm()">OK</button></div>
  </div>
</div>

<div class="modal-backdrop" id="rename-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Rename</div><button class="close-btn" onclick="closeModal('rename-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><input type="text" class="form-input" id="rename-input" onkeydown="if(event.key==='Enter')doRename()"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('rename-modal')">Cancel</button><button class="btn btn-primary" onclick="doRename()">Rename</button></div>
  </div>
</div>

<div class="modal-backdrop" id="editor-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title" id="editor-title"></div><button class="close-btn" onclick="closeModal('editor-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="editor-wrap"><div id="line-numbers"></div><textarea id="code-editor" spellcheck="false" oninput="editorChanged()"></textarea></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('editor-modal')">Close</button><button class="btn btn-primary" onclick="saveFile()"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
  </div>
</div>

<div class="modal-backdrop" id="upload-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Upload Files</div><button class="close-btn" onclick="closeModal('upload-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body">
      <div id="upload-drop" onclick="document.getElementById('file-input').click()" ondragover="uploadDragOver(event)" ondrop="uploadDrop(event)" ondragleave="uploadDragLeave(event)">
        <i class="fa-solid fa-cloud-arrow-up"></i><p><strong>Click or drag files here</strong></p>
      </div>
      <input type="file" id="file-input" multiple style="display:none" onchange="fileInputChanged(this.files)">
      <div id="upload-list"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="clearUploadQueue()">Clear</button><button class="btn btn-ghost" onclick="closeModal('upload-modal')">Close</button><button class="btn btn-primary" id="upload-btn" onclick="doUpload()" disabled>Upload</button></div>
  </div>
</div>

<div class="modal-backdrop" id="preview-modal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title" id="preview-title"></div><button class="close-btn" onclick="closeModal('preview-modal')"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="preview-content" class="modal-body"></div>
  </div>
</div>

<div class="modal-backdrop" id="chmod-modal">
  <div class="modal" style="width: 420px;">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-shield-halved"></i> File Permissions</div>
      <button class="close-btn" onclick="closeModal('chmod-modal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px;">
        <div>
          <div class="form-label" style="margin-bottom: 8px;">Owner</div>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="or" onchange="updateOctal()"> Read
          </label>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="ow" onchange="updateOctal()"> Write
          </label>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="ox" onchange="updateOctal()"> Execute
          </label>
        </div>
        <div>
          <div class="form-label" style="margin-bottom: 8px;">Group</div>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="gr" onchange="updateOctal()"> Read
          </label>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="gw" onchange="updateOctal()"> Write
          </label>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="gx" onchange="updateOctal()"> Execute
          </label>
        </div>
        <div>
          <div class="form-label" style="margin-bottom: 8px;">Others</div>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="pr" onchange="updateOctal()"> Read
          </label>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="pw" onchange="updateOctal()"> Write
          </label>
          <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 6px;">
            <input type="checkbox" id="px" onchange="updateOctal()"> Execute
          </label>
        </div>
      </div>
      <div class="form-group" style="margin-bottom: 0;">
        <label class="form-label">Octal Notation</label>
        <div style="display: flex; align-items: center; gap: 10px;">
          <input type="text" class="form-input" id="chmod-octal-input" value="0644" style="width: 80px; text-align: center; font-weight: 600;" oninput="octalToChecks()">
          <span id="chmod-symbolic" style="font-family: monospace; font-size: 13px; color: var(--text2); background: var(--surface); padding: 6px 12px; border-radius: var(--radius); border: 1px solid var(--border);">rw-r--r--</span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('chmod-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="applyChmod()">Apply</button>
    </div>
  </div>
</div>

<script>
let currentPath = '', currentItems = [], currentView = 'grid', multiSelect = false, selected = new Set(), sortField = 'name', sortAsc = true, remoteMode = false, remoteCwd = '/', editingPath = '', pendingFiles = [], renameTarget = '', currentPreviewPath = '';
let sftpActive = <?php echo !empty($_SESSION['sftp_active']) ? 'true' : 'false'; ?>;

function api(action, data={}) {
  const url = `?api=${action}&dir=${encodeURIComponent(remoteMode ? remoteCwd : currentPath)}`;
  if (action === 'list' || action === 'diskusage') return fetch(url).then(r => r.json());
  const opts = { method: 'POST' };
  opts.headers = { 'Content-Type': 'application/json' };
  opts.body = JSON.stringify(data);
  return fetch(url, opts).then(r => r.json());
}

async function load(path) {
  const refreshIcon = document.querySelector('.fa-arrows-rotate');
  if (refreshIcon) {
    refreshIcon.classList.add('spin-animation');
    setTimeout(() => refreshIcon.classList.remove('spin-animation'), 600);
  }

  if (path !== undefined) {
    document.getElementById('search-input').value = '';
  } else {
    const q = document.getElementById('search-input').value.trim();
    if (q) {
      doSearch(q);
      return;
    }
  }

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
  else document.getElementById('disk-card').style.display = 'none';
}

async function loadDiskUsage() {
  const d = await api('diskusage');
  if (d.status !== 'success') return;
  const pct = Math.round(d.used / d.total * 100);
  document.getElementById('disk-pct').textContent = pct + '%';
  document.getElementById('disk-fill').style.width = pct + '%';
  document.getElementById('disk-detail').textContent = fmtSize(d.used) + ' used of ' + fmtSize(d.total);
}

function getIcon(item) {
  if (item.is_dir) return '<i class="fa-solid fa-folder ic-folder"></i>';
  const ext = (item.ext || '').toLowerCase();
  const map = {
    php:'fa-brands fa-php ic-php', html:'fa-brands fa-html5 ic-html', htm:'fa-brands fa-html5 ic-html',
    css:'fa-brands fa-css3-alt ic-css', js:'fa-brands fa-js ic-js', json:'fa-solid fa-brackets-curly ic-json',
    md:'fa-brands fa-markdown ic-md', jpg:'fa-solid fa-image ic-img', jpeg:'fa-solid fa-image ic-img',
    png:'fa-solid fa-image ic-img', gif:'fa-solid fa-image ic-img', webp:'fa-solid fa-image ic-img',
    svg:'fa-solid fa-image ic-img', zip:'fa-solid fa-file-zipper ic-zip', tar:'fa-solid fa-file-zipper ic-zip',
    gz:'fa-solid fa-file-zipper ic-gz', pdf:'fa-solid fa-file-pdf ic-pdf', sql:'fa-solid fa-database ic-sql',
    txt:'fa-solid fa-file-lines ic-txt', sh:'fa-solid fa-terminal ic-sh', xml:'fa-solid fa-code ic-xml',
    mp4:'fa-solid fa-film ic-mp4', webm:'fa-solid fa-film ic-mp4', mp3:'fa-solid fa-music ic-mp3',
    wav:'fa-solid fa-music ic-mp3'
  };
  return `<i class="${map[ext] || 'fa-solid fa-file ic-default'}"></i>`;
}

function sortItems(items) {
  return [...items].sort((a,b) => {
    if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
    let av = a[sortField], bv = b[sortField];
    if (typeof av === 'string') { av = av.toLowerCase(); bv = bv.toLowerCase(); }
    if (av < bv) return sortAsc ? -1 : 1;
    if (av > bv) return sortAsc ? 1 : -1;
    return 0;
  });
}

function render(items) {
  const list = document.getElementById('file-list');
  const hdr = document.getElementById('list-header');
  const sorted = sortItems(items || currentItems);
  list.className = currentView;
  list.innerHTML = '';
  hdr.style.display = currentView === 'list' ? 'grid' : 'none';

  document.querySelectorAll('.hdr-sort').forEach(el => {
    el.classList.remove('sort-asc','sort-desc');
    if (el.dataset.sort === sortField) el.classList.add(sortAsc ? 'sort-asc' : 'sort-desc');
  });

  const headerCb = document.getElementById('header-select-all');
  if (headerCb) {
    headerCb.checked = currentItems.length > 0 && selected.size === currentItems.length;
  }

  if (!sorted.length) {
    list.className = '';
    list.innerHTML = `<div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>Empty folder</p></div>`;
    return;
  }

  sorted.forEach(item => {
    const fullRelPath = remoteMode ? remoteCwd + '/' + item.name : (currentPath ? currentPath + '/' + item.name : item.name);
    if (currentView === 'grid') {
      const card = document.createElement('div');
      card.className = 'file-card' + (selected.has(fullRelPath) ? ' selected' : '');
      card.dataset.path = fullRelPath;
      card.innerHTML = `<div class="fc-icon">${getIcon(item)}</div><div class="fc-name">${escHtml(item.name)}</div><div class="fc-size">${item.is_dir ? '—' : fmtSize(item.size)}</div>`;
      card.onclick = e => handleClick(e, item, fullRelPath, card);
      card.ondblclick = () => handleDblClick(item, fullRelPath);
      card.oncontextmenu = e => showCtx(e, item, fullRelPath);
      list.appendChild(card);
    } else {
      const row = document.createElement('div');
      row.className = 'file-row' + (selected.has(fullRelPath) ? ' selected' : '');
      row.dataset.path = fullRelPath;
      const d = new Date(item.mtime * 1000);
      const mtime = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
      row.innerHTML = `<div><input type="checkbox" ${selected.has(fullRelPath)?'checked':''}></div><div class="fr-icon">${getIcon(item)}</div><div class="fr-name">${escHtml(item.name)}</div><div class="fr-mtime">${mtime}</div><div class="fr-size">${item.is_dir ? '—' : fmtSize(item.size)}</div><div class="fr-perms">${item.perms}</div><div class="fr-type">${item.is_dir ? 'Folder' : (item.ext ? item.ext.toUpperCase() : 'File')}</div>`;
      const cb = row.querySelector('input');
      cb.addEventListener('change', e => { e.stopPropagation(); toggleSelect(fullRelPath, row); });
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
    document.querySelectorAll('.file-card.selected,.file-row.selected').forEach(x => {
      x.classList.remove('selected');
      const cb = x.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = false;
    });
    toggleSelect(path, el);
  }
  updateSelectionUI();
}

document.getElementById('file-area').addEventListener('click', function(e) {
  if (e.target === this || e.target.id === 'drop-overlay' || e.target.closest('#drop-overlay')) {
    clearSelection();
  }
});

function toggleSelect(path, el) {
  if (selected.has(path)) {
    selected.delete(path);
    el && el.classList.remove('selected');
    if (el) { const cb = el.querySelector('input[type=checkbox]'); if (cb) cb.checked = false; }
  } else {
    selected.add(path);
    el && el.classList.add('selected');
    if (el) { const cb = el.querySelector('input[type=checkbox]'); if (cb) cb.checked = true; }
  }
  
  const headerCb = document.getElementById('header-select-all');
  if (headerCb) {
    headerCb.checked = currentItems.length > 0 && selected.size === currentItems.length;
  }
  
  updateSelectionUI();
}

function rangeSelect(path) {
  const items = Array.from(document.querySelectorAll('.file-card,.file-row'));
  const paths = items.map(i => i.dataset.path);
  const lastSel = [...selected].pop();
  const a = paths.indexOf(lastSel), b = paths.indexOf(path);
  if (a < 0 || b < 0) return;
  const [s, e] = a < b ? [a,b] : [b,a];
  paths.slice(s, e+1).forEach(p => {
    selected.add(p);
    const el = document.querySelector(`[data-path="${CSS.escape(p)}"]`);
    el && el.classList.add('selected');
    if (el) { const cb = el.querySelector('input[type=checkbox]'); if (cb) cb.checked = true; }
  });
  updateSelectionUI();
}

function updateSelectionUI() {
  const n = selected.size;
  document.getElementById('del-btn').style.display = n ? '' : 'none';
  document.getElementById('zip-sel-btn').style.display = n ? '' : 'none';
  document.getElementById('clear-sel-btn').style.display = n ? '' : 'none';
  document.getElementById('sel-count').textContent = n;
}

function clearSelection() {
  selected.clear();
  document.querySelectorAll('.file-card.selected,.file-row.selected').forEach(x => {
    x.classList.remove('selected');
    const cb = x.querySelector('input[type="checkbox"]');
    if (cb) cb.checked = false;
  });
  const headerCb = document.getElementById('header-select-all');
  if (headerCb) headerCb.checked = false;
  updateSelectionUI();
}

function selectAll() {
  selected.clear();
  currentItems.forEach(item => {
    const fullRelPath = remoteMode ? remoteCwd + '/' + item.name : (currentPath ? currentPath + '/' + item.name : item.name);
    selected.add(fullRelPath);
  });
  render();
  const headerCb = document.getElementById('header-select-all');
  if (headerCb) headerCb.checked = true;
  updateSelectionUI();
}

function toggleMultiSelect() {
  multiSelect = !multiSelect;
  const btn = document.getElementById('sel-btn');
  btn.style.background = multiSelect ? 'var(--acc-glow)' : '';
  btn.style.borderColor = multiSelect ? 'var(--acc)' : '';
  btn.style.color = multiSelect ? 'var(--acc)' : '';
  if (!multiSelect) {
    selected.clear();
    const headerCb = document.getElementById('header-select-all');
    if (headerCb) headerCb.checked = false;
    render();
    updateSelectionUI();
  }
}

function handleSort(field) {
  if (sortField === field) {
    sortAsc = !sortAsc;
  } else {
    sortField = field;
    sortAsc = true;
  }
  render();
}

function toggleSelectAll(cb) {
  if (cb.checked) {
    selectAll();
  } else {
    clearSelection();
  }
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

function navUp() { const parts = (remoteMode ? remoteCwd : currentPath).split('/').filter(p => p); parts.pop(); load(parts.join('/')); }

function renderBreadcrumb() {
  const bc = document.getElementById('breadcrumb');
  const base = remoteMode ? remoteCwd : currentPath;
  const parts = base.split('/').filter(p => p);
  let html = `<i class="fa-solid fa-house" style="color:var(--text2);font-size:12px"></i><span class="bc-sep">›</span><span class="bc-item" onclick="load('')">htdocs</span>`;
  let built = '';
  parts.forEach((p,i) => {
    built += (built ? '/' : '') + p;
    html += `<span class="bc-sep">›</span><span class="bc-item${i===parts.length-1?' active':''}" onclick="load('${escHtml(built)}')">${escHtml(p)}</span>`;
  });
  bc.innerHTML = html;
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
    document.querySelectorAll('.file-card.selected,.file-row.selected').forEach(x => x.classList.remove('selected'));
    selected.add(path);
    const el = document.querySelector(`[data-path="${CSS.escape(path)}"]`);
    el && el.classList.add('selected');
    updateSelectionUI();
  }
  const isZip = item.ext === 'zip';
  const menu = document.getElementById('ctx-menu');
  menu.innerHTML = `
    ${!item.is_dir ? `<div class="ctx-item" onclick="openEditor('${escJs(path)}','${escJs(item.name)}')"><i class="fa-solid fa-pen"></i> Edit</div>` : ''}
    ${!item.is_dir ? `<div class="ctx-item" onclick="openPreview(currentItemByPath('${escJs(path)}'),'${escJs(path)}')"><i class="fa-solid fa-eye"></i> Preview</div>` : ''}
    ${!item.is_dir ? `<div class="ctx-item" onclick="downloadFile('${escJs(path)}')"><i class="fa-solid fa-download"></i> Download</div>` : ''}
    
    ${remoteMode ? 
      `<div class="ctx-item" onclick="transferCross('to_local', '${escJs(path)}')"><i class="fa-solid fa-arrow-down-left-and-arrow-up-right-to-center"></i> Transfer to Local</div>` : 
      (sftpActive ? `<div class="ctx-item" onclick="transferCross('to_sftp', '${escJs(path)}')"><i class="fa-solid fa-network-wired"></i> Transfer to SFTP</div>` : '')
    }

    <div class="ctx-item" onclick="openRename('${escJs(path)}','${escJs(item.name)}')"><i class="fa-solid fa-pen-to-square"></i> Rename</div>
    <div class="ctx-item" onclick="openMoveCopyModal('copy')"><i class="fa-solid fa-copy"></i> Copy to…</div>
    <div class="ctx-item" onclick="openMoveCopyModal('move')"><i class="fa-solid fa-scissors"></i> Move to…</div>
    ${isZip ? `<div class="ctx-item" onclick="doUnzip('${escJs(path)}')"><i class="fa-solid fa-file-arrow-down"></i> Extract</div>` : `<div class="ctx-item" onclick="zipSelected()"><i class="fa-solid fa-file-zipper"></i> Compress</div>`}
    <div class="ctx-item" onclick="openChmod('${escJs(path)}','${escJs(item.perms)}')"><i class="fa-solid fa-shield-halved"></i> Permissions</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item danger" onclick="deleteSelected()"><i class="fa-solid fa-trash"></i> Delete</div>`;
  const pw = window.innerWidth, ph = window.innerHeight;
  let x = e.clientX, y = e.clientY;
  menu.style.display = 'block';
  const mw = menu.offsetWidth, mh = menu.offsetHeight;
  if (x + mw > pw) x = pw - mw - 8;
  if (y + mh > ph) y = ph - mh - 8;
  menu.style.left = x + 'px';
  menu.style.top = y + 'px';
}

document.addEventListener('click', () => { document.getElementById('ctx-menu').style.display = 'none'; });
document.addEventListener('keydown', e => { if (e.key==='Escape') { closeAllModals(); document.getElementById('ctx-menu').style.display='none'; } });

function currentItemByPath(path) {
  const name = path.split('/').pop();
  return currentItems.find(i => i.name === name) || {name,ext:'',is_dir:false,size:0,mtime:0,perms:'0644'};
}

function promptAction(action) {
  const modal = document.getElementById('prompt-modal');
  document.getElementById('prompt-input').value = '';
  if (action === 'mkdir') {
    document.getElementById('prompt-title').innerHTML = '<i class="fa-solid fa-folder-plus"></i> New Folder';
    document.getElementById('prompt-input').placeholder = 'Folder Name';
    window._promptAction = async () => {
      const name = document.getElementById('prompt-input').value.trim();
      if (!name) return;
      await api('mkdir', {name});
      closeModal('prompt-modal'); load(); showToast('Folder created','success');
    };
  } else {
    document.getElementById('prompt-title').innerHTML = '<i class="fa-solid fa-file-plus"></i> New File';
    document.getElementById('prompt-input').placeholder = 'File Name';
    window._promptAction = async () => {
      const name = document.getElementById('prompt-input').value.trim();
      if (!name) return;
      await api('mkfile', {name});
      closeModal('prompt-modal'); load(); showToast('File created','success');
    };
  }
  openModal('prompt-modal');
  setTimeout(() => document.getElementById('prompt-input').focus(), 100);
}

function promptConfirm() { window._promptAction && window._promptAction(); }

async function deleteSelected() {
  if (!selected.size) return;
  if (!confirm(`Delete ${selected.size} item(s)?`)) return;
  await api('delete', {paths:[...selected]});
  selected.clear(); load(); showToast('Deleted','success');
}

function openRename(path, name) {
  renameTarget = path;
  document.getElementById('rename-input').value = name;
  openModal('rename-modal');
  setTimeout(() => {
    const inp = document.getElementById('rename-input');
    inp.focus();
    const dot = name.lastIndexOf('.');
    inp.setSelectionRange(0, dot > 0 ? dot : name.length);
  }, 100);
}

async function doRename() {
  const newName = document.getElementById('rename-input').value.trim();
  if (!newName) return;
  const dir = renameTarget.split('/').slice(0,-1).join('/');
  const newPath = (dir ? dir+'/' : '') + newName;
  await api('rename', {old:renameTarget, new:newPath});
  closeModal('rename-modal'); load(); showToast('Renamed','success');
}

function openMoveCopyModal(mode) {
  if (!selected.size) return;
  const dst = prompt(`Destination (relative):`, currentPath);
  if (!dst) return;
  api(mode, {paths:[...selected], dst}).then(r => {
    if (r.status === 'success') { selected.clear(); load(); showToast(mode==='move'?'Moved':'Copied','success'); }
  });
}

async function zipSelected() {
  if (!selected.size) return;
  const name = prompt('ZIP file name:', [...selected][0].split('/').pop() + '.zip');
  if (!name) return;
  await api('zip', {paths:[...selected], name});
  load(); showToast('ZIP created','success');
}

async function doUnzip(path) {
  await api('unzip', {path});
  load(); showToast('Extracted','success');
}

function openChmod(path, perms) {
  chmodTarget = path;
  document.getElementById('chmod-octal-input').value = perms;
  octalToChecks();
  openModal('chmod-modal');
}

async function applyChmod() {
  const perms = document.getElementById('chmod-octal-input').value;
  await api('chmod', {path:chmodTarget, perms});
  closeModal('chmod-modal'); load(); showToast('Permissions updated','success');
}

function updateOctal() {
  const ids = ['or','ow','ox','gr','gw','gx','pr','pw','px'];
  const vals = ids.map(id => document.getElementById(id).checked ? 1 : 0);
  const o = (vals[0]*4+vals[1]*2+vals[2]) + '' + (vals[3]*4+vals[4]*2+vals[5]) + '' + (vals[6]*4+vals[7]*2+vals[8]);
  document.getElementById('chmod-octal-input').value = '0' + o;
  updateSymbolic('0' + o);
}

function octalToChecks() {
  let v = document.getElementById('chmod-octal-input').value.replace(/^0/,'');
  if (!/^[0-7]{3}$/.test(v)) return;
  const ids = ['or','ow','ox','gr','gw','gx','pr','pw','px'];
  const digits = v.split('').map(Number);
  digits.forEach((d,i) => {
    document.getElementById(ids[i*3]).checked   = !!(d & 4);
    document.getElementById(ids[i*3+1]).checked = !!(d & 2);
    document.getElementById(ids[i*3+2]).checked = !!(d & 1);
  });
  updateSymbolic('0'+v);
}

function updateSymbolic(octal) {
  const v = octal.replace(/^0/,'');
  if (!/^[0-7]{3}$/.test(v)) return;
  const s = v.split('').map(d => (d&4?'r':'-')+(d&2?'w':'-')+(d&1?'x':'-')).join('');
  document.getElementById('chmod-symbolic').textContent = s;
}

function updateEditorLineNumbers() {
  const textarea = document.getElementById('code-editor');
  const lines = textarea.value.split('\n').length;
  const lineNumDiv = document.getElementById('line-numbers');
  let html = '';
  for (let i = 1; i <= lines; i++) html += `<div>${i}</div>`;
  lineNumDiv.innerHTML = html;
  lineNumDiv.scrollTop = textarea.scrollTop;
}

document.getElementById('code-editor').addEventListener('scroll', function() {
  document.getElementById('line-numbers').scrollTop = this.scrollTop;
});

async function openEditor(path, name) {
  const res = await api('read', {path});
  if (res.status !== 'success') { showToast('Cannot open file','error'); return; }
  editingPath = path;
  document.getElementById('code-editor').value = res.content;
  document.getElementById('editor-title').textContent = name || path.split('/').pop();
  updateEditorLineNumbers();
  openModal('editor-modal');
}

async function saveFile() {
  const content = document.getElementById('code-editor').value;
  const res = await api('save', {path:editingPath, content});
  if (res.status === 'success') showToast('File saved','success');
  else showToast('Save failed','error');
  updateEditorLineNumbers();
}

function editorChanged() {
  const title = document.getElementById('editor-title');
  if (!title.textContent.endsWith('*')) title.textContent += '*';
  updateEditorLineNumbers();
}

function openPreview(item, path) {
  currentPreviewPath = path;
  const ext = (item.ext || '').toLowerCase();
  const wrap = document.getElementById('preview-content');
  document.getElementById('preview-title').textContent = item.name;
  wrap.innerHTML = '<div style="text-align:center;padding:30px"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
  fetch(`?api=preview&dir=${encodeURIComponent(remoteMode ? remoteCwd : currentPath)}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({path})})
    .then(r => r.blob())
    .then(blob => {
      const url = URL.createObjectURL(blob);
      const images = ['jpg','jpeg','png','gif','webp','svg'];
      const video = ['mp4','webm','ogg'];
      const audio = ['mp3','wav','ogg','m4a'];
      if (images.includes(ext)) wrap.innerHTML = `<img src="${url}" class="img-fluid">`;
      else if (video.includes(ext)) wrap.innerHTML = `<video controls class="w-100"><source src="${url}"></video>`;
      else if (audio.includes(ext)) wrap.innerHTML = `<audio controls class="w-100"><source src="${url}"></audio>`;
      else {
        const reader = new FileReader();
        reader.onload = e => wrap.innerHTML = `<pre style="padding:20px;white-space:pre-wrap;word-break:break-all;font-size:13px;line-height:1.6;font-family:monospace;overflow:auto">${escHtml(e.target.result)}</pre>`;
        reader.readAsText(blob);
      }
    });
  openModal('preview-modal');
}

function downloadFile(path) {
  fetch(`?api=download&dir=${encodeURIComponent(remoteMode ? remoteCwd : currentPath)}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({path})})
    .then(r => r.blob())
    .then(b => { const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = path.split('/').pop(); a.click(); });
}

function openUpload() { pendingFiles = []; renderUploadList(); openModal('upload-modal'); }

function fileInputChanged(files) {
  pendingFiles = [...pendingFiles, ...files];
  renderUploadList();
  document.getElementById('file-input').value = '';
}

function renderUploadList() {
  const ul = document.getElementById('upload-list');
  const btn = document.getElementById('upload-btn');
  ul.innerHTML = pendingFiles.map(f => `<div class="upload-item"><i class="fa-solid fa-file"></i><span class="ui-name">${escHtml(f.name)}</span><span class="ui-size">${fmtSize(f.size)}</span><span class="ui-status">Ready</span></div>`).join('');
  btn.disabled = pendingFiles.length === 0 || btn.dataset.busy === '1';
}

function clearUploadQueue() {
  if (document.getElementById('upload-btn').dataset.busy === '1') return;
  pendingFiles = [];
  document.getElementById('file-input').value = '';
  renderUploadList();
}

async function processChunkedUpload(file) {
  const chunkSize = 2 * 1024 * 1024;
  const totalChunks = Math.ceil(file.size / chunkSize);
  const list = document.getElementById('upload-list');
  
  let row = document.createElement('div');
  row.className = 'upload-item';
  row.innerHTML = `<i class="fa-solid fa-file"></i><span class="ui-name">${escHtml(file.name)}</span><span class="ui-size">${fmtSize(file.size)}</span><span class="ui-status">Initialising...</span>`;
  list.appendChild(row);
  const statusEl = row.querySelector('.ui-status');

  for (let index = 0; index < totalChunks; index++) {
    const start = index * chunkSize;
    const end = Math.min(start + chunkSize, file.size);
    const chunk = file.slice(start, end);
    
    const fd = new FormData();
    fd.append('file', chunk);
    fd.append('fileName', file.name);
    fd.append('chunkIndex', index);
    fd.append('totalChunks', totalChunks);
    
    const url = `?api=upload_chunk&dir=${encodeURIComponent(remoteMode ? remoteCwd : currentPath)}`;
    
    try {
      let res = await fetch(url, { method: 'POST', body: fd }).then(r => r.json());
      if (res.status !== 'success') throw new Error(res.message || 'Chunk error');
      
      const pct = Math.round(((index + 1) / totalChunks) * 100);
      statusEl.textContent = `Uploading ${pct}%`;
    } catch (err) {
      row.classList.add('failed');
      statusEl.textContent = 'Upload Failed';
      return false;
    }
  }
  row.classList.add('done');
  statusEl.textContent = 'Uploaded';
  return true;
}

async function doUpload() {
  if (!pendingFiles.length) return;
  const btn = document.getElementById('upload-btn');
  btn.dataset.busy = '1';
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading';
  
  document.getElementById('upload-list').innerHTML = '';
  
  for (let i = 0; i < pendingFiles.length; i++) {
    await processChunkedUpload(pendingFiles[i]);
  }
  
  btn.dataset.busy = '0';
  btn.innerHTML = 'Upload';
  pendingFiles = [];
  document.getElementById('file-input').value = '';
  await load(remoteMode ? remoteCwd : currentPath);
  setTimeout(() => closeModal('upload-modal'), 1200);
}

async function transferCross(direction, path) {
  const paths = selected.has(path) ? [...selected] : [path];
  const action = direction === 'to_local' ? 'transfer_to_local' : 'transfer_to_sftp';
  const data = direction === 'to_local' ? { paths, localDir: currentPath } : { paths, remoteDir: remoteCwd };
  
  showToast('Processing transfer across server boundaries...', 'info', 0);
  const res = await api(action, data);
  document.querySelectorAll('.toast').forEach(t => t.remove());
  if (res.status === 'success') {
    showToast('Cross transfer completed successfully', 'success');
    selected.clear();
    load();
  } else {
    showToast(res.message || 'Cross boundary pipeline crash', 'error');
  }
}

function uploadDragOver(e) { e.preventDefault(); document.getElementById('upload-drop').classList.add('drag'); }
function uploadDragLeave(e) { document.getElementById('upload-drop').classList.remove('drag'); }

function uploadDrop(e) {
  e.preventDefault();
  document.getElementById('upload-drop').classList.remove('drag');
  pendingFiles = [...pendingFiles, ...e.dataTransfer.files];
  renderUploadList();
}

function onDragOver(e) { e.preventDefault(); document.getElementById('drop-overlay').style.display='flex'; }
function onDragLeave(e) { if (!e.currentTarget.contains(e.relatedTarget)) document.getElementById('drop-overlay').style.display='none'; }

function onDrop(e) {
  e.preventDefault();
  document.getElementById('drop-overlay').style.display='none';
  pendingFiles = [...e.dataTransfer.files];
  if (pendingFiles.length) { renderUploadList(); openModal('upload-modal'); }
}

function debounceSearch(q) {
  clearTimeout(window._searchTimer);
  if (!q.trim()) { load(currentPath); return; }
  window._searchTimer = setTimeout(() => doSearch(q), 400);
}

async function doSearch(q) {
  const res = await api('search', {query: q});
  if (res.status !== 'success') return;
  currentItems = res.results;
  render(res.results);
  document.getElementById('sb-items').textContent = `${res.results.length} result${res.results.length!==1?'s':''} for "${q}"`;
}

function setView(v) {
  currentView = v;
  document.getElementById('vbtn-grid').classList.toggle('active', v==='grid');
  document.getElementById('vbtn-list').classList.toggle('active', v==='list');
  document.getElementById('nav-grid').classList.toggle('active', v==='grid');
  document.getElementById('nav-list').classList.toggle('active', v==='list');
  render();
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function closeAllModals() { document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open')); }

document.querySelectorAll('.modal-backdrop').forEach(b => {
  b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); });
});

let toastId = 0;
function showToast(msg, type='info', duration=3000) {
  const id = ++toastId;
  const icon = type==='success'?'fa-circle-check':type==='error'?'fa-circle-xmark':'fa-circle-info';
  const color = type==='success'?'var(--green)':type==='error'?'var(--red)':'var(--acc)';
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.id = 'toast-'+id;
  el.innerHTML = `<div class="toast-title"><i class="fa-solid ${icon}" style="color:${color}"></i>${escHtml(msg)}</div><div class="toast-bar"><div class="toast-fill" style="width:100%"></div></div>`;
  document.getElementById('toast-wrap').appendChild(el);
  if (duration > 0) {
    setTimeout(() => removeToast(id), duration);
    const fill = el.querySelector('.toast-fill');
    fill.style.transition = `width ${duration}ms linear`;
    setTimeout(() => fill.style.width = '0%', 50);
  }
  return id;
}

function removeToast(id) { const el = document.getElementById('toast-'+id); if (el) { el.style.opacity='0'; el.style.transform='translateX(20px)'; el.style.transition='.2s'; setTimeout(()=>el.remove(),200); } }
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }

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
    sftpActive = true;
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
      sftpActive = false;
      document.getElementById('nav-remote').classList.remove('active');
      document.getElementById('nav-local').classList.add('active');
      document.getElementById('sftp-label').textContent = 'Connect SFTP';
      document.getElementById('disk-card').style.display = '';
      load('');
    });
  } else load('');
}

function loadRemote() { load(remoteCwd); }

document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', () => {
    if (window.innerWidth <= 768) {
      document.getElementById('sidebar').classList.remove('open');
    }
  });
});

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escJs(s) { return String(s).replace(/'/g,"\\'").replace(/\\/g,'\\\\'); }

function fmtSize(b) {
  if (b >= 1073741824) return (b/1073741824).toFixed(2)+' GB';
  if (b >= 1048576) return (b/1048576).toFixed(2)+' MB';
  if (b >= 1024) return (b/1024).toFixed(1)+' KB';
  return b+' B';
}

document.addEventListener('keydown', e => {
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
  if (e.key === 'Delete' && selected.size) deleteSelected();
  if (e.key === 'F5') { e.preventDefault(); load(); }
  if (e.key === 'F2' && selected.size === 1) {
    const path = [...selected][0];
    const name = path.split('/').pop();
    openRename(path, name);
  }
});

load();
</script>
</body>
</html>
