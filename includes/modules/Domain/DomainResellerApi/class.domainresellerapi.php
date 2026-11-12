<?php

/**
 * Domain Reseller API - HostBill Domain Registrar Module
 *
 * Integrates with the Domain Reseller API (domain-reseller-api.de) by Wolfscastle.
 * Supports domain registration, transfer, renewal, DNS management, contact
 * management, registrar lock, glue records, ID protection, EPP codes, and
 * domain synchronisation.
 *
 * Method names, option keys and return formats follow the HostBill domain
 * module dev kit (wiki.hostbillapp.com "Domain_Modules" and the official
 * example modules cdn.hostbillapp.com/WIKI_img/hbdommod/example1.zip and
 * example2.zip).
 *
 * @version 1.0.0
 * @link    https://domain-reseller-api.de/dokumentation
 */

require_once __DIR__ . '/DomainResellerApiClient.php';

class DomainResellerApi extends DomainModule
{
    protected $modname     = 'Domain Reseller API';
    protected $description = 'Domain Reseller API by Wolfscastle';
    protected $version     = '1.0.0';

    /**
     * Module configuration fields shown in admin panel.
     * Accessed via $this->configuration['key']['value']
     *
     * The contact type is not configurable: the API only accepts
     * person|organization|role, and organization/role require a company
     * name. Contacts with a company name are created as "organization",
     * all others as "person".
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
    ];

    /**
     * Translations for configuration labels.
     */
    protected $lang = [
        'english' => [
            'apiurl'  => 'API URL',
            'apikey'  => 'API Key',
            'sandbox' => 'Sandbox Mode',
        ],
    ];

    /**
     * Admin-available commands (buttons in Domain Management). The dev kit
     * documents Register, Transfer, Renew, ContactInfo, DNSmanagement,
     * EmailForwarding, RegisterNameServers and EppCode; RegistrarLock and
     * IDProtection are not documented as commands.
     */
    protected $commands = [
        'Register',
        'Transfer',
        'Renew',
        'ContactInfo',
        'DNSmanagement',
        'RegisterNameServers',
        'EppCode',
        'RegistrarLock',
        'IDProtection',
    ];

    /**
     * Client-facing commands.
     */
    protected $clientCommands = [
        'ContactInfo',
        'DNSmanagement',
        'RegisterNameServers',
        'EppCode',
        'RegistrarLock',
        'IDProtection',
    ];

    /**
     * Nameservers of the managed DNS service of the Domain Reseller API.
     */
    const MANAGED_NAMESERVERS = ['ns1.domain-reseller-api.de', 'ns2.domain-reseller-api.de'];

    /**
     * Phone format required by the API: +CC.NUMBER (e.g. +49.3012345678).
     */
    const PHONE_PATTERN = '/^\+\d{1,3}\.\d{1,14}$/';

    /**
     * E-mail format accepted by the API/registry.
     */
    const EMAIL_PATTERN = '/^[0-9a-zA-Z._-]{1,64}@[0-9a-zA-Z._-]{3,64}$/';

    /**
     * Placeholder for registry-required fields that HostBill has no value for.
     */
    const PLACEHOLDER = '-';

    /**
     * ITU calling codes by ISO 3166-1 alpha-2 country code.
     */
    const CALLING_CODES = [
        'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1', 'AI' => '1', 'AL' => '355', 'AM' => '374',
        'AO' => '244', 'AR' => '54', 'AS' => '1', 'AT' => '43', 'AU' => '61', 'AW' => '297', 'AX' => '358',
        'AZ' => '994', 'BA' => '387', 'BB' => '1', 'BD' => '880', 'BE' => '32', 'BF' => '226', 'BG' => '359',
        'BH' => '973', 'BI' => '257', 'BJ' => '229', 'BM' => '1', 'BN' => '673', 'BO' => '591', 'BR' => '55',
        'BS' => '1', 'BT' => '975', 'BW' => '267', 'BY' => '375', 'BZ' => '501', 'CA' => '1', 'CD' => '243',
        'CF' => '236', 'CG' => '242', 'CH' => '41', 'CI' => '225', 'CK' => '682', 'CL' => '56', 'CM' => '237',
        'CN' => '86', 'CO' => '57', 'CR' => '506', 'CU' => '53', 'CV' => '238', 'CW' => '599', 'CY' => '357',
        'CZ' => '420', 'DE' => '49', 'DJ' => '253', 'DK' => '45', 'DM' => '1', 'DO' => '1', 'DZ' => '213',
        'EC' => '593', 'EE' => '372', 'EG' => '20', 'ER' => '291', 'ES' => '34', 'ET' => '251', 'FI' => '358',
        'FJ' => '679', 'FK' => '500', 'FM' => '691', 'FO' => '298', 'FR' => '33', 'GA' => '241', 'GB' => '44',
        'GD' => '1', 'GE' => '995', 'GF' => '594', 'GG' => '44', 'GH' => '233', 'GI' => '350', 'GL' => '299',
        'GM' => '220', 'GN' => '224', 'GP' => '590', 'GQ' => '240', 'GR' => '30', 'GT' => '502', 'GU' => '1',
        'GW' => '245', 'GY' => '592', 'HK' => '852', 'HN' => '504', 'HR' => '385', 'HT' => '509', 'HU' => '36',
        'ID' => '62', 'IE' => '353', 'IL' => '972', 'IM' => '44', 'IN' => '91', 'IQ' => '964', 'IR' => '98',
        'IS' => '354', 'IT' => '39', 'JE' => '44', 'JM' => '1', 'JO' => '962', 'JP' => '81', 'KE' => '254',
        'KG' => '996', 'KH' => '855', 'KI' => '686', 'KM' => '269', 'KN' => '1', 'KP' => '850', 'KR' => '82',
        'KW' => '965', 'KY' => '1', 'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1', 'LI' => '423',
        'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370', 'LU' => '352', 'LV' => '371', 'LY' => '218',
        'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382', 'MF' => '590', 'MG' => '261', 'MH' => '692',
        'MK' => '389', 'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853', 'MP' => '1', 'MQ' => '596',
        'MR' => '222', 'MS' => '1', 'MT' => '356', 'MU' => '230', 'MV' => '960', 'MW' => '265', 'MX' => '52',
        'MY' => '60', 'MZ' => '258', 'NA' => '264', 'NC' => '687', 'NE' => '227', 'NG' => '234', 'NI' => '505',
        'NL' => '31', 'NO' => '47', 'NP' => '977', 'NR' => '674', 'NU' => '683', 'NZ' => '64', 'OM' => '968',
        'PA' => '507', 'PE' => '51', 'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92', 'PL' => '48',
        'PM' => '508', 'PR' => '1', 'PS' => '970', 'PT' => '351', 'PW' => '680', 'PY' => '595', 'QA' => '974',
        'RE' => '262', 'RO' => '40', 'RS' => '381', 'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677',
        'SC' => '248', 'SD' => '249', 'SE' => '46', 'SG' => '65', 'SH' => '290', 'SI' => '386', 'SK' => '421',
        'SL' => '232', 'SM' => '378', 'SN' => '221', 'SO' => '252', 'SR' => '597', 'SS' => '211', 'ST' => '239',
        'SV' => '503', 'SX' => '1', 'SY' => '963', 'SZ' => '268', 'TC' => '1', 'TD' => '235', 'TG' => '228',
        'TH' => '66', 'TJ' => '992', 'TL' => '670', 'TM' => '993', 'TN' => '216', 'TO' => '676', 'TR' => '90',
        'TT' => '1', 'TV' => '688', 'TW' => '886', 'TZ' => '255', 'UA' => '380', 'UG' => '256', 'US' => '1',
        'UY' => '598', 'UZ' => '998', 'VA' => '39', 'VC' => '1', 'VE' => '58', 'VG' => '1', 'VI' => '1',
        'VN' => '84', 'VU' => '678', 'WF' => '681', 'WS' => '685', 'XK' => '383', 'YE' => '967', 'YT' => '262',
        'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
    ];

