<?php

namespace Qssdk\Jobmanage\Models;

use Illuminate\Database\Eloquent\Model;

class JobEmail extends Model
{
    protected $table = 'job_email';
    protected $connection = 'jobclient';
    
    protected $fillable = [
        'name',
        'email',
    ];
}

