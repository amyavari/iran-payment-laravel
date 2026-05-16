<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating callback data from the Payping gateway.
 */
final class PaypingRequest extends FormRequest
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
            'status' => ['required', 'numeric'],
            'errorCode' => ['present', 'nullable', 'numeric'],
            'data' => ['required', 'array'],
            'data.paymentCode' => ['required', 'string'],
            'data.clientRefId' => ['nullable', 'string'],
            'data.paymentRefId' => ['nullable', 'numeric'],
            'data.amount' => ['nullable', 'numeric'],
            'data.gatewayAmount' => ['nullable', 'numeric'],
            'data.cardNumber' => ['nullable', 'string'],
            'data.cardHashPan' => ['nullable', 'string'],
        ];
    }
}
