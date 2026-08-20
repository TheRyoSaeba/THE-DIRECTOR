<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Safety guard. The feature suite uses RefreshDatabase, which truncates
        // every table on whichever connection it is handed. Refuse to run unless
        // the target is clearly a throwaway database — its name must contain
        // "test", or ALLOW_DESTRUCTIVE_TESTS must be set deliberately.
        //
        // phpunit.xml intentionally leaves DB_CONNECTION/DB_DATABASE unset so
        // that .env.testing is the single source of truth for where tests write.
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $looksLikeTestDatabase = str_contains(strtolower($database), 'test');
        $override = filter_var(env('ALLOW_DESTRUCTIVE_TESTS', false), FILTER_VALIDATE_BOOL);

        if (! $looksLikeTestDatabase && ! $override) {
            $this->fail(
                "SAFETY ABORT: refusing to run destructive tests against database '{$database}'. "
                . 'Point .env.testing at a throwaway database whose name contains "test", '
                . 'or set ALLOW_DESTRUCTIVE_TESTS=true if you are certain.'
            );
        }
    }
}
