<?php

namespace Ssdk\Jobmanage\Models;

use Illuminate\Database\Eloquent\Model;

class JobConfig extends Model
{
    protected $table = 'job_config';
    protected $connection = 'jobclient';

    protected $fillable = [
        'id',
        'sched_name',
        'job_name',
        'job_desc',
        'requests_recovery',
        'trigger_cron',
        'exec_type',
        'exec_data',
        'job_status',
        'job_group',
        'max_retry',
    ];
}
