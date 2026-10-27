<?php

/** GLPI entry points for the notification bell plugin. */

use Glpi\Plugin\Hooks;

define('PLUGIN_NOTIFICATIONBELL_VERSION', '1.1.0');

/** Register assets and event hooks only when the plugin is active. */
function plugin_init_notificationbell(): void
{
    global $PLUGIN_HOOKS;

    // GLPI 12 removed the legacy token-based CSRF hook.
    if (defined(Hooks::class . '::CSRF_COMPLIANT')) {
        $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['notificationbell'] = true;
    }

    if (!Plugin::isPluginActive('notificationbell')) {
        return;
    }

    require_once __DIR__ . '/inc/notification.class.php';

    foreach (plugin_notificationbell_palette_stylesheets(GLPI_VERSION) as $stylesheet) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['notificationbell'][] = $stylesheet;
    }
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['notificationbell'][] = 'js/bell-live10.js';

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][Ticket::class] = [PluginNotificationbellNotification::class, 'onTicketAdded'];
    foreach ([TicketTask::class, ITILSolution::class, TicketValidation::class, Ticket_User::class, Document_Item::class] as $type) {
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][$type] = [PluginNotificationbellNotification::class, 'onActivity'];
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][$type] = [PluginNotificationbellNotification::class, 'onActivity'];
        $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['notificationbell'][$type] = [PluginNotificationbellNotification::class, 'onActivityDeleted'];
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['notificationbell'][$type] = [PluginNotificationbellNotification::class, 'onActivityDeleted'];
    }
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][Group_Ticket::class] = [PluginNotificationbellNotification::class, 'onActivity'];
    $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['notificationbell'][Group_Ticket::class] = [PluginNotificationbellNotification::class, 'onActivityDeleted'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['notificationbell'][Group_Ticket::class] = [PluginNotificationbellNotification::class, 'onActivityDeleted'];
    $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['notificationbell'][Ticket::class] = [PluginNotificationbellNotification::class, 'onActivityDeleted'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['notificationbell'][Ticket::class] = [PluginNotificationbellNotification::class, 'onActivityDeleted'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['notificationbell'][ITILFollowup::class] = [PluginNotificationbellNotification::class, 'onFollowupDeleted'];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][Group_Ticket::class] = [PluginNotificationbellNotification::class, 'onGroupAssigned'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][Ticket::class] = [PluginNotificationbellNotification::class, 'onTicketUpdated'];
    // Native pre/post hooks receive the same object; each actual update gets a fresh identity.
    foreach ([Ticket::class, Change::class] as $type) {
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['notificationbell'][$type] = [PluginNotificationbellNotification::class, 'onBeforeItemUpdate'];
    }
    // Changes use their own lifecycle and actor hooks, independently of Ticket IDs.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][Change::class] = [PluginNotificationbellNotification::class, 'onChangeAdded'];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notificationbell'][Change_Group::class] = [PluginNotificationbellNotification::class, 'onChangeGroupAssigned'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notificationbell'][Change::class] = [PluginNotificationbellNotification::class, 'onChangeUpdated'];
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
            'glpi' => ['min' => '11.0.0', 'max' => '12.0.1'],
            'php'  => ['min' => version_compare(GLPI_VERSION, '12.0.0', '>=') ? '8.3' : '8.2'],
        ],
    ];
}

/** The metadata cannot express the gap between the supported release lines. */
function plugin_notificationbell_check_prerequisites(): bool
{
    return plugin_notificationbell_supports_version(GLPI_VERSION, PHP_VERSION_ID);
}

function plugin_notificationbell_supports_version(string $glpiVersion, int $phpVersionId): bool
{
    return (version_compare($glpiVersion, '11.0.0', '>=')
            && version_compare($glpiVersion, '11.0.12', '<')
            && $phpVersionId >= 80200)
        || (version_compare($glpiVersion, '12.0.0', '>=')
            && version_compare($glpiVersion, '12.0.1', '<')
            && $phpVersionId >= 80300);
}

/** GLPI 11's dark palette needs an extra surface token for the notification UI. */
function plugin_notificationbell_palette_stylesheets(string $glpiVersion): array
{
    $stylesheets = ['css/bell-palette.css'];
    if (version_compare($glpiVersion, '12.0.0', '<')) {
        $stylesheets[] = 'css/bell-palette-glpi11.css';
    }
    return $stylesheets;
}
