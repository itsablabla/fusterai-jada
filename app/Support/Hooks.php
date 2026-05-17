<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class Hooks
{
    protected static array $actions = [];

    protected static array $filters = [];

    /** Set once by AppServiceProvider after all providers have booted. */
    protected static bool $booted = false;

    public static function markBooted(): void
    {
        static::$booted = true;
    }

    /** Register a callback for an action hook */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        if (static::$booted) {
            // In Octane workers the process stays alive between requests.
            // Registering hooks after boot causes them to accumulate indefinitely.
            Log::warning("Hooks::addAction [{$hook}] registered after application boot — unsafe in Octane.");
        }

        static::$actions[$hook][$priority][] = $callback;
    }

    /**
     * Fire all callbacks registered for an action.
     *
     * Each listener is isolated: an exception in one never stops the others.
     */
    public static function doAction(string $hook, mixed ...$args): void
    {
        if (empty(static::$actions[$hook])) {
            return;
        }

        ksort(static::$actions[$hook]);
        foreach (static::$actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $callback(...$args);
                } catch (\Throwable $e) {
                    Log::error("Hook action [{$hook}] threw an exception: ".$e->getMessage(), [
                        'hook' => $hook,
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    /** Register a callback for a filter hook */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        if (static::$booted) {
            Log::warning("Hooks::addFilter [{$hook}] registered after application boot — unsafe in Octane.");
        }

        static::$filters[$hook][$priority][] = $callback;
    }

    /**
     * Apply all filter callbacks to a value.
     *
     * Each filter is isolated: an exception keeps the last good value and
     * continues with the remaining filters.
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (empty(static::$filters[$hook])) {
            return $value;
        }

        ksort(static::$filters[$hook]);
        foreach (static::$filters[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $value = $callback($value, ...$args);
                } catch (\Throwable $e) {
                    Log::error("Hook filter [{$hook}] threw an exception: ".$e->getMessage(), [
                        'hook' => $hook,
                        'exception' => $e,
                    ]);
                    // Keep the last good value and continue
                }
            }
        }

        return $value;
    }

    /** Clear all hooks and booted state (tests only). */
    public static function reset(): void
    {
        static::$actions = [];
        static::$filters = [];
        static::$booted = false;
    }
}
