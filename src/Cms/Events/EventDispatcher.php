<?php

declare(strict_types=1);

namespace Rkn\Cms\Events;

final class EventDispatcher
{
    /** @var array<string, list<callable(Event): void>> */
    private array $listeners = [];

    /**
     * Register a listener for an event.
     *
     * @param callable(Event): void $listener
     */
    public function listen(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     */
    public function dispatch(Event $event): Event
    {
        $listeners = $this->listeners[$event->name()] ?? [];

        foreach ($listeners as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        // Also dispatch to wildcard listeners
        $wildcardListeners = $this->listeners['*'] ?? [];
        foreach ($wildcardListeners as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }

    /**
     * Check if an event has listeners.
     */
    public function hasListeners(string $eventName): bool
    {
        return !empty($this->listeners[$eventName]) || !empty($this->listeners['*']);
    }

    /**
     * Remove all listeners for an event. If no event specified, removes all.
     */
    public function removeListeners(?string $eventName = null): void
    {
        if ($eventName === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$eventName]);
        }
    }
}
