<?php
require_once __DIR__ . '/core/security.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
  exit;
}

$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
if (strpos($contentType, 'application/json') !== 0) {
  http_response_code(415);
  echo json_encode(['ok' => false, 'message' => 'Unsupported media type']);
  exit;
}

$now = time();
$window = 60;
$maxRequests = 15;
if (!isset($_SESSION['_geo_error_logs']) || !is_array($_SESSION['_geo_error_logs'])) {
  $_SESSION['_geo_error_logs'] = [];
}
$_SESSION['_geo_error_logs'] = array_values(array_filter(
  $_SESSION['_geo_error_logs'],
  static fn($ts) => is_int($ts) && ($now - $ts) < $window
));

if (count($_SESSION['_geo_error_logs']) >= $maxRequests) {
  http_response_code(429);
  echo json_encode(['ok' => false, 'message' => 'Too many requests']);
  exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid payload']);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid JSON']);
  exit;
}

$errorCode = isset($data['errorCode']) ? substr((string)$data['errorCode'], 0, 32) : 'unknown';
$errorMessage = isset($data['errorMessage']) ? substr(trim((string)$data['errorMessage']), 0, 255) : 'unknown';
$errorKey = isset($data['errorKey']) ? substr(trim((string)$data['errorKey']), 0, 64) : 'unknown';
$path = substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 255);
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

error_log(sprintf(
  '[geo_error] ts=%s path=%s code=%s key=%s msg=%s ua=%s',
  date('c', $now),
  $path,
  $errorCode,
  $errorKey,
  $errorMessage,
  $userAgent
));

$_SESSION['_geo_error_logs'][] = $now;

echo json_encode(['ok' => true]);
