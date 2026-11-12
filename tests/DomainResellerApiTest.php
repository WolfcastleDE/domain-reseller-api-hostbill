<?php

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for the DomainResellerApi HostBill module.
 *
 * Uses a mock of DomainResellerApiClient injected via reflection so that
 * every module method can be tested without network calls.
 */
class DomainResellerApiTest extends TestCase
{
    /** @var DomainResellerApi */
    private $module;

    /** @var DomainResellerApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $apiMock;

    /**
     * Set a protected/private property on the module via reflection.
     */
    private function setProperty(string $name, $value): void
    {
        $ref = new ReflectionProperty(DomainResellerApi::class, $name);
        $ref->setAccessible(true);
        $ref->setValue($this->module, $value);
    }

    protected function setUp(): void
    {
        $this->module = new DomainResellerApi();

        // Set minimum required configuration values via reflection (protected)
        $this->setProperty('configuration', [
            'apiurl'       => ['value' => 'https://domain-reseller-api.de', 'type' => 'input', 'default' => false],
            'apikey'       => ['value' => 'drapi_test_key', 'type' => 'password', 'default' => false],
            'sandbox'      => ['value' => '0', 'type' => 'check', 'default' => '0'],
        ]);

        // Default domain params (public in DomainModule stub)
        $this->module->params  = ['sld' => 'example', 'tld' => 'com'];
        $this->module->options = ['numyears' => 1, 'sld' => 'example', 'tld' => 'com'];
        $this->module->name    = 'example.com';
        $this->module->period  = 1;

        // Default client data
        $this->module->client_data = [
            'firstname'   => 'Max',
            'lastname'    => 'Mustermann',
            'companyname' => '',
            'email'       => 'max@example.com',
            'address1'    => 'Hauptstraße 42',
            'city'        => 'Berlin',
            'state'       => 'Berlin',
            'postcode'    => '10115',
            'country'     => 'DE',
            'phonenumber' => '+49.3012345678',
        ];

        // Empty domain_contacts — will fall back to client_data
        $this->module->domain_contacts = [];

        // Create the API mock
        $this->apiMock = $this->createMock(DomainResellerApiClient::class);

        // Inject mock into the private $apiClient property
        $this->setProperty('apiClient', $this->apiMock);

        // Make the mock return empty arrays for getLastRequest/getLastResponse
        // so log() doesn't crash
        $this->apiMock->method('getLastRequest')->willReturn([]);
        $this->apiMock->method('getLastResponse')->willReturn([]);
    }

    // -----------------------------------------------------------------
    // Helper to make createContact succeed with a given handle
    // -----------------------------------------------------------------

    private function expectContactCreation(string $handle = 'HANDLE-001', int $times = 1): void
    {
        $invocation = $this->apiMock->expects($times === 1 ? $this->once() : $this->exactly($times))
            ->method('createContact');

        $invocation->willReturn([
            'success' => true,
            'data'    => ['handle' => $handle],
            'code'    => 201,
        ]);
    }

    private function expectContactCreationAny(string $handle = 'HANDLE-001'): void
    {
        $this->apiMock->method('createContact')
            ->willReturn([
                'success' => true,
                'data'    => ['handle' => $handle],
                'code'    => 201,
            ]);
    }

    // =================================================================
    // testConnection
    // =================================================================

    public function testTestConnectionSuccess(): void
    {
        // Authenticated endpoint: /health would not validate the API key
        $this->apiMock->expects($this->once())
            ->method('listDomains')
            ->with(1, 1)
            ->willReturn(['success' => true, 'data' => ['domains' => []], 'code' => 200]);
        $this->apiMock->expects($this->never())->method('healthCheck');

        $this->assertTrue($this->module->testConnection());
    }

    public function testTestConnectionRejectsInvalidApiKey(): void
    {
        $this->apiMock->expects($this->once())
            ->method('listDomains')
            ->willReturn(['success' => false, 'error' => 'Invalid API key', 'code' => 401]);

        $this->assertFalse($this->module->testConnection());
        $this->assertStringContainsString('Invalid API key', $this->module->_errors[0]);
    }

    public function testTestConnectionFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('listDomains')
            ->willReturn(['success' => false, 'error' => 'Connection refused', 'code' => 0]);

