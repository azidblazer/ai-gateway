/**
 * LiteLLM Management Portal - Frontend Application
 * jQuery-based SPA communicating with PHP API proxy
 */

(function ($) {
    'use strict';

    // ============================================================
    // State
    // ============================================================
    var State = {
        sessionInfo: null,
        budgets: []
    };

    // ============================================================
    // Utility Functions
    // ============================================================
    var Utils = {

        showLoading: function () {
            $('#loading-overlay')
                .addClass('visible')
                .attr('aria-hidden', 'false');
        },

        hideLoading: function () {
            $('#loading-overlay')
                .removeClass('visible')
                .attr('aria-hidden', 'true');
        },

        notify: function (message, type) {
            type = type || 'info';
            var icons = { success: '✓', error: '✗', info: 'ℹ' };
            var $notif = $('<div>')
                .addClass('notification notification-' + type)
                .attr('role', 'alert')
                .html(
                    '<span aria-hidden="true">' + (icons[type] || '') + '</span> ' +
                    '<span>' + Utils.escapeHtml(Utils.stringifyError(message)) + '</span>' +
                    '<button type="button" class="notification-close" ' +
                    'aria-label="Dismiss notification">&times;</button>'
                );

            $notif.find('.notification-close').on('click', function () {
                $notif.fadeOut(200, function () { $(this).remove(); });
            });

            $('#notification-area').append($notif);

            setTimeout(function () {
                $notif.fadeOut(400, function () { $(this).remove(); });
            }, 5000);
        },

        escapeHtml: function (str) {
            return $('<div>').text(String(str ?? '')).html();
        },

        /**
         * Safely convert any value (including objects/arrays) to a
         * human-readable string for display in error messages.
         * This prevents the dreaded "[object Object]" display.
         */
        stringifyError: function (val) {
            if (val === null || val === undefined) {
                return 'Unknown error';
            }
            if (typeof val === 'string') {
                return val;
            }
            if (typeof val === 'object') {
                // Common LiteLLM error shapes
                if (val.detail && typeof val.detail === 'string') {
                    return val.detail;
                }
                if (val.detail && typeof val.detail === 'object' && val.detail.message) {
                    return val.detail.message;
                }
                if (val.message && typeof val.message === 'string') {
                    return val.message;
                }
                if (val.error && typeof val.error === 'string') {
                    return val.error;
                }
                // Last resort: JSON stringify
                try {
                    return JSON.stringify(val);
                } catch (e) {
                    return 'Unknown error';
                }
            }
            return String(val);
        },

        formatCurrency: function (val) {
            var num = parseFloat(val);
            if (isNaN(num)) return '$0.000000';
            return '$' + num.toFixed(6);
        },

        formatDate: function (str) {
            if (!str) return 'N/A';
            try {
                return new Date(str).toLocaleString();
            } catch (e) {
                return str;
            }
        },

        buildAlert: function (message, type) {
            return '<div class="alert alert-' + type + '" role="alert">'
                + Utils.escapeHtml(Utils.stringifyError(message)) + '</div>';
        },

        todayISO: function () {
            return new Date().toISOString().slice(0, 10);
        },

        firstOfMonthISO: function () {
            var d = new Date();
            d.setDate(1);
            return d.toISOString().slice(0, 10);
        }
    };

    // ============================================================
    // API Module
    // ============================================================
    var API = {

        request: function (options) {
            var settings = $.extend({
                action:     '',
                method:     'GET',
                params:     {},
                body:       null,
                success:    function () {},
                error:      function (msg) { Utils.notify(Utils.stringifyError(msg), 'error'); },
                showLoader: true
            }, options);

            if (settings.showLoader) Utils.showLoading();

            var queryParams = $.extend({ action: settings.action }, settings.params);
            var url = 'api.php?' + $.param(queryParams);

            var ajaxOpts = {
                url:      url,
                type:     settings.method,
                dataType: 'json',
                success: function (response) {
                    if (settings.showLoader) Utils.hideLoading();
                    if (response && response.error) {
                        // response.error may itself be an object
                        settings.error(Utils.stringifyError(response.error));
                    } else {
                        settings.success(response);
                    }
                },
                error: function (jqXHR) {
                    if (settings.showLoader) Utils.hideLoading();
                    var msg = 'Request failed. Please try again.';
                    try {
                        var resp = JSON.parse(jqXHR.responseText);
                        if (resp) msg = Utils.stringifyError(resp.error || resp.detail || resp);
                    } catch (e) { /* keep default */ }
                    settings.error(msg);
                }
            };

            if (settings.method === 'POST' && settings.body !== null) {
                ajaxOpts.contentType = 'application/json';
                ajaxOpts.data        = JSON.stringify(settings.body);
            }

            return $.ajax(ajaxOpts);
        },

        get: function (action, params, success, error, opts) {
            return API.request($.extend({
                action:  action,
                method:  'GET',
                params:  params  || {},
                success: success || function () {},
                error:   error   || function (msg) { Utils.notify(Utils.stringifyError(msg), 'error'); }
            }, opts || {}));
        },

        post: function (action, body, success, error, opts) {
            return API.request($.extend({
                action:  action,
                method:  'POST',
                body:    body    || {},
                success: success || function () {},
                error:   error   || function (msg) { Utils.notify(Utils.stringifyError(msg), 'error'); }
            }, opts || {}));
        },

        downloadFile: function (action, params) {
            var allParams = $.extend({ action: action }, params || {});
            var form = $('<form method="GET" action="api.php"></form>')
                .css('display', 'none');
            $.each(allParams, function (k, v) {
                $('<input type="hidden">').attr('name', k).val(v).appendTo(form);
            });
            $('body').append(form);
            form[0].submit();
            form.remove();
        }
    };

    // ============================================================
    // Authentication Module
    // ============================================================
    var Auth = {

        init: function () {
            if (!APP_CONFIG.isAuthenticated) {
                Auth.initLoginForm();
                return;
            }
            Auth.loadSessionInfo();
        },

        loadSessionInfo: function () {
            API.get('session_info', {}, function (data) {
                State.sessionInfo = data;
                Auth.applyRoleUI(data);
                App.init();
            }, function (msg) {
                Utils.notify('Could not load session info: ' + Utils.stringifyError(msg), 'error');
            }, { showLoader: false });
        },

        applyRoleUI: function (session) {
            $('#display-name-header').text(session.display_name || session.username || '');

            var roleLabel = (session.role || 'user');
            roleLabel = roleLabel.charAt(0).toUpperCase() + roleLabel.slice(1);
            $('#role-badge')
                .text(roleLabel)
                .removeClass('role-admin role-helpdesk role-user')
                .addClass('role-' + (session.role || 'user'))
                .attr('aria-label', 'Your role: ' + (session.role || 'user'));

            if (session.can_admin || session.can_helpdesk) {
                $('.admin-helpdesk-field').show();
                $('.helpdesk-ok').show();
            }
        },

        initLoginForm: function () {
            if (APP_CONFIG.authMode !== 'local') return;

            $('#login-username, #login-password').on('blur', function () {
                var empty = ($(this).val().trim() === '');
                $(this)
                    .toggleClass('error', empty)
                    .attr('aria-invalid', empty ? 'true' : 'false');
            });

            $('#login-form').on('submit', function (e) {
                e.preventDefault();

                var username = $('#login-username').val().trim();
                var password = $('#login-password').val();
                var $submit  = $('#login-submit');
                var $error   = $('#login-error');

                $error.hide().text('').removeAttr('tabindex');

                if (!username || !password) {
                    $error.text('Please enter both username and password.')
                          .show()
                          .attr('tabindex', '-1')
                          .focus();
                    return;
                }

                $submit.prop('disabled', true)
                       .attr('aria-busy', 'true')
                       .text('Signing in\u2026');
                Utils.showLoading();

                $.ajax({
                    url:         'auth.php?action=login',
                    type:        'POST',
                    contentType: 'application/json; charset=utf-8',
                    dataType:    'json',
                    data:        JSON.stringify({ username: username, password: password }),
                    success: function (response) {
                        Utils.hideLoading();
                        if (response && response.success) {
                            window.location.replace('index.php');
                        } else {
                            var errMsg = Utils.stringifyError(
                                (response && response.error) ? response.error : 'Login failed.'
                            );
                            $error.text(errMsg)
                                  .show()
                                  .attr('tabindex', '-1')
                                  .focus();
                            $submit.prop('disabled', false)
                                   .removeAttr('aria-busy')
                                   .text('Sign In');
                        }
                    },
                    error: function (jqXHR) {
                        Utils.hideLoading();
                        var errMsg = 'An error occurred. Please try again.';
                        try {
                            var resp = JSON.parse(jqXHR.responseText);
                            if (resp) errMsg = Utils.stringifyError(resp.error || resp.detail || resp);
                        } catch (ex) { /* keep default */ }
                        $error.text(errMsg)
                              .show()
                              .attr('tabindex', '-1')
                              .focus();
                        $submit.prop('disabled', false)
                               .removeAttr('aria-busy')
                               .text('Sign In');
                    }
                });
            });
        }
    };

    // ============================================================
    // Navigation
    // ============================================================
    var Nav = {
        init: function () {
            $(document).on('click', '.nav-tab', function () {
                Nav.switchTo($(this).data('panel'), $(this));
            });

            $(document).on('keydown', '.nav-tab', function (e) {
                var $tabs = $('.nav-tab:visible');
                var idx   = $tabs.index($(this));
                var next;

                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    next = $tabs.eq((idx + 1) % $tabs.length);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    next = $tabs.eq((idx - 1 + $tabs.length) % $tabs.length);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    next = $tabs.first();
                } else if (e.key === 'End') {
                    e.preventDefault();
                    next = $tabs.last();
                }

                if (next && next.length) {
                    next.focus().trigger('click');
                }
            });
        },

        switchTo: function (panelId, $clickedTab) {
            $('.nav-tab')
                .removeClass('active')
                .attr('aria-selected', 'false')
                .attr('tabindex', '-1');

            var $tab = $clickedTab || $('[data-panel="' + panelId + '"]');
            $tab.addClass('active')
                .attr('aria-selected', 'true')
                .attr('tabindex', '0');

            $('.panel').removeClass('active').attr('hidden', true);
            var $panel = $('#' + panelId);
            $panel.addClass('active').removeAttr('hidden');

            $panel.find('h2').first().attr('tabindex', '-1').focus();
        }
    };

    // ============================================================
    // Spend Module
    // ============================================================
    var Spend = {
        init: function () {
            $('#spend-start-date').val(Utils.firstOfMonthISO());
            $('#spend-end-date').val(Utils.todayISO());

            $('#form-user-spend').on('submit', function (e) {
                e.preventDefault();
                Spend.fetchUserSpend();
            });

            $('#form-check-budget').on('submit', function (e) {
                e.preventDefault();
                Spend.checkBudget();
            });
        },

        fetchUserSpend: function () {
            var startDate = $('#spend-start-date').val();
            var endDate   = $('#spend-end-date').val();
            var $results  = $('#spend-results');

            if (!startDate || !endDate) {
                $results.html(Utils.buildAlert('Please select both start and end dates.', 'error'));
                return;
            }

            var params   = { start_date: startDate, end_date: endDate };
            var username = $('#spend-username').val().trim();
            if (username) params.username = username;

            $results.html('<div class="alert alert-info" role="status">Loading spend data\u2026</div>');

            API.get('user_spend', params, function (data) {
                Spend.renderSpendResults(
                    $results,
                    data,
                    username || (State.sessionInfo ? State.sessionInfo.username : '')
                );
            }, function (msg) {
                $results.html(Utils.buildAlert(
                    'Error loading spend: ' + Utils.stringifyError(msg), 'error'
                ));
            });
        },

        checkBudget: function () {
            var $results = $('#budget-check-results');
            var params   = {};
            var username = $('#budget-check-username').val().trim();
            if (username) params.username = username;

            $results.html('<div class="alert alert-info" role="status">Checking budget\u2026</div>');

            API.get('check_budget', params, function (data) {
                Spend.renderSpendResults(
                    $results,
                    data,
                    username || (State.sessionInfo ? State.sessionInfo.username : '')
                );
            }, function (msg) {
                $results.html(Utils.buildAlert(
                    'Error: ' + Utils.stringifyError(msg), 'error'
                ));
            });
        },

		renderSpendResults: function ($container, data, username) {
			// Normalize — handle both wrapped {user_info:...} and direct shapes
			var info = data;
			if (data && data.user_info) info = data.user_info;

			if (!info) {
				$container.html(Utils.buildAlert('No data returned.', 'warning'));
				return;
			}
			if (info.error) {
				$container.html(Utils.buildAlert('Error: ' + Utils.stringifyError(info.error), 'error'));
				return;
			}

			// ── Array: raw spend log entries ─────────────────────────────────
			if (Array.isArray(info)) {
				if (info.length === 0) {
					$container.html(Utils.buildAlert('No spend records found for the selected period.', 'info'));
					return;
				}
				var logTotal = 0;
				info.forEach(function (r) { logTotal += parseFloat(r.spend || r.cost || 0); });

				var tHtml = '<p><strong>Period total: $' + logTotal.toFixed(6) + '</strong></p>'
					+ '<div class="table-responsive"><table class="spend-table"><thead><tr>'
					+ '<th>Date / Time</th><th>Model</th><th>Prompt Tokens</th>'
					+ '<th>Completion Tokens</th><th>Total Tokens</th><th>Cost</th>'
					+ '</tr></thead><tbody>';
				info.forEach(function (r) {
					tHtml += '<tr>'
						+ '<td>' + Utils.escapeHtml(r.startTime || r.start_time || r.created_at || '') + '</td>'
						+ '<td>' + Utils.escapeHtml(r.model || '') + '</td>'
						+ '<td>' + parseInt(r.prompt_tokens     || 0).toLocaleString() + '</td>'
						+ '<td>' + parseInt(r.completion_tokens || 0).toLocaleString() + '</td>'
						+ '<td>' + parseInt(r.total_tokens      || 0).toLocaleString() + '</td>'
						+ '<td>$' + parseFloat(r.spend || r.cost || 0).toFixed(6) + '</td>'
						+ '</tr>';
				});
				tHtml += '</tbody></table></div>';
				$container.html(tHtml);
				return;
			}

			// ── Single-user summary object ────────────────────────────────────
			var displayName    = info.user_id || info.end_user_id || info.username || username || 'Unknown';
			var currentSpend   = parseFloat(info.spend      || 0);
			var totalSpend     = (info.total_spend  != null) ? parseFloat(info.total_spend)  : null;
			var maxBudget      = (info.max_budget   != null) ? parseFloat(info.max_budget)   : null;
			var budgetId       = info.budget_id       || null;
			var budgetDuration = info.budget_duration || null;
			var budgetResetAt  = info.budget_reset_at || null;

			var rows = '';

			rows += '<tr><th scope="row">End User ID</th>'
				+ '<td>' + Utils.escapeHtml(String(displayName)) + '</td></tr>';

			// Budget ID — the main field that was previously missing
			rows += '<tr><th scope="row">Budget ID</th><td>'
				+ (budgetId
					? '<strong>' + Utils.escapeHtml(String(budgetId)) + '</strong>'
					: '<em style="color:#888">None assigned</em>')
				+ '</td></tr>';

			if (maxBudget !== null)
				rows += '<tr><th scope="row">Max Budget</th>'
					+ '<td>$' + maxBudget.toFixed(2) + '</td></tr>';

			if (budgetDuration)
				rows += '<tr><th scope="row">Budget Duration</th>'
					+ '<td>' + Utils.escapeHtml(String(budgetDuration)) + '</td></tr>';

			// Days until reset
			if (budgetResetAt) {
				var resetDate = new Date(budgetResetAt);
				var now       = new Date();
				var diffDays  = Math.ceil((resetDate - now) / 86400000);
				var dateStr   = resetDate.toLocaleDateString(undefined,
									{ year: 'numeric', month: 'short', day: 'numeric' });
				if (diffDays > 0) {
					rows += '<tr><th scope="row">Budget Resets In</th>'
						+ '<td>' + diffDays + ' day(s) &mdash; ' + dateStr + '</td></tr>';
				} else if (diffDays === 0) {
					rows += '<tr><th scope="row">Budget Resets</th>'
						+ '<td>Today (' + dateStr + ')</td></tr>';
				} else {
					rows += '<tr><th scope="row">Budget Reset At</th>'
						+ '<td>' + dateStr
						+ ' <span style="color:#b45309;font-weight:600">(reset overdue)</span>'
						+ '</td></tr>';
				}
			}

			// Progress bar (only when a max budget is set)
			if (maxBudget !== null && maxBudget > 0) {
				var pct      = Math.min(100, (currentSpend / maxBudget) * 100);
				var pctStr   = pct.toFixed(1);
				var barColor = pct >= 90 ? '#dc2626' : (pct >= 70 ? '#d97706' : '#16a34a');
				rows += '<tr><th scope="row">Budget Usage</th><td>'
					+ '<div style="background:#e5e7eb;border-radius:6px;height:18px;'
					+ 'overflow:hidden;margin-bottom:4px">'
					+ '<div style="background:' + barColor + ';height:100%;width:' + pctStr + '%;'
					+ 'min-width:2em;display:flex;align-items:center;justify-content:center;'
					+ 'color:#fff;font-size:.75rem;font-weight:700">'
					+ pctStr + '%</div></div>'
					+ '<small>$' + currentSpend.toFixed(6) + ' used of $'
					+ maxBudget.toFixed(2) + '</small>'
					+ '</td></tr>';
			}

			// Spent this period (since last budget reset)
			rows += '<tr><th scope="row">Spent This Period</th>'
				+ '<td><strong>$' + currentSpend.toFixed(6) + '</strong></td></tr>';

			// All-time lifetime spend (new field from updated check_budget API)
			if (totalSpend !== null)
				rows += '<tr><th scope="row">Total Spent (All Time)</th>'
					+ '<td><strong>$' + totalSpend.toFixed(6) + '</strong></td></tr>';

			var html = '<div class="spend-summary-card">'
				+ '<h4 class="spend-summary-title">Budget &amp; Spend Summary</h4>'
				+ '<table class="spend-table"><tbody>' + rows + '</tbody></table>'
				+ '</div>';

			// Append a spend log table if the response also carries one (user_spend query)
			var spendLogs = info.spend_logs
				|| (data && Array.isArray(data.spend_logs) ? data.spend_logs : null);
			if (Array.isArray(spendLogs) && spendLogs.length > 0) {
				var periodTotal = 0;
				spendLogs.forEach(function (r) { periodTotal += parseFloat(r.spend || r.cost || 0); });
				html += '<h4 class="spend-summary-title" style="margin-top:1.5rem">'
					+ 'Spend Log for Selected Period</h4>'
					+ '<p><strong>Period total: $' + periodTotal.toFixed(6) + '</strong></p>'
					+ '<div class="table-responsive"><table class="spend-table"><thead><tr>'
					+ '<th>Date / Time</th><th>Model</th><th>Tokens</th><th>Cost</th>'
					+ '</tr></thead><tbody>';
				spendLogs.forEach(function (r) {
					html += '<tr>'
						+ '<td>' + Utils.escapeHtml(r.startTime || r.start_time || r.created_at || '') + '</td>'
						+ '<td>' + Utils.escapeHtml(r.model || '') + '</td>'
						+ '<td>' + parseInt(r.total_tokens || 0).toLocaleString() + '</td>'
						+ '<td>$' + parseFloat(r.spend || r.cost || 0).toFixed(6) + '</td>'
						+ '</tr>';
				});
				html += '</tbody></table></div>';
			}

			$container.html(html);
	}};

		// ============================================================
		// Budget Module
		// ============================================================
		var Budget = {

			init: function () {
				Budget.loadBudgetOptions();

				$('#btn-refresh-budgets').on('click', function () {
					Budget.loadBudgetOptions(true);
				});

				$('#form-update-budget').on('submit', function (e) {
					e.preventDefault();
					Budget.updateBudget();
				});

			},

			loadBudgetOptions: function (showNotification) {
				var $select = $('#budget-select');
				$select.prop('disabled', true)
					   .html('<option value="">Loading\u2026</option>');

				API.get('list_budgets', {}, function (data) {
					$select.empty()
						   .append('<option value="">-- Select a Budget --</option>');

					var budgets = data;
					if (data && data.budgets) budgets = data.budgets;
					if (!Array.isArray(budgets)) {
						budgets = (typeof budgets === 'object') ? Object.values(budgets) : [];
					}

					State.budgets = budgets;

					if (!budgets.length) {
						$select.append(
							'<option value="" disabled>No budgets configured on server</option>'
						);
					} else {
						budgets.forEach(function (b) {
							var id  = b.budget_id || b.id || '';
							var lbl = id;
							if (b.max_budget != null)
								lbl += '  ($' + parseFloat(b.max_budget).toFixed(2) + ')';
							if (b.budget_duration)
								lbl += '  /  ' + b.budget_duration;
							$select.append($('<option>', { value: id, text: lbl }));
						});
					}

					$select.prop('disabled', false);
					if (showNotification) Utils.notify('Budget list refreshed.', 'success');

				}, function (msg) {
					$select.html('<option value="">-- Error loading budgets --</option>')
						   .prop('disabled', false);
					Utils.notify('Failed to load budgets: ' + Utils.stringifyError(msg), 'error');
				});
			},

			// NOTE: updateBudget now ends with , (comma) because checkBudget follows it
			updateBudget: function () {
				var username = $('#budget-username').val().trim();
				var budgetId = $('#budget-select').val();
				var $results = $('#budget-update-results');

				$results.empty();
				$('#budget-username').removeClass('error').removeAttr('aria-invalid');

				if (!username) {
					$results.html(Utils.buildAlert('End User ID is required.', 'error'));
					$('#budget-username').addClass('error').attr('aria-invalid', 'true').focus();
					return;
				}

				if (!budgetId) {
					$results.html(Utils.buildAlert('Please select a budget.', 'error'));
					return;
				}

				var payload = {
					username:  username,
					budget_id: budgetId
				};

				$results.html(Utils.buildAlert('Assigning budget\u2026', 'info'));

				API.post('update_budget', payload, function (data) {
					// Build detailed notification showing exactly what was assigned
					var details = [
						'&bull;&nbsp;<strong>Budget ID:</strong> ' + Utils.escapeHtml(budgetId)
					];

					// Pull extra details (max_budget, duration) from the cached budget list
					if (State.budgets && State.budgets.length) {
						for (var i = 0; i < State.budgets.length; i++) {
							var b = State.budgets[i];
							if ((b.budget_id || b.id) === budgetId) {
								if (b.max_budget != null)
									details.push('&bull;&nbsp;<strong>Max Budget:</strong> $'
										+ parseFloat(b.max_budget).toFixed(2));
								if (b.budget_duration)
									details.push('&bull;&nbsp;<strong>Duration:</strong> '
										+ Utils.escapeHtml(b.budget_duration));
								break;
							}
						}
					}

					var msg = 'Budget assigned to '
						+ Utils.escapeHtml(username);
					$results.html(Utils.buildAlert(msg, 'success'));
					Utils.notify('Budget assigned to ' + username, 'success');

				}, function (msg) {
					$results.html(Utils.buildAlert(
						'Failed to update budget: ' + Utils.stringifyError(msg), 'error'
					));
				});
			}

		};

	// ============================================================
	// Cost Index & Bulk Budget Module
	// ============================================================
	var CostIndex = {

		init: function () {
			// Bulk budget CSV upload (multipart POST)
			$('#form-bulk-budget').on('submit', function (e) {
				e.preventDefault();
				var file = $('#bulk-budget-file')[0].files[0];
				if (!file) { $('#bulk-budget-status').text('Please choose a CSV file.'); return; }

				var fd = new FormData();
				fd.append('csv_file', file);

				$('#bulk-budget-status').text('Uploading and processing…');
				$.ajax({
					url: 'api.php?action=bulk_budget_csv',
					method: 'POST',
					data: fd,
					processData: false,
					contentType: false
				}).done(function (res) {
					var msg = 'Updated: ' + (res.updated_count || 0)
							+ ', Created: ' + (res.created_count || 0)
							+ ', Failed: '  + (res.failed_count  || 0);
					if (res.failed_count > 0 && res.details && res.details.failed) {
						msg += ' — ' + res.details.failed.map(function (f) {
							return f.user + ' (' + f.error + ')';
						}).join('; ');
					}
					$('#bulk-budget-status').text(msg);
				}).fail(function (xhr) {
					var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Upload failed';
					$('#bulk-budget-status').text('Error: ' + err);
				});
			});
			// Submit for CSV upload to bulk set cost indexes
			$(document).on('submit', '#form-bulk-cost-index', function (e) {
				e.preventDefault();

				var fileInput = $('#bulk-cost-index-file')[0];
				if (!fileInput || !fileInput.files.length) {
					Utils.notify('Please choose a CSV file first.', 'error');
					return;
				}

				var formData = new FormData();
				formData.append('csv_file', fileInput.files[0]);

				var $status = $('#bulk-cost-index-status');
				$status.html('<div class="alert alert-info" role="status">Uploading\u2026</div>');
				Utils.showLoading();

				$.ajax({
					url:         'api.php?action=bulk_cost_index_csv',
					type:        'POST',
					data:        formData,
					processData: false,   // don't let jQuery serialize FormData
					contentType: false,   // let the browser set the multipart boundary
					dataType:    'json'
				})
				.done(function (resp) {
					$status.html(Utils.buildAlert(
						'Updated: ' + resp.updated_count +
						', Created: ' + resp.created_count +
						', Failed: ' + resp.failed_count,
						resp.success ? 'success' : 'warning'
					));
				})
				.fail(function (jqXHR) {
					var msg = 'Upload failed.';
					try { msg = Utils.stringifyError(JSON.parse(jqXHR.responseText).error); } catch (e) {}
					$status.html(Utils.buildAlert(msg, 'error'));
				})
				.always(function () { Utils.hideLoading(); });
			});
			// Export all users & budgets
			$('#btn-export-users-budgets').on('click', function () {
				window.location.href = 'api.php?action=export_users_budgets_csv';
			});

			// Set / clear cost index
			$('#form-cost-index').on('submit', function (e) {
				e.preventDefault();
				var userId = $('#ci-username').val().trim();
				var ci     = $('#ci-value').val().trim();
				if (!userId) { $('#cost-index-status').text('End User ID is required.'); return; }

				$.ajax({
					url: 'api.php?action=set_cost_index',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify({ user_id: userId, cost_index: ci })
				}).done(function (res) {
					$('#cost-index-status').text(res.message || 'Saved.');
				}).fail(function (xhr) {
					var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Save failed';
					$('#cost-index-status').text('Error: ' + err);
				});
			});

			// Export cost index map
			$('#btn-export-cost-index').on('click', function () {
				window.location.href = 'api.php?action=export_cost_index_csv';
			});

			// Export costs grouped by index
			$('#form-export-index-costs').on('submit', function (e) {
				e.preventDefault();
				var s = $('#idx-start-date').val();
				var d = $('#idx-end-date').val();
				if (!s || !d) { $('#export-index-status').text('Both dates are required.'); return; }
				window.location.href = 'api.php?action=export_index_costs_csv'
					+ '&start_date=' + encodeURIComponent(s)
					+ '&end_date='   + encodeURIComponent(d);
			});
		}
	};

    // ============================================================
    // Export Module
    // ============================================================
    var Export = {
        init: function () {
            $('#form-export-user').on('submit', function (e) {
                e.preventDefault();
                Export.exportUserLogs();
            });

            $('#form-export-all').on('submit', function (e) {
                e.preventDefault();
                Export.exportAllSpend();
            });
        },

        exportUserLogs: function () {
            var username = $('#export-username').val().trim();
            var $status  = $('#export-user-status');

            $status.empty();
            $('#export-username').removeClass('error').removeAttr('aria-invalid');

            if (!username) {
                $status.html(Utils.buildAlert('Username is required.', 'error'));
                $('#export-username').addClass('error')
                                     .attr('aria-invalid', 'true')
                                     .focus();
                return;
            }

            var params = { username: username };
            var sd = $('#export-start-date').val();
            var ed = $('#export-end-date').val();
            if (sd) params.start_date = sd;
            if (ed) params.end_date   = ed;

            $status.html('<div class="alert alert-info" role="status">'
                       + 'Preparing download\u2026</div>');

            API.downloadFile('export_user_logs_csv', params);

            setTimeout(function () {
                $status.html(Utils.buildAlert(
                    'Download started for: ' + Utils.escapeHtml(username)
                    + '. If nothing happened, check your browser download settings.',
                    'success'
                ));
            }, 1500);
        },

		exportAllSpend: function () {
			var sd      = $('#export-all-start-date').val();
			var ed      = $('#export-all-end-date').val();
			var $status = $('#export-all-status');

			if (!sd) {
				$status.html(Utils.buildAlert('Please enter a start date.', 'error'));
				$('#export-all-start-date').focus();
				return;
			}
			if (!ed) {
				$status.html(Utils.buildAlert('Please enter an end date.', 'error'));
				$('#export-all-end-date').focus();
				return;
			}

			var params = {
				start_date: sd,
				end_date:   ed
			};

			$status.html('<div class="alert alert-info" role="status">'
				+ 'Preparing download\u2026</div>');

			API.downloadFile('export_all_spend_csv', params);

			setTimeout(function () {
				$status.html(Utils.buildAlert(
					'Download started. If nothing happened, check your browser download settings.',
					'success'
				));
			}, 1500);
		}
    };

    // ============================================================
    // Main Application
    // ============================================================
    var App = {
        init: function () {
            Nav.init();
            Spend.init();

            if (State.sessionInfo &&
                (State.sessionInfo.can_admin || State.sessionInfo.can_helpdesk)) {
                Budget.init();
                Export.init();
				CostIndex.init();
            }

            // Session heartbeat every 5 minutes
            setInterval(function () {
                $.ajax({
                    url:      'auth.php?action=check',
                    type:     'GET',
                    dataType: 'json',
                    success: function (data) {
                        if (!data.authenticated) {
                            Utils.notify(
                                'Your session has expired. Redirecting to login\u2026',
                                'error'
                            );
                            setTimeout(function () {
                                window.location.replace('index.php');
                            }, 3000);
                        }
                    },
                    error: function () { /* Fail silently */ }
                });
            }, 5 * 60 * 1000);
        }
    };

    // ============================================================
    // Bootstrap
    // ============================================================
    $(document).ready(function () {

        Auth.init();

        // Global AJAX error handler
        $(document).ajaxError(function (event, jqXHR) {
            if (jqXHR.status === 401) {
                Utils.notify('Session expired. Redirecting\u2026', 'error');
                setTimeout(function () {
                    window.location.replace('index.php');
                }, 2000);
            } else if (jqXHR.status === 403) {
                Utils.notify(
                    'Access denied. You do not have permission for this action.',
                    'error'
                );
                Utils.hideLoading();
            }
        });

        // Prevent double-submit
        $(document).on('submit', 'form', function () {
            var $btn = $(this).find('[type="submit"]');
            $btn.prop('disabled', true);
            setTimeout(function () { $btn.prop('disabled', false); }, 3000);
        });
		
		// Submit for CSV upload to bulk adjust budgets
		$(document).on('submit', '#form-bulk-budget', function (e) {
			e.preventDefault();

			var fileInput = $('#bulk-budget-file')[0];
			if (!fileInput || !fileInput.files.length) {
				Utils.notify('Please choose a CSV file first.', 'error');
				return;
			}

			var formData = new FormData();
			formData.append('csv_file', fileInput.files[0]);

			var $status = $('#bulk-budget-status');
			$status.html('<div class="alert alert-info" role="status">Uploading\u2026</div>');
			Utils.showLoading();

			$.ajax({
				url:         'api.php?action=bulk_budget_csv',
				type:        'POST',
				data:        formData,
				processData: false,   // don't let jQuery serialize FormData
				contentType: false,   // let the browser set the multipart boundary
				dataType:    'json'
			})
			.done(function (resp) {
				$status.html(Utils.buildAlert(
					'Updated: ' + resp.updated_count +
					', Created: ' + resp.created_count +
					', Failed: ' + resp.failed_count, 
					resp.success ? 'success' : 'warning'
				));
			})
			.fail(function (jqXHR) {
				var msg = 'Upload failed.';
				try { msg = Utils.stringifyError(JSON.parse(jqXHR.responseText).error); } catch (e) {}
				$status.html(Utils.buildAlert(msg, 'error'));
			})
			.always(function () { Utils.hideLoading(); });
		});
		

    });

}(jQuery));