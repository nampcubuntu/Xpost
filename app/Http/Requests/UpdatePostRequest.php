<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdatePostRequest extends StorePostRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * Same shape as creating, but every field is optional so the composer can
     * PATCH a single toggle without resending the whole creative.
     */
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(static fn (array $rules): array => array_values(array_filter(
                $rules,
                static fn ($rule) => $rule !== 'required',
            )))
            ->all();
    }
}
