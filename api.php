<?php
// api.php: inoltra la chiamata verso il wallet-api locale con super debug
header('Content-Type: application/json');

// Massimo reporting errori
error_reporting(E_ALL);
ini_set('display_errors', 1);

// File di log
$logFile = '/tmp/api_debug.log';
$verboseFile = '/tmp/curl_verbose.log';

// Crea file se non esistono e setta permessi
if (!file_exists($logFile)) touch($logFile);
if (!file_exists($verboseFile)) touch($verboseFile);
chmod($logFile, 0664);
chmod($verboseFile, 0664);

// Log iniziale
file_put_contents($logFile, "===== Nuova chiamata ===== " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

// Cattura errori PHP
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($logFile) {
    file_put_contents($logFile, "PHP Error [$errno] $errstr in $errfile:$errline\n", FILE_APPEND);
});
register_shutdown_function(function() use ($logFile) {
    $error = error_get_last();
    if ($error) {
        file_put_contents($logFile, "Fatal error: " . print_r($error, true) . "\n", FILE_APPEND);
    }
});

// Legge input
$inputRaw = file_get_contents('php://input');
file_put_contents($logFile, "Input raw: $inputRaw\n", FILE_APPEND);
$input = json_decode($inputRaw, true);
file_put_contents($logFile, "Input decodificato: " . print_r($input, true) . "\n", FILE_APPEND);

// Parametri wallet
$filename = $input['filename'] ?? 'test.wallet';
$password = $input['password'] ?? 'desy2011';

// Prepara CURL verso wallet-api
$url = 'http://127.0.0.1:17082/wallet/create';
$payload = json_encode(['filename'=>$filename, 'password'=>$password]);
file_put_contents($logFile, "Payload CURL: $payload\n", FILE_APPEND);

$ch = curl_init($url);
$verboseHandle = fopen($verboseFile, 'w+');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-KEY: desy2011'
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_VERBOSE => true,
    CURLOPT_STDERR => $verboseHandle
]);

// Esecuzione CURL
$response = curl_exec($ch);
$curlError = curl_error($ch);
$curlInfo = curl_getinfo($ch);
fclose($verboseHandle);

file_put_contents($logFile, "CURL error: $curlError\n", FILE_APPEND);
file_put_contents($logFile, "CURL info: " . print_r($curlInfo, true) . "\n", FILE_APPEND);
file_put_contents($logFile, "CURL response: $response\n", FILE_APPEND);

$httpCode = $curlInfo['http_code'] ?? 0;
curl_close($ch);

// Prepariamo sempre un JSON di ritorno
$output = [
    'status' => ($curlError || $httpCode !== 200) ? 'error' : 'success',
    'http_code' => $httpCode,
    'curl_error' => $curlError ?: null,
    'wallet_response_raw' => $response,
    'wallet_file' => $filename,
    'logfile' => $logFile,
    'verbose_file' => $verboseFile
];

if ($curlError || $httpCode !== 200) {
    http_response_code(500);
}

// Ritorna sempre JSON valido
echo json_encode($output, JSON_PRETTY_PRINT);