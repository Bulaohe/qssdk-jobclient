<?php
namespace Ssdk\Jobmanage\Services;

use Ssdk\Jobmanage\Models\JobTask;
use Ssdk\Jobmanage\Models\JobConfig;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;
use Ssdk\Jobmanage\Models\JobTaskRecord;

class JobExecutor
{

    /**
     * 任务解析,生成任务列表
     *
     * @return boolean
     */
    public static function executor($job_group = '')
    {
        /**
         * 按条件查询
         *
         * 1. task_status 未执行
         * 2. sched_time 计划到期
         * 3. 任务未取消
         * 4. job_group 任务分组|项目分组
         */
        $task = JobTask::where('task_status', 0)->where('sched_time', '<=', date('Y-m-d H:i:s'))
            ->where('is_cancel', 0)
            ->where('job_group', $job_group)
            ->orderBy('id', 'ASC')
            ->first();

        if (! empty($task)) { // endif 条件查询task，第一次触发的优先执行
            self::transTask($task);
        } else { // 执行允许失败重试的任务
            $task = JobTask::where('task_status', 4)->where('sched_time', '<=', date('Y-m-d H:i:s'))
                ->where('is_cancel', 0)
                ->where('requests_recovery', 1)
                ->whereRaw('repeat_count < max_retry')
                ->where('job_group', $job_group)
                ->orderBy('id', 'ASC')
                ->first();

            if (! empty($task)) {
                self::transTask($task);
            }
        }

        return true;
    }

    private static function transTask($task)
    {
        try {
            DB::beginTransaction();

            // 请求排它锁
            $task_u = JobTask::where('id', $task['id'])->whereIn('task_status', [
                0,
                4
            ])
                ->where('is_cancel', 0)
                ->lockForUpdate()
                ->first();

            if ($task_u) { // 如果可以拿到排它锁，执行任务
                if ($task_u['task_status'] == 0 || $task_u['task_status'] == 4) { // 第一次触发执行或者是失败重试
                    $update = [
                        'task_status' => 1, // 触发执行
                        'started_at' => date('Y-m-d H:i:s')
                    ];
                    $t_created_at = date('Y-m-d H:i:s'); // 记录任务开始执行时间

                    $job = JobConfig::where('id', $task_u['job_id'])->first();
                    try {
                        if ($job['exec_type'] == 1) { // curl
                            $client = new Client();

                            // 测试用，上级前需要注释掉
                            // $job['exec_data'] = 'http://www.baidu.com';

                            // 为执行器添加Task ID
                            if (strpos($job['exec_data'], '?') !== false) {
                                $job['exec_data'] .= '&execjobtaskid=' . $task_u['id'];
                            } else {
                                $job['exec_data'] .= '?execjobtaskid=' . $task_u['id'];
                            }

                            // Send an asynchronous request.
                            $request = new \GuzzleHttp\Psr7\Request('GET', $job['exec_data']);
                            $promise = $client->sendAsync($request)->then(function ($response) {
                                // echo 'I completed! ' . $response->getBody();
                                echo 'Curl completed! ';
                            });
                            $promise->wait();
                        } else if ($job['exec_type'] == 3) { // exec

                            // 为执行器添加Task ID
                            exec($job['exec_data'], $output, $return);

                            // success 记录执行状态
                            if ($return == 0) {
                                $job_service = new JobService();
                                $job_service->createJobTaskRecordRedis([
                                    'taskid' => $task_u['id'],
                                    'status' => 3,
                                    'create_time' => time()
                                ]);
                            } else {
                                $job_service = new JobService();
                                $job_service->createErrorRecordRedis('执行器执行脚本异常', [
                                    'taskid' => $task_u['id'],
                                    'error_msg' => 'exec_type:3,执行异常'
                                ], 2);
                            }
                        } else { // exec

                            // 固定脚本必须在传参的地方加上取代符 ---jobtaskid---
                            // 为执行器添加Task ID
                            if (strpos($job['exec_data'], '---execjobtaskid---') !== false) {
                                $job['exec_data'] = str_replace('---execjobtaskid---', 'execjobtaskid=' . $task_u['id'], $job['exec_data']);
                            }

                            exec($job['exec_data']);
                        }
                    } catch (\Exception $e) {
                        $update['task_status'] = 4;

                        $job_service = new JobService();
                        $job_service->createErrorRecordRedis('执行器执行脚本异常', [
                            'taskid' => $task_u['id'],
                            'error_msg' => $e->getMessage()
                        ], 2);
                    }

                    JobTask::where('id', $task['id'])->whereIn('task_status', [
                        0,
                        4
                    ])
                        ->where('is_cancel', 0)
                        ->update($update);
                    JobTask::where('id', $task['id'])->where('is_cancel', 0)->increment('repeat_count'); // 这个数字在并发的时候不一定非常精确，基本没啥影响

                    JobTaskRecord::create([
                        'job_id' => $task_u['job_id'],
                        'task_id' => $task_u['id'],
                        'status' => 1,
                        'created_at' => $t_created_at
                    ]);
                } // endif task_status
            } // endif task_u
            DB::commit();

            sleep(rand(1, 4)); // 减少并发执行
        } catch (\Exception $e) {
            // 数据库操作异常，回滚
            $job_service = new JobService();
            $job_service->createErrorRecordRedis('执行器操作数据库异常，脚本可能已经执行', [
                'taskid' => $task['id'],
                'error_msg' => $e->getMessage()
            ], 2);

            DB::rollBack();
        }
    }
}