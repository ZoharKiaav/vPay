<?php

namespace Tests\Unit\Extensions\Others;

use ArrayIterator;
use InvalidArgumentException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\CustomerPropertyExtractor;
use PHPUnit\Framework\TestCase;
use stdClass;

class CustomerPropertyExtractorTest extends TestCase
{
    public function test_it_extracts_domain_contact_properties(): void
    {
        $properties = new ArrayIterator([
            $this->property('phone', ' +27.100000000 '),
            $this->property('company_name', ' Example Test '),
            $this->property('country', ' ZA '),
            $this->property('address', ' 1 Example Road '),
            $this->property('address2', ''),
            $this->property('city', ' Johannesburg '),
            $this->property('state', ' Gauteng '),
            $this->property('zip', ' 2000 '),
        ]);

        $result = $this->extractor()->extract($properties);

        $this->assertSame([
            'phone' => '+27.100000000',
            'company_name' => 'Example Test',
            'country' => 'ZA',
            'address' => '1 Example Road',
            'address2' => '',
            'city' => 'Johannesburg',
            'state' => 'Gauteng',
            'zip' => '2000',
        ], $result);
    }

    public function test_it_ignores_unrelated_properties(): void
    {
        $result = $this->extractor()->extract([
            $this->property('phone', '+27.100000000'),
            $this->property('favourite_colour', 'Blue'),
            $this->property('internal_reference', 'TEST-1'),
        ]);

        $this->assertSame([
            'phone' => '+27.100000000',
        ], $result);
    }

    public function test_it_ignores_properties_without_a_definition(): void
    {
        $property = new stdClass;
        $property->value = 'Ignored';
        $property->parent_property = null;

        $result = $this->extractor()->extract([$property]);

        $this->assertSame([], $result);
    }

    public function test_it_preserves_null_optional_values_as_empty_strings(): void
    {
        $result = $this->extractor()->extract([
            $this->property('address2', null),
            $this->property('tax_id', null),
        ]);

        $this->assertSame([
            'address2' => '',
            'tax_id' => '',
        ], $result);
    }

    public function test_it_rejects_duplicate_property_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Duplicate customer property detected: phone.',
        );

        $this->extractor()->extract([
            $this->property('phone', '+27.100000000'),
            $this->property('phone', '+27.200000000'),
        ]);
    }

    public function test_it_rejects_non_scalar_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Customer property phone must contain a scalar value.',
        );

        $this->extractor()->extract([
            $this->property('phone', ['not', 'scalar']),
        ]);
    }

    private function property(
        string $key,
        mixed $value,
    ): stdClass {
        $definition = new stdClass;
        $definition->key = $key;

        $property = new stdClass;
        $property->value = $value;
        $property->parent_property = $definition;

        return $property;
    }

    private function extractor(): CustomerPropertyExtractor
    {
        return new CustomerPropertyExtractor;
    }
}
