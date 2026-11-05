{{--
    👤 수신자 피커 — board 요청 시각 규칙과 그 밖의 알림톡이 **같은 이 조각**을 쓴다 (jin 2026-09-12).

    필요한 변수:
      $c     = Volt 컴포넌트($this). @include 안에서는 $this 가 컴포넌트를 가리킨다는 보장이 없어 넘겨받는다.
      $addr  = 수신자 주소. `rule:{code}:{idx}` 또는 `role:{code}` (컴포넌트 tokensAt/putTokensAt 참고).
      $salesOrphanNote = (선택) 영업 개별 지정 시 「계정 없는 영업담당자는 제외」 안내를 띄울지.
      $groups = (선택) 역할 선택지 [키 => 라벨] — 기본 BROADCAST_GROUPS. 지급 결재 결과는 「결재선 전원/재무/관리」(GROUP_OPTIONS).
                사람이 없는 의사 그룹(결재선 전원)은 체크 하나만 그린다.
      $daysCode = (선택) 단계별 확대 알림 코드 — 역할 줄마다 「D+ 일부터」(+ 경매 차량 날짜) 칸을 붙인다(말소 재촉, jin 2026-11-05).
                사람을 개별로 골라도 그 사람 역할의 날짜로 간다(AlimtalkRecipients::scopedForTiered).
      $noNumbers = (선택) 「번호 직접 추가」를 숨길지 — 차량 스코프형(말소 재촉)은 번호로는 담당 차를 못 가려 못 받는다.

    🚫 이 조각을 복사해 두 벌로 만들지 말 것 — 한쪽만 고쳐지면 「화면마다 다르게 동작」이 된다(§8 #44).
--}}
<div x-data="{ open: '' }">
    <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('alimtalk_catalog.rule_recipients') }}</div>
    <div class="flex flex-col gap-0.5">
        @foreach(($groups ?? \App\Support\AlimtalkRecipients::BROADCAST_GROUPS) as $g => $gLabel)
            @php
                $st = $c->groupStateAt($addr, $g);
                $members = $c->groupMembers($g);
                $picked = $c->selectedMembersAt($addr, $g);
            @endphp
            <div class="rounded bg-white px-2 py-1">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" x-on:click="open = (open === '{{ $g }}' ? '' : '{{ $g }}')"
                            class="w-4 text-[11px] text-gray-400 hover:text-gray-700"
                            x-text="open === '{{ $g }}' ? '▾' : '▸'">▸</button>
                    <label class="flex items-center gap-1 text-[11px] text-gray-700">
                        <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300"
                               wire:click="toggleGroupAt('{{ $addr }}', '{{ $g }}')"
                               @checked($st === 'all') />
                        <span class="font-medium">{{ $gLabel }}</span>
                    </label>
                    @if($members === [] && $g === \App\Support\AlimtalkRecipients::APPROVAL_LINE)
                        {{-- 결재선 전원 = 배치마다의 결재선이라 미리 셀 사람이 없다 --}}
                    @elseif($st === 'all')
                        <span class="text-[11px] text-emerald-700">{{ __('alimtalk_catalog.rule_group_all', ['n' => count($members)]) }}</span>
                    @elseif($st === 'some')
                        <span class="text-[11px] text-amber-700">{{ __('alimtalk_catalog.rule_group_some', ['n' => count($picked)]) }}</span>
                    @else
                        <span class="text-[11px] text-gray-300">{{ __('alimtalk_catalog.rule_group_none') }}</span>
                    @endif

                    {{-- 🚨 영업을 개별로 고르면 ERP 계정 없는 영업담당자(salesmen 만 있는 사람)가 빠진다.
                         고를 수 있는 대상이 아니라서 그런 것인데, 안 적으면 「왜 안 받지」가 된다. --}}
                    @if(! empty($daysCode))
                        {{-- 🪜 「체크 = 받을지 / 숫자 = 언제부터」 — 역할 줄마다. 🔨 = 구입처에 「경매」가 들어간 차량의 날짜. --}}
                        <span class="ml-auto inline-flex items-center gap-2 text-[11px] text-gray-500">
                            <span class="inline-flex items-center gap-0.5">D+<input type="number" min="0" max="365" inputmode="numeric"
                                wire:model="escalate.{{ $daysCode }}.{{ $g }}"
                                class="w-11 rounded border border-gray-300 px-1 py-0.5 text-center text-[11px]" />{{ __('alimtalk_catalog.escalate_unit') }}</span>
                            @if(\App\Support\AlimtalkRecipients::supportsAuctionEscalation($daysCode))
                            <span class="inline-flex items-center gap-0.5 rounded bg-slate-50 px-1" data-auction-dereg-days title="{{ __('alimtalk_catalog.auction_dereg_label') }}">🔨 D+<input type="number" min="0" max="365" inputmode="numeric"
                                wire:model="escalateAuction.{{ $daysCode }}.{{ $g }}"
                                class="w-11 rounded border border-gray-300 px-1 py-0.5 text-center text-[11px]" />{{ __('alimtalk_catalog.escalate_unit') }}</span>
                            @endif
                        </span>
                    @endif
                    @if(($salesOrphanNote ?? false) && $g === '영업' && $st === 'some')
                        <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">
                            {{ __('alimtalk_catalog.rule_sales_orphan_hint') }}
                        </span>
                    @endif
                </div>
                <div x-show="open === '{{ $g }}'" x-cloak class="mt-1 flex flex-wrap gap-x-4 gap-y-1 pl-6">
                    @forelse($members as $m)
                        <label wire:key="mem-{{ $addr }}-{{ $g }}-{{ $m['id'] }}"
                               class="flex items-center gap-1 text-[11px] text-gray-600">
                            <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300"
                                   wire:click="toggleMemberAt('{{ $addr }}', '{{ $g }}', {{ $m['id'] }})"
                                   @checked(in_array($m['id'], $picked, true)) />
                            {{ $m['name'] }}
                            @if($m['phone'] === '')
                                <span class="rounded bg-red-100 px-1 font-bold text-red-700"
                                      title="{{ __('alimtalk_catalog.rule_no_phone_hint') }}">⚠️ {{ __('alimtalk_catalog.rule_no_phone') }}</span>
                            @else
                                <span class="text-gray-400">{{ $m['phone'] }}</span>
                            @endif
                        </label>
                    @empty
                        <span class="text-[11px] text-gray-400">—</span>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- ERP 계정이 없는 외부 수신자용 통로는 남긴다. 다만 그 번호는 퇴사해도 계속 가므로 경고를 붙인다. --}}
    @if(! ($noNumbers ?? false))
    @php $nums = $c->numbersAt($addr); @endphp
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
        @foreach($nums as $num)
            <span wire:key="num-{{ $addr }}-{{ $loop->index }}"
                  class="inline-flex items-center gap-1 rounded-full bg-gray-200 px-2 py-0.5 text-[11px] text-gray-700">
                {{ $num }}
                <button type="button" wire:click="removeNumberAt('{{ $addr }}', '{{ $num }}')"
                        class="text-gray-500 hover:text-red-600">✕</button>
            </span>
        @endforeach
        <input type="text" wire:model="numberDraft.{{ $addr }}"
               wire:keydown.enter="addNumberAt('{{ $addr }}')"
               placeholder="{{ __('alimtalk_catalog.rule_number_ph') }}"
               title="{{ __('alimtalk_catalog.rule_number_hint') }}"
               class="input-base w-36 text-[11px]" />
        <button type="button" wire:click="addNumberAt('{{ $addr }}')"
                class="rounded border border-gray-300 px-2 py-1 text-[11px] text-gray-600 hover:bg-white">
            {{ __('alimtalk_catalog.rule_number_add') }}
        </button>
    </div>
    @endif
</div>
