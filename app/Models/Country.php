<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = ['name', 'code', 'currency'];

    /**
     * ISO 3166-1 alpha-3 → 영문 국가명 (수출서류용, ASCII 안전).
     *
     * 배경 (jin 2026-07-06 quick win ⑤): 통관 단계를 건너뛰고 선적만 해서 서류를 뽑을 때
     * 목적국/목적항이 통관 탭에 있어 미입력이면 `DocValue::dischargeDestination`이
     * 바이어/컨사이니 국가의 한글 `name`으로 fallback → 영문 수출서류에 한글이 박히는 문제.
     * `code`(alpha-3)가 이미 있으므로 DB 컬럼 없이 code→영문 맵으로 근본 해결.
     * 맵에 없는 code(수기 추가 국가)는 accessor가 한글 name으로 graceful fallback.
     */
    public const CODE_TO_EN = [
        // Africa
        'DZA' => 'Algeria', 'AGO' => 'Angola', 'BEN' => 'Benin', 'BWA' => 'Botswana',
        'BFA' => 'Burkina Faso', 'BDI' => 'Burundi', 'CMR' => 'Cameroon', 'CPV' => 'Cape Verde',
        'CAF' => 'Central African Republic', 'TCD' => 'Chad', 'COM' => 'Comoros',
        'COG' => 'Republic of the Congo', 'COD' => 'DR Congo', 'DJI' => 'Djibouti', 'EGY' => 'Egypt',
        'GNQ' => 'Equatorial Guinea', 'ERI' => 'Eritrea', 'SWZ' => 'Eswatini', 'ETH' => 'Ethiopia',
        'GAB' => 'Gabon', 'GMB' => 'Gambia', 'GHA' => 'Ghana', 'GIN' => 'Guinea',
        'GNB' => 'Guinea-Bissau', 'CIV' => 'Ivory Coast', 'KEN' => 'Kenya', 'LSO' => 'Lesotho',
        'LBR' => 'Liberia', 'LBY' => 'Libya', 'MDG' => 'Madagascar', 'MWI' => 'Malawi',
        'MLI' => 'Mali', 'MRT' => 'Mauritania', 'MUS' => 'Mauritius', 'MAR' => 'Morocco',
        'MOZ' => 'Mozambique', 'NAM' => 'Namibia', 'NER' => 'Niger', 'NGA' => 'Nigeria',
        'RWA' => 'Rwanda', 'STP' => 'Sao Tome and Principe', 'SEN' => 'Senegal', 'SYC' => 'Seychelles',
        'SLE' => 'Sierra Leone', 'SOM' => 'Somalia', 'ZAF' => 'South Africa', 'SSD' => 'South Sudan',
        'SDN' => 'Sudan', 'TZA' => 'Tanzania', 'TGO' => 'Togo', 'TUN' => 'Tunisia',
        'UGA' => 'Uganda', 'ZMB' => 'Zambia', 'ZWE' => 'Zimbabwe',

        // Americas
        'ATG' => 'Antigua and Barbuda', 'ARG' => 'Argentina', 'BHS' => 'Bahamas', 'BRB' => 'Barbados',
        'BLZ' => 'Belize', 'BOL' => 'Bolivia', 'BRA' => 'Brazil', 'CAN' => 'Canada',
        'CHL' => 'Chile', 'COL' => 'Colombia', 'CRI' => 'Costa Rica', 'CUB' => 'Cuba',
        'DMA' => 'Dominica', 'DOM' => 'Dominican Republic', 'ECU' => 'Ecuador', 'SLV' => 'El Salvador',
        'GRD' => 'Grenada', 'GTM' => 'Guatemala', 'GUY' => 'Guyana', 'HTI' => 'Haiti',
        'HND' => 'Honduras', 'JAM' => 'Jamaica', 'MEX' => 'Mexico', 'NIC' => 'Nicaragua',
        'PAN' => 'Panama', 'PRY' => 'Paraguay', 'PER' => 'Peru', 'KNA' => 'Saint Kitts and Nevis',
        'LCA' => 'Saint Lucia', 'VCT' => 'Saint Vincent and the Grenadines', 'SUR' => 'Suriname',
        'TTO' => 'Trinidad and Tobago', 'USA' => 'United States', 'URY' => 'Uruguay', 'VEN' => 'Venezuela',

        // Asia
        'AFG' => 'Afghanistan', 'ARM' => 'Armenia', 'AZE' => 'Azerbaijan', 'BHR' => 'Bahrain',
        'BGD' => 'Bangladesh', 'BTN' => 'Bhutan', 'BRN' => 'Brunei', 'KHM' => 'Cambodia',
        'CHN' => 'China', 'CYP' => 'Cyprus', 'GEO' => 'Georgia', 'IND' => 'India',
        'IDN' => 'Indonesia', 'IRN' => 'Iran', 'IRQ' => 'Iraq', 'ISR' => 'Israel',
        'JPN' => 'Japan', 'JOR' => 'Jordan', 'KAZ' => 'Kazakhstan', 'KWT' => 'Kuwait',
        'KGZ' => 'Kyrgyzstan', 'LAO' => 'Laos', 'LBN' => 'Lebanon', 'MYS' => 'Malaysia',
        'MDV' => 'Maldives', 'MNG' => 'Mongolia', 'MMR' => 'Myanmar', 'NPL' => 'Nepal',
        'PRK' => 'North Korea', 'OMN' => 'Oman', 'PAK' => 'Pakistan', 'PSE' => 'Palestine',
        'PHL' => 'Philippines', 'QAT' => 'Qatar', 'SAU' => 'Saudi Arabia', 'SGP' => 'Singapore',
        'KOR' => 'South Korea', 'LKA' => 'Sri Lanka', 'SYR' => 'Syria', 'TWN' => 'Taiwan',
        'TJK' => 'Tajikistan', 'THA' => 'Thailand', 'TLS' => 'Timor-Leste', 'TUR' => 'Turkey',
        'TKM' => 'Turkmenistan', 'ARE' => 'United Arab Emirates', 'UZB' => 'Uzbekistan',
        'VNM' => 'Vietnam', 'YEM' => 'Yemen', 'HKG' => 'Hong Kong', 'MAC' => 'Macao',

        // Europe
        'ALB' => 'Albania', 'AND' => 'Andorra', 'AUT' => 'Austria', 'BLR' => 'Belarus',
        'BEL' => 'Belgium', 'BIH' => 'Bosnia and Herzegovina', 'BGR' => 'Bulgaria', 'HRV' => 'Croatia',
        'CZE' => 'Czechia', 'DNK' => 'Denmark', 'EST' => 'Estonia', 'FIN' => 'Finland',
        'FRA' => 'France', 'DEU' => 'Germany', 'GRC' => 'Greece', 'HUN' => 'Hungary',
        'ISL' => 'Iceland', 'IRL' => 'Ireland', 'ITA' => 'Italy', 'XKX' => 'Kosovo',
        'LVA' => 'Latvia', 'LIE' => 'Liechtenstein', 'LTU' => 'Lithuania', 'LUX' => 'Luxembourg',
        'MLT' => 'Malta', 'MDA' => 'Moldova', 'MCO' => 'Monaco', 'MNE' => 'Montenegro',
        'NLD' => 'Netherlands', 'MKD' => 'North Macedonia', 'NOR' => 'Norway', 'POL' => 'Poland',
        'PRT' => 'Portugal', 'ROU' => 'Romania', 'RUS' => 'Russia', 'SMR' => 'San Marino',
        'SRB' => 'Serbia', 'SVK' => 'Slovakia', 'SVN' => 'Slovenia', 'ESP' => 'Spain',
        'SWE' => 'Sweden', 'CHE' => 'Switzerland', 'UKR' => 'Ukraine', 'GBR' => 'United Kingdom',
        'VAT' => 'Vatican City',

        // Oceania
        'AUS' => 'Australia', 'FJI' => 'Fiji', 'KIR' => 'Kiribati', 'MHL' => 'Marshall Islands',
        'FSM' => 'Micronesia', 'NRU' => 'Nauru', 'NZL' => 'New Zealand', 'PLW' => 'Palau',
        'PNG' => 'Papua New Guinea', 'WSM' => 'Samoa', 'SLB' => 'Solomon Islands', 'TON' => 'Tonga',
        'TUV' => 'Tuvalu', 'VUT' => 'Vanuatu',
    ];

    /**
     * 영문 국가명 — code(alpha-3) 기준. 맵에 없으면 한글 name fallback (빈칸 방지).
     * 서류 resolver(DocValue::destinationCountry)가 한글 대신 이 값을 우선 사용.
     */
    public function getNameEnAttribute(): string
    {
        return self::CODE_TO_EN[$this->code] ?? ($this->name ?? '');
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(Buyer::class);
    }

    public function consignees(): HasMany
    {
        return $this->hasMany(Consignee::class);
    }
}
