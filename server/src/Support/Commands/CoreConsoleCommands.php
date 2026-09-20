<?php

namespace Fleetbase\Ai\Support\Commands;

use Fleetbase\Ai\Support\AiAudience;

/**
 * Console commands for the core console and the built-in IAM and Developers engines, which have no
 * server package of their own.
 */
class CoreConsoleCommands
{
    public const IAM_ENGINE = '@fleetbase/iam-engine';

    public const DEV_ENGINE = '@fleetbase/dev-engine';

    protected const DOCS = 'https://fleetbase.io/docs/platform';

    /**
     * @return AiConsoleCommand[]
     */
    public static function all(): array
    {
        return array_merge(static::iam(), static::developers(), static::console(), static::admin());
    }

    public static function iam(): array
    {
        $commands  = [];
        // The fifth element is the dialog's own title in the console, so the confirmation card
        // and the answer quote the label the user actually sees.
        $resources = [
            'users'    => ['user', 'Users', 'New User', 'user-actions', 'identity-and-access/users', ['member', 'team member', 'staff', 'account', 'login']],
            'groups'   => ['group', 'Groups', 'New Group', 'group-actions', 'identity-and-access/groups', ['team']],
            'roles'    => ['role', 'Roles', 'New Role', 'role-actions', 'identity-and-access/roles-and-permissions', ['permission', 'access']],
            'policies' => ['policy', 'Policies', 'New Policy', 'policy-actions', 'identity-and-access/policies', ['permission', 'access']],
        ];

        foreach ($resources as $route => [$resource, $plural, $dialog, $service, $docs, $keywords]) {
            $commands[] = AiConsoleCommand::navigate("iam.{$route}.open", "Open {$plural}", "IAM › {$plural}", "Go to the {$plural} list in IAM.", "console.iam.{$route}.index", [
                'permissions' => ["iam list {$resource}"],
                'keywords'    => array_merge(['iam', 'view', 'list', 'manage'], $keywords),
                'docs_url'    => static::DOCS . "/{$docs}",
                'module'      => 'iam',
            ]);

            $commands[] = AiConsoleCommand::dialog("iam.{$route}.create", $dialog, "IAM › {$plural}", "Go to {$plural} in IAM and click New to open the {$dialog} form.", "console.iam.{$route}.index", static::IAM_ENGINE, $service, 'modal.create', [
                'permissions' => ["iam create {$resource}"],
                'keywords'    => array_merge(['add', 'new', 'create'], $keywords),
                'docs_url'    => static::DOCS . "/{$docs}",
                'module'      => 'iam',
            ]);
        }

        $commands[] = AiConsoleCommand::dialog('iam.users.invite', 'Invite User', 'IAM › Users', 'Go to Users in IAM and click Invite User, which emails an invitation.', 'console.iam.users.index', static::IAM_ENGINE, 'user-actions', 'modal.invite', [
            'permissions' => ['iam create user'],
            'keywords'    => ['invite', 'invitation', 'email', 'add', 'team member'],
            'docs_url'    => static::DOCS . '/identity-and-access/users#inviting-a-user',
            'module'      => 'iam',
        ]);

        return $commands;
    }

