<?php
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
ob_start();
/**
 * API Proxy
 * All LiteLLM API calls are routed through this file.
 * Credentials are never exposed to the browser.
 */

require_once 'config.php';
require_once 'auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ============================================================
// cURL Helper
// ============================================================

function litellmRequest(string $method, string $endpoint, array $params = [], array $body = []): array {
    $url = LITELLM_API_URL . $endpoint;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $headers = [
        'Authorization: ' . LITELLM_AUTH_HEADER,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => LITELLM_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => LITELLM_VERIFY_SSL ? 2 : 0,
        CURLOPT_TIMEOUT        => 30,
    ]);

    switch (strtoupper($method)) {
        case 'GET':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['http_code' => 0, 'data' => ['error' => 'Connection error: ' . $curlError]];
    }

    if (empty($response)) {
        return ['http_code' => $httpCode, 'data' => ['error' => 'Empty response from LiteLLM (HTTP ' . $httpCode . ')']];
    }

    $decoded = json_decode($response, true);

    if ($decoded === null) {
        return ['http_code' => $httpCode, 'data' => ['error' => 'Non-JSON response from LiteLLM', 'raw' => substr($response, 0, 500)]];
    }

    if ($httpCode >= 400) {
        return ['http_code' => $httpCode, 'data' => ['error' => extractErrorMessage($decoded)]];
    }

    return ['http_code' => $httpCode, 'data' => $decoded];
}

function extractErrorMessage(array $decoded): string {
    if (isset($decoded['detail'])) {
        if (is_string($decoded['detail'])) return $decoded['detail'];
        if (is_array($decoded['detail']) && isset($decoded['detail']['message'])) return (string)$decoded['detail']['message'];
        return json_encode($decoded['detail']);
    }
    if (isset($decoded['error'])) {
        if (is_string($decoded['error'])) return $decoded['error'];
        if (is_array($decoded['error']) && isset($decoded['error']['message'])) return (string)$decoded['error']['message'];
        return json_encode($decoded['error']);
    }
    if (isset($decoded['message']) && is_string($decoded['message'])) return $decoded['message'];
    return 'LiteLLM error: ' . json_encode($decoded);
}

function getPostJson(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ============================================================
// CSV Helpers
// ============================================================

function outputCsvHeaders(string $filename): void {
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function arrayToCsv(array $rows, array $headers): string {
    $output = fopen('php://temp', 'r+b');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($output, array_values((array)$row), ',', '"', '\\');
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return $csv;
}

// ============================================================
// Spend log filter by date range (client-side filtering)
// LiteLLM /spend/logs may not honour date params for end_users
// so we filter the returned records ourselves.
// ============================================================

function filterLogsByDateRange(array $logs, string $startDate, string $endDate): array {
    if (empty($startDate) && empty($endDate)) return $logs;

    $start = !empty($startDate) ? strtotime($startDate . ' 00:00:00') : null;
    $end   = !empty($endDate)   ? strtotime($endDate   . ' 23:59:59') : null;

    return array_filter($logs, function ($log) use ($start, $end) {
        $ts  = $log['startTime'] ?? $log['start_time'] ?? '';
        $time = $ts ? strtotime($ts) : null;
        if (!$time) return true; // keep records with no timestamp
        if ($start && $time < $start) return false;
        if ($end   && $time > $end)   return false;
        return true;
    });
}

// ============================================================
// Extract budget ID from a customer/info response.
// The budget info is nested under the 'budget_table' key.
// ============================================================

function extractBudgetInfo(array $data): array {
    $budgetId       = $data['budget_id']       ?? null;
	$maxBudget      = $data['max_budget']       ?? null;
	$budgetDuration = $data['budget_duration']  ?? null;
	$budgetResetAt  = $data['budget_reset_at']  ?? null;

    // Direct fields first (sometimes present at top level)
    if (!empty($data['budget_id']))  $budgetId  = $data['budget_id'];
    if (isset($data['max_budget']))  $maxBudget = $data['max_budget'];

    // LiteLLM nests the real budget info under 'budget_table'
    if (isset($data['budget_table']) && is_array($data['budget_table'])) {
        $bt = $data['budget_table'];
        if (!empty($bt['budget_id']))       $budgetId       = $bt['budget_id'];
        if (isset($bt['max_budget']))       $maxBudget      = $bt['max_budget'];
        if (!empty($bt['budget_duration'])) $budgetDuration = $bt['budget_duration'];
        if (!empty($bt['budget_reset_at'])) $budgetResetAt  = $bt['budget_reset_at'];
    }

    return [
        'budget_id'       => $budgetId,
        'max_budget'      => $maxBudget,
        'budget_duration' => $budgetDuration,
        'budget_reset_at' => $budgetResetAt,
    ];
}


// ============================================================
// Bumps the end date forward one so we do not have an inclusive date problem
// ============================================================

function bumpEndDate(string $date): string {
    // Expects YYYY-MM-DD, returns next day in YYYY-MM-DD
    $ts = strtotime($date . ' +1 day');
    return $ts ? date('Y-m-d', $ts) : $date;
}


if (!function_exists('convertToAPIDateShared')) {
    function convertToAPIDateShared(string $date): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) return $m[3].'-'.$m[1].'-'.$m[2];
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/',  $date, $m)) return '20'.$m[3].'-'.$m[1].'-'.$m[2];
        return $date;
    }
}


