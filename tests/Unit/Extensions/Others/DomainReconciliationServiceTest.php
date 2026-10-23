<?php

namespace Tests\Unit\Extensions\Others;

use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainReconciliationService;
use PHPUnit\Framework\TestCase;

class DomainReconciliationServiceTest extends TestCase
{
    public function test_it_normalises_safe_domain_information(): void
    {
        $result = $this->service()->normalise([
            'domain' => ' Example.Co.Za. ',
            'status' => 'Active',
            'expiryDate' => '2027-10-22T00:00:00+02:00',
            'nameservers' => [
                ' NS1.EXAMPLE.TEST. ',
                'ns2.example.test',
                'ns1.example.test',
                '',
            ],
            'transferLock' => true,
            'restorable' => false,
            'willDomainSuspend' => '0',
            'renewBeforeExpiration' => '1',
            'registrantEmailAddress' => 'private@example.test',
            'unknownProviderField' => 'discarded',
        ]);

        $this->assertSame('example.co.za', $result->domain);
        $this->assertSame('Active', $result->status);
        $this->assertSame('2027-10-22', $result->expiryDate);
        $this->assertSame([
            'ns1.example.test',
            'ns2.example.test',
        ], $result->nameservers);
        $this->assertTrue($result->transferLocked);
        $this->assertFalse($result->restorable);
        $this->assertFalse($result->pendingSuspension);
        $this->assertTrue($result->renewBeforeExpiration);

        $normalised = $result->toArray();

        $this->assertSame([
            'domain',
            'status',
            'expiry_date',
            'nameservers',
            'transfer_locked',
            'restorable',
            'pending_suspension',
            'renew_before_expiration',
        ], array_keys($normalised));

        $this->assertArrayNotHasKey(
            'registrantEmailAddress',
            $normalised,
        );
        $this->assertArrayNotHasKey(
            'unknownProviderField',
            $normalised,
        );
    }

    public function test_optional_operational_flags_may_be_absent(): void
    {
        $result = $this->service()->normalise([
            'domain' => 'example.co.za',
            'status' => 'Active',
            'expiryDate' => '2027-10-22',
            'nameservers' => [
                'ns1.example.test',
                'ns2.example.test',
            ],
        ]);

        $this->assertNull($result->transferLocked);
        $this->assertNull($result->restorable);
        $this->assertNull($result->pendingSuspension);
        $this->assertNull($result->renewBeforeExpiration);
    }

    public function test_it_rejects_a_non_array_response(): void
    {
        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting returned invalid domain information.',
        );

        $this->service()->normalise('invalid response');
    }

    public function test_it_rejects_missing_required_fields(): void
    {
        $cases = [
            'domain' => 'Absolute Hosting domain information is missing domain.',
            'status' => 'Absolute Hosting domain information is missing status.',
            'expiryDate' => 'Absolute Hosting domain information is missing expiryDate.',
            'nameservers' => 'Absolute Hosting domain information is missing nameservers.',
        ];

        foreach ($cases as $key => $message) {
            $response = $this->validResponse();
            unset($response[$key]);

            try {
                $this->service()->normalise($response);

                $this->fail(
                    "Expected missing-field failure for {$key}.",
                );
            } catch (AbsoluteHostingException $exception) {
                $this->assertSame(
                    $message,
                    $exception->getMessage(),
                    "Unexpected message for missing {$key}.",
                );
            }
        }
    }

    public function test_it_rejects_an_invalid_domain(): void
    {
        $response = $this->validResponse();
        $response['domain'] = 'not a domain';

        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting returned an invalid domain name.',
        );

        $this->service()->normalise($response);
    }

    public function test_it_rejects_an_invalid_expiry_date(): void
    {
        $response = $this->validResponse();
        $response['expiryDate'] = 'not-a-date';

        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting returned an invalid domain expiry date.',
        );

        $this->service()->normalise($response);
    }

    public function test_it_rejects_invalid_nameservers(): void
    {
        $response = $this->validResponse();
        $response['nameservers'] = [
            'not a nameserver',
        ];

        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting returned an invalid domain nameserver.',
        );

        $this->service()->normalise($response);
    }

    public function test_it_rejects_invalid_boolean_values(): void
    {
        $response = $this->validResponse();
        $response['transferLock'] = 'sometimes';

        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting returned invalid transferLock.',
        );

        $this->service()->normalise($response);
    }

    private function validResponse(): array
    {
        return [
            'domain' => 'example.co.za',
            'status' => 'Active',
            'expiryDate' => '2027-10-22',
            'nameservers' => [
                'ns1.example.test',
                'ns2.example.test',
            ],
            'transferLock' => true,
            'restorable' => false,
            'willDomainSuspend' => false,
            'renewBeforeExpiration' => true,
        ];
    }

    private function service(): DomainReconciliationService
    {
        return new DomainReconciliationService;
    }
}
