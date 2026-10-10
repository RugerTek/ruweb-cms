<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class RevisionConflict extends HttpException
{
    public function __construct(public readonly string $expected, public readonly string $current)
    {
        parent::__construct(409, 'Revision conflict: read the current record, merge your changes and retry with its revision.');
    }

    public function diagnostic(): array
    {
        return ['status' => 409, 'cause' => 'revision_conflict', 'field' => 'expected_revision', 'expected_revision' => $this->expected, 'current_revision' => $this->current, 'recovery' => 'Read current revision, merge changes and retry.'];
    }
}
