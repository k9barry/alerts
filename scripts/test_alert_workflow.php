<?php
/**
 * Test Alert Workflow Script
 * 
 * This script tests the complete workflow of an alert from download from the API
 * to the final sending to a user. It uses a random record from the API download
 * for the test message and prompts for which user should receive the test.
 * All results are logged to Dozzle in a clear report format.
 * 
 * Workflow Steps:
 *   1. Fetch active alerts from weather.gov API
 *   2. Select a random alert for testing
 *   3. Retrieve list of configured users
 *   4. Prompt for user selection (interactive)
 *   5. Build and send test notification message
 *   6. Report results to console and logs
 * 
 * Usage:
 *   php scripts/test_alert_workflow.php
 *   docker exec -it alerts php scripts/test_alert_workflow.php
 * 
 * Prerequisites:
 *   - Database initialized (migrate.php run)
 *   - At least one user configured with notification credentials
 *   - Internet connectivity for API access
 * 
 * Exit Codes:
 *   - 0: Test completed successfully
 *   - 1: Test failed (no alerts, no users, notification error, etc.)
 * 
 * Environment Variables:
 *   - PUSHOVER_ENABLED: Enable/disable Pushover notifications
 *   - NTFY_ENABLED: Enable/disable ntfy notifications
 *   - All other Config variables (API URLs, etc.)
 * 
 * Logs:
 *   All operations are logged via LoggerFactory to stdout/Dozzle
 *   with structured JSON format for easy filtering and analysis.
 * 
 * @package Alerts
 * @author  Alerts Team
 * @license MIT
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\DB\Connection;
use App\Service\AlertFetcher;
use App\Service\AlertProcessor;
use App\Service\PushoverNotifier;
use App\Service\NtfyNotifier;
use App\Repository\AlertsRepository;
use App\Logging\LoggerFactory;

$logger = LoggerFactory::get();

// Print header
echo str_repeat("=", 70) . "\n";
echo "ALERT WORKFLOW TEST\n";
echo str_repeat("=", 70) . "\n\n";

$logger->info("=== STARTING ALERT WORKFLOW TEST ===");

try {
    // Step 1: Fetch alerts from API
    echo "Step 1: Fetching alerts from weather.gov API...\n";
    $logger->info("Step 1: Fetching alerts from API", ['url' => Config::$weatherApiUrl]);
    
    $fetcher = new AlertFetcher();
    $alertCount = $fetcher->fetchAndStoreIncoming();
    
    echo "  ✓ Fetched {$alertCount} alerts from API\n\n";
    $logger->info("Fetch completed", ['alert_count' => $alertCount]);
    
    if ($alertCount === 0) {
        echo "No alerts available for testing. Exiting.\n";
        $logger->warning("No alerts available for testing");
        exit(0);
    }
    
    // Step 2: Get a random alert for testing
    echo "Step 2: Selecting a random alert for testing...\n";
    $logger->info("Step 2: Selecting random alert for testing");
    
    $repo = new AlertsRepository();
    $pdo = Connection::get();
    
    $stmt = $pdo->query("SELECT * FROM incoming_alerts ORDER BY RANDOM() LIMIT 1");
    $testAlert = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$testAlert) {
        echo "  ✗ Could not retrieve a test alert\n";
        $logger->error("Could not retrieve test alert from incoming_alerts");
        exit(1);
    }
    
    echo "  ✓ Selected alert: {$testAlert['event']}\n";
    echo "    ID: {$testAlert['id']}\n";
    echo "    Severity: {$testAlert['severity']}\n";
    echo "    Area: {$testAlert['area_desc']}\n\n";
    
    $logger->info("Random alert selected", [
        'alert_id' => $testAlert['id'],
        'event' => $testAlert['event'],
        'severity' => $testAlert['severity'],
        'area' => $testAlert['area_desc']
    ]);
    
    // Step 3: Get list of users
    echo "Step 3: Retrieving users list...\n";
    $logger->info("Step 3: Retrieving users list");
    
    $stmt = $pdo->query("SELECT idx, FirstName, LastName, Email, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, NtfyTopic FROM users ORDER BY idx");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "  ✗ No users found in database\n";
        echo "    Please add at least one user via the web interface at http://localhost:8080\n";
        $logger->error("No users found in database");
        exit(1);
    }
    
    echo "  ✓ Found " . count($users) . " user(s)\n\n";
    $logger->info("Users retrieved", ['user_count' => count($users)]);
    
    // Log which fields are available for each user (for debugging)
    if (!empty($users)) {
        $sampleUser = $users[0];
        $logger->debug("User fields retrieved from database", [
            'fields' => array_keys($sampleUser),
            'has_PushoverToken' => isset($sampleUser['PushoverToken']),
            'has_NtfyToken' => isset($sampleUser['NtfyToken']),
            'has_NtfyUser' => isset($sampleUser['NtfyUser']),
            'has_NtfyPassword' => isset($sampleUser['NtfyPassword'])
        ]);
    }
    
    // Step 4: Prompt for user selection
    echo "Step 4: Select a user to receive the test alert:\n\n";
    echo str_repeat("-", 70) . "\n";
    printf("%-5s %-20s %-30s %-15s\n", "ID", "Name", "Email", "Services");
    echo str_repeat("-", 70) . "\n";
    
    foreach ($users as $user) {
        $services = [];
        if (!empty($user['PushoverUser'])) $services[] = 'Pushover';
        if (!empty($user['NtfyTopic'])) $services[] = 'Ntfy';
        $serviceStr = empty($services) ? 'None' : implode(', ', $services);
        
        printf(
            "%-5d %-20s %-30s %-15s\n",
            $user['idx'],
            $user['FirstName'] . ' ' . $user['LastName'],
            $user['Email'],
            $serviceStr
        );
    }
    echo str_repeat("-", 70) . "\n\n";
    
    echo "Enter user ID to send test alert to: ";
    $handle = fopen("php://stdin", "r");
    $userIdInput = trim(fgets($handle));
    fclose($handle);
    
    $selectedUser = null;
    foreach ($users as $user) {
        if ((string)$user['idx'] === $userIdInput) {
            $selectedUser = $user;
            break;
        }
    }
    
    if (!$selectedUser) {
        echo "  ✗ Invalid user ID\n";
        $logger->error("Invalid user ID selected", ['input' => $userIdInput]);
        exit(1);
    }
    
    echo "\n  ✓ Selected: {$selectedUser['FirstName']} {$selectedUser['LastName']} ({$selectedUser['Email']})\n\n";
    $logger->info("User selected for test", [
        'user_id' => $selectedUser['idx'],
        'user_name' => $selectedUser['FirstName'] . ' ' . $selectedUser['LastName'],
        'user_email' => $selectedUser['Email']
    ]);
    
    // Step 5: Build and send test message
    echo "Step 5: Building and sending test alert message...\n";
    $logger->info("Step 5: Building and sending test message");
    
    // Parse alert JSON
    $alertData = json_decode($testAlert['json'], true);
    
    // Build message
    $severity = $testAlert['severity'] ?? 'Unknown';
    $certainty = $testAlert['certainty'] ?? 'Unknown';
    $urgency = $testAlert['urgency'] ?? 'Unknown';
    $event = $testAlert['event'] ?? 'Weather Alert';
    $headline = $testAlert['headline'] ?? '';
    $description = $testAlert['description'] ?? '';
    $areaDesc = $testAlert['area_desc'] ?? '';
    
    // Format timestamps
    $effective = $testAlert['effective'] ?? '';
    $expires = $testAlert['expires'] ?? '';
    
    $message = "[TEST ALERT]\n\n";
    $message .= "Event: {$event}\n";
    $message .= "Severity: {$severity} | Certainty: {$certainty} | Urgency: {$urgency}\n";
    if ($headline) $message .= "Headline: {$headline}\n";
    $message .= "Area: {$areaDesc}\n";
    if ($effective) $message .= "Effective: {$effective}\n";
    if ($expires) $message .= "Expires: {$expires}\n";
    $message .= "\n{$description}\n";
    
    // Get alert URL
    $alertUrl = "https://api.weather.gov/alerts/{$testAlert['id']}";
    
    echo "  Message prepared:\n";
    echo "  " . str_repeat("-", 66) . "\n";
    foreach (explode("\n", $message) as $line) {
        echo "  " . substr($line, 0, 66) . "\n";
    }
    echo "  " . str_repeat("-", 66) . "\n\n";
    
    $results = [];
    
    // Send via Pushover if configured
    $pushoverUser = trim($selectedUser['PushoverUser'] ?? '');
    $pushoverToken = trim($selectedUser['PushoverToken'] ?? '');
    
    $logger->debug("Pushover credentials check", [
        'has_PushoverUser' => !empty($pushoverUser),
        'has_PushoverToken' => !empty($pushoverToken),
        'PushoverUser_length' => strlen($pushoverUser),
        'PushoverToken_length' => strlen($pushoverToken)
    ]);
    
    if (Config::$pushoverEnabled && !empty($pushoverUser) && !empty($pushoverToken)) {
        echo "  Sending via Pushover...\n";
        $logger->info("Attempting Pushover notification");
        
        try {
            $pushover = new PushoverNotifier();
            $result = $pushover->sendAlert(
                $pushoverUser,
                $pushoverToken,
                $event,
                $message,
                $alertUrl
            );
            
            if ($result['success']) {
                echo "    ✓ Pushover sent successfully\n";
                echo "      Request ID: {$result['request_id']}\n";
                $logger->info("Pushover notification sent", [
                    'request_id' => $result['request_id'],
                    'user' => $selectedUser['Email']
                ]);
                $results['pushover'] = 'SUCCESS';
            } else {
                echo "    ✗ Pushover failed\n";
                echo "      Error: {$result['error']}\n";
                $logger->error("Pushover notification failed", [
                    'error' => $result['error'],
                    'user' => $selectedUser['Email']
                ]);
                $results['pushover'] = 'FAILED: ' . $result['error'];
            }
        } catch (Exception $e) {
            echo "    ✗ Pushover exception: {$e->getMessage()}\n";
            $logger->error("Pushover exception", [
                'error' => $e->getMessage(),
                'user' => $selectedUser['Email']
            ]);
            $results['pushover'] = 'ERROR: ' . $e->getMessage();
        }
    } else {
        // Determine the specific reason why Pushover was skipped
        if (!Config::$pushoverEnabled) {
            $reason = 'disabled';
        } elseif (empty($pushoverUser)) {
            $reason = 'missing PushoverUser';
        } elseif (empty($pushoverToken)) {
            $reason = 'missing PushoverToken';
        } else {
            $reason = 'not configured for user';
        }
        echo "  ⊘ Pushover skipped ({$reason})\n";
        $logger->info("Pushover skipped", ['reason' => $reason]);
        $results['pushover'] = 'SKIPPED: ' . $reason;
    }
    
    // Send via Ntfy if configured
    $ntfyTopic = trim($selectedUser['NtfyTopic'] ?? '');
    $ntfyUser = !empty($selectedUser['NtfyUser']) ? trim($selectedUser['NtfyUser']) : null;
    $ntfyPassword = !empty($selectedUser['NtfyPassword']) ? trim($selectedUser['NtfyPassword']) : null;
    $ntfyToken = !empty($selectedUser['NtfyToken']) ? trim($selectedUser['NtfyToken']) : null;
    
    $logger->debug("Ntfy credentials check", [
        'has_NtfyTopic' => !empty($ntfyTopic),
        'has_NtfyToken' => !empty($ntfyToken),
        'has_NtfyUser' => !empty($ntfyUser),
        'has_NtfyPassword' => !empty($ntfyPassword),
        'NtfyTopic' => $ntfyTopic
    ]);
    
    if (Config::$ntfyEnabled && !empty($ntfyTopic)) {
        echo "  Sending via Ntfy...\n";
        $logger->info("Attempting Ntfy notification", [
            'topic' => $ntfyTopic,
            'has_token' => !empty($ntfyToken),
            'has_user_pass' => !empty($ntfyUser) && !empty($ntfyPassword)
        ]);
        
        try {
            $ntfy = new NtfyNotifier();
            $result = $ntfy->sendAlert(
                $ntfyTopic,
                $event,
                $message,
                $alertUrl,
                $ntfyUser,
                $ntfyPassword,
                $ntfyToken
            );
            
            if ($result['success']) {
                echo "    ✓ Ntfy sent successfully\n";
                $logger->info("Ntfy notification sent", [
                    'topic' => $ntfyTopic,
                    'user' => $selectedUser['Email']
                ]);
                $results['ntfy'] = 'SUCCESS';
            } else {
                echo "    ✗ Ntfy failed\n";
                echo "      Error: {$result['error']}\n";
                $logger->error("Ntfy notification failed", [
                    'error' => $result['error'],
                    'user' => $selectedUser['Email']
                ]);
                $results['ntfy'] = 'FAILED: ' . $result['error'];
            }
        } catch (Exception $e) {
            echo "    ✗ Ntfy exception: {$e->getMessage()}\n";
            $logger->error("Ntfy exception", [
                'error' => $e->getMessage(),
                'user' => $selectedUser['Email']
            ]);
            $results['ntfy'] = 'ERROR: ' . $e->getMessage();
        }
    } else {
        // Determine the specific reason why Ntfy was skipped
        if (!Config::$ntfyEnabled) {
            $reason = 'disabled';
        } elseif (empty($ntfyTopic)) {
            $reason = 'missing NtfyTopic';
        } else {
            $reason = 'not configured for user';
        }
        echo "  ⊘ Ntfy skipped ({$reason})\n";
        $logger->info("Ntfy skipped", ['reason' => $reason]);
        $results['ntfy'] = 'SKIPPED: ' . $reason;
    }
    
    // Step 6: Report results
    echo "\n";
    echo str_repeat("=", 70) . "\n";
    echo "TEST WORKFLOW REPORT\n";
    echo str_repeat("=", 70) . "\n\n";
    
    echo "Test Alert Details:\n";
    echo "  Event: {$event}\n";
    echo "  Alert ID: {$testAlert['id']}\n";
    echo "  Severity: {$severity}\n";
    echo "  Area: {$areaDesc}\n\n";
    
    echo "Target User:\n";
    echo "  Name: {$selectedUser['FirstName']} {$selectedUser['LastName']}\n";
    echo "  Email: {$selectedUser['Email']}\n";
    echo "  User ID: {$selectedUser['idx']}\n\n";
    
    echo "Notification Results:\n";
    foreach ($results as $channel => $result) {
        $icon = str_starts_with($result, 'SUCCESS') ? '✓' : 
                (str_starts_with($result, 'SKIPPED') ? '⊘' : '✗');
        echo "  {$icon} " . ucfirst($channel) . ": {$result}\n";
    }
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "Test completed successfully!\n";
    echo "Check Dozzle logs at http://localhost:9999 for detailed information.\n";
    echo str_repeat("=", 70) . "\n";
    
    $logger->info("=== ALERT WORKFLOW TEST COMPLETED ===", [
        'alert_id' => $testAlert['id'],
        'user_id' => $selectedUser['idx'],
        'results' => $results
    ]);
    
} catch (Throwable $e) {
    echo "\n✗ Test failed with error:\n";
    echo "  {$e->getMessage()}\n";
    echo "  File: {$e->getFile()}:{$e->getLine()}\n\n";
    
    $logger->error("Alert workflow test failed", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    exit(1);
}
