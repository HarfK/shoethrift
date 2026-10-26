<?php

namespace App\Http\Requests;

use App\Http\Controllers\CheckoutController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Address fields for the address book. Checkout reuses fieldRules() / fieldMessages() for its
 * "use a different address" form (see CheckoutController).
 */
class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::fieldRules() + [
            'make_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::fieldMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30', 'regex:'.CheckoutController::PHONE_PATTERN],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'digits:5'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::in(config('shop.states'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fieldMessages(): array
    {
        return [
            'phone.regex' => 'Enter a Malaysian phone number, e.g. 012-345 6789.',
            'postcode.digits' => 'Malaysian postcodes have 5 digits.',
            'state.in' => 'Choose a state from the list.',
            'line1.required' => 'Enter the street address.',
        ];
    }

    /**
     * Validated address columns, trimmed.
     *
     * @return array<string, string|null>
     */
    public function addressData(): array
    {
        return array_map(fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null,
            $this->safe()->except('make_default'));
    }
}
