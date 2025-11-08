<?php

namespace Shaf\LaravelDeployer\Support\Abstract;

abstract class Action
{
    /**
     * Execute the action
     *
     * @return mixed
     */
    abstract public function execute();

    /**
     * Static factory method for fluent execution
     *
     * @param mixed ...$args
     * @return mixed
     */
    public static function run(...$args)
    {
        $instance = new static(...$args);

        return $instance->execute();
    }
}
