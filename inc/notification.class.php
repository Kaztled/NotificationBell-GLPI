<?php

/**
 * Stores ticket activity in group inboxes.
 *
 * Group membership controls inbox selection, while Ticket::can() independently
 * controls disclosure and mutation of each ticket's notifications.
 */
final class PluginNotificationbellNotification
{
    public const TABLE = 'glpi_plugin_notificationbell_group_notifications';
    private const MAX_FEED_ROWS = 1000;

    /** Text for the static JavaScript interface, translated by GLPI for this user. */
    private static function uiStrings(): array
    {
        return [
            'all' => __('All', 'notificationbell'),
            'resolved' => __('Resolved', 'notificationbell'),
            'attention' => __('Attention', 'notificationbell'),
            'responses' => __('Replies', 'notificationbell'),
            'notifications' => __('Notifications', 'notificationbell'),
            'recent' => __('Recent notifications', 'notificationbell'),
            'viewAll' => __('View all', 'notificationbell'),
            'sharedInbox' => __('Shared group inbox', 'notificationbell'),
            'sharedInboxGroup' => __('Shared inbox · %s', 'notificationbell'),
            'close' => __('Close', 'notificationbell'),
            'markAllRead' => __('Mark all as read', 'notificationbell'),
            'clear' => __('Clear', 'notificationbell'),
            'filter' => __('Filter notifications', 'notificationbell'),
            'groupSelector' => __('Notification group', 'notificationbell'),
            'unread' => __('Unread', 'notificationbell'),
            'newCount' => __('%d new', 'notificationbell'),
            'noGroup' => __('No group', 'notificationbell'),
            'empty' => __('No notifications yet.', 'notificationbell'),
            'emptyNoGroup' => __('You do not belong to a GLPI group.', 'notificationbell'),
            'emptyCategory' => __('No notifications in this category.', 'notificationbell'),
            'loadError' => __('Could not load notifications.', 'notificationbell'),
            'loadErrorStatus' => __('Could not load notifications (%d).', 'notificationbell'),
            'csrfError' => __('GLPI security token was not found.', 'notificationbell'),
        ];
    }

    /** Build titles from stable event keys so old notices follow the viewer's language too. */
    private static function localizedTitle(array $row): string
    {
        $key = (string) ($row['event_key'] ?? '');
        $ticketId = (int) $row['tickets_id'];
        $messages = [
            'ticket-assigned:' => 'Ticket #%d assigned to group',
            'ticket-resolved:' => 'Ticket #%d resolved',
            'ticket-reopened:' => 'Ticket #%d reopened',
            'ticket-response:' => 'New reply on ticket #%d',
            'solution-rejected:' => 'Solution rejected · Ticket #%d',
        ];
        foreach ($messages as $prefix => $message) {
            if (str_starts_with($key, $prefix)) {
                return sprintf(__($message, 'notificationbell'), $ticketId);
            }
        }
        if (str_starts_with($key, 'attention-reminder:')) {
            $parts = explode(':', $key);
            $waiting = ($parts[2] ?? '') === 'waiting'
                || (!in_array($parts[2] ?? '', ['active', 'waiting'], true)
                    && str_contains((string) ($row['title'] ?? ''), 'pendiente'));
            $message = $waiting
                ? 'Ticket #%d waiting for more than 24 hours'
                : 'Ticket #%d active for more than 24 hours';
            return sprintf(__($message, 'notificationbell'), $ticketId);
        }
        return (string) $row['title'];
    }

