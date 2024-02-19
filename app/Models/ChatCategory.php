<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatCategory extends Model
{
    protected $table = 'chat_category';

    protected $fillable = [
        'name'
    ];
}
