<?php

namespace App\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiProblem extends HttpException
{
    public function __construct(int $status, public readonly string $problemCode)
    {
        parent::__construct($status);
    }
}
