<?php

require_once __DIR__ . '/inc/notification.class.php';

/** Create the shared inbox with a unique event key and register hourly reminders. */
function plugin_notificationbell_install(): bool
{
    global $DB;

    if (!$DB->tableExists(PluginNotificationbellNotification::TABLE) && !$DB->doQuery(
        'CREATE TABLE `glpi_plugin_notificationbell_group_notifications` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `groups_id` INT UNSIGNED NOT NULL,
            `tickets_id` INT UNSIGNED NOT NULL,
            `source_itemtype` VARCHAR(40) NOT NULL,
            `source_items_id` INT UNSIGNED NOT NULL,
            `event_key` VARCHAR(160) NOT NULL,
            `category` VARCHAR(20) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `body` TEXT NOT NULL,
            `date_creation` TIMESTAMP NOT NULL,
            `date_read` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `group_event` (`groups_id`, `event_key`),
            KEY `group_date` (`groups_id`, `date_creation`),
            KEY `ticket` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    )) {
        return false;
    }

    $task = new CronTask();
    if (!$task->getFromDBbyName(PluginNotificationbellNotification::class, 'AttentionReminders')) {
        return CronTask::register(
            PluginNotificationbellNotification::class,
            'AttentionReminders',
            HOUR_TIMESTAMP,
            ['mode' => CronTask::MODE_EXTERNAL, 'state' => CronTask::STATE_WAITING]
        );
    }

    return true;
}

/** Remove both the current table and the legacy per-user table on uninstall. */
function plugin_notificationbell_uninstall(): bool
{
    global $DB;

    foreach ([PluginNotificationbellNotification::TABLE, 'glpi_plugin_notificationbell_notifications'] as $table) {
        if ($DB->tableExists($table) && !$DB->doQuery('DROP TABLE `' . $table . '`')) {
            return false;
        }
    }

    return true;
}
