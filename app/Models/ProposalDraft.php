<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProposalDraft extends Model
{
    protected $table = 'proposal_drafts';

    protected $fillable = ['lead_id','company_id','service','title','body','price_low_usd','price_high_usd','status','evidence','approved_by'];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
