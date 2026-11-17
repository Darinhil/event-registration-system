<?php

namespace App\Http\Controllers;

use App\Models\ProcurementDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcurementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => ProcurementDocument::query()->where('user_id', $request->user()->id)->latest('updated_at')->limit(200)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateDocument($request);
        $document = ProcurementDocument::create(['user_id' => $request->user()->id, ...$data, 'status' => 'draft']);

        return response()->json(['data' => $document], 201);
    }

    public function update(Request $request, int $document): JsonResponse
    {
        $item = ProcurementDocument::query()->where('user_id', $request->user()->id)->findOrFail($document);
        abort_if($item->status !== 'draft', 422, 'Only draft procurement documents can be edited.');
        $item->update($this->validateDocument($request));

        return response()->json(['data' => $item->fresh()]);
    }

    public function transition(Request $request, int $document): JsonResponse
    {
        $item = ProcurementDocument::query()->where('user_id', $request->user()->id)->findOrFail($document);
        $allowed = match ($item->type) {
            'purchase_order' => $item->status === 'draft' && $request->input('status') === 'issued',
            'goods_received_note' => $item->status === 'draft' && $request->input('status') === 'received',
            default => $item->status === 'draft' && $request->input('status') === 'issued',
        };
        abort_unless($allowed, 422, 'This status change is not available for this document.');
        $item->update(['status' => $request->input('status')]);

        return response()->json(['data' => $item->fresh()]);
    }

    private function validateDocument(Request $request): array
    {
        $types = ['purchase_order', 'goods_received_note', 'inventory_record', 'stationary_record', 'disposal_record', 'asset_custodian', 'asset_transfer'];
        $type = (string) $request->input('type');
        $procurement = in_array($type, ['purchase_order', 'goods_received_note'], true);

        $rules = [
            'type' => ['required', Rule::in($types)],
            'document_number' => [
                'required', 'string', 'max:80',
                Rule::unique('procurement_documents', 'document_number')
                    ->where('user_id', $request->user()->id)
                    ->where('type', $type)
                    ->ignore($request->route('document')),
            ],
            'document_date' => ['nullable', 'date'],
            'payload' => ['required', 'array'],
            'payload.supplier' => ['nullable', 'string', 'max:255'],
            'payload.supplier_representative' => ['nullable', 'string', 'max:255'],
            'payload.supplier_position' => ['nullable', 'string', 'max:255'],
            'payload.purchase_order_id' => [$type === 'goods_received_note' ? 'required' : 'nullable', 'integer'],
            'payload.reference' => ['nullable', 'string', 'max:100'],
            'payload.notes' => ['nullable', 'string', 'max:5000'],
            'payload.remarks' => ['nullable', 'string', 'max:5000'],
            'payload.tax' => ['nullable', 'numeric', 'min:0'],
            'payload.comments' => ['nullable', 'string', 'max:2000'],
            'payload.organization_name' => ['nullable', 'string', 'max:255'],
            'payload.kind' => ['nullable', 'string', 'max:50'],
            'payload.period' => ['nullable', 'string', 'max:255'],
            'payload.office_location' => ['nullable', 'string', 'max:255'],
            'payload.category' => ['nullable', 'string', 'max:255'],
            'payload.location' => ['nullable', 'string', 'max:255'],
            'payload.department' => ['nullable', 'string', 'max:255'],
            'payload.condition' => ['nullable', 'string', 'max:255'],
            'payload.custodian' => ['nullable', 'string', 'max:255'],
            'payload.custodian_position' => ['nullable', 'string', 'max:255'],
            'payload.asset_code' => ['nullable', 'string', 'max:100'],
            'payload.asset_name' => ['nullable', 'string', 'max:255'],
            'payload.from_location' => ['nullable', 'string', 'max:255'],
            'payload.to_location' => ['nullable', 'string', 'max:255'],
            'payload.from_custodian' => ['nullable', 'string', 'max:255'],
            'payload.to_custodian' => ['nullable', 'string', 'max:255'],
            'payload.reason' => ['nullable', 'string', 'max:2000'],
            'payload.method' => ['nullable', 'string', 'max:255'],
            'payload.methods' => ['nullable', 'array', 'max:3'],
            'payload.methods.*' => ['nullable', 'string', 'max:50'],
            'payload.approved_by' => ['nullable', 'string', 'max:255'],
            'payload.approved_by_position' => ['nullable', 'string', 'max:255'],
            'payload.approved_by_date' => ['nullable', 'date'],
            'payload.prepared_by' => ['nullable', 'string', 'max:255'],
            'payload.prepared_by_position' => ['nullable', 'string', 'max:255'],
            'payload.prepared_by_date' => ['nullable', 'date'],
            'payload.employee_name' => ['nullable', 'string', 'max:255'],
            'payload.employee_code' => ['nullable', 'string', 'max:100'],
            'payload.custodian_by' => ['nullable', 'string', 'max:255'],
            'payload.office' => ['nullable', 'string', 'max:255'],
            'payload.purpose' => ['nullable', 'string', 'max:2000'],
            'payload.acknowledged_by' => ['nullable', 'string', 'max:255'],
            'payload.acknowledged_position' => ['nullable', 'string', 'max:255'],
            'payload.acknowledged_date' => ['nullable', 'date'],
            'payload.received_by_name' => ['nullable', 'string', 'max:255'],
            'payload.received_by_position' => ['nullable', 'string', 'max:255'],
            'payload.received_by_date' => ['nullable', 'date'],
            'payload.checked_by_name' => ['nullable', 'string', 'max:255'],
            'payload.checked_by_position' => ['nullable', 'string', 'max:255'],
            'payload.checked_by_date' => ['nullable', 'date'],
            'payload.issued_to' => ['nullable', 'array'],
            'payload.issued_to.name' => ['nullable', 'string', 'max:255'],
            'payload.issued_to.address' => ['nullable', 'string', 'max:500'],
            'payload.issued_to.telephone' => ['nullable', 'string', 'max:50'],
            'payload.issued_to.fax' => ['nullable', 'string', 'max:50'],
            'payload.issued_to.email' => ['nullable', 'email', 'max:255'],
            'payload.authorized_by' => ['nullable', 'array'],
            'payload.authorized_by.name' => ['nullable', 'string', 'max:255'],
            'payload.authorized_by.title' => ['nullable', 'string', 'max:255'],
            'payload.ordered_by' => ['nullable', 'array'],
            'payload.ordered_by.name' => ['nullable', 'string', 'max:255'],
            'payload.ordered_by.date' => ['nullable', 'date'],
            'payload.delivered_by' => ['nullable', 'array'],
            'payload.delivered_by.name' => ['nullable', 'string', 'max:255'],
            'payload.delivered_by.address' => ['nullable', 'string', 'max:500'],
            'payload.delivered_by.telephone' => ['nullable', 'string', 'max:50'],
            'payload.delivered_by.fax' => ['nullable', 'string', 'max:50'],
            'payload.delivered_by.email' => ['nullable', 'email', 'max:255'],
            'payload.received_according_to_po' => ['nullable', Rule::in(['yes', 'no'])],
            'payload.received_according_to_po_comment' => ['nullable', 'string', 'max:2000'],
            'payload.received_in_good_condition' => ['nullable', Rule::in(['yes', 'no'])],
            'payload.received_in_good_condition_comment' => ['nullable', 'string', 'max:2000'],
            'payload.checked_by' => ['nullable', 'array'],
            'payload.checked_by.name' => ['nullable', 'string', 'max:255'],
            'payload.checked_by.date' => ['nullable', 'date'],
            'payload.received_by' => ['nullable', 'array'],
            'payload.received_by.name' => ['nullable', 'string', 'max:255'],
            'payload.received_by.date' => ['nullable', 'date'],
            'payload.items' => [$procurement ? 'required' : 'nullable', 'array', 'min:1', 'max:100'],
            'payload.items.*.description' => ['nullable', 'string', 'max:500'],
            'payload.items.*.item_name' => ['nullable', 'string', 'max:255'],
            'payload.items.*.asset_code' => ['nullable', 'string', 'max:100'],
            'payload.items.*.category' => ['nullable', 'string', 'max:255'],
            'payload.items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'payload.items.*.unit' => ['nullable', 'string', 'max:50'],
            'payload.items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.supplier' => ['nullable', 'string', 'max:255'],
            'payload.items.*.price' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.funding_percent' => ['nullable', 'string', 'max:255'],
            'payload.items.*.funding_source' => ['nullable', 'string', 'max:255'],
            'payload.items.*.user' => ['nullable', 'string', 'max:255'],
            'payload.items.*.ref_voucher' => ['nullable', 'string', 'max:255'],
            'payload.items.*.received_quantity' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.condition' => ['nullable', 'string', 'max:100'],
            'payload.items.*.location' => ['nullable', 'string', 'max:255'],
            'payload.items.*.custodian' => ['nullable', 'string', 'max:255'],
            'payload.items.*.remarks' => ['nullable', 'string', 'max:1000'],
            'payload.items.*.stock_in' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.stock_out' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.balance' => ['nullable', 'numeric'],
            'payload.items.*.purchase_date' => ['nullable', 'date'],
            'payload.items.*.original_cost' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.disposal_value' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.from_location' => ['nullable', 'string', 'max:255'],
            'payload.items.*.to_location' => ['nullable', 'string', 'max:255'],
            'payload.items.*.from_custodian' => ['nullable', 'string', 'max:255'],
            'payload.items.*.to_custodian' => ['nullable', 'string', 'max:255'],
        ];
        $data = $request->validate($rules);

        if ($procurement) {
            $request->validate(['payload.items.*.description' => ['required', 'string', 'max:500'], 'payload.items.*.quantity' => ['required', 'numeric', 'gt:0']]);
        }

        if ($type === 'goods_received_note') {
            $order = ProcurementDocument::query()->where('user_id', $request->user()->id)->where('type', 'purchase_order')->findOrFail($data['payload']['purchase_order_id']);
            abort_unless($order->status === 'issued', 422, 'Goods can only be received against an issued purchase order.');
            $data['payload']['purchase_order_number'] = $order->document_number;
        }

        return $data;
    }
}