    /**
     * Countries that keep the national trunk prefix (0) in international format.
     */
    const KEEP_TRUNK_PREFIX = ['IT', 'SM', 'VA'];

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
     *
     * logCall() is not part of the documented DomainModule API (only
     * logAction() is), so it is only used when the base class provides it.
     */
    private function log(string $action): void
    {
        if (!method_exists($this, 'logCall')) {
            return;
        }
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

        return strtolower($this->validateDomainName($domain));
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
     * Registration/renewal period in years from HostBill (1-10, as accepted by the API).
     *
     * The API rejects terms a TLD does not offer (e.g. 1 year for .ai, whose
     * minimum is 2) with a descriptive error; importTLDPricing() returns the
     * bookable range (minyears/maxyears) so HostBill only offers valid terms.
     */
    private function years(): int
    {
        return min(10, max(1, (int)($this->options['numyears'] ?? $this->period ?? 1)));
    }

    /**
     * Collect nameservers from HostBill params (lower case, no trailing dot,
     * no duplicates, at most 6 as accepted by the API).
     * @return string[]
     */
    private function collectNameservers(): array
    {
        $ns = [];
        for ($i = 1; $i <= 6; $i++) {
            $val = $this->params['ns' . $i] ?? $this->options['ns' . $i] ?? '';
            $val = rtrim(strtolower(trim((string)$val)), '.');
            if ($val !== '' && !in_array($val, $ns, true)) {
                $ns[] = $val;
            }
        }
        return $ns;
    }

    /**
     * Whether all nameservers belong to the managed DNS service of the API.
     */
    private static function isManagedNameserverSet(array $nameservers): bool
    {
        if (empty($nameservers)) {
            return false;
        }
        foreach ($nameservers as $nameserver) {
            if (!in_array($nameserver, self::MANAGED_NAMESERVERS, true)
                && substr($nameserver, -strlen('.domain-reseller-api.de')) !== '.domain-reseller-api.de'
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * First non-null value of the given keys in $this->options, then $this->params.
     */
    private function option(array $keys)
    {
        foreach ([$this->options, $this->params] as $source) {
            foreach ($keys as $key) {
                if (is_array($source) && array_key_exists($key, $source) && $source[$key] !== null) {
                    return $source[$key];
                }
            }
        }
        return null;
    }

    // =====================================================================
    // Contact Helpers
    // =====================================================================

    /**
     * Build the POST /contacts payload from HostBill contact data.
     *
     * - type: "organization" with a company name, otherwise "person"
     *   (the API only accepts person|organization|role)
     * - sex: "NA" (required by the API, HostBill has no such field)
     * - organization: only sent for organisations (natural persons have none)
     * - street/houseNumber: split from address1; houseNumber is omitted for
     *   addresses without one; address2 goes to extension
     * - phone: +CC.NUMBER
     * - pin: nic.it RegCode for .it, taken from a "pin" field if HostBill provides one
     *
     * @param array $contact HostBill-format contact (firstname, lastname, companyname, ...)
     */
    public static function buildContactPayload(array $contact): array
    {
        $value = function (string $key) use ($contact): string {
            return trim((string)($contact[$key] ?? ''));
        };

        $firstName = $value('firstname');
        $lastName  = $value('lastname');
        $company   = $value('companyname');
        $country   = strtoupper($value('country'));
        $country   = $country === 'UK' ? 'GB' : $country;

        list($street, $houseNumber) = self::splitStreet($value('address1'));

        $payload = [
            'type'       => $company !== '' ? 'organization' : 'person',
            'sex'        => 'NA',
            'firstName'  => self::limit($firstName !== '' ? $firstName : ($company !== '' ? $company : self::PLACEHOLDER), 100),
            'lastName'   => self::limit($lastName !== '' ? $lastName : ($company !== '' ? $company : self::PLACEHOLDER), 100),
            'street'     => self::limit($street !== '' ? $street : self::PLACEHOLDER, 255),
            'postalCode' => self::limit($value('postcode') !== '' ? $value('postcode') : self::PLACEHOLDER, 20),
            'city'       => self::limit($value('city') !== '' ? $value('city') : self::PLACEHOLDER, 100),
            'country'    => $country,
            'email'      => $value('email'),
            'phone'      => (string)self::formatPhone($value('phonenumber'), $country),
        ];

        if ($houseNumber !== '') {
            $payload['houseNumber'] = self::limit($houseNumber, 20);
        }
        if ($company !== '') {
            $payload['organization'] = self::limit($company, 255);
        }
        if ($value('address2') !== '') {
            $payload['extension'] = self::limit($value('address2'), 255);
        }
        if ($value('state') !== '') {
            $payload['state'] = self::limit($value('state'), 100);
        }
        if ($value('pin') !== '') {
            $payload['pin'] = self::limit($value('pin'), 36);
        }

        return $payload;
    }

    /**
     * Fields of a contact payload that the API would reject.
     * @return string[]
     */
    private static function invalidContactFields(array $payload): array
    {
        $invalid = [];
        if (!preg_match('/^[A-Z]{2}$/', $payload['country'] ?? '')) {
            $invalid[] = 'country';
        }
        if (!preg_match(self::EMAIL_PATTERN, $payload['email'] ?? '')) {
            $invalid[] = 'email';
        }
        if (!preg_match(self::PHONE_PATTERN, $payload['phone'] ?? '')) {
            $invalid[] = 'phone (expected +CC.NUMBER)';
        }
        return $invalid;
    }

    /**
     * Create a contact in the Domain Reseller API from HostBill contact data.
     *
     * @param  array  $contact  HostBill-format contact (firstname, lastname, etc.)
     * @return string|false     Contact handle on success, false on failure
     */
    private function createApiContact(array $contact)
    {
        $data = self::buildContactPayload($contact);

        $invalid = self::invalidContactFields($data);
        if (!empty($invalid)) {
            $this->addError('Failed to create contact: invalid ' . implode(', ', $invalid));
            return false;
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
        $contact = [];
        foreach (['firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city',
                  'state', 'postcode', 'country', 'phonenumber', 'pin'] as $key) {
            $contact[$key] = $this->client_data[$key] ?? '';
        }
        return $contact;
    }

    /**
     * Map an API contact to the HostBill contact fields used by
     * getContactInfo() (same keys as the client data: firstname, lastname,
     * companyname, email, address1, address2, city, state, postcode,
     * country, phonenumber).
     */
    private function mapApiContactToHostBill(array $c): array
    {
        $clean = function (string $key) use ($c): string {
            $value = trim((string)($c[$key] ?? ''));
            return $value === self::PLACEHOLDER ? '' : $value;
        };

        return [
            'firstname'   => $clean('firstName'),
            'lastname'    => $clean('lastName'),
            'companyname' => ($c['type'] ?? '') === 'person' ? '' : $clean('organization'),
            'email'       => $clean('email'),
            'address1'    => trim($clean('street') . ' ' . $clean('houseNumber')),
            'address2'    => $clean('extension'),
            'city'        => $clean('city'),
            'state'       => $clean('state'),
            'postcode'    => $clean('postalCode'),
            'country'     => $clean('country'),
            'phonenumber' => $clean('phone'),
        ];
    }

    /**
     * Build a PATCH /contacts/:handle payload. The type and sex can not be
     * changed; an empty organisation/house number is sent as "" to clear it
     * (the API only allows "" for these optional fields).
     */
    private static function buildContactUpdatePayload(array $contact): array
    {
        $payload = self::buildContactPayload($contact);
        unset($payload['type'], $payload['sex']);
        foreach (['organization', 'houseNumber'] as $field) {
            if (!isset($payload[$field])) {
                $payload[$field] = '';
            }
        }
        return $payload;
    }

    /**
     * Convert a phone number to the +CC.NUMBER format required by the API.
     *
     * @return string|null The formatted number, or null if it could not be formatted
     */
    public static function formatPhone(string $phone, string $country = ''): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        if (preg_match(self::PHONE_PATTERN, $phone)) {
            return $phone;
        }

        // Drop extensions such as "x123" or "ext. 123"
        $phone = preg_replace('/\s*(?:x|ext\.?|extension)\s*\d+$/i', '', $phone);

        $international = strpos($phone, '+') === 0;
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$international && strpos($digits, '00') === 0) {
            $international = true;
            $digits = substr($digits, 2);
        }

        if ($digits === '') {
            return null;
        }

        $country = strtoupper(trim($country));
        $countryCode = self::CALLING_CODES[$country] ?? null;

        if ($international) {
            $code = null;
            if ($countryCode !== null && strpos($digits, $countryCode) === 0) {
                $code = $countryCode;
            } else {
                foreach ([3, 2, 1] as $length) {
                    $candidate = substr($digits, 0, $length);
                    if (in_array($candidate, self::CALLING_CODES, true)) {
                        $code = $candidate;
                        break;
                    }
                }
            }

            if ($code === null) {
                return null;
            }

            $number = substr($digits, strlen($code));
            $codeCountry = $countryCode === $code ? $country : array_search($code, self::CALLING_CODES, true);
            // "+49 (0) 30 123" carries a redundant trunk prefix
            if (preg_match('/\(\s*0\s*\)/', $phone) || !in_array($codeCountry, self::KEEP_TRUNK_PREFIX, true)) {
                $number = ltrim($number, '0');
            }
        } else {
            if ($countryCode === null) {
                return null;
            }

            $code = $countryCode;
            $number = in_array($country, self::KEEP_TRUNK_PREFIX, true) ? $digits : ltrim($digits, '0');
            // NANP numbers are often written with the leading 1
            if ($code === '1' && strlen($number) === 11 && $number[0] === '1') {
                $number = substr($number, 1);
            }
        }

        if ($number === '' || strlen($number) > 14) {
            return null;
        }

        return '+' . $code . '.' . $number;
    }

    /**
     * Split an address line into street and house number
     * ("Musterstraße 12a" and "123 Main St").
     *
     * @return string[] [street, houseNumber]
     */
    public static function splitStreet(string $address): array
    {
        $address = trim(preg_replace('/\s+/u', ' ', $address));
        if ($address === '') {
            return ['', ''];
        }

        $number = '\d+\s?[a-zA-Z]?(?:\s?[-\/]\s?\d+\s?[a-zA-Z]?)?';

        // European: street first, number last
        if (preg_match('/^(.*?\D)[\s,]+(' . $number . ')$/u', $address, $m)) {
            return [rtrim($m[1], ' ,'), str_replace(' ', '', $m[2])];
        }

        // North American: number first
        if (preg_match('/^(' . $number . ')[\s,]+(.*\D.*)$/u', $address, $m)) {
            return [trim($m[2]), str_replace(' ', '', $m[1])];
        }

        return [$address, ''];
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    // =====================================================================
    // Connection Test
    // =====================================================================

    /**
     * Test API connection and API key. Called from admin module configuration page.
     * Uses an authenticated endpoint: /health does not check the key.
     */
    public function testConnection()
    {
        $result = $this->api()->listDomains(1, 1);
        $this->log('testConnection');

        if (!empty($result['success'])) {
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

        if (count($ns) === 1) {
            $this->addError('At least 2 nameservers are required');
            return false;
        }

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

            $this->disableApiAutoRenew($domain);

            // ID protection ordered with the domain (dev kit: $this->options['idprotection'])
            if (!empty($this->options['idprotection'])) {
                $protection = $this->applyIdProtection($domain, true);
                if (empty($protection['success'])) {
                    $this->addError('Domain registered, but ID protection could not be enabled: '
                        . ($protection['error'] ?? 'Unknown error'));
                }
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
        $eppCode = $this->options['epp_code'] ?? $this->params['epp_code'] ?? $this->params['eppcode'] ?? '';

        $contacts = $this->getContactHandles();
        if (!$contacts) {
            return false;
        }

        $result = $this->api()->transferDomain($domain, $eppCode, $contacts);
        $this->log('Transfer');

        if (!empty($result['success'])) {
            $this->addDomain('Pending Transfer');
            $this->addInfo('Transfer initiated for ' . $domain);
            // Usually refused while the transfer is pending; synchInfo() retries once it is active
            $this->disableApiAutoRenew($domain);
            return true;
        }

        $this->addError('Transfer failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Domain Renewal
    // =====================================================================

    /**
     * Renew the domain via POST /domains/:domain/renew for the HostBill period.
     * The API charges the wallet and returns the new expiry date.
     *
     * Renewals are driven by HostBill (renewal invoices), so the API's own
     * auto-renew is switched off after registration/transfer to avoid
     * renewing a domain twice.
     */
    public function Renew()
    {
        $domain = $this->domainName();

        $result = $this->api()->renewDomain($domain, $this->years());
        $this->log('Renew');

        if (!empty($result['success'])) {
            if (!empty($result['data']['expiresAt'])) {
                $this->setExpiry(date('Y-m-d', strtotime($result['data']['expiresAt'])));
            }

            $this->addPeriod();
            $this->addInfo('Domain ' . $domain . ' renewed for ' . ($result['data']['years'] ?? $this->years()) . ' year(s)');
            return true;
        }

        $this->addError('Renewal failed: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    /**
     * Switch off the API's auto-renew (best effort, errors are ignored).
     */
    private function disableApiAutoRenew(string $domain): void
    {
        $this->api()->updateDomain($domain, ['autoRenew' => false]);
        $this->log('disableApiAutoRenew');
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
        // Nothing to undo at the API: renewals are driven by HostBill, so the
        // API's auto-renew stays off (re-enabling it could renew twice).
        $domain = $this->domainName();
        $this->addInfo('Domain ' . $domain . ' unsuspended');
        return true;
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

        // Dev kit: "just a simple array with the nameservers of the domain"
        if (!empty($result['success']) && isset($result['data']['nameservers'])) {
            return array_values((array)$result['data']['nameservers']);
        }

        $this->addError('Failed to retrieve nameservers');
        return false;
    }

    /**
     * Update nameservers. Switching to the managed nameservers enables managed
     * DNS; switching away from them disables it (the API keeps both in sync
     * only when useManagedDns is sent along).
     */
    public function updateNameServers()
    {
        $domain = $this->domainName();
        $ns     = $this->collectNameservers();

        if (count($ns) < 2) {
            $this->addError('At least 2 nameservers are required');
            return false;
        }

        $info = $this->api()->getDomain($domain);
        $this->log('updateNameServers-info');
        if (empty($info['success']) || empty($info['data'])) {
            $this->addError('Failed to update nameservers: ' . ($info['error'] ?? 'Domain not found'));
            return false;
        }
        $managed = !empty($info['data']['useManagedDns']);

        if (self::isManagedNameserverSet($ns)) {
            if ($managed) {
                $this->addInfo('Nameservers updated for ' . $domain);
                return true;
            }
            $data = ['useManagedDns' => true];
        } else {
            $data = ['nameservers' => $ns];
            if ($managed) {
                $data['useManagedDns'] = false;
            }
        }

        $result = $this->api()->updateDomain($domain, $data);
        $this->log('updateNameServers');

        if (!empty($result['success'])) {
            $this->addInfo('Nameservers updated for ' . $domain);
            return true;
        }

        $this->addError('Failed to update nameservers: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Glue records (child nameservers)
    // =====================================================================

    /**
     * Glue records. HostBill passes the label of the host in NameServer
     * (the dev kit builds the full name as NameServer.sld.tld), the IP in
     * NameServerIP, NameServerOldIP/NameServerNewIP when modifying.
     */
    public function registerNameServer()
    {
        $domain   = $this->domainName();
        $hostname = $this->glueHostname($domain);
        $ip       = trim((string)$this->option(['NameServerIP', 'ipaddress']));

        list($ipv4, $ipv6) = self::splitIp($ip);
        $result = $this->api()->createHost($domain, $hostname, $ipv4, $ipv6);
        $this->log('registerNameServer');

        if (!empty($result['success'])) {
            $this->addInfo('Nameserver ' . $hostname . ' registered');
            return true;
        }

        $this->addError('Failed to register nameserver: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    public function modifyNameServer()
    {
        $domain   = $this->domainName();
        $hostname = $this->glueHostname($domain);
        $ip       = trim((string)$this->option(['NameServerNewIP', 'newipaddress']));

        list($ipv4, $ipv6) = self::splitIp($ip);
        $result = $this->api()->updateHost($domain, $hostname, $ipv4, $ipv6);
        $this->log('modifyNameServer');

        if (!empty($result['success'])) {
            $this->addInfo('Nameserver ' . $hostname . ' updated');
            return true;
        }

        $this->addError('Failed to modify nameserver: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    public function deleteNameServer()
    {
        $domain   = $this->domainName();
        $hostname = $this->glueHostname($domain);

        $result = $this->api()->deleteHost($domain, $hostname);
        $this->log('deleteNameServer');

        if (!empty($result['success'])) {
            $this->addInfo('Nameserver ' . $hostname . ' deleted');
            return true;
        }

        $this->addError('Failed to delete nameserver: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    /**
     * Full host name of a glue record: the label from HostBill plus the
     * domain (the API only accepts hosts below the domain, e.g. ns1.example.com).
     */
    private function glueHostname(string $domain): string
    {
        $host = rtrim(strtolower(trim((string)$this->option(['NameServer', 'nameserver']))), '.');
        if ($host === '' || $host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
            return $host;
        }
        return $host . '.' . $domain;
    }

    /**
     * @return string[] [ipv4, ipv6] — one of them filled, depending on the address family
     */
    private static function splitIp(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['', $ip];
        }
        return [$ip, ''];
    }

    // =====================================================================
    // Registrar Lock
    // =====================================================================

    public function getRegistrarLock()
    {
        $domain = $this->domainName();
        $result = $this->api()->getLock($domain);
        $this->log('getRegistrarLock');

        return !empty($result['success']) && !empty($result['data']['locked']);
    }

    public function updateRegistrarLock()
    {
        $domain = $this->domainName();
        $locked = !empty($this->option(['registrarLock', 'registrarlock', 'reglock']));

        $result = $this->api()->setLock($domain, $locked);
        $this->log('updateRegistrarLock');

        if (!empty($result['success'])) {
            $this->addInfo('Registrar lock ' . ($locked ? 'enabled' : 'disabled') . ' for ' . $domain);
            return true;
        }

        $this->addError('Failed to update registrar lock: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // ID Protection (WHOIS privacy)
    // =====================================================================

    /**
     * WHOIS privacy of OpenProvider TLDs (whoisPrivacy), otherwise the WHOIS
     * protection flag of the owner contact.
     */
    public function getIDProtection()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDomain($domain);
        $this->log('getIDProtection');

        if (empty($result['success']) || empty($result['data'])) {
            return false;
        }
        if (!empty($result['data']['whoisPrivacy'])) {
            return true;
        }

        $owner = $result['data']['contacts']['owner'] ?? null;
        if (!$owner) {
            return false;
        }
        $contact = $this->api()->getContact($owner);
        $this->log('getIDProtection-contact');

        return !empty($contact['success']) && !empty($contact['data']['protection']);
    }

    /**
     * Enable/disable ID protection. Enabling sends acceptWppTerms: the
     * registrant accepts the WPP terms by ordering ID protection in HostBill.
     * Domains that are not routed to OpenProvider (HTTP 400) fall back to the
     * WHOIS protection flag of the owner contact.
     */
    public function updateIDProtection()
    {
        $domain = $this->domainName();
        // The dev kit passes the requested state in IDProtection; idprotection
        // holds the domain's stored flag, so it is only a fallback
        $enabled = !empty($this->option(['IDProtection', 'idprotection', 'idProtection', 'id_protection']));

        $result = $this->applyIdProtection($domain, $enabled);

        if (!empty($result['success'])) {
            $this->addInfo('ID protection ' . ($enabled ? 'enabled' : 'disabled') . ' for ' . $domain);
            return true;
        }

        $this->addError('Failed to update ID protection: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    /**
     * Set WHOIS privacy (OpenProvider TLDs) or, for domains that are not routed
     * to OpenProvider (HTTP 400), the WHOIS protection flag of the owner contact.
     */
    private function applyIdProtection(string $domain, bool $enabled): array
    {
        $result = $this->api()->setWhoisPrivacy($domain, $enabled, $enabled);
        $this->log('updateIDProtection');

        if (empty($result['success']) && ($result['code'] ?? 0) === 400) {
            $info  = $this->api()->getDomain($domain);
            $owner = $info['data']['contacts']['owner'] ?? null;
            if ($owner) {
                $result = $this->api()->updateContact($owner, ['protection' => $enabled]);
                $this->log('updateIDProtection-contact');
            }
        }

        return $result;
    }

    // =====================================================================
    // DNS Management
    // =====================================================================

    /**
     * Record types offered in the HostBill DNS editor.
     */
    const DNS_RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    /**
     * Records of the managed DNS zone in the dev kit format:
     * ['hosts' => [['hostname', 'address', 'recordtype'], ...], 'records' => [types]].
     * priority and ttl are added per host (not part of the documented format).
     *
     * Without managed DNS the API answers success:false and an empty host list
     * is returned; managed DNS is only enabled by updateDNSmanagement() (it
     * changes the nameservers of the domain).
     */
    public function getDNSmanagement()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDnsRecords($domain);
        $this->log('getDNSmanagement');

        $hosts = [];
        if (!empty($result['success']) && isset($result['data']['records'])) {
            foreach ($result['data']['records'] as $record) {
                // Apex NS records of the managed nameservers are maintained by the API
                if (!empty($record['managed'])) {
                    continue;
                }
                $hosts[] = [
                    'hostname'   => $record['name'] ?? '',
                    'address'    => $record['content'] ?? '',
                    'recordtype' => $record['type'] ?? '',
                    'priority'   => $record['priority'] ?? '',
                    'ttl'        => $record['ttl'] ?? '',
                ];
            }
        }

        return ['hosts' => $hosts, 'records' => self::DNS_RECORD_TYPES];
    }

    /**
     * Replace the records of the managed DNS zone with the records submitted
     * in HostBill. The dev kit passes them as HostName{i}, RecordType{i} and
     * Address{i} in $this->options (index from 0 or 1). A priority is not part
     * of the documented form: it is taken from Priority{i} when present, or
     * from an address like "10 mail.example.com" for MX/SRV.
     */
    public function updateDNSmanagement()
    {
        $records = $this->dnsRecordsFromOptions();
        if ($records === null) {
            // Nothing submitted: do not wipe the zone
            $this->addError('No DNS records were submitted');
            return false;
        }
        return $this->replaceDnsRecords($records);
    }

    /**
     * @return array|null Records from the HostBill form, null if the form has no record fields
     */
    private function dnsRecordsFromOptions(): ?array
    {
        $options = is_array($this->options) ? $this->options : [];
        $found   = false;
        $records = [];

        for ($i = 0; $i <= 1 || isset($options['HostName' . $i]) || isset($options['RecordType' . $i]); $i++) {
            if (!isset($options['HostName' . $i]) && !isset($options['RecordType' . $i])) {
                continue;
            }
            $found   = true;
            $type    = strtoupper(trim((string)($options['RecordType' . $i] ?? '')));
            $address = trim((string)($options['Address' . $i] ?? ''));
            if ($type === '' || $address === '') {
                continue; // removed row
            }

            $record = [
                'hostname' => (string)($options['HostName' . $i] ?? ''),
                'type'     => $type,
                'address'  => $address,
            ];
            if (isset($options['Priority' . $i]) && $options['Priority' . $i] !== '') {
                $record['priority'] = $options['Priority' . $i];
            } elseif (in_array($type, ['MX', 'SRV'], true) && preg_match('/^(\d{1,5})\s+(\S.*)$/', $address, $m)) {
                $record['priority'] = $m[1];
                $record['address']  = $m[2];
            }
            $records[] = $record;
        }

        return $found ? $records : null;
    }

    /**
     * @param array $records [['hostname', 'type', 'address', 'priority'?, 'ttl'?], ...]
     */
    private function replaceDnsRecords(array $records): bool
    {
        $domain = $this->domainName();

        // Managed DNS must be active (PATCH useManagedDns also points the
        // nameservers at the registry to the managed DNS service)
        if (!$this->ensureManagedDns($domain)) {
            return false;
        }

        // Create all new records first, before deleting old ones.
        // This avoids leaving DNS in a broken state if creation fails.
        $createdIds = [];
        $createFailed = false;

        foreach ($records as $record) {
            $type = strtoupper(trim((string)($record['type'] ?? 'A')));
            $name = trim((string)($record['hostname'] ?? ''));
            $name = $name === '' ? '@' : rtrim($name, '.');

            // SOA and apex NS are managed by the API and can not be created
            if ($type === 'SOA' || ($type === 'NS' && ($name === '@' || strtolower($name) === $domain))) {
                continue;
            }

            $data = [
                'type'    => $type,
                'name'    => $name,
                'content' => $record['address'] ?? '',
                'ttl'     => min(86400, max(60, (int)($record['ttl'] ?? 3600))),
            ];
            if (isset($record['priority']) && $record['priority'] !== '') {
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
            if ($recordId !== '' && empty($er['managed']) && !in_array($recordId, $createdIds, true)) {
                $this->api()->deleteDnsRecord($domain, $recordId);
                $this->log('deleteDnsRecord');
            }
        }

        $this->addInfo('DNS records updated for ' . $domain);
        return true;
    }

    /**
     * Enable managed DNS via PATCH /domains/:domain if it is not active yet.
     * (POST /dns/:domain/enable would only create the zone and leave the
     * nameservers at the registry unchanged.)
     */
    private function ensureManagedDns(string $domain): bool
    {
        $info = $this->api()->getDomain($domain);
        $this->log('getDomain-forDNS');
        if (empty($info['success']) || empty($info['data'])) {
            $this->addError('Failed to retrieve domain details: ' . ($info['error'] ?? 'Unknown error'));
            return false;
        }
        if (!empty($info['data']['useManagedDns'])) {
            return true;
        }

        $result = $this->api()->updateDomain($domain, ['useManagedDns' => true]);
        $this->log('enableManagedDns');
        if (!empty($result['success'])) {
            $this->addInfo('Managed DNS enabled for ' . $domain . ' (nameservers changed to the managed DNS service)');
            return true;
        }

        $this->addError('Failed to enable managed DNS: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Contact Info
    // =====================================================================

    /**
     * HostBill role => API role.
     */
    const CONTACT_ROLES = [
        'registrant' => 'owner',
        'admin'      => 'admin',
        'tech'       => 'tech',
        'billing'    => 'billing',
    ];

    /**
     * Contacts of the domain in the dev kit format:
     * ['registrant' => [...], 'admin' => [...], 'tech' => [...], 'billing' => [...]].
     */
    public function getContactInfo()
    {
        $domain = $this->domainName();
        $result = $this->api()->getDomain($domain);
        $this->log('getContactInfo');

        if (empty($result['success']) || empty($result['data'])) {
            $this->addError('Failed to retrieve domain details');
            return false;
        }

        $contacts = $result['data']['contacts'] ?? [];
        $output   = [];
        $cache    = [];

        foreach (self::CONTACT_ROLES as $role => $apiKey) {
            $handle = $contacts[$apiKey] ?? null;
            if (!$handle) {
                continue;
            }

            if (!array_key_exists($handle, $cache)) {
                $contactResult = $this->api()->getContact($handle);
                $this->log('getContact-' . $apiKey);
                $cache[$handle] = !empty($contactResult['success']) && !empty($contactResult['data'])
                    ? $this->mapApiContactToHostBill($contactResult['data'])
                    : null;
            }

            if ($cache[$handle] !== null) {
                $output[$role] = $cache[$handle];
            }
        }

        return $output;
    }

    /**
     * Update the contacts of the domain from $this->options['registrant'|'admin'|'tech'|'billing']
     * (dev kit: HostBill passes the submitted contacts there, with the client data keys).
     *
     * Each role's handle is updated in place (PATCH /contacts/:handle). Admin,
     * tech and billing usually share the owner's handle (see getContactHandles());
     * when such a role gets different data, a new contact is created and assigned
     * via PATCH /domains/:domain/contacts instead, so the owner stays intact.
     */
    public function updateContactInfo()
    {
        $domain = $this->domainName();

        // Get current domain to find existing contact handles
        $domainResult = $this->api()->getDomain($domain);
        $this->log('updateContactInfo-domain');

        if (empty($domainResult['success']) || empty($domainResult['data'])) {
            $this->addError('Failed to retrieve domain info');
            return false;
        }

        $contacts = $domainResult['data']['contacts'] ?? [];
        $success  = true;

        // Handles already updated in this call, with the data they were updated to
        $updated  = [];
        $reassign = [];

        foreach (self::CONTACT_ROLES as $role => $apiKey) {
            if (empty($this->options[$role]) || !is_array($this->options[$role])) {
                continue;
            }

            $handle = $contacts[$apiKey] ?? null;
            if (!$handle) {
                continue;
            }

            $contact = $this->options[$role];
            $payload = self::buildContactUpdatePayload($contact);
            $label   = ucfirst($role);

            $invalid = self::invalidContactFields($payload);
            if (!empty($invalid)) {
                $success = false;
                $this->addError('Failed to update ' . $label . ' contact: invalid ' . implode(', ', $invalid));
                continue;
            }

            $sharesOwner = $apiKey !== 'owner' && $handle === ($contacts['owner'] ?? null);
            if (isset($updated[$handle]) || $sharesOwner) {
                if (isset($updated[$handle]) && $updated[$handle] == $payload) {
                    continue; // same handle, same data
                }
                // Shared handle with different data (or the owner's handle, which
                // must not change through this role): give this role its own contact
                $newHandle = $this->createApiContact($contact);
                if (!$newHandle) {
                    $success = false;
                    continue;
                }
                $reassign[$apiKey] = $newHandle;
                continue;
            }

            $result = $this->api()->updateContact($handle, $payload);
            $this->log('updateContact-' . $apiKey);

            if (empty($result['success'])) {
                $success = false;
                $this->addError('Failed to update ' . $label . ' contact: ' . ($result['error'] ?? 'Unknown error'));
                continue;
            }
            $updated[$handle] = $payload;
        }

        if (!empty($reassign)) {
            $result = $this->api()->updateDomainContacts($domain, $reassign);
            $this->log('updateDomainContacts');
            if (empty($result['success'])) {
                $success = false;
                $this->addError('Failed to assign contacts: ' . ($result['error'] ?? 'Unknown error'));
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

    /**
     * EPP/auth code as string (dev kit name: getEppCode; PHP method names are
     * case-insensitive, so getEPPCode() resolves to the same method).
     */
    public function getEppCode()
    {
        $domain = $this->domainName();
        $result = $this->api()->getAuthCode($domain);
        $this->log('getEppCode');

        $authCode = $result['data']['authCode'] ?? '';
        // The API returns this text in authCode when the registrar has no code
        if (!empty($result['success']) && $authCode !== '' && $authCode !== 'Unable to retrieve auth code') {
            return $authCode;
        }

        $this->addError('Failed to retrieve EPP code: ' . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    // =====================================================================
    // Domain Check / Lookup
    // =====================================================================

    /**
     * Availability check with the signature used by current HostBill modules
     * (lookupDomain($sld, $tld, $settings), e.g. the Domain Name API module).
     * Not part of the public dev kit; HostBill may only call it for modules
     * implementing its DomainLookupInterface (see README).
     *
     * @return array ['available' => bool, 'premium' => ['register' => price, 'currency' => 'EUR']?]
     */
    public function lookupDomain($sld, $tld, $settings = [])
    {
        $response = ['available' => false];

        try {
            $domain = strtolower($this->validateDomainName(trim((string)$sld) . '.' . ltrim(trim((string)$tld), '.')));
        } catch (\InvalidArgumentException $e) {
            return $response;
        }

        $result = $this->api()->checkDomain($domain);
        $this->log('lookupDomain');

        if (empty($result['success']) || !isset($result['data'])) {
            return $response;
        }

        $response['available'] = !empty($result['data']['available']);
        if (!empty($result['data']['premium']) && isset($result['data']['price'])) {
            $response['premium'] = [
                'register' => number_format((float)$result['data']['price'], 2, '.', ''),
                'currency' => 'EUR',
            ];
        }
        return $response;
    }

    public function checkAvailability()
    {
        $domain = $this->domainName();
        $result = $this->api()->checkDomain($domain, $this->years());
        $this->log('checkAvailability');

        if (!empty($result['success']) && isset($result['data'])) {
            return [
                'available' => $result['data']['available'] ?? false,
                'premium'   => $result['data']['premium'] ?? false,
                // Net total for data.years years (the TLD minimum if the requested term is not bookable)
                'price'     => $result['data']['price'] ?? null,
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

        // Domain states of the API (see GET /domains/:domain/state) mapped to the
        // statuses the dev kit documents for synchInfo(): Active, Pending,
        // Pending Transfer, Pending Registration, Expired
        $statusMap = [
            'pending'          => 'Pending Registration', // ordered at the registrar, not yet registered
            'pending_transfer' => 'Pending Transfer',
            'active'           => 'Active',
            'expired'          => 'Expired',
            'redemption'       => 'Expired',
            'pending_delete'   => 'Active', // still active until the scheduled deletion
            'transferred_away' => 'Expired', // no longer with this registrar
            'suspended'        => 'Active',
            'deleted'          => 'Expired',
        ];

        $apiStatus = strtolower($data['status'] ?? '');
        $hbStatus  = $statusMap[$apiStatus] ?? 'Active';

        $return = [
            'status' => $hbStatus,
        ];

        if (!empty($data['expiresAt'])) {
            $return['expires'] = date('Y-m-d', strtotime($data['expiresAt']));
        }

        // Nameservers (dev kit: array of nameservers)
        if (isset($data['nameservers']) && is_array($data['nameservers'])) {
            $return['ns'] = array_values($data['nameservers']);
        }

        // ID protection (dev kit: 1/0). The registrar lock is not synchronised:
        // it needs a live registrar call per domain (getRegistrarLock()).
        $protected = !empty($data['whoisPrivacy']);
        if (!$protected && !empty($data['contacts']['owner'])) {
            $owner = $this->api()->getContact($data['contacts']['owner']);
            $this->log('synchInfo-contact');
            $protected = !empty($owner['success']) && !empty($owner['data']['protection']);
        }
        $return['idprotection'] = $protected ? 1 : 0;

        // Renewals are driven by HostBill: turn the API's auto-renew off once
        // the domain can be modified (e.g. after a completed transfer)
        if ($apiStatus === 'active' && !empty($data['autoRenew'])) {
            $this->disableApiAutoRenew($domain);
        }

        return $return;
    }

    // =====================================================================
    // TLD Pricing Import
    // =====================================================================

    /**
     * Import TLD pricing from the API for HostBill price configuration.
     *
     * register/renew are net EUR per year at the minimum term, transfer is
     * the net total of a transfer-in (covering transferyears years, 2 for .ai).
     * minyears is the shortest bookable term (e.g. 2 for .ai).
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
                'register'      => (float)($tld['registerPrice'] ?? 0),
                'renew'         => (float)($tld['renewPrice'] ?? 0),
                'transfer'      => (float)($tld['transferPrice'] ?? 0),
                'transferyears' => (int)($tld['transferYears'] ?? 1),
                'setup'         => (float)($tld['setupPrice'] ?? 0),
                'restore'       => (float)($tld['restorePrice'] ?? 0),
                'minyears'      => (int)($tld['minYears'] ?? 1),
                'maxyears'      => (int)($tld['maxYears'] ?? 10),
            ];
        }

        return $pricing;
    }

    /**
     * TLD prices in the format of current HostBill price import modules
     * (getDomainPrices(), e.g. the Domain Name API module):
     * ['EUR' => [tld => [years => ['period', 'register', 'transfer', 'renew', 'redemption']]]].
     * Not part of the public dev kit; HostBill may only call it for modules
     * implementing its DomainPriceImport interface (see README).
     *
     * register/renew are the exact net totals per term (`periods` of the API).
     * A transfer is only offered for the term it covers (transferYears, 2 for .ai).
     *
     * @return array|string Prices, or the error message
     */
    public function getDomainPrices()
    {
        $result = $this->api()->getPricing();
        $this->log('getDomainPrices');

        if (empty($result['success']) || empty($result['data']) || !is_array($result['data'])) {
            return 'Failed to fetch pricing data: ' . ($result['error'] ?? 'Unknown error');
        }

        $prices = [];
        foreach ($result['data'] as $tld) {
            $tldName = $tld['tld'] ?? '';
            if ($tldName === '' || empty($tld['periods']) || !is_array($tld['periods'])) {
                continue;
            }

            $transferYears = (int)($tld['transferYears'] ?? 1);
            foreach ($tld['periods'] as $term) {
                $years = (int)($term['years'] ?? 0);
                if ($years < 1 || !isset($term['registerPrice'])) {
                    continue;
                }

                $row = [
                    'period'   => $years,
                    'register' => number_format((float)$term['registerPrice'], 2, '.', ''),
                ];
                if (isset($term['renewPrice'])) {
                    $row['renew'] = number_format((float)$term['renewPrice'], 2, '.', '');
                }
                if ($years === $transferYears && isset($tld['transferPrice'])) {
                    $row['transfer'] = number_format((float)$tld['transferPrice'], 2, '.', '');
                }
                if (isset($tld['restorePrice'])) {
                    $row['redemption'] = number_format((float)$tld['restorePrice'], 2, '.', '');
                }
                $prices['EUR'][$tldName][$years] = $row;
            }
        }

        return $prices;
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
