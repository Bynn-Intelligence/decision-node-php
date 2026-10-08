<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * The models list entries this client has read, by model name, so the local
 * checks can use the limits only the list states. Empty until `models()` is
 * called; a limit the client does not hold is left to the server.
 *
 * @internal
 */
final class Held
{
    /** @var array<string, array<string, mixed>> */
    private array $models = [];

    /**
     * @param list<array<array-key, mixed>> $entries
     */
    public function keep(array $entries): void
    {
        $models = [];
        foreach ($entries as $entry) {
            if (is_string($entry['name'] ?? null)) {
                /** @var array<string, mixed> $entry */
                $models[$entry['name']] = $entry;
            }
        }
        $this->models = $models;
    }

    /**
     * The held entry of a model, with the name the request used under
     * `model`, or []. A model is found by its entry's name, else by the entry
     * that states the name as its `alias` or its pinned `version`; a name the
     * held list does not state holds nothing.
     *
     * @return array<string, mixed>
     */
    public function limits(string $model): array
    {
        $entry = $this->models[$model] ?? null;
        if ($entry === null) {
            foreach ($this->models as $candidate) {
                if (($candidate['alias'] ?? null) === $model || ($candidate['version'] ?? null) === $model) {
                    $entry = $candidate;
                    break;
                }
            }
        }

        return $entry === null ? [] : ['model' => $model] + $entry;
    }
}
