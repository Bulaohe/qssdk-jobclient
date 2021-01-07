<?php

namespace Qssdk\Jobmanage\Services;

use Qssdk\Jobmanage\Models\JobTask;
use Qssdk\Jobmanage\Models\JobTaskRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Qssdk\Jobmanage\Models\JobErrorMsg;
use Qssdk\Jobmanage\Models\JobEmail;

class JobService
{
    
    /**
     * 任务执行状态回调先写入Redis List， 以提高响应速度, 同时减少并发更新数据库抢锁的机率
     * 
     * taskid int
     * status tinyint
     * create_time int
     * 
     * @param array $params
     */
    public function createJobTaskRecordRedis($params)
    {
        if(!isset($params['taskid']) || !isset($params['status']) || !isset($params['create_time'])){
            //insert to a ErrorMessage to Error record
            $this->createErrorRecordRedis('创建任务执行记录，参数不正确:createJobTaskRecordRedis', $params);
            
            return false;
        }
        
        Redis::connection(env('JOBCLIENT_REDIS_CONNECTION'))->lpush(config('jobkey.task_record_list'), json_encode([
            'taskid' => $params['taskid'],
            'status' => $params['status'],
            'create_time' => $params['create_time'],
        ]));
        
        return true;
    }
    
    /**
     * 
     * @param string $msg
     * @param array $params
     * @param array $error_level, 1 正常,一般性错误 2 严重错误，如程序异常抛错等 3 。。。
     */
    public function createErrorRecordRedis($msg = '', $params = [], $error_level = 1)
    {
        if(empty($msg) || empty($params)){
            //ignore invalid func call
            //do nothing
            
            return false;
        }
        
        Redis::connection(env('JOBCLIENT_REDIS_CONNECTION'))->lpush(config('jobkey.error_list'), json_encode([
            'msg' => $msg,
            'params' => $params,
            'error_level' => $error_level,
        ]));
        
        return true;
    }
}