// ============================================================
// Route Handler
// ============================================================

$action = sanitizeString($_GET['action'] ?? '');
$input  = getPostJson();

switch ($action) {








// ----------------------------------------------------------
// POST: Bulk set budgets from uploaded CSV (user_id,budget_id)
// Creates the end user if they do not exist.
// ----------------------------------------------------------
	case 'bulk_budget_csv':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
			http_response_code(400);
			echo json_encode(['error' => 'A CSV file upload is required (field name: csv_file)']);
			break;
		}

		$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
		if ($fh === false) {
			http_response_code(500);
			echo json_encode(['error' => 'Could not read uploaded file']);
			break;
		}

		$results = ['updated' => [], 'created' => [], 'failed' => []];
		$lineNo  = 0;

		while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
			$lineNo++;
			$userId   = trim($row[0] ?? '');
			$budgetId = trim($row[1] ?? '');

			// Strip UTF-8 BOM on first line
			$userId = preg_replace('/^\xEF\xBB\xBF/', '', $userId);

			// Skip blanks and a possible header row
			if ($userId === '' || $budgetId === '') continue;
			if ($lineNo === 1 && stripos($userId, 'user') !== false && stripos($budgetId, 'budget') !== false) continue;

			// Does the end user already exist?
			$info   = litellmRequest('GET', '/customer/info', ['end_user_id' => $userId]);
			$exists = ($info['http_code'] === 200 && !isset($info['data']['error']));

			if ($exists) {
				$res = litellmRequest('POST', '/customer/update', [], [
					'user_id'   => $userId,
					'budget_id' => $budgetId,
				]);
				if ($res['http_code'] === 200) { $results['updated'][] = $userId; }
				else { $results['failed'][] = ['user' => $userId, 'error' => extractErrorMessage($res['data'])]; }
			} else {
				$res = litellmRequest('POST', '/customer/new', [], [
					'user_id'   => $userId,
					'budget_id' => $budgetId,
				]);
				if ($res['http_code'] === 200) { $results['created'][] = $userId; }
				else { $results['failed'][] = ['user' => $userId, 'error' => extractErrorMessage($res['data'])]; }
			}
		}
		fclose($fh);

		echo json_encode([
			'success'       => empty($results['failed']),
			'updated_count' => count($results['updated']),
			'created_count' => count($results['created']),
			'failed_count'  => count($results['failed']),
			'details'       => $results,
		]);
		break;
