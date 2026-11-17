<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function orderPayload(): array
    {
        return [
            'type' => 'purchase_order',
            'document_number' => 'PO-1001',
            'document_date' => '2026-11-17',
            'payload' => [
                'supplier' => 'Office World',
                'supplier_representative' => 'Dara Sok',
                'supplier_position' => 'Sales manager',
                'issued_to' => ['name' => 'Live & Learn', 'address' => 'Street 598', 'telephone' => '012345678', 'fax' => '', 'email' => 'office@example.com'],
                'authorized_by' => ['name' => 'Sokha', 'title' => 'Director'],
                'ordered_by' => ['name' => 'Sokha', 'date' => '2026-11-17'],
                'tax' => 5,
                'notes' => 'PO number must appear on all invoices.',
                'items' => [
                    ['description' => 'Paper', 'quantity' => 2, 'unit_cost' => 4.5],
                    ['description' => 'Pens', 'quantity' => 10, 'unit_cost' => 0.5],
                ],
            ],
        ];
    }

    public function test_purchase_orders_keep_the_template_details(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $this->postJson('/api/me/procurement-documents', $this->orderPayload())
            ->assertCreated()
            ->assertJsonPath('data.payload.supplier', 'Office World')
            ->assertJsonPath('data.payload.supplier_representative', 'Dara Sok')
            ->assertJsonPath('data.payload.issued_to.email', 'office@example.com')
            ->assertJsonPath('data.payload.authorized_by.title', 'Director')
            ->assertJsonPath('data.payload.ordered_by.name', 'Sokha')
            ->assertJsonPath('data.payload.items.1.description', 'Pens')
            ->assertJsonPath('data.payload.items.0.unit_cost', 4.5)
            ->assertJsonPath('data.payload.tax', 5);

        $this->getJson('/api/me/procurement-documents')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_received_notes_need_an_issued_order_and_copy_its_number(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));
        $orderId = $this->postJson('/api/me/procurement-documents', $this->orderPayload())->assertCreated()->json('data.id');

        $receipt = [
            'type' => 'goods_received_note',
            'document_number' => 'GRN-1001',
            'document_date' => '2026-11-18',
            'payload' => [
                'purchase_order_id' => $orderId,
                'purchase_order_number' => 'FAKE',
                'comments' => 'Delivered in one trip.',
                'delivered_by' => ['name' => 'Dara Sok', 'address' => 'Street 598', 'telephone' => '098765432', 'fax' => '', 'email' => 'dara@example.com'],
                'received_according_to_po' => 'yes',
                'received_according_to_po_comment' => '',
                'received_in_good_condition' => 'no',
                'received_in_good_condition_comment' => 'One box was damaged.',
                'checked_by' => ['name' => 'Sokha', 'date' => '2026-11-18'],
                'received_by' => ['name' => 'Sokha', 'date' => '2026-11-18'],
                'items' => [
                    ['description' => 'Paper', 'quantity' => 2, 'unit_cost' => 4.5, 'received_quantity' => 1],
                    ['description' => 'Pens', 'quantity' => 10, 'unit_cost' => 0.5, 'received_quantity' => 10],
                ],
            ],
        ];

        $this->postJson('/api/me/procurement-documents', $receipt)->assertStatus(422);

        $this->patchJson("/api/me/procurement-documents/{$orderId}/status", ['status' => 'issued'])->assertOk();

        $this->postJson('/api/me/procurement-documents', $receipt)
            ->assertCreated()
            ->assertJsonPath('data.payload.purchase_order_number', 'PO-1001')
            ->assertJsonPath('data.payload.received_according_to_po', 'yes')
            ->assertJsonPath('data.payload.received_in_good_condition_comment', 'One box was damaged.')
            ->assertJsonPath('data.payload.items.0.received_quantity', 1)
            ->assertJsonPath('data.payload.checked_by.name', 'Sokha');
    }

    public function test_only_the_owner_can_see_and_change_drafts(): void
    {
        $owner = User::factory()->create(['role' => 'officer_1']);
        Sanctum::actingAs($owner);
        $orderId = $this->postJson('/api/me/procurement-documents', $this->orderPayload())->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'officer_2']));
        $this->getJson('/api/me/procurement-documents')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/me/procurement-documents/{$orderId}/status", ['status' => 'issued'])->assertNotFound();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/me/procurement-documents/{$orderId}/status", ['status' => 'issued'])->assertOk();
        $this->putJson("/api/me/procurement-documents/{$orderId}", $this->orderPayload())->assertStatus(422);
    }
}
