<?php

namespace Ssdk\Jobmanage\Traits;

use Swoole\Process;

trait ProcessPoolTrait
{
    protected $action;
    protected $pid_file;
    protected $pid;
    
    protected function initAction()
    {
        $this->action = $this->argument('action');
        if(!in_array($this->action, [
            'start',
            'stop',
            'restart',
        ])){
            $this->error('Unexpected argument "' . $this->action . '".');
            exit(1);
        }
    }
    
    /**
     * Run action.
     */
    protected function runAction()
    {
        $this->detectSwoole();
        
        $this->{$this->action}();
    }
    
    
    /**
     * Extension swoole is required.
     */
    protected function detectSwoole()
    {
        if (! extension_loaded('swoole')) {
            $this->error('Extension swoole is required!');
            
            exit(1);
        }
    }
    
    /**
     * Gets pid file path.
     *
     * @return string
     */
    protected function getPidFile()
    {
        return $this->pid_file;
    }
    
    /**
     * Create pid file.
     */
    protected function createPidFile()
    {
        $pidFile = $this->getPidFile();
        $pid = getmypid();
        
        file_put_contents($pidFile, $pid);
    }
    
    /**
     * Clear APC or OPCache.
     */
    protected function clearCache()
    {
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }
        
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }
    
    /**
     * Stop swoole_process_pool.
     */
    protected function stop()
    {

        $pid = $this->getPid();
        
        if (!$this->isRunning($pid)) {
            $this->error("Failed! There is no swoole_process_pool process running.");
            exit(1);
        }
        
        $this->info('Stopping swoole_process_pool...');
        
        $isRunning = $this->killProcess($pid, SIGTERM, 15);
        
        if ($isRunning) {
            $this->error('Unable to stop the swoole_process_pool process.');
            exit(1);
        }
        
        // I don't known why Swoole didn't trigger "onShutdown" after sending SIGTERM.
        // So we should manually remove the pid file.
        $this->removePidFile();
        
        $this->info('> success');
    }
    
    /**
     * Restart swoole http server.
     */
    protected function restart()
    {
        $pid = $this->getPid();
        
        if ($this->isRunning($pid)) {
            $this->stop();
        }
        
        $this->start();
    }
    
    /**
     * Get pid.
     *
     * @return int|null
     */
    protected function getPid()
    {
        if ($this->pid) {
            return $this->pid;
        }
        
        $pid = null;
        $path = $this->getPidPath();
        
        if (file_exists($path)) {
            $pid = (int)file_get_contents($path);
            
            if (!$pid) {
                $this->removePidFile();
            } else {
                $this->pid = $pid;
            }
        }
        
        return $this->pid;
    }
    
    /**
     * Get Pid file path.
     *
     * @return string
     */
    protected function getPidPath()
    {
        return $this->pid_file;
    }
    
    /**
     * Remove Pid file.
     */
    protected function removePidFile()
    {
        if (file_exists($this->getPidPath())) {
            unlink($this->getPidPath());
        }
    }
    
    /**
     * If Swoole process is running.
     *
     * @param  int $pid
     * @return bool
     */
    protected function isRunning($pid)
    {
        if (!$pid) {
            return false;
        }
        
        Process::kill($pid, 0);
        
        return !swoole_errno();
    }
    
    /**
     * Kill process.
     *
     * @param  int $pid
     * @param  int $sig
     * @param  int $wait
     * @return bool
     */
    protected function killProcess($pid, $sig, $wait = 0)
    {
        Process::kill($pid, $sig);
        
        if ($wait) {
            $start = time();
            
            do {
                if (!$this->isRunning($pid)) {
                    break;
                }
                
                usleep(100000);
            } while (time() < $start + $wait);
        }
        
        return $this->isRunning($pid);
    }
}