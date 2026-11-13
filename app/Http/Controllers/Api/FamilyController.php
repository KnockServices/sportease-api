<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\ClientEvent;
use App\Models\Family;
use App\Models\Lead;
use App\Models\TemporaryCredit;
use App\Services\Billing;
use App\Services\Hub;
use App\Services\Messenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyController extends Controller
{
    public function index(Request $request)
    {
        $page = Family::withCount('clients')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")
                ->orWhereHas('clients', fn ($q) => $q->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('code', 'like', "%$s%"))))
            ->latest()->paginate($request->integer('per_page', 25));

        $page->getCollection()->transform(fn ($f) => $f->setAttribute('billing', Billing::status($f)));

        return $page;
    }

    public function show(Family $family)
    {
        $family->load([
            'clients.registrations' => fn ($q) => $q->with('package:id,name', 'team:id,name')->latest('start_date'),
            'invoices' => fn ($q) => $q->latest()->with('items'),
            'payments' => fn ($q) => $q->latest()->limit(50),
            'temporaryCredits' => fn ($q) => $q->latest(),
        ]);
        $family->setAttribute('billing', Billing::status($family));

        return $family;
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'discount_percent' => 'numeric|min:0|max:100', 'notes' => 'nullable|string']);
        $family = Family::create($data + ['code' => Hub::sequence('families', 'code', 'FAM-')]);
        Hub::journey(null, 'family', "Family {$family->code} created", [], $family->id);

        return response()->json($family, 201);
    }

    public function update(Request $request, Family $family)
    {
        $data = $request->validate(['name' => 'sometimes|string|max:120', 'discount_percent' => 'sometimes|numeric|min:0|max:100', 'notes' => 'nullable|string']);
        if (isset($data['discount_percent']) && (float) $data['discount_percent'] !== (float) $family->discount_percent) {
            Hub::audit('family.discount', $family, $family->id, null, "Family discount changed from {$family->discount_percent}% to {$data['discount_percent']}%");
        }
        $family->update($data);

        return $family;
    }

    public function addCredit(Request $request, Family $family)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1', 'expires_at' => 'required|date|after_or_equal:today', 'reason' => 'nullable|string']);
        $credit = TemporaryCredit::create($data + ['family_id' => $family->id, 'created_by' => $request->user()->id]);
        Hub::audit('credit.temporary', $credit, $family->id, $credit->amount, "Temporary credit of \${$credit->amount} until {$data['expires_at']}");
        Hub::journey(Billing::billingClient($family)?->id, 'billing', "Temporary credit \${$credit->amount} granted until {$data['expires_at']}", [], $family->id);

        return response()->json($credit, 201);
    }

    // ---- Clients ------------------------------------------------------------

    public function clients(Request $request)
    {
        return Client::with('family:id,code,name')
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")
                ->orWhere('phone', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->orderBy('first_name')->paginate($request->integer('per_page', 25));
    }

    public function client(Client $client)
    {
        $client->load(['family', 'registrations' => fn ($q) => $q->select('registrations.*')->selectSub(EnrolmentController::teamAttendanceSql(), 'attended_count')->latest(), 'registrations.package:id,name,type', 'registrations.team:id,name', 'registrations.coach:id,name', 'walletTransactions']);
        $client->setAttribute('billing', Billing::status($client->family_id));
        $client->setAttribute('journey', ClientEvent::where('client_id', $client->id)
            ->orWhere(fn ($q) => $client->lead_id ? $q->where('lead_id', $client->lead_id)->whereNull('client_id') : $q->whereRaw('1=0'))
            ->with('actor:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->get());
        $client->setAttribute('package_sessions', \App\Models\PackageSession::where('client_id', $client->id)->with('registration.package:id,name', 'coach:id,name')->latest('held_at')->limit(60)->get());
        $client->setAttribute('attendance', Attendance::where('client_id', $client->id)->with('session.team:id,name')->latest()->limit(60)->get());

        return $client;
    }

    public function storeClient(Request $request, Family $family)
    {
        $client = DB::transaction(fn () => $this->createClient($family, $this->clientRules($request)));

        return response()->json($client, 201);
    }

    public function updateClient(Request $request, Client $client)
    {
        $data = $this->clientRules($request, true);
        $this->assertBillingRules($client->family, $data + $client->only('type', 'billing_flag'), $client);
        $client->update($data);
        Hub::journey($client->id, 'profile', 'Profile updated', array_keys($data), $client->family_id);

        return $client;
    }

    public function topUp(Request $request, Client $client)
    {
        $data = $request->validate(['amount' => 'required|numeric|not_in:0', 'method' => 'required|in:cash,card,transfer,adjustment', 'reference' => 'nullable|string']);
        $type = $data['method'] === 'adjustment' ? 'adjustment' : 'topup';
        Billing::walletMove($client, (float) $data['amount'], $type, $data['reference'] ?? $data['method']);
        Hub::journey($client->id, 'wallet', "Wallet {$type} \${$data['amount']} ({$data['method']})", [], $client->family_id);

        return $client->fresh();
    }

    public function sendVerification(Client $client)
    {
        $this->issueVerification($client);

        return ['message' => 'Verification SMS sent.'];
    }

    /** Public: client opens the SMS link, enters the code and completes the data entry form. */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string', 'code' => 'required|string',
            'email' => 'nullable|email', 'date_of_birth' => 'nullable|date', 'gender' => 'nullable|in:male,female',
        ]);
        // Public link: find the client in any academy by its one-time token, then work inside that academy.
        $client = Client::withoutGlobalScope('tenant')->where('verification_token', $data['token'])->first();
        \App\Services\Tenant::set($client ? \App\Models\Organization::find($client->organization_id) : null);
        if (! $client || $client->verification_code !== $data['code']) {
            throw ValidationException::withMessages(['code' => 'Invalid verification code.']);
        }
        $client->update(array_filter($request->only('email', 'date_of_birth', 'gender')) + [
            'phone_verified_at' => now(), 'verification_code' => null, 'verification_token' => null,
        ]);
        Hub::journey($client->id, 'verification', 'Phone number verified and profile completed', [], $client->family_id);

        return ['message' => 'Thank you, your profile is verified.'];
    }

    /** Approved trial → full registration of the family and its members. */
    public function convertLead(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'family_id' => 'nullable|exists:families,id',
            'family_name' => 'required_without:family_id|string|max:120',
            'discount_percent' => 'numeric|min:0|max:100',
            'clients' => 'required|array|min:1',
            'clients.*.type' => 'required|in:parent,adult,child',
            'clients.*.first_name' => 'required|string|max:80',
            'clients.*.last_name' => 'required|string|max:80',
            'clients.*.phone' => 'nullable|string|max:30',
            'clients.*.email' => 'nullable|email',
            'clients.*.date_of_birth' => 'nullable|date',
            'clients.*.gender' => 'nullable|in:male,female',
            'clients.*.billing_flag' => 'boolean',
        ]);

        $family = DB::transaction(function () use ($data, $lead) {
            $family = isset($data['family_id']) ? Family::find($data['family_id']) : Family::create([
                'code' => Hub::sequence('families', 'code', 'FAM-'),
                'name' => $data['family_name'],
                'discount_percent' => $data['discount_percent'] ?? 0,
            ]);
            // Create adults first so children can map to a parent in the same request.
            $ordered = collect($data['clients'])->sortBy(fn ($c) => $c['type'] === 'child' ? 1 : 0);
            foreach ($ordered as $c) {
                $this->createClient($family, $c + ['lead_id' => $lead->id]);
            }
            $lead->update(['status' => 'converted', 'family_id' => $family->id]);
            Hub::journey(null, 'conversion', "Lead converted into family {$family->code}", [], $family->id, $lead->id);

            return $family;
        });

        return response()->json($family->load('clients'), 201);
    }

    // ---- helpers ------------------------------------------------------------

    private function clientRules(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes|' : '';

        return $request->validate([
            'type' => $r.'required|in:parent,adult,child',
            'first_name' => $r.'required|string|max:80',
            'last_name' => $r.'required|string|max:80',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'billing_flag' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,inactive',
        ]);
    }

    private function assertBillingRules(Family $family, array $c, ?Client $existing = null): void
    {
        if (($c['type'] ?? null) === 'child' && ! empty($c['billing_flag'])) {
            throw ValidationException::withMessages(['billing_flag' => 'Child clients cannot hold a billing flag.']);
        }
        if (($c['type'] ?? null) === 'child' && ! $family->clients()->whereIn('type', ['parent', 'adult'])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            throw ValidationException::withMessages(['type' => 'A child must be mapped to a family that has a Parent client.']);
        }
    }

    private function createClient(Family $family, array $c): Client
    {
        $this->assertBillingRules($family, $c);
        $client = $family->clients()->create($c + [
            'code' => Hub::sequence('clients', 'code', Hub::clientPrefix()),
            'billing_flag' => false,
        ]);
        // First Parent/Adult gets the billing flag automatically when none exists.
        if ($client->type !== 'child' && ! $family->clients()->where('billing_flag', true)->exists() && ! array_key_exists('billing_flag', $c)) {
            $client->update(['billing_flag' => true]);
        }
        Hub::journey($client->id, 'registration', "Registered as {$client->type} in family {$family->code} ({$client->code})", [], $family->id);
        if ($client->phone) {
            $this->issueVerification($client);
        }

        return $client;
    }

    private function issueVerification(Client $client): void
    {
        $client->update(['verification_code' => (string) random_int(100000, 999999), 'verification_token' => Str::random(40)]);
        $url = rtrim(config('app.portal_url', config('app.url')), '/').'/verify/'.$client->verification_token;
        Messenger::send('sms', $client->phone, "Welcome to ".\App\Services\Tenant::name()."! Your code is {$client->verification_code}. Complete your profile: {$url}", null, 'verification', $client);
    }
}
