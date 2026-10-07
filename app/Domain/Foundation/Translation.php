<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    protected $fillable = ['locale', 'translation_group', 'translation_key', 'value'];
}
