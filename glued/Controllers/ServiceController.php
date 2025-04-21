<?php

declare(strict_types=1);

namespace Glued\Controllers;

use Glued\Lib\Sql;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Glued\Lib\Controllers\AbstractService;

class ServiceController extends AbstractService
{

    protected Validator $validator;

    public function __construct(ContainerInterface $container)
    {
        $this->validator = new Validator();
        $resolver = $this->validator->loader()->resolver();
        $resolver->registerPrefix('file:///mail/',__ROOT__ . '/glued/Config/Schemas');
        parent::__construct($container);
    }


}


