<?php

/**
 * Stores ticket and Change activity in global, group, and personal inboxes.
 *
 * The active profile selects an inbox; Ticket::can() or Change::can() controls
 * feed disclosure and mark-as-read access. Clearing shared history requires
 * access to the selected inbox and intentionally includes retained hidden rows.
 */
require_once __DIR__ . '/realtime.class.php';

final class PluginNotificationbellNotification
{
    public const TABLE = 'glpi_plugin_notificationbell_group_notifications';
    public const USER_TABLE = 'glpi_plugin_notificationbell_user_notifications';
    // Reserved group inbox for roles that must see ticket activity across groups.
    private const GLOBAL_INBOX_ID = 4294967295;
    private const MAX_FEED_ROWS = 1000;
    private const FEED_BATCH_SIZE = 250;
    /** @var \WeakMap<Ticket|Change, string>|null Tokens live only as long as their native update objects. */
    private static ?\WeakMap $statusEvents = null;

    /** Text for the static JavaScript interface, translated by GLPI for this user. */
    private static function uiStrings(): array
    {
        return [
            'all' => __('All', 'notificationbell'),
            'incoming' => __('Incoming', 'notificationbell'),
            'personalInbox' => __('Personal mentions', 'notificationbell'),
            'live' => __('Live', 'notificationbell'),
            'reconnecting' => __('Reconnecting', 'notificationbell'),
            'realtimeUnavailable' => __('Live service unavailable', 'notificationbell'),
            'resolved' => __('Resolved', 'notificationbell'),
            'attention' => __('Attention', 'notificationbell'),
            'responses' => __('Replies', 'notificationbell'),
            'notifications' => __('Notifications', 'notificationbell'),
            'recent' => __('Recent notifications', 'notificationbell'),
            'viewAll' => __('View all', 'notificationbell'),
            'sharedInbox' => __('Shared group inbox', 'notificationbell'),
            'sharedInboxGroup' => __('Shared inbox · %s', 'notificationbell'),
            'close' => __('Close', 'notificationbell'),
            'cancel' => __('Cancel', 'notificationbell'),
            'markAllRead' => __('Mark all as read', 'notificationbell'),
            'clear' => __('Clear history', 'notificationbell'),
            'confirmClear' => __('Clear notification history?', 'notificationbell'),
            'clearGroupDetail' => __('Delete all notifications from %s and your personal inbox? The shared inbox will be cleared for its other members too.', 'notificationbell'),
            'clearPersonalDetail' => __('Delete all notifications from your personal inbox?', 'notificationbell'),
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
        if (str_starts_with($key, 'ticket-status:') || str_starts_with($key, 'change-status:')) {
            // Keys retain the destination: prefix:item:previous:destination:timestamp:operation.
            // Resolve its label at read time so historical events follow the viewer's language.
            $parts = explode(':', $key);
            $status = (int) $parts[3];
            return str_starts_with($key, 'change-status:')
                ? sprintf(__('Change status: %s', 'notificationbell'), Change::getStatus($status))
                : sprintf(__('Ticket status: %s', 'notificationbell'), Ticket::getStatus($status));
        }
        if (str_starts_with($key, 'change-created:')) {
            return __('A new change has been received', 'notificationbell');
        }
        if (str_starts_with($key, 'user-mentioned:') || str_starts_with($key, 'group-mentioned:')) {
            $parts = explode(':', $key);
            $actorId = count($parts) >= (str_starts_with($key, 'group-mentioned:') ? 5 : 4) ? (int) end($parts) : 0;
            $actor = self::userName($actorId) ?: __('A user', 'notificationbell');
            $message = str_starts_with($key, 'group-mentioned:')
                ? '@%s mentioned the area'
                : '@%s mentioned you';
            return sprintf(__($message, 'notificationbell'), $actor);
        }
        if (str_starts_with($key, 'ticket-response:')) {
            return __('A reply was added to the ticket', 'notificationbell');
        }
        if (($row['category'] ?? '') === 'incoming') {
            return __('A new ticket has been received', 'notificationbell');
        }
        if (($row['category'] ?? '') === 'resolved') {
            return __('The following ticket has been resolved', 'notificationbell');
        }
        if (($row['category'] ?? '') === 'attention') {
            return __('Please follow up on the ticket', 'notificationbell');
        }
        return (string) $row['title'];
    }

    private static function userName(int $userId): string
    {
        if ($userId <= 0 || !class_exists(User::class)) {
            return '';
        }
        $user = new User();
        if (!$user->getFromDB($userId)) {
            return '';
        }
        return trim((string) ($user->fields['firstname'] ?? '') . ' ' . (string) ($user->fields['realname'] ?? ''))
            ?: (string) ($user->fields['name'] ?? '');
    }

    /** Each newly linked area receives the same single ticket notice. */
    public static function onGroupAssigned(Group_Ticket $assignment): void
    {
        if (!self::available()) {
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
            Ticket::class,
            $ticketId,
            'ticket-created:' . $ticketId,
            'incoming',
            'A new ticket has been received',
            self::plain((string) ($ticket->fields['name'] ?? ''), 180),
            [$groupId]
        );
    }

    /** Start one operation identity before GLPI prepares input and applies business rules. */
    public static function onBeforeItemUpdate(Ticket|Change $item): void
    {
        self::$statusEvents ??= new \WeakMap();
        self::$statusEvents[$item] = bin2hex(random_bytes(16));
    }

    /** Repeated post-update callbacks share a token; the next native update replaces it. */
    private static function statusEventId(Ticket|Change $item): string
    {
        self::$statusEvents ??= new \WeakMap();
        return self::$statusEvents[$item] ??= bin2hex(random_bytes(16));
    }

    /** Route status transitions to all groups involved when GLPI runs the hook. */
    public static function onTicketUpdated(Ticket $ticket): void
    {
        if (!self::available()) {
            return;
        }

        if (in_array('content', $ticket->updates, true)) {
            self::publishMentions($ticket, $ticket);
        }
        if (!in_array('status', $ticket->updates, true)) {
            return;
        }

        $id = (int) $ticket->getID();
        $status = (int) ($ticket->fields['status'] ?? 0);
        $previous = (int) ($ticket->oldvalues['status'] ?? 0);
        // Some hooks report an updated field even when its value did not change.
        if ($previous === $status) {
            return;
        }
        $stamp = (string) ($ticket->fields['date_mod'] ?? date('Y-m-d H:i:s'));
        // Second-resolution timestamps describe the event time, not its unique identity.
        $operation = self::statusEventId($ticket);

        if (in_array($status, [Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING], true)) {
            // Immediate state changes reach shared inboxes, including Service Desk.
            // Requesters keep their existing replies and resolution notices instead.
            self::publish(
                $ticket, Ticket::class, $id, "ticket-status:$id:$previous:$status:$stamp:$operation",
                'attention', 'Ticket status: ' . Ticket::getStatus($status), '', self::involvedGroups($ticket), true, [], false
            );
        }
        if ($status === Ticket::SOLVED) {
            self::publish(
                $ticket,
                Ticket::class,
                $id,
                "ticket-resolved:$id:$stamp:$operation",
                'resolved',
                'The following ticket has been resolved',
                self::plain((string) ($ticket->fields['name'] ?? ''), 180),
                self::involvedGroups($ticket)
            );
        }
    }

    /** Public replies reach involved groups; private notes must never enter a shared inbox. */
    public static function onFollowupAdded(ITILFollowup $followup): void
    {
        if (!self::available()
            || ($followup->fields['itemtype'] ?? '') !== Ticket::class
        ) {
            return;
        }
        if ((int) ($followup->fields['is_private'] ?? 0) !== 0) {
            $ticket = self::activityTicket($followup);
            if ($ticket !== null) {
                self::publishMentions($followup, $ticket);
            }
            return;
        }

        $ticket = new Ticket();
        $ticketId = (int) ($followup->fields['items_id'] ?? 0);
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }
        self::publishMentions($followup, $ticket);
        self::syncPublicReply($followup, $ticket);
    }

