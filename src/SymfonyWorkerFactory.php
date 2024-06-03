<?php

declare(strict_types=1);

namespace Balpom\SymfonyMessengerWorkerman;

use DI\ContainerBuilder;

class SymfonyWorkerFactory
{

    static public function getWorker(string $definitionsPath)
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions($definitionsPath);
        $container = $containerBuilder->build();

        return $container->get(SymfonyWorker::class);
    }
}