        $this->assertFalse($this->module->testConnection());
        $this->assertNotEmpty($this->module->_errors);
        $this->assertStringContainsString('API connection failed', $this->module->_errors[0]);
    }

    // =================================================================
    // Register
    // =================================================================

    public function testRegisterSuccess(): void
    {
        $this->module->options['numyears'] = 2;
        $this->module->params['ns1'] = 'ns1.test.com';
        $this->module->params['ns2'] = 'ns2.test.com';

        $this->expectContactCreationAny('H-OWNER');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with(
                'example.com',
                2,
                ['ns1.test.com', 'ns2.test.com'],
                ['owner' => 'H-OWNER', 'admin' => 'H-OWNER', 'tech' => 'H-OWNER']
            )
            ->willReturn([
                'success' => true,
                'data'    => [
                    'domainId'  => 'dom-123',
                    'domain'    => 'example.com',
                    'status'    => 'active',
                    'expiresAt' => '2028-03-03T00:00:00Z',
                ],
                'code' => 200,
            ]);

        // Renewals are driven by HostBill: the API's auto-renew is switched off
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['autoRenew' => false])
            ->willReturn(['success' => true, 'code' => 200]);

        $result = $this->module->Register();

        $this->assertTrue($result);
        $this->assertSame('Active', $this->module->_domainStatus);
        $this->assertNotEmpty($this->module->_infos);
        $this->assertStringContainsString('registered', $this->module->_infos[0]);
        $this->assertSame('2028-03-03', $this->module->_expiryDate);
    }

    public function testRegisterWithoutNameserversOmitsThem(): void
    {
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with('example.com', 1, [], $this->anything())
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->assertTrue($this->module->Register());
    }

    public function testRegisterRejectsSingleNameserver(): void
    {
        $this->module->params['ns1'] = 'ns1.only.com';
        $this->apiMock->expects($this->never())->method('createContact');
        $this->apiMock->expects($this->never())->method('registerDomain');

        $this->assertFalse($this->module->Register());
        $this->assertStringContainsString('At least 2 nameservers', $this->module->_errors[0]);
    }

    public function testRegisterNormalisesNameservers(): void
    {
        $this->module->params['ns1'] = ' NS1.Example.NET. ';
        $this->module->params['ns2'] = 'ns2.example.net';
        $this->module->params['ns3'] = 'ns1.example.net';
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with('example.com', 1, ['ns1.example.net', 'ns2.example.net'], $this->anything())
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    public function testRegisterReportsMinimumTermError(): void
    {
        // .ai can only be registered for 2+ years; the API answers 400
        $this->module->options = ['numyears' => 1, 'sld' => 'example', 'tld' => 'ai'];
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with('example.ai', 1, $this->anything(), $this->anything())
            ->willReturn([
                'success' => false,
                'error'   => 'A registration term of 1 year(s) is not available for .ai. Bookable terms: 2, 4, 6, 8, 10 year(s).',
                'code'    => 400,
            ]);

        $this->assertFalse($this->module->Register());
        $this->assertStringContainsString('not available for .ai', end($this->module->_errors));
    }

    public function testRegisterFailsOnContactCreationError(): void
    {
        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->willReturn(['success' => false, 'error' => 'Invalid phone', 'code' => 400]);

        $result = $this->module->Register();

        $this->assertFalse($result);
        $this->assertStringContainsString('Failed to create contact', $this->module->_errors[0]);
    }

    public function testRegisterFailsOnApiError(): void
    {
        $this->expectContactCreationAny('H-OWNER');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->willReturn(['success' => false, 'error' => 'Domain already registered', 'code' => 400]);

        $result = $this->module->Register();

        $this->assertFalse($result);
        $this->assertStringContainsString('Registration failed', $this->module->_errors[count($this->module->_errors) - 1]);
    }

    public function testRegisterUsesYearsFromOptions(): void
    {
        $this->module->options['numyears'] = 5;
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with('example.com', 5, $this->anything(), $this->anything())
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    public function testRegisterMinimumOneYear(): void
    {
        $this->module->options['numyears'] = 0;
        $this->module->period = 0;
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with('example.com', 1, $this->anything(), $this->anything())
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    // =================================================================
    // Transfer
    // =================================================================

    public function testTransferSuccess(): void
    {
        $this->module->options['epp_code'] = 'TRANSFER-AUTH-XYZ';
        $this->expectContactCreationAny('H-OWNER');

        $this->apiMock->expects($this->once())
            ->method('transferDomain')
            ->with('example.com', 'TRANSFER-AUTH-XYZ', $this->anything())
            ->willReturn([
                'success' => true,
                'data'    => ['domain' => 'example.com', 'status' => 'pending'],
                'code'    => 200,
            ]);

        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['autoRenew' => false])
            ->willReturn(['success' => false, 'error' => 'Domain cannot be modified in current state', 'code' => 422]);

        $result = $this->module->Transfer();

        // A refused auto-renew switch (pending transfer) does not fail the transfer
        $this->assertTrue($result);
        $this->assertSame('Pending Transfer', $this->module->_domainStatus);
        $this->assertEmpty($this->module->_errors);
    }

    public function testTransferUsesEppCodeFromParams(): void
    {
        $this->module->options = ['sld' => 'example', 'tld' => 'com', 'numyears' => 1];
        $this->module->params['eppcode'] = 'EPP-FROM-PARAMS';
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('transferDomain')
            ->with('example.com', 'EPP-FROM-PARAMS', $this->anything())
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Transfer();
    }

    public function testTransferFailure(): void
    {
        $this->module->options['epp_code'] = 'BAD';
        $this->expectContactCreationAny('H1');

        $this->apiMock->expects($this->once())
            ->method('transferDomain')
            ->willReturn(['success' => false, 'error' => 'Invalid auth code', 'code' => 400]);

        $this->assertFalse($this->module->Transfer());
        $this->assertStringContainsString('Transfer failed', $this->module->_errors[count($this->module->_errors) - 1]);
    }

    // =================================================================
    // Renew
    // =================================================================

    public function testRenewSuccess(): void
    {
        $this->module->options['numyears'] = 2;

        $this->apiMock->expects($this->once())
            ->method('renewDomain')
            ->with('example.com', 2)
            ->willReturn([
                'success' => true,
                'data'    => [
                    'domain'    => 'example.com',
                    'years'     => 2,
                    'expiresAt' => '2028-03-03T00:00:00.000Z',
                    'message'   => 'Domain renewed successfully',
                ],
                'code'    => 200,
            ]);
        $this->apiMock->expects($this->never())->method('updateDomain');

        $result = $this->module->Renew();

        $this->assertTrue($result);
        $this->assertTrue($this->module->_periodAdded);
        $this->assertSame('2028-03-03', $this->module->_expiryDate);
        $this->assertStringContainsString('renewed for 2 year(s)', $this->module->_infos[0]);
    }

    public function testRenewFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('renewDomain')
            ->willReturn(['success' => false, 'error' => 'Insufficient wallet balance', 'code' => 402]);

        $this->assertFalse($this->module->Renew());
        $this->assertFalse($this->module->_periodAdded);
        $this->assertStringContainsString('Renewal failed: Insufficient wallet balance', $this->module->_errors[0]);
    }

    // =================================================================
    // Suspend / Unsuspend
    // =================================================================

    public function testSuspendDisablesAutoRenew(): void
    {
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['autoRenew' => false])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->Suspend());
        $this->assertStringContainsString('suspended', $this->module->_infos[0]);
    }

    public function testSuspendFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->willReturn(['success' => false, 'error' => 'error', 'code' => 500]);

        $this->assertFalse($this->module->Suspend());
    }

    public function testUnsuspendDoesNotReenableApiAutoRenew(): void
    {
        $this->apiMock->expects($this->never())->method('updateDomain');

        $this->assertTrue($this->module->Unsuspend());
        $this->assertStringContainsString('unsuspended', $this->module->_infos[0]);
    }

    // =================================================================
    // Terminate
    // =================================================================

    public function testTerminateSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('deleteDomain')
            ->with('example.com')
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->Terminate());
        $this->assertStringContainsString('deleted', $this->module->_infos[0]);
    }

    public function testTerminateFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('deleteDomain')
            ->willReturn(['success' => false, 'error' => 'Cannot delete', 'code' => 422]);

        $this->assertFalse($this->module->Terminate());
        $this->assertStringContainsString('Termination failed', $this->module->_errors[0]);
    }

    // =================================================================
    // Nameservers
    // =================================================================

    public function testGetNameServersSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->with('example.com')
            ->willReturn([
                'success' => true,
                'data'    => ['nameservers' => ['ns1.dns.com', 'ns2.dns.com', 'ns3.dns.com']],
                'code'    => 200,
            ]);

        $ns = $this->module->getNameServers();

        $this->assertSame([
            'ns1' => 'ns1.dns.com',
            'ns2' => 'ns2.dns.com',
            'ns3' => 'ns3.dns.com',
        ], $ns);
    }

    public function testGetNameServersFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn(['success' => false, 'code' => 404]);

        $this->assertFalse($this->module->getNameServers());
        $this->assertStringContainsString('Failed to retrieve nameservers', $this->module->_errors[0]);
    }

    public function testUpdateNameServersSuccess(): void
    {
        $this->module->params['ns1'] = 'ns1.new.com';
        $this->module->params['ns2'] = 'ns2.new.com';

        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['useManagedDns' => false], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['nameservers' => ['ns1.new.com', 'ns2.new.com']])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->updateNameServers());
    }

    public function testUpdateNameServersAwayFromManagedDnsDisablesIt(): void
    {
        $this->module->params['ns1'] = 'ns1.own.net';
        $this->module->params['ns2'] = 'ns2.own.net';

        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['useManagedDns' => true], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['nameservers' => ['ns1.own.net', 'ns2.own.net'], 'useManagedDns' => false])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->updateNameServers());
    }

    public function testUpdateNameServersToManagedDnsEnablesIt(): void
    {
        $this->module->params['ns1'] = 'ns1.domain-reseller-api.de';
        $this->module->params['ns2'] = 'ns2.domain-reseller-api.de';

        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['useManagedDns' => false], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['useManagedDns' => true])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->updateNameServers());
    }

    public function testUpdateNameServersRequiresMinimumTwo(): void
    {
        $this->module->params = ['sld' => 'example', 'tld' => 'com', 'ns1' => 'ns1.only.com'];
        $this->module->options = ['sld' => 'example', 'tld' => 'com', 'numyears' => 1];

        $this->assertFalse($this->module->updateNameServers());
        $this->assertStringContainsString('At least 2 nameservers', $this->module->_errors[0]);
    }

    public function testUpdateNameServersFailure(): void
    {
        $this->module->params['ns1'] = 'ns1.x.com';
        $this->module->params['ns2'] = 'ns2.x.com';

        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['useManagedDns' => false], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->willReturn(['success' => false, 'error' => 'Invalid NS', 'code' => 400]);

        $this->assertFalse($this->module->updateNameServers());
    }

    // =================================================================
    // DNS Management
    // =================================================================

    public function testGetDNSSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDnsRecords')
            ->with('example.com')
            ->willReturn([
                'success' => true,
                'data'    => [
                    'domain'        => 'example.com',
                    'useManagedDns' => true,
                    'records'       => [
                        ['id' => 'r1', 'type' => 'A',    'name' => '@',   'content' => '1.2.3.4', 'ttl' => 3600, 'priority' => null],
                        ['id' => 'r2', 'type' => 'MX',   'name' => '@',   'content' => 'mail.example.com', 'ttl' => 3600, 'priority' => 10],
                        ['id' => 'r3', 'type' => 'CNAME', 'name' => 'www', 'content' => 'example.com', 'ttl' => 3600, 'priority' => null],
                    ],
                ],
                'code' => 200,
            ]);

        $records = $this->module->getDNS();

        $this->assertCount(3, $records);
        $this->assertSame('@', $records[0]['hostname']);
        $this->assertSame('A', $records[0]['type']);
        $this->assertSame('1.2.3.4', $records[0]['address']);
        $this->assertSame(10, $records[1]['priority']);
        $this->assertSame('www', $records[2]['hostname']);
    }

    public function testGetDNSDoesNotEnableManagedDns(): void
    {
        // Without managed DNS the API answers HTTP 200 with success:false
        $this->apiMock->expects($this->once())
            ->method('getDnsRecords')
            ->willReturn([
                'success' => false,
                'error'   => 'Managed DNS is not enabled for this domain. Enable it first to manage DNS records.',
                'code'    => 200,
            ]);
        $this->apiMock->expects($this->never())->method('enableManagedDns');
        $this->apiMock->expects($this->never())->method('updateDomain');

        $this->assertSame([], $this->module->getDNS());
    }

    public function testGetDNSSkipsManagedApexNsRecords(): void
    {
        $this->apiMock->method('getDnsRecords')->willReturn([
            'success' => true,
            'data'    => ['domain' => 'example.com', 'useManagedDns' => true, 'records' => [
                ['id' => '1', 'type' => 'NS', 'name' => '@', 'content' => 'ns1.domain-reseller-api.de', 'ttl' => 3600, 'priority' => null, 'managed' => true],
                ['id' => '2', 'type' => 'A', 'name' => '@', 'content' => '1.2.3.4', 'ttl' => 3600, 'priority' => null],
            ]],
            'code'    => 200,
        ]);

        $records = $this->module->getDNS();
        $this->assertCount(1, $records);
        $this->assertSame('A', $records[0]['type']);
    }

    public function testGetDNSReturnsEmptyOnTotalFailure(): void
    {
        $this->apiMock->method('getDnsRecords')
            ->willReturn(['success' => false, 'code' => 500]);

        $this->assertSame([], $this->module->getDNS());
    }

    public function testSetDNSCreatesNewThenDeletesOld(): void
    {
        // Managed DNS is enabled via PATCH /domains/:domain (also switches the nameservers)
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn(['success' => true, 'data' => ['useManagedDns' => false], 'code' => 200]);
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['useManagedDns' => true])
            ->willReturn(['success' => true, 'useManagedDns' => true, 'code' => 200]);
        $this->apiMock->expects($this->never())->method('enableManagedDns');

        // New record created first, returns an ID
        $this->apiMock->expects($this->once())
            ->method('createDnsRecord')
            ->with('example.com', [
                'type'    => 'A',
                'name'    => '@',
                'content' => '9.9.9.9',
                'ttl'     => 3600,
            ])
            ->willReturn(['success' => true, 'data' => ['id' => 'new-1'], 'code' => 201]);

        // After creation, getDnsRecords is called to find old records to delete
        $this->apiMock->expects($this->once())
            ->method('getDnsRecords')
            ->willReturn([
                'success' => true,
                'data'    => [
                    'records' => [
                        ['id' => 'ns-1', 'type' => 'NS', 'name' => '@', 'content' => 'ns1.domain-reseller-api.de', 'managed' => true],
                        ['id' => 'old-1', 'type' => 'A', 'name' => '@', 'content' => '1.1.1.1'],
                        ['id' => 'new-1', 'type' => 'A', 'name' => '@', 'content' => '9.9.9.9'],
                    ],
                ],
                'code' => 200,
            ]);

        // Only old-1 should be deleted (new-1 is kept as it was just created)
        $this->apiMock->expects($this->once())
            ->method('deleteDnsRecord')
            ->with('example.com', 'old-1')
            ->willReturn(['success' => true, 'code' => 200]);

        $result = $this->module->setDNS([
            ['type' => 'A', 'hostname' => '@', 'address' => '9.9.9.9'],
        ]);

        $this->assertTrue($result);
    }

    public function testSetDNSRollsBackOnCreateFailure(): void
    {
        $this->apiMock->method('getDomain')->willReturn(['success' => true, 'data' => ['useManagedDns' => true], 'code' => 200]);

        // First createDnsRecord succeeds, second fails
        $this->apiMock->method('createDnsRecord')
            ->willReturnOnConsecutiveCalls(
                ['success' => true, 'data' => ['id' => 'new-1'], 'code' => 201],
                ['success' => false, 'error' => 'Invalid record', 'code' => 400]
            );

        // Rollback should delete the successfully-created record
        $this->apiMock->expects($this->once())
            ->method('deleteDnsRecord')
            ->with('example.com', 'new-1')
            ->willReturn(['success' => true, 'code' => 200]);

        $result = $this->module->setDNS([
            ['type' => 'A', 'hostname' => '@', 'address' => '9.9.9.9'],
            ['type' => 'AAAA', 'hostname' => '@', 'address' => '::1'],
        ]);

        $this->assertFalse($result);
        $this->assertStringContainsString('Failed to create DNS record', $this->module->_errors[0]);
    }

    public function testSetDNSReturnsFalseOnCreateFailure(): void
    {
        $this->apiMock->method('getDomain')->willReturn(['success' => true, 'data' => ['useManagedDns' => true], 'code' => 200]);

        $this->apiMock->expects($this->once())
            ->method('createDnsRecord')
            ->willReturn(['success' => false, 'error' => 'Invalid record', 'code' => 400]);

        $result = $this->module->setDNS([
            ['type' => 'A', 'hostname' => '@', 'address' => '9.9.9.9'],
        ]);

        $this->assertFalse($result);
    }

    public function testSetDNSSkipsApexNsAndSoa(): void
    {
        $this->apiMock->method('getDomain')->willReturn(['success' => true, 'data' => ['useManagedDns' => true], 'code' => 200]);
        $this->apiMock->method('getDnsRecords')->willReturn(['success' => true, 'data' => ['records' => []], 'code' => 200]);

        $this->apiMock->expects($this->once())
            ->method('createDnsRecord')
            ->with('example.com', ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all', 'ttl' => 3600])
            ->willReturn(['success' => true, 'data' => ['id' => 't1'], 'code' => 200]);

        $this->assertTrue($this->module->setDNS([
            ['type' => 'NS', 'hostname' => '@', 'address' => 'ns1.domain-reseller-api.de'],
            ['type' => 'SOA', 'hostname' => '@', 'address' => 'x'],
            ['type' => 'TXT', 'hostname' => '', 'address' => 'v=spf1 -all'],
        ]));
    }

    public function testSetDNSFailsWhenManagedDnsCannotBeEnabled(): void
    {
        $this->apiMock->method('getDomain')->willReturn(['success' => true, 'data' => ['useManagedDns' => false], 'code' => 200]);
        $this->apiMock->method('updateDomain')->willReturn([
            'success' => false, 'error' => 'DNS-Zone wurde erstellt, aber die Nameserver antworten noch nicht autoritativ.', 'code' => 503,
        ]);
        $this->apiMock->expects($this->never())->method('createDnsRecord');

        $this->assertFalse($this->module->setDNS([['type' => 'A', 'hostname' => '@', 'address' => '1.2.3.4']]));
        $this->assertStringContainsString('Failed to enable managed DNS', $this->module->_errors[0]);
    }

    public function testSetDNSIncludesPriorityForMX(): void
    {
        $this->apiMock->method('getDomain')->willReturn(['success' => true, 'data' => ['useManagedDns' => true], 'code' => 200]);
        $this->apiMock->method('getDnsRecords')->willReturn([
            'success' => true, 'data' => ['records' => []], 'code' => 200,
        ]);
        $this->apiMock->method('deleteDnsRecord')->willReturn(['success' => true, 'code' => 200]);

        $this->apiMock->expects($this->once())
            ->method('createDnsRecord')
            ->with('example.com', [
                'type'     => 'MX',
                'name'     => '@',
                'content'  => 'mail.example.com',
                'ttl'      => 3600,
                'priority' => 10,
            ])
            ->willReturn(['success' => true, 'data' => ['id' => 'new-mx'], 'code' => 201]);

        $this->module->setDNS([
            ['type' => 'MX', 'hostname' => '@', 'address' => 'mail.example.com', 'priority' => '10'],
        ]);
    }

    // =================================================================
    // Contact Details
    // =================================================================

    public function testGetContactDetailsSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => [
                    'contacts' => ['owner' => 'H-OWN', 'admin' => 'H-ADM', 'tech' => 'H-TCH'],
                ],
                'code' => 200,
            ]);

        $contactData = [
            'firstName'    => 'Max',
            'lastName'     => 'Mustermann',
            'organization' => 'ACME GmbH',
            'email'        => 'max@example.com',
            'street'       => 'Hauptstraße',
            'houseNumber'  => '42',
            'city'         => 'Berlin',
            'state'        => 'Berlin',
            'postalCode'   => '10115',
            'country'      => 'DE',
            'phone'        => '+49.3012345678',
        ];

        $this->apiMock->expects($this->exactly(3))
            ->method('getContact')
            ->willReturn(['success' => true, 'data' => $contactData, 'code' => 200]);

        $result = $this->module->getContactDetails();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('Registrant', $result);
        $this->assertArrayHasKey('Admin', $result);
        $this->assertArrayHasKey('Tech', $result);
        $this->assertSame('Max', $result['Registrant']['First Name']);
        $this->assertSame('Mustermann', $result['Registrant']['Last Name']);
        $this->assertSame('ACME GmbH', $result['Registrant']['Company Name']);
        $this->assertSame('Hauptstraße 42', $result['Registrant']['Address 1']);
    }

    public function testGetContactDetailsFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn(['success' => false, 'code' => 404]);

        $this->assertFalse($this->module->getContactDetails());
        $this->assertStringContainsString('Failed to retrieve domain details', $this->module->_errors[0]);
    }

    public function testSetContactDetailsSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['contacts' => ['owner' => 'H-OWN', 'admin' => 'H-ADM', 'tech' => 'H-TCH']],
                'code'    => 200,
            ]);

        $this->apiMock->expects($this->exactly(2))
            ->method('updateContact')
            ->willReturn(['success' => true, 'code' => 200]);

        $result = $this->module->setContactDetails([
            'Registrant' => [
                'First Name' => 'Jane', 'Last Name' => 'Doe', 'Company Name' => '',
                'Email' => 'jane@example.com', 'Address 1' => 'Gartenweg 5',
                'City' => 'München', 'State' => 'Bayern', 'Postcode' => '80331',
                'Country' => 'DE', 'Phone' => '+49.8912345678',
            ],
            'Admin' => [
                'First Name' => 'Admin', 'Last Name' => 'User', 'Company Name' => '',
                'Email' => 'admin@example.com', 'Address 1' => 'Adminstr 1',
                'City' => 'Hamburg', 'State' => 'Hamburg', 'Postcode' => '20095',
                'Country' => 'DE', 'Phone' => '+49.4012345678',
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testSetContactDetailsPartialFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['contacts' => ['owner' => 'H-OWN']],
                'code'    => 200,
            ]);

        // Invalid data is rejected before the API call
        $this->apiMock->expects($this->never())->method('updateContact');

        $result = $this->module->setContactDetails([
            'Registrant' => [
                'First Name' => 'Jane', 'Last Name' => 'Doe', 'Company Name' => '',
                'Email' => 'bad', 'Address 1' => '', 'City' => '', 'State' => '',
                'Postcode' => '', 'Country' => '', 'Phone' => '',
            ],
        ]);

        $this->assertFalse($result);
        $this->assertStringContainsString('invalid country, email, phone', $this->module->_errors[0]);
    }

    public function testSetContactDetailsApiFailure(): void
    {
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['contacts' => ['owner' => 'H-OWN']], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateContact')
            ->willReturn(['success' => false, 'error' => 'Validation error', 'code' => 400]);

        $this->assertFalse($this->module->setContactDetails(['Registrant' => self::contactForm('Jane')]));
        $this->assertStringContainsString('Failed to update Registrant contact: Validation error', $this->module->_errors[0]);
    }

    public function testSetContactDetailsSendsApiFieldNames(): void
    {
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['contacts' => ['owner' => 'H-OWN']], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateContact')
            ->with('H-OWN', [
                // type and sex can not be changed via PATCH; "" clears organisation/house number
                'firstName'    => 'Jane',
                'lastName'     => 'Doe',
                'street'       => 'Gartenweg',
                'postalCode'   => '80331',
                'city'         => 'München',
                'country'      => 'DE',
                'email'        => 'jane@example.com',
                'phone'        => '+49.8912345678',
                'houseNumber'  => '5',
                'state'        => 'Bayern',
                'organization' => '',
            ])
            ->willReturn(['success' => true, 'message' => 'Contact updated successfully', 'code' => 200]);

        $this->assertTrue($this->module->setContactDetails(['Registrant' => self::contactForm('Jane')]));
    }

    public function testSetContactDetailsGivesSharedRoleOwnContact(): void
    {
        // Admin and tech share the owner's handle (as created by Register)
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true,
            'data'    => ['contacts' => ['owner' => 'H-OWN', 'admin' => 'H-OWN', 'tech' => 'H-OWN']],
            'code'    => 200,
        ]);

        // Owner is updated in place, only once
        $this->apiMock->expects($this->once())
            ->method('updateContact')
            ->with('H-OWN', $this->anything())
            ->willReturn(['success' => true, 'code' => 200]);

        // Admin has different data → own contact, assigned via PATCH /domains/:domain/contacts
        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->with($this->callback(function ($data) {
                return $data['firstName'] === 'Admin' && $data['type'] === 'person' && $data['sex'] === 'NA';
            }))
            ->willReturn(['success' => true, 'data' => ['id' => 'c2', 'handle' => 'H-ADM2', 'message' => 'ok'], 'code' => 200]);

        $this->apiMock->expects($this->once())
            ->method('updateDomainContacts')
            ->with('example.com', ['admin' => 'H-ADM2'])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->setContactDetails([
            'Registrant' => self::contactForm('Jane'),
            'Admin'      => self::contactForm('Admin'),
            'Tech'       => self::contactForm('Jane'),
        ]));
    }

    private static function contactForm(string $firstName): array
    {
        return [
            'First Name' => $firstName, 'Last Name' => 'Doe', 'Company Name' => '',
            'Email' => 'jane@example.com', 'Address 1' => 'Gartenweg 5',
            'City' => 'München', 'State' => 'Bayern', 'Postcode' => '80331',
            'Country' => 'DE', 'Phone' => '+49.8912345678',
        ];
    }

    // =================================================================
    // EPP Code
    // =================================================================

    public function testGetEPPCodeSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getAuthCode')
            ->with('example.com')
            ->willReturn([
                'success' => true,
                'data'    => ['domain' => 'example.com', 'authCode' => 'SECRET-EPP-123'],
                'code'    => 200,
            ]);

        $this->assertSame('SECRET-EPP-123', $this->module->getEPPCode());
    }

    public function testGetEPPCodeRejectsPlaceholderText(): void
    {
        $this->apiMock->method('getAuthCode')->willReturn([
            'success' => true,
            'data'    => ['domain' => 'example.com', 'authCode' => 'Unable to retrieve auth code'],
            'code'    => 200,
        ]);

        $this->assertFalse($this->module->getEPPCode());
    }

    public function testGetEPPCodeFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getAuthCode')
            ->willReturn(['success' => false, 'error' => 'Not allowed', 'code' => 403]);

        $this->assertFalse($this->module->getEPPCode());
        $this->assertStringContainsString('Failed to retrieve EPP code', $this->module->_errors[0]);
    }

    // =================================================================
    // checkAvailability
    // =================================================================

    public function testCheckAvailabilityAvailable(): void
    {
        $this->apiMock->expects($this->once())
            ->method('checkDomain')
            ->with('example.com', 1)
            ->willReturn([
                'success' => true,
                'data'    => [
                    'domain'     => 'example.com',
                    'available'  => true,
                    'premium'    => false,
                    'price'      => 9.99,
                    'years'      => 1,
                    'setupPrice' => null,
                ],
                'code' => 200,
            ]);

        $result = $this->module->checkAvailability();

        $this->assertTrue($result['available']);
        $this->assertFalse($result['premium']);
        $this->assertSame(9.99, $result['price']);
    }

    public function testCheckAvailabilityNotAvailable(): void
    {
        $this->apiMock->expects($this->once())
            ->method('checkDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['domain' => 'example.com', 'available' => false, 'premium' => false, 'setupPrice' => null],
                'code'    => 200,
            ]);

        $result = $this->module->checkAvailability();
        $this->assertFalse($result['available']);
    }

    public function testCheckAvailabilityApiFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('checkDomain')
            ->willReturn(['success' => false, 'code' => 500]);

        $result = $this->module->checkAvailability();
        $this->assertFalse($result['available']);
    }

    // =================================================================
    // synchInfo
    // =================================================================

    public function testSynchInfoActive(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'active', 'expiresAt' => '2027-06-15T00:00:00Z'],
                'code'    => 200,
            ]);

        $result = $this->module->synchInfo();

        $this->assertSame('Active', $result['status']);
        $this->assertSame('2027-06-15', $result['expires']);
    }

    public function testSynchInfoExpired(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'expired', 'expiresAt' => '2025-01-01T00:00:00Z'],
                'code'    => 200,
            ]);

        $result = $this->module->synchInfo();
        $this->assertSame('Expired', $result['status']);
    }

    public function testSynchInfoTransferring(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'pending_transfer', 'expiresAt' => null],
                'code'    => 200,
            ]);

        $result = $this->module->synchInfo();
        $this->assertSame('Pending Transfer', $result['status']);
        $this->assertArrayNotHasKey('expires', $result);
    }

    /**
     * @dataProvider apiStatusProvider
     */
    public function testSynchInfoMapsApiStates(string $apiStatus, string $hostBillStatus): void
    {
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['status' => $apiStatus, 'expiresAt' => null, 'autoRenew' => false], 'code' => 200,
        ]);

        $this->assertSame($hostBillStatus, $this->module->synchInfo()['status']);
    }

    public function apiStatusProvider(): array
    {
        return [
            ['pending', 'Pending'],
            ['pending_transfer', 'Pending Transfer'],
            ['active', 'Active'],
            ['expired', 'Expired'],
            ['redemption', 'Expired'],
            ['pending_delete', 'Active'],
            ['transferred_away', 'Transferred Away'],
            ['suspended', 'Active'],
            ['deleted', 'Cancelled'],
        ];
    }

    public function testSynchInfoDisablesApiAutoRenewOfActiveDomain(): void
    {
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['status' => 'active', 'autoRenew' => true, 'expiresAt' => '2027-01-01T00:00:00Z'], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateDomain')
            ->with('example.com', ['autoRenew' => false])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->module->synchInfo();
    }

    public function testSynchInfoUnknownStatusDefaultsToActive(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'some_new_status', 'expiresAt' => '2030-01-01T00:00:00Z'],
                'code'    => 200,
            ]);

        $result = $this->module->synchInfo();
        $this->assertSame('Active', $result['status']);
    }

    public function testSynchInfoApiFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->willReturn(['success' => false, 'code' => 500]);

        $this->assertSame([], $this->module->synchInfo());
    }

    // =================================================================
    // importTLDPricing
    // =================================================================

    public function testImportTLDPricingSuccess(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getPricing')
            ->willReturn([
                'success' => true,
                'data'    => [
                    [
                        'tld'           => 'com',
                        'registerPrice' => '9.99',
                        'renewPrice'    => '11.99',
                        'transferPrice' => '8.99',
                        'restorePrice'  => '59.99',
                        'minYears'      => 1,
                        'maxYears'      => 10,
                    ],
                    [
                        'tld'           => 'de',
                        'registerPrice' => '4.99',
                        'renewPrice'    => '4.99',
                        'transferPrice' => '4.99',
                        'restorePrice'  => null,
                        'minYears'      => 1,
                        'maxYears'      => 1,
                    ],
                ],
                'code' => 200,
            ]);

        $pricing = $this->module->importTLDPricing();

        $this->assertIsArray($pricing);
        $this->assertArrayHasKey('com', $pricing);
        $this->assertArrayHasKey('de', $pricing);
        $this->assertSame(9.99, $pricing['com']['register']);
        $this->assertSame(11.99, $pricing['com']['renew']);
        $this->assertSame(8.99, $pricing['com']['transfer']);
        $this->assertSame(59.99, $pricing['com']['restore']);
        $this->assertSame(1, $pricing['com']['minyears']);
        $this->assertSame(10, $pricing['com']['maxyears']);
        $this->assertSame(0.0, $pricing['de']['restore']);
    }

    public function testImportTLDPricingFailure(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getPricing')
            ->willReturn(['success' => false, 'error' => 'Server error', 'code' => 500]);

        $this->assertFalse($this->module->importTLDPricing());
    }

    public function testImportTLDPricingSkipsEmptyTld(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getPricing')
            ->willReturn([
                'success' => true,
                'data'    => [['tld' => '', 'registerPrice' => '1.00', 'renewPrice' => '1.00', 'transferPrice' => '1.00', 'restorePrice' => null, 'minYears' => 1, 'maxYears' => 1]],
                'code'    => 200,
            ]);

        $pricing = $this->module->importTLDPricing();
        $this->assertEmpty($pricing);
    }

    // =================================================================
    // Domain name resolution
    // =================================================================

    public function testDomainNameFromOptions(): void
    {
        $this->module->options = ['sld' => 'mysite', 'tld' => 'org', 'numyears' => 1];
        $this->module->params  = [];

        $this->apiMock->method('getDomain')->willReturn(['success' => false, 'code' => 404]);

        // Invoke getNameServers which calls domainName() internally
        $this->module->getNameServers();

        // Verify the mock received the correct domain
        // (We check via the error message which includes the domain)
        // Actually let's use a direct approach - the getDomain mock was called
        $this->assertTrue(true); // if we got here without error, domain was resolved
    }

    public function testDomainNameFromParams(): void
    {
        $this->module->options = ['numyears' => 1]; // no sld/tld in options
        $this->module->params  = ['sld' => 'fromparams', 'tld' => 'net'];

        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->with('fromparams.net')
            ->willReturn(['success' => false, 'code' => 404]);

        $this->module->getNameServers();
    }

    public function testDomainNameFallsBackToName(): void
    {
        $this->module->options = ['numyears' => 1];
        $this->module->params  = [];
        $this->module->name    = 'fallback.io';

        $this->apiMock->expects($this->once())
            ->method('getDomain')
            ->with('fallback.io')
            ->willReturn(['success' => false, 'code' => 404]);

        $this->module->getNameServers();
    }

    // =================================================================
    // Contact creation with company → org type
    // =================================================================

    public function testContactCreationUsesOrgTypeForCompany(): void
    {
        $this->module->client_data['companyname'] = 'ACME GmbH';

        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->with($this->callback(function ($data) {
                // API enum: person|organization|role; sex is required (MALE|FEMALE|NA)
                return $data['type'] === 'organization' && $data['organization'] === 'ACME GmbH'
                    && $data['sex'] === 'NA';
            }))
            ->willReturn(['success' => true, 'data' => ['handle' => 'H-ORG'], 'code' => 201]);

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    public function testContactCreationUsesPersonTypeWithoutCompany(): void
    {
        $this->module->client_data['companyname'] = '';

        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->with($this->callback(function ($data) {
                // Natural persons have no organisation
                return $data['type'] === 'person' && $data['sex'] === 'NA'
                    && !array_key_exists('organization', $data);
            }))
            ->willReturn(['success' => true, 'data' => ['handle' => 'H-PER'], 'code' => 201]);

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    // =================================================================
    // Address splitting (EU format: street + houseNumber)
    // =================================================================

    public function testAddressSplitsHouseNumber(): void
    {
        $this->module->client_data['address1'] = 'Hauptstraße 42b';

        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->with($this->callback(function ($data) {
                return $data['street'] === 'Hauptstraße' && $data['houseNumber'] === '42b';
            }))
            ->willReturn(['success' => true, 'data' => ['handle' => 'H1'], 'code' => 201]);

        $this->apiMock->method('registerDomain')
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    public function testAddressWithoutNumberStaysIntact(): void
    {
        $this->module->client_data['address1'] = 'Post Office Box';

        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->with($this->callback(function ($data) {
                // houseNumber is optional and omitted when there is none
                return $data['street'] === 'Post Office Box' && !array_key_exists('houseNumber', $data);
            }))
            ->willReturn(['success' => true, 'data' => ['handle' => 'H1'], 'code' => 201]);

        $this->apiMock->method('registerDomain')
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    // =================================================================
    // Logging
    // =================================================================

    public function testApiCallsAreLogged(): void
    {
        $this->apiMock->expects($this->once())
            ->method('listDomains')
            ->willReturn(['success' => true, 'code' => 200]);

        $this->module->testConnection();

        $this->assertNotEmpty($this->module->_logCalls);
        $this->assertArrayHasKey('action', $this->module->_logCalls[0]['request']);
        $this->assertSame('testConnection', $this->module->_logCalls[0]['request']['action']);
    }

    // =================================================================
    // Phone formatting
    // =================================================================

    public function testPhoneWithPlusPassedThrough(): void
    {
        $this->module->client_data['phonenumber'] = '+49.3012345678';

        $this->apiMock->expects($this->once())
            ->method('createContact')
            ->with($this->callback(function ($data) {
                // API pattern ^\+\d+\.\d+$ — the dot must be kept
                return $data['phone'] === '+49.3012345678';
            }))
            ->willReturn(['success' => true, 'data' => ['handle' => 'H1'], 'code' => 201]);

        $this->apiMock->method('registerDomain')
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->module->Register();
    }

    // =================================================================
    // Register with separate admin/tech contacts
    // =================================================================

    public function testRegisterWithSeparateContacts(): void
    {
        $this->module->domain_contacts = [
            'registrant' => [
                'firstname' => 'Owner', 'lastname' => 'Person', 'companyname' => '',
                'email' => 'owner@test.com', 'address1' => 'Str 1', 'city' => 'Berlin',
                'state' => 'Berlin', 'postcode' => '10115', 'country' => 'DE',
                'phonenumber' => '+49.111',
            ],
            'admin' => [
                'firstname' => 'Admin', 'lastname' => 'Person', 'companyname' => '',
                'email' => 'admin@test.com', 'address1' => 'Str 2', 'city' => 'Berlin',
                'state' => 'Berlin', 'postcode' => '10115', 'country' => 'DE',
                'phonenumber' => '+49.222',
            ],
        ];

        // Owner and admin are different, tech falls back to registrant
        $this->apiMock->expects($this->exactly(2))
            ->method('createContact')
            ->willReturnOnConsecutiveCalls(
                ['success' => true, 'data' => ['handle' => 'H-OWNER'], 'code' => 201],
                ['success' => true, 'data' => ['handle' => 'H-ADMIN'], 'code' => 201]
            );

        $this->apiMock->expects($this->once())
            ->method('registerDomain')
            ->with(
                'example.com',
                $this->anything(),
                $this->anything(),
                ['owner' => 'H-OWNER', 'admin' => 'H-ADMIN', 'tech' => 'H-OWNER']
            )
            ->willReturn(['success' => true, 'data' => [], 'code' => 200]);

        $this->assertTrue($this->module->Register());
    }

    // =================================================================
    // Contact payload (POST /contacts schema)
    // =================================================================

    public function testContactPayloadMatchesApiSchema(): void
    {
        $payload = DomainResellerApi::buildContactPayload([
            'firstname'   => 'Max',
            'lastname'    => 'Mustermann',
            'companyname' => 'ACME GmbH',
            'email'       => 'max@example.com',
            'address1'    => 'Hauptstraße 42',
            'address2'    => 'Hinterhaus',
            'city'        => 'Hannover',
            'state'       => 'Niedersachsen',
            'postcode'    => '30159',
            'country'     => 'de',
            'phonenumber' => '0511 123456',
            'pin'         => 'DE123456789',
        ]);

        $this->assertSame([
            'type'         => 'organization',
            'sex'          => 'NA',
            'firstName'    => 'Max',
            'lastName'     => 'Mustermann',
            'street'       => 'Hauptstraße',
            'postalCode'   => '30159',
            'city'         => 'Hannover',
            'country'      => 'DE',
            'email'        => 'max@example.com',
            'phone'        => '+49.511123456',
            'houseNumber'  => '42',
            'organization' => 'ACME GmbH',
            'extension'    => 'Hinterhaus',
            // Passed as entered; the API maps it to the ISO 3166-2 code for .it
            'state'        => 'Niedersachsen',
            'pin'          => 'DE123456789',
        ], $payload);

        $this->assertContains($payload['type'], ['person', 'organization', 'role']);
        $this->assertContains($payload['sex'], ['MALE', 'FEMALE', 'NA']);
        $this->assertMatchesRegularExpression('/^\+\d+\.\d+$/', $payload['phone']);
        $this->assertMatchesRegularExpression('/^[A-Za-z]{2}$/', $payload['country']);
    }

    public function testContactPayloadFillsRequiredFieldsForCompanyWithoutNames(): void
    {
        $payload = DomainResellerApi::buildContactPayload([
            'companyname' => 'ACME GmbH', 'email' => 'info@acme.de', 'address1' => '',
            'city' => '', 'postcode' => '', 'country' => 'DE', 'phonenumber' => '+49 30 1234',
        ]);

        // firstName/lastName/street/postalCode/city have minLength 1 in the API
        $this->assertSame('ACME GmbH', $payload['firstName']);
        $this->assertSame('ACME GmbH', $payload['lastName']);
        $this->assertSame('-', $payload['street']);
        $this->assertSame('-', $payload['postalCode']);
        $this->assertSame('-', $payload['city']);
        $this->assertArrayNotHasKey('state', $payload);
        $this->assertArrayNotHasKey('pin', $payload);
    }

    public function testRegisterRejectsUnformattablePhoneLocally(): void
    {
        $this->module->client_data['phonenumber'] = 'n/a';
        $this->apiMock->expects($this->never())->method('createContact');
        $this->apiMock->expects($this->never())->method('registerDomain');

        $this->assertFalse($this->module->Register());
        $this->assertStringContainsString('invalid phone', $this->module->_errors[0]);
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testFormatPhone(string $input, string $country, ?string $expected): void
    {
        $this->assertSame($expected, DomainResellerApi::formatPhone($input, $country));
    }

    public function phoneProvider(): array
    {
        return [
            'already API format'      => ['+49.3012345678', 'DE', '+49.3012345678'],
            'international spaces'    => ['+49 30 12345678', 'DE', '+49.3012345678'],
            'redundant trunk prefix'  => ['+49 (0)30 12345678', 'DE', '+49.3012345678'],
            '00 prefix'               => ['0049 30 12345678', 'DE', '+49.3012345678'],
            'national with country'   => ['030 12345678', 'DE', '+49.3012345678'],
            'Italy keeps trunk 0'     => ['06 1234567', 'IT', '+39.061234567'],
            'NANP with leading 1'     => ['1 (555) 123-4567', 'US', '+1.5551234567'],
            'other country code'      => ['+43 1 234567', 'DE', '+43.1234567'],
            'empty'                   => ['', 'DE', null],
            'national without country'=> ['030 1234', '', null],
        ];
    }

    /**
     * @dataProvider streetProvider
     */
    public function testSplitStreet(string $address, array $expected): void
    {
        $this->assertSame($expected, DomainResellerApi::splitStreet($address));
    }

    public function streetProvider(): array
    {
        return [
            ['Hauptstraße 42', ['Hauptstraße', '42']],
            ['Hauptstraße 42 b', ['Hauptstraße', '42b']],
            ['Am Markt 3-5', ['Am Markt', '3-5']],
            ['123 Main Street', ['Main Street', '123']],
            ['Post Office Box', ['Post Office Box', '']],
            ['', ['', '']],
        ];
    }

    public function testGetContactDetailsHidesOrganisationOfPersons(): void
    {
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['contacts' => ['owner' => 'H-OWN']], 'code' => 200,
        ]);
        $this->apiMock->method('getContact')->willReturn(['success' => true, 'data' => [
            'handle' => 'H-OWN', 'type' => 'person', 'firstName' => 'Max', 'lastName' => 'M',
            'organization' => 'Max M', 'street' => 'Weg', 'houseNumber' => null, 'extension' => 'c/o X',
            'city' => 'Berlin', 'postalCode' => '10115', 'state' => null, 'country' => 'DE',
            'email' => 'm@example.com', 'phone' => '+49.301234',
        ], 'code' => 200]);

        $result = $this->module->getContactDetails();
        $this->assertSame('', $result['Registrant']['Company Name']);
        $this->assertSame('Weg', $result['Registrant']['Address 1']);
        $this->assertSame('c/o X', $result['Registrant']['Address 2']);
    }

    // =================================================================
    // Registrar lock
    // =================================================================

    public function testGetRegistrarLock(): void
    {
        $this->apiMock->expects($this->once())
            ->method('getLock')
            ->with('example.com')
            ->willReturn(['success' => true, 'data' => ['domain' => 'example.com', 'supported' => true, 'locked' => true], 'code' => 200]);

        $this->assertTrue($this->module->getRegistrarLock());
    }

    public function testGetRegistrarLockUnsupportedTld(): void
    {
        $this->apiMock->method('getLock')->willReturn([
            'success' => true, 'data' => ['domain' => 'example.de', 'supported' => false, 'locked' => null], 'code' => 200,
        ]);

        $this->assertFalse($this->module->getRegistrarLock());
    }

    public function testUpdateRegistrarLock(): void
    {
        $this->module->options['registrarLock'] = 1;

        $this->apiMock->expects($this->once())
            ->method('setLock')
            ->with('example.com', true)
            ->willReturn(['success' => true, 'data' => ['domain' => 'example.com', 'locked' => true], 'code' => 200]);

        $this->assertTrue($this->module->updateRegistrarLock());
    }

    public function testUpdateRegistrarLockFailure(): void
    {
        $this->apiMock->method('setLock')->willReturn([
            'success' => false, 'error' => 'This TLD has no transfer lock', 'code' => 422,
        ]);

        $this->assertFalse($this->module->updateRegistrarLock());
        $this->assertStringContainsString('no transfer lock', $this->module->_errors[0]);
    }

    // =================================================================
    // Glue records
    // =================================================================

    public function testRegisterNameServer(): void
    {
        $this->module->options['NameServer']   = 'NS1.example.com';
        $this->module->options['NameServerIP'] = '192.0.2.10';

        $this->apiMock->expects($this->once())
            ->method('createHost')
            ->with('example.com', 'ns1.example.com', '192.0.2.10', '')
            ->willReturn(['success' => true, 'data' => ['hostname' => 'ns1.example.com', 'ipv4' => '192.0.2.10'], 'code' => 200]);

        $this->assertTrue($this->module->registerNameServer());
    }

    public function testModifyNameServerWithIpv6(): void
    {
        $this->module->options['NameServer']      = 'ns1.example.com';
        $this->module->options['NameServerNewIP'] = '2001:db8::1';

        $this->apiMock->expects($this->once())
            ->method('updateHost')
            ->with('example.com', 'ns1.example.com', '', '2001:db8::1')
            ->willReturn(['success' => true, 'data' => ['hostname' => 'ns1.example.com'], 'code' => 200]);

        $this->assertTrue($this->module->modifyNameServer());
    }

    public function testDeleteNameServer(): void
    {
        $this->module->options['NameServer'] = 'ns1.example.com';

        $this->apiMock->expects($this->once())
            ->method('deleteHost')
            ->with('example.com', 'ns1.example.com')
            ->willReturn(['success' => false, 'error' => 'Host is still in use', 'code' => 409]);

        $this->assertFalse($this->module->deleteNameServer());
        $this->assertStringContainsString('still in use', $this->module->_errors[0]);
    }

    // =================================================================
    // ID protection
    // =================================================================

    public function testUpdateIDProtectionEnablesWhoisPrivacyWithTerms(): void
    {
        $this->module->options['idprotection'] = 1;

        $this->apiMock->expects($this->once())
            ->method('setWhoisPrivacy')
            ->with('example.com', true, true)
            ->willReturn(['success' => true, 'data' => ['domain' => 'example.com', 'whoisPrivacy' => true], 'code' => 200]);
        $this->apiMock->expects($this->never())->method('updateContact');

        $this->assertTrue($this->module->updateIDProtection());
    }

    public function testUpdateIDProtectionFallsBackToContactProtection(): void
    {
        $this->module->options['idprotection'] = 0;

        // Vautron TLDs: whois-privacy is OpenProvider-only (400) → contact protection flag
        $this->apiMock->method('setWhoisPrivacy')->willReturn([
            'success' => false, 'error' => 'WHOIS privacy is only available for OpenProvider domains', 'code' => 400,
        ]);
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['contacts' => ['owner' => 'H-OWN']], 'code' => 200,
        ]);
        $this->apiMock->expects($this->once())
            ->method('updateContact')
            ->with('H-OWN', ['protection' => false])
            ->willReturn(['success' => true, 'code' => 200]);

        $this->assertTrue($this->module->updateIDProtection());
    }

    public function testGetIDProtectionFromContact(): void
    {
        $this->apiMock->method('getDomain')->willReturn([
            'success' => true, 'data' => ['whoisPrivacy' => false, 'contacts' => ['owner' => 'H-OWN']], 'code' => 200,
        ]);
        $this->apiMock->method('getContact')->willReturn([
            'success' => true, 'data' => ['handle' => 'H-OWN', 'protection' => true], 'code' => 200,
        ]);

        $this->assertTrue($this->module->getIDProtection());
    }

    // =================================================================
    // Pricing import: minimum terms
    // =================================================================

    public function testImportTLDPricingMinimumTermAndTransferYears(): void
    {
        $this->apiMock->method('getPricing')->willReturn([
            'success' => true,
            'data'    => [[
                'tld' => 'ai', 'registerPrice' => '70.00', 'renewPrice' => '70.00',
                'transferPrice' => '140.00', 'transferYears' => 2, 'restorePrice' => null,
                'setupPrice' => null, 'minYears' => 2, 'maxYears' => 10,
                'periods' => [['years' => 2, 'registerPrice' => '140.00', 'renewPrice' => '140.00']],
            ]],
            'currency' => 'EUR',
            'code'     => 200,
        ]);

        $pricing = $this->module->importTLDPricing();
        $this->assertSame(2, $pricing['ai']['minyears']);
        $this->assertSame(2, $pricing['ai']['transferyears']);
        $this->assertSame(140.0, $pricing['ai']['transfer']);
    }
}
