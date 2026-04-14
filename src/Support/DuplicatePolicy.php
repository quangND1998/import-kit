<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Support;

final class DuplicatePolicy
{
    public const UPDATE = 'update';
    public const SKIP = 'skip';
    public const FAIL = 'fail';
}
