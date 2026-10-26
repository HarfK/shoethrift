<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Models\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's address book. Addresses are always looked up through the user's own
 * addresses() relation, so another customer's address is a 404.
 */
class AddressController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.addresses.index', [
            'addresses' => $request->user()->addresses()->orderByDesc('is_default')->latest('updated_at')->get(),
            'canAddMore' => $request->user()->addresses()->count() < config('shop.max_addresses'),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->addresses()->count() >= config('shop.max_addresses')) {
            return redirect()->route('account.addresses.index')->with('error', $this->limitMessage());
        }

        return view('account.addresses.form', ['address' => new Address(['recipient_name' => $request->user()->name, 'phone' => $request->user()->phone])]);
    }

    public function store(AddressRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->addresses()->count() >= config('shop.max_addresses')) {
            return redirect()->route('account.addresses.index')->with('error', $this->limitMessage());
        }

        $address = $user->addresses()->create($request->addressData());

        if ($request->boolean('make_default') || ! $user->defaultAddress()->exists()) {
            $address->makeDefault();
        }

        return redirect()->route('account.addresses.index')->with('success', 'Address saved.');
    }

    public function edit(Request $request, int $address): View
    {
        return view('account.addresses.form', ['address' => $this->own($request, $address)]);
    }

    public function update(AddressRequest $request, int $address): RedirectResponse
    {
        $address = $this->own($request, $address);
        $address->update($request->addressData());

        if ($request->boolean('make_default')) {
            $address->makeDefault();
        }

        return redirect()->route('account.addresses.index')->with('success', 'Address updated. Past orders keep the address they were sent to.');
    }

    public function destroy(Request $request, int $address): RedirectResponse
    {
        $this->own($request, $address)->deleteKeepingDefault();

        return redirect()->route('account.addresses.index')->with('success', 'Address deleted.');
    }

    public function makeDefault(Request $request, int $address): RedirectResponse
    {
        $this->own($request, $address)->makeDefault();

        return redirect()->route('account.addresses.index')->with('success', 'Default address updated.');
    }

    private function own(Request $request, int $id): Address
    {
        return $request->user()->addresses()->findOrFail($id);
    }

    private function limitMessage(): string
    {
        return 'You can save up to '.config('shop.max_addresses').' addresses. Delete one to add another.';
    }
}
