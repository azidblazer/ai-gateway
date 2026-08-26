<?php
$LiteLLMManagerURI = 'https://'.getenv('LITELLMMANAGER_DOMAIN');

header("Content-Security: default-src 'self' $LiteLLMManagerURI; script-src 'self' 'unsafe-inline' $LiteLLMManagerURI https://fonts.googleapis.com https://ajax.googleapis.com https://fonts.gstatic.com; style-src 'self' $LiteLLMManagerURI https://fonts.googleapis.com https://ajax.googleapis.com https://fonts.gstatic.com 'unsafe-inline'; font-src https://fonts.gstatic.com; connect-src 'self' $LiteLLMManagerURI frame-ancestors; img-src $LiteLLMManagerURI https://i.slcc.edu;");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

require_once 'config.php';
require_once 'auth.php';

$authError = sanitizeString($_GET['auth_error'] ?? '');
$isAuth    = isAuthenticated();
$authMode  = AUTH_MODE;
$logoUrl   = APP_LOGO_URL;
$appName   = APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($appName) ?> - Manage LiteLLM user budgets and spending">
    <title><?= htmlspecialchars($appName) ?></title>

    <!-- Google Fonts: Roboto (UI) + Minion Pro fallback via serif stack -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">

    <!-- jQuery from Google CDN -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <!-- jQuery UI from Google CDN -->
    <link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js"></script>

    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- HEADER -->
<header class="site-header" role="banner">
    <div class="header-inner">
        <div class="header-logo">
            <img src="<?= htmlspecialchars($logoUrl) ?>"
                 alt="<?= htmlspecialchars($appName) ?> Logo"
                 class="logo-image"
                 onerror="this.style.display='none';document.getElementById('logo-fallback').style.display='block';">
            <span id="logo-fallback" class="logo-fallback" style="display:none;">
                <?= htmlspecialchars($appName) ?>
            </span>
        </div>
        <div class="header-title">
            <h1><?= htmlspecialchars($appName) ?></h1>
        </div>
        <?php if ($isAuth): ?>
        <div class="header-user" id="header-user-info">
            <span class="user-info-text">
                <span id="display-name-header" aria-live="polite"></span>
                &mdash;
                <span id="role-badge" class="role-badge" aria-label="Your role: "></span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm" id="logout-btn">
                <span aria-hidden="true">&#x2717;</span> Sign Out
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isAuth): ?>
    <nav class="main-nav" role="navigation" aria-label="Main navigation">
        <ul class="nav-tabs" role="tablist">
            <li role="presentation">
                <button class="nav-tab active" role="tab" aria-selected="true"
                        aria-controls="panel-spend" id="tab-spend"
                        data-panel="panel-spend" tabindex="0">
                    Spend Overview
                </button>
            </li>
            <li role="presentation" class="helpdesk-ok" style="display:none">
                <button class="nav-tab" role="tab" aria-selected="false"
                        aria-controls="panel-budget" id="tab-budget"
                        data-panel="panel-budget" tabindex="-1">
                    Budget Management
                </button>
            </li>
            <?php if (canAdmin()): ?>
            <li role="presentation" class="helpdesk-ok" style="display:none">
                <button class="nav-tab" role="tab" aria-selected="false"
                        aria-controls="panel-export" id="tab-export"
                        data-panel="panel-export" tabindex="-1">
                    Export Reports
                </button>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</header>

