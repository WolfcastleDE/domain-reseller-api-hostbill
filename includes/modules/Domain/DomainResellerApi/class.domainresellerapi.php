<?php

/**
 * Domain Reseller API - HostBill Domain Registrar Module
 *
 * Integrates with the Domain Reseller API (domain-reseller-api.de) by Wolfscastle.
 * Supports domain registration, transfer, renewal, DNS management, contact
 * management, DNSSEC, EPP codes, and domain synchronisation.
 *
 * @version 1.0.0
 * @link    https://domain-reseller-api.de/swagger
 */

require_once __DIR__ . '/DomainResellerApiClient.php';

class DomainResellerApi extends DomainModule
{
    protected $description = 'Domain Reseller API by Wolfscastle';
    protected $version     = '1.0.0';

    /**
     * Module configuration fields shown in admin panel.
     * Accessed via $this->configuration['key']['value']
     */
    protected $configuration = [
        'apiurl' => [
            'value'   => 'https://domain-reseller-api.de',
            'type'    => 'input',
            'default' => false,
        ],
        'apikey' => [
            'value'   => '',
            'type'    => 'password',
            'default' => false,
        ],
        'sandbox' => [
            'value'   => '0',
            'type'    => 'check',
            'default' => '0',
        ],
        'contact_type' => [
            'value'   => 'person',
            'type'    => 'select',
            'default' => ['person', 'org'],
        ],
    ];

    /**
     * Translations for configuration labels.
     */
    protected $lang = [
        'english' => [
            'apiurl'       => 'API URL',
            'apikey'       => 'API Key',
            'sandbox'      => 'Sandbox Mode',
            'contact_type' => 'Default Contact Type',
        ],
    ];

    /**
     * Admin-available commands.
     */
    protected $commands = [
        'Register',
        'Transfer',
        'Renew',
        'ContactInfo',
        'DNSmanagement',
        'RegisterNameServers',
        'EppCode',
    ];

    /**
     * Client-facing commands.
     */
    protected $clientCommands = [
        'ContactInfo',
        'DNSmanagement',
        'RegisterNameServers',
        'EppCode',
    ];

    /**
     * @var DomainResellerApiClient|null
     */
    private $apiClient;

    // =====================================================================
    // API Client
    // =====================================================================

    /**
     * Lazy-init the API client from module configuration.
     */
    private function api(): DomainResellerApiClient
    {
        if ($this->apiClient === null) {
            $this->apiClient = new DomainResellerApiClient(
                $this->configuration['apiurl']['value'] ?: 'https://domain-reseller-api.de',
                $this->configuration['apikey']['value'],
                !empty($this->configuration['sandbox']['value'])
            );
        }
        return $this->apiClient;
    }

    /**
     * Log API call for HostBill debug panel.
     */
    private function log(string $action): void
    {
        $request = $this->api()->getLastRequest();
        $request['action'] = $action;
        $this->logCall($request, $this->api()->getLastResponse());
    }

    /**
     * Build full domain name from HostBill params.
     */
    private function domainName(): string
    {
        if (!empty($this->options['sld']) && !empty($this->options['tld'])) {
            $domain = $this->options['sld'] . '.' . $this->options['tld'];
        } elseif (isset($this->params['sld'], $this->params['tld'])) {
            $domain = $this->params['sld'] . '.' . $this->params['tld'];
        } else {
            $domain = $this->name ?? '';
        }

        return $this->validateDomainName($domain);
    }

    /**
     * Validate domain name format to prevent injection or malformed requests.
     *
     * @throws \InvalidArgumentException
     */
    private function validateDomainName(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            throw new \InvalidArgumentException('Domain name is empty');
        }

        // Must contain at least one dot (SLD.TLD)
        if (strpos($domain, '.') === false) {
            throw new \InvalidArgumentException('Invalid domain name format');
        }

        // Block path traversal, query strings, fragments, and whitespace
        if (preg_match('~[/\\\\?#\s]~', $domain)) {
            throw new \InvalidArgumentException('Domain name contains invalid characters');
        }

