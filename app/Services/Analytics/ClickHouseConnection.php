<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the ClickHouse HTTP client.
 *
 * ClickHouse is optional: when it is switched off (or unreachable) every call
 * degrades to a no-op and the app keeps publishing. Analytics going dark must
 * never take posting down with it.
 */
final class ClickHouseConnection
{
    private ?Client $client = null;

    private bool $failed = false;

    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && ! $this->failed;
    }

    public function client(): ?Client
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($this->client !== null) {
            return $this->client;
        }

        try {
            $client = new Client([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'username' => $this->config['username'],
                'password' => $this->config['password'],
            ]);

            $client->database($this->config['database']);
            $client->setTimeout((float) ($this->config['timeout'] ?? 10));
            $client->setConnectTimeOut((int) ($this->config['connect_timeout'] ?? 5));

            return $this->client = $client;
        } catch (\Throwable $e) {
            $this->degrade('connect', $e);

            return null;
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function insert(string $table, array $rows, array $columns): void
    {
        if ($rows === [] || ($client = $this->client()) === null) {
            return;
        }

        try {
            $client->insert($table, array_map(
                static fn (array $row): array => array_map(
                    static fn (string $column) => $row[$column] ?? null,
                    $columns,
                ),
                $rows,
            ), $columns);
        } catch (\Throwable $e) {
            $this->degrade('insert:'.$table, $e);
        }
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        if (($client = $this->client()) === null) {
            return [];
        }

        try {
            return $client->select($sql, $bindings)->rows();
        } catch (\Throwable $e) {
            $this->degrade('select', $e);

            return [];
        }
    }

    public function statement(string $sql): bool
    {
        if (($client = $this->client()) === null) {
            return false;
        }

        try {
            $client->write($sql);

            return true;
        } catch (\Throwable $e) {
            $this->degrade('statement', $e);

            return false;
        }
    }

    /**
     * Is the server reachable?
     *
     * Deliberately asks a client that is bound to no database. client() pins
     * itself to the configured database, so before the first migration that
     * check answered "unreachable" for a perfectly healthy server whose only
     * problem was that the database had not been created yet.
     */
    public function ping(): bool
    {
        $client = $this->rawClient();

        if ($client === null) {
            return false;
        }

        try {
            return (int) $client->select('SELECT 1 AS ok')->fetchOne('ok') === 1;
        } catch (\Throwable $e) {
            $this->degrade('ping', $e);

            return false;
        }
    }

    /**
     * Create the analytics database if it is missing.
     *
     * Has to run on the unbound client for the same reason as ping(): you
     * cannot create a database from inside it.
     */
    public function ensureDatabase(): bool
    {
        $client = $this->rawClient();

        if ($client === null) {
            return false;
        }

        try {
            $client->write('CREATE DATABASE IF NOT EXISTS '.$this->config['database']);

            return true;
        } catch (\Throwable $e) {
            $this->degrade('ensureDatabase', $e);

            return false;
        }
    }

    /** A client with no database selected, for server-level checks and DDL. */
    private function rawClient(): ?Client
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $client = new Client([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'username' => $this->config['username'],
                'password' => $this->config['password'],
            ]);

            $client->setTimeout((float) ($this->config['timeout'] ?? 10));
            $client->setConnectTimeOut((int) ($this->config['connect_timeout'] ?? 5));

            return $client;
        } catch (\Throwable $e) {
            $this->degrade('connect', $e);

            return null;
        }
    }

    /**
     * Trip the breaker for the rest of the request/job rather than retrying a
     * dead server on every row.
     */
    private function degrade(string $operation, \Throwable $e): void
    {
        $this->failed = true;

        Log::channel('xpost')->warning('ClickHouse unavailable, analytics degraded', [
            'operation' => $operation,
            'error' => $e->getMessage(),
        ]);
    }
}
