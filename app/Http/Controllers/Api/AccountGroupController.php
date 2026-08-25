<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\XAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        return response()->json([
            'data' => AccountGroup::query()->orderBy('name')->get(['id', 'name', 'color', 'description', 'accounts_count']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $group = AccountGroup::create($this->validated($request));

        return response()->json($group, 201);
    }

    public function update(Request $request, AccountGroup $accountGroup): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $accountGroup->fill($this->validated($request, $accountGroup))->save();

        return response()->json($accountGroup);
    }

    public function destroy(AccountGroup $accountGroup): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $accountGroup->delete();

        return response()->json(status: 204);
    }

    /** Add or remove accounts in one call, so the UI can save a whole edit at once. */
    public function sync(Request $request, AccountGroup $accountGroup): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'attach' => ['array'],
            'attach.*' => ['integer'],
            'detach' => ['array'],
            'detach.*' => ['integer'],
        ]);

        if ($ids = $data['attach'] ?? []) {
            $accountGroup->accounts()->syncWithoutDetaching($ids);
        }

        if ($ids = $data['detach'] ?? []) {
            $accountGroup->accounts()->detach($ids);
        }

        $accountGroup->syncCount();

        return response()->json($accountGroup->fresh());
    }

    private function validated(Request $request, ?AccountGroup $existing = null): array
    {
        return $request->validate([
            'name' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('account_groups')
                    ->where('team_id', $request->user()->current_team_id)
                    ->ignore($existing?->id),
            ],
            'color' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
