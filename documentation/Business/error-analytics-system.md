# Error Analytics System

## Overview
Complete error monitoring and analytics system for production environments, similar to the visitor analytics page. Provides at-a-glance insights into what's going wrong in production.

## Components Created

### 1. Database Layer

#### Entity: `ErrorLog`
**Location**: `src/Entity/ErrorLog.php`

Stores comprehensive error information:
- **Basic Info**: Error type, message, severity
- **HTTP Details**: Status code, route, method, URL
- **Location**: File path and line number
- **Stack Trace**: Full exception trace (truncated to 10K chars)
- **User Context**: IP address, user agent, session ID
- **Additional Context**: JSON field for custom data
- **Timestamp**: When the error occurred

**Indexes** for performance:
- `idx_error_logged_at` - Fast time-based queries
- `idx_error_status_code` - Query by HTTP status
- `idx_error_type` - Group by error type

#### Repository: `ErrorLogRepository`
**Location**: `src/Repository/ErrorLogRepository.php`

Provides comprehensive query methods:
- **Time-based counts**: Last hour, 24h, 7d, 30d
- **Error breakdowns**: By type, status code, severity, route
- **Common errors**: Most frequent error messages
- **Time series**: Errors per hour/day for charts
- **Critical errors**: 500+ status codes or high severity
- **IP analysis**: Errors by IP address
- **Metrics**: Error rate, unique types, etc.

### 2. Event Listener

#### `ErrorLoggerSubscriber`
**Location**: `src/EventListener/ErrorLoggerSubscriber.php`

Automatically captures all exceptions via Symfony's KernelEvents::EXCEPTION:

**Features**:
- ✅ Captures exception details (type, message, trace)
- ✅ Extracts HTTP context (route, method, URL, status code)
- ✅ Records user information (IP, user agent, session)
- ✅ Sanitizes sensitive data (passwords, tokens, API keys)
- ✅ Determines severity based on status code
- ✅ Stores custom context (request data, server info)
- ✅ Environment-aware (disabled in dev by default)
- ✅ Graceful failure handling

**Configuration**:
```yaml
# config/services.yaml
_defaults:
    bind:
        $environment: '%kernel.environment%'
```

### 3. Controller & Views

#### Controller: `ErrorAnalyticsController`
**Location**: `src/Controller/Administration/ErrorAnalyticsController.php`

Route: `/admin/error-analytics` (requires ROLE_ADMIN)

Provides comprehensive analytics:
- Error counts by time period (1h, 24h, 7d, 30d)
- Error breakdowns (type, status code, severity)
- Most problematic routes
- Most common errors
- Recent errors (last 20)
- Critical errors (500+ or high severity)
- Time series data for charts
- IP address analysis

#### Template: `error-analytics.html.twig`
**Location**: `templates/admin/error-analytics.html.twig`

**Dashboard Sections**:
1. **Summary Cards**
   - Error counts by time period
   - Error metrics (rate, unique types, critical count)

2. **Critical Errors Alert**
   - Red alert box showing critical errors from last 24h
   - Includes time, type, message, route, status

3. **Time Series Charts**
   - Errors per hour (last 24 hours) - Line chart
   - Errors per day (last 30 days) - Bar chart

4. **Error Breakdowns**
   - By type (exception class names)
   - By status code (color-coded badges)
   - By severity (critical/error/warning/info)

5. **Problematic Routes**
   - Last 24 hours top 10
   - Last 7 days top 10

6. **Common Errors**
   - Last 24 hours most frequent
   - Last 7 days most frequent

7. **IP Analysis**
   - Top 10 IPs generating errors

8. **Recent Errors Table**
   - Last 20 errors with full details
   - Time, severity, type, message, route, status, file:line

### 4. Chart Controllers

#### `errors_per_hour_chart_controller.js`
**Location**: `assets/controllers/errors_per_hour_chart_controller.js`

Red line chart showing error trends over the last 24 hours.

#### `errors_per_day_chart_controller.js`
**Location**: `assets/controllers/errors_per_day_chart_controller.js`

Red bar chart showing error trends over the last 30 days.

## Setup Instructions

### 1. Create Database Migration

```bash
php bin/console make:migration
```

Review the generated migration file, then run:

```bash
php bin/console doctrine:migrations:migrate
```