    public static function developers(): array
    {
        return [
            AiConsoleCommand::navigate('developers.api_keys.open', 'Open API keys', 'Developers › API Keys', 'Go to the organization API keys in the Developers console.', 'console.developers.api-keys.index', [
                'permissions' => ['developers list api-key'],
                'keywords'    => ['api', 'key', 'token', 'secret', 'integration'],
                'docs_url'    => static::DOCS . '/developer-console/api-keys',
                'module'      => 'developers',
            ]),
            AiConsoleCommand::dialog('developers.api_keys.create', 'New API Key', 'Developers › API Keys', 'Go to API Keys in the Developers console and click New to open the New API Key form.', 'console.developers.api-keys.index', static::DEV_ENGINE, 'api-key-actions', 'modal.create', [
                'permissions' => ['developers create api-key'],
                'keywords'    => ['api', 'key', 'new', 'generate', 'integration'],
                'docs_url'    => static::DOCS . '/developer-console/api-keys',
                'module'      => 'developers',
            ]),
            AiConsoleCommand::navigate('developers.webhooks.open', 'Open webhooks', 'Developers › Webhooks', 'Go to the webhook endpoints in the Developers console.', 'console.developers.webhooks.index', [
                'permissions' => ['developers list webhook'],
                'keywords'    => ['webhook', 'endpoint', 'callback', 'integration', 'event'],
                'docs_url'    => static::DOCS . '/developer-console/webhooks',
                'module'      => 'developers',
            ]),
            AiConsoleCommand::dialog('developers.webhooks.create', 'New Webhook', 'Developers › Webhooks', 'Go to Webhooks in the Developers console and click New to open the New Webhook form.', 'console.developers.webhooks.index', static::DEV_ENGINE, 'webhook-actions', 'modal.create', [
                'permissions' => ['developers create webhook'],
                'keywords'    => ['webhook', 'endpoint', 'new', 'add', 'callback', 'integration'],
                'docs_url'    => static::DOCS . '/developer-console/webhooks',
                'module'      => 'developers',
            ]),
            AiConsoleCommand::navigate('developers.events.open', 'Open events', 'Developers › Events', 'Go to the system event log in the Developers console.', 'console.developers.events.index', [
                'permissions' => ['developers list event'],
                'keywords'    => ['event', 'activity'],
                'docs_url'    => static::DOCS . '/developer-console/system-events',
                'module'      => 'developers',
            ]),
            AiConsoleCommand::navigate('developers.logs.open', 'Open request logs', 'Developers › Logs', 'Go to the API request logs in the Developers console.', 'console.developers.logs.index', [
                'permissions' => ['developers list log'],
                'keywords'    => ['log', 'request', 'api', 'error', 'debug'],
                'docs_url'    => static::DOCS . '/developer-console/request-logs',
                'module'      => 'developers',
            ]),
        ];
    }

    public static function console(): array
    {
        return [
            AiConsoleCommand::navigate('settings.organization.open', 'Open organization settings', 'Settings › Organization', 'Go to the organization settings: name, description, currency, and other organization-wide options.', 'console.settings.index', [
                'keywords' => ['organization', 'company', 'currency', 'settings', 'name', 'timezone'],
                'module'   => 'console',
            ]),
            AiConsoleCommand::navigate('settings.notifications.open', 'Open notification settings', 'Settings › Notifications', 'Go to the organization notification settings.', 'console.settings.notifications', [
                'keywords' => ['notification', 'alert', 'email', 'sms'],
                'docs_url' => static::DOCS . '/console-features/notifications',
                'module'   => 'console',
            ]),
            AiConsoleCommand::navigate('settings.two_fa.open', 'Open two-factor settings', 'Settings › Two-Factor Authentication', 'Go to the organization two-factor authentication settings.', 'console.settings.two-fa', [
                'keywords' => ['2fa', 'two factor', 'security', 'mfa'],
                'docs_url' => static::DOCS . '/identity-and-access/two-factor-authentication',
                'module'   => 'console',
            ]),
            AiConsoleCommand::navigate('account.profile.open', 'Open your profile', 'Account › Profile', 'Go to your own account profile.', 'console.account.index', [
                'keywords' => ['profile', 'account', 'password', 'avatar', 'my'],
                'module'   => 'console',
            ]),
            AiConsoleCommand::navigate('account.organizations.open', 'Open your organizations', 'Account › Organizations', 'Go to the organizations you belong to, where you can switch, leave, or edit them.', 'console.account.organizations', [
                'keywords' => ['organization', 'switch', 'leave', 'membership'],
                'docs_url' => static::DOCS . '/identity-and-access/organizations#user-view-your-organizations',
                'module'   => 'console',
            ]),
            AiConsoleCommand::navigate('extensions.explore.open', 'Browse extensions', 'Extensions › Explore', 'Go to the extensions marketplace to browse and install extensions.', 'console.extensions.explore.index', [
                'keywords' => ['extension', 'marketplace', 'install', 'plugin', 'app', 'module'],
                'docs_url' => static::DOCS . '/extensions/browsing-and-installing',
                'module'   => 'console',
            ]),
            AiConsoleCommand::navigate('extensions.installed.open', 'Open installed extensions', 'Extensions › Installed', 'Go to the extensions installed for your organization.', 'console.extensions.installed', [
                'keywords' => ['extension', 'installed', 'uninstall', 'manage'],
                'docs_url' => static::DOCS . '/extensions/managing-extensions',
                'module'   => 'console',
            ]),
        ];
    }

