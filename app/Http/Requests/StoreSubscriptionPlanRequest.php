<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Admin authorization should be handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_name' => 'required|string|max:50|unique:subscription_plan,plan_name',
            'price' => 'required|numeric|min:0|max:99999999.99',
            // Allow 0 months to support day/second-based plans
            'duration_in_months' => 'nullable|integer|min:0|max:120',
            // New optional fields accepted (even if not persisted yet)
            'duration_in_days' => 'nullable|integer|min:0',
            'duration_in_seconds' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'plan_name.required' => 'Plan name is required.',
            'plan_name.unique' => 'A plan with this name already exists.',
            'price.required' => 'Price is required.',
            'price.numeric' => 'Price must be a valid number.',
            'price.min' => 'Price cannot be negative.',
            'duration_in_months.min' => 'Months cannot be negative.',
            'duration_in_months.max' => 'Duration cannot exceed 120 months (10 years).',
            'duration_in_days.min' => 'Days cannot be negative.',
            'duration_in_seconds.min' => 'Seconds cannot be negative.',
        ];
    }
}
