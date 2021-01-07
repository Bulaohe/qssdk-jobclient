<?php

namespace Ssdk\Jobmanage\Models;

use Illuminate\Database\Eloquent\Model;

class JobErrorMsg extends Model
{
    protected $table = 'job_error_msg';
    protected $connection = 'jobclient';
    
    protected $fillable = [
        'msg',
        'content',
        'error_level',
        'task_id',
        'exception_code',
    ];
}

