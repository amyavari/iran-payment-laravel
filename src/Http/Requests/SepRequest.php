<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating and sanitizing callback data from the Sep gateway.
 */
final class SepRequest extends FormRequest
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
            'MID' => ['numeric'],
            'State' => ['required', 'string'],
            'Status' => ['required', 'numeric'],
            'RRN' => ['numeric'],
            'RefNum' => ['string'],
            'ResNum' => ['required', 'string'],
            'TerminalId' => ['numeric'],
            'TraceNo' => ['numeric'],
            'Amount' => ['numeric'],
            'SecurePan' => ['string'],
            'HashedCardNumber' => ['string'],
        ];
    }
}
