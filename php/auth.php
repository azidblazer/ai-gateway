<?php
/**
 * auth.php
 * Handles authentication for both local and Azure AD OAuth (App Roles) modes.
 * App Roles are read directly from the "roles" claim in the Azure AD ID token —
 * no Microsoft Graph API call required.
 */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Regenerate session ID on first visit to prevent fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// ============================================================
// Session & Role Helpers
// ============================================================

/**
 * Check whether the current session is authenticated and not expired.
 */
function isAuthenticated(): bool {
    return isset($_SESSION['authenticated'])
        && $_SESSION['authenticated'] === true
        && isset($_SESSION['expires_at'])
        && $_SESSION['expires_at'] > time();
}

/**
 * Return the current user's role string.
 */
function getRole(): string {
    return $_SESSION['role'] ?? '';
}

/**
 * True if the current user has the admin role.
 */
function canAdmin(): bool {
    return getRole() === 'admin';
}

/**
 * True if the current user has admin or helpdesk role.
 */
function canHelpdesk(): bool {
    return in_array(getRole(), ['admin', 'helpdesk'], true);
}

/**
 * True if the current user has any valid role.
 */
function canUser(): bool {
    return in_array(getRole(), ['admin', 'helpdesk', 'user'], true);
}

/**
 * Populate the session with user identity and role.
 */
function setAuthSession(string $username, string $role, string $displayName = ''): void {
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['username']      = $username;
    $_SESSION['display_name']  = $displayName ?: $username;
    $_SESSION['role']          = $role;
    $_SESSION['expires_at']    = time() + SESSION_LIFETIME;
}

/**
 * Return current session info as an array (used by the session_info API action).
 */
function getSessionInfo(): array {
    return [
        'authenticated' => isAuthenticated(),
        'username'      => $_SESSION['username']      ?? '',
        'display_name'  => $_SESSION['display_name']  ?? '',
        'role'          => getRole(),
        'can_admin'     => canAdmin(),
        'can_helpdesk'  => canHelpdesk(),
        'expires_at'    => $_SESSION['expires_at']    ?? 0,
    ];
}

/**
 * Sanitize a plain string (strip tags + trim).
 */
function sanitizeString(string $value): string {
    return trim(strip_tags($value));
}

/**
 * Return the current request action from GET or POST.
 */
function getRequestAction(): string {
    return sanitizeString($_GET['action'] ?? $_POST['action'] ?? '');
}

// ============================================================
// Role Authorization Helper
// ============================================================

/**
 * Require a minimum role level to access a resource.
 * Role hierarchy: admin > helpdesk > user
 * Sends a 403 JSON response and exits if the requirement is not met.
 */
function requireRole(string $minimumRole): void {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated.']);
        exit;
    }

    $hierarchy = ['user' => 1, 'helpdesk' => 2, 'admin' => 3];
    $userRole  = getRole();

    $userLevel    = $hierarchy[$userRole]    ?? 0;
    $minimumLevel = $hierarchy[$minimumRole] ?? 99;

    if ($userLevel < $minimumLevel) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions.']);
        exit;
    }
}

// ============================================================
// Local Authentication
// ============================================================

/**
 * Handle a local username/password login (AUTH_MODE = 'local').
 * The local account is always granted the 'admin' role.
 */
function handleLocalLogin(): array {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = sanitizeString($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (empty($username) || empty($password)) {
        return ['success' => false, 'error' => 'Username and password are required.'];
    }

    if (
        hash_equals(LOCAL_USERNAME, $username) &&
        hash_equals(LOCAL_PASSWORD, $password)
    ) {
        setAuthSession($username, 'admin', $username);
        return ['success' => true];
    }

    return ['success' => false, 'error' => 'Invalid username or password.'];
}

// ============================================================
// OAuth — Authorization URL
// ============================================================

/**
 * Build the Azure AD authorization URL and store a CSRF state token in session.
 */
function getOAuthAuthorizationUrl(): string {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'     => MICROSOFT_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => OAUTH_REDIRECT_URI,
        // openid + profile give us the ID token with name/UPN claims.
        // No "Group.Read.All" scope needed — App Roles are in the token automatically.
        'scope'         => 'openid profile email',
        'response_mode' => 'query',
        'state'         => $state,
    ]);

    return 'https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID
         . '/oauth2/v2.0/authorize?' . $params;
}

// ============================================================
// OAuth — Token Exchange
// ============================================================

/**
 * Exchange an authorization code for tokens via the Azure AD token endpoint.
 * Returns the decoded JSON response array, or null on failure.
 */
function exchangeCodeForToken(string $code): ?array {
    $url  = 'https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id'     => MICROSOFT_CLIENT_ID,
        'client_secret' => MICROSOFT_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => OAUTH_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
        'scope'         => 'openid profile email',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $response === false) {
        error_log('auth.php exchangeCodeForToken curl error: ' . $curlErr);
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

// ============================================================
// OAuth — JWT Decoding
// ============================================================

/**
 * Decode the payload segment of a JWT and return it as an associative array.
 *
 * NOTE: This does NOT cryptographically verify the signature.
 * The token was obtained directly from Microsoft's token endpoint over TLS,
 * so its authenticity is already guaranteed by the exchange.
 */
function decodeJwtPayload(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return [];
    }

    // Base64url → Base64 → decode
    $payload = $parts[1];
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $padding = strlen($payload) % 4;
    if ($padding !== 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return [];
    }

    return json_decode($decoded, true) ?? [];
}

// ============================================================
// OAuth — App Role Resolution
// ============================================================

