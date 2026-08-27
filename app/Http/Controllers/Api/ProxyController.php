<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProxyScheme;
use App\Http\Controllers\Controller;
use App\Jobs\CheckProxy;
use App\Models\Proxy;
use App\Models\XAccount;
use App\Services\Proxies\ProxyAssigner;
use App\Services\Proxies\ProxyChecker;
use App\Support\ProxyLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProxyController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        return response()->json([
            'data' => Proxy::query()
                ->orderBy('label')
                ->get(['id', 'label', 'scheme', 'host', 'port', 'country', 'is_active',
                    'health_status', 'latency_ms', 'assigned_count', 'max_accounts', 'last_checked_at',
                    'exit_ip', 'region', 'city', 'check_queued_at']),
        ]);
    }

    /**
     * One row, checked now.
     *
     * Kept inline rather than queued: a single click should answer, and one
     * proxy is fifteen seconds at worst. A selection goes through checkMany.
     */
    public function check(Proxy $proxy, ProxyChecker $checker): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        return response()->json($checker->check($proxy) + [
            'exit_ip' => $proxy->exit_ip,
            'country' => $proxy->country,
            'region' => $proxy->region,
            'city' => $proxy->city,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        return response()->json(Proxy::create($this->validated($request)), 201);
    }

    /**
     * A supplier's whole delivery, pasted.
     *
     * Proxies arrive as a text blob and every seller picks a different column
     * order, so ProxyLine recognises each field by what it looks like instead
     * of by where it sits. Entering a thousand of them one form at a time is
     * not a workflow.
     *
     * A line already on the team is refreshed rather than refused: re-pasting
     * the supplier's list is how a rotated password gets applied, and "đã tồn
     * tại" on 900 of 1000 lines would make that impossible.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:500000'],
            'scheme' => ['nullable', Rule::enum(ProxyScheme::class)],
            'max_accounts' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        [$lines, $skipped] = ProxyLine::parseMany($data['text']);
        $limit = (int) config('xpost.proxies.bulk_limit');

        if (count($lines) > $limit) {
            return response()->json([
                'message' => "Tối đa {$limit} proxy mỗi lần dán. Hãy chia nhỏ danh sách.",
            ], 422);
        }

        /*
         * Every existing row up front, rather than a SELECT per line: at two
         * thousand lines that is the difference between one query and two
         * thousand, and the rows are four small columns.
         */
        $existing = Proxy::query()
            ->get(['id', 'host', 'port', 'username'])
            ->keyBy(fn (Proxy $proxy) => self::fingerprint($proxy->host, $proxy->port, $proxy->username));

        $created = 0;
        $updated = 0;

        foreach ($lines as $line) {
            $attributes = [
                'scheme' => $line->scheme ?? $data['scheme'] ?? ProxyScheme::Http->value,
                'host' => $line->host,
                'port' => $line->port,
                'username' => $line->username,
                'password' => $line->password,
            ];

            $match = $existing->get(self::fingerprint($line->host, $line->port, $line->username));

            if ($match !== null) {
                $match->fill($attributes)->save();
                $updated++;

                continue;
            }

            $proxy = Proxy::create($attributes + array_filter([
                'max_accounts' => $data['max_accounts'] ?? null,
            ]));

            // Kept so a list that repeats a line updates it rather than
            // failing the unique index on the second pass.
            $existing->put(self::fingerprint($line->host, $line->port, $line->username), $proxy);
            $created++;
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 201);
    }

    public function update(Request $request, Proxy $proxy): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $this->validated($request, $proxy);

        // A blank password field means "keep the stored one".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $proxy->fill($data)->save();

        return response()->json($proxy->fresh());
    }

    public function destroy(Proxy $proxy): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        if ($proxy->assigned_count > 0) {
            return response()->json(['message' => __('xpost.errors.proxy_in_use')], 422);
        }

        $proxy->delete();

        return response()->json(status: 204);
    }

    /**
     * A selection deleted in one go.
     *
     * Proxies still carrying accounts are left alone and named, rather than the
     * whole request being refused: a selection of fifty where two are in use
     * should delete forty-eight and say which two it kept.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $proxies = $this->selection($request);

        $inUse = $proxies->filter(fn (Proxy $proxy) => $proxy->assigned_count > 0);
        $deletable = $proxies->reject(fn (Proxy $proxy) => $proxy->assigned_count > 0);

        Proxy::query()->whereKey($deletable->modelKeys())->delete();

        return response()->json([
            'deleted' => $deletable->count(),
            'skipped' => $inUse->map(fn (Proxy $proxy) => [
                'id' => $proxy->id,
                'label' => $proxy->label ?? "{$proxy->host}:{$proxy->port}",
                'reason' => __('xpost.errors.proxy_in_use'),
            ])->values(),
        ]);
    }

    /**
     * One check per row, queued.
     *
     * A dead proxy takes fifteen seconds to admit it, so a selection of a
     * hundred does not fit in an HTTP request -- the page marks them queued and
     * watches the verdicts arrive.
     *
     * The mark is set here rather than in the job so the row changes the moment
     * the click lands. A queue that is backed up or stopped would otherwise
     * look exactly like a click that did nothing.
     */
    public function checkMany(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $proxies = $this->selection($request);

        Proxy::query()->whereKey($proxies->modelKeys())->update(['check_queued_at' => now()]);

        foreach ($proxies as $proxy) {
            CheckProxy::dispatch($proxy->id, $proxy->team_id);
        }

        return response()->json(['queued' => $proxies->count()], 202);
    }

    /**
     * These proxies, spread over those accounts.
     *
     * The same operation as spread_proxy on the accounts page, entered from the
     * other end: there, a set of accounts is handed to the whole pool; here, a
     * set of proxies is picked first and the accounts are chosen to go behind
     * them. Both run through ProxyAssigner, so "emptiest first" and the
     * max_accounts ceiling mean the same thing whichever page you started on.
     */
    public function assignAccounts(Request $request, ProxyAssigner $assigner): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:10000'],
            'account_ids.*' => ['integer'],
        ]);

        $proxies = $this->selection($request);

        if ($proxies->isEmpty()) {
            return response()->json(['message' => 'Không tìm thấy proxy nào trong lựa chọn.'], 422);
        }

        $assigner->restrictTo($proxies->modelKeys());
        $assigned = 0;

        XAccount::query()
            ->whereIn('id', $request->input('account_ids'))
            // Chunked so the observer that maintains assigned_count still fires
            // per row, and so ten thousand ids do not hold one long write lock.
            ->chunkById(500, function ($accounts) use ($assigner, &$assigned): void {
                foreach ($accounts as $account) {
                    $proxy = $assigner->next($account->team_id);

                    if ($proxy === null) {
                        continue;
                    }

                    $account->forceFill(['proxy_id' => $proxy->id])->save();
                    $assigned++;
                }
            });

        return response()->json([
            'assigned' => $assigned,
            // Accounts that found the selected proxies full. Silence would read
            // as "done" on accounts that still share one IP.
            'unassigned' => $assigner->unassigned,
        ]);
    }

    /**
     * The rows a bulk action names, scoped to the team.
     *
     * whereKey after the team scope rather than trusting the ids: they arrive
     * from the browser, and an id from another team must find nothing rather
     * than something.
     *
     * @return Collection<int, Proxy>
     */
    private function selection(Request $request): object
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:2000'],
            'ids.*' => ['integer'],
        ]);

        return Proxy::query()
            ->whereKey($data['ids'])
            ->get(['id', 'team_id', 'label', 'host', 'port', 'assigned_count']);
    }

    /** The unique index, as a string: host, port and username identify a row. */
    private static function fingerprint(string $host, int $port, ?string $username): string
    {
        return strtolower($host)."\0{$port}\0".($username ?? '');
    }

    private function validated(Request $request, ?Proxy $existing = null): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'scheme' => [$existing ? 'sometimes' : 'required', Rule::enum(ProxyScheme::class)],
            'host' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'port' => [$existing ? 'sometimes' : 'required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'max_accounts' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
