<?php
/** @noinspection PhpUndefinedVariableInspection */
declare(strict_types=1);

use Sabre\Event\Emitter;

/**
 * STAGE1
 * used extend / modify the default container dependencies
 */

$container->set('events', function () {
    return new Emitter();
});


