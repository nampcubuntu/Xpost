<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\XAppTier;
use App\Exceptions\XAuthException;
use App\Http\Controllers\Controller;
use App\Http\Resources\XAppResource;
use App\Models\XApp;
use App\Services\X\XOAuth1Service;
use App\Services\X\XOAuth2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class XAppController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', XApp::class);

        return XAppResource::collection(
            XApp::query()->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', XApp::class);

        $data = $this->validated($request);

        $tier = XAppTier::from($data['tier']);

        $app = XApp::create($data + [
            'daily_post_cap' => $data['daily_post_cap'] ?? $tier->defaultDailyPostCap(),
            'monthly_post_cap' => $data['monthly_post_cap'] ?? $tier->defaultMonthlyPostCap(),
            'usage_period_started_on' => now()->toDateString(),
            'scopes' => $data['scopes'] ?? XOAuth2Service::DEFAULT_SCOPES,
        ]);

        return XAppResource::make($app)->response()->setStatusCode(201);
    }

    public function show(XApp $xApp): XAppResource
    {
        $this->authorize('view', $xApp);

        return XAppResource::make($xApp);
    }

    public function update(Request $request, XApp $xApp): XAppResource
    {
        $this->authorize('update', $xApp);

        $data = $this->validated($request, $xApp);

        // Blank secret means "leave it alone" -- the UI never receives the
        // current value, so an empty field must not wipe it.
        foreach (['client_secret', 'consumer_secret'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $xApp->fill($data)->save();

        return XAppResource::make($xApp->fresh());
    }

    public function destroy(XApp $xApp): JsonResponse
    {
        $this->authorize('delete', $xApp);

        if ($xApp->assigned_count > 0) {
            return response()->json([
                'message' => __('xpost.errors.app_has_accounts', ['count' => $xApp->assigned_count]),
            ], 422);
        }

        $xApp->delete();

        return response()->json(status: 204);
    }

    /** Start the OAuth 2.0 consent flow for a new account on this app. */
    public function authorizeUrl(Request $request, XApp $xApp, XOAuth2Service $oauth): JsonResponse
    {
        $this->authorize('update', $xApp);

        try {
            return response()->json([
                'url' => $oauth->authorizationUrl(
                    $xApp,
                    $request->user()->id,
                    $request->query('redirect_to'),
                ),
            ]);
        } catch (XAuthException $e) {
            // This is an X-app credential/configuration problem, not an
            // unexpected server crash. The Accounts page can now show the
            // actionable reason rather than its unhelpful "Server Error".
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The OAuth 1.0a variant.
     *
     * Worth a separate button rather than a silent upgrade: it is the only flow
     * the Ads API accepts, so it is the only way an account can mint its own
     * card -- and it costs the account holder an extra consent screen.
     */
    public function authorizeUrlOAuth1(Request $request, XApp $xApp, XOAuth1Service $oauth): JsonResponse
    {
        $this->authorize('update', $xApp);

        try {
            return response()->json([
                'url' => $oauth->authorizationUrl(
                    $xApp,
                    $request->user()->id,
                    $request->query('redirect_to'),
                    forceLogin: $request->query('force_login') !== '0',
                ),
            ]);
        } catch (XAuthException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function validated(Request $request, ?XApp $existing = null): array
    {
        return $request->validate([
            'name' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('x_apps')
                    ->where('team_id', $request->user()->current_team_id)
                    ->ignore($existing?->id),
            ],
            'tier' => [$existing ? 'sometimes' : 'required', Rule::enum(XAppTier::class)],
            // X shows this as a plain number; kept as a string so nothing
            // reformats or truncates an id that is only ever compared by eye.
            'provider_app_id' => ['nullable', 'string', 'max:32', 'regex:/^\d+$/'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'consumer_key' => ['nullable', 'string', 'max:255'],
            'consumer_secret' => ['nullable', 'string', 'max:255'],
            'callback_url' => ['nullable', 'url:https', 'max:2048'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'daily_post_cap' => ['nullable', 'integer', 'min:1'],
            'monthly_post_cap' => ['nullable', 'integer', 'min:1'],
            'max_accounts' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
