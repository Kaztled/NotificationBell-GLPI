<?php

/**
 * Isolated contract test for routing, shared state, and ticket permissions.
 * GLPI test doubles live only in this file; production never loads them.
 */

// Prevent a web request from executing the standalone test doubles in a plugin install.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const READ = 1;
$testLanguage = 'en';
function __($message, $domain = 'glpi'): string
{
    global $testLanguage;
    $spanish = [
        'Ticket #%d assigned to group' => 'Ticket #%d asignado al grupo',
        'Solution rejected · Ticket #%d' => 'Solución rechazada · Ticket #%d',
        'Notifications' => 'Notificaciones',
    ];
    return $testLanguage === 'es' ? ($spanish[$message] ?? $message) : $message;
}
class TestAccessDeniedException extends RuntimeException {}
class_alias(TestAccessDeniedException::class, 'Glpi\\Exception\\Http\\AccessDeniedHttpException');
// Minimal GLPI objects expose only the fields and methods exercised by the plugin.
class CommonITILActor
{
    public const REQUESTER = 1;
    public const ASSIGN = 2;
}
class CommonDBTM
{
    public array $fields = [];
    public array $updates = [];
    public array $oldvalues = [];
    public function getID(): int { return (int) $this->fields['id']; }
}
class Ticket extends CommonDBTM
{
    public const INCOMING = 1;
    public const ASSIGNED = 2;
    public const PLANNED = 3;
    public const WAITING = 4;
    public const SOLVED = 5;
    public static array $records = [];
    public static array $readableIds = [42];
    public function getFromDB(int $id): bool
    {
        if (!isset(self::$records[$id])) return false;
        $this->fields = self::$records[$id];
        return true;
    }
    public function can(int $id, int $right): bool { return in_array($id, self::$readableIds, true) && $right === READ; }
}
class Group_Ticket extends CommonDBTM {}
class Group_User
{
    public static array $memberships = [
        10 => [['id' => 9, 'name' => 'Monitoreo'], ['id' => 11, 'name' => 'Seguridad']],
        20 => [['id' => 9, 'name' => 'Monitoreo']],
        30 => [['id' => 11, 'name' => 'Seguridad']],
    ];
    public static function getUserGroups(int $userId): array { return self::$memberships[$userId] ?? []; }
}
class ITILFollowup extends CommonDBTM {}
class ITILSolution extends CommonDBTM {}
class CommonITILValidation { public const REFUSED = 4; }
class CronTask
{
    public int $volume = 0;
    public function addVolume(int $count): void { $this->volume += $count; }
    public function log(string $message): void {}
}

// In-memory records retain the group and ticket columns needed for permission checks.
class FakeDB
{
    public int $requestCalls = 0;
    public array $notifications = [];
    public array $ticketGroups = [
        ['tickets_id' => 42, 'groups_id' => 9, 'type' => CommonITILActor::ASSIGN],
        ['tickets_id' => 42, 'groups_id' => 11, 'type' => CommonITILActor::ASSIGN],
        ['tickets_id' => 42, 'groups_id' => 12, 'type' => CommonITILActor::REQUESTER],
    ];
    private int $nextId = 1;
    public function tableExists(string $table): bool { return true; }
    public function request(array $query): array
    {
        $this->requestCalls++;
        $source = match ($query['FROM']) {
            'glpi_groups_tickets' => $this->ticketGroups,
            'glpi_tickets' => array_values(Ticket::$records),
            default => $this->notifications,
        };
        $rows = array_values(array_filter($source, static function (array $row) use ($query): bool {
            foreach ($query['WHERE'] ?? [] as $key => $value) {
                if (is_array($value) && $value[0] === '<=') {
                    if (($row[$key] ?? null) > $value[1]) return false;
                } elseif (is_array($value) ? !in_array($row[$key] ?? null, $value, true) : ($row[$key] ?? null) !== $value) return false;
            }
            return true;
        }));
        if (isset($query['GROUPBY'])) {
            $counts = [];
            foreach ($rows as $row) {
                $ticketId = $row['tickets_id'];
                $counts[$ticketId] = ($counts[$ticketId] ?? 0) + 1;
            }
            return array_map(
                static fn(int $ticketId, int $count): array => ['tickets_id' => $ticketId, 'unread' => $count],
                array_keys($counts),
                array_values($counts)
            );
        }
        usort($rows, static fn(array $a, array $b): int => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
        return isset($query['LIMIT']) ? array_slice($rows, 0, (int) $query['LIMIT']) : $rows;
    }
    public function insert(string $table, array $row): void
    {
        $row['id'] = $this->nextId++;
        $row['date_read'] = null;
        $this->notifications[] = $row;
    }
    public function update(string $table, array $values, array $where): void
    {
        foreach ($this->notifications as &$row) {
            foreach ($where as $key => $value) {
                if (is_array($value) ? !in_array($row[$key] ?? null, $value, true) : ($row[$key] ?? null) !== $value) continue 2;
            }
            $row = array_replace($row, $values);
        }
    }
    public function delete(string $table, array $where): void
    {
        $this->notifications = array_values(array_filter($this->notifications, static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) {
                if (is_array($value) ? !in_array($row[$key] ?? null, $value, true) : ($row[$key] ?? null) !== $value) return true;
            }
            return false;
        }));
    }
}

/** Fail immediately at the first broken contract. */
function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