Or if using Docker:

```bash
docker compose exec php bin/console make:migration
docker compose exec php bin/console doctrine:migrations:migrate
```

### 2. Enable Error Logging

The event subscriber is automatically registered due to `autoconfigure: true` in `services.yaml`.

**To control when errors are logged**, edit `ErrorLoggerSubscriber.php`:

```php
// Line ~28
if ($this->environment === 'dev') {
    return; // Skip logging in dev environment
}
```

Options:
- Log only in production: `if ($this->environment !== 'prod') return;`
- Log everywhere: Remove the check entirely
- Add environment variable: Check `$_ENV['LOG_ERRORS'] ?? false`

### 3. Add to Admin Navigation

Find your admin navigation template (e.g., `templates/admin/nav.html.twig` or `layout.html.twig`) and add:

```twig
<li>
    <a href="{{ path('admin_error_analytics') }}">
        🚨 Error Analytics
    </a>
</li>
```

### 4. Test the System

#### Generate a Test Error:

Create a temporary test route:

```php
#[Route('/test-error', name: 'test_error')]
public function testError(): Response
{
    throw new \RuntimeException('This is a test error for analytics');
}
```

Visit `/test-error` and then check `/admin/error-analytics` to see it logged.

#### Check Database:

```sql
SELECT * FROM error_log ORDER BY logged_at DESC LIMIT 10;
```

## Features & Benefits

### 📊 Comprehensive Analytics
- See what's breaking at a glance
- Identify trends over time
- Spot problematic routes immediately

### 🚨 Critical Error Alerts
- Red alert box for 500+ errors
- Separate view for high-severity issues
- Quick response to production incidents

### 📈 Time Series Visualization
- Hourly breakdown for last 24h
- Daily breakdown for last 30d
- Spot patterns and spikes

### 🔍 Detailed Error Information
- Full exception details
- Stack traces for debugging
- User context (IP, session, user agent)

### 🔒 Security & Privacy
- Automatic sanitization of sensitive data
- Passwords, tokens, API keys redacted
- Session info available for tracking

### ⚡ Performance Optimized
- Database indexes for fast queries
- Truncated stack traces (10K limit)
- Efficient aggregation queries

### 🎨 User-Friendly UI
- Color-coded severity badges
- Responsive tables
- Interactive charts (Chart.js)
- Similar design to visitor analytics

## Usage Examples

### View Recent Errors
```php
$recentErrors = $errorLogRepository->getRecentErrors(20);
```

### Get Error Rate
```php
$errorsPerHour = $errorLogRepository->getErrorRate();
// Returns: float (e.g., 2.5 errors per hour on average)
```

### Find Problematic Routes
```php
$problematicRoutes = $errorLogRepository->getErrorCountByRoute(
    new \DateTimeImmutable('-24 hours'),
    10
);
```

### Get Critical Errors
```php
$criticalErrors = $errorLogRepository->getCriticalErrors(
    new \DateTimeImmutable('-24 hours'),
    15
);
```

## Customization

### Change Severity Levels

Edit `ErrorLoggerSubscriber.php` line ~70:

```php
// Current logic
if ($statusCode >= 500) {
    $errorLog->setSeverity('error');
} elseif ($statusCode >= 400) {
    $errorLog->setSeverity('warning');
} else {
    $errorLog->setSeverity('info');
}

// Custom logic
if ($statusCode >= 500) {
    $errorLog->setSeverity('critical');
} elseif ($statusCode === 404) {
    $errorLog->setSeverity('info'); // Don't alert on 404s
} elseif ($statusCode >= 400) {
    $errorLog->setSeverity('warning');
}
```

### Add Custom Context

In `ErrorLoggerSubscriber.php` around line ~75:

```php
$context = [
    'request_data' => [...],
    'server' => [...],
    'custom' => [
        'user_id' => $this->getCurrentUserId(),
        'tenant_id' => $this->getTenantId(),
        'feature_flags' => $this->getActiveFeatureFlags(),
    ]
];
$errorLog->setContext($context);
```

### Filter Specific Errors

Skip logging certain error types:

```php
// At the start of onKernelException()
if ($exception instanceof NotFoundHttpException) {
    return; // Don't log 404 errors
}

if ($exception instanceof AccessDeniedException) {
    return; // Don't log permission errors
}
```

