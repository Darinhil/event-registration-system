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
        $next = ['draft' => 'issued', 'issued' => 'received'];
        abort_unless(($next[$item->status] ?? null) === $request->input('status'), 422, 'This status change is not available.');
        abort_unless(($item->type === 'purchase_order' && $item->status === 'draft' && $request->input('status') === 'issued') || ($item->type === 'goods_received_note' && $item->status === 'draft' && $request->input('status') === 'received'), 422, 'This status change is not available for this document.');
        $item->update(['status' => $request->input('status')]);

        return response()->json(['data' => $item->fresh()]);
    }

    private function validateDocument(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['purchase_order', 'goods_received_note'])],
            'document_number' => [
                'required', 'string', 'max:80',
                Rule::unique('procurement_documents', 'document_number')
                    ->where('user_id', $request->user()->id)
                    ->where('type', $request->input('type'))
                    ->ignore($request->route('document')),
            ],
            'document_date' => ['nullable', 'date'],
            'payload' => ['required', 'array'],
            'payload.supplier' => ['nullable', 'string', 'max:255'],
            'payload.purchase_order_id' => [$request->input('type') === 'goods_received_note' ? 'required' : 'nullable', 'integer'],
            'payload.reference' => ['nullable', 'string', 'max:100'],
            'payload.notes' => ['nullable', 'string', 'max:5000'],
            'payload.items' => ['required', 'array', 'min:1', 'max:100'],
            'payload.items.*.description' => ['required', 'string', 'max:500'],
            'payload.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'payload.items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.received_quantity' => ['nullable', 'numeric', 'min:0'],
            'payload.items.*.condition' => ['nullable', 'string', 'max:100'],
        ]);
        if ($data['type'] === 'goods_received_note') {
            $order = ProcurementDocument::query()->where('user_id', $request->user()->id)->where('type', 'purchase_order')->findOrFail($data['payload']['purchase_order_id']);
            abort_unless($order->status === 'issued', 422, 'Goods can only be received against an issued purchase order.');
        }

        return $data;
    }
}
