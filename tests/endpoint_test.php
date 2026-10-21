<?php

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/inc/notification.class.php';

// Only the GLPI/Symfony dispatch boundary is doubled; use the real endpoint and service.
class EndpointJsonResponse
{
    public mixed $data;
    public function __construct(mixed $data = null, public int $status = 200, public array $headers = []) { $this->data = $data; }
    public function setEncodingOptions(int $flags): void {}
    public function setData(mixed $data): void { $this->data = $data; }
}
class_alias(EndpointJsonResponse::class, 'Symfony\\Component\\HttpFoundation\\JsonResponse');
class Session
{
    public static bool $authenticated = true;
    public static function checkLoginUser(): void
    {
        if (!self::$authenticated || ($_SESSION['valid_id'] ?? null) !== session_id()) {
            throw new RuntimeException('Expired session');
        }
    }
    public static function getLoginUserID(): int { return (int) $_SESSION['glpiID']; }
    public static function checkCSRF(array $data, bool $preserve): void
    {
        expect($preserve, 'AJAX CSRF checks must preserve the existing token.');
        if (!hash_equals($_SESSION['test_csrf'], $data['_glpi_csrf_token'])) {
            throw new RuntimeException('Invalid CSRF');
        }
    }
}
class Plugin
{
    public static bool $active = true;
    public static function isPluginActive(string $name): bool { return self::$active; }
}
define('GLPI_ROOT', dirname(__DIR__));
$CFG_GLPI = ['root_doc' => '/glpi'];
$DB = new FakeDB();

function callEndpoint(string $method = 'GET', array $get = [], array $post = [], mixed $token = null): EndpointJsonResponse
{
    global $DB, $CFG_GLPI;
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    // Simulate GLPI's SessionStart listener updating a variable before dispatch.
    $_SESSION['glpi_currenttime'] = 'changed by GLPI bootstrap';
    $_GET = $get;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_X_GLPI_CSRF_TOKEN']);
    if ($token !== null) $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] = $token;
    try {
        return require dirname(__DIR__) . '/front/notifications.php';
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    }
}
function denied(callable $call, string $message): void
{
    $denied = false;
    try { $call(); } catch (RuntimeException) { $denied = true; }
    expect($denied, $message);
}

