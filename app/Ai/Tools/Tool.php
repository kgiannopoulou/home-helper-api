<?php

namespace App\Ai\Tools;

/**
 * Something the assistant may call (docs/assistant.md#tools).
 *
 * A tool declares what it does (description and JSON schema, for the model),
 * how its input is checked (Laravel validation rules, run by the registry
 * before anything else), and whether it changes data. A tool that writes
 * never runs straight from the model: the registry turns the call into a
 * pending action, and it runs when the person presses Confirm.
 */
abstract class Tool
{
    abstract public function name(): string;

    /** For the model: what it returns and when to use it */
    abstract public function description(): string;

    /**
     * JSON schema of the input, for the model. Keep it in step with rules().
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    /**
     * Laravel validation rules for the input; the registry rejects anything else.
     *
     * @return array<string, mixed>
     */
    public function rules(ToolContext $context): array
    {
        return [];
    }

    /** Changes data: needs the person's confirmation and the module's create/update permission */
    public function writes(): bool
    {
        return false;
    }

    /** Extra permission check beyond household membership (e.g. a policy) */
    public function authorize(ToolContext $context): bool
    {
        return true;
    }

    /**
     * For a write: what will happen, in words, on the Confirm button.
     *
     * @param  array<string, mixed>  $input  validated
     */
    public function describe(array $input, ToolContext $context): string
    {
        return $this->name();
    }

    /**
     * Do it. The result goes back to the model as JSON, so keep it small and
     * readable: names, dates and amounts, not whole rows.
     *
     * @param  array<string, mixed>  $input  validated
     * @return array<string, mixed>
     */
    abstract public function run(array $input, ToolContext $context): array;

    /** For people: "Looked at the shopping list" */
    abstract public function label(): string;
}
