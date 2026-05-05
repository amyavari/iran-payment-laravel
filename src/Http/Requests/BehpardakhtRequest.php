<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating callback data from the Behpardakht gateway.
 */
final class BehpardakhtRequest extends FormRequest
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
            'RefId' => ['required', 'string'],
            'ResCode' => ['required', 'numeric'],
            'SaleOrderId' => ['required', 'numeric'],
            'SaleReferenceId' => ['nullable', 'numeric'],
            'CardHolderInfo' => ['nullable', 'string'],
            'CardHolderPan' => ['nullable', 'string'],
            'FinalAmount' => ['nullable', 'numeric'],
        ];
    }
}