    /** Publish only assignment relations; requester and observer relations are different events. */
    public static function onGroupAssigned(Group_Ticket $assignment): void
    {
        if (!self::available() || (int) ($assignment->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }

        $ticketId = (int) ($assignment->fields['tickets_id'] ?? 0);
        $groupId = (int) ($assignment->fields['groups_id'] ?? 0);
        $ticket = new Ticket();
        if ($groupId <= 0 || !$ticket->getFromDB($ticketId)) {
            return;
        }

        self::publish(
            $ticket,
            Group_Ticket::class,
            (int) $assignment->getID(),
            'ticket-assigned:' . (int) $assignment->getID(),
            'other',
            sprintf('Ticket #%d assigned to group', $ticketId),
            self::plain((string) ($ticket->fields['name'] ?? ''), 180),
            [$groupId]
        );
    }

    /** Route status transitions to the groups assigned when GLPI runs the hook. */
    public static function onTicketUpdated(Ticket $ticket): void
    {
        if (!self::available() || !in_array('status', $ticket->updates, true)) {
            return;
        }

        $id = (int) $ticket->getID();
        $status = (int) ($ticket->fields['status'] ?? 0);
        $stamp = (string) ($ticket->fields['date_mod'] ?? date('Y-m-d H:i:s'));

        if ($status === Ticket::SOLVED) {
            self::publish(
                $ticket,
                Ticket::class,
                $id,
                "ticket-resolved:$id:$stamp",
                'resolved',
                sprintf('Ticket #%d resolved', $id),
                self::plain((string) ($ticket->fields['name'] ?? ''), 180),
                self::assignedGroups($ticket)
            );
        } elseif ($status === Ticket::INCOMING && (int) ($ticket->oldvalues['status'] ?? 0) === Ticket::SOLVED) {
            self::publish(
                $ticket,
                Ticket::class,
                $id,
                "ticket-reopened:$id:$stamp",
                'other',
                sprintf('Ticket #%d reopened', $id),
                self::plain((string) ($ticket->fields['name'] ?? ''), 180),
                self::assignedGroups($ticket)
            );
        }
    }

    /** Public replies reach involved groups; private notes must never enter a shared inbox. */
    public static function onFollowupAdded(ITILFollowup $followup): void
    {
        if (!self::available()
            || ($followup->fields['itemtype'] ?? '') !== Ticket::class
            || (int) ($followup->fields['is_private'] ?? 0) !== 0
        ) {
            return;
        }

        $ticket = new Ticket();
        $ticketId = (int) ($followup->fields['items_id'] ?? 0);
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }

        $id = (int) $followup->getID();
        self::publish(
            $ticket,
            ITILFollowup::class,
            $id,
            "ticket-response:$id",
            'responses',
            sprintf('New reply on ticket #%d', $ticketId),
            self::plain((string) ($followup->fields['content'] ?? ''), 180),
            self::involvedGroups($ticket)
        );
    }

    /** Revoke shared excerpts when an existing reply becomes private. */
    public static function onFollowupUpdated(ITILFollowup $followup): void
    {
        if (($followup->fields['itemtype'] ?? '') !== Ticket::class
            || !in_array('is_private', $followup->updates, true)
            || (int) ($followup->fields['is_private'] ?? 0) !== 1
        ) {
            return;
        }

        self::deleteFollowupNotices((int) $followup->getID());
    }

    /** Remove an excerpt when GLPI deletes its source reply. */
    public static function onFollowupDeleted(ITILFollowup $followup): void
    {
        if (($followup->fields['itemtype'] ?? '') === Ticket::class) {
            self::deleteFollowupNotices((int) $followup->getID());
        }
    }

    /** Delete by source identity across groups so privacy changes take effect everywhere. */
    private static function deleteFollowupNotices(int $followupId): void
    {
        global $DB;

        if ($followupId <= 0 || !self::available()) {
            return;
        }

        $DB->delete(self::TABLE, [
            'source_itemtype' => ITILFollowup::class,
            'source_items_id' => $followupId,
        ]);
    }

    /** Record rejected solutions only after their status actually changes. */
    public static function onSolutionUpdated(ITILSolution $solution): void
    {
        if (!self::available()
            || ($solution->fields['itemtype'] ?? '') !== Ticket::class
            || !in_array('status', $solution->updates, true)
            || (int) ($solution->fields['status'] ?? 0) !== CommonITILValidation::REFUSED
        ) {
            return;
        }

        $ticket = new Ticket();
        $ticketId = (int) ($solution->fields['items_id'] ?? 0);
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }

