<?php

namespace App\Exceptions;

use RuntimeException;

class DockerBinaryNotFoundException extends RuntimeException
{
    /**
     * @param  list<string>  $searchedPaths
     */
    public static function afterSearching(array $searchedPaths): self
    {
        return new self(
            'Could not find the `docker` executable. Searched: '.implode(', ', $searchedPaths)
            .'. Set DOCKER_BINARY_PATH in your .env if docker lives somewhere else.'
        );
    }
}