        // Basic length check (max 253 chars per RFC 1035)
        if (strlen($domain) > 253) {
            throw new \InvalidArgumentException('Domain name exceeds maximum length');
        }

        return $domain;
    }

    /**
     * Registration period in years from HostBill.
     */
    private function years(): int
    {
        return max(1, (int)($this->options['numyears'] ?? $this->period ?? 1));
    }

    /**
     * Collect nameservers from HostBill params.
     * @return string[]
     */
    private function collectNameservers(): array
    {
        $ns = [];
        for ($i = 1; $i <= 5; $i++) {
            $val = $this->params['ns' . $i] ?? $this->options['ns' . $i] ?? '';
            if (!empty($val)) {
                $ns[] = $val;
            }
        }
        return $ns;
    }

    // =====================================================================
    // Contact Helpers
    // =====================================================================

    /**
     * Create a contact in the Domain Reseller API from HostBill contact data.
     *
     * @param  array  $contact  HostBill-format contact (firstname, lastname, etc.)
     * @return string|false     Contact handle on success, false on failure
     */
    private function createApiContact(array $contact)
    {
        $hasOrg = !empty($contact['companyname']);
        $type = $hasOrg ? 'org' : ($this->configuration['contact_type']['value'] ?: 'person');

        $data = [
            'type'         => $type,
            'firstName'    => $contact['firstname'] ?? '',
            'lastName'     => $contact['lastname'] ?? '',
            'organization' => $contact['companyname'] ?? '',
            'street'       => $contact['address1'] ?? '',
            'houseNumber'  => '',
            'postalCode'   => $contact['postcode'] ?? '',
            'city'         => $contact['city'] ?? '',
            'state'        => $contact['state'] ?? '',
            'country'      => $contact['country'] ?? '',
            'email'        => $contact['email'] ?? '',
            'phone'        => $this->formatPhone($contact['phonenumber'] ?? '', $contact['country'] ?? ''),
        ];

        // Split house number from street if present (common in EU addresses)
        if (preg_match('/^(.+?)\s+(\d+.*)$/', $data['street'], $m)) {
            $data['street']      = $m[1];
            $data['houseNumber'] = $m[2];
        }

        $result = $this->api()->createContact($data);
        $this->log('createContact');

        if (!empty($result['success']) && !empty($result['data']['handle'])) {
            return $result['data']['handle'];
        }

        $this->addError('Failed to create contact: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    /**
     * Get or create contact handles for a domain operation.
     * Returns ['owner' => handle, 'admin' => handle, 'tech' => handle] or false.
     */
    private function getContactHandles()
    {
        // Use domain-specific contacts if available, fall back to client data
        $registrant = $this->domain_contacts['registrant']
                   ?? $this->domain_contacts['Registrant']
                   ?? $this->clientDataAsContact();

        $admin = $this->domain_contacts['admin']
              ?? $this->domain_contacts['Admin']
              ?? $registrant;

        $tech = $this->domain_contacts['tech']
             ?? $this->domain_contacts['Tech']
             ?? $registrant;

        $ownerHandle = $this->createApiContact($registrant);
        if (!$ownerHandle) {
            return false;
        }

        // Reuse owner handle for admin/tech if they match
        $adminHandle = ($admin === $registrant) ? $ownerHandle : $this->createApiContact($admin);
        $techHandle  = ($tech === $registrant) ? $ownerHandle : $this->createApiContact($tech);

        if (!$adminHandle || !$techHandle) {
            return false;
        }

        return [
            'owner' => $ownerHandle,
            'admin' => $adminHandle,
            'tech'  => $techHandle,
        ];
    }

    /**
     * Map HostBill client_data to a contact array.
     */
    private function clientDataAsContact(): array
    {
        return [
            'firstname'   => $this->client_data['firstname'] ?? '',
            'lastname'    => $this->client_data['lastname'] ?? '',
            'companyname' => $this->client_data['companyname'] ?? '',
            'email'       => $this->client_data['email'] ?? '',
            'address1'    => $this->client_data['address1'] ?? '',
            'city'        => $this->client_data['city'] ?? '',
            'state'       => $this->client_data['state'] ?? '',
            'postcode'    => $this->client_data['postcode'] ?? '',
            'country'     => $this->client_data['country'] ?? '',
            'phonenumber' => $this->client_data['phonenumber'] ?? '',
        ];
    }

    /**
     * Map an API contact response to HostBill display format.
     */
    private function mapApiContactToHostBill(array $c): array
    {
        return [
            'First Name'   => $c['firstName'] ?? '',
            'Last Name'    => $c['lastName'] ?? '',
            'Company Name' => $c['organization'] ?? '',
            'Email'        => $c['email'] ?? '',
            'Address 1'    => trim(($c['street'] ?? '') . ' ' . ($c['houseNumber'] ?? '')),
            'City'         => $c['city'] ?? '',
            'State'        => $c['state'] ?? '',
            'Postcode'     => $c['postalCode'] ?? '',
            'Country'      => $c['country'] ?? '',
            'Phone'        => $c['phone'] ?? '',
        ];
    }

    /**
     * Convert phone number to ITU-T E.164 format (+CC.NNNNNN).
     */
    private function formatPhone(string $phone, string $country): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        if (!empty($phone) && $phone[0] !== '+') {
            // Attempt to use HostBill utility if available
            if (class_exists('Utilities') && method_exists('Utilities', 'get_phone_info')) {
                $info = \Utilities::get_phone_info($phone, $country);
                return '+' . $info['ccode'] . '.' . $info['number'];
            }
        }
        return $phone;
    }

    // =====================================================================
    // Connection Test
    // =====================================================================

    /**
     * Test API connection. Called from admin module configuration page.
     */
    public function testConnection()
    {
        $result = $this->api()->healthCheck();
        $this->log('testConnection');

        if (!empty($result['success']) || ($result['code'] ?? 0) === 200) {
            return true;
        }

        $this->addError('API connection failed: ' . ($result['error'] ?? 'Could not reach API'));
        return false;
    }

    // =====================================================================
    // Domain Registration
    // =====================================================================

    public function Register()
    {
        $domain = $this->domainName();
        $ns     = $this->collectNameservers();
        $years  = $this->years();

        $contacts = $this->getContactHandles();
        if (!$contacts) {
            return false;
        }

        $result = $this->api()->registerDomain($domain, $years, $ns, $contacts);
        $this->log('Register');

        if (!empty($result['success'])) {
            $this->addDomain('Active');
            $this->addInfo('Domain ' . $domain . ' registered successfully');

            // Set expiry if provided
            if (!empty($result['data']['expiresAt'])) {
                $this->setExpiry(date('Y-m-d', strtotime($result['data']['expiresAt'])));
            }

            return true;
        }

        $this->addError('Registration failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Domain Transfer
    // =====================================================================

    public function Transfer()
    {
        $domain  = $this->domainName();
        $eppCode = $this->options['epp_code'] ?? $this->params['eppcode'] ?? '';

        $contacts = $this->getContactHandles();
        if (!$contacts) {
            return false;
        }

        $result = $this->api()->transferDomain($domain, $eppCode, $contacts);
        $this->log('Transfer');

        if (!empty($result['success'])) {
            $this->addDomain('Pending Transfer');
            $this->addInfo('Transfer initiated for ' . $domain);
            return true;
        }

        $this->addError('Transfer failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Domain Renewal
    // =====================================================================

    /**
     * The Domain Reseller API handles renewal automatically via autoRenew.
     * This method ensures autoRenew is enabled and records the renewal in HostBill.
     */
    public function Renew()
    {
        $domain = $this->domainName();

        // Ensure auto-renew is enabled at the registrar
        $result = $this->api()->updateDomain($domain, ['autoRenew' => true]);
        $this->log('Renew');

        if (!empty($result['success'])) {
            // Sync the latest expiry date
            $info = $this->api()->getDomain($domain);
            $this->log('Renew-Sync');

            if (!empty($info['data']['expiresAt'])) {
                $this->setExpiry(date('Y-m-d', strtotime($info['data']['expiresAt'])));
            }

            $this->addPeriod();
            $this->addInfo('Domain ' . $domain . ' renewal confirmed (auto-renew enabled)');
            return true;
        }

        $this->addError('Renewal failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Suspend / Unsuspend / Terminate
    // =====================================================================

    public function Suspend()
    {
        // Domain Reseller API does not have a direct suspend concept.
        // We disable auto-renew to prevent further charges.
        $domain = $this->domainName();
        $result = $this->api()->updateDomain($domain, ['autoRenew' => false]);
        $this->log('Suspend');

        if (!empty($result['success'])) {
            $this->addInfo('Domain ' . $domain . ' suspended (auto-renew disabled)');
            return true;
        }

        $this->addError('Suspend failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    public function Unsuspend()
    {
        $domain = $this->domainName();
        $result = $this->api()->updateDomain($domain, ['autoRenew' => true]);
        $this->log('Unsuspend');

        if (!empty($result['success'])) {
            $this->addInfo('Domain ' . $domain . ' unsuspended (auto-renew enabled)');
            return true;
        }

        $this->addError('Unsuspend failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    public function Terminate()
    {
        $domain = $this->domainName();
        $result = $this->api()->deleteDomain($domain);
        $this->log('Terminate');

        if (!empty($result['success'])) {
            $this->addInfo('Domain ' . $domain . ' deleted');
            return true;
        }

        $this->addError('Termination failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Nameservers
    // =====================================================================

    public function getNameServers()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDomain($domain);
        $this->log('getNameServers');

        if (!empty($result['success']) && isset($result['data']['nameservers'])) {
            $ns = [];
            foreach (array_values($result['data']['nameservers']) as $i => $nameserver) {
                $ns['ns' . ($i + 1)] = $nameserver;
            }
            return $ns;
        }

        $this->addError('Failed to retrieve nameservers');
        return false;
    }

    public function updateNameServers()
    {
        $domain = $this->domainName();
        $ns     = $this->collectNameservers();

        if (count($ns) < 2) {
            $this->addError('At least 2 nameservers are required');
            return false;
        }

        $result = $this->api()->updateDomain($domain, ['nameservers' => $ns]);
        $this->log('updateNameServers');

        if (!empty($result['success'])) {
            $this->addInfo('Nameservers updated for ' . $domain);
            return true;
        }

        $this->addError('Failed to update nameservers: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // DNS Management
    // =====================================================================

    public function getDNS()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDnsRecords($domain);
        $this->log('getDNS');

        if (!empty($result['success']) && isset($result['data']['records'])) {
            $records = [];
            foreach ($result['data']['records'] as $record) {
                $records[] = [
                    'hostname' => $record['name'] ?? '',
                    'type'     => $record['type'] ?? '',
                    'address'  => $record['content'] ?? '',
                    'priority' => $record['priority'] ?? '',
                ];
            }
            return $records;
        }

        // If managed DNS is not enabled, try enabling it first
        if (($result['code'] ?? 0) === 400 || ($result['code'] ?? 0) === 404) {
            $this->api()->enableManagedDns($domain);
            $this->log('enableManagedDns');

            $result = $this->api()->getDnsRecords($domain);
            $this->log('getDNS-retry');

            if (!empty($result['success']) && isset($result['data']['records'])) {
                $records = [];
                foreach ($result['data']['records'] as $record) {
                    $records[] = [
                        'hostname' => $record['name'] ?? '',
                        'type'     => $record['type'] ?? '',
                        'address'  => $record['content'] ?? '',
                        'priority' => $record['priority'] ?? '',
                    ];
                }
                return $records;
            }
        }

        return [];
    }

    public function setDNS($records)
    {
        $domain = $this->domainName();

        // Ensure managed DNS is enabled
        $this->api()->enableManagedDns($domain);
        $this->log('enableManagedDns');

        // Create all new records first, before deleting old ones.
        // This avoids leaving DNS in a broken state if creation fails.
        $createdIds = [];
        $createFailed = false;

        foreach ($records as $record) {
            $data = [
                'type'    => $record['type'] ?? 'A',
                'name'    => $record['hostname'] ?? '',
                'content' => $record['address'] ?? '',
                'ttl'     => max(60, (int)($record['ttl'] ?? 3600)),
            ];
            if (!empty($record['priority'])) {
                $data['priority'] = (int)$record['priority'];
            }

            $result = $this->api()->createDnsRecord($domain, $data);
            $this->log('createDnsRecord');

            if (empty($result['success'])) {
                $createFailed = true;
                $this->addError('Failed to create DNS record: ' . ($result['error'] ?? 'Unknown error'));
                break;
            }

            if (!empty($result['data']['id'])) {
                $createdIds[] = (string)$result['data']['id'];
            }
        }

        // If creation failed, roll back newly created records
        if ($createFailed) {
            foreach ($createdIds as $newId) {
                $this->api()->deleteDnsRecord($domain, $newId);
                $this->log('rollbackDnsRecord');
            }
            return false;
        }

        // All new records created — now delete the old ones
        $existing = $this->api()->getDnsRecords($domain);
        $this->log('getDNS-forSet');
        $existingRecords = $existing['data']['records'] ?? [];

        foreach ($existingRecords as $er) {
            $recordId = (string)($er['id'] ?? '');
            if ($recordId !== '' && !in_array($recordId, $createdIds, true)) {
                $this->api()->deleteDnsRecord($domain, $recordId);
                $this->log('deleteDnsRecord');
            }
        }

        $this->addInfo('DNS records updated for ' . $domain);
        return true;
    }

    // =====================================================================
    // Contact Info
    // =====================================================================

    public function getContactDetails()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDomain($domain);
        $this->log('getContactDetails');

        if (empty($result['success']) || empty($result['data'])) {
            $this->addError('Failed to retrieve domain details');
            return false;
        }

        $data = $result['data'];
        $contacts = $data['contacts'] ?? [];
        $output = [];

        $typeMap = [
            'owner' => 'Registrant',
            'admin' => 'Admin',
            'tech'  => 'Tech',
        ];

        foreach ($typeMap as $apiKey => $label) {
            $handleId = $contacts[$apiKey] ?? null;
            if (!$handleId) {
                continue;
            }

            $contactResult = $this->api()->getContact($handleId);
            $this->log('getContact-' . $apiKey);

            if (!empty($contactResult['success']) && !empty($contactResult['data'])) {
                $output[$label] = $this->mapApiContactToHostBill($contactResult['data']);
            }
        }

        return $output;
    }

    public function setContactDetails($params)
    {
        $domain = $this->domainName();

        // Get current domain to find existing contact handles
        $domainResult = $this->api()->getDomain($domain);
        $this->log('getContactDetails-forSet');

        if (empty($domainResult['success']) || empty($domainResult['data'])) {
            $this->addError('Failed to retrieve domain info');
            return false;
        }

        $contacts = $domainResult['data']['contacts'] ?? [];
        $success = true;

        $typeMap = [
            'Registrant' => 'owner',
            'Admin'      => 'admin',
            'Tech'       => 'tech',
        ];

        foreach ($typeMap as $label => $apiKey) {
            if (empty($params[$label])) {
                continue;
            }

            $handle = $contacts[$apiKey] ?? null;
            if (!$handle) {
                continue;
            }

            $contactData = $params[$label];
            $street = $contactData['Address 1'] ?? '';
            $houseNumber = '';
            if (preg_match('/^(.+?)\s+(\d+.*)$/', $street, $m)) {
                $street      = $m[1];
                $houseNumber = $m[2];
            }

            $updateData = [
                'firstName'    => $contactData['First Name'] ?? '',
                'lastName'     => $contactData['Last Name'] ?? '',
                'organization' => $contactData['Company Name'] ?? '',
                'email'        => $contactData['Email'] ?? '',
                'street'       => $street,
                'houseNumber'  => $houseNumber,
                'city'         => $contactData['City'] ?? '',
                'state'        => $contactData['State'] ?? '',
                'postalCode'   => $contactData['Postcode'] ?? '',
                'country'      => $contactData['Country'] ?? '',
                'phone'        => $this->formatPhone(
                    $contactData['Phone'] ?? '',
                    $contactData['Country'] ?? ''
                ),
            ];

            $result = $this->api()->updateContact($handle, $updateData);
            $this->log('updateContact-' . $apiKey);

            if (empty($result['success'])) {
                $success = false;
                $this->addError('Failed to update ' . $label . ' contact: ' . ($result['error'] ?? 'Unknown error'));
            }
        }

        if ($success) {
            $this->addInfo('Contact details updated for ' . $domain);
        }

        return $success;
    }

    // =====================================================================
    // EPP / Auth Code
    // =====================================================================

    public function getEPPCode()
    {
        $domain = $this->domainName();
        $result = $this->api()->getAuthCode($domain);
        $this->log('getEPPCode');

        if (!empty($result['success']) && isset($result['data']['authCode'])) {
            return $result['data']['authCode'];
        }

        $this->addError('Failed to retrieve EPP code: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Domain Check / Lookup
    // =====================================================================

    public function checkAvailability()
    {
        $domain = $this->domainName();
        $result = $this->api()->checkDomain($domain);
        $this->log('checkAvailability');

        if (!empty($result['success']) && isset($result['data'])) {
            return [
                'available' => $result['data']['available'] ?? false,
                'premium'   => $result['data']['premium'] ?? false,
                'price'     => $result['data']['setupPrice'] ?? $result['data']['price'] ?? null,
            ];
        }

        return ['available' => false];
    }

    // =====================================================================
    // Domain Sync
    // =====================================================================

    /**
     * Synchronise domain details from the API back into HostBill.
     * Called by HostBill cron when haveSyncDomains is enabled.
     */
    public function synchInfo()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDomain($domain);
        $this->log('synchInfo');

        if (empty($result['success']) || empty($result['data'])) {
            return [];
        }

        $data = $result['data'];

        $statusMap = [
            'active'          => 'Active',
            'pending'         => 'Pending',
            'expired'         => 'Expired',
            'cancelled'       => 'Cancelled',
            'transfer'        => 'Pending Transfer',
            'transferring'    => 'Pending Transfer',
            'redemption'      => 'Expired',
            'deleting'        => 'Cancelled',
            'deleted'         => 'Cancelled',
        ];

        $apiStatus = strtolower($data['status'] ?? '');
        $hbStatus  = $statusMap[$apiStatus] ?? 'Active';

        $return = [
            'status' => $hbStatus,
        ];

        if (!empty($data['expiresAt'])) {
            $return['expires'] = date('Y-m-d', strtotime($data['expiresAt']));
        }

        return $return;
    }

    // =====================================================================
    // TLD Pricing Import
    // =====================================================================

    /**
     * Import TLD pricing from the API for HostBill price configuration.
     */
    public function importTLDPricing()
    {
        $result = $this->api()->getPricing();
        $this->log('importTLDPricing');

        if (empty($result['success']) || empty($result['data'])) {
            $this->addError('Failed to fetch pricing data');
            return false;
        }

        $pricing = [];
        $tlds = is_array($result['data']) ? $result['data'] : [$result['data']];

        foreach ($tlds as $tld) {
            $tldName = $tld['tld'] ?? '';
            if (empty($tldName)) {
                continue;
            }

            $pricing[$tldName] = [
                'register' => (float)($tld['registerPrice'] ?? 0),
                'renew'    => (float)($tld['renewPrice'] ?? 0),
                'transfer' => (float)($tld['transferPrice'] ?? 0),
                'restore'  => (float)($tld['restorePrice'] ?? 0),
                'minyears' => (int)($tld['minYears'] ?? 1),
                'maxyears' => (int)($tld['maxYears'] ?? 10),
            ];
        }

        return $pricing;
    }

    // =====================================================================
    // Utility
    // =====================================================================

    /**
     * Set the domain expiry date in HostBill.
     */
    private function setExpiry(string $date): void
    {
        if (method_exists($this, 'setExpiryDate')) {
            $this->setExpiryDate($date);
        }
    }
}
