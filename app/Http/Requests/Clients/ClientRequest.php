<?php

namespace App\Http\Requests\Clients;

use App\Enums\EntityType;
use App\Enums\ReportingFramework;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised in controller via policy
    }

    public function rules(): array
    {
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:200'],
            'registration_no' => ['nullable', 'string', 'max:40',
                Rule::unique('clients', 'registration_no')
                    ->where('firm_id', $this->user()->firm_id)
                    ->whereNull('deleted_at')
                    ->ignore($client?->id)],
            'entity_type' => ['required', Rule::enum(EntityType::class)],
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class)],
            'industry' => ['nullable', 'string', 'max:120'],
            'fye_month' => ['required', 'integer', 'between:1,12'],
            'fye_day' => ['required', 'integer', 'between:1,31'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $m = (int) $this->input('fye_month');
            $d = (int) $this->input('fye_day');
            if ($m >= 1 && $m <= 12 && $d > cal_days_in_month(CAL_GREGORIAN, $m, 2024)) {
                $validator->errors()->add('fye_day', 'The financial year-end day is not valid for that month.');
            }
        }];
    }

    public function messages(): array
    {
        return ['registration_no.unique' => 'Another client in your firm already uses this registration number.'];
    }
}
