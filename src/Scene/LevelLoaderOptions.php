<?php

namespace PHPLife\Scene;

class LevelLoaderOptions
{
    public ?string $serialisationFilterComponent = null;

    public function __construct(
        public readonly string $levelFilePath
    )
    {
        
    }
}
