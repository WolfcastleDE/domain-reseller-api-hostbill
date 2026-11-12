<?php

/**
 * Stub of HostBill's DomainModule base class for testing purposes.
 *
 * Documented by the HostBill domain module dev kit
 * (wiki.hostbillapp.com/index.php?title=Domain_Modules, example1/2.zip from
 * cdn.hostbillapp.com/WIKI_img/hbdommod/): $description, $configuration,
 * $lang, $commands, $options, $client_data, $params, addInfo(), addError(),
 * addDomain(), addPeriod(), logAction(). $modname, $version,
 * $clientCommands, $domain_contacts and $name are used by current
 * open-source modules (domainreseller/hostbill-dna).
 *
 * NOT documented (unverified, the module only calls them via method_exists):
 * logCall(), setExpiryDate(). Visibility of the properties is public here to
 * simplify tests; in HostBill they may be protected.
 */
class DomainModule
{
    protected $modname     = '';
    protected $description = '';
    protected $version     = '';

    protected $configuration = [];
    protected $lang          = [];
    protected $commands       = [];
    protected $clientCommands = [];

    /** @var array Domain-order parameters (sld, tld, etc.) */
    public $params = [];

    /** @var array Additional options (numyears, ns1-ns5, epp_code, sld, tld, etc.) */
    public $options = [];

    /** @var array Client/customer data */
    public $client_data = [];

    /** @var array Per-contact-type arrays keyed by registrant/admin/tech */
    public $domain_contacts = [];

    /** @var string Full domain name */
    public $name = '';

    /** @var int Registration/renewal period in years */
    public $period = 1;

    /** @var array Nameservers (ns1..ns5 keys) */
    public $nameservers = [];

    /** @var string EPP/auth code for transfers */
    public $eppcode = '';

    /** @var int HostBill order ID */
    public $orderid = 0;

    /** @var array Domain config extras */
    public $domain_config = [];

    // -- Tracking arrays used by tests to assert behaviour --

    /** @var string[] Info messages recorded */
    public $_infos = [];

    /** @var string[] Error messages recorded */
    public $_errors = [];

    /** @var array Log call entries [['request' => ..., 'response' => ...], ...] */
    public $_logCalls = [];

    /** @var string|null Domain status set via addDomain() */
    public $_domainStatus = null;

    /** @var bool Whether addPeriod() was called */
    public $_periodAdded = false;

    /** @var array logAction() entries */
    public $_actions = [];

    /** @var string|null Expiry date set via setExpiryDate() */
    public $_expiryDate = null;

    // ------------------------------------------------------------------
    // Methods normally provided by the HostBill DomainModule base class
    // ------------------------------------------------------------------

    protected function addInfo(string $message): void
    {
        $this->_infos[] = $message;
    }

    protected function addError(string $message): void
    {
        $this->_errors[] = $message;
    }

    protected function logCall($request, $response): void
    {
        $this->_logCalls[] = ['request' => $request, 'response' => $response];
    }

    protected function logAction(array $action): void
    {
        $this->_actions[] = $action;
    }

    protected function addDomain(string $status): bool
    {
        $this->_domainStatus = $status;
        return true;
    }

    protected function addPeriod(): void
    {
        $this->_periodAdded = true;
    }

    protected function setExpiryDate(string $date): void
    {
        $this->_expiryDate = $date;
    }
}
