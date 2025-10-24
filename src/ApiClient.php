<?php

namespace Alerts;

use Psr\Log\LoggerInterface;

/**
 * Weather.gov API Client
 * 
 * Handles communication with the weather.gov alerts API,
 * including rate limiting and proper user agent headers.
 */
class ApiClient
{
    /**
     * API base URL
     * 
     * @var string
     */
    private string $baseUrl;
    
    /**
     * Logger instance
     * 
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    
    /**
     * User agent string
     * 
     * @var string
     */
    private string $userAgent;
    
    /**
     * Rate limit (calls per period)
     * 
     * @var int
     */
    private int $rateLimit;
    
    /**
     * Rate limit period in seconds
     * 
     * @var int
     */
    private int $ratePeriod;
    
    /**
     * Database instance for rate limiting
     * 
     * @var Database
     */
    private Database $database;
    
    /**
     * Constructor
     *
     * @param string $baseUrl API base URL
     * @param string $appName Application name
     * @param string $appVersion Application version
     * @param string $contactEmail Contact email
     * @param int $rateLimit Rate limit (calls per period)
     * @param int $ratePeriod Rate period in seconds
     * @param Database $database Database instance
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        string $baseUrl,
        string $appName,
        string $appVersion,
        string $contactEmail,
        int $rateLimit,
        int $ratePeriod,
        Database $database,
        LoggerInterface $logger
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->rateLimit = $rateLimit;
        $this->ratePeriod = $ratePeriod;
        $this->database = $database;
        $this->logger = $logger;
        
        // Construct user agent: (appName/version contactEmail)
        $this->userAgent = sprintf(
            '%s/%s (%s)',
            $appName,
            $appVersion,
            $contactEmail
        );
        
        $this->logger->debug("API Client initialized with User-Agent: {$this->userAgent}");
    }
    
    /**
     * Wait if necessary to comply with rate limiting
     *
     * @return void
     */
    private function waitForRateLimit(): void
    {
        $recentCalls = $this->database->getRecentApiCallCount($this->ratePeriod);
        
        if ($recentCalls >= $this->rateLimit) {
            $waitTime = $this->ratePeriod;
            $this->logger->info("Rate limit reached ($recentCalls/$this->rateLimit). Waiting {$waitTime} seconds...");
            sleep($waitTime);
        }
    }
    
    /**
     * Fetch alerts from the API
     *
     * @param array $params Query parameters for the API
     * @return array|null Array of alerts or null on failure
     */
    public function fetchAlerts(array $params = []): ?array
    {
        // Apply rate limiting
        $this->waitForRateLimit();
        
        $url = $this->baseUrl;
        
        // Add query parameters
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $this->logger->info("Fetching alerts from: $url");
        
        try {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/geo+json',
                ],
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($error) {
                $this->logger->error("cURL error: $error");
                $this->database->recordApiCall(false, 0, $error);
                return null;
            }
            
            if ($httpCode !== 200) {
                $this->logger->error("HTTP error: $httpCode");
                $this->database->recordApiCall(false, 0, "HTTP $httpCode");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonError = json_last_error_msg();
                $this->logger->error("JSON decode error: $jsonError");
                $this->database->recordApiCall(false, 0, $jsonError);
                return null;
            }
            
            $features = $data['features'] ?? [];
            $alertCount = count($features);
            
            $this->logger->info("Successfully fetched $alertCount alerts");
            $this->database->recordApiCall(true, $alertCount);
            
            return $features;
            
        } catch (\Exception $e) {
            $this->logger->error("Exception while fetching alerts: " . $e->getMessage());
            $this->database->recordApiCall(false, 0, $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fetch a specific alert by ID
     *
     * @param string $alertId Alert identifier
     * @return array|null Alert data or null on failure
     */
    public function fetchAlertById(string $alertId): ?array
    {
        // Apply rate limiting
        $this->waitForRateLimit();
        
        $url = $this->baseUrl . '/' . urlencode($alertId);
        
        $this->logger->info("Fetching alert: $url");
        
        try {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/geo+json',
                ],
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($error) {
                $this->logger->error("cURL error: $error");
                $this->database->recordApiCall(false, 0, $error);
                return null;
            }
            
            if ($httpCode !== 200) {
                $this->logger->error("HTTP error: $httpCode");
                $this->database->recordApiCall(false, 0, "HTTP $httpCode");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonError = json_last_error_msg();
                $this->logger->error("JSON decode error: $jsonError");
                $this->database->recordApiCall(false, 0, $jsonError);
                return null;
            }
            
            $this->logger->info("Successfully fetched alert: $alertId");
            $this->database->recordApiCall(true, 1);
            
            return $data;
            
        } catch (\Exception $e) {
            $this->logger->error("Exception while fetching alert: " . $e->getMessage());
            $this->database->recordApiCall(false, 0, $e->getMessage());
            return null;
        }
    }
}
