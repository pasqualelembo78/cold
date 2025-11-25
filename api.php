<?php
// api.php - Proxy robusto, multi-server, multi-utente e queue per wallet-api (Mevacoin)
// Versione: legge API_KEY da /opt/mevacoin_config/.env (solo API key)

date_default_timezone_set('Europe/Rome');

$LOG_BASE     = '/tmp/mevacoin_api';
$QUEUE_DIR    = $LOG_BASE . '/queue';
$JOBS_DIR     = $LOG_BASE . '/jobs';
$RESULTS_DIR  = $LOG_BASE . '/results';
$VERBOSE_FILE = $LOG_BASE . '/curl_verbose.log';
$API_DEBUG    = $LOG_BASE . '/api_debug.log';

$SERVERS = [
    '82' => 'http://82.165.218.56:8070',
    '87' => 'http://87.106.40.193:8070'
];

$BASE_WALLET_DIR = '/opt/mevacoin_data/wallets';
$LOCK_TIMEOUT = 60;

// Path del file .env che contiene solo la API_KEY (fuori dalla webroot)
$ENV_PATH = '/opt/mevacoin_config/.env';

// -----------------------------------------------------------------------------
// prepare dirs, headers, logging
// -----------------------------------------------------------------------------
@mkdir($LOG_BASE, 0775, true);
@mkdir($QUEUE_DIR, 0775, true);
@mkdir($JOBS_DIR, 0775, true);
@mkdir($RESULTS_DIR, 0775, true);
@mkdir($BASE_WALLET_DIR, 0775, true);

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

function log_line($path, $message) {
    $line = '['.date('Y-m-d H:i:s').'] ' . $message . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

// -----------------------------------------------------------------------------
// load only API_KEY from .env (simple parser, ignores comments/empty lines)
// -----------------------------------------------------------------------------
function load_api_key_from_env($envPath) {
    global $API_DEBUG;
    if (!file_exists($envPath)) {
        log_line($API_DEBUG, "load_api_key_from_env: .env not found at $envPath");
        return null;
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // remove optional quotes
        if ((substr($value,0,1) === '"' && substr($value,-1) === '"')
            || (substr($value,0,1) === "'" && substr($value,-1) === "'")) {
            $value = substr($value,1,-1);
        }
        if ($name === 'API_KEY') {
            // set in environment for consistency
            putenv("API_KEY=$value");
            $_ENV['API_KEY'] = $value;
            $_SERVER['API_KEY'] = $value;
            log_line($API_DEBUG, "load_api_key_from_env: API_KEY loaded (len=" . strlen($value) . ")");
            return $value;
        }
    }
    log_line($API_DEBUG, "load_api_key_from_env: API_KEY not found in $envPath");
    return null;
}

// carico la API key (non la stampo mai)
$FIXED_API_KEY = load_api_key_from_env($ENV_PATH);

// -----------------------------------------------------------------------------
// util
// -----------------------------------------------------------------------------
function safe_basename($name) {
    $n = basename($name);
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $n);
}

function build_wallet_path_simple($baseDir, $filename) {
    $safe = safe_basename($filename);
    $dir = rtrim($baseDir, '/');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $full = $dir . '/' . $safe;

    if (file_exists($full)) {
        $dotPos = strrpos($safe, '.');
        if ($dotPos !== false) {
            $name = substr($safe, 0, $dotPos);
            $ext  = substr($safe, $dotPos);
        } else {
            $name = $safe;
            $ext  = '';
        }
        $uniq = bin2hex(random_bytes(4));
        $safe2 = $name . '_' . $uniq . $ext;
        $full = $dir . '/' . $safe2;
        $safe = $safe2;
    }

    return ['dir'=>$dir,'fullpath'=>$full,'relative'=>basename($full)];
}

// -----------------------------------------------------------------------------
// call wallet-api (usa la API key caricata; se mancante logga e invia richiesta senza header)
// -----------------------------------------------------------------------------
function call_wallet_api($host, $endpoint, $method='GET', $payload=null) {
    global $VERBOSE_FILE, $API_DEBUG, $FIXED_API_KEY;

    $endpoint = (substr($endpoint,0,1)==='/') ? $endpoint : '/'.$endpoint;
    $url = rtrim($host,'/') . $endpoint;

    $ch = curl_init($url);
    $fh = @fopen($VERBOSE_FILE,'a+');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,10);
    curl_setopt($ch, CURLOPT_TIMEOUT,60);
    curl_setopt($ch, CURLOPT_VERBOSE,true);
    if ($fh) curl_setopt($ch, CURLOPT_STDERR,$fh);

    $headers = ["Accept: application/json"];
    if (!empty($FIXED_API_KEY)) {
        $headers[] = "X-API-KEY: $FIXED_API_KEY";
    } else {
        log_line($API_DEBUG, "call_wallet_api: WARNING no API key loaded for $url");
    }

    if ($payload !== null) $headers[] = "Content-Type: application/json";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $m = strtoupper($method);
    if ($m === 'POST') curl_setopt($ch, CURLOPT_POST,true);
    elseif (in_array($m,['PUT','DELETE'])) curl_setopt($ch, CURLOPT_CUSTOMREQUEST,$m);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);

    if ($fh) fclose($fh);
    curl_close($ch);

    log_line($API_DEBUG, "call_wallet_api: $m $url -> http=" . ($info['http_code'] ?? 0) . " err=" . ($err?:'OK'));

    return [
        'url'=>$url,
        'method'=>$m,
        'payload'=>$payload,
        'response'=>$resp,
        'error'=>$err,
        'http_code'=>$info['http_code'] ?? 0
    ];
}

