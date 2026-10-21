<?php

require __DIR__ . '/bootstrap.php';

// Keep the fake database local to this executable test harness.
$DB = new FakeDB();
$CFG_GLPI = ['root_doc' => '/glpi'];
require dirname(__DIR__) . '/inc/notification.class.php';

// Hook events must deduplicate assignments and exclude private notes.
$ticket = new Ticket();
$ticket->fields = ['id' => 42, 'name' => 'Prueba <b>de red</b>', 'status' => 1];
Ticket::$records[42] = $ticket->fields;
$assignment = new Group_Ticket();
$assignment->fields = ['id' => 90, 'tickets_id' => 42, 'groups_id' => 9, 'type' => CommonITILActor::ASSIGN];
PluginNotificationbellNotification::onGroupAssigned($assignment);
PluginNotificationbellNotification::onGroupAssigned($assignment);
expect(count($DB->notifications) === 1, 'Assignment must create one notice for its group.');
$assignment->fields = ['id' => 91, 'tickets_id' => 42, 'groups_id' => 11, 'type' => CommonITILActor::ASSIGN];
PluginNotificationbellNotification::onGroupAssigned($assignment);
$assignment->fields = ['id' => 92, 'tickets_id' => 42, 'groups_id' => 12, 'type' => CommonITILActor::REQUESTER];
PluginNotificationbellNotification::onGroupAssigned($assignment);
expect(count($DB->notifications) === 2, 'Requester groups must not receive assignment notices.');

$followup = new ITILFollowup();
$followup->fields = ['id' => 8, 'itemtype' => Ticket::class, 'items_id' => 42, 'content' => '<p>Revisar enlace</p>', 'is_private' => 0];
PluginNotificationbellNotification::onFollowupAdded($followup);
expect(count($DB->notifications) === 5, 'Public response must reach all three involved groups.');
expect($DB->notifications[2]['category'] === 'responses', 'A public reply belongs in Respuestas.');
$followup->fields['id'] = 9;
$followup->fields['is_private'] = 1;
PluginNotificationbellNotification::onFollowupAdded($followup);
expect(count($DB->notifications) === 5, 'Private note must not create a notice.');

$ticket->updates = ['status'];
$ticket->oldvalues = ['status' => 1];
$ticket->fields['status'] = Ticket::SOLVED;
PluginNotificationbellNotification::onTicketUpdated($ticket);
expect(count($DB->notifications) === 7, 'Resolved ticket must reach both assigned groups.');

$solution = new ITILSolution();
$solution->fields = ['id' => 7, 'itemtype' => Ticket::class, 'items_id' => 42, 'status' => CommonITILValidation::REFUSED];
$solution->updates = ['status'];
PluginNotificationbellNotification::onSolutionUpdated($solution);
expect(count($DB->notifications) === 9, 'Rejected solution must reach both assigned groups.');

// Membership and ticket rights independently gate reads and mutations.
$feed = PluginNotificationbellNotification::feed(10, 9);
expect($feed['unread'] === 4 && count($feed['groups']) === 2, 'A member must see their selected group inbox.');
expect($feed['notifications'][0]['url'] === '/glpi/front/ticket.form.php?id=42', 'Notices must link to the GLPI ticket.');
expect($feed['notifications'][0]['title'] === 'Solution rejected · Ticket #42', 'Titles must use the viewer language.');
$DB->notifications[0]['title'] = 'Ticket #42 asignado al grupo';
$englishLegacy = PluginNotificationbellNotification::feed(10, 9);
expect($englishLegacy['notifications'][3]['title'] === 'Ticket #42 assigned to group', 'A legacy Spanish title must display in English.');
$testLanguage = 'es';
$translatedFeed = PluginNotificationbellNotification::feed(10, 9);
expect($translatedFeed['notifications'][0]['title'] === 'Solución rechazada · Ticket #42', 'An existing notice must translate when the viewer language changes.');
expect($translatedFeed['i18n']['notifications'] === 'Notificaciones', 'The interface dictionary must use GLPI translations.');
$testLanguage = 'en';
expect(PluginNotificationbellNotification::feed(20, 9)['unread'] === 4, 'Other members must see the same inbox.');
$DB->insert(PluginNotificationbellNotification::TABLE, [
    'groups_id' => 9, 'tickets_id' => 43, 'event_key' => 'hidden-ticket',
    'category' => 'other', 'title' => 'Oculto', 'body' => '', 'date_creation' => date('Y-m-d H:i:s'),
]);
$hiddenId = $DB->notifications[array_key_last($DB->notifications)]['id'];
expect(PluginNotificationbellNotification::feed(10, 9)['unread'] === 4, 'Ticket permissions must hide inaccessible notices.');
$denied = false;
try { PluginNotificationbellNotification::markRead(10, 9, $hiddenId); } catch (RuntimeException $error) { $denied = true; }
expect($denied, 'A member must not change an inaccessible notice.');
PluginNotificationbellNotification::markRead(10, 9, $feed['notifications'][0]['id']);
expect(PluginNotificationbellNotification::feed(20, 9)['unread'] === 3, 'Read state must be shared by group.');
PluginNotificationbellNotification::markAllRead(20, 9);
expect(PluginNotificationbellNotification::feed(10, 9)['unread'] === 0, 'Mark all must update the shared group inbox.');
expect(PluginNotificationbellNotification::feed(30, 11)['unread'] === 4, 'Other groups must retain unread notices.');
PluginNotificationbellNotification::clear(20, 9);
expect(PluginNotificationbellNotification::feed(10, 9)['notifications'] === [], 'Clear must remove only the selected group history.');
expect(count(array_filter($DB->notifications, static fn(array $row): bool => $row['tickets_id'] === 43)) === 1, 'Clear must preserve inaccessible notices.');
expect(count(PluginNotificationbellNotification::feed(30, 11)['notifications']) === 4, 'Other groups must retain their history.');

