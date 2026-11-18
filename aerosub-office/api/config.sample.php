<?php
// Copy this file to config.php and fill in your values. NEVER commit config.php.
return [
    'db_host' => 'localhost',
    'db_name' => 'CPANELUSER_office',
    'db_user' => 'CPANELUSER_officeuser',
    'db_pass' => 'CHANGE_ME',

    'app_name' => 'Aerosub Office',
    'timezone' => 'Africa/Lagos',

    // Random string used to protect cron.php when it is called by URL. Generate a long one.
    'cron_key' => 'CHANGE_ME_LONG_RANDOM',

    // Secret used to encrypt staff mailbox passwords stored for the in-app email. Make it long and random,
    // and do not change it later (people would have to reconnect their mailboxes).
    'mail_key' => 'CHANGE_ME_MAIL_KEY',

    // Public address of this site (used for unsubscribe links and images in marketing emails).
    'app_url' => 'https://office.aerosub.co',

    // The one person allowed into the Agents area (/agent). Must be a Super Admin account.
    // They sign in to the office app as usual (see agent_require_code below for an optional extra code).
    'agent_owner_email' => 'CHANGE_ME_OWNER_EMAIL',

    // Extra authenticator-app code before the Agents area opens. OFF by default; set true to require it (the Personal agent will want it).
    // See docs/security/open-items.md in the project notes before changing this.
    'agent_require_code' => false,

    // Largest document (PDF or Word) the team may upload for checking, in MB. Never more than the server allows (see limits.php).
    'agent_doc_max_mb' => 8,

    // First Super Admin, created by install.php (temporary — change after first login).
    'admin_name'  => 'Aerosub Admin',
    'admin_email' => 'dev.aerosubco@gmail.com',
    'admin_pass'  => 'CHANGE_ME_TEMPORARY',
];
