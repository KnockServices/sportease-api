<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    public function session()
    {
        return $this->belongsTo(TeamSession::class, 'team_session_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
