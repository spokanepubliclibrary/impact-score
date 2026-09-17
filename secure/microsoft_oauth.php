<?php
/**
 * Microsoft OAuth Configuration
 *
 * Register your app at https://portal.azure.com → Microsoft Entra ID → App registrations.
 * See AZURE_SETUP.md for step-by-step instructions.
 *
 * IMPORTANT: This file contains secrets. Never commit it to version control.
 * The Dockerfile should inject this file at build time, similar to db_connection.php.
 */
return [
    // Azure Application (client) ID — from App registrations overview
    'client_id'     => 'YOUR_CLIENT_ID',

    // Client secret value — from Certificates & secrets (not the secret ID, the Value)
    'client_secret' => 'YOUR_CLIENT_SECRET',

    // Directory (tenant) ID — use your tenant ID for single-org, or 'common' for multi-tenant
    'tenant_id'     => 'YOUR_TENANT_ID',

    // Must exactly match the Redirect URI registered in Azure
    'redirect_uri'  => 'https://yourdomain.com/oauth/microsoft_callback.php',
];
