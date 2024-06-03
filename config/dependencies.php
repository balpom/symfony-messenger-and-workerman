<?php

declare(strict_types=1);

use Balpom\SymfonyMessengerWorkerman\SymfonyWorker;
use Balpom\SymfonyMessengerWorkerman\SmsNotification;
use Balpom\SymfonyMessengerWorkerman\SmsNotificationHandler;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceiver;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

return [
    CacheItemPoolInterface::class => function () {
        return new FilesystemAdapter('test_namespace', 10, __DIR__ . '/../var/cache');
    },
    'options' => [
        'limit' => null, // Limit the number of received messages
        'failure-limit' => null, // The number of failed messages the worker can consume
        'memory-limit' => null, // The memory limit the worker can consume
        'time-limit' => null, // The time limit in seconds the worker can handle new messages
        'sleep' => null, // Seconds to sleep before asking for new messages after no messages were found
        'bus' => null, // Name of the bus to which received messages should be dispatched (if not passed, bus is determined automatically)
        'queues' => null, // Limit receivers to only consume from the specified queues
        'no-reset' => null, // Do not reset container services after each message
    ],
    // Need for DoctrineTransport autowiring.
    SerializerInterface::class => function () {
        return new PhpSerializer;
    },
    Connection::class => function () {
        $dsnParser = new DsnParser();
        $connectionParams = $dsnParser->parse('pdo-sqlite:////' . __DIR__ . '/../data/queue.sqlite');
        $connection = DriverManager::getConnection($connectionParams);
        $configuration = []; // See DEFAULT_OPTIONS in DoctrineConnection.
        return new Connection($configuration, $connection);
    },
    'doctrine-async' => function (ContainerInterface $container) {
        return new DoctrineReceiver($container->get(Connection::class));
    },
    'message-bus' => function (ContainerInterface $container) {
        $handler = new SmsNotificationHandler($container);
        return new MessageBus([
    new SendMessageMiddleware(
            new SendersLocator([
                SmsNotification::class => [DoctrineTransport::class]
                    ], $container)
    ),
    new HandleMessageMiddleware(
            new HandlersLocator([
                SmsNotification::class => [$handler],
                    ])
    )
        ]);
    },
    RoutableMessageBus::class => function (ContainerInterface $container) {
        return new RoutableMessageBus($container);
    },
    EventDispatcherInterface::class => function () {
        return new EventDispatcher();
    },
    SymfonyWorker::class => function (ContainerInterface $container) {
        $receiverNames = ['doctrine-async'];
        $routableBus = $container->get(RoutableMessageBus::class);
        $eventDispatcher = $container->get(EventDispatcherInterface::class);
        $cacheItemPool = $container->get(CacheItemPoolInterface::class);
        $options = $container->get('options');
        return new SymfonyWorker($container, $receiverNames, $routableBus, $eventDispatcher, $cacheItemPool, $options);
    },
];
