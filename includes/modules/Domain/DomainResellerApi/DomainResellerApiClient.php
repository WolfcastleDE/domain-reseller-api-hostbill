<?php

/**
 * HTTP client for the Domain Reseller API (domain-reseller-api.de)
 *
 * Handles authentication, sandbox mode, request/response logging,
 * and provides typed methods for all API operations.
 */
class DomainResellerApiClient
{
    private $baseUrl;
    private $apiKey;
    private $sandbox;
    private $lastRequest  = [];
    private $lastResponse = [];
    private $lastHttpCode = 0;
    private $timeout      = 30;

    public function __construct(string $baseUrl, string $apiKey, bool $sandbox = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->sandbox = $sandbox;

        $this->validateBaseUrl($this->baseUrl);
    }

    /**
     * Validate the API base URL to prevent SSRF attacks.
     * Only HTTPS URLs to non-private hosts are allowed.
     *
     * @throws \InvalidArgumentException
     */
    private function validateBaseUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid API URL');
        }

        if (strtolower($parsed['scheme']) !== 'https') {
            throw new \InvalidArgumentException('API URL must use HTTPS');
        }

        $host = $parsed['host'];

        // Block IP-based URLs to prevent SSRF to internal services
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $privateRanges = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (!filter_var($host, FILTER_VALIDATE_IP, $privateRanges)) {
                throw new \InvalidArgumentException('API URL must not point to private/reserved IP ranges');
            }
        }
    }

    // ------------------------------------------------------------------
    // Generic HTTP
    // ------------------------------------------------------------------

    /**
     * @return array{success: bool, data?: mixed, error?: string, code: int}
     */
    public function request(string $method, string $endpoint, array $data = [], array $query = []): array
    {
        if (empty($this->apiKey)) {
            $this->lastResponse = ['success' => false, 'error' => 'API key is not configured'];
            return ['success' => false, 'error' => 'API key is not configured', 'code' => 0];
        }

        $url = $this->baseUrl . '/api/v1' . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->sandbox) {
            $headers[] = 'x-sandbox: true';
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'HostBill-DomainResellerApi/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->lastRequest  = ['method' => $method, 'url' => $url, 'data' => $this->redactSensitive($data)];
        $this->lastHttpCode = $httpCode;

        if ($curlError) {
            $this->lastResponse = ['success' => false, 'error' => 'cURL error: ' . $curlError];
            return ['success' => false, 'error' => 'Connection error: ' . $curlError, 'code' => 0];
        }

        $decoded = json_decode($response, true);
        $this->lastResponse = $decoded ?: ['raw' => $response];

        if ($decoded === null) {
            return ['success' => false, 'error' => 'Invalid JSON response', 'code' => $httpCode];
        }

        // Ensure 'success' key exists, falling back to HTTP status code
        if (!isset($decoded['success'])) {
            $decoded['success'] = ($httpCode >= 200 && $httpCode < 300);
        }

        $decoded['code'] = $httpCode;
        return $decoded;
    }

    public function getLastRequest(): array  { return $this->lastRequest; }
    public function getLastResponse(): array { return $this->lastResponse; }
    public function getLastHttpCode(): int   { return $this->lastHttpCode; }

    /**
     * Redact sensitive fields from data before logging.
     */
    private function redactSensitive(array $data): array
    {
        $sensitiveKeys = ['authCode', 'auth_code', 'eppCode', 'epp_code', 'password'];
        foreach ($sensitiveKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && strlen($data[$key]) > 0) {
                $data[$key] = '***REDACTED***';
            }
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Health
    // ------------------------------------------------------------------

    public function healthCheck(): array
    {
        return $this->request('GET', '/health/');
    }

    // ------------------------------------------------------------------
    // Domains
    // ------------------------------------------------------------------

    public function checkDomain(string $domain): array
    {
        return $this->request('GET', '/domains/check/' . urlencode($domain));
    }

    public function registerDomain(string $domain, int $years, array $nameservers, array $contacts): array
    {
        return $this->request('POST', '/domains/register', [
            'domain'      => $domain,
            'years'       => $years,
            'nameservers' => $nameservers,
            'contacts'    => $contacts,
        ]);
    }

    public function transferDomain(string $domain, string $authCode, array $contacts): array
    {
        return $this->request('POST', '/domains/transfer', [
            'domain'   => $domain,
            'authCode' => $authCode,
            'contacts' => $contacts,
        ]);
    }

    public function getDomain(string $domain): array
    {
        return $this->request('GET', '/domains/' . urlencode($domain));
    }

    public function updateDomain(string $domain, array $data): array
    {
        return $this->request('PATCH', '/domains/' . urlencode($domain), $data);
    }

    public function deleteDomain(string $domain, bool $immediate = false): array
    {
        $query = $immediate ? ['immediate' => 'true'] : [];
        return $this->request('DELETE', '/domains/' . urlencode($domain), [], $query);
    }

    public function restoreDomain(string $domain): array
    {
        return $this->request('POST', '/domains/' . urlencode($domain) . '/restore');
    }

    public function cancelDelete(string $domain): array
    {
        return $this->request('POST', '/domains/' . urlencode($domain) . '/cancel-delete');
    }

    public function getDomainState(string $domain): array
    {
        return $this->request('GET', '/domains/' . urlencode($domain) . '/state');
    }

    public function getAuthCode(string $domain): array
    {
        return $this->request('GET', '/domains/' . urlencode($domain) . '/authcode');
    }

    public function resetAuthCode(string $domain): array
    {
        return $this->request('POST', '/domains/' . urlencode($domain) . '/reset-authcode');
    }

    public function transferOut(string $domain, bool $approve): array
    {
        return $this->request('POST', '/domains/' . urlencode($domain) . '/transfer-out', [
            'approve' => $approve,
        ]);
    }

    public function listDomains(int $page = 1, int $pageSize = 50, string $search = '', string $status = ''): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ($search) $query['search'] = $search;
        if ($status) $query['status'] = $status;
        return $this->request('GET', '/domains/', [], $query);
    }

    // ------------------------------------------------------------------
    // DNSSEC
    // ------------------------------------------------------------------

    public function getDnssec(string $domain): array
    {
        return $this->request('GET', '/domains/' . urlencode($domain) . '/dnssec');
    }

    public function updateDnssec(string $domain, bool $enabled, array $dnskeys = []): array
    {
        $data = ['enabled' => $enabled];
        if (!empty($dnskeys)) {
            $data['dnskeys'] = $dnskeys;
        }
        return $this->request('PUT', '/domains/' . urlencode($domain) . '/dnssec', $data);
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    public function createContact(array $data): array
    {
        return $this->request('POST', '/contacts/', $data);
    }

    public function getContact(string $handle): array
    {
        return $this->request('GET', '/contacts/' . urlencode($handle));
    }

    public function updateContact(string $handle, array $data): array
    {
        return $this->request('PATCH', '/contacts/' . urlencode($handle), $data);
    }

    public function deleteContact(string $handle): array
    {
        return $this->request('DELETE', '/contacts/' . urlencode($handle));
    }

    public function listContacts(int $page = 1, int $pageSize = 50, string $search = ''): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ($search) $query['search'] = $search;
        return $this->request('GET', '/contacts/', [], $query);
    }

    // ------------------------------------------------------------------
    // DNS
    // ------------------------------------------------------------------

    public function enableManagedDns(string $domain): array
    {
        return $this->request('POST', '/dns/' . urlencode($domain) . '/enable');
    }

    public function disableManagedDns(string $domain): array
    {
        return $this->request('POST', '/dns/' . urlencode($domain) . '/disable');
    }

    public function getDnsRecords(string $domain): array
    {
        return $this->request('GET', '/dns/' . urlencode($domain));
    }

    public function createDnsRecord(string $domain, array $record): array
    {
        return $this->request('POST', '/dns/' . urlencode($domain) . '/records', $record);
    }

    public function updateDnsRecord(string $domain, string $recordId, array $data): array
    {
        return $this->request('PATCH', '/dns/' . urlencode($domain) . '/records/' . urlencode($recordId), $data);
    }

    public function deleteDnsRecord(string $domain, string $recordId): array
    {
        return $this->request('DELETE', '/dns/' . urlencode($domain) . '/records/' . urlencode($recordId));
    }

    public function getZoneInfo(string $domain): array
    {
        return $this->request('GET', '/dns/' . urlencode($domain) . '/zone-info');
    }

    public function exportZone(string $domain): array
    {
        return $this->request('GET', '/dns/' . urlencode($domain) . '/export');
    }

    public function importZone(string $domain, string $zoneFile): array
    {
        return $this->request('POST', '/dns/' . urlencode($domain) . '/import', [
            'zoneFile' => $zoneFile,
        ]);
    }

    // ------------------------------------------------------------------
    // Pricing
    // ------------------------------------------------------------------

    public function getPricing(string $tld = ''): array
    {
        $query = $tld ? ['tld' => $tld] : [];
        return $this->request('GET', '/pricing/', [], $query);
    }
}
