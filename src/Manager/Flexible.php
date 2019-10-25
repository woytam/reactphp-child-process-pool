<?php

namespace WyriHaximus\React\ChildProcess\Pool\Manager;

use Evenement\EventEmitterTrait;
use React\EventLoop\LoopInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Message;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use WyriHaximus\React\ChildProcess\Messenger\ProcessUnexpectedEndException;
use WyriHaximus\React\ChildProcess\Pool\ManagerInterface;
use WyriHaximus\React\ChildProcess\Pool\Options;
use WyriHaximus\React\ChildProcess\Pool\ProcessCollectionInterface;
use WyriHaximus\React\ChildProcess\Pool\Worker;
use WyriHaximus\React\ChildProcess\Pool\WorkerInterface;

class Flexible implements ManagerInterface
{
    use EventEmitterTrait;

    /**
     * @var WorkerInterface[]
     */
    protected $workers = [];

    /**
     * @var ProcessCollectionInterface
     */
    protected $processCollection;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var array
     */
    protected $defaultOptions = [
        Options::MIN_SIZE => 0,
        Options::MAX_SIZE => 4,
    ];

    /**
     * @var int
     */
    protected $startingProcesses = 0;

    public function __construct(ProcessCollectionInterface $processCollection, LoopInterface $loop, array $options = [])
    {
        $this->processCollection = $processCollection;
        $this->loop = $loop;
        $this->options = array_merge($this->defaultOptions, $options);

        for ($i = 0; $i < $this->options[Options::MIN_SIZE]; $i++) {
            $this->spawn();
        }
    }

    protected function workerAvailable(WorkerInterface $worker)
    {
        $this->emit('ready', [$worker]);
    }

    protected function spawn()
    {
        $this->startingProcesses++;
        $current = $this->processCollection->current();
        $promise = $this->spawnAndGetMessenger($current);
        $promise->done(function (Messenger $messenger) {
            $worker = new Worker($messenger);
            $this->workers[] = $worker;
            $worker->on('done', function (WorkerInterface $worker) {
                $this->workerAvailable($worker);
            });
            $worker->on('terminating', function (WorkerInterface $worker) {
                foreach ($this->workers as $key => $value) {
                    if ($worker === $value) {
                        unset($this->workers[$key]);
                        break;
                    }
                }
            });
            $worker->on('message', function ($message) {
                $this->emit('message', [$message]);
            });
            $worker->on('error', function ($error) use ($worker) {
                if($error instanceof ProcessUnexpectedEndException){
                    $worker->terminate();
                    $this->ping();
                }
                $this->emit('error', [$error]);
            });
            $this->workerAvailable($worker);
            $this->startingProcesses--;
        }, function () {
            $this->ping();
        });

        $this->processCollection->next();
        if (!$this->processCollection->valid()) {
            $this->processCollection->rewind();
        }
    }

    protected function spawnAndGetMessenger(callable $current)
    {
        return $current($this->loop, $this->options)->then(function ($timeoutOrMessenger) use ($current) {
            if ($timeoutOrMessenger instanceof Messenger) {
                return \React\Promise\resolve($timeoutOrMessenger);
            }

            return $this->spawnAndGetMessenger($current);
        });
    }

    public function ping()
    {
        if (count($this->workers) + $this->startingProcesses < $this->options[Options::MIN_SIZE]) {
            for($i = count($this->workers) + $this->startingProcesses; $i <= $this->options[Options::MIN_SIZE]; $++){
                $this->spawn();
            }
            return;
        }
        
        foreach ($this->workers as $worker) {
            if (!$worker->isBusy()) {
                $this->workerAvailable($worker);
                return;
            }
        }

        if (count($this->workers) + $this->startingProcesses < $this->options[Options::MAX_SIZE]) {
            $this->spawn();
        }
    }

    public function message(Message $message)
    {
        foreach ($this->workers as $worker) {
            $worker->message($message);
        }
    }

    public function terminate()
    {
        $promises = [];

        foreach ($this->workers as $worker) {
            $promises[] = $worker->terminate();
        }

        return \React\Promise\all($promises);
    }

    public function getTotal()
    {
        return count($this->workers);
    }
    
    public function info()
    {
        $count = count($this->workers);
        $busy = 0;
        foreach ($this->workers as $worker) {
            if ($worker->isBusy()) {
                $busy++;
            }
        }
        return [
            'total' => $count,
            'busy' => $busy,
            'idle' => $count - $busy,
        ];
    }
}
