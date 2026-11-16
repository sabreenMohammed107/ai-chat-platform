<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageDaily extends Model
{
    protected $table = 'usage_daily';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'date', 'messages', 'input_tokens', 'output_tokens', 'cost'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cost' => 'decimal:6'];
    }
}
