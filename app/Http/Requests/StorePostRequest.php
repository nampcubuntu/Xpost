<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DestinationType;
use App\Enums\PostStatus;
use App\Enums\ReplySettings;
use App\Enums\ScheduleMode;
use App\Enums\VariantStrategy;
use App\Models\Post;
use App\Rules\FitsTweetLength;
use App\Rules\ValidSpintax;
use App\Services\Publishing\TweetTextWeigher;
use App\Support\WallClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', new FitsTweetLength, new ValidSpintax],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],

            'reply_settings' => ['required', Rule::enum(ReplySettings::class)],

            'destination_type' => ['required', Rule::enum(DestinationType::class)],
            'website_url' => ['nullable', 'url:http,https', 'max:2048', Rule::requiredIf(
                fn () => $this->input('destination_type') === DestinationType::Website->value
            )],
            'headline' => ['nullable', 'string', 'max:70'],
            'app_store_url' => ['nullable', 'url:http,https', 'max:2048', Rule::requiredIf(
                fn () => $this->input('destination_type') === DestinationType::App->value
            )],
            'app_country_code' => ['nullable', 'string', 'size:2'],

            'is_ai_generated' => ['boolean'],
            'promoted_only' => ['boolean'],

            'variant_strategy' => ['required', Rule::enum(VariantStrategy::class)],
            'variants' => ['array', 'max:200'],
            'variants.*.body' => ['required', 'string', new FitsTweetLength],
            'variants.*.headline' => ['nullable', 'string', 'max:70'],
            'variants.*.website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'variants.*.weight' => ['integer', 'min:1', 'max:1000'],

            'media_ids' => ['array', 'max:'.config('xpost.composer.max_media_per_post')],
            'media_ids.*' => ['integer', Rule::exists('post_media', 'id')],

            'schedule_mode' => ['required', Rule::enum(ScheduleMode::class)],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'window_ends_at' => ['nullable', 'date', 'after:scheduled_at'],
            'jitter_seconds' => ['integer', 'min:0', 'max:86400'],
            'per_minute_cap' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'timezone' => ['nullable', 'timezone'],

            'audience' => ['required', 'array'],
            'audience.mode' => ['required', 'in:all,groups,accounts,filter'],
            'audience.groups' => ['array'],
            'audience.groups.*' => ['integer', Rule::exists('account_groups', 'id')],
            'audience.accounts' => ['array'],
            'audience.accounts.*' => ['integer', Rule::exists('x_accounts', 'id')],
            'audience.exclude_accounts' => ['array'],
            'audience.exclude_groups' => ['array'],
            'audience.filters' => ['array'],
            'audience.limit' => ['nullable', 'integer', 'min:1', 'max:'.config('xpost.publishing.max_targets_per_post')],
            'audience.shuffle' => ['boolean'],

            'status' => ['nullable', Rule::in([PostStatus::Draft->value, PostStatus::Scheduled->value])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // X will reject an empty tweet; catch it here rather than after a
            // 10k-target fan-out has already been queued.
            if (blank($this->input('body')) && blank($this->input('media_ids'))) {
                $validator->errors()->add('body', __('validation.custom.post.empty'));
            }

            // A scheduled post with no time is a draft that will never fire.
            if ($this->input('status') === PostStatus::Scheduled->value
                && $this->input('schedule_mode') !== ScheduleMode::Immediate->value
                && blank($this->input('scheduled_at'))) {
                $validator->errors()->add('scheduled_at', __('validation.custom.post.needs_time'));
            }

            // Drip and jitter need somewhere to spread into.
            $mode = ScheduleMode::tryFrom((string) $this->input('schedule_mode'));

            if ($mode?->needsWindow()
                && blank($this->input('window_ends_at'))
                && blank($this->input('jitter_seconds'))
                && blank($this->input('per_minute_cap'))) {
                $validator->errors()->add('window_ends_at', __('validation.custom.post.needs_window'));
            }

            // Strategies that read the variant table need rows in it.
            $strategy = VariantStrategy::tryFrom((string) $this->input('variant_strategy'));

            if ($strategy?->usesVariantTable() && count($this->input('variants', [])) < 1) {
                $validator->errors()->add('variants', __('validation.custom.post.needs_variants'));
            }

            // The destination URL is appended to the tweet, so it counts.
            if ($this->input('destination_type') === DestinationType::Website->value) {
                $combined = trim($this->input('body', '').' '.$this->input('website_url', ''));

                if (! TweetTextWeigher::fits($combined)) {
                    $validator->errors()->add('body', __('validation.custom.post.too_long_with_url'));
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $timezone = $this->input('timezone') ?: ($this->user()->timezone ?? config('app.timezone'));

        $this->merge([
            'reply_settings' => $this->input('reply_settings', ReplySettings::Everyone->value),
            'destination_type' => $this->input('destination_type', DestinationType::None->value),
            'variant_strategy' => $this->input('variant_strategy', VariantStrategy::Same->value),
            'schedule_mode' => $this->input('schedule_mode', ScheduleMode::Immediate->value),
            'timezone' => $timezone,

            // The hour the user typed means nothing without knowing whose clock
            // it was on; resolve it before the `after_or_equal:now` rule reads it.
            'scheduled_at' => WallClock::toAppTimezone($this->input('scheduled_at'), $timezone),
            'window_ends_at' => WallClock::toAppTimezone($this->input('window_ends_at'), $timezone),
        ]);
    }
}
