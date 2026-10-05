<?php

namespace Tests\Feature;

use App\Domain\Entitlements\EntitlementService;
use App\Domain\Outbox\OutboxWriter;
use App\Models\Order;
use RuntimeException;
use Tests\TestCase;

/**
 * Deliberately NOT using RefreshDatabase: that trait wraps every test in a
 * transaction, which would defeat the very invariant under test. Both
 * assertions fire before any database access.
 */
class TransactionBoundaryTest extends TestCase
{
    public function test_outbox_writer_throws_outside_a_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside a database transaction');

        (new OutboxWriter)->write('order', '1', 'order.paid', [], 'order:1:paid');
    }

    public function test_entitlement_grant_throws_outside_a_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(EntitlementService::class)->grantForOrder(new Order);
    }
}
