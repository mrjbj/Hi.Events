<?php

namespace Tests\Unit\Services\Domain\Contact;

use HiEvents\Services\Domain\Contact\ContactAttributeOptionsService;
use Tests\TestCase;

class ContactAttributeOptionsServiceTest extends TestCase
{
    public function test_scalar_rename(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            'Mexico',
            [['from' => 'Mexico', 'action' => 'rename', 'to' => 'Other']],
        );
        $this->assertSame('Other', $value);
        $this->assertTrue($changed);
    }

    public function test_scalar_delete_returns_null(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            'Mars',
            [['from' => 'Mars', 'action' => 'delete']],
        );
        $this->assertNull($value);
        $this->assertTrue($changed);
    }

    public function test_scalar_unchanged_when_no_match(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            'US',
            [['from' => 'Mexico', 'action' => 'delete']],
        );
        $this->assertSame('US', $value);
        $this->assertFalse($changed);
    }

    public function test_array_rename_replaces_in_place(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['US', 'Mexico', 'CA'],
            [['from' => 'Mexico', 'action' => 'rename', 'to' => 'MX']],
        );
        $this->assertSame(['US', 'MX', 'CA'], $value);
        $this->assertTrue($changed);
    }

    public function test_array_delete_removes_item(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['US', 'Mexico', 'CA'],
            [['from' => 'Mexico', 'action' => 'delete']],
        );
        $this->assertSame(['US', 'CA'], $value);
        $this->assertTrue($changed);
    }

    public function test_array_rename_to_existing_value_dedupes(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['US', 'Mexico', 'CA'],
            [['from' => 'Mexico', 'action' => 'rename', 'to' => 'US']],
        );
        $this->assertSame(['US', 'CA'], $value);
        $this->assertTrue($changed);
    }

    public function test_array_delete_all_results_in_empty_array(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['Mars', 'Pluto'],
            [
                ['from' => 'Mars', 'action' => 'delete'],
                ['from' => 'Pluto', 'action' => 'delete'],
            ],
        );
        $this->assertSame([], $value);
        $this->assertTrue($changed);
    }

    public function test_multiple_migrations_applied_in_order(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['Mexico', 'Mars'],
            [
                ['from' => 'Mexico', 'action' => 'rename', 'to' => 'MX'],
                ['from' => 'Mars', 'action' => 'delete'],
            ],
        );
        $this->assertSame(['MX'], $value);
        $this->assertTrue($changed);
    }

    public function test_array_with_no_matches_unchanged(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['US', 'CA'],
            [['from' => 'Mexico', 'action' => 'delete']],
        );
        $this->assertSame(['US', 'CA'], $value);
        $this->assertFalse($changed);
    }

    public function test_null_value_unchanged(): void
    {
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            null,
            [['from' => 'Mexico', 'action' => 'delete']],
        );
        $this->assertNull($value);
        $this->assertFalse($changed);
    }

    public function test_non_string_array_items_preserved(): void
    {
        // Although storage shouldn't contain non-strings, the helper shouldn't crash on them.
        [$value, $changed] = ContactAttributeOptionsService::applyMigrationsToValue(
            ['US', 42, 'Mexico'],
            [['from' => 'Mexico', 'action' => 'delete']],
        );
        $this->assertSame(['US', 42], $value);
        $this->assertTrue($changed);
    }
}
