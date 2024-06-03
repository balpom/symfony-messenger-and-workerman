# symfony-messenger-and-workerman
## A simple example showing how to use Workerman and a Symfony Messenger with queues.

Not finding a simple and complete example of using the [Symfony Messenger](https://github.com/symfony/messenger/), I create it myself: [https://github.com/balpom/symfony-messenger-sample](https://github.com/balpom/symfony-messenger-sample).
It use Doctrine with sqlite database as Message Bus transport and for Workers running it use [Symfony Console](https://github.com/symfony/console/).

However, my inner perfectionist :-) does not like the idea, when manually open the console, run a script in it, etc.
I wanted to use a single command to open several consoles at once in the right amount, in which the worker's would already be working, and if one of the workers fell, the console was restarting itself (a set number of running consoles (scripts) was supported).

As a result, I created a new example, in which I completely abandoned the symfony/console component, and for Workers starting and for maintaining a set number of them, I use [Workerman](https://github.com/walkor/workerman) framework (https://github.com/walkor/workerman).

Everything was tested in Linux.

### Requirements 
- **PHP >= 8.1**

### Installation
#### Using composer (recommended)
```bash
composer create balpom/symfony-messenger-and-workerman
```

## How to use

Open console window. Run the command:
```bash
php bin/start
```
It starts three simple Worker, which imitate SMS sending. Now it is waiting for messages to be sent from the queue, which is still empty.

Run the command:
```bash
php tests/send.php
```
It runs a simple script that adds some several messages to the queue.
After this, in previously automatically opened consoles you may see, how several Workers "sending" SMS.

Run the command:
```bash
php tests/sendmany.php
```
It runs a simple script that adds many several messages to the queue.

Run the command:
```bash
php bin/reload
```
It reloads all Workers. After reloading all Workers continue execution.

Run the command:
```bash
php bin/stop
```
It stop all Workers executions.


## Specificity
Created by me SymfonyWorker class is a wrapper for Symfony\Component\Messenger\Worker and built on the base of 
the ConsumeMessagesCommand class (Symfony\Component\Messenger\Command\ConsumeMessagesCommand).

For Workerman\Worker running I created very simple script bin/runner:
```php
namespace Balpom\SymfonyMessengerWorkerman;
use Workerman\Worker;
use Symfony\Component\Process\Process;

Worker::$daemonize = true; // Always run as daemon.
$worker = new Worker();
$worker->count = 3;        // Numbef of Workers.

$worker->onWorkerStart = function (Worker $worker) {
    //$process = new Process(['php', 'bin/start_worker']);
    // SymfonyWorkerFactory::getWorker(__DIR__ . '/../config/dependencies.php')->run();
    $process = new Process(['gnome-terminal', '--', 'php', 'bin/start_worker']);
    $process->run();
};
Worker::runAll();
```
It has line $process = new Process(\['gnome-terminal', '--', 'php', 'bin/start_worker'\]);
If you don't have *gnome-terminal* in your system, you must replace this line to the line, that runs the command "php bin/start_worker" for your terminal.

Also you may run, stop and reload workers directly from "bin/runner" script by this commands:
```bash
php bin/runner start
php bin/runner reload
php bin/runner stop
```
For current status checking try this command:
```bash
php bin/runner status
```

## Disadvantages

In this example for illustration I try start Symfony Workers from terminal console.

As practice showing, when Symfony Worker, launched from the terminal console, is shutting down, for some reason the terminal console does not restart and the corresponding php process remains hanging in memory (however, it completes its work on command php *bin/runner stop* or *php bin/stop*; command *php bin/reload* don't work properly).

Symfony Workers, that are not run from the terminal, work normally and managed by commands *bin/runner start*, *bin/runner stop* and *bin/runner reload*.
