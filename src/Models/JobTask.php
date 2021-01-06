<?php

namespace Qssdk\Jobmanage\Models;

use Illuminate\Database\Eloquent\Model;

class JobTask extends Model
{
    protected $table = 'job_task';
    protected $connection = 'jobclient';
    
    protected $fillable = [
        'job_id',
        'job_group',
        'sched_time',
        'created_at',
        'requests_recovery',
        'max_retry',
    ];
}
