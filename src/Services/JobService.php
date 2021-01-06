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
        
        Redis::lpush(config('jobkey.task_record_list'), json_encode([
            'taskid' => $params['taskid'],
            'status' => $params['status'],
            'create_time' => $params['create_time'],
        ]));
        
        return true;
    }
    
    /**
     * 异步消费Record List， 插入数据库
     * rpop a json task record from redis list and insert a task exec record into mysql
     * 
     * taskid
     * create_time
     * status
     */
    public function consumeJobTaskRecordMysql()
    {
        $pop = Redis::rpop(config('jobkey.task_record_list'));
        
        if($pop){
            $params = json_decode($pop, true);
            if(!isset($params['taskid']) || !isset($params['status']) || !isset($params['create_time'])){
                //insert an ErrorMessage into Error record
                $this->createErrorRecordRedis('异步消费任务执行日志，consumeJobTaskRecordMysql，参数错误', $params);
                
                return false;
            }
            
            $task = JobTask::where('id', $params['taskid'])->first();
            if(!empty($task)){
                JobTaskRecord::create([
                    'job_id' => $task['job_id'],
                    'task_id' => $task['id'],
                    'status' => $params['status'],
                    'created_at' => date('Y-m-d H:i:s', intval($params['create_time']))
                ]);
                
                JobTask::where('id', $task['id'])->where('task_status', '<', $params['status'])->update([
                    'task_status' => $params['status'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }else{
                //insert a no task ErrorMessage into Error record
                $this->createErrorRecordRedis('异步消费任务执行日志，根据TaskId没有查到JobTask', $params);
                
                return false;
            }
            
            
            return true;
        }else{
            return false;
        }
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
        
        Redis::lpush(config('jobkey.error_list'), json_encode([
            'msg' => $msg,
            'params' => $params,
            'error_level' => $error_level,
        ]));
        
        return true;
    }
    
    /**
     * 异步消费Error List， 插入数据库
     * error_level, 1 正常,一般性错误 2 严重错误，如程序异常抛错等 3
     * 严重错误发邮件/SMS
     * 
     */
    public function consumeErrorRecordMysql()
    {
        $pop = Redis::rpop(config('jobkey.error_list'));
        if($pop){
            $params = json_decode($pop, true);
            if(!isset($params['msg']) || !isset($params['params']) || !isset($params['error_level'])){
                //invalid error msg, do nothing
                return false;
            }
            
            //任务执行异常捕捉taskid 和 code
            $task_id = 0;
            $exception_code = 0;
            if(isset($params['params']['data'])){
                $t_c = json_decode($params['params']['data'], true);
                $task_id = $t_c['taskid'] ?? 0;
                $task_id = isset($t_c['taskid']) && !empty($t_c['taskid']) ? intval($t_c['taskid']) : 0;
                $exception_code = isset($t_c['code']) && !empty($t_c['code']) ? intval($t_c['code']) : 0;
            }
            
            JobErrorMsg::create([
                'msg' => $params['msg'],
                'content' => json_encode($params['params']),
                'error_level' => $params['error_level'],
                'task_id' => $task_id,
                'exception_code' => $exception_code,
            ]);
            
            //error_level, 1 正常,一般性错误 2 严重错误，如程序异常抛错等 3
            if($params['error_level'] > 1){
                //  send email or send SMS
                $this->sendWarningNotice($params);
            }
            
            return true;
        }else{
            return false;
        }
    }
    
    /**
     * 通过邮件或者短信发送错误报告
     * 前期采用通过发邮件的方式报告错误 2019-10-10
     * 
     * @params error_level
     * @params msg
     * @params params
     * 
     * @param array $params
     * @return boolean
     */
    private function sendWarningNotice($params)
    {
        //查询处理正常状态的邮件列表，发送报警邮件。job_email
        try{
            $mails = JobEmail::where('is_lock',0)->get();
            if(!empty($mails)){
                foreach ($mails as $mail) {
                    Mail::send('emails.notice', [
                        'title' => $params['msg'],
                        'content' => json_encode($params['params'], JSON_UNESCAPED_UNICODE),
                    ], function($message) use ($params, $mail){
                        $message->to($mail['email'])->subject($params['msg']);
                    });
                }
            }
            
        }catch(\Exception $e){
            echo $e->getMessage();
        }
        
        return true;
    }
}