// -----------------------------------------------------------------------------
// queue utils
// -----------------------------------------------------------------------------
function enqueue_job_file($job, $queueDir) {
    $id = bin2hex(random_bytes(12));
    $path = $queueDir.'/'.$id.'.job';
    @file_put_contents($path.'.tmp', json_encode($job,JSON_PRETTY_PRINT), LOCK_EX);
    rename($path.'.tmp', $path);
    return $id;
}

function pick_and_lock_job($queueDir, $lockPath) {
    $lockFd = @fopen($lockPath,'c+');
    if (!$lockFd) return null;
    if (!flock($lockFd, LOCK_EX|LOCK_NB)) { fclose($lockFd); return null; }

    $files = glob($queueDir.'/*.job');
    if (empty($files)) { flock($lockFd,LOCK_UN); fclose($lockFd); return null; }

    usort($files, fn($a,$b)=>filemtime($a)-filemtime($b));

    $processing = $files[0].'.processing';
    rename($files[0], $processing);

    return ['lockFd'=>$lockFd,'processing'=>$processing];
}

function release_lock($lockFd) {
    flock($lockFd, LOCK_UN);
    fclose($lockFd);
}

// -----------------------------------------------------------------------------
// process job
// -----------------------------------------------------------------------------
function process_job_file($jobPath) {
    global $JOBS_DIR, $RESULTS_DIR, $SERVERS, $BASE_WALLET_DIR;

    $job = json_decode(@file_get_contents($jobPath), true);
    $jobId = basename($jobPath);
    $jobLog = $JOBS_DIR.'/'.$jobId.'.log';

    log_line($jobLog, "START job=".json_encode($job));

    $serverKey = $job['serverKey'] ?? null;
    $serverHost = isset($SERVERS[$serverKey]) ? $SERVERS[$serverKey] : $SERVERS[array_rand($SERVERS)];

    $action = $job['action'] ?? 'create';
    $result = ['jobId'=>$jobId,'action'=>$action,'server'=>$serverHost];

    try {
        if ($action === 'create') {
            $walletFile = $job['fullPath'] ?? null;
            $walletPassword = $job['password'] ?? null;

            $result['create'] = call_wallet_api(
                $serverHost,
                '/wallet/create',
                'POST',
                ['filename'=>$walletFile,'password'=>$walletPassword]
            );

            $result['close'] = call_wallet_api($serverHost, '/wallet', 'DELETE', []);
            $result['success'] = in_array($result['close']['http_code'],[200,204]);

        } elseif ($action === 'address_create') {
            $walletFile = $job['fullPath'] ?? null;
            $walletPassword = $job['password'] ?? null;

            $result['open'] = call_wallet_api(
                $serverHost,
                '/wallet/open',
                'POST',
                ['filename'=>$walletFile,'password'=>$walletPassword]
            );

            $payload = !empty($job['label']) ? ['label'=>$job['label']] : null;
            $result['address'] = call_wallet_api(
                $serverHost,
                '/addresses/create',
                'POST',
                $payload
            );

            $result['close'] = call_wallet_api($serverHost, '/wallet','DELETE',[]);
            $result['success'] = ($result['address']['http_code'] ?? 0) == 200;

        } elseif ($action === 'custom') {
            $result['custom'] = call_wallet_api(
                $serverHost,
                $job['endpoint'] ?? '/',
                $job['method'] ?? 'GET',
                $job['payload'] ?? null
            );
            $result['success'] = in_array($result['custom']['http_code'] ?? 0,[200,201,204]);
        } else {
            throw new Exception("Azione non implementata: $action");
        }
    } catch (Exception $e) {
        log_line($jobLog, "EXCEPTION: ".$e->getMessage());
        $result['error'] = $e->getMessage();
        $result['success'] = false;
    }

    @file_put_contents($RESULTS_DIR.'/'.$jobId.'.result.json', json_encode($result,JSON_PRETTY_PRINT));
    @unlink($jobPath);

    return $result;
}

// -----------------------------------------------------------------------------
// main
// -----------------------------------------------------------------------------
$input = json_decode(@file_get_contents('php://input'), true) ?: [];

$action   = $input['action'] ?? 'create';
$filename = safe_basename($input['filename'] ?? ('wallet_'.time().'.wallet'));
$pwd      = $input['password'] ?? null;
$server   = $input['server'] ?? null;
$async    = !empty($input['async']);

$jobPathInfo = null;
if ($action==='create') {
    $jobPathInfo = build_wallet_path_simple($BASE_WALLET_DIR, $filename);
}

$job = [
    'action'=>$action,
    'filename'=>$filename,
    'password'=>$pwd,
    'serverKey'=>$server,
    'relative'=>$jobPathInfo['relative'] ?? null,
    'fullPath'=>$jobPathInfo['fullpath'] ?? null,
    'endpoint'=>$input['endpoint'] ?? null,
    'method'=>$input['method'] ?? null,
    'payload'=>$input['payload'] ?? null,
    'label'=>$input['label'] ?? null
];

$lockPath = $QUEUE_DIR.'/lock_'.($server?:'default').'.lock';
$jobId = enqueue_job_file($job, $QUEUE_DIR);

if ($async) {
    echo json_encode(['status'=>'queued','jobId'=>$jobId], JSON_PRETTY_PRINT);
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