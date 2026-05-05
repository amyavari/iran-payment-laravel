<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating callback data from the Sep gateway.
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
        return [
            'MID' => ['nullable', 'numeric'],
            'State' => ['required', 'string'],
            'Status' => ['required', 'numeric'],
            'RRN' => ['nullable', 'numeric'],
            'RefNum' => ['nullable', 'string'],
            'ResNum' => ['required', 'string'],
            'TerminalId' => ['nullable', 'numeric'],
            'TraceNo' => ['nullable', 'numeric'],
            'Amount' => ['nullable', 'numeric'],
            'SecurePan' => ['nullable', 'string'],
            'HashedCardNumber' => ['nullable', 'string'],
        ];
    }
}
