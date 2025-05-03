<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
