<?php

namespace App\Http\Requests\Engagements;

use App\Enums\EngagementType;
use App\Enums\ReportingFramework;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EngagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy checked in controller
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $engagement = $this->route('engagement');

        return [
            'client_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-\/_.]+$/',
                Rule::unique('engagements', 'code')->where('firm_id', $this->user()->firm_id)->ignore($engagement?->id)],
            'name' => ['required', 'string', 'max:200'],
            'engagement_type' => ['required', Rule::enum(EngagementType::class)],
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'reporting_deadline' => ['nullable', 'date', 'after:period_end'],
            'engagement_partner_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'manager_id' => $creating ? ['nullable', 'integer'] : ['prohibited'],
        ];
    }
}
