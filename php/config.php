<?php
/**
 * Configuration File
 * SENSITIVE: These values must never be exposed to the client
 */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// ============================================================
// LiteLLM API Configuration
// ============================================================
define('LITELLM_API_URL', 'https://'.getenv('LITELLM_DOMAIN'));
define('LITELLM_AUTH_HEADER', 'Bearer '.getenv('LITELLMMANAGER_AUTH_HEADER'));

// Set to true to disable SSL certificate verification for LiteLLM connection
// WARNING: Only disable in development/trusted internal networks
// This should keep all communication inside the docker container and all certs are on NGINX
define('LITELLM_VERIFY_SSL', false);

// ============================================================
// Authentication Mode: 'local' or 'oauth'
// ============================================================
define('AUTH_MODE', getenv('LITELLMMANAGER_AUTH_MODE')); // Change to 'oauth' to enable Azure AD

// ============================================================
// Local Authentication (used when AUTH_MODE = 'local')
// Hard-coded admin credentials - treated as ADMIN role
// ============================================================
define('LOCAL_USERNAME', getenv('LITELLMMANAGER_ADMIN_USER'));
define('LOCAL_PASSWORD', getenv('LITELLMMANAGER_ADMIN_PASSWORD')); // Change this password

// ============================================================
// Azure AD OAuth Configuration (used when AUTH_MODE = 'oauth')
// ============================================================
define('MICROSOFT_CLIENT_ID',     getenv('MICROSOFT_CLIENT_ID'));
define('MICROSOFT_CLIENT_SECRET', getenv('MICROSOFT_CLIENT_SECRET'));
define('MICROSOFT_TENANT_ID',     getenv('MICROSOFT_TENANT_ID'));

// Azure AD Group/Role Object IDs
define('ADMIN_USER_ROLE',    getenv('LITELLMMANAGER_ADMIN_ROLE')); // e.g. Azure AD Group Object ID for Admins
define('HELPDESK_USER_ROLE', getenv('LITELLMMANAGER_HELPDESK_ROLE')); // e.g. Azure AD Group Object ID for Helpdesk
define('USER_ROLE',          getenv('LITELLMMANAGER_USER_ROLE')); // e.g. Azure AD Group Object ID for Users

// OAuth Redirect URI - must match what is registered in Azure AD App Registration
define('OAUTH_REDIRECT_URI', getenv('LITELLMMANAGER_OAUTH_REDIRECT_URI'));

// ============================================================
// Session Configuration
// ============================================================
define('SESSION_LIFETIME', getenv('LITELLMMANAGER_SESSION_LIFETIME')); // 1 hour in seconds

// ============================================================
// Application Branding
// ============================================================
define('APP_LOGO_URL', getenv('LITELLMMANAGER_LOGO_URL'));
define('APP_NAME', 'LiteLLM User Management Portal');

