<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Family;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\Branch;
use App\Services\Billing;
use App\Services\Hub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Invoices & payments, POS with per-location inventory, and the expense ledger. */
class FinanceController extends Controller
{
    public function invoices(Request $request)
    {
        return Invoice::with('family:id,code,name', 'client:id,first_name,last_name', 'branch:id,code,name')->inBranch()
            ->when($request->status === 'overdue', fn ($q) => $q->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today()))
            ->when($request->status && $request->status !== 'overdue', fn ($q) => $q->where('status', $request->status))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->when($request->family_id, fn ($q, $f) => $q->where('family_id', $f))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")->orWhereHas('family', fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%"))))
            ->latest()->paginate($request->integer('per_page', 25));
    }

    public function invoice(Invoice $invoice)
    {
        return $invoice->load('items', 'payments.creator:id,name', 'family', 'client', 'creator:id,name');
    }

    public function storeInvoice(Request $request)
    {
        $data = $request->validate([
            'family_id' => 'required|exists:families,id', 'discount_percent' => 'nullable|numeric|min:0|max:100',
            'due_in_days' => 'integer|min:0|max:90', 'notes' => 'nullable|string',
            'items' => 'required|array|min:1', 'items.*.description' => 'required|string',
            'items.*.quantity' => 'numeric|min:0.01', 'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        return response()->json(Billing::invoice(Family::find($data['family_id']), $data['items'], 'manual', $data['discount_percent'] ?? 0, $data['due_in_days'] ?? 7, $data['notes'] ?? null)->load('items'), 201);
    }

    public function pay(Request $request, Invoice $invoice)
    {
        $methods = Hub::setting('payments.methods', CatalogController::SETTINGS['payments.methods']);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'method' => 'required|in:'.implode(',', array_unique(array_merge($methods, ['wallet', 'gateway']))), 'reference' => 'nullable|string']);
        Billing::pay($invoice, (float) $data['amount'], $data['method'], $data['reference'] ?? null);

        return $invoice->fresh()->load('items', 'payments');
    }

    public function void(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['reason' => 'required|string']);
        Billing::voidInvoice($invoice, $data['reason']);

        return $invoice->fresh();
    }

    // ---- Products & inventory ----------------------------------------------

    public function products(Request $request)
    {
        $locationId = $request->integer('location_id') ?: Branch::id();

        return Product::with(['inventory' => fn ($q) => $q->when($locationId, fn ($q) => $q->where('location_id', $locationId))->with('location:id,name')])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%")->orWhere('barcode', $s)))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('name')->get()
            ->map(fn ($p) => $p->setAttribute('stock', $p->inventory->sum('quantity')));
    }

    public function lookup(Request $request)
    {
        $request->validate(['code' => 'required|string', 'location_id' => 'required|exists:locations,id']);
        $product = Product::where('active', true)->where(fn ($q) => $q->where('barcode', $request->code)->orWhere('sku', $request->code))->firstOrFail();

        return $product->setAttribute('stock', (int) InventoryItem::where('product_id', $product->id)->where('location_id', $request->location_id)->value('quantity'));
    }

    public function saveProduct(Request $request, ?Product $product = null)
    {
        $data = $request->validate([
            'sku' => 'required|string|unique:products,sku'.($product ? ",{$product->id}" : ''),
            'barcode' => 'nullable|string|unique:products,barcode'.($product ? ",{$product->id}" : ''),
            'name' => 'required|string', 'category' => 'nullable|string', 'price' => 'required|numeric|min:0',
            'cost' => 'numeric|min:0', 'show_online' => 'boolean', 'active' => 'boolean',
        ]);
        $product ? $product->update($data) : $product = Product::create($data);
        foreach (Location::pluck('id') as $loc) {
            InventoryItem::firstOrCreate(['product_id' => $product->id, 'location_id' => $loc], ['quantity' => 0]);
        }

        return $product->load('inventory.location:id,name');
    }

    public function adjustStock(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id', 'location_id' => 'required|exists:locations,id',
            'change' => 'required|integer|not_in:0', 'reason' => 'required|in:restock,adjustment,transfer', 'reference' => 'nullable|string',
            'reorder_level' => 'nullable|integer|min:0',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $item = InventoryItem::firstOrCreate(['product_id' => $data['product_id'], 'location_id' => $data['location_id']], ['quantity' => 0]);
            if ($item->quantity + $data['change'] < 0) {
                throw ValidationException::withMessages(['change' => "Only {$item->quantity} in stock at this location."]);
            }
            $item->increment('quantity', $data['change']);
            if (isset($data['reorder_level'])) {
                $item->update(['reorder_level' => $data['reorder_level']]);
            }
            StockMovement::create(collect($data)->except('reorder_level')->all() + ['created_by' => $request->user()->id]);

            return $item->fresh();
        });
    }

    public function movements(Request $request)
    {
        return StockMovement::with('product:id,name,sku', 'location:id,name', 'creator:id,name')->inBranch()
            ->when($request->location_id, fn ($q, $l) => $q->where('location_id', $l))
            ->latest()->paginate(50);
    }

    // ---- POS ----------------------------------------------------------------

    public function sales(Request $request)
    {
        return Sale::with('items.product:id,name', 'location:id,name', 'family:id,code,name', 'creator:id,name')->inBranch()
            ->when($request->location_id, fn ($q, $l) => $q->where('location_id', $l))
            ->when($request->date, fn ($q, $d) => $q->whereDate('created_at', $d))
            ->latest()->paginate($request->integer('per_page', 25));
    }

    /** Counter sale. method=account charges the family (invoice, unpaid); wallet debits the billing client. */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'location_id' => 'required|exists:locations,id', 'family_id' => 'nullable|exists:families,id',
            'method' => 'required|in:cash,card,wallet,account,whish,omt,transfer',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.quantity' => 'required|integer|min:1',
        ]);
        if (in_array($data['method'], ['wallet', 'account']) && empty($data['family_id'])) {
            throw ValidationException::withMessages(['family_id' => 'Select the client family to charge.']);
        }
        $family = isset($data['family_id']) ? Family::find($data['family_id']) : null;
        Billing::guard($family, 'Purchasing');

        $sale = DB::transaction(function () use ($data, $family, $request) {
            $lines = [];
            $total = 0;
            foreach ($data['items'] as $line) {
                $product = Product::find($line['product_id']);
                $stock = InventoryItem::where('product_id', $product->id)->where('location_id', $data['location_id'])->lockForUpdate()->first();
                if (! $stock || $stock->quantity < $line['quantity']) {
                    throw ValidationException::withMessages(['items' => "Not enough stock for {$product->name} at this location (".($stock->quantity ?? 0).' left).']);
                }
                $stock->decrement('quantity', $line['quantity']);
                $lines[] = ['product' => $product, 'quantity' => $line['quantity']];
                $total += $product->price * $line['quantity'];
            }

            $sale = Sale::create([
                'number' => Hub::sequence('sales', 'number', 'POS-'.now()->format('ymd').'-', 4),
                'location_id' => $data['location_id'], 'family_id' => $family?->id, 'total' => round($total, 2),
                'method' => $data['method'], 'created_by' => $request->user()->id,
            ]);
            foreach ($lines as $l) {
                $sale->items()->create(['product_id' => $l['product']->id, 'quantity' => $l['quantity'], 'unit_price' => $l['product']->price, 'total' => $l['product']->price * $l['quantity']]);
                StockMovement::create(['product_id' => $l['product']->id, 'location_id' => $data['location_id'], 'change' => -$l['quantity'], 'reason' => 'sale', 'reference' => $sale->number, 'created_by' => $request->user()->id]);
            }

            // Every sale is invoiced so revenue and the audit trail stay in one ledger.
            $invoiceFamily = $family ?? Family::firstOrCreate(['code' => 'WALK-IN'], ['name' => 'Walk-in customers']);
            $invoice = Billing::invoice($invoiceFamily, collect($lines)->map(fn ($l) => ['description' => $l['product']->name, 'quantity' => $l['quantity'], 'unit_price' => $l['product']->price, 'itemable' => $l['product']])->all(), 'pos', 0, 0, "POS sale {$sale->number}", (int) $data['location_id']);
            if ($data['method'] !== 'account') {
                Billing::pay($invoice, $invoice->total, $data['method'], $sale->number);
            }
            $sale->update(['invoice_id' => $invoice->id]);
            if ($family) {
                Hub::journey(Billing::billingClient($family)?->id, 'purchase', "POS purchase {$sale->number} \${$sale->total} ({$data['method']})", [], $family->id);
            }

            return $sale;
        });

        return response()->json($sale->load('items.product:id,name', 'invoice'), 201);
    }

    // ---- Expenses -----------------------------------------------------------

    public function expenses(Request $request)
    {
        return Expense::with('creator:id,name', 'location:id,name')->inBranch(null, true)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate($request->integer('per_page', 25));
    }

    public function saveExpense(Request $request, ?Expense $expense = null)
    {
        $data = $request->validate([
            'location_id' => 'nullable|exists:locations,id', 'category' => 'required|string', 'description' => 'required|string',
            'amount' => 'required|numeric|min:0.01', 'expense_date' => 'required|date', 'status' => 'in:pending,approved,paid,canceled',
        ]);
        if ($expense) {
            $expense->update($data);
            Hub::audit('expense.updated', $expense, null, $expense->amount, "Expense #{$expense->id} {$expense->status}");
        } else {
            $expense = Expense::create($data + ['created_by' => $request->user()->id]);
            Hub::audit('expense.created', $expense, null, $expense->amount, "Expense: {$expense->description}");
        }

        return $expense->load('creator:id,name');
    }

    public function expenseStatus(Request $request, Expense $expense)
    {
        $data = $request->validate(['status' => 'required|in:pending,approved,paid,canceled']);
        $expense->update($data);
        Hub::audit('expense.'.$data['status'], $expense, null, $expense->amount, "Expense #{$expense->id} marked {$data['status']}");

        return $expense;
    }
}
