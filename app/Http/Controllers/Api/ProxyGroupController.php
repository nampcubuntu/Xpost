<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proxy;
use App\Models\ProxyGroup;
use App\Models\XAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Names for batches of proxies.
 *
 * Deliberately the same shape as AccountGroupController: same verbs, same sync
 * endpoint, same authorisation. Two pages solving one problem two different
 * ways is how the second one ends up being the one nobody learns.
 *
 * Unpaginated, unlike the proxies themselves. Groups are typed in by hand --
 * one per supplier, per country, per purpose -- so the number is set by how
 * many names someone is willing to invent, not by how many proxies they bought.
 */
class ProxyGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        return response()->json([
            'data' => ProxyGroup::query()->orderBy('name')
                ->get(['id', 'name', 'color', 'description', 'proxies_count']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        return response()->json(ProxyGroup::create($this->validated($request)), 201);
    }

    public function update(Request $request, ProxyGroup $proxyGroup): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $proxyGroup->fill($this->validated($request, $proxyGroup))->save();

        return response()->json($proxyGroup);
    }

    /**
     * The name goes; the proxies stay.
     *
     * Only the memberships are dropped, by the pivot's cascade. Deleting the
     * proxies with the group would make a mis-click into a re-import of a
     * supplier's whole delivery.
     */
    public function destroy(ProxyGroup $proxyGroup): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $proxyGroup->delete();

        return response()->json(status: 204);
    }

    /**
     * Add and remove in one call, so a whole edit saves at once.
     *
     * Ids are filtered through a team-scoped query rather than trusted: they
     * arrive from the browser, and the pivot's own foreign key would happily
     * accept another team's proxy.
     */
    public function sync(Request $request, ProxyGroup $proxyGroup): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'attach' => ['array', 'max:2000'],
            'attach.*' => ['integer'],
            'detach' => ['array', 'max:2000'],
            'detach.*' => ['integer'],
        ]);

        if ($ids = $this->onTeam($data['attach'] ?? [])) {
            $proxyGroup->proxies()->syncWithoutDetaching($ids);
        }

        if ($ids = $this->onTeam($data['detach'] ?? [])) {
            $proxyGroup->proxies()->detach($ids);
        }

        $proxyGroup->syncCount();

        return response()->json($proxyGroup->fresh());
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function onTeam(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Proxy::query()->whereKey($ids)->pluck('id')->all();
    }

    private function validated(Request $request, ?ProxyGroup $existing = null): array
    {
        return $request->validate([
            'name' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('proxy_groups')
                    ->where('team_id', $request->user()->current_team_id)
                    ->ignore($existing?->id),
            ],
            'color' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