// ----------------------------------------------------------
// POST: Bulk set cost index from an uploaded CSV
// CSV columns: user_id,cost_index  (blank cost_index clears it)
// ----------------------------------------------------------
    case 'bulk_cost_index_csv':
        if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'A CSV file upload is required (field name: csv_file)']);
            break;
        }

        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($fh === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not read uploaded file']);
            break;
        }

        $results = ['updated' => [], 'created' => [], 'failed' => []];
        $lineNo  = 0;

        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            $userId    = trim($row[0] ?? '');
            $costIndex = trim($row[1] ?? '');

            // Strip UTF-8 BOM on first line
            $userId = preg_replace('/^\xEF\xBB\xBF/', '', $userId);

            // Skip blank user rows and a possible header row.
            // NOTE: we only require user_id — a blank cost_index is valid (it clears the index).
            if ($userId === '') continue;
            if ($lineNo === 1 && stripos($userId, 'user') !== false && stripos($costIndex, 'index') !== false) continue;

            // Empty string clears the cost index (stored in default_model)
            $defaultModel = ($costIndex === '') ? '' : $costIndex;

            // Does the end user already exist?
            $info   = litellmRequest('GET', '/customer/info', ['end_user_id' => $userId]);
            $exists = ($info['http_code'] === 200 && !isset($info['data']['error']));

            if ($exists) {
                $res = litellmRequest('POST', '/customer/update', [], [
                    'user_id'       => $userId,
                    'default_model' => $defaultModel,
                ]);
                if ($res['http_code'] === 200) { $results['updated'][] = $userId; }
                else { $results['failed'][] = ['user' => $userId, 'error' => extractErrorMessage($res['data'])]; }
            } else {
                $res = litellmRequest('POST', '/customer/new', [], [
                    'user_id'       => $userId,
                    'default_model' => $defaultModel,
                ]);
                if ($res['http_code'] === 200) { $results['created'][] = $userId; }
                else { $results['failed'][] = ['user' => $userId, 'error' => extractErrorMessage($res['data'])]; }
            }
        }
        fclose($fh);

        echo json_encode([
            'success'       => empty($results['failed']),
            'updated_count' => count($results['updated']),
            'created_count' => count($results['created']),
            'failed_count'  => count($results['failed']),
            'details'       => $results,
        ]);
        break;
	// ----------------------------------------------------------
	// GET: Export CSV of all end users with their budget settings
	// ----------------------------------------------------------
	case 'export_users_budgets_csv':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$result = litellmRequest('GET', '/customer/list');
		if ($result['http_code'] !== 200) {
			http_response_code($result['http_code'] ?: 500);
			echo json_encode($result['data'] ?? ['error' => 'Failed to list end users']);
			break;
		}

		$users = is_array($result['data']) ? $result['data'] : [];
		$csvHeaders = ['Email Address', 'Budget ID', 'Max Budget ($)', 'Budget Duration', 'Current Spend ($)'];
		$rows = [];

		foreach ($users as $u) {
			// Budget details nest under litellm_budget_table (fallback: budget_table)
			$bt = $u['litellm_budget_table'] ?? $u['budget_table'] ?? [];
			$rows[] = [
				$u['user_id'] ?? '',
				$u['budget_id'] ?? ($bt['budget_id'] ?? ''),
				isset($bt['max_budget']) ? number_format((float)$bt['max_budget'], 6)
					: (isset($u['max_budget']) ? number_format((float)$u['max_budget'], 6) : ''),
				$bt['budget_duration'] ?? '',
				number_format((float)($u['spend'] ?? 0), 6),
			];
		}

		usort($rows, fn($a, $b) => strcasecmp($a[0], $b[0]));

		outputCsvHeaders('endusers_budgets_' . date('Ymd') . '.csv');
		echo arrayToCsv($rows, $csvHeaders);
		exit;

	// ----------------------------------------------------------
	// POST: Set or clear (NULL) an end user's cost index
	// ----------------------------------------------------------
	case 'set_cost_index':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$userId    = sanitizeString($input['user_id'] ?? '');
		$costIndex = isset($input['cost_index']) ? trim((string)$input['cost_index']) : '';
		if ($costIndex === '') $costIndex = null;   // empty string clears the index

		if (empty($userId)) {
			http_response_code(400);
			echo json_encode(['error' => 'user_id is required']);
			break;
		}

		// Store the cost index in the customer's default_model field via LiteLLM.
        // An empty string clears it (send empty default_model).
        $result = litellmRequest('POST', '/customer/update', [], [
            'user_id'       => $userId,
            'default_model' => $costIndex === null ? '' : $costIndex,
        ]);

        if ($result['http_code'] !== 200) {
            http_response_code($result['http_code'] ?: 500);
            echo json_encode($result['data'] ?? ['error' => 'Failed to set cost index']);
            break;
        }

        echo json_encode([
            'success'    => true,
            'user_id'    => $userId,
            'cost_index' => $costIndex,
            'message'    => $costIndex === null
                ? 'Cost index cleared for ' . $userId
                : 'Cost index set to "' . $costIndex . '" for ' . $userId,
        ]);
        break;

		break;

	// ----------------------------------------------------------
	// GET: Export CSV of user_id and associated cost index
	// ----------------------------------------------------------
	case 'export_cost_index_csv':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$result = litellmRequest('GET', '/customer/list');
        if ($result['http_code'] !== 200) {
            http_response_code($result['http_code'] ?: 500);
            echo json_encode($result['data'] ?? ['error' => 'Failed to list end users']);
            break;
        }

        $users = is_array($result['data']) ? $result['data'] : [];
        $rows  = [];
        foreach ($users as $u) {
            $ci = $u['default_model'] ?? '';
            // Only include users that actually have a cost index set
            if ($ci === '' || $ci === null) continue;
            $rows[] = [$u['user_id'] ?? '', $ci];
        }

        usort($rows, fn($a, $b) => strcasecmp($a[0], $b[0]));

        outputCsvHeaders('cost_index_map_' . date('Ymd') . '.csv');
        echo arrayToCsv($rows, ['Email Address', 'Cost Index']);
        exit;

	// ----------------------------------------------------------
	// GET: Export costs grouped by cost index for a date range
	// Output: cost_index,$total
	// ----------------------------------------------------------
	case 'export_index_costs_csv':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$startDate = sanitizeString($_GET['start_date'] ?? '');
		$endDate   = sanitizeString($_GET['end_date']   ?? '');
		if (empty($startDate)) { echo json_encode(['error' => 'Start Date is required']); exit; }
		if (empty($endDate))   { echo json_encode(['error' => 'End Date is required']);   exit; }

		// Load user_id -> cost_index map from LiteLLM (default_model field).
        // Only include users that HAVE an index set.
        $listResult = litellmRequest('GET', '/customer/list');
        if ($listResult['http_code'] !== 200) {
            http_response_code($listResult['http_code'] ?: 500);
            echo json_encode($listResult['data'] ?? ['error' => 'Failed to list end users']);
            break;
        }

        $indexMap = [];
        foreach ((is_array($listResult['data']) ? $listResult['data'] : []) as $u) {
            $ci = $u['default_model'] ?? '';
            if ($ci !== '' && $ci !== null) {
                $indexMap[$u['user_id'] ?? ''] = $ci;
            }
        }

		$apiStartDate = convertToAPIDateShared($startDate);
		$apiEndDate   = bumpEndDate(convertToAPIDateShared($endDate)); // inclusive end date

		// Paginate through /spend/logs/v2 (same pattern as other exports)
		$indexTotals = [];
		$page        = 1;
		$totalPages  = 1;

		do {
			$result = litellmRequest('GET', '/spend/logs/v2', [
				'start_date' => $apiStartDate,
				'end_date'   => $apiEndDate,
				'page'       => $page,
				'page_size'  => 100,
			]);

			if ($result['http_code'] !== 200 || isset($result['data']['error'])) {
				http_response_code($result['http_code'] ?: 500);
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['error' => $result['data']['error'] ?? 'Failed to fetch logs']);
				exit;
			}

			$responseData = $result['data'];
			$pageData     = $responseData['data'] ?? [];
			$totalPages   = (int)($responseData['total_pages'] ?? 1);

			foreach ($pageData as $log) {
				$endUser = $log['end_user'] ?? '';
				if ($endUser === '' || !isset($indexMap[$endUser])) continue; // only indexed users
				$ci = $indexMap[$endUser];
				if (!isset($indexTotals[$ci])) $indexTotals[$ci] = 0.0;
				$indexTotals[$ci] += (float)($log['spend'] ?? 0);
			}

			$page++;
			if ($page > 100) break; // safety limit
		} while ($page <= $totalPages);

		ksort($indexTotals);

		$rows = [];
		foreach ($indexTotals as $ci => $total) {
			$rows[] = [$ci, '$' . number_format($total, 2)];
		}

		$safeStart = str_replace('/', '-', $startDate);
		$safeEnd   = str_replace('/', '-', $endDate);
		outputCsvHeaders('index_costs_' . $safeStart . '_to_' . $safeEnd . '_' . date('Ymd') . '.csv');
		echo arrayToCsv($rows, ['Cost Index', 'Total Cost']);
		exit;















	// ----------------------------------------------------------
	// GET: User spend by date range
	// Endpoint: GET /spend/logs?end_user=<id>&start_date=<date>&end_date=<date>
	// ----------------------------------------------------------
	case 'user_spend':
		if (!canHelpdesk()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
		$username  = sanitizeString($_GET['username']   ?? '');
		$startDate = sanitizeString($_GET['start_date'] ?? '');
		$endDate   = sanitizeString($_GET['end_date']   ?? '');

		if (empty($username) && isset($_SESSION['username'])) {
			$username = $_SESSION['username'];
		}

		if (empty($username)) {
			http_response_code(400);
			echo json_encode(['error' => 'Email Address is required']);
			break;
		}

		if (empty($startDate)) {
			http_response_code(400);
			echo json_encode(['error' => 'Start Date is required']);
			break;
		}

		if (empty($endDate)) {
			http_response_code(400);
			echo json_encode(['error' => 'End Date is required']);
			break;
		}

		// Dates already arrive as YYYY-MM-DD from the date input
		// Bump end date forward by one day for inclusive boundary
		$bumpedEndDate = bumpEndDate($endDate);

		$logsResult = litellmRequest('GET', '/spend/logs', [
			'end_user_id' => $username,
			'start_date'  => $startDate,
			'end_date'    => $bumpedEndDate,
		]);

		if ($logsResult['http_code'] !== 200) {
			http_response_code($logsResult['http_code'] ?: 500);
			echo json_encode($logsResult['data'] ?? ['error' => 'Failed to retrieve spend logs']);
			break;
		}

		$logs = $logsResult['data'];

		// Unwrap spend_logs array if present — same as export_user_logs_csv
		if (isset($logs['spend_logs']) && is_array($logs['spend_logs'])) {
			$logs = $logs['spend_logs'];
		}

		// Calculate total spend across all log entries in date range
		$periodTotal = 0.0;
		if (is_array($logs)) {
			foreach ($logs as $log) {
				$periodTotal += (float)($log['spend'] ?? $log['cost'] ?? 0);
			}
		}

		echo json_encode([
			'user_id'      => $username,
			'spend'        => $periodTotal,
		]);
		break;

	// ----------------------------------------------------------
	// GET: Check budget for an end user
	// Endpoint: GET /customer/info?end_user_id=<id>
	// ----------------------------------------------------------
	case 'check_budget':
	//If they are not helpdesk or greater, they can only see their session username budget
		if (!canHelpdesk()) {
			$username = $_SESSION['username'];
		}else{
			$username = sanitizeString($_GET['username'] ?? '');

			if (empty($username) && isset($_SESSION['username'])) {
				$username = $_SESSION['username'];
			}
		}
		

		if (empty($username)) {
			http_response_code(400);
			echo json_encode(['error' => 'Email Address is required']);
			break;
		}

		$result = litellmRequest('GET', '/customer/info', ['end_user_id' => $username]);

		if ($result['http_code'] !== 200) {
			http_response_code($result['http_code'] ?: 500);
			echo json_encode($result['data'] ?? ['error' => 'Failed to retrieve budget info']);
			break;
		}

		$data = $result['data'];

		// Extract budget info from response
		$budgetId       = $data['budget_id']       ?? null;
		$maxBudget      = $data['max_budget']       ?? null;
		$budgetDuration = $data['budget_duration']  ?? null;
		$budgetResetAt  = $data['budget_reset_at']  ?? null;
		$spend          = $data['spend']            ?? null;

		// FIX: LiteLLM nests budget info under 'litellm_budget_table', not 'budget_table'
		$bt = null;
		if (isset($data['litellm_budget_table']) && is_array($data['litellm_budget_table'])) {
			$bt = $data['litellm_budget_table'];
		} elseif (isset($data['budget_table']) && is_array($data['budget_table'])) {
			// Fallback for older API versions
			$bt = $data['budget_table'];
		}

		if ($bt !== null) {
			if (!empty($bt['budget_id']))       $budgetId       = $bt['budget_id'];
			if (isset($bt['max_budget']))       $maxBudget      = $bt['max_budget'];
			if (!empty($bt['budget_duration'])) $budgetDuration = $bt['budget_duration'];
			if (!empty($bt['budget_reset_at'])) $budgetResetAt  = $bt['budget_reset_at'];
		}

		echo json_encode([
			'user_id'         => $data['user_id']  ?? $username,
			'spend'           => $spend,
			'budget_id'       => $budgetId,
			'max_budget'      => $maxBudget,
			'budget_duration' => $budgetDuration,
			'budget_reset_at' => $budgetResetAt,
		]);
		break;

    // ----------------------------------------------------------
    // GET: List all end users / customers
    // Endpoint: GET /customer/list
    // ----------------------------------------------------------
    case 'list_users':
        if (!canHelpdesk()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

        $result = litellmRequest('GET', '/customer/list');
        if ($result['http_code'] !== 200) http_response_code($result['http_code'] ?: 500);
        echo json_encode($result['data']);
        break;

    // ----------------------------------------------------------
    // GET: List available budget configurations
    // Endpoint: GET /budget/list
    // ----------------------------------------------------------
    case 'list_budgets':
        if (!canHelpdesk()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

        $result = litellmRequest('GET', '/budget/list');
        if ($result['http_code'] !== 200) http_response_code($result['http_code'] ?: 500);
        echo json_encode($result['data']);
        break;

	// ----------------------------------------------------------
	// POST: Assign / update budget for an end user
	// Endpoint: POST /customer/update
	// ----------------------------------------------------------
	case 'update_budget':
		if (!canHelpdesk()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$endUserId      = sanitizeString($input['username']        ?? '');
		$budgetId       = sanitizeString($input['budget_id']       ?? '');
		$budgetDuration = sanitizeString($input['budget_duration'] ?? '');
		$maxBudget      = isset($input['max_budget']) ? (float)$input['max_budget'] : null;

		if (empty($endUserId)) {
			http_response_code(400);
			echo json_encode(['error' => 'Email Address is required']);
			break;
		}

		// FIX: LiteLLM /customer/update requires "user_id", NOT "username"
		$body = ['user_id' => $endUserId];
		if (!empty($budgetId))       $body['budget_id']       = $budgetId;
		if (!empty($budgetDuration)) $body['budget_duration'] = $budgetDuration;
		if ($maxBudget !== null)     $body['max_budget']      = $maxBudget;

		$result = litellmRequest('POST', '/customer/update', [], $body);
		if ($result['http_code'] !== 200) {
			http_response_code($result['http_code'] ?: 500);
			echo json_encode($result['data'] ?? ['error' => 'Failed to update budget']);
			break;
		}
		echo json_encode(['success' => true, 'message' => 'Budget updated for ' . $endUserId]);
		break;

    // ----------------------------------------------------------
    // GET: Export CSV of spend logs for a specific end user
    // Endpoint: GET /spend/logs?end_user=<id>
    // ----------------------------------------------------------
	case 'export_user_logs_csv':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$endUserId = sanitizeString($_GET['username']   ?? '');
		$startDate = sanitizeString($_GET['start_date'] ?? '');
		$endDate   = sanitizeString($_GET['end_date']   ?? '');

		if (empty($endUserId)) {
			echo json_encode(['error' => 'Username is required']); exit;
		}
		if (empty($startDate)) {
			echo json_encode(['error' => 'Start Date is required']); exit;
		}
		if (empty($endDate)) {
			echo json_encode(['error' => 'End Date is required']); exit;
		}

		// Convert DD/MM/YYYY → YYYY-MM-DD for the API
		function convertToAPIDate(string $date): string {
			// Already YYYY-MM-DD
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				return $date;
			}
			// DD/MM/YYYY → YYYY-MM-DD
			//if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
			//	return $m[3] . '-' . $m[2] . '-' . $m[1];
			//}
			// MM/DD/YYYY → YYYY-MM-DD
			if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
				return $m[3] . '-' . $m[1] . '-' . $m[2];
			}
			// MM/DD/YY → YYYY-MM-DD
			if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $date, $m)) {
				return '20' . $m[3] . '-' . $m[1] . '-' . $m[2];
			}
			return $date;
		}

			$apiStartDate = convertToAPIDate($startDate);
			$apiEndDate   = bumpEndDate(convertToAPIDate($endDate)); // +1 day for inclusive end date

		// Paginate through all pages of /spend/logs/v2
		// Filter results client-side to the requested end_user
		// page_size capped at 100 to avoid system errors
		$allLogs    = [];
		$page       = 1;
		$totalPages = 1;

		do {
			$params = [
				'start_date' => $apiStartDate,
				'end_date'   => $apiEndDate,
				'page'       => $page,
				'page_size'  => 100,
			];

			$result = litellmRequest('GET', '/spend/logs/v2', $params);

			if ($result['http_code'] !== 200 || isset($result['data']['error'])) {
				http_response_code($result['http_code'] ?: 500);
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['error' => $result['data']['error'] ?? 'Failed to fetch logs']);
				exit;
			}

			$responseData = $result['data'];
			$pageData     = $responseData['data']        ?? [];
			$totalPages   = (int)($responseData['total_pages'] ?? 1);

			// Filter to only logs matching the requested end_user
			foreach ($pageData as $log) {
				$logEndUser = $log['end_user'] ?? '';
				if ($logEndUser === $endUserId) {
					$allLogs[] = $log;
				}
			}

			$page++;

			// Safety limit
			if ($page > 100) break;

		} while ($page <= $totalPages);

		// Sort logs by startTime ascending
		usort($allLogs, function($a, $b) {
			return strcmp($a['startTime'] ?? '', $b['startTime'] ?? '');
		});

		// Pre-calculate daily totals and grand total
		// Keyed by YYYY-MM-DD date portion of startTime
		$dailyTotals = [];
		$grandTotal  = 0.0;

		foreach ($allLogs as $log) {
			$rawDateTime = $log['startTime'] ?? '';
			$dateKey     = substr($rawDateTime, 0, 10); // YYYY-MM-DD
			$spend       = (float)($log['spend'] ?? 0);

			if (!isset($dailyTotals[$dateKey])) {
				$dailyTotals[$dateKey] = 0.0;
			}
			$dailyTotals[$dateKey] += $spend;
			$grandTotal            += $spend;
		}

		$grandTotalFormatted = number_format($grandTotal, 6);

		$csvHeaders = [
			'Date',
			'Time',
			'End User',
			'Model',
			'Prompt Tokens',
			'Completion Tokens',
			'Total Tokens',
			'Cost ($)',
			'Request Duration (s)',
			'Daily Total',
			'Total Spent in Date Range',
		];

		$rows = [];

		foreach ($allLogs as $log) {
			$rawDateTime = $log['startTime'] ?? '';
			$dateKey     = substr($rawDateTime, 0, 10); // YYYY-MM-DD for lookup

			// Format date YYYY-MM-DD and time HH:MM:SS
			$formattedDate = '';
			$formattedTime = '';
			if ($rawDateTime) {
				$ts = strtotime($rawDateTime);
				if ($ts) {
					$formattedDate = date('Y-m-d', $ts);
					$formattedTime = date('H:i:s', $ts);
				}
			}

			// Convert request_duration_ms to seconds with 2 decimal places
			$durationMs = (float)($log['request_duration_ms'] ?? 0);
			$durationS  = number_format($durationMs / 1000, 2);

			// Daily total spend for this date
			$dailyTotal = number_format($dailyTotals[$dateKey] ?? 0.0, 6);

			$rows[] = [
				$formattedDate,
				$formattedTime,
				$endUserId,
				$log['model']             ?? '',
				$log['prompt_tokens']     ?? '0',
				$log['completion_tokens'] ?? '0',
				$log['total_tokens']      ?? '0',
				number_format((float)($log['spend'] ?? 0), 6),
				$durationS,
				$dailyTotal,
				$grandTotalFormatted,
			];
		}

		if (empty($rows)) {
			$safeId = preg_replace('/[^a-zA-Z0-9_\-@\.]/', '_', $endUserId);
			outputCsvHeaders('chat_logs_' . $safeId . '_' . date('Ymd') . '.csv');
			echo arrayToCsv([], $csvHeaders);
			exit;
		}

		$safeId = preg_replace('/[^a-zA-Z0-9_\-@\.]/', '_', $endUserId);
		outputCsvHeaders('chat_logs_' . $safeId . '_' . date('Ymd') . '.csv');
		echo arrayToCsv($rows, $csvHeaders);
		exit;

	// ----------------------------------------------------------
	// GET: Export spend summary CSV for ALL end users
	// ----------------------------------------------------------
	case 'export_all_spend_csv':
		if (!canAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

		$startDate = sanitizeString($_GET['start_date'] ?? '');
		$endDate   = sanitizeString($_GET['end_date']   ?? '');

		if (empty($startDate)) {
			echo json_encode(['error' => 'Start Date is required']); exit;
		}
		if (empty($endDate)) {
			echo json_encode(['error' => 'End Date is required']); exit;
		}

		// Convert DD/MM/YYYY or MM/DD/YY → YYYY-MM-DD for the API
		function convertToAPIDate(string $date): string {
			// Already YYYY-MM-DD
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				return $date;
			}
			// DD/MM/YYYY → YYYY-MM-DD
			//if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
			//	return $m[3] . '-' . $m[2] . '-' . $m[1];
			//}
			// MM/DD/YYYY → YYYY-MM-DD
			if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
				return $m[3] . '-' . $m[1] . '-' . $m[2];
			}
			// MM/DD/YY → YYYY-MM-DD
			if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $date, $m)) {
				return '20' . $m[3] . '-' . $m[1] . '-' . $m[2];
			}
			return $date;
		}

			$apiStartDate = convertToAPIDate($startDate);
			$apiEndDate   = bumpEndDate(convertToAPIDate($endDate)); // +1 day for inclusive end date

		// Paginate through all pages of /spend/logs/v2
		// page_size capped at 100 to avoid system errors
		$allLogs    = [];
		$page       = 1;
		$totalPages = 1;

		do {
			$params = [
				'start_date' => $apiStartDate,
				'end_date'   => $apiEndDate,
				'page'       => $page,
				'page_size'  => 100,
			];

			$result = litellmRequest('GET', '/spend/logs/v2', $params);

			if ($result['http_code'] !== 200 || isset($result['data']['error'])) {
				http_response_code($result['http_code'] ?: 500);
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['error' => $result['data']['error'] ?? 'Failed to fetch logs']);
				exit;
			}

			$responseData = $result['data'];
			$pageData     = $responseData['data']        ?? [];
			$totalPages   = (int)($responseData['total_pages'] ?? 1);

			foreach ($pageData as $log) {
				$allLogs[] = $log;
			}

			$page++;

			// Safety limit
			if ($page > 100) break;

		} while ($page <= $totalPages);

		// Group all logs by date (YYYY-MM-DD) and sum spend per day
		$dailySpend = [];
		$grandTotal = 0.0;

		foreach ($allLogs as $log) {
			$rawDateTime = $log['startTime'] ?? '';
			$dateKey     = substr($rawDateTime, 0, 10); // YYYY-MM-DD
			$spend       = (float)($log['spend'] ?? 0);

			if (empty($dateKey)) continue;

			if (!isset($dailySpend[$dateKey])) {
				$dailySpend[$dateKey] = 0.0;
			}
			$dailySpend[$dateKey] += $spend;
			$grandTotal           += $spend;
		}

		// Sort by date ascending
		ksort($dailySpend);

		$grandTotalFormatted = number_format($grandTotal, 6);

		$csvHeaders = [
			'Date',
			'Date Total Cost',
			'Total Spend for Date Range',
		];

		$rows = [];

		foreach ($dailySpend as $dateKey => $daySpend) {
			// Format date YYYY-MM-DD for CSV output
			$ts            = strtotime($dateKey);
			$formattedDate = $ts ? date('Y-m-d', $ts) : $dateKey;

			$rows[] = [
				$formattedDate,
				number_format($daySpend, 6),
				$grandTotalFormatted,
			];
		}

		// Build filename from selected date range
		$safeStart = str_replace('/', '-', $startDate);
		$safeEnd   = str_replace('/', '-', $endDate);

		if (empty($rows)) {
			outputCsvHeaders('spend_all_' . $safeStart . '_to_' . $safeEnd . '_' . date('Ymd') . '.csv');
			echo arrayToCsv([], $csvHeaders);
			exit;
		}

		outputCsvHeaders('spend_all_' . $safeStart . '_to_' . $safeEnd . '_' . date('Ymd') . '.csv');
		echo arrayToCsv($rows, $csvHeaders);
		exit;

    // ----------------------------------------------------------
    // GET: Session info
    // ----------------------------------------------------------
    case 'session_info':
        echo json_encode([
            'username'     => $_SESSION['username']     ?? '',
            'display_name' => $_SESSION['display_name'] ?? '',
            'role'         => getRole(),
            'can_admin'    => canAdmin(),
            'can_helpdesk' => canHelpdesk(),
        ]);
        break;

    // ----------------------------------------------------------
    // GET: Month-to-date spend summary
    // ----------------------------------------------------------
    case 'spend_summary':
        if (!canHelpdesk()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

        $result = litellmRequest('GET', '/spend/logs', [
            'start_date' => date('Y-m-01'),
            'end_date'   => date('Y-m-d'),
        ]);
        if ($result['http_code'] !== 200) http_response_code($result['http_code'] ?: 500);
        echo json_encode($result['data']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
        break;
}