        $id = (int) $solution->getID();
        $stamp = (string) ($solution->fields['date_mod'] ?? date('Y-m-d H:i:s'));
        self::publish(
            $ticket,
            ITILSolution::class,
            $id,
            "solution-rejected:$id:$stamp",
            'other',
            sprintf('Solution rejected · Ticket #%d', $ticketId),
            self::plain((string) ($ticket->fields['name'] ?? ''), 180),
            self::assignedGroups($ticket)
        );
    }

    /** Describe the cron task to GLPI without exposing unrelated task names. */
    public static function cronInfo(string $name): array
    {
        return $name === 'AttentionReminders'
            ? ['description' => __('Reminders every 24 hours for pending or active tickets', 'notificationbell')]
            : [];
    }

    /**
     * Scan stale active tickets and rate-limit reminders per ticket and group.
     * The database query bounds the work to candidates; the last-notice lookup
     * prevents hourly cron runs from notifying the same group repeatedly.
     */
    public static function cronAttentionReminders(CronTask $task): int
    {
        global $DB;

        if (!self::available()) {
            return 0;
        }

        $now = time();
        $cutoff = date('Y-m-d H:i:s', $now - 86400);
        $sent = 0;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => [
                'is_deleted' => 0,
                'status' => [Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING],
                'date_mod' => ['<=', $cutoff],
            ],
        ]) as $row) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $row['id'])) {
                continue;
            }

            $ticketId = (int) $ticket->getID();
            $status = (int) $ticket->fields['status'];
            $state = $status === Ticket::WAITING ? 'waiting' : 'active';
            foreach (self::assignedGroups($ticket) as $groupId) {
                $last = $DB->request([
                    'SELECT' => ['date_creation'],
                    'FROM' => self::TABLE,
                    'WHERE' => [
                        'groups_id' => $groupId,
                        'tickets_id' => $ticketId,
                        'source_itemtype' => self::class,
                        'category' => 'attention',
                    ],
                    'ORDER' => ['date_creation DESC', 'id DESC'],
                    'LIMIT' => 1,
                ]);
                foreach ($last as $notice) {
                    if (strtotime((string) $notice['date_creation']) > $now - 86400) {
                        continue 2;
                    }
                }

                $sent += self::publish(
                    $ticket,
                    self::class,
                    $ticketId,
                    "attention-reminder:$ticketId:$state:" . bin2hex(random_bytes(8)),
                    'attention',
                    sprintf('Ticket #%d %s for more than 24 hours', $ticketId, $state),
                    self::plain((string) ($ticket->fields['name'] ?? ''), 180),
                    [$groupId]
                );
            }
        }

        if ($sent > 0) {
            $task->addVolume($sent);
            $task->log(sprintf(__('Attention reminders created: %d', 'notificationbell'), $sent));
        }
        return $sent > 0 ? 1 : 0;
    }

    /**
     * Return only rows for tickets the current user may read. The same filter
     * applies to the unread count, so the badge cannot disclose hidden activity.
     *
     * @return array{unread: int, group_id: int, groups: array<int, array{id: int, name: string}>, notifications: array<int, array<string, mixed>>, i18n: array<string, string>}
     */
    public static function feed(int $userId, ?int $groupId = null, bool $previewOnly = false): array
    {
        global $DB, $CFG_GLPI;

        $groups = self::groupsForUser($userId);
        $selectedGroupId = self::selectedGroupId($groups, $groupId);
        $i18n = self::uiStrings();
        if (!self::available()) {
            return ['unread' => 0, 'group_id' => $selectedGroupId, 'groups' => $groups, 'notifications' => [], 'i18n' => $i18n];
        }

        if ($selectedGroupId === 0) {
            return ['unread' => 0, 'group_id' => 0, 'groups' => [], 'notifications' => [], 'i18n' => $i18n];
        }

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['groups_id' => $selectedGroupId],
            'ORDER' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => self::MAX_FEED_ROWS,
        ]);

        $result = [];
        $access = [];
        foreach ($rows as $row) {
            $ticketId = (int) $row['tickets_id'];
            if (!array_key_exists($ticketId, $access)) {
                $ticket = new Ticket();
                $access[$ticketId] = $ticket->can($ticketId, READ);
            }
            if (!$access[$ticketId]) {
                continue;
            }

            $result[] = [
                'id'       => (int) $row['id'],
                'category' => (string) $row['category'],
                'title'    => self::localizedTitle($row),
                'body'     => (string) $row['body'],
                'created'  => (string) $row['date_creation'],
                'unread'   => empty($row['date_read']),
                'url'      => rtrim((string) $CFG_GLPI['root_doc'], '/') . '/front/ticket.form.php?id=' . $ticketId,
            ];
            if ($previewOnly && count($result) >= 5) {
                break;
            }
        }

        // Count the full inbox, including unread notices older than the feed limit.
        $unread = 0;
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'COUNT' => 'id AS unread'],
            'FROM' => self::TABLE,
            'WHERE' => ['groups_id' => $selectedGroupId, 'date_read' => null],
            'GROUPBY' => 'tickets_id',
        ]) as $row) {
            $ticketId = (int) $row['tickets_id'];
            if (!array_key_exists($ticketId, $access)) {
                $ticket = new Ticket();
                $access[$ticketId] = $ticket->can($ticketId, READ);
            }
            if ($access[$ticketId]) {
                $unread += (int) $row['unread'];
            }
        }

        return ['unread' => $unread, 'group_id' => $selectedGroupId, 'groups' => $groups, 'notifications' => $result, 'i18n' => $i18n];
    }

    /** Check both group membership and ticket read permission before changing shared state. */
    public static function markRead(int $userId, int $groupId, int $id): void
    {
        global $DB;

        self::requireGroup($userId, $groupId);
        if ($id > 0) {
            $rows = $DB->request([
                'SELECT' => ['tickets_id'],
                'FROM' => self::TABLE,
                'WHERE' => ['id' => $id, 'groups_id' => $groupId],
                'LIMIT' => 1,
            ]);
            foreach ($rows as $row) {
                $ticket = new Ticket();
                if (!$ticket->can((int) $row['tickets_id'], READ)) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $DB->update(self::TABLE, ['date_read' => date('Y-m-d H:i:s')], [
                    'id' => $id,
                    'groups_id' => $groupId,
                    'date_read' => null,
                ]);
                return;
            }
        }
    }

    /** Mark only visible notifications; updates are chunked to bound SQL statement size. */
    public static function markAllRead(int $userId, int $groupId): void
    {
        global $DB;

        self::requireGroup($userId, $groupId);
        foreach (array_chunk(self::visibleIds($groupId), 500) as $ids) {
            $DB->update(self::TABLE, ['date_read' => date('Y-m-d H:i:s')], [
                'groups_id' => $groupId,
                'id' => $ids,
                'date_read' => null,
            ]);
        }
    }

    /** Delete visible group history; inaccessible ticket rows remain untouched. */
    public static function clear(int $userId, int $groupId): void
    {
        global $DB;

        self::requireGroup($userId, $groupId);
        foreach (array_chunk(self::visibleIds($groupId), 500) as $ids) {
            $DB->delete(self::TABLE, ['groups_id' => $groupId, 'id' => $ids]);
        }
    }

    /**
     * Cache ticket permission decisions within this request because many rows
     * can reference the same ticket.
     *
     * @return int[]
     */
    private static function visibleIds(int $groupId): array
    {
        global $DB;

        $ids = [];
        $access = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'tickets_id'],
            'FROM' => self::TABLE,
            'WHERE' => ['groups_id' => $groupId],
        ]) as $row) {
            $ticketId = (int) $row['tickets_id'];
            if (!array_key_exists($ticketId, $access)) {
                $ticket = new Ticket();
                $access[$ticketId] = $ticket->can($ticketId, READ);
            }
            if ($access[$ticketId]) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Read memberships from GLPI on every request so revoked access is effective
     * without depending on browser state.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private static function groupsForUser(int $userId): array
    {
        $groups = [];
        foreach (Group_User::getUserGroups($userId) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $groups[$id] = ['id' => $id, 'name' => (string) (($row['completename'] ?? '') ?: ($row['name'] ?? ''))];
            }
        }

        return array_values($groups);
    }

    /**
     * Reject a requested group outside the user's current memberships.
     *
     * @param array<int, array{id: int, name: string}> $groups
     */
    private static function selectedGroupId(array $groups, ?int $groupId): int
    {
        if ($groupId === null || $groupId === 0) {
            return $groups[0]['id'] ?? 0;
        }

        foreach ($groups as $group) {
            if ($group['id'] === $groupId) {
                return $groupId;
            }
        }

        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }

    /** Mutating an inbox always requires an explicit, currently authorized group. */
    private static function requireGroup(int $userId, int $groupId): void
    {
        if ($groupId <= 0 || self::selectedGroupId(self::groupsForUser($userId), $groupId) !== $groupId) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
    }

    /** Hooks may run during installation before the plugin table exists. */
    private static function available(): bool
    {
        global $DB;

        return $DB !== null && $DB->tableExists(self::TABLE);
    }

    /** Store short plain-text excerpts; the browser still inserts them as text. */
    private static function plain(string $html, int $limit): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return mb_strimwidth($plain, 0, $limit, '…', 'UTF-8');
    }

    /**
     * Fan out an event to distinct groups. The unique database key is the final
     * guard against duplicate hook delivery, including concurrent requests.
     * Hook failures are logged with throttling so a notification cannot cancel the GLPI action.
     */
    private static function publish(
        Ticket $ticket,
        string $sourceType,
        int $sourceId,
        string $eventKey,
        string $category,
        string $title,
        string $body,
        array $groupIds
    ): int {
        global $DB;

        $ticketId = (int) $ticket->getID();
        if ($ticketId <= 0) {
            return 0;
        }

        $sent = 0;
        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            if ($groupId <= 0) {
                continue;
            }
            try {
                $existing = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => self::TABLE,
                    'WHERE'  => ['groups_id' => $groupId, 'event_key' => $eventKey],
                    'LIMIT'  => 1,
                ]);
                if (count($existing) > 0) {
                    continue;
                }

                $DB->insert(self::TABLE, [
                    'groups_id'       => $groupId,
                    'tickets_id'      => $ticketId,
                    'source_itemtype' => $sourceType,
                    'source_items_id' => $sourceId,
                    'event_key'       => $eventKey,
                    'category'        => $category,
                    'title'           => $title,
                    'body'            => $body,
                    'date_creation'   => date('Y-m-d H:i:s'),
                ]);
                $sent++;
            } catch (Throwable $error) {
                // A duplicate or failed insert for one group must not starve the others.
                self::logSaveFailure($error);
            }
        }
        return $sent;
    }

    /** Bound repeated failures and never copy SQL or ticket contents into a log. */
    private static function logSaveFailure(Throwable $error): void
    {
        global $GLPI_CACHE;

        static $reported = false;
        if ($reported) {
            return;
        }
        $reported = true;

        // GLPI's shared cache also limits repeats across requests. If unavailable,
        // keep the once-per-request limit without letting logging break a ticket action.
        try {
            if (isset($GLPI_CACHE)) {
                $key = 'notificationbell_save_failure_logged';
                if ($GLPI_CACHE->get($key)) {
                    return;
                }
                $GLPI_CACHE->set($key, true, 900);
            }
        } catch (Throwable) {
            // Cache outages must not trigger another log loop.
        }

        error_log(sprintf('notificationbell: notification save failed (%s, code %d).', get_class($error), $error->getCode()));
    }

    /**
     * Public replies include requester, observer, and assigned group relations.
     *
     * @return int[]
     */
    private static function involvedGroups(Ticket $ticket): array
    {
        global $DB;

        $groups = [];
        foreach ($DB->request([
            'SELECT' => ['groups_id'],
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => ['tickets_id' => (int) $ticket->getID()],
        ]) as $row) {
            $id = (int) $row['groups_id'];
            if ($id > 0) {
                $groups[$id] = true;
            }
        }
        return array_map('intval', array_keys($groups));
    }

    /**
     * Status and reminder events target only groups assigned to the ticket.
     *
     * @return int[]
     */
    private static function assignedGroups(Ticket $ticket): array
    {
        global $DB;

        $ticketId = (int) $ticket->getID();
        $groups = [];
        foreach ($DB->request([
            'SELECT' => ['groups_id'],
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => ['tickets_id' => $ticketId, 'type' => CommonITILActor::ASSIGN],
        ]) as $row) {
            $id = (int) $row['groups_id'];
            if ($id > 0) {
                $groups[$id] = true;
            }
        }

        return array_map('intval', array_keys($groups));
    }
}