    /** Send an ordinary reply to participants who were not addressed by @. */
    private static function syncPublicReply(ITILFollowup $followup, Ticket $ticket): void
    {
        global $DB;
        $content = (string) ($followup->fields['content'] ?? '');
        $groupMentions = self::mentionedGroups($ticket, $content);
        $userMentions = class_exists(\Glpi\RichText\UserMention::class)
            ? array_unique(array_map('intval', \Glpi\RichText\UserMention::getUserIdsFromUserMentions($content)))
            : [];
        $groups = array_values(array_diff(self::involvedGroups($ticket), $groupMentions));
        $key = 'ticket-response:' . $followup->getID();
        $where = ['source_itemtype' => ITILFollowup::class, 'source_items_id' => (int) $followup->getID(), 'event_key' => $key];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            if ((int) $row['groups_id'] !== self::GLOBAL_INBOX_ID && !in_array((int) $row['groups_id'], $groups, true)) {
                $DB->delete(self::TABLE, ['id' => (int) $row['id']]);
                PluginNotificationbellRealtime::changed('g_' . (int) $row['groups_id']);
            }
        }
        if ($DB->tableExists(self::USER_TABLE)) {
            foreach ($DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $where]) as $row) {
                if (in_array((int) $row['users_id'], $userMentions, true)) {
                    $DB->delete(self::USER_TABLE, ['id' => (int) $row['id']]);
                    PluginNotificationbellRealtime::changed('u_' . (int) $row['users_id']);
                }
            }
        }
        self::publish(
            $ticket, ITILFollowup::class, (int) $followup->getID(), $key, 'responses',
            'A reply was added to the ticket', self::plain($content, 180), $groups, true, $userMentions
        );
    }

    /** Keep public excerpts current and revoke them when a reply becomes private. */
    public static function onFollowupUpdated(ITILFollowup $followup): void
    {
        global $DB;
        $ticket = self::activityTicket($followup);
        if ($ticket !== null) {
            self::publishMentions($followup, $ticket);
        }
        if (($followup->fields['itemtype'] ?? '') !== Ticket::class || !self::available()) {
            return;
        }
        if ((int) ($followup->fields['is_private'] ?? 0) === 1) {
            self::deleteFollowupNotices((int) $followup->getID());
        } elseif (in_array('content', $followup->updates, true) || in_array('is_private', $followup->updates, true)) {
            if ($ticket !== null) {
                self::syncPublicReply($followup, $ticket);
            }
            $where = ['source_itemtype' => ITILFollowup::class, 'source_items_id' => (int) $followup->getID()];
            $body = self::plain((string) ($followup->fields['content'] ?? ''), 180);
            $DB->update(self::TABLE, ['body' => $body], $where);
            if ($DB->tableExists(self::USER_TABLE)) {
                $personalWhere = $where + ['category' => 'responses'];
                $DB->update(self::USER_TABLE, ['body' => $body], $personalWhere);
                foreach ($DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $personalWhere]) as $row) {
                    PluginNotificationbellRealtime::changed('u_' . (int) $row['users_id']);
                }
            }
            foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
                PluginNotificationbellRealtime::changed('g_' . (int) $row['groups_id']);
            }
        }
    }

    /** Remove an excerpt when GLPI deletes its source reply. */
    public static function onFollowupDeleted(ITILFollowup $followup): void
    {
        self::onActivityDeleted($followup);
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

        $groups = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['source_itemtype' => ITILFollowup::class, 'source_items_id' => $followupId]]) as $row) {
            $groups[] = (int) $row['groups_id'];
        }
        $DB->delete(self::TABLE, [
            'source_itemtype' => ITILFollowup::class,
            'source_items_id' => $followupId,
        ]);
        if ($DB->tableExists(self::USER_TABLE)) {
            $personalWhere = [
                'source_itemtype' => ITILFollowup::class,
                'source_items_id' => $followupId,
                'category' => 'responses',
            ];
            $users = [];
            foreach ($DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $personalWhere]) as $row) {
                $users[(int) $row['users_id']] = true;
            }
            $DB->delete(self::USER_TABLE, $personalWhere);
            foreach (array_keys($users) as $userId) {
                PluginNotificationbellRealtime::changed('u_' . $userId);
            }
        }
        foreach ($groups as $groupId) {
            PluginNotificationbellRealtime::changed('g_' . $groupId);
        }
    }

    /** Keep mentions in edited solutions in sync with their current content. */
    public static function onSolutionUpdated(ITILSolution $solution): void
    {
        if (!self::available()
            || ($solution->fields['itemtype'] ?? '') !== Ticket::class
        ) {
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) ($solution->fields['items_id'] ?? 0))) {
            return;
        }

        self::publishMentions($solution, $ticket);
    }

    public static function onTicketAdded(Ticket $ticket): void
    {
        if (self::available()) {
            self::publishTicketReceived($ticket);
            self::publishMentions($ticket, $ticket);
        }
    }

    private static function publishTicketReceived(Ticket $ticket): void
    {
        self::publish($ticket, Ticket::class, (int) $ticket->getID(), 'ticket-created:' . $ticket->getID(), 'incoming',
            'A new ticket has been received', self::plain((string) ($ticket->fields['name'] ?? ''), 180),
            self::involvedGroups($ticket));
    }

    /** Deliver Change arrivals using the same shared-inbox rules as ticket arrivals. */
    public static function onChangeAdded(Change $change): void
    {
        if (self::available()) {
            self::publishChangeReceived($change, self::involvedGroups($change));
        }
    }

    /** A later group link reuses the creation key, avoiding duplicate arrivals per inbox. */
    public static function onChangeGroupAssigned(Change_Group $assignment): void
    {
        if (!self::available()) {
            return;
        }
        $change = new Change();
        $groupId = (int) ($assignment->fields['groups_id'] ?? 0);
        if ($groupId > 0 && $change->getFromDB((int) ($assignment->fields['changes_id'] ?? 0))) {
            self::publishChangeReceived($change, [$groupId]);
        }
    }

    /** Keep arrivals in Incoming; subsequent Change transitions belong to Attention. */
    private static function publishChangeReceived(Change $change, array $groupIds): void
    {
        $id = (int) $change->getID();
        self::publish($change, Change::class, $id, "change-created:$id", 'incoming',
            'A new change has been received', '', $groupIds, true, [], false);
    }

    /** Publish real Change transitions using GLPI's native, complete status catalog. */
    public static function onChangeUpdated(Change $change): void
    {
        if (!self::available() || !in_array('status', $change->updates, true)) {
            return;
        }
        $id = (int) $change->getID();
        $status = (int) ($change->fields['status'] ?? 0);
        $previous = (int) ($change->oldvalues['status'] ?? 0);
        if ($previous === $status || !array_key_exists($status, Change::getAllStatusArray())) {
            return;
        }
        $stamp = (string) ($change->fields['date_mod'] ?? date('Y-m-d H:i:s'));
        $operation = self::statusEventId($change);
        self::publish($change, Change::class, $id, "change-status:$id:$previous:$status:$stamp:$operation",
            'attention', 'Change status: ' . Change::getStatus($status), '', self::involvedGroups($change), true, [], false);
    }

    /** Publish activity metadata without copying private bodies into group feeds. */
    public static function onActivity(CommonDBTM $item): void
    {
        if (!self::available()) {
            return;
        }
        $ticket = self::activityTicket($item);
        if ($ticket === null) {
            return;
        }
        // Ticket requester links can be created after the ticket add hook.
        if ($item instanceof Ticket_User && (int) ($item->fields['type'] ?? 0) === CommonITILActor::REQUESTER) {
            self::publishTicketReceived($ticket);
        }
        self::publishMentions($item, $ticket);
    }

    public static function onActivityDeleted(CommonDBTM $item): void
    {
        global $DB;
        if (!self::available()) {
            return;
        }
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => [
            'source_itemtype' => $item::class, 'source_items_id' => (int) $item->getID(), 'category' => 'responses',
        ]]) as $row) {
            if (str_starts_with((string) $row['event_key'], 'group-mentioned:')) {
                $DB->delete(self::TABLE, ['id' => (int) $row['id']]);
                PluginNotificationbellRealtime::changed('g_' . (int) $row['groups_id']);
            }
        }
        if ($DB->tableExists(self::USER_TABLE)) {
            $where = ['source_itemtype' => $item::class, 'source_items_id' => (int) $item->getID()];
            $users = [];
            foreach ($DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $where]) as $row) {
                $users[(int) $row['users_id']] = true;
            }
            $DB->delete(self::USER_TABLE, $where);
            foreach (array_keys($users) as $userId) {
                PluginNotificationbellRealtime::changed('u_' . $userId);
            }
        }
    }

    private static function activityTicket(CommonDBTM $item): ?Ticket
    {
        if ($item instanceof Ticket) {
            return $item;
        }
        if (isset($item->fields['itemtype']) && $item->fields['itemtype'] !== Ticket::class) {
            return null;
        }
        $ticket = new Ticket();
        $id = (int) ($item->fields['tickets_id'] ?? $item->fields['items_id'] ?? 0);
        return $id > 0 && $ticket->getFromDB($id) ? $ticket : null;
    }

    /** Native GLPI @ selection encodes the exact user ID, independent of display names. */
    private static function publishMentions(CommonDBTM $item, Ticket $ticket): void
    {
        try {
            self::syncMentions($item, $ticket);
        } catch (Throwable $error) {
            self::logSaveFailure($error);
        }
    }

    private static function syncMentions(CommonDBTM $item, Ticket $ticket): void
    {
        global $DB;
        $content = (string) ($item->fields['content'] ?? '');
        if (!$DB->tableExists(self::USER_TABLE) || !class_exists(\Glpi\RichText\UserMention::class)) {
            self::syncGroupMentions($item, $ticket, $content);
            return;
        }
        $ids = array_unique(array_map('intval', \Glpi\RichText\UserMention::getUserIdsFromUserMentions($content)));
        $where = [
            'source_itemtype' => $item::class,
            'source_items_id' => (int) $item->getID(),
            'category' => 'mentions',
        ];
        // Reconcile edits as well as additions, so removed mentions do not retain excerpts.
        foreach ($DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $where]) as $row) {
            if (!in_array((int) $row['users_id'], $ids, true)) {
                $DB->delete(self::USER_TABLE, ['id' => (int) $row['id']]);
                PluginNotificationbellRealtime::changed('u_' . (int) $row['users_id']);
            }
        }
        foreach ($ids as $userId) {
            $user = new User();
            if ($userId <= 0 || !$user->getFromDB($userId) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
                continue;
            }
            $actorId = (int) ($item->fields['users_id'] ?? $item->fields['users_id_lastupdater'] ?? $item->fields['users_id_recipient'] ?? 0);
            $key = 'user-mentioned:' . $item::class . ':' . $item->getID() . ':' . $actorId;
            $recipient = ['users_id' => $userId, 'event_key' => $key];
            $body = self::plain($content, 180);
            $existing = $DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $recipient, 'LIMIT' => 1]);
            try {
                if (count($existing) > 0) {
                    $DB->update(self::USER_TABLE, ['body' => $body], $recipient);
                } else {
                    $saved = $DB->insert(self::USER_TABLE, $recipient + $where + [
                        'tickets_id' => (int) $ticket->getID(),
                        'title' => '@' . (self::userName($actorId) ?: 'A user') . ' mentioned you',
                        'body' => $body, 'date_creation' => date('Y-m-d H:i:s'),
                    ]);
                    if ($saved === false) {
                        throw new RuntimeException('Mention insert failed');
                    }
                }
                PluginNotificationbellRealtime::changed('u_' . $userId);
            } catch (Throwable $error) {
                self::logSaveFailure($error);
            }
        }
        self::syncGroupMentions($item, $ticket, $content);
    }

    /** A written @area name addresses that ticket's assigned area once. */
    private static function syncGroupMentions(CommonDBTM $item, Ticket $ticket, string $content): void
    {
        global $DB;
        $source = ['source_itemtype' => $item::class, 'source_items_id' => (int) $item->getID(), 'category' => 'responses'];
        $matches = (int) ($item->fields['is_private'] ?? 0) === 0
            ? array_fill_keys(self::mentionedGroups($ticket, $content), true)
            : [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $source]) as $row) {
            if (str_starts_with((string) $row['event_key'], 'group-mentioned:') && !isset($matches[(int) $row['groups_id']])) {
                $DB->delete(self::TABLE, ['id' => (int) $row['id']]);
                PluginNotificationbellRealtime::changed('g_' . (int) $row['groups_id']);
            }
        }
        $actorId = (int) ($item->fields['users_id'] ?? $item->fields['users_id_lastupdater'] ?? $item->fields['users_id_recipient'] ?? 0);
        foreach (array_keys($matches) as $groupId) {
            $eventKey = 'group-mentioned:' . $item::class . ':' . $item->getID() . ':' . $groupId . ':' . $actorId;
            $exists = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['groups_id' => $groupId, 'event_key' => $eventKey], 'LIMIT' => 1]);
            if (count($exists) > 0) {
                $DB->update(self::TABLE, ['body' => self::plain($content, 180)], ['groups_id' => $groupId, 'event_key' => $eventKey]);
                PluginNotificationbellRealtime::changed('g_' . $groupId);
                continue;
            }
            $DB->insert(self::TABLE, [
                'groups_id' => $groupId, 'tickets_id' => (int) $ticket->getID(),
                'source_itemtype' => $item::class, 'source_items_id' => (int) $item->getID(),
                'event_key' => $eventKey, 'category' => 'responses', 'is_incoming' => 0,
                'title' => '@' . (self::userName($actorId) ?: 'A user') . ' mentioned the area',
                'body' => self::plain($content, 180), 'date_creation' => date('Y-m-d H:i:s'),
            ]);
            PluginNotificationbellRealtime::changed('g_' . $groupId);
        }
    }

    /** Match explicit @area names only among groups linked to this ticket. */
    private static function mentionedGroups(Ticket $ticket, string $content): array
    {
        if (!class_exists(Group::class)) {
            return [];
        }
        $plain = self::plain($content, 10000);
        $matches = [];
        foreach (self::involvedGroups($ticket) as $groupId) {
            $group = new Group();
            if (!$group->getFromDB($groupId)) {
                continue;
            }
            foreach (array_unique(array_filter([(string) ($group->fields['name'] ?? ''), (string) ($group->fields['completename'] ?? '')])) as $name) {
                if (preg_match('/(?<![\pL\pN_])@' . preg_quote($name, '/') . '(?![\pL\pN_])/iu', $plain)) {
                    $matches[] = $groupId;
                    break;
                }
            }
        }
        return $matches;
    }

    /** Mention excerpts also require current access to their source (including private notes). */
    private static function canReadNotice(array $row): bool
    {
        $ticket = self::noticeItem($row);
        if (!$ticket->can(self::noticeId($row), READ)) {
            return false;
        }
        if (($row['scope'] ?? '') !== 'user' || ($row['category'] ?? '') !== 'mentions' || $row['source_itemtype'] === Ticket::class) {
            return true;
        }
        $type = $row['source_itemtype'];
        if (!in_array($type, [ITILFollowup::class, ITILSolution::class, TicketTask::class], true)) {
            return false;
        }
        $source = new $type();
        return $source->can((int) $row['source_items_id'], READ);
    }

    /**
     * Change events point directly to their source; tickets keep their existing ID column.
     * Only these two known classes may be instantiated from a stored notification.
     */
    private static function noticeItem(array $row): Ticket|Change
    {
        return ($row['source_itemtype'] ?? '') === Change::class ? new Change() : new Ticket();
    }

    /** A Change ID must never be interpreted as a Ticket ID, even when numbers match. */
    private static function noticeId(array $row): int
    {
        return (int) (($row['source_itemtype'] ?? '') === Change::class ? $row['source_items_id'] : $row['tickets_id']);
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
            $eventKey = "attention-reminder:$ticketId:$state:" . bin2hex(random_bytes(8));
            $targetGroups = array_values(array_filter(
                self::involvedGroups($ticket),
                static fn(int $groupId): bool => !self::isDeskInbox($groupId)
            ));
            foreach ($targetGroups as $groupId) {
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
                    $eventKey,
                    'attention',
                    'Please follow up on the ticket',
                    self::plain((string) ($ticket->fields['name'] ?? ''), 180),
                    [$groupId],
                    false,
                    [],
                    false
                );
            }
        }

        if ($sent > 0) {
            $task->addVolume($sent);
            $task->log(sprintf(__('Attention reminders created: %d', 'notificationbell'), $sent));
        }
        return $sent > 0 ? 1 : 0;
    }

    /** Read stable date/ID batches without letting hidden rows consume the visible result limit. */
    private static function inboxRows(string $table, array $where): \Generator
    {
        global $DB;
        if (!$DB->tableExists($table)) {
            return;
        }
        $cursor = null;
        do {
            $criteria = $where;
            if ($cursor !== null) {
                // A strict keyset cursor also handles equal timestamps and backdated rows.
                $criteria['OR'] = [
                    ['date_creation' => ['<', $cursor['date_creation']]],
                    ['date_creation' => $cursor['date_creation'], 'id' => ['<', $cursor['id']]],
                ];
            }
            $count = 0;
            foreach ($DB->request([
                'FROM' => $table, 'WHERE' => $criteria,
                'ORDER' => ['date_creation DESC', 'id DESC'], 'LIMIT' => self::FEED_BATCH_SIZE,
            ]) as $row) {
                $count++;
                $cursor = ['date_creation' => (string) $row['date_creation'], 'id' => (int) $row['id']];
                yield $row;
            }
        } while ($count === self::FEED_BATCH_SIZE);
    }

    /** Merge the two ordered sources lazily; each source buffers at most one batch. */
    private static function feedRows(int $userId, int $groupId): \Generator
    {
        $shared = self::inboxRows(self::TABLE, ['groups_id' => $groupId]);
        $personal = self::inboxRows(self::USER_TABLE, ['users_id' => $userId]);
        while ($shared->valid() || $personal->valid()) {
            $groupRow = $shared->valid() ? $shared->current() : null;
            $userRow = $personal->valid() ? $personal->current() : null;
            if ($userRow === null || ($groupRow !== null
                && [$groupRow['date_creation'], $groupRow['id']] >= [$userRow['date_creation'], $userRow['id']])) {
                yield $groupRow + ['scope' => 'group'];
                $shared->next();
            } else {
                yield $userRow + ['scope' => 'user'];
                $personal->next();
            }
        }
    }

    /**
     * Return only rows for items the current user may read. The same filter
     * applies to the unread count, so the badge cannot disclose hidden activity.
     *
     * @return array{unread: int, group_id: int, groups: array<int, array{id: int, name: string}>, notifications: array<int, array<string, mixed>>, i18n: array<string, string>}
     */
    public static function feed(int $userId, ?int $groupId = null, bool $previewOnly = false): array
    {
        global $DB, $CFG_GLPI;

        $role = self::activeRole();
        $groups = self::groupsForUser($userId);
        $selectedGroupId = self::selectedGroupId($groups, $groupId);
        $i18n = self::uiStrings();
        if (!self::available()) {
            return ['unread' => 0, 'group_id' => $selectedGroupId, 'groups' => $groups, 'notifications' => [], 'i18n' => $i18n];
        }

        $personalScopeCache = [];
        $result = [];
        $seenIncoming = [];
        $access = [];
        $roleAccess = [];
        $ticketLabels = [];
        foreach (self::feedRows($userId, $selectedGroupId) as $row) {
            if (!self::showEvent($row)
                || ($row['scope'] === 'user' && !self::canShowPersonalNotice($role, $row, $userId, $personalScopeCache))) {
                continue;
            }
            $ticket = self::noticeItem($row);
            $ticketId = self::noticeId($row);
            // Permissions, labels and arrival deduplication must distinguish Ticket:42 from Change:42.
            $itemKey = $ticket::class . ':' . $ticketId;
            if (!array_key_exists($itemKey, $access)) {
                $access[$itemKey] = $ticket->can($ticketId, READ);
            }
            $roleKey = $itemKey . ':' . $row['scope'];
            if (!array_key_exists($roleKey, $roleAccess)) {
                $roleAccess[$roleKey] = self::roleCanSeeTicket($role, $ticketId, $userId, $row['scope'] === 'user' ? 0 : $selectedGroupId, $ticket::class);
            }
            if (!$access[$itemKey] || !$roleAccess[$roleKey]
                || ($row['scope'] === 'user' && !self::canReadNotice($row))) {
                continue;
            }
            $incomingKey = $row['scope'] . ':' . $itemKey;
            if ($row['category'] === 'incoming') {
                if (isset($seenIncoming[$incomingKey])) {
                    continue;
                }
                $seenIncoming[$incomingKey] = true;
            }
            if (!isset($ticketLabels[$itemKey])) {
                $ticketLabels[$itemKey] = $ticket->getFromDB($ticketId)
                    ? self::plain((string) ($ticket->fields['name'] ?? ''), 150) . ' (' . $ticketId . ')'
                    : '(' . $ticketId . ')';
            }
            $body = $ticketLabels[$itemKey];
            if (in_array($row['category'], ['responses', 'mentions'], true) && trim((string) $row['body']) !== '') {
                $body .= ' · ' . (string) $row['body'];
            }

            $result[] = [
                'id'       => (int) $row['id'],
                'scope'    => $row['scope'],
                'incoming' => $row['category'] === 'incoming',
                'category' => $row['category'] === 'mentions' ? 'responses' : (string) $row['category'],
                'title'    => self::localizedTitle($row),
                'body'     => $body,
                'created'  => (string) $row['date_creation'],
                'unread'   => empty($row['date_read']),
                'url'      => rtrim((string) $CFG_GLPI['root_doc'], '/') . ($ticket instanceof Change ? '/front/change.form.php?id=' : '/front/ticket.form.php?id=') . $ticketId,
            ];
            if (count($result) >= ($previewOnly ? 5 : self::MAX_FEED_ROWS)) {
                break;
            }
        }

        // Count visible events after collapsing old creation/assignment duplicates.
        $unread = 0;
        $seenIncoming = [];
        foreach (self::inboxRows(self::TABLE, ['groups_id' => $selectedGroupId]) as $row) {
            if (!self::showEvent($row)) {
                continue;
            }
            $ticket = self::noticeItem($row);
            $ticketId = self::noticeId($row);
            $itemKey = $ticket::class . ':' . $ticketId;
            if ($row['category'] === 'incoming') {
                if (isset($seenIncoming[$itemKey])) {
                    continue;
                }
                $seenIncoming[$itemKey] = true;
            }
            if (!array_key_exists($itemKey, $access)) {
                $access[$itemKey] = $ticket->can($ticketId, READ);
            }
            $roleKey = $itemKey . ':group';
            if (!array_key_exists($roleKey, $roleAccess)) {
                $roleAccess[$roleKey] = self::roleCanSeeTicket($role, $ticketId, $userId, $selectedGroupId, $ticket::class);
            }
            if ($access[$itemKey] && $roleAccess[$roleKey] && empty($row['date_read'])) {
                $unread++;
            }
        }

        if ($DB->tableExists(self::USER_TABLE)) {
            foreach (self::inboxRows(self::USER_TABLE, ['users_id' => $userId, 'date_read' => null]) as $row) {
                if (self::showEvent($row) && self::canShowPersonalNotice($role, $row, $userId, $personalScopeCache)
                    && self::roleCanSeeTicket($role, (int) $row['tickets_id'], $userId, 0)
                    && self::canReadNotice($row + ['scope' => 'user'])) {
                    $unread++;
                }
            }
        }
        return ['unread' => $unread, 'group_id' => $selectedGroupId, 'groups' => $groups, 'notifications' => $result, 'i18n' => $i18n,
            'realtime' => PluginNotificationbellRealtime::connection($userId, array_column($groups, 'id'))];
    }

    /** Hide legacy activity and scheduled reminders whose recipients are ineligible. */
    private static function showEvent(array $row): bool
    {
        // Desk exclusions apply to scheduled reminders, not immediate status notifications.
        if (($row['category'] ?? '') === 'attention'
            && (isset($row['users_id'])
                || (($row['source_itemtype'] ?? '') === self::class && self::isDeskInbox((int) ($row['groups_id'] ?? 0))))) {
            return false;
        }
        $key = (string) ($row['event_key'] ?? '');
        foreach (['ticket-activity:', 'ticket-updated:', 'ticket-reopened:', 'solution-rejected:'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }
        return true;
    }

    /** Service Desk uses the global inbox; Mesa de Servicios is also a GLPI area. */
    private static function isDeskInbox(int $groupId): bool
    {
        static $cache = [];
        if ($groupId === self::GLOBAL_INBOX_ID) {
            return true;
        }
        if ($groupId <= 0 || !class_exists(Group::class)) {
            return false;
        }
        if (!array_key_exists($groupId, $cache)) {
            $group = new Group();
            $name = $group->getFromDB($groupId) ? mb_strtolower(trim((string) ($group->fields['name'] ?? '')), 'UTF-8') : '';
            $cache[$groupId] = in_array($name, ['service desk', 'mesa de servicios'], true);
        }
        return $cache[$groupId];
    }

    /** Check both group membership and ticket read permission before changing shared state. */
    public static function markRead(int $userId, int $groupId, int $id, string $scope = 'group'): void
    {
        global $DB;

        if ($scope === 'user') {
            self::personalAction($userId, $id);
            return;
        }
        self::requireGroup($userId, $groupId);
        if ($id > 0) {
            $rows = $DB->request([
                'SELECT' => ['tickets_id', 'source_itemtype', 'source_items_id'],
                'FROM' => self::TABLE,
                'WHERE' => ['id' => $id, 'groups_id' => $groupId],
                'LIMIT' => 1,
            ]);
            foreach ($rows as $row) {
                // Authorize the underlying item, not the legacy tickets_id storage column alone.
                $ticket = self::noticeItem($row);
                $ticketId = self::noticeId($row);
                if (!$ticket->can($ticketId, READ)
                    || !self::roleCanSeeTicket(self::activeRole(), $ticketId, $userId, $groupId, $ticket::class)) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }

                $DB->update(self::TABLE, ['date_read' => date('Y-m-d H:i:s')], [
                    'id' => $id,
                    'groups_id' => $groupId,
                    'date_read' => null,
                ]);
                PluginNotificationbellRealtime::changed('g_' . $groupId);
                return;
            }
        }
    }

    /** Mark only visible notifications; updates are chunked to bound SQL statement size. */
    public static function markAllRead(int $userId, int $groupId): void
    {
        global $DB;

        if ($groupId > 0) {
            self::requireGroup($userId, $groupId);
        }
        self::personalAction($userId);
        if ($groupId === 0) {
            return;
        }
        foreach (array_chunk(self::visibleIds($groupId, $userId), 500) as $ids) {
            $DB->update(self::TABLE, ['date_read' => date('Y-m-d H:i:s')], [
                'groups_id' => $groupId,
                'id' => $ids,
                'date_read' => null,
            ]);
        }
        PluginNotificationbellRealtime::changed('g_' . $groupId);
    }

    /** Empty the authorized shared inbox and this user's personal inbox in storage. */
    public static function clear(int $userId, int $groupId): void
    {
        global $DB;

        if ($groupId > 0) {
            self::requireGroup($userId, $groupId);
        }
        // A notice may outlive the user's current ticket rights or its source.
        // Clear the stored rows too, otherwise they return when rights change.
        if ($DB->tableExists(self::USER_TABLE)) {
            $DB->delete(self::USER_TABLE, ['users_id' => $userId]);
            PluginNotificationbellRealtime::changed('u_' . $userId);
        }
        if ($groupId === 0) {
            return;
        }
        $DB->delete(self::TABLE, ['groups_id' => $groupId]);
        PluginNotificationbellRealtime::changed('g_' . $groupId);
    }

    private static function personalAction(int $userId, ?int $id = null): void
    {
        global $DB;
        if (!$DB->tableExists(self::USER_TABLE)) {
            return;
        }
        $where = ['users_id' => $userId] + ($id === null ? [] : ['id' => $id]);
        $changed = false;
        $personalScopeCache = [];
        foreach ($DB->request(['FROM' => self::USER_TABLE, 'WHERE' => $where]) as $row) {
            if (!self::canShowPersonalNotice(self::activeRole(), $row, $userId, $personalScopeCache)
                || !self::canReadNotice($row + ['scope' => 'user'])) {
                if ($id !== null) {
                    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
                }
                continue;
            }
            $DB->update(self::USER_TABLE, ['date_read' => date('Y-m-d H:i:s')], ['id' => (int) $row['id'], 'users_id' => $userId]);
            $changed = true;
        }
        if ($changed) {
            PluginNotificationbellRealtime::changed('u_' . $userId);
        }
    }

    /**
     * Cache ticket permission decisions within this request because many rows
     * can reference the same ticket.
     *
     * @return int[]
     */
    private static function visibleIds(int $groupId, int $userId): array
    {
        global $DB;

        $ids = [];
        $access = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'tickets_id', 'source_itemtype', 'source_items_id'],
            'FROM' => self::TABLE,
            'WHERE' => ['groups_id' => $groupId],
        ]) as $row) {
            $ticket = self::noticeItem($row);
            $ticketId = self::noticeId($row);
            $itemKey = $ticket::class . ':' . $ticketId;
            if (!array_key_exists($itemKey, $access)) {
                $access[$itemKey] = $ticket->can($ticketId, READ);
            }
            if ($access[$itemKey] && self::roleCanSeeTicket(self::activeRole(), $ticketId, $userId, $groupId, $ticket::class)) {
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
        $role = self::activeRole();
        if ($role === 'self_service') {
            return [];
        }
        if ($role === 'global') {
            return [['id' => self::GLOBAL_INBOX_ID, 'name' => __('All tickets', 'notificationbell')]];
        }
        $groups = [];
        foreach (Group_User::getUserGroups($userId) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0
                && ($role !== 'technician' || (int) ($row['is_assign'] ?? 0) === 1)
                && ($role !== 'requester_group' || (int) ($row['is_requester'] ?? 0) === 1 || (int) ($row['is_watcher'] ?? 0) === 1)) {
                $groups[$id] = ['id' => $id, 'name' => (string) (($row['completename'] ?? '') ?: ($row['name'] ?? ''))];
            }
        }

        return array_values($groups);
    }

    /** Use the effective profile rights from GLPI's authenticated session, not profile names or IDs. */
    private static function activeRole(): string
    {
        $profile = $_SESSION['glpiactiveprofile'] ?? [];
        if ($profile === []) {
            return 'self_service';
        }
        $rights = (int) ($profile['ticket'] ?? 0);
        if (($profile['interface'] ?? '') === 'helpdesk') {
            return 'self_service';
        }
        // GLPI's stock Technician also has READALL. OWN without ASSIGN is the
        // distinguishing capability; newly created profiles follow the same rule.
        if (($rights & Ticket::READALL) && (($rights & Ticket::ASSIGN) || !($rights & Ticket::OWN))) {
            return 'global';
        }
        if ($rights & (Ticket::READASSIGN | Ticket::OWN)) {
            return 'technician';
        }
        if ($rights & Ticket::READGROUP) {
            // Some GLPI installations give a central Self-Service profile both
            // READMY and READGROUP. Keep that minimal combination personal;
            // a group-only profile can still use READGROUP without READMY.
            return ($rights & Ticket::READMY) ? 'self_service' : 'requester_group';
        }
        return ($rights & Ticket::READALL) ? 'global' : 'self_service';
    }

    private static function isRequester(int $ticketId, int $userId): bool
    {
        global $DB;
        return count($DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticketId, 'users_id' => $userId, 'type' => CommonITILActor::REQUESTER],
            'LIMIT' => 1,
        ])) > 0;
    }

    /** Apply the existing inbox role to the item's own group relations; GLPI checks read rights separately. */
    private static function roleCanSeeTicket(string $role, int $ticketId, int $userId, int $groupId, string $itemType = Ticket::class): bool
    {
        global $DB;
        if ($role === 'self_service') {
            return $itemType === Ticket::class && self::isRequester($ticketId, $userId);
        }
        if ($groupId <= 0 || !in_array($role, ['technician', 'requester_group'], true)) {
            return true;
        }
        $types = $role === 'technician'
            ? CommonITILActor::ASSIGN
            : [CommonITILActor::REQUESTER, CommonITILActor::OBSERVER];
        return count($DB->request([
            'SELECT' => ['groups_id'], 'FROM' => $itemType === Change::class ? 'glpi_changes_groups' : 'glpi_groups_tickets',
            'WHERE' => [($itemType === Change::class ? 'changes_id' : 'tickets_id') => $ticketId, 'groups_id' => $groupId, 'type' => $types],
            'LIMIT' => 1,
        ])) > 0;
    }

    private static function canShowPersonalNotice(string $role, array $row, int $userId, ?array &$scopeCache = null): bool
    {
        // Reject retained requester arrivals as well as newly generated ones, including badge/actions.
        if (($row['category'] ?? '') === 'incoming') {
            return false;
        }
        $ticketId = (int) $row['tickets_id'];
        if ($role === 'self_service') {
            $key = 'self:' . $ticketId;
            if ($scopeCache !== null && array_key_exists($key, $scopeCache)) {
                return $scopeCache[$key];
            }
            $allowed = self::isRequester($ticketId, $userId);
            if ($scopeCache !== null) {
                $scopeCache[$key] = $allowed;
            }
            return $allowed;
        }
        if (($row['category'] ?? '') !== 'mentions') {
            return false;
        }
        if (!in_array($role, ['technician', 'requester_group'], true)) {
            return true;
        }
        $key = $role . ':' . $ticketId;
        if ($scopeCache !== null && array_key_exists($key, $scopeCache)) {
            return $scopeCache[$key];
        }
        foreach (self::groupsForUser($userId) as $group) {
            if (self::roleCanSeeTicket($role, $ticketId, $userId, $group['id'])) {
                if ($scopeCache !== null) {
                    $scopeCache[$key] = true;
                }
                return true;
            }
        }
        if ($scopeCache !== null) {
            $scopeCache[$key] = false;
        }
        return false;
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
    /** Share arrivals and let callers choose requester delivery for other Ticket events. */
    private static function publish(
        Ticket|Change $ticket,
        string $sourceType,
        int $sourceId,
        string $eventKey,
        string $category,
        string $title,
        string $body,
        array $groupIds,
        bool $includeGlobal = true,
        array $excludeUserIds = [],
        bool $includeRequesters = true
    ): int {
        global $DB;

        $ticketId = (int) $ticket->getID();
        if ($ticketId <= 0) {
            return 0;
        }

        $sent = 0;
        foreach (array_unique(array_map('intval', $includeGlobal ? array_merge($groupIds, [self::GLOBAL_INBOX_ID]) : $groupIds)) as $groupId) {
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

                // Direct Change sources carry their ID; tickets_id=0 prevents Ticket ID collisions.
                $saved = $DB->insert(self::TABLE, [
                    'groups_id'       => $groupId,
                    'tickets_id'      => $ticket instanceof Ticket ? $ticketId : 0,
                    'source_itemtype' => $sourceType,
                    'source_items_id' => $sourceId,
                    'event_key'       => $eventKey,
                    'category'        => $category,
                    'is_incoming'     => (int) ($category === 'incoming'),
                    'title'           => $title,
                    'body'            => $body,
                    'date_creation'   => date('Y-m-d H:i:s'),
                ]);
                if ($saved === false) {
                    throw new RuntimeException('Notification insert failed');
                }
                PluginNotificationbellRealtime::changed('g_' . $groupId);
                $sent++;
            } catch (Throwable $error) {
                // A duplicate or failed insert for one group must not starve the others.
                self::logSaveFailure($error);
            }
        }
        if ($includeRequesters && $ticket instanceof Ticket && $category !== 'incoming') {
            self::publishToRequesters($ticketId, $sourceType, $sourceId, $eventKey, $category, $title, $body, $excludeUserIds);
        }
        return $sent;
    }

    private static function publishToRequesters(int $ticketId, string $sourceType, int $sourceId, string $eventKey, string $category, string $title, string $body, array $excludeUserIds = []): void
    {
        global $DB;
        if (!$DB->tableExists(self::USER_TABLE)) {
            return;
        }
        try {
            foreach ($DB->request([
                'SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users',
                'WHERE' => ['tickets_id' => $ticketId, 'type' => CommonITILActor::REQUESTER],
            ]) as $requester) {
                $userId = (int) $requester['users_id'];
                if ($userId <= 0 || in_array($userId, $excludeUserIds, true) || count($DB->request([
                    'SELECT' => ['id'], 'FROM' => self::USER_TABLE,
                    'WHERE' => ['users_id' => $userId, 'event_key' => $eventKey], 'LIMIT' => 1,
                ])) > 0) {
                    continue;
                }
                $saved = $DB->insert(self::USER_TABLE, [
                    'users_id' => $userId, 'tickets_id' => $ticketId,
                    'source_itemtype' => $sourceType, 'source_items_id' => $sourceId,
                    'event_key' => $eventKey, 'category' => $category,
                    'title' => $title, 'body' => $body, 'date_creation' => date('Y-m-d H:i:s'),
                ]);
                if ($saved === false) {
                    throw new RuntimeException('Requester notification insert failed');
                }
                PluginNotificationbellRealtime::changed('u_' . $userId);
            }
        } catch (Throwable $error) {
            self::logSaveFailure($error);
        }
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
     * Read requester, observer and assigned groups from the object's native actor table.
     *
     * @return int[]
     */
    private static function involvedGroups(Ticket|Change $ticket): array
    {
        global $DB;

        $groups = [];
        foreach ($DB->request([
            'SELECT' => ['groups_id'],
            'FROM' => $ticket instanceof Change ? 'glpi_changes_groups' : 'glpi_groups_tickets',
            'WHERE' => [($ticket instanceof Change ? 'changes_id' : 'tickets_id') => (int) $ticket->getID()],
        ]) as $row) {
            $id = (int) $row['groups_id'];
            if ($id > 0) {
                $groups[$id] = true;
            }
        }
        return array_map('intval', array_keys($groups));
    }

}
