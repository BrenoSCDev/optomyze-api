<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    use HasCompanyTimezone;

    const UPDATED_AT = null;

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    protected $fillable = [
        'request_id',
        'company_id',
        'api_token_id',
        'user_id',
        'method',
        'endpoint',
        'full_url',
        'ip_address',
        'user_agent',
        'request_headers',
        'query_params',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'duration_ms',
        'status',
        'exception_class',
        'exception_message',
        'exception_trace',
        'created_at',
    ];

    protected $casts = [
        'request_headers'  => 'array',
        'query_params'     => 'array',
        'request_body'     => 'array',
        'response_headers' => 'array',
        'response_body'    => 'array',
        'created_at'       => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