// Use PHP's real file session handler to verify that GET neither saves data nor renews mtime.
$sessionDir = sys_get_temp_dir() . '/notificationbell-session-' . bin2hex(random_bytes(8));
mkdir($sessionDir);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.gc_probability', '0');
session_save_path($sessionDir);
session_id(bin2hex(random_bytes(16)));
$sessionFile = $sessionDir . '/sess_' . session_id();
try {
    session_start();
    $_SESSION = ['glpiID' => 20, 'valid_id' => session_id(), 'test_csrf' => 'valid-token', 'glpi_currenttime' => 'original activity'];
    session_write_close();
    touch($sessionFile, time() - 120);
    clearstatcache(true, $sessionFile);
    $beforeMtime = filemtime($sessionFile);

    foreach ([[9, 42], [9, 43], [11, 42]] as $index => [$groupId, $ticketId]) {
        $DB->insert(PluginNotificationbellNotification::TABLE, [
            'groups_id' => $groupId, 'tickets_id' => $ticketId, 'event_key' => 'endpoint-' . $index,
            'category' => 'other', 'title' => 'Test', 'body' => 'Private payload', 'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }
    $response = callEndpoint('GET', ['group_id' => '9']);
    expect($response->status === 200 && $response->data['unread'] === 1, 'GET must filter inaccessible tickets and their counts.');
    expect($response->headers['Cache-Control'] === 'private, no-store', 'Ticket data must not enter shared caches.');
    expect(session_status() === PHP_SESSION_NONE, 'GET must release the session lock.');
    expect($_SESSION['glpi_currenttime'] === 'changed by GLPI bootstrap', 'Aborting persistence must retain current in-memory security refreshes for this request.');
    clearstatcache(true, $sessionFile);
    expect(filemtime($sessionFile) === $beforeMtime, 'GET must not refresh the session timestamp.');
    session_start();
    expect($_SESSION['glpi_currenttime'] === 'original activity', 'GET must discard bootstrap session writes.');
    session_abort();

    $initial = $DB->notifications;
    foreach ([['group_id' => ['9']], ['group_id' => '-1'], ['group_id' => '9 OR 1=1'], ['group_id' => str_repeat('9', 40)], ['action' => ['list']], ['action' => 'clear'], ['view' => []]] as $get) {
        expect(callEndpoint('GET', $get)->status === 400, 'Malformed GET must return 400 without a mutation.');
    }
    expect(callEndpoint('DELETE')->status === 405, 'Only GET and POST are supported.');
    expect(callEndpoint('POST', ['action' => 'clear', 'group_id' => 9])->status === 400, 'POST must not inherit an action from GET.');
    expect(callEndpoint('POST', [], ['action' => 'mark_read', 'group_id' => 9, 'id' => []], 'valid-token')->status === 400, 'Array IDs must not produce PHP warnings.');
    expect(callEndpoint('POST', [], ['action' => 'clear', 'group_id' => 9], [])->status === 400, 'Array CSRF tokens must be rejected.');
    denied(fn() => callEndpoint('POST', [], ['action' => 'clear', 'group_id' => 9]), 'Missing CSRF must deny writes.');
    denied(fn() => callEndpoint('POST', [], ['action' => 'clear', 'group_id' => 9], 'wrong-token'), 'Invalid CSRF must deny writes.');
    denied(fn() => callEndpoint('GET', ['group_id' => 11, 'users_id' => 30]), 'A forged user ID must not grant another group.');
    foreach (['mark_read', 'mark_all_read', 'clear'] as $action) {
        denied(fn() => callEndpoint('POST', [], ['action' => $action, 'group_id' => 11, 'id' => 3, 'users_id' => 30], 'valid-token'), 'Every mutation must deny another group.');
    }
    denied(fn() => callEndpoint('POST', [], ['action' => 'mark_read', 'group_id' => 9, 'id' => 2], 'valid-token'), 'A member must not mark an inaccessible ticket.');
    expect($DB->notifications === $initial, 'Rejected requests must leave all notifications unchanged.');

    $memberships = Group_User::$memberships;
    Group_User::$memberships[20] = [];
    denied(fn() => callEndpoint('GET', ['group_id' => 9]), 'Revoked membership must take effect on the next read.');
    Group_User::$memberships = $memberships;
    Ticket::$readableIds = [];
    expect(callEndpoint('GET', ['group_id' => 9])->data['unread'] === 0, 'Revoked ticket rights must hide the unread count.');
    callEndpoint('POST', [], ['action' => 'clear', 'group_id' => 9], 'valid-token');
    expect($DB->notifications === $initial, 'Revoked ticket rights must prevent bulk mutations.');
    Ticket::$readableIds = [42];

    $reads = $DB->requestCalls;
    Session::$authenticated = false;
    denied(fn() => callEndpoint(), 'Expired sessions must not read a feed.');
    denied(fn() => callEndpoint('POST', [], ['action' => 'clear', 'group_id' => 9], 'valid-token'), 'Expired sessions must not write a feed.');
    expect($reads === $DB->requestCalls, 'Authentication must precede every database query.');
    Session::$authenticated = true;
    Plugin::$active = false;
    expect(callEndpoint()->status === 404, 'Inactive plugins must not expose a feed.');
    Plugin::$active = true;

    $response = callEndpoint('POST', [], ['action' => 'mark_all_read', 'group_id' => 9], 'valid-token');
    expect($response->status === 200 && $response->data['unread'] === 0, 'An authorized POST must still work.');
    expect($DB->notifications[0]['date_read'] !== null && $DB->notifications[1]['date_read'] === null, 'Bulk writes must preserve inaccessible ticket rows.');
    session_start();
    expect($_SESSION['glpi_currenttime'] === 'changed by GLPI bootstrap', 'Explicit POST may persist GLPI security updates.');
    session_abort();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    if (is_file($sessionFile)) unlink($sessionFile);
    rmdir($sessionDir);
}
echo "Notification Bell endpoint authorization, input and native session tests passed.\n";
