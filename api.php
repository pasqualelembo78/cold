<?php
// api.php - Proxy per wallet-api (Mevacoin) con supporto download via token + X-Sendfile safe-mode
// Collocare in /var/www/mevacoin/api.php oppure come da VirtualHost rewrite.
// Legge API_KEY da variabile d'ambiente API_KEY o fallback /opt/mevacoin_config/.env

date_default_timezone_set('Europe/Rome');

# ----------------------- CONFIG -----------------------
$LOG_BASE          = '/tmp/mevacoin_api';
$QUEUE_DIR         = $LOG_BASE . '/queue';
$JOBS_DIR          = $LOG_BASE . '/jobs';
$RESULTS_DIR       = $LOG_BASE . '/results';
$VERBOSE_FILE      = $LOG_BASE . '/curl_verbose.log';
$API_DEBUG         = $LOG_BASE . '/api_debug.log';
$DOWNLOADS_DIR     = $LOG_BASE . '/downloads';            // meta token files
$SERVERS = [
    '82' => 'http://82.165.218.56:8070',
    '87' => 'http://87.106.40.193:8070'
];
$BASE_WALLET_DIR   = '/opt/mevacoin_data/wallets';
$ENV_PATH          = '/opt/mevacoin_config/.env';         // fallback file for API_KEY
$TOKEN_TTL         = 300;    // token lifetime in seconds (default 5 minuti)
$DOWNLOAD_BASE_URL = null;   // se vuoi forzare es. 'https://www.mevacoin.com' altrimenti usa Host
$USE_X_SENDFILE    = getenv('USE_X_SENDFILE') === '1'; // setta in env 1 se usi mod_xsendfile
$SENDFILE_DELAY_SEC = 30;    // se X-Sendfile=true, attendi questo tempo prima di cancellare file marked 'used'
# ------------------------------------------------------

@mkdir($LOG_BASE, 0775, true);
@mkdir($QUEUE_DIR, 0775, true);
@mkdir($JOBS_DIR, 0775, true);
@mkdir($RESULTS_DIR, 0775, true);
@mkdir($DOWNLOADS_DIR, 0700, true);
@mkdir($BASE_WALLET_DIR, 0755, true);

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

function log_line($path, $message) {
    $line = '['.date('Y-m-d H:i:s').'] ' . $message . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

# ------------------ Load API_KEY (env or .env fallback) ------------------
function load_api_key($envPath) {
    $key = getenv('API_KEY');
    if ($key !== false && $key !== '') return $key;
    if (!is_readable($envPath)) return null;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) {
        $line = trim($l);
        if ($line === '' || $line[0]==='#' || $line[0]===';') continue;
        if (strpos($line,'=') === false) continue;
        list($n,$v) = explode('=', $line, 2);
        if (trim($n) === 'API_KEY') {
            $val = trim($v);
            if ((substr($val,0,1) === '"' && substr($val,-1) === '"') || (substr($val,0,1) === "'" && substr($val,-1) === "'")) {
                $val = substr($val,1,-1);
            }
            putenv("API_KEY=$val");
            return $val;
        }
    }
    return null;
}
$FIXED_API_KEY = load_api_key($ENV_PATH);
log_line($API_DEBUG, "api.php start; API_KEY loaded? " . (!empty($FIXED_API_KEY) ? 'yes' : 'no'));

# ------------------ UTIL ------------------
function safe_basename($name) {
    $n = basename($name);
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $n);
}
function build_wallet_path_simple($baseDir, $filename) {
    $safe = safe_basename($filename);
    $dir = rtrim($baseDir,'/');
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    $full = $dir . '/' . $safe;
    if (file_exists($full)) {
        $dotPos = strrpos($safe, '.');
        if ($dotPos!==false) { $name = substr($safe,0,$dotPos); $ext = substr($safe,$dotPos); }
        else { $name = $safe; $ext = ''; }
        $uniq = bin2hex(random_bytes(4));
        $safe2 = $name . '_' . $uniq . $ext;
        $full = $dir . '/' . $safe2;
        $safe = $safe2;
    }
    return ['dir'=>$dir,'fullpath'=>$full,'relative'=>basename($full)];
}