/**
 * Map the "roles" claim array from the Azure AD token to the highest
 * matching internal role string.
 *
 * Role hierarchy (highest to lowest): admin > helpdesk > user
 *
 * If a user is assigned multiple App Roles in Azure AD, they will receive
 * the highest privilege role they are a member of.
 *
 * The constants ADMIN_USER_ROLE, HELPDESK_USER_ROLE, USER_ROLE (defined in
 * config.php) must match the "value" strings in the App Registration manifest.
 *
 * Returns: 'admin' | 'helpdesk' | 'user' | '' (empty = access denied)
 */
function resolveRoleFromAppRoles(array $tokenRoles): string {
    // Define priority order — highest privilege first.
    // We always evaluate in this order so a user in multiple roles
    // always receives the highest one they qualify for.
    $roleMap = [
        'admin'    => ADMIN_USER_ROLE,
        'helpdesk' => HELPDESK_USER_ROLE,
        'user'     => USER_ROLE,
    ];

    foreach ($roleMap as $internalRole => $appRoleValue) {
        // Skip if the constant is empty/not configured
        if (empty($appRoleValue)) {
            continue;
        }
        if (in_array($appRoleValue, $tokenRoles, true)) {
            return $internalRole;
        }
    }

    return ''; // No matching App Role — access denied
}

// ============================================================
// OAuth — Process Token & Build Session
// ============================================================

/**
 * Decode the ID token returned from Azure AD, extract the "roles" claim,
 * resolve the highest internal role, and populate the session.
 *
 * The "roles" claim is populated automatically by Azure AD when the user
 * has been assigned an App Role in the Enterprise Application — no extra
 * Graph API call is required.
 */
function processTokenData(array $tokenData): array {
    $idToken     = $tokenData['id_token']     ?? '';
    $accessToken = $tokenData['access_token'] ?? '';

    // Prefer the ID token for user claims; fall back to the access token
    $claims = !empty($idToken)
        ? decodeJwtPayload($idToken)
        : decodeJwtPayload($accessToken);

    if (empty($claims)) {
        return ['success' => false, 'error' => 'Failed to decode token claims.'];
    }

    // App Roles appear in the "roles" claim as an array of strings.
    // A user assigned multiple roles will have multiple entries here,
    // e.g. ["LiteLLM.Admin", "LiteLLM.User"]
    $tokenRoles = $claims['roles'] ?? [];

    if (!is_array($tokenRoles) || empty($tokenRoles)) {
        return [
            'success' => false,
            'error'   => 'Your account does not have an authorized App Role assigned. '
                       . 'Please contact your administrator.',
        ];
    }

    // Resolve highest role — admin beats helpdesk beats user
    $role = resolveRoleFromAppRoles($tokenRoles);

    if (empty($role)) {
        return [
            'success' => false,
            'error'   => 'Your account does not have an authorized role. '
                       . 'Please contact your administrator.',
        ];
    }

    // Extract identity claims
    $username    = $claims['preferred_username'] ?? $claims['upn'] ?? $claims['email'] ?? '';
    $displayName = $claims['name'] ?? $username;

    if (empty($username)) {
        return ['success' => false, 'error' => 'Could not determine username from token claims.'];
    }

    setAuthSession($username, $role, $displayName);
    return ['success' => true];
}

// ============================================================
// OAuth — Callback Handler
// ============================================================

/**
 * Handle the OAuth callback from Azure AD (?code=...).
 * Validates the CSRF state, exchanges the code for tokens,
 * then resolves the highest App Role from claims in the ID token.
 */
function handleOAuthCallback(): array {
    // Validate CSRF state token
    $state = $_GET['state'] ?? '';
    if (
        empty($state)
        || !isset($_SESSION['oauth_state'])
        || !hash_equals($_SESSION['oauth_state'], $state)
    ) {
        return ['success' => false, 'error' => 'Invalid OAuth state. Possible CSRF attack.'];
    }
    unset($_SESSION['oauth_state']);

    // Check for errors returned from Azure
    if (isset($_GET['error'])) {
        $errDesc = $_GET['error_description'] ?? $_GET['error'];
        return ['success' => false, 'error' => htmlspecialchars($errDesc, ENT_QUOTES, 'UTF-8')];
    }

    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        return ['success' => false, 'error' => 'No authorization code received.'];
    }

    // Exchange authorization code for tokens (id_token + access_token)
    $tokenData = exchangeCodeForToken($code);
    if (!$tokenData || empty($tokenData['access_token'])) {
        return ['success' => false, 'error' => 'Failed to obtain access token from Microsoft.'];
    }

    // Decode ID token claims and resolve highest role — no Graph API call needed
    return processTokenData($tokenData);
}

// ============================================================
// Request Dispatcher (when this file is accessed directly)
// ============================================================

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {

    $action = getRequestAction();

    // -- OAuth callback (Azure redirects back with ?code=...)
    if (AUTH_MODE === 'oauth' && isset($_GET['code'])) {
        $result = handleOAuthCallback();
        if ($result['success']) {
            header('Location: index.php');
        } else {
            header('Location: index.php?auth_error=' . urlencode($result['error']));
        }
        exit;
    }

    // -- Local login (POST with action=login)
    if (
        AUTH_MODE === 'local'
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && $action === 'login'
    ) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(handleLocalLogin());
        exit;
    }

    // -- OAuth login initiation
    if (AUTH_MODE === 'oauth' && $action === 'oauth_login') {
        header('Location: ' . getOAuthAuthorizationUrl());
        exit;
    }

    // -- Session check (used by app.js heartbeat)
    if ($action === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(getSessionInfo());
        exit;
    }

    // -- Session info (used by app.js on load)
    if ($action === 'session_info') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(getSessionInfo());
        exit;
    }

    // Fallback — redirect to index
    header('Location: index.php');
    exit;
}