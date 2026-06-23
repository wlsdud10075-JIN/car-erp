<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            default => $setting->value,
        };
    }

    /**
     * 서류 양식 세트(=회사) 단일 출처. 기능설정 토글(company_template_set) 우선,
     * 미설정 시 .env COMPANY_TEMPLATE_SET(config) fallback. 값: system(SSANCAR)/heyman/karaba.
     */
    public static function companyTemplateSet(): string
    {
        return static::get('company_template_set') ?: config('company.template_set', 'system');
    }
}
