<?php

namespace App\Services\Documents\Mappings;

/**
 * 통관 — 통관 SET **Ver.2** (6시트, jin 2026-10-15). Ver.1 옆에 나란히 둔다(어느 쪽을 쓸지는 실무자가 고른다).
 *
 * Ver.1 과의 차이 = 양식뿐이다 — ① 「차량인보이스」 탭이 없다 ② 「차량팩킹」 상단 오른쪽 블록(Reference No.·CERTIFICATE OF ORIGIN·
 * ID·Remark·L/C)이 없고 Shipper/Notify 칸이 전폭이다. 매핑(구매리스트 40칸 + Travel 3칸)은 Ver.1 과 **같은 것**을 쓴다 —
 * 두 벌로 갈리면 한쪽만 고쳐진다(§8 #44·#45). 탭 삭제로 끊긴 수식은 양식 빌드가 구매리스트 칸으로 되살렸다
 * (`scripts/build-clearance-set-v2.php`). 양식은 3사 세트마다 `clearance_set_v2.xlsx`.
 *
 * 🚫 `brandHeader` 는 없다 — Ver.1 의 그것은 「차량인보이스 A3」 전용이었고 차량팩킹 A3 는 Ver.1 에서도 치환하지 않았다(세트별 인쇄본 그대로).
 */
class ClearanceSetV2Mapping
{
    public static function config(): array
    {
        $config = ClearanceSetMapping::config();
        $config['template'] = 'clearance_set_v2.xlsx';
        $config['label'] = '통관SET(Ver.2)';
        unset($config['brandHeader']);

        return $config;
    }
}
