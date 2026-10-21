<?php

/** GLPI entry points for the notification bell plugin. */

use Glpi\Plugin\Hooks;

define('PLUGIN_NOTIFICATIONBELL_VERSION', '1.0.0');

/** Register assets and event hooks only when the plugin is active. */
function plugin_init_notificationbell(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['notificationbell'] = true;

    if (!Plugin::isPluginActive('notificationbell')) {
        return;
    }

    require_once __DIR__ . '/inc/notification.class.php';

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['notificationbell'][] = 'css/bell.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['notificationbell'][] = 'js/bell.v3.js';

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][Group_Ticket::class] = [PluginNotificationbellNotification::class, 'onGroupAssigned'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][Ticket::class] = [PluginNotificationbellNotification::class, 'onTicketUpdated'];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][ITILFollowup::class] = [PluginNotificationbellNotification::class, 'onFollowupAdded'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][ITILFollowup::class] = [PluginNotificationbellNotification::class, 'onFollowupUpdated'];
    $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['notificationbell'][ITILFollowup::class] = [PluginNotificationbellNotification::class, 'onFollowupDeleted'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][ITILSolution::class] = [PluginNotificationbellNotification::class, 'onSolutionUpdated'];
}

/** Advertise the runtime versions supported by this plugin. */
function plugin_version_notificationbell(): array
{
    return [
        'name'         => __('Notification bell', 'notificationbell'),
        'version'      => PLUGIN_NOTIFICATIONBELL_VERSION,
        'author'       => 'Kaztled',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '11.0.12'],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

/** Keep the installer check consistent with the declared GLPI version range. */
function plugin_notificationbell_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '11.0.0', '>=')
        && version_compare(GLPI_VERSION, '11.0.12', '<');
}
