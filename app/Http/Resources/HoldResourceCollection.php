<?php

namespace App\Http\Resources;

class HoldResourceCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = HoldResource::class;
}