$denied = false;
try { PluginNotificationbellNotification::feed(20, 11); } catch (RuntimeException $error) { $denied = true; }
expect($denied, 'A user must not access another group inbox.');
$denied = false;
try { PluginNotificationbellNotification::clear(30, 9); } catch (RuntimeException $error) { $denied = true; }
expect($denied, 'A user must not clear another group inbox.');

// Hourly cron runs must honor the per-group, 24-hour reminder interval.
$ticket->fields['status'] = Ticket::ASSIGNED;
$ticket->fields['date_mod'] = date('Y-m-d H:i:s', time() - 90000);
$ticket->fields['is_deleted'] = 0;
Ticket::$records[42] = $ticket->fields;
$task = new CronTask();
expect(PluginNotificationbellNotification::cronAttentionReminders($task) === 1, 'An old active ticket must get a reminder.');
expect($task->volume === 2, 'Only assigned groups receive attention reminders.');
$reminder = array_values(array_filter($DB->notifications, static fn(array $row): bool => ($row['source_itemtype'] ?? '') === PluginNotificationbellNotification::class))[0];
expect(str_contains($reminder['event_key'], ':active:'), 'Reminder keys must preserve the ticket state for translation.');
expect(PluginNotificationbellNotification::feed(30, 11)['notifications'][0]['title'] === 'Ticket #42 active for more than 24 hours', 'Reminder titles must use the viewer language.');
expect(PluginNotificationbellNotification::cronAttentionReminders($task) === 0, 'A reminder must not repeat before 24 hours.');
foreach ($DB->notifications as &$row) {
    if (($row['source_itemtype'] ?? '') === PluginNotificationbellNotification::class) {
        $row['date_creation'] = date('Y-m-d H:i:s', time() - 90000);
    }
}
unset($row);
expect(PluginNotificationbellNotification::cronAttentionReminders($task) === 1, 'The reminder may repeat after 24 hours.');
$ticket->fields['status'] = Ticket::SOLVED;
Ticket::$records[42] = $ticket->fields;
expect(PluginNotificationbellNotification::cronAttentionReminders($task) === 0, 'Resolved tickets must stop receiving reminders.');

// Privacy changes and deletion must remove already shared reply excerpts.
$followup->fields['id'] = 8;
$followup->fields['is_private'] = 1;
$followup->updates = ['is_private'];
PluginNotificationbellNotification::onFollowupUpdated($followup);
expect(count(array_filter($DB->notifications, static fn(array $row): bool => ($row['source_itemtype'] ?? '') === ITILFollowup::class && ($row['source_items_id'] ?? 0) === 8)) === 0, 'Private reply must leave no shared excerpts.');
$followup->fields['id'] = 10;
$followup->fields['is_private'] = 0;
PluginNotificationbellNotification::onFollowupAdded($followup);
PluginNotificationbellNotification::onFollowupDeleted($followup);
expect(count(array_filter($DB->notifications, static fn(array $row): bool => ($row['source_itemtype'] ?? '') === ITILFollowup::class && ($row['source_items_id'] ?? 0) === 10)) === 0, 'Deleted reply must leave no shared excerpts.');

// The unread badge must include accessible notices beyond the 1000-row history page.
$DB = new FakeDB();
for ($id = 0; $id < 1001; $id++) {
    $DB->insert(PluginNotificationbellNotification::TABLE, [
        'groups_id' => 9, 'tickets_id' => 42, 'event_key' => "older-$id",
        'category' => 'other', 'title' => 'Anterior', 'body' => '', 'date_creation' => date('Y-m-d H:i:s'),
    ]);
}
$feed = PluginNotificationbellNotification::feed(10, 9);
expect(count($feed['notifications']) === 1000 && $feed['unread'] === 1001, 'Unread count must include notices beyond the feed limit.');
$preview = PluginNotificationbellNotification::feed(10, 9, true);
expect(count($preview['notifications']) === 5 && $preview['unread'] === 1001, 'Polling must return only five notices without truncating the unread badge.');
PluginNotificationbellNotification::markAllRead(10, 9);
expect(PluginNotificationbellNotification::feed(10, 9)['unread'] === 0, 'Mark all must clear the complete unread count.');

echo "Notification Bell group routing and shared inbox test passed.\n";
