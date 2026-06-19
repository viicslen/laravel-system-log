<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Contracts;

use Closure;
use Illuminate\Support\defer;

/**
 * Bridges ambient, request- or context-scoped state across the deferred write.
 *
 * The observer captures log payloads synchronously but writes them via
 * {@see defer()} — after the HTTP response is sent or the
 * console command exits. Any ambient state that the write depends on (an active
 * multi-tenant database connection, the current request, etc.) may have been
 * torn down by then. A host application implements this contract to snapshot
 * that state while it is still active and restore it around the deferred write.
 *
 * Configure an implementation via `system-log.scope`. When none is configured
 * the observer writes inline without any wrapping.
 */
interface ScopeBridge
{
    /**
     * Snapshot the ambient scope. Runs synchronously, while the originating
     * request/command context is still active.
     *
     * @return mixed an opaque token passed back to {@see restore()}
     */
    public function capture(): mixed;

    /**
     * Restore the snapshotted scope and run the deferred write within it.
     *
     * @param  mixed  $scope  the token returned by {@see capture()}
     * @param  Closure():void  $callback  the deferred database write
     */
    public function restore(mixed $scope, Closure $callback): void;
}
