<?php

declare(strict_types=1);

namespace Balpom\SymfonyMessengerWorkerman;

use Psr\Container\ContainerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\Exception\RuntimeException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\WorkerMetadata;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SymfonyWorker
{
    private ?Worker $worker;
    private ?string $output;

    public function __construct(
            private ContainerInterface $receiverLocator,
            private array $receiverNames,
            private RoutableMessageBus $routableBus,
            private EventDispatcherInterface $eventDispatcher,
            private CacheItemPoolInterface $restartSignalCachePool,
            private array $options = [],
            private ?LoggerInterface $logger = null,
            private ?ResetServicesListener $resetServicesListener = null,
            private ?ContainerInterface $rateLimiterLocator = null
    )
    {
        $this->eventDispatcher->addSubscriber(new StopWorkerOnRestartSignalListener($restartSignalCachePool));
        $this->worker = null;
    }

    public function run(): int
    {
        if (null === $this->worker) {
            $this->init();
        }

        $options = [
            'sleep' => $this->getOption('sleep') * 1000000,
        ];
        if ($queues = $this->getOption('queues')) {
            $options['queues'] = $queues;
        }

        try {
            echo $this->output;
            $this->worker->run($options);
        } finally {
            $this->worker = null;
        }

        return 0;
    }

    public function stopWorkers(): int
    {
        $cacheItem = $this->restartSignalCachePool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        $cacheItem->set(microtime(true));
        $this->restartSignalCachePool->save($cacheItem);

        echo 'Signal successfully sent to stop any running workers.' . PHP_EOL;

        return 0;
    }

    public function getMetadata(): WorkerMetadata
    {
        if (null === $this->worker) {
            $this->init();
        }

        return $this->worker->getMetadata();
    }

    private function init(): void
    {
        $this->output = '';
        $receivers = [];
        $rateLimiters = [];
        foreach ($this->receiverNames as $receiverName) {
            if (!$this->receiverLocator->has($receiverName)) {
                $message = sprintf('The receiver "%s" does not exist.', $receiverName);
                if ($this->receiverNames) {
                    $message .= sprintf(' Valid receivers are: %s.', implode(', ', $this->receiverNames));
                }

                throw new RuntimeException($message);
            }

            $receivers[$receiverName] = $this->receiverLocator->get($receiverName);
            if ($this->rateLimiterLocator?->has($receiverName)) {
                $rateLimiters[$receiverName] = $this->rateLimiterLocator->get($receiverName);
            }
        }

        if (null !== $this->resetServicesListener && !$this->getOption('no-reset')) {
            $this->eventDispatcher->addSubscriber($this->resetServicesListener);
        }

        $stopsWhen = [];
        if (null !== $limit = $this->getOption('limit')) {
            if (!is_numeric($limit) || 0 >= $limit) {
                throw new InvalidOptionException(sprintf('Option "limit" must be a positive integer, "%s" passed.', $limit));
            }

            $stopsWhen[] = "processed {$limit} messages";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $this->logger));
        }

        if ($failureLimit = $this->getOption('failure-limit')) {
            $stopsWhen[] = "reached {$failureLimit} failed messages";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnFailureLimitListener($failureLimit, $this->logger));
        }

        if ($memoryLimit = $this->getOption('memory-limit')) {
            $stopsWhen[] = "exceeded {$memoryLimit} of memory";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener($this->convertToBytes($memoryLimit), $this->logger));
        }

        if (null !== $timeLimit = $this->getOption('time-limit')) {
            if (!is_numeric($timeLimit) || 0 >= $timeLimit) {
                throw new InvalidOptionException(sprintf('Option "time-limit" must be a positive integer, "%s" passed.', $timeLimit));
            }

            $stopsWhen[] = "been running for {$timeLimit}s";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($timeLimit, $this->logger));
        }

        $stopsWhen[] = 'received a stop signal';

        $this->output .= sprintf('Consuming messages from transport%s "%s".', \count($receivers) > 1 ? 's' : '', implode(', ', $this->receiverNames)) . PHP_EOL;

        if ($stopsWhen) {
            $last = array_pop($stopsWhen);
            $stopsWhen = ($stopsWhen ? implode(', ', $stopsWhen) . ' or ' : '') . $last;
            $this->output .= "The worker will automatically exit once it has {$stopsWhen}." . PHP_EOL;
        }

        $this->output .= 'Quit the worker with CONTROL-C.' . PHP_EOL;

        $bus = $this->getOption('bus') ? $this->routableBus->getMessageBus($this->getOption('bus')) : $this->routableBus;

        $this->worker = new Worker($receivers, $bus, $this->eventDispatcher, $this->logger, $rateLimiters);
    }

    private function getOption(string $name): mixed
    {
        return isset($this->options[$name]) && !empty($this->options[$name]) ? $this->options[$name] : null;
    }

    private function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = strtolower($memoryLimit);
        $max = ltrim($memoryLimit, '+');
        if (str_starts_with($max, '0x')) {
            $max = \intval($max, 16);
        } elseif (str_starts_with($max, '0')) {
            $max = \intval($max, 8);
        } else {
            $max = (int) $max;
        }

        switch (substr(rtrim($memoryLimit, 'b'), -1)) {
            case 't': $max *= 1024;
            // no break
            case 'g': $max *= 1024;
            // no break
            case 'm': $max *= 1024;
            // no break
            case 'k': $max *= 1024;
        }

        return $max;
    }
}
