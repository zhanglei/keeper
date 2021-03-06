<?php
/**
 * ProcessManager.php
 *
 * @copyright Chongyi <xpz3847878@163.com>
 * @link      https://insp.top
 */

namespace Dybasedev\Keeper\Process;

use Closure;
use Dybasedev\Keeper\Process\Exceptions\OperationRejectedException;
use Dybasedev\Keeper\Process\Exceptions\SingletonException;
use Swoole\Process as SwProcess;

/**
 * Class ProcessManager
 *
 * 标准主管理进程
 *
 * @package Dybasedev\Keeper\Process
 */
abstract class ProcessManager extends Process
{
    use ProcessIdFileTrait;

    /**
     * @var bool 守护进程开关
     */
    protected $daemon = false;

    /**
     * @var ProcessController 子进程控制器
     */
    private $processController = null;

    /**
     * @var bool
     */
    private $running = false;

    /**
     * @var array|Closure[]
     */
    private $terminating = [];

    /**
     * @var array|Closure[]
     */
    private $prepared = [];

    /**
     * @inheritDoc
     */
    public function process()
    {
        try {
            $this->singleGuarantee();

            if ($this->daemon) {
                $this->daemon();
            }

            $this->freshProcessIdFile();

            SwProcess::signal(SIGTERM, $this->onTerminating());
            SwProcess::signal(SIGUSR1, $this->onReopen());
            SwProcess::signal(SIGUSR2, $this->onReload());

            $this->onPreparing();

            foreach ($this->prepared as $callback) {
                $callback();
            }

            $this->running = true;
        } catch (SingletonException $e) {
            fwrite(STDERR, "Have running instance (PID: {$e->runningInstanceProcessId}). Nothing to do.\n");
            exit(1);
        }
    }

    abstract protected function onPreparing();

    /**
     * 注册一个子进程实例
     *
     * @param Process $process
     *
     * @return $this
     */
    public function registerChildProcess(Process $process)
    {
        if (is_null($this->processController)) {
            $this->processController = new ProcessController($this);
            SwProcess::signal(SIGCHLD, $this->processController->getChildrenProcessShutdownHandler());

            $this->pushPreparedCallback(function () {
                $this->processController->bootstrap();
            });

            $this->pushTerminatingCallback(function () {
                $this->processController->terminate();
            });

            $this->processController->terminated(function () {
                $this->clearProcessIdFile();
            });
        }

        $this->processController->registerProcess($process);

        return $this;
    }

    /**
     * 压入一个预处理后的回调
     *
     * @param Closure $callback
     *
     * @return $this
     */
    protected function pushPreparedCallback(Closure $callback)
    {
        $this->prepared[] = $callback;
        return $this;
    }

    /**
     * 压入一个终止时的回调
     *
     * @param Closure $callback
     *
     * @return $this
     */
    protected function pushTerminatingCallback(Closure $callback)
    {
        $this->terminating[] = $callback;
        return $this;
    }

    /**
     * 终止事件
     *
     * @return Closure
     */
    private function onTerminating()
    {
        return function () {
            if ($this->running) {
                foreach ($this->terminating as $callback) {
                    $callback();
                }

                if (!$this->processController) {
                    $this->clearProcessIdFile();
                }

                $this->running = false;
            }
        };
    }

    /**
     * 重新加载事件
     *
     * 默认该操作会向所有子进程发起 USR1 信号，根据子进程注册参数会有差异
     *
     * @return Closure
     */
    private function onReload()
    {
        return function () {
            $this->processController->reload();
        };
    }

    /**
     * 重新加载子进程事件
     *
     * 该操作会将所有子进程关闭并重新开启（或根据配置发起信号）
     *
     * @return Closure
     */
    private function onReopen()
    {
        return function () {
            $this->processController->reopen();
        };
    }

    /**
     * @param bool $daemon
     *
     * @return $this
     */
    public function setDaemon($daemon)
    {
        $this->daemon = $daemon;

        return $this;
    }

    /**
     * 重启
     *
     * @param bool $force
     */
    public function restart($force = false)
    {
        try {
            $this->singleGuarantee();
            $this->clearProcessIdFile();

            if (!$force) {
                throw new OperationRejectedException();
            }

            $this->run();
        } catch (SingletonException $e) {
            $this->shutdownRunningInstance = true;
            $this->restart(true);
        } catch (OperationRejectedException $e) {
            fwrite(STDERR, "No instance can be restart.\n");
            exit(2);
        }
    }

    /**
     * 停止
     */
    public function stop()
    {
        $runningProcessId = $this->getProcessIdFromFile();

        if ($runningProcessId === false) {
            fwrite(STDERR, "No running instance\n");
            exit(4);
        }

        SwProcess::kill($runningProcessId);
    }
}