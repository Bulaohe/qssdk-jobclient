<?php

namespace Ssdk\Jobmanage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class JobTaskRecord extends Model
{
    protected $connection = 'jobclient';
    protected $fillable = [
        'job_id',
        'task_id',
        'status',
        'created_at',
    ];
    
    protected function bootIfNotBooted()
    {
        parent::bootIfNotBooted();
        
        $this->table = 'job_task_record_' . date('Ym');
        
        if ( ! Schema::connection('jobclient')->hasTable($this->table)) {
            // 创建数据库表的代码
            try {
                Schema::connection('jobclient')->create($this->table, function ($table) {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8';
                    $table->collation = 'utf8_general_ci';
                    $table->increments('id');
                    $table->integer('job_id')->comment('Job ID，job_config.id');
                    $table->integer('task_id')->comment('任务ID，job_task.id');
                    $table->tinyInteger('status')->default(0)->comment('状态：1触发执行，2开始执行，3执行成功，4执行错误');
                    $table->nullableTimestamps();
                    
                    $table->index('job_id');
                    $table->index('task_id');
                    $table->index('status');
                    $table->index('created_at');
                });
            } catch (\Exception $e) {
                //并发创建可能会抛异常，捕捉重新创建，如果再次抛出异常，不处理
                if ( ! Schema::connection('jobclient')->hasTable($this->table)) {
                    Schema::connection('jobclient')->create($this->table, function ($table) {
                        $table->engine = 'InnoDB';
                        $table->charset = 'utf8';
                        $table->collation = 'utf8_general_ci';
                        $table->increments('id');
                        $table->integer('job_id')->comment('Job ID，job_config.id');
                        $table->integer('task_id')->comment('任务ID，job_task.id');
                        $table->tinyInteger('status')->default(0)->comment('状态：1触发执行，2开始执行，3执行成功，4执行错误');
                        $table->nullableTimestamps();
                        
                        $table->index('job_id');
                        $table->index('task_id');
                        $table->index('status');
                        $table->index('created_at');
                    });
                }
            }
        }
    }
}

