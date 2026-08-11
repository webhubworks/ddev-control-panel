<?php

namespace App\Exceptions;

use RuntimeException;

class DdevBinaryNotFoundException extends RuntimeException
{
    /**
     * @param  list<string>  $searchedPaths
     */
    public static function afterSearching(array $searchedPaths): self
    {
        return new self(
            'Could not find the `ddev` executable. Searched: '.implode(', ', $searchedPaths)
            .'. Set DDEV_BINARY_PATH in your .env if ddev lives somewhere else.'
        );
    }
}