# ------------------ HTTP client verso wallet-api ------------------
function call_wallet_api($host, $endpoint, $method='GET', $payload=null) {
    global $VERBOSE_FILE, $API_DEBUG, $FIXED_API_KEY;
    $endpoint = (substr($endpoint,0,1)==='/'? $endpoint : '/'.$endpoint);
    $url = rtrim($host,'/') . $endpoint;

    $ch = curl_init($url);
    $fh = @fopen($VERBOSE_FILE,'a+');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    if ($fh) curl_setopt($ch, CURLOPT_STDERR, $fh);

    $headers = ["Accept: application/json"];
    if (!empty($FIXED_API_KEY)) $headers[] = "X-API-KEY: $FIXED_API_KEY";
    if ($payload !== null) $headers[] = "Content-Type: application/json";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $m = strtoupper($method);
    if ($m === 'POST') curl_setopt($ch, CURLOPT_POST, true);
    elseif (in_array($m,['PUT','DELETE'])) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $m);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    if ($fh) fclose($fh);
    curl_close($ch);

    log_line($API_DEBUG, "call_wallet_api: $m $url -> http=" . ($info['http_code'] ?? 0) . " err=" . ($err?:'OK'));
    return ['url'=>$url,'method'=>$m,'payload'=>$payload,'response'=>$resp,'error'=>$err,'http_code'=>$info['http_code'] ?? 0];
}

