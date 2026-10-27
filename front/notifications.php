<?php

use Symfony\Component\HttpFoundation\JsonResponse;

// This script must be dispatched through GLPI's authenticated front controller.
if (!defined('GLPI_ROOT')) {
    http_response_code(404);
    exit;
}

// Authentication precedes all reads and writes; the service also checks group and ticket access.
Session::checkLoginUser();

$headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
if (!Plugin::isPluginActive('notificationbell')) {
    return new JsonResponse(['error' => 'Plugin unavailable'], 404, $headers);
}

require_once __DIR__ . '/../inc/notification.class.php';

$userId = (int) Session::getLoginUserID();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    return new JsonResponse(['error' => 'Method not allowed'], 405, $headers + ['Allow' => 'GET, POST']);
}

// Never coerce arrays, negative IDs, or mixed query/body parameters into an action.
$input = $method === 'POST' ? $_POST : $_GET;
$action = $input['action'] ?? ($method === 'GET' ? 'list' : '');
$view = $input['view'] ?? 'history';
$groupId = $input['group_id'] ?? 0;
$id = $input['id'] ?? 0;
$scope = $input['scope'] ?? 'group';
foreach ([$groupId, $id] as $value) {
    if ((!is_string($value) && !is_int($value))
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
    ) {
        return new JsonResponse(['error' => 'Invalid identifier'], 400, $headers);
    }
}
$groupId = (int) $groupId;
$id = (int) $id;
if (!is_string($action)
    || !in_array($scope, ['group', 'user'], true)
    || !in_array($view, ['preview', 'history'], true)
    || ($method === 'GET' && $action !== 'list')
    || ($method === 'POST' && !in_array($action, ['mark_read', 'mark_all_read', 'clear'], true))
    || ($action === 'mark_read' && $scope === 'group' && $groupId <= 0)
    || ($action === 'mark_read' && $id <= 0)
) {
    return new JsonResponse(['error' => 'Invalid action'], 400, $headers);
}

// GLPI 11 uses a token; GLPI 12 checks browser request origin in its controller.
$csrfMode = version_compare(GLPI_VERSION, '12.0.0', '<') ? 'token' : 'origin';
if ($method === 'POST') {
    if ($csrfMode === 'token') {
        $token = $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? ($_POST['_glpi_csrf_token'] ?? '');
        if (!is_string($token)) {
            return new JsonResponse(['error' => 'Invalid token'], 400, $headers);
        }
        Session::checkCSRF(['_glpi_csrf_token' => $token], true);
    }
}

// Reads must not refresh PHP session storage (including requests from older cached clients).
// Keep GLPI's normal authentication/rights refresh above, then release the session lock.
// Explicit POST actions may persist the session changes made by GLPI's security checks.
if (session_status() === PHP_SESSION_ACTIVE) {
    if ($method === 'GET') {
        session_abort();
    } else {
        session_write_close();
    }
}

if ($method === 'POST') {
    switch ($action) {
        case 'mark_read':
            PluginNotificationbellNotification::markRead($userId, $groupId, $id, $scope);
            break;
        case 'mark_all_read':
            PluginNotificationbellNotification::markAllRead($userId, $groupId);
            break;
        case 'clear':
            PluginNotificationbellNotification::clear($userId, $groupId);
            break;
    }
}

$response = new JsonResponse(null, 200, $headers);
$response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$feed = PluginNotificationbellNotification::feed($userId, $groupId ?: null, $view === 'preview');
$feed['csrf_mode'] = $csrfMode;
$response->setData($feed);
return $response;
