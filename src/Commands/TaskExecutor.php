<?php
namespace Ssdk\Jobmanage\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Ssdk\Jobmanage\Services\JobExecutor;
use Ssdk\Jobmanage\Traits\ProcessPoolTrait;

class TaskExecutor extends Command
{

    use ProcessPoolTrait;

    /**
     * 控制台命令:启动调度中心任务执行器
     *
     * @var string
     */
    protected $signature = 'jobmanage:taskexecutor {action : start|stop|restart}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'the Task Executor of Job Manage Center';

    /**
     * 任务分组
     *
     * @var string
     */
    protected $job_group = '';

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->addOption('pid_file', 'pfi', InputOption::VALUE_REQUIRED, 'Swoole server pid file.', env('SCHEDULE_PARSER_PID_FILE', '/tmp/schedule_parser_001.pid'))->addOption('job_group', 'jg', InputOption::VALUE_OPTIONAL, 'Job Execute Group', '');
    }

    public function handle()
    {
        $this->pid_file = $this->input->getOption('pid_file');
        $this->job_group = $this->input->getOption('job_group');

        $this->initAction();
        $this->runAction();
    }

    /**
     * Run swoole_process_pool.
     */
    protected function start()
    {
        $this->clearCache();
        $this->createPidFile();

        if ($this->isRunning($this->getPid())) {
            $this->error('Failed! swoole_task_executor process is already running.');
            exit(1);
        }

        $this->info('Starting swoole task executor...');

        $this->info('> (You can run this command to ensure the ' . 'swoole_process_pool process is running: ps aux|grep "swoole")');

        $workerNum = env('SCHEDULE_TASKEXECUTOR_WORKERNUM', 10);
        $tick_time = env('SCHEDULE_TASKEXECUTOR_TICKTIME', 5000);

        $job_group = $this->job_group;
        $pool = new \Swoole\Process\Pool($workerNum);

        $pool->on("WorkerStart", function ($pool, $workerId) use ($tick_time, $job_group) {
            echo "Worker#{$workerId} is started\n";

            while (true) {
                // 调用任务执行处理
                JobExecutor::executor($job_group);

                usleep($tick_time * 1000);
            }
        });

        $pool->on("WorkerStop", function ($pool, $workerId) {
            echo "Worker#{$workerId} is stopped\n";
        });

        $pool->start();
    }
}