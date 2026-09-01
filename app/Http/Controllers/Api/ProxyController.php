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
    /**
     * A page of proxies, not the pool.
     *
     * This returned every row. At 328 bytes each a supplier's delivery of two
     * thousand is 0.6MB down a tunnel and two thousand rows of table, and the
     * bulk import accepts two thousand in one paste -- so the size the page had
     * to survive was set by a limit somewhere else entirely.
     *
     * Filtered here rather than in the browser for the same reason: searching a
     * pool you have not finished downloading is not searching it.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        $proxies = Proxy::query()
            ->when($request->query('search'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('label', 'like', "%{$term}%")
                    ->orWhere('host', 'like', "%{$term}%")
                    ->orWhere('exit_ip', 'like', "%{$term}%")
                    ->orWhere('country', 'like', "%{$term}%")
            ))
            ->when($request->query('health'), fn ($q, $health) => $q->where('health_status', $health))
            // Assignable first when asked: the account pages want somewhere to
            // put accounts, not an audit of everything ever imported.
            ->when($request->boolean('assignable'), fn ($q) => $q->assignable())
            ->orderBy('label')
            ->orderBy('host')
            ->paginate(min((int) $request->query('per_page', 50), 200))
            ->withQueryString();

        return response()->json([
            'data' => $proxies->through(fn (Proxy $proxy) => $proxy->only([
                'id', 'label', 'scheme', 'host', 'port', 'country', 'is_active',
                'health_status', 'latency_ms', 'assigned_count', 'max_accounts',
                'last_checked_at', 'exit_ip', 'region', 'city', 'check_queued_at',
            ]))->items(),
            'meta' => [
                'current_page' => $proxies->currentPage(),
                'last_page' => $proxies->lastPage(),
                'per_page' => $proxies->perPage(),
                'total' => $proxies->total(),
            ],
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

    /**
     * Deleted, and the accounts behind it let go first.
     *
     * This used to refuse while any account was assigned, which made the only
     * way to retire a dead proxy a hunt through the accounts page for the
     * hundred rows pointing at it. The supplier's line is dead either way; the
     * refusal did not keep those accounts working, it just kept them pointing
     * at something that no longer answers.
     *
     * So the assignment is cut here and the count comes back, because it is a
     * consequence worth reading: those accounts now go out from the server's
     * own IP until they are given another proxy, which is the one thing this
     * page exists to prevent.
     */
    public function destroy(Proxy $proxy): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $unassigned = $this->detach([$proxy->id]);

        $proxy->delete();

        return response()->json(['unassigned' => $unassigned]);
    }

    /** A selection deleted in one go, accounts released the same way. */
    public function destroyMany(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $proxies = $this->selection($request);
        $unassigned = $this->detach($proxies->modelKeys());

        Proxy::query()->whereKey($proxies->modelKeys())->delete();

        return response()->json([
            'deleted' => $proxies->count(),
            'unassigned' => $unassigned,
        ]);
    }

    /**
     * Release every account sitting behind these proxies.
     *
     * One statement rather than a save per account: a proxy can carry its whole
     * max_accounts, and a selection can carry two thousand proxies, so this is
     * the difference between one update and tens of thousands.
     *
     * That skips XAccountObserver, which keeps proxies.assigned_count in step
     * -- and here that is the point rather than an oversight, because the rows
     * whose counter it would fix are about to be deleted.
     *
     * Done explicitly even though the foreign key is nullOnDelete and would do
     * it anyway: the database would do it silently, and this has to be able to
     * say how many accounts it touched.
     *
     * @param  array<int, int>  $proxyIds
     */
    private function detach(array $proxyIds): int
    {
        if ($proxyIds === []) {
            return 0;
        }

        return XAccount::query()
            ->whereIn('proxy_id', $proxyIds)
            ->update(['proxy_id' => null]);
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