    /**
     * System administration commands, only ever offered to users whose type is admin.
     */
    public static function admin(): array
    {
        $options = fn (array $keywords, ?string $docs = null) => [
            'audience' => AiAudience::SYSTEM_ADMIN,
            'keywords' => array_merge(['admin', 'system'], $keywords),
            'docs_url' => $docs ? static::DOCS . "/system-setup/{$docs}" : null,
            'module'   => 'admin',
        ];

        return [
            AiConsoleCommand::navigate('admin.config.services.open', 'Open service settings', 'Admin › Config › Services', 'Go to the system service credentials: Google Maps, AWS, Twilio, Sentry, and IP geolocation.', 'console.admin.config.services', $options(['service', 'google maps', 'api key', 'aws', 'twilio', 'credential', 'geocoding'], 'services')),
            AiConsoleCommand::navigate('admin.config.mail.open', 'Open mail settings', 'Admin › Config › Mail', 'Go to the system mail server settings.', 'console.admin.config.mail', $options(['mail', 'email', 'smtp', 'ses', 'mailgun'], 'mail')),
            AiConsoleCommand::navigate('admin.config.filesystem.open', 'Open filesystem settings', 'Admin › Config › Filesystem', 'Go to the system file storage settings.', 'console.admin.config.filesystem', $options(['filesystem', 'storage', 's3', 'upload'], 'filesystem')),
            AiConsoleCommand::navigate('admin.config.queue.open', 'Open queue settings', 'Admin › Config › Queue', 'Go to the system queue settings.', 'console.admin.config.queue', $options(['queue', 'jobs', 'sqs', 'redis'], 'queue')),
            AiConsoleCommand::navigate('admin.config.socket.open', 'Open socket settings', 'Admin › Config › Socket', 'Go to the system real-time socket settings.', 'console.admin.config.socket', $options(['socket', 'socketcluster', 'realtime'], 'socket')),
            AiConsoleCommand::navigate('admin.config.push_notifications.open', 'Open push notification settings', 'Admin › Config › Push Notifications', 'Go to the system push notification channels (APNs and FCM).', 'console.admin.config.notification-channels', $options(['push', 'notification', 'apns', 'fcm', 'firebase'], 'push-notifications')),
            AiConsoleCommand::navigate('admin.branding.open', 'Open branding', 'Admin › Branding', 'Go to the system branding settings: logo, icon, and theme.', 'console.admin.branding', $options(['branding', 'logo', 'icon', 'theme', 'white label'], 'branding')),
            AiConsoleCommand::navigate('admin.two_fa.open', 'Open system two-factor settings', 'Admin › Two-Factor Authentication', 'Go to the system-wide two-factor authentication enforcement settings.', 'console.admin.two-fa-settings', $options(['2fa', 'two factor', 'enforce'], 'two-factor-authentication')),
            AiConsoleCommand::navigate('admin.organizations.open', 'Open all organizations', 'Admin › Organizations', 'Go to the list of every organization on this Fleetbase instance.', 'console.admin.organizations.index', $options(['organization', 'tenant', 'companies'])),
        ];
    }
}
