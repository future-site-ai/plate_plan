<?php

declare(strict_types=1);

namespace App\AI;

use Closure;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * A Neuron tool whose behavior is a closure, so the agent's tools can be declared
 * in one place without a class per tool. The closure receives the tool inputs as
 * an associative array.
 */
class CallbackTool extends Tool
{
    /**
     * @param ToolPropertyInterface[] $properties
     */
    public function __construct(
        string $name,
        string $description,
        array $properties,
        protected Closure $handler,
    ) {
        $this->name = $name;
        $this->description = $description;
        foreach ($properties as $property) {
            $this->addProperty($property);
        }
    }

    public function __invoke(mixed ...$inputs): mixed
    {
        return ($this->handler)($inputs);
    }
}
