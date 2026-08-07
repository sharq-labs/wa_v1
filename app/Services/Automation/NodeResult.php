<?php

namespace App\Services\Automation;

/**
 * Outcome of a single node execution.
 */
class NodeResult
{
    public const CONTINUE = 'continue';

    public const WAIT = 'wait';

    public const STOP = 'stop';

    public const FAIL = 'fail';

    public const GOTO = 'goto';

    protected function __construct(
        public readonly string $type,
        public readonly string $handle = 'next',
        public readonly ?string $gotoNodeId = null,
        public readonly ?string $error = null,
        public readonly array $output = [],
    ) {}

    /** Continue along the edge with the given source handle. */
    public static function next(string $handle = 'next', array $output = []): self
    {
        return new self(self::CONTINUE, $handle, null, null, $output);
    }

    /** Persist a wait; execution resumes later (reply/delay/until). */
    public static function wait(array $output = []): self
    {
        return new self(self::WAIT, 'next', null, null, $output);
    }

    /** Stop the run successfully. */
    public static function stop(array $output = []): self
    {
        return new self(self::STOP, 'next', null, null, $output);
    }

    public static function fail(string $error, array $output = []): self
    {
        return new self(self::FAIL, 'next', null, $error, $output);
    }

    /** Jump directly to a node id (Go To Node). */
    public static function goto(string $nodeId, array $output = []): self
    {
        return new self(self::GOTO, 'next', $nodeId, null, $output);
    }
}
