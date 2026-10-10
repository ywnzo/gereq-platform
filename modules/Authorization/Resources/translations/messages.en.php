<?php

declare(strict_types=1);

return [
    'module.authorization.description' => 'Customer sign-in via OAuth plugins (Google, Facebook, GitHub…).',
    'nav.authorization' => 'Authorization',
    'palette.authorization' => 'OAuth providers',
    'perm.group.authorization' => 'Authorization',
    'perm.authorization.view' => 'View authorization',
    'perm.authorization.manage' => 'Manage authorization',
    'authorization.title' => 'Authorization',
    'authorization.lead' => 'Enable providers under Plugins and fill in Client ID / Secret. Copy the redirect URI into the provider console.',
    'authorization.plugins' => 'Plugins',
    'authorization.settings' => 'Settings',
    'authorization.empty' => 'No OAuth plugin is enabled.',
    'authorization.col.provider' => 'Provider',
    'authorization.col.plugin' => 'Plugin',
    'authorization.col.status' => 'Status',
    'authorization.col.callback' => 'Redirect URI',
    'authorization.status.ready' => 'Ready',
    'authorization.status.needs_keys' => 'Missing keys',
    'authorization.status.off' => 'Off',
    'authorization.customer.tab' => 'Social accounts',
    'authorization.customer.empty' => 'No Google / Facebook / GitHub account is linked. Sign-in with those providers will not work until the customer links them, even if the e-mail matches.',
    'authorization.customer.none' => 'Not linked',
];