# ------------------ Token storage ------------------
function generate_download_token($walletFullPath, $ttl = 300) {
    global $DOWNLOADS_DIR;
    $token = bin2hex(random_bytes(16));
    $meta = [
        'file' => $walletFullPath,
        'expires_at' => time() + $ttl,
        'created_at' => time(),
        'used' => false,
        'used_at' => null,
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    $metaPathTmp = rtrim($DOWNLOADS_DIR,'/') . '/' . $token . '.json.tmp';
    $metaPath    = rtrim($DOWNLOADS_DIR,'/') . '/' . $token . '.json';
    @file_put_contents($metaPathTmp, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    rename($metaPathTmp, $metaPath);
    @chmod($metaPath, 0600);
    return $token;
}

function load_token_meta($token) {
    global $DOWNLOADS_DIR;
    $metaPath = rtrim($DOWNLOADS_DIR,'/') . '/' . $token . '.json';
    if (!is_file($metaPath) || !is_readable($metaPath)) return null;
    $c = @file_get_contents($metaPath);
    if ($c === false) return null;
    $j = json_decode($c, true);
    if (!is_array($j)) return null;
    $j['_meta_path'] = $metaPath;
    return $j;
}

function mark_token_used($token) {
    $meta = load_token_meta($token);
    if ($meta === null) return false;
    $meta['used'] = true;
    $meta['used_at'] = time();
    $path = $meta['_meta_path'];
    @file_put_contents($path . '.tmp', json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    rename($path . '.tmp', $path);
    @chmod($path, 0600);
    return true;
}

function remove_token_meta($token) {
    global $DOWNLOADS_DIR;
    $metaPath = rtrim($DOWNLOADS_DIR,'/') . '/' . $token . '.json';
    if (!is_file($metaPath)) return false;
    $meta = json_decode(@file_get_contents($metaPath), true);
    if (is_array($meta) && !empty($meta['file']) && file_exists($meta['file'])) {
        @unlink($meta['file']);
    }
    @unlink($metaPath);
    return true;
}

# ------------------ Cleanup expired / used tokens ------------------
function cleanup_tokens() {
    global $DOWNLOADS_DIR, $SENDFILE_DELAY_SEC;
    $files = glob(rtrim($DOWNLOADS_DIR,'/') . '/*.json');
    if (!$files) return;
    $now = time();
    foreach ($files as $f) {
        $meta = @json_decode(@file_get_contents($f), true);
        if (!is_array($meta)) { @unlink($f); continue; }
        $token = basename($f, '.json');
        // remove expired tokens (not used) where expires_at < now
        if (empty($meta['used']) && !empty($meta['expires_at']) && $meta['expires_at'] < $now) {
            @unlink($f);
            if (!empty($meta['file']) && file_exists($meta['file'])) @unlink($meta['file']);
            continue;
        }
        // if used and used_at older than $SENDFILE_DELAY_SEC, delete both meta and file
        if (!empty($meta['used']) && !empty($meta['used_at']) && ($meta['used_at'] + $SENDFILE_DELAY_SEC) < $now) {
            if (!empty($meta['file']) && file_exists($meta['file'])) @unlink($meta['file']);
            @unlink($f);
            continue;
        }
    }
}

# run cleanup at beginning of each api.php invocation
cleanup_tokens();

# ------------------ QUEUE helpers (semplificati) ------------------
function enqueue_job_file($job, $queueDir) {
    $id = bin2hex(random_bytes(12));
    $path = rtrim($queueDir,'/') . '/' . $id . '.job';
    @file_put_contents($path . '.tmp', json_encode($job, JSON_PRETTY_PRINT), LOCK_EX);
    rename($path . '.tmp', $path);
    return $id;
}
function pick_and_lock_job($queueDir, $lockPath) {
    $lockFd = @fopen($lockPath,'c+');
    if (!$lockFd) return null;
    if (!flock($lockFd, LOCK_EX|LOCK_NB)) { fclose($lockFd); return null; }
    $files = glob(rtrim($queueDir,'/') . '/*.job');
    if (empty($files)) { flock($lockFd, LOCK_UN); fclose($lockFd); return null; }
    usort($files, fn($a,$b)=>filemtime($a)-filemtime($b));
    $processing = $files[0] . '.processing';
    rename($files[0], $processing);
    return ['lockFd'=>$lockFd,'processing'=>$processing];
}
function release_lock($lockFd) { flock($lockFd, LOCK_UN); fclose($lockFd); }

# PROCESS JOB (stesso comportamento di prima)
function process_job_file($jobPath) {
    global $JOBS_DIR, $RESULTS_DIR, $SERVERS, $BASE_WALLET_DIR;
    $job = json_decode(@file_get_contents($jobPath), true);
    $jobId = basename($jobPath);
    $jobLog = rtrim($JOBS_DIR,'/') . '/' . $jobId . '.log';
    log_line($jobLog, "START job=".json_encode($job));
    $serverKey = $job['serverKey'] ?? null;
    $serverHost = isset($SERVERS[$serverKey]) ? $SERVERS[$serverKey] : $SERVERS[array_rand($SERVERS)];
    $action = $job['action'] ?? 'create';
    $result = ['jobId'=>$jobId,'action'=>$action,'server'=>$serverHost];
    try {
        if ($action === 'create') {
            $walletFile = $job['fullPath'] ?? null;
            $walletPassword = $job['password'] ?? null;
            $result['create'] = call_wallet_api($serverHost, '/wallet/create', 'POST', ['filename'=>$walletFile,'password'=>$walletPassword]);
            $result['close']  = call_wallet_api($serverHost, '/wallet', 'DELETE', []);
            $result['success'] = in_array($result['close']['http_code'] ?? 0, [200,204]);
            // In async mode: keep file and create token meta for later download if requested
        } elseif ($action === 'address_create') {
            $walletFile = $job['fullPath'] ?? null;
            $walletPassword = $job['password'] ?? null;
            $result['open'] = call_wallet_api($serverHost, '/wallet/open', 'POST', ['filename'=>$walletFile,'password'=>$walletPassword]);
            $payload = !empty($job['label']) ? ['label'=>$job['label']] : null;
            $result['address'] = call_wallet_api($serverHost, '/addresses/create', 'POST', $payload);
            $result['close'] = call_wallet_api($serverHost, '/wallet', 'DELETE', []);
            $result['success'] = ($result['address']['http_code'] ?? 0) == 200;
        } elseif ($action === 'custom') {
            $result['custom'] = call_wallet_api($serverHost, $job['endpoint'] ?? '/', $job['method'] ?? 'GET', $job['payload'] ?? null);
            $result['success'] = in_array($result['custom']['http_code'] ?? 0, [200,201,204]);
        } else {
            throw new Exception("Azione non implementata: $action");
        }
    } catch (Exception $e) {
        log_line($jobLog, "EXCEPTION: ".$e->getMessage());
        $result['error'] = $e->getMessage();
        $result['success'] = false;
    }
    @file_put_contents(rtrim($RESULTS_DIR,'/') . '/' . $jobId . '.result.json', json_encode($result, JSON_PRETTY_PRINT));
    @unlink($jobPath);
    return $result;
}

# ------------------ MAIN ------------------
$inputRaw = @file_get_contents('php://input');
$input = json_decode($inputRaw, true) ?: [];

$action   = $input['action'] ?? 'create';
$filename = isset($input['filename']) ? safe_basename($input['filename']) : ('wallet_' . time() . '.wallet');
$pwd      = $input['password'] ?? null;
$server   = $input['server'] ?? null;
$async    = !empty($input['async']);
$user     = $input['user'] ?? 'anonymous';

# build path for create
$jobPathInfo = null;
if ($action === 'create') {
    $jobPathInfo = build_wallet_path_simple($BASE_WALLET_DIR, $filename);
    $fullPath = $jobPathInfo['fullpath'];
} else {
    $fullPath = null;
}

# choose server
$serverKey = $server ?? null;
$serverHost = isset($SERVERS[$serverKey]) ? $SERVERS[$serverKey] : $SERVERS[array_rand($SERVERS)];

# Sync create -> create wallet and return token+url (no immediate deletion)
if ($action === 'create' && !$async) {
    if (empty($pwd)) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Password wallet richiesta (campo "password")'], JSON_PRETTY_PRINT);
        exit;
    }
    // 1) create on wallet-api
    $createResp = call_wallet_api($serverHost, '/wallet/create', 'POST', ['filename'=>$fullPath, 'password'=>$pwd]);
    // 2) close
    $closeResp = call_wallet_api($serverHost, '/wallet', 'DELETE', []);

    // if file available locally -> generate token and return download URL
    if (file_exists($fullPath) && is_readable($fullPath)) {
        $ttl = isset($input['token_ttl']) ? intval($input['token_ttl']) : $GLOBALS['TOKEN_TTL'];
        $token = generate_download_token($fullPath, $ttl);

        // build base URL
        if (!empty($GLOBALS['DOWNLOAD_BASE_URL'])) {
            $base = rtrim($GLOBALS['DOWNLOAD_BASE_URL'], '/');
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = $scheme . '://' . $host;
        }
        $downloadUrl = $base . '/download.php?token=' . urlencode($token);

        echo json_encode([
            'status' => 'ok',
            'message' => 'Wallet creato. Scarica con il link monouso sotto (scade in ' . $ttl . 's).',
            'create' => $createResp,
            'close' => $closeResp,
            'download_url' => $downloadUrl,
            'token' => $token,
            'filename' => basename($fullPath)
        ], JSON_PRETTY_PRINT);
        exit;
    } else {
        http_response_code(500);
        echo json_encode([
            'status'=>'error',
            'message'=>'Wallet creato su wallet-api ma file non trovato su filesystem locale: ' . $fullPath,
            'create'=>$createResp,
            'close'=>$closeResp
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

# Else: enqueue job or handle other actions
$job = [
    'action' => $action,
    'filename' => $filename,
    'password' => $pwd,
    'serverKey' => $server,
    'relative' => $jobPathInfo['relative'] ?? null,
    'fullPath' => $fullPath,
    'endpoint' => $input['endpoint'] ?? null,
    'method' => $input['method'] ?? null,
    'payload' => $input['payload'] ?? null,
    'label' => $input['label'] ?? null,
    'user' => $user,
    'created_at' => time()
];

$lockPath = $QUEUE_DIR . '/lock_' . ($server ?: 'default') . '.lock';
$jobId = enqueue_job_file($job, $QUEUE_DIR);

if ($async) {
    echo json_encode(['status'=>'queued','jobId'=>$jobId,'relative'=>$job['relative'] ?? null,'fullPath'=>$job['fullPath'] ?? null], JSON_PRETTY_PRINT);
    exit;
}

$pick = pick_and_lock_job($QUEUE_DIR, $lockPath);
if ($pick) {
    $res = process_job_file($pick['processing']);
    release_lock($pick['lockFd']);
    echo json_encode(['status'=>'processed','jobId'=>$jobId,'result'=>$res], JSON_PRETTY_PRINT);
} else {
    echo json_encode(['status'=>'waiting','jobId'=>$jobId], JSON_PRETTY_PRINT);
}