<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionPlanRequest extends FormRequest
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
        $plan = $this->route('subscriptionPlan'); // Get the plan from route model binding
        $planId = $plan ? $plan->plan_id : null;
        
        return [
            'plan_name' => 'required|string|max:50|unique:subscription_plan,plan_name,' . $planId . ',plan_id',
            'price' => 'required|numeric|min:0|max:99999999.99',
            'duration_in_months' => 'required|integer|min:1|max:120',
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
            'duration_in_months.required' => 'Duration is required.',
            'duration_in_months.min' => 'Duration must be at least 1 month.',
            'duration_in_months.max' => 'Duration cannot exceed 120 months (10 years).',
        ];
    }
}
