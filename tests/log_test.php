<?php

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/inc/notification.class.php';

if (($argv[1] ?? '') === 'worker') {
    ini_set('error_log', $argv[2]);
    $cacheFile = $argv[3];
    $GLPI_CACHE = new class ($cacheFile) {
        public function __construct(private string $file) {}
        public function get(string $key): bool { return is_file($this->file); }
        public function set(string $key, mixed $value, int $ttl): bool
        {
            expect($ttl === 900, 'Repeated save failures must have a 15-minute throttle.');
            return file_put_contents($this->file, 'throttled') !== false;
        }
    };
    $DB = new class extends FakeDB {
        public function insert(string $table, array $row): void
        {
            throw new RuntimeException('SECRET ticket content and SQL query');
        }
    };
    Ticket::$records[42] = ['id' => 42, 'name' => 'SECRET', 'status' => Ticket::ASSIGNED];
    $followup = new ITILFollowup();
    $followup->fields = ['id' => 8, 'itemtype' => Ticket::class, 'items_id' => 42, 'content' => 'SECRET', 'is_private' => 0];
    for ($attempt = 0; $attempt < 4; $attempt++) {
        PluginNotificationbellNotification::onFollowupAdded($followup);
    }
    exit;
}

$logFile = tempnam(sys_get_temp_dir(), 'bell-log-');
$cacheFile = $logFile . '-cache';
try {
    // Independent PHP processes prove the shared-cache limit extends across requests.
    for ($request = 0; $request < 2; $request++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $logFile, $cacheFile], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start log test worker.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process) === 0 && $output === '' && $errors === '', 'Notification failures must not break the originating GLPI event.');
    }
    $log = file_get_contents($logFile);
    expect(substr_count($log, 'notificationbell: notification save failed') === 1, '24 failed group inserts across requests must produce one plugin log entry.');
    expect(!str_contains($log, 'SECRET') && !str_contains($log, 'SQL query'), 'Logs must not contain raw exception messages or ticket data.');
} finally {
    unlink($logFile);
    if (is_file($cacheFile)) unlink($cacheFile);
}
echo "Notification Bell cross-request log throttle and sensitive data tests passed.\n";
