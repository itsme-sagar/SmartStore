<?php
/**
 * SmartStore - API Response & Request Helpers
 * Review 02 - Web Technologies Lab
 * Student: Sagar Dhakal | Roll No: 24CS275
 */

// Enable CORS and define JSON response headers
function setApiHeaders(): void {
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    // Handle OPTIONS preflight
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Sends a standardized JSON response and exits.
 *
 * @param bool $success Whether the operation was successful
 * @param mixed $data Payload to return
 * @param string $message Friendly user-facing message
 * @param int $statusCode HTTP status code (200, 201, 400, 404, 409, 500)
 */
function sendJsonResponse(bool $success, $data = null, string $message = '', int $statusCode = 200): void {
    setApiHeaders();
    http_response_code($statusCode);

    $response = [
        'success'   => $success,
        'message'   => $message,
        'data'      => $data,
        'timestamp' => date('c')
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Safely parses the incoming JSON or form-encoded request body.
 *
 * @return array
 */
function getRequestData(): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        return $_GET;
    }

    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

/**
 * Sanitizes a string or recursively sanitizes an array.
 *
 * @param mixed $input
 * @return mixed
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    if (is_string($input)) {
        return trim(strip_tags($input));
    }
    return $input;
}

/**
 * Logs private technical errors to a server-side log file.
 *
 * @param string $context
 * @param Throwable $e
 */
function logSystemError(string $context, Throwable $e): void {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/error_' . date('Y-m-d') . '.log';
    $message = sprintf(
        "[%s] [%s] %s in %s:%d\nStack Trace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        $context,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @file_put_contents($logFile, $message, FILE_APPEND);
}
