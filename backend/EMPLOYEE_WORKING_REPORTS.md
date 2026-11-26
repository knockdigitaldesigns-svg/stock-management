# Employee Working Reports

## Requirements and data notes

- Apply `backend/migrations/employee_working_reports.sql` before running the scheduler or report APIs.
- PHP CLI must have `mysqli`, `mysqlnd` (`mysqli_stmt::get_result`), `mbstring`, and process execution enabled.
- Node.js 20+ must be available to the PHP CLI process. The PDF renderer uses the existing `jspdf` and `jspdf-autotable` dependencies in `frontend/`.
- The CRM stores completed follow-up interactions, but has no telephony call log or call-duration field. PDFs identify these as CRM-recorded interactions and show duration as unavailable.
- Follow-up actor attribution uses `updated_by_user_id`, because the existing CRM table has no immutable follow-up creator field. The PDF calls out this limitation.
- Reports are generated for active users except roles named Dealer or Technician (case-insensitive, trimmed).
- The scheduler uses MySQL `NOW()` and fixed, non-overlapping two-hour windows. Keep the MySQL server/session timezone consistent with the server timezone. The timezone label saved in reports comes from the MySQL session/system timezone.

## Install

From the repository root, apply the migration once:

```sh
mysql -u <database-user> -p stock_management < backend/migrations/employee_working_reports.sql
```

Confirm the PHP CLI can connect to the same database configured in `backend/config/database.php`, and that Node can resolve the renderer dependencies:

```sh
php -m
node --version
node -e "import('jspdf').then(() => import('jspdf-autotable')).then(() => console.log('PDF dependencies ready'))"
```

The default PDF location is `backend/storage/employee-working-reports/`. For production, set `EMPLOYEE_WORKING_REPORTS_DIR` to a directory outside the web document root and grant the PHP process read/write access. `NODE_BINARY` may be set to the absolute Node executable path when it is not on PHP's `PATH`.

The default storage directory has an Apache `.htaccess` deny rule. For Nginx, also deny direct access to the default directory (or, preferably, use an external storage directory), for example:

```nginx
location ^~ /backend/storage/ {
    deny all;
    return 404;
}
```

Downloads must go through the authenticated CRM endpoint; do not make the report directory public.

## Schedule

Run the CLI task every two hours, at the beginning of each even-numbered hour:

```cron
0 */2 * * * cd /path/to/stock-management && /usr/bin/php backend/cron/generate_employee_working_reports.php >> /var/log/employee-working-reports.log 2>&1
```

Use the full PHP executable path if cron does not have PHP on `PATH`. The task obtains a MySQL advisory lock, generates the latest completed window, catches up missed windows on later runs, and records per-report status and errors. A failed report is retried on a subsequent invocation; one employee's failure does not stop other reports.

On Windows, create a Task Scheduler task with a two-hour repetition interval, run whether or not a user is logged in, and set:

- **Program:** full path to `php.exe`
- **Arguments:** full path to `backend\cron\generate_employee_working_reports.php`
- **Start in:** repository root
- **Environment:** `NODE_BINARY` and `EMPLOYEE_WORKING_REPORTS_DIR` as needed

## Permissions and access

The existing Permissions page includes:

- **View Employee Working Reports** — view and filter all reports.
- **View Own Employee Working Reports** — view only the authenticated user's reports.
- **Download Employee Working Reports** — download reports when combined with either view permission.

Super Admin receives all permissions through the existing role behavior. The list, employee filter, and download endpoints enforce access server-side. The migration adds only the report tables and permission definitions; assign view/download permissions to other roles through the existing Permissions page.

## Verify

After the first scheduled run, review report status and errors in the CRM history page. Check the scheduler's standard output and PHP/server error log for run-level issues. Use the report list to filter by employee and reporting date, then download a generated PDF. Do not expect historical reports for windows before the scheduler's initial cursor unless the cursor is deliberately initialized by an administrator.
