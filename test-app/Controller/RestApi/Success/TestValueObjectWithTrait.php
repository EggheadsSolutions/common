<?php

declare(strict_types=1);

namespace TestApp\Controller;

use ArtSkills\Controller\Response\ApiResponse;

/**
 * @OA\Schema()
 */
class TestValueObjectWithTrait extends ApiResponse
{
    use TestTrait;

    /**
     * @OA\Property()
     * @var string
     */
    public string $propertyFromObject = 'testData';
}