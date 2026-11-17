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

    public function test_each_asset_record_type_is_stored_and_marked_recorded(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));
        $records = [
            'inventory_record' => ['category' => 'IT equipment', 'items' => [['asset_code' => 'LL-001', 'item_name' => 'Laptop', 'quantity' => 2, 'condition' => 'Good']]],
            'stationary_record' => ['kind' => 'transfer', 'to_location' => 'Field office in SRP', 'items' => [['item_name' => 'Drone-DJI Air Fly More', 'unit' => '1 Set', 'remarks' => 'New']]],
            'disposal_record' => ['method' => 'Scrapped', 'methods' => ['Scrapped'], 'reason' => 'Broken beyond repair', 'items' => [['asset_code' => 'LL-002', 'location' => 'Head office', 'description' => 'Chair', 'purchase_date' => '2020-03-01', 'original_cost' => 120, 'disposal_value' => 15]]],
            'asset_custodian' => ['employee_name' => 'Sorn Bunthoeurn', 'employee_code' => 'LLC-012', 'department' => 'ESCM Project', 'office' => 'Siem Reap province', 'custodian_by' => 'Roeung Bunna', 'items' => [['asset_code' => 'CAR00014', 'item_name' => 'Motorbike Honda Dream 110CC', 'quantity' => 1, 'remarks' => 'Working']]],
            'asset_transfer' => ['from_location' => 'Head office', 'to_location' => 'Kampot', 'items' => [['asset_code' => 'LL-004', 'description' => 'Printer']]],
        ];

        foreach ($records as $type => $payload) {
            $id = $this->postJson('/api/me/procurement-documents', ['type' => $type, 'document_number' => strtoupper($type), 'document_date' => '2026-11-17', 'payload' => $payload])
                ->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.status', 'draft')
                ->json('data.id');

            $this->patchJson("/api/me/procurement-documents/{$id}/status", ['status' => 'issued'])
                ->assertOk()
                ->assertJsonPath('data.status', 'issued');
        }

        $this->getJson('/api/me/procurement-documents')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_disposal_record_keeps_the_asset_disposal_template_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $this->postJson('/api/me/procurement-documents', [
            'type' => 'disposal_record',
            'document_number' => 'DSP-0001',
            'document_date' => '2026-11-17',
            'payload' => [
                'method' => 'Scrapped, Sold',
                'methods' => ['Scrapped', 'Sold'],
                'reason' => 'It no longer functions and repairs are not cost effective.',
                'prepared_by' => 'Sokha',
                'prepared_by_position' => 'Logistics Officer',
                'prepared_by_date' => '2026-11-17',
                'checked_by_name' => 'Dara',
                'checked_by_position' => 'Admin Officer',
                'checked_by_date' => '2026-11-18',
                'approved_by' => 'Vibol',
                'approved_by_position' => 'Operations Manager',
                'approved_by_date' => '2026-11-19',
                'items' => [[
                    'asset_code' => 'LL-002',
                    'location' => 'Head office',
                    'description' => 'Chair',
                    'purchase_date' => '2020-03-01',
                    'original_cost' => 120,
                    'disposal_value' => 15,
                ]],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.payload.methods.0', 'Scrapped')
            ->assertJsonPath('data.payload.methods.1', 'Sold')
            ->assertJsonPath('data.payload.reason', 'It no longer functions and repairs are not cost effective.')
            ->assertJsonPath('data.payload.prepared_by_position', 'Logistics Officer')
            ->assertJsonPath('data.payload.prepared_by_date', '2026-11-17')
            ->assertJsonPath('data.payload.checked_by_name', 'Dara')
            ->assertJsonPath('data.payload.checked_by_position', 'Admin Officer')
            ->assertJsonPath('data.payload.approved_by_date', '2026-11-19')
            ->assertJsonPath('data.payload.items.0.asset_code', 'LL-002')
            ->assertJsonPath('data.payload.items.0.location', 'Head office')
            ->assertJsonPath('data.payload.items.0.purchase_date', '2020-03-01')
            ->assertJsonPath('data.payload.items.0.original_cost', 120)
            ->assertJsonPath('data.payload.items.0.disposal_value', 15);
    }

    public function test_stationery_transfer_record_keeps_the_transfer_template_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $response = $this->postJson('/api/me/procurement-documents', [
            'type' => 'stationary_record',
            'document_number' => 'STN-TRANSFER-01',
            'document_date' => '2026-03-10',
            'payload' => [
                'kind' => 'transfer',
                'to_location' => 'field office in SRP',
                'purpose' => 'All stationery is transferred to the field office for the purpose of the project and office use. After receiving the stationery, the Junior Project Coordinator should check, verify, and count it, then update the LLC stationery list.',
                'prepared_by' => 'Roeung Bunna',
                'prepared_by_position' => 'Admin and Logistic Assistant',
                'prepared_by_date' => '2026-03-10',
                'received_by_name' => 'Chum Seedeth',
                'received_by_position' => 'Junior Project Coordinator',
                'received_by_date' => '2026-03-10',
                'items' => [
                    ['item_name' => 'Drone-DJI Air Fly More, S/N DJI 3S ( $ 1386.5 )', 'unit' => '1 Set', 'remarks' => 'New'],
                ],
            ],
        ])->assertCreated();

        $payload = $response->json('data.payload');
        $this->assertSame('transfer', $payload['kind']);
        $this->assertSame('field office in SRP', $payload['to_location']);
        $this->assertStringContainsString('update the LLC stationery list', $payload['purpose']);
        $this->assertSame('Roeung Bunna', $payload['prepared_by']);
        $this->assertSame('Chum Seedeth', $payload['received_by_name']);
        $this->assertSame('1 Set', $payload['items'][0]['unit']);
    }

    public function test_asset_custodian_record_keeps_the_custodian_template_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $response = $this->postJson('/api/me/procurement-documents', [
            'type' => 'asset_custodian',
            'document_number' => '0001-SRP',
            'document_date' => '2026-08-18',
            'payload' => [
                'employee_name' => 'Sorn Bunthoeurn',
                'employee_code' => 'LLC-012',
                'department' => 'ESCM Project',
                'office' => 'Siem Reap province',
                'custodian_by' => 'Roeung Bunna',
                'purpose' => 'All asset shall be used only for LLC business and with regard to;',
                'prepared_by' => 'Roeung Bunna',
                'prepared_by_position' => 'Logistics Officer',
                'prepared_by_date' => '2026-08-18',
                'checked_by_name' => 'Dara',
                'checked_by_position' => 'Admin Officer',
                'checked_by_date' => '2026-08-18',
                'approved_by' => 'Vibol',
                'approved_by_position' => 'Operations Manager',
                'approved_by_date' => '2026-08-18',
                'acknowledged_by' => 'Sorn Bunthoeurn',
                'acknowledged_position' => 'Project Officer',
                'acknowledged_date' => '2026-08-18',
                'items' => [
                    ['asset_code' => 'CAR00014', 'item_name' => 'Motorbike Honda Dream 110CC Black', 'quantity' => 1, 'remarks' => 'Working'],
                    ['asset_code' => 'LAP00036', 'item_name' => 'Lenovo ThinkPad L14 G6', 'quantity' => 1, 'remarks' => 'New'],
                ],
            ],
        ])->assertCreated();

        $payload = $response->json('data.payload');
        $this->assertSame('Sorn Bunthoeurn', $payload['employee_name']);
        $this->assertSame('LLC-012', $payload['employee_code']);
        $this->assertSame('ESCM Project', $payload['department']);
        $this->assertSame('Siem Reap province', $payload['office']);
        $this->assertSame('Roeung Bunna', $payload['custodian_by']);
        $this->assertStringContainsString('LLC business', $payload['purpose']);
        $this->assertSame('Logistics Officer', $payload['prepared_by_position']);
        $this->assertSame('Dara', $payload['checked_by_name']);
        $this->assertSame('Vibol', $payload['approved_by']);
        $this->assertSame('Sorn Bunthoeurn', $payload['acknowledged_by']);
        $this->assertSame('2026-08-18', $payload['acknowledged_date']);
        $this->assertSame('CAR00014', $payload['items'][0]['asset_code']);
        $this->assertSame('Motorbike Honda Dream 110CC Black', $payload['items'][0]['item_name']);
        $this->assertSame(1, $payload['items'][0]['quantity']);
        $this->assertSame('New', $payload['items'][1]['remarks']);
    }

    public function test_stationery_detailed_list_keeps_the_template_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $response = $this->postJson('/api/me/procurement-documents', [
            'type' => 'stationary_record',
            'document_number' => 'STN-LIST-01',
            'document_date' => '2026-06-30',
            'payload' => [
                'kind' => 'detailed_list',
                'organization_name' => 'Live & Learn Cambodia (LLC)',
                'period' => 'Jan to June 2026',
                'office_location' => 'Phnom Penh',
                'prepared_by' => 'Roeung Bunna',
                'prepared_by_position' => 'Admin & Logistic Assistant',
                'checked_by_name' => 'Heng Sokkunthea',
                'checked_by_position' => 'Operation Manager',
                'approved_by' => 'Sou Socheath',
                'approved_by_position' => 'Executive Director',
                'items' => [[
                    'asset_code' => 'F&S-00001',
                    'description' => 'Office Chair DM8227 (Black)',
                    'purchase_date' => '2023-07-19',
                    'supplier' => 'KST Office Supply',
                    'price' => 48,
                    'funding_percent' => '100%',
                    'funding_source' => 'KHM 0106, VOICE, LLC',
                    'location' => 'PNH',
                    'user' => 'ALA',
                    'ref_voucher' => '2307037-PNH',
                    'condition' => 'Working',
                ]],
            ],
        ])->assertCreated();

        $payload = $response->json('data.payload');
        $this->assertSame('detailed_list', $payload['kind']);
        $this->assertSame('Jan to June 2026', $payload['period']);
        $this->assertSame('F&S-00001', $payload['items'][0]['asset_code']);
        $this->assertSame('KST Office Supply', $payload['items'][0]['supplier']);
        $this->assertSame('Heng Sokkunthea', $payload['checked_by_name']);
        $this->assertSame('Sou Socheath', $payload['approved_by']);
    }

    public function test_stationery_summary_keeps_the_template_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $response = $this->postJson('/api/me/procurement-documents', [
            'type' => 'stationary_record',
            'document_number' => 'STN-SUM-01',
            'document_date' => '2024-03-31',
            'payload' => [
                'kind' => 'summary',
                'organization_name' => 'Live & Learn Cambodia (LLC)',
                'period' => 'January to March 2024',
                'office_location' => 'Phnom Penh',
                'prepared_by' => 'Roeung Bunna',
                'prepared_by_date' => '2024-03-31',
                'approved_by' => 'Heng Sokkunthea',
                'approved_by_date' => '2024-03-31',
                'items' => [
                    ['item_name' => 'Chair', 'quantity' => 7, 'remarks' => ''],
                    ['item_name' => 'Fan', 'quantity' => 3, 'remarks' => ''],
                ],
            ],
        ])->assertCreated();

        $payload = $response->json('data.payload');
        $this->assertSame('summary', $payload['kind']);
        $this->assertSame('January to March 2024', $payload['period']);
        $this->assertSame('Phnom Penh', $payload['office_location']);
        $this->assertSame('Chair', $payload['items'][0]['item_name']);
        $this->assertSame(7, $payload['items'][0]['quantity']);
        $this->assertSame('Roeung Bunna', $payload['prepared_by']);
        $this->assertSame('Heng Sokkunthea', $payload['approved_by']);
    }

    public function test_inventory_record_keeps_the_inventory_template_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));

        $response = $this->postJson('/api/me/procurement-documents', [
            'type' => 'inventory_record',
            'document_number' => 'INV-2026-H1',
            'document_date' => '2026-06-30',
            'payload' => [
                'organization_name' => 'Live & Learn Cambodia (LLC)',
                'period' => 'Jan to June 2026',
                'office_location' => 'Phnom Penh',
                'prepared_by' => 'Roeung Bunna',
                'prepared_by_position' => 'Admin & Logistic Assistant',
                'prepared_by_date' => '2026-06-30',
                'checked_by_name' => 'Korm Navy',
                'checked_by_position' => 'Fiannce and Procurement Officer',
                'checked_by_date' => '2026-06-30',
                'approved_by' => 'Heng Sokkunthea',
                'approved_by_position' => 'Operation Manager',
                'approved_by_date' => '2026-06-30',
                'items' => [
                    [
                        'category' => 'Laptop',
                        'asset_code' => 'LAP00001',
                        'description' => 'Laptop Macbook Pro 2019  ( Blank )',
                        'purchase_date' => '2020-02-21',
                        'supplier' => 'PTC Company',
                        'price' => 1837,
                        'funding_percent' => "30%\n31%\n27%\n12%",
                        'funding_source' => "JUH\nFS\nTCF\nLLC",
                        'location' => 'PNH',
                        'user' => 'Sou Socheath',
                        'ref_voucher' => '2002035-PNH',
                        'condition' => 'Working',
                        'remarks' => '',
                    ],
                ],
            ],
        ])->assertCreated();

        $payload = $response->json('data.payload');
        $this->assertSame('Live & Learn Cambodia (LLC)', $payload['organization_name']);
        $this->assertSame('Jan to June 2026', $payload['period']);
        $this->assertSame('Phnom Penh', $payload['office_location']);
        $this->assertSame('Roeung Bunna', $payload['prepared_by']);
        $this->assertSame('Korm Navy', $payload['checked_by_name']);
        $this->assertSame('Heng Sokkunthea', $payload['approved_by']);
        $this->assertSame('LAP00001', $payload['items'][0]['asset_code']);
        $this->assertSame('Laptop', $payload['items'][0]['category']);
        $this->assertSame('PTC Company', $payload['items'][0]['supplier']);
        $this->assertSame(1837, $payload['items'][0]['price']);
        $this->assertStringContainsString('30%', $payload['items'][0]['funding_percent']);
        $this->assertStringContainsString('JUH', $payload['items'][0]['funding_source']);
        $this->assertSame('PNH', $payload['items'][0]['location']);
        $this->assertSame('Sou Socheath', $payload['items'][0]['user']);
        $this->assertSame('2002035-PNH', $payload['items'][0]['ref_voucher']);
        $this->assertSame('Working', $payload['items'][0]['condition']);
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
