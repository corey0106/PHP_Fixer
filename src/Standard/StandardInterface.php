<?php

namespace TwigCsFixer\Standard;

use TwigCsFixer\Sniff\SniffInterface;

/**
 * Interface for all standard.
 */
interface StandardInterface
{
    /**
     * @return SniffInterface[]
     */
    public function getSniffs(): array;
}
