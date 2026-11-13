<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Family;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TemporaryCredit;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Family billing: invoices with family discounts, payments, credit wallets and the
 * automated restriction engine for overdue accounts.
 */
class Billing
{
    /** The Parent/Adult client who carries the family's billing flag. */
    public static function billingClient(Family $family): ?Client
    {
        return $family->clients()->where('billing_flag', true)->whereIn('type', ['parent', 'adult'])->first();
    }

    /**
     * @param  array<int, array{description:string, quantity?:float, unit_price:float, itemable?:\Illuminate\Database\Eloquent\Model}>  $items
     */
    public static function invoice(Family $family, array $items, string $source, ?float $discountOverride = null, int $dueInDays = 7, ?string $notes = null, ?int $locationId = null): Invoice
    {
        // The branch that earns the revenue: from what is billed (team, court, tournament, sale), else the working branch.
        $locationId ??= Branch::of($items[0]['itemable'] ?? null) ?? Branch::fallback();

        return DB::transaction(function () use ($family, $items, $source, $discountOverride, $dueInDays, $notes, $locationId) {
            $subtotal = collect($items)->sum(fn ($i) => ($i['quantity'] ?? 1) * $i['unit_price']);
            // Family discount auto-applies to registrations and reservations; staff may override.
            $discountPct = $discountOverride ?? (in_array($source, ['registration', 'reservation']) ? (float) $family->discount_percent : 0);
            $discount = round($subtotal * $discountPct / 100, 2);

            $invoice = Invoice::create([
                'number' => Hub::sequence('invoices', 'number', 'INV-'.now()->format('y').'-'),
                'location_id' => $locationId,
                'family_id' => $family->id,
                'client_id' => self::billingClient($family)?->id,
                'source' => $source,
                'subtotal' => $subtotal,
                'discount_percent' => $discountPct,
                'discount_amount' => $discount,
                'total' => round($subtotal - $discount, 2),
                'paid_amount' => 0,
                'status' => $subtotal - $discount <= 0 ? 'paid' : 'unpaid',
                'due_date' => today()->addDays($dueInDays),
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $qty = $item['quantity'] ?? 1;
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price' => $item['unit_price'],
                    'total' => round($qty * $item['unit_price'], 2),
                    'itemable_type' => isset($item['itemable']) ? $item['itemable']::class : null,
                    'itemable_id' => isset($item['itemable']) ? $item['itemable']->getKey() : null,
                ]);
            }

            Hub::audit('invoice.created', $invoice, $family->id, $invoice->total, "Invoice {$invoice->number} issued ({$source})", ['discount_percent' => $discountPct]);
            Hub::journey($invoice->client_id, 'invoice', "Invoice {$invoice->number} issued for \${$invoice->total}", ['invoice_id' => $invoice->id], $family->id);

            return $invoice;
        });
    }

    public static function pay(Invoice $invoice, float $amount, string $method, ?string $reference = null): Payment
    {
        if (in_array($invoice->status, ['paid', 'void'])) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already '.$invoice->status.'.']);
        }
        $amount = round(min($amount, $invoice->balance), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference) {
            if ($method === 'wallet') {
                $client = $invoice->client ?? self::billingClient($invoice->family);
                if (! $client) {
                    throw ValidationException::withMessages(['method' => 'This family has no billing client with a credit wallet.']);
                }
                self::walletMove($client, -$amount, 'charge', $invoice->number);
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'family_id' => $invoice->family_id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'created_by' => auth()->id(),
            ]);

            $invoice->paid_amount = round($invoice->paid_amount + $amount, 2);
            $invoice->status = $invoice->paid_amount >= $invoice->total ? 'paid' : 'partial';
            $invoice->save();

            Hub::audit('payment.received', $payment, $invoice->family_id, $amount, "Payment of \${$amount} by {$method} on {$invoice->number}");
            Hub::journey($invoice->client_id, 'payment', "Paid \${$amount} ({$method}) on {$invoice->number}", ['invoice_id' => $invoice->id], $invoice->family_id);

            return $payment;
        });
    }

    public static function voidInvoice(Invoice $invoice, string $reason): void
    {
        if ($invoice->paid_amount > 0) {
            throw ValidationException::withMessages(['invoice' => 'Invoices with payments cannot be voided.']);
        }
        $invoice->update(['status' => 'void', 'notes' => trim(($invoice->notes ?? '')."\nVoided: ".$reason)]);
        Hub::audit('invoice.voided', $invoice, $invoice->family_id, $invoice->total, "Invoice {$invoice->number} voided: {$reason}");
    }

    public static function walletMove(Client $client, float $amount, string $type, ?string $reference = null): WalletTransaction
    {
        if (! $client->billing_flag) {
            throw ValidationException::withMessages(['wallet' => 'Only Parent/Adult clients with a billing flag hold a credit wallet.']);
        }
        $client->refresh();
        $newBalance = round($client->wallet_balance + $amount, 2);
        if ($newBalance < 0) {
            throw ValidationException::withMessages(['wallet' => "Insufficient wallet balance (\${$client->wallet_balance})."]);
        }
        $client->update(['wallet_balance' => $newBalance]);
        $tx = WalletTransaction::create([
            'client_id' => $client->id,
            'amount' => $amount,
            'type' => $type,
            'balance_after' => $newBalance,
            'reference' => $reference,
            'created_by' => auth()->id(),
        ]);
        Hub::audit('wallet.'.$type, $tx, $client->family_id, $amount, "Wallet {$type} of \${$amount} for {$client->full_name}");

        return $tx;
    }

    /** @return array{restricted:bool, overdue:float, outstanding:float, credit:float, credit_expires_at:?string} */
    public static function status(Family|int $family): array
    {
        $familyId = $family instanceof Family ? $family->id : $family;
        $open = Invoice::where('family_id', $familyId)->whereIn('status', ['unpaid', 'partial']);
        $outstanding = (float) (clone $open)->sum(DB::raw('total - paid_amount'));
        $overdue = (float) (clone $open)->whereDate('due_date', '<', today())->sum(DB::raw('total - paid_amount'));
        $credits = TemporaryCredit::where('family_id', $familyId)->whereDate('expires_at', '>=', today());
        $credit = (float) (clone $credits)->sum('amount');

        return [
            'restricted' => $overdue > 0 && $credit < $overdue,
            'overdue' => round($overdue, 2),
            'outstanding' => round($outstanding, 2),
            'credit' => round($credit, 2),
            'credit_expires_at' => (clone $credits)->min('expires_at'),
        ];
    }

    /** Restriction engine: blocks purchases, reservations and registrations for overdue families. */
    public static function guard(Family|int|null $family, string $action): void
    {
        if (! $family) {
            return;
        }
        $s = self::status($family);
        if ($s['restricted']) {
            throw ValidationException::withMessages([
                'restricted' => "Account restricted: \${$s['overdue']} overdue. {$action} is blocked until payment is confirmed or temporary credit is applied.",
            ]);
        }
    }
}