<!-- MAIN CONTENT -->
<main id="main-content" tabindex="-1">

    <!-- LOGIN -->
    <?php if (!$isAuth): ?>
    <section id="login-section" class="login-section" aria-labelledby="login-heading">
        <div class="login-card">
            <h2 id="login-heading">Sign In</h2>

            <?php if ($authError): ?>
            <div class="alert alert-error" role="alert" aria-live="assertive">
                <strong>Authentication Error:</strong> <?= $authError ?>
            </div>
            <?php endif; ?>

            <?php if ($authMode === 'local'): ?>
            <form id="login-form" novalidate>
                <div class="form-group">
                    <label for="login-username">
                        Username <span class="required" aria-hidden="true">*</span>
                    </label>
                    <input type="text" id="login-username" name="username"
                           class="form-control" required autocomplete="username"
                           aria-required="true" aria-describedby="username-desc">
                    <span id="username-desc" class="sr-only">Enter your username</span>
                </div>
                <div class="form-group">
                    <label for="login-password">
                        Password <span class="required" aria-hidden="true">*</span>
                    </label>
                    <input type="password" id="login-password" name="password"
                           class="form-control" required autocomplete="current-password"
                           aria-required="true">
                </div>
                <div id="login-error" class="alert alert-error" role="alert"
                     aria-live="assertive" style="display:none;"></div>
                <button type="submit" class="btn btn-primary btn-block" id="login-submit">
                    Sign In
                </button>
            </form>

            <?php elseif ($authMode === 'oauth'): ?>
            <p class="oauth-intro">Sign in with your organizational Microsoft account.</p>
            <a href="auth.php?action=oauth_login"
               class="btn btn-primary btn-block btn-oauth" id="oauth-login-btn">
                <svg aria-hidden="true" focusable="false" width="20" height="20"
                     viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#f3f3f3" d="M0 0h23v23H0z"/>
                    <path fill="#f35325" d="M1 1h10v10H1z"/>
                    <path fill="#81bc06" d="M12 1h10v10H12z"/>
                    <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                    <path fill="#ffba08" d="M12 12h10v10H12z"/>
                </svg>
                Sign in with Microsoft
            </a>
            <?php endif; ?>
        </div>
    </section>

    <?php else: ?>

    <!-- DASHBOARD -->
    <div class="dashboard" id="dashboard">

        <div id="notification-area" role="status" aria-live="polite"
             aria-atomic="true" class="notification-area"></div>

        <!-- PANEL: SPEND OVERVIEW -->
        <section id="panel-spend" class="panel active"
                 role="tabpanel" aria-labelledby="tab-spend">
            <div class="panel-header">
                <h2>Spend Overview</h2>
            </div>



            <div class="card">
                <h3 class="card-title">Check Current Budget</h3>
                <form id="form-check-budget" class="form-inline-grid" novalidate>
                    <div class="form-group admin-helpdesk-field" style="display:none">
                        <label for="budget-check-username">Email Address</label>
                        <input type="text" id="budget-check-username" name="username"
                               class="form-control" placeholder="user@domain.com"
                               autocomplete="off">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btn-check-budget">
                            Check Budget
                        </button>
                    </div>
                </form>
                <div id="budget-check-results" class="results-area"
                     aria-live="polite" aria-atomic="true"></div>
            </div>
        </section>

        <!-- PANEL: BUDGET MANAGEMENT -->
		<?php if (canHelpdesk()): ?>
        <section id="panel-budget" class="panel"
                 role="tabpanel" aria-labelledby="tab-budget" hidden>
            <div class="panel-header">
                <h2>Budget Management</h2>
            </div>

            <div class="card">
                <h3 class="card-title">Assign Budget to End User</h3>
                <p class="card-description">
                    Select an end user and assign a budget configuration
                    pulled from the LiteLLM server.
                </p>

                <form id="form-update-budget" novalidate>
                    <div class="form-group">
                        <label for="budget-username">
                            Email Address <span class="required" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="budget-username" name="username"
                               class="form-control" required
                               placeholder="user@domain.com"
                               autocomplete="off" aria-required="true">
                    </div>

                    <div class="form-group">
                        <label for="budget-select">
                            Budget Configuration <span class="required" aria-hidden="true">*</span>
                        </label>
                        <div class="input-with-btn">
                            <select id="budget-select" name="budget_id"
                                    class="form-control" required aria-required="true">
                                <option value="">-- Loading budgets... --</option>
                            </select>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    id="btn-refresh-budgets"
                                    aria-label="Refresh budget list from server">
                                &#x21BB; Refresh
                            </button>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btn-update-budget">
                            Assign Budget
                        </button>
                    </div>
                </form>

                <div id="budget-update-results" class="results-area"
                     aria-live="polite" aria-atomic="true"></div>
            </div>
			<div class="card">
				<h3 class="card-title">Bulk Budget Upload</h3>
				<p class="card-description">
					Upload a CSV of <code>Email Address,budget_id</code> (e.g. JohnDoe@example.net,weekly-5-usd).
					Missing end users are created automatically.
				</p>
				<form id="form-bulk-budget" class="form-inline-grid" novalidate enctype="multipart/form-data">
					<div class="form-group">
						<label for="bulk-budget-file">CSV File <span class="required">*</span></label>
						<input type="file" id="bulk-budget-file" name="csv_file" accept=".csv,text/csv"
							   class="form-control" required>
					</div>
					<div class="form-actions">
						<button type="submit" class="btn btn-primary" id="btn-bulk-budget">&#x2B06; Upload &amp; Apply</button>
					</div>
				</form>
				<div id="bulk-budget-status" class="results-area" aria-live="polite"></div>
			</div>
        </section>
		<?php endif; ?>
        <!-- PANEL: EXPORT REPORTS -->
		<?php if (canAdmin()): ?>
        <section id="panel-export" class="panel"
                 role="tabpanel" aria-labelledby="tab-export" hidden>
            <div class="panel-header">
                <h2>Export Reports</h2>
            </div>

            <div class="card">
                <h3 class="card-title">Export End User Chat Logs &amp; Spend</h3>
                <p class="card-description">
                    Download a CSV of all chat log entries and spend data for a specific end user.
                </p>
                <form id="form-export-user" class="form-inline-grid" novalidate>
                    <div class="form-group">
                        <label for="export-username">
                            Email Address <span class="required" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="export-username" name="username"
                               class="form-control" required
                               placeholder="user@domain.com"
                               autocomplete="off" aria-required="true">
                    </div>
                    <div class="form-group">
                        <label for="export-start-date">Start Date</label>
                        <input type="date" id="export-start-date" name="start_date"
                               class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="export-end-date">End Date</label>
                        <input type="date" id="export-end-date" name="end_date"
                               class="form-control" required>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btn-export-user">
                            &#x2B07; Export User CSV
                        </button>
                    </div>
                </form>
                <div id="export-user-status" class="results-area" aria-live="polite"></div>
            </div>

			<div class="card">
				<h3 class="card-title">Export All End Users Spend Summary</h3>
				<p class="card-description">
					Download an aggregated spend summary CSV grouped by day.
				</p>
				<form id="form-export-all" class="form-inline-grid" novalidate>
					<div class="form-group">
						<label for="export-all-start-date">
							Start Date <span class="required" aria-hidden="true">*</span>
						</label>
						<input type="date" id="export-all-start-date" name="start_date"
							class="form-control" required
							autocomplete="off" aria-required="true">
					</div>
					<div class="form-group">
						<label for="export-all-end-date">
							End Date <span class="required" aria-hidden="true">*</span>
						</label>
						<input type="date" id="export-all-end-date" name="end_date"
							class="form-control" required
							autocomplete="off" aria-required="true">
					</div>
					<div class="form-actions">
						<button type="submit" class="btn btn-primary" id="btn-export-all">
							&#x2B07; Export All Users CSV
						</button>
					</div>
				</form>
				<div id="export-all-status" class="results-area" aria-live="polite"></div>
			</div>
			<div class="card">
				<h3 class="card-title">Export All End Users &amp; Budgets</h3>
				<p class="card-description">Download a CSV of every end user and their current budget settings.</p>
				<button type="button" class="btn btn-primary" id="btn-export-users-budgets">&#x2B07; Export Users &amp; Budgets CSV</button>
			</div>

			<div class="card">
				<h3 class="card-title">Cost Index Management</h3>
				<p class="card-description">Assign a cost index to an end user. Leave the index blank to clear it (NULL). WARNING, this is using the default_model field in the database and may break.</p>
				<form id="form-cost-index" class="form-inline-grid" novalidate>
					<div class="form-group">
						<label for="ci-username">Email Address <span class="required">*</span></label>
						<input type="text" id="ci-username" class="form-control" required placeholder="user@domain.com" autocomplete="off">
					</div>
					<div class="form-group">
						<label for="ci-value">Cost Index</label>
						<input type="text" id="ci-value" class="form-control" placeholder="index12111 (blank = clear)" autocomplete="off">
					</div>
					<div class="form-actions">
						<button type="submit" class="btn btn-primary" id="btn-set-cost-index">Save Cost Index</button>
					</div>
				</form>
				<div id="cost-index-status" class="results-area" aria-live="polite"></div>
				<button type="button" class="btn" id="btn-export-cost-index" style="margin-top:1rem">&#x2B07; Export Cost Index CSV</button>
			</div>
			<div class="card">
				<h3 class="card-title">Bulk Cost Index Upload</h3>
				<p class="card-description">
					Upload a CSV of <code>user_id,cost_index</code> (e.g. JohnDoe@example.net,index12111).
					Leave the cost_index column blank to clear it (NULL). Missing end users are created automatically.
					<strong>WARNING</strong>, this uses the <code>default_model</code> field in the database and may break.
				</p>
				<form id="form-bulk-cost-index" class="form-inline-grid" novalidate enctype="multipart/form-data">
					<div class="form-group">
						<label for="bulk-cost-index-file">CSV File <span class="required">*</span></label>
						<input type="file" id="bulk-cost-index-file" name="csv_file" accept=".csv,text/csv"
							   class="form-control" required>
					</div>
					<div class="form-actions">
						<button type="submit" class="btn btn-primary" id="btn-bulk-cost-index">&#x2B06; Upload &amp; Apply</button>
					</div>
				</form>
				<div id="bulk-cost-index-status" class="results-area" aria-live="polite"></div>
			</div>
			<div class="card">
				<h3 class="card-title">Export Costs by Cost Index</h3>
				<p class="card-description">Download total spend per cost index for a date range.</p>
				<form id="form-export-index-costs" class="form-inline-grid" novalidate>
					<div class="form-group">
						<label for="idx-start-date">Start Date <span class="required">*</span></label>
						<input type="date" id="idx-start-date" class="form-control" required>
					</div>
					<div class="form-group">
						<label for="idx-end-date">End Date <span class="required">*</span></label>
						<input type="date" id="idx-end-date" class="form-control" required>
					</div>
					<div class="form-actions">
						<button type="submit" class="btn btn-primary" id="btn-export-index-costs">&#x2B07; Export Index Costs CSV</button>
					</div>
				</form>
				<div id="export-index-status" class="results-area" aria-live="polite"></div>
			</div>
			
			
        </section>
		<?php endif; ?>

    </div><!-- /.dashboard -->
    <?php endif; ?>

</main>

<footer class="site-footer" role="contentinfo">
    <div class="footer-inner">
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?>. All rights reserved.</p>
    </div>
</footer>

<div id="loading-overlay" class="loading-overlay" aria-hidden="true" role="status">
    <div class="loading-spinner">
        <div class="spinner" aria-hidden="true"></div>
        <span class="loading-text">Loading&hellip;</span>
    </div>
</div>

<script>
var APP_CONFIG = {
    isAuthenticated: <?= $isAuth ? 'true' : 'false' ?>,
    authMode: <?= json_encode($authMode) ?>
};
</script>
<script src="assets/app.js"></script>
</body>
</html>
