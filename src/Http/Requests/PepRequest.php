<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating and sanitizing callback data from the Pep gateway.
 */
final class PepRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Only to sanitize inputs.
         */
        return [
            'invoiceId' => ['required', 'numeric'],
            'status' => ['required', 'string'],
            'referenceNumber' => ['numeric'],
            'trackId' => ['numeric'],
        ];
    }
}