### Add Notifications

Send alerts for critical errors:

```php
// After saving the error log
if ($errorLog->getSeverity() === 'critical') {
    $this->notificationService->sendAlert(
        'Critical Error',
        $errorLog->getMessage()
    );
}
```

## Maintenance

### Clean Old Errors

Create a command to purge old logs:

```php
#[AsCommand(name: 'app:error-log:clean')]
class CleanErrorLogsCommand extends Command
{
    public function __construct(
        private ErrorLogRepository $errorLogRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = new \DateTimeImmutable('-90 days');
        
        $qb = $this->errorLogRepository->createQueryBuilder('e')
            ->delete()
            ->where('e.loggedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
            
        $output->writeln("Deleted errors older than 90 days");
        return Command::SUCCESS;
    }
}
```

Run with cron:
```bash
# Daily at 3 AM
0 3 * * * cd /path/to/app && php bin/console app:error-log:clean
```

## Troubleshooting

### Errors Not Being Logged?

1. **Check environment setting**:
   ```php
   // ErrorLoggerSubscriber.php line ~28
   if ($this->environment === 'dev') {
       return; // This might be preventing logging
   }
   ```

2. **Verify subscriber is registered**:
   ```bash
   php bin/console debug:event-dispatcher kernel.exception
   ```
   Should show `ErrorLoggerSubscriber::onKernelException`

3. **Check database connection**:
   ```bash
   php bin/console doctrine:query:sql "SELECT COUNT(*) FROM error_log"
   ```

### Page Shows "No Errors"?

1. **Check if errors exist**:
   ```sql
   SELECT COUNT(*) FROM error_log;
   ```

2. **Check time range**:
   Analytics queries use specific time ranges. Ensure you have recent errors.

3. **Manually log a test error** (see Setup Instructions)

### Charts Not Displaying?

1. **Check console for JavaScript errors**
2. **Verify Chart.js is loaded**:
   ```bash
   grep -r "chart.js" assets/
   ```
3. **Ensure controllers are registered**:
   Check `assets/controllers.json` includes the error chart controllers

## Performance Considerations

- **Database indexes**: Already configured for fast queries
- **Trace truncation**: Limited to 10K characters
- **Query limits**: Most queries limited to 10-20 results
- **Time ranges**: Queries use indexed `logged_at` column

### For High-Traffic Sites

Consider:
1. **Sampling**: Only log every Nth error
2. **Async logging**: Queue error logs for background processing
3. **Separate database**: Use dedicated error logging database
4. **Partitioning**: Partition `error_log` table by month

## Comparison with Visitor Analytics

| Feature | Visitor Analytics | Error Analytics |
|---------|-------------------|-----------------|
| **Purpose** | Track user behavior | Monitor errors |
| **Time periods** | 24h, 7d, all time | 1h, 24h, 7d, 30d |
| **Charts** | Line charts | Line + bar charts |
| **Critical view** | No | Yes (red alert) |
| **IP tracking** | Yes (visitors) | Yes (error sources) |
| **Real-time** | Session-based | Error-based |
| **Route analysis** | Most visited | Most problematic |

## Security Notes

- ✅ Passwords automatically redacted
- ✅ API keys and tokens sanitized
- ✅ Cookie data not stored
- ✅ Authorization headers removed
- ✅ Admin-only access (ROLE_ADMIN required)
- ⚠️ Stack traces may contain sensitive paths
- ⚠️ Consider additional sanitization for your use case

## Future Enhancements

Potential additions:
- [ ] Email/Slack notifications for critical errors
- [ ] Error grouping by similarity
- [ ] Source maps for minified JavaScript errors
- [ ] Performance metrics (response time correlation)
- [ ] User-reported errors (client-side logging)
- [ ] Error resolution tracking (mark as fixed)
- [ ] Export to CSV/JSON
- [ ] API endpoint for external monitoring tools
- [ ] Real-time dashboard with WebSockets
- [ ] Machine learning for anomaly detection

## Support

For issues or questions:
1. Check this documentation
2. Review the code comments
3. Check Symfony logs: `var/log/dev.log` or `var/log/prod.log`
4. Verify database schema matches the entity

---

**Created**: December 2, 2024  
**Version**: 1.0.0  
**Status**: ✅ Production Ready

