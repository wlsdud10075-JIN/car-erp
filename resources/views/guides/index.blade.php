<x-layouts.app>
    {{-- 업무 가이드 목록 (jin 2026-11-13) — 목업 docs/design/mock/2026-11-13/guides-page.html.
         씬이 30개 안팎이라 검색·칩은 서버 왕복 없이 화면에서 거른다. 색·모양은 이 파일의 <style> 에만 둔다
         (새 Tailwind 유틸을 쓰면 빌드 CSS 에 없어 조용히 안 먹는다 — SKILLS §8 #50). --}}
    <style>
        .gd-search { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 14px; margin: 14px 0 10px; }
        .gd-search input { flex: 1 1 220px; min-width: 0; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 12px; font-size: 14px; background: #fff; color: #1f2937; }
        .gd-search input:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 1px var(--color-primary); }
        .gd-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; align-items: center; }
        .gd-chip { border: 1px solid #d1d5db; background: #fff; border-radius: 99px; padding: 5px 12px; font-size: 12.5px; color: #374151; cursor: pointer; }
        .gd-chip.on { background: var(--color-primary); border-color: var(--color-primary); color: #fff; font-weight: 700; }
        .gd-lbl { font-size: 12px; color: #6b7280; }
        .gd-sep { width: 1px; height: 18px; background: #d1d5db; margin: 0 4px; }
        .gd-lap { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 12px 14px; margin-bottom: 12px; background: linear-gradient(90deg, #f5f3ff, #fff); }
        .gd-lap a { border: 1px solid #c4b5fd; background: #fff; color: var(--color-primary-text); border-radius: 6px; padding: 5px 9px; font-size: 12px; }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table th { background: #fafafa; color: #6b7280; font-weight: 600; font-size: 12px; text-align: left; padding: 9px 12px; border-bottom: 1px solid #e5e7eb; }
        .gd-table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-size: 13px; color: #1f2937; }
        .gd-row { cursor: pointer; }
        .gd-row:hover td { background: #faf9fe; }
        .gd-row.wait { cursor: default; }
        .gd-row.wait td { color: #6b7280; background: transparent; }
        .gd-grp td { background: #f3f4f6; font-size: 12px; font-weight: 700; color: #4b5563; padding: 6px 12px; }
        .gd-ttl { font-weight: 700; font-size: 13.5px; }
        .gd-row.wait .gd-ttl { font-weight: 600; }
        .gd-sum { color: #6b7280; font-size: 12.5px; margin-top: 2px; }
        .gd-hit { margin-top: 5px; font-size: 12px; color: #374151; background: #fffbea; border-left: 3px solid #f59e0b; padding: 4px 8px; border-radius: 0 4px 4px 0; }
        .gd-table mark, .gd-hit mark { background: #fde68a; color: inherit; padding: 0 1px; border-radius: 2px; }
        .gd-who { display: inline-block; font-size: 11.5px; background: #f3f4f6; color: #4b5563; border-radius: 4px; padding: 2px 6px; margin: 0 3px 3px 0; white-space: nowrap; }
        .gd-st { display: inline-block; font-size: 11.5px; font-weight: 700; border-radius: 99px; padding: 2px 9px; white-space: nowrap; }
        .gd-st.ok { background: #dcfce7; color: #15803d; } .gd-st.new { background: #dbeafe; color: #1d4ed8; } .gd-st.wait { background: #f3f4f6; color: #4b5563; }
        .gd-empty { padding: 36px 14px; text-align: center; color: #6b7280; }
        @media (max-width: 640px) {
            .gd-table thead, .gd-c-who, .gd-c-date, .gd-c-no { display: none; }
            .gd-table, .gd-table tbody, .gd-table tr, .gd-table td { display: block; width: 100%; }
            .gd-row { border-bottom: 1px solid #e5e7eb; padding: 10px 12px; position: relative; }
            .gd-row td { border: 0; padding: 0; }
            .gd-c-st { margin-top: 6px; }
            .gd-c-go { position: absolute; right: 12px; top: 10px; width: auto !important; }
            .gd-c-ttl { padding-right: 64px !important; }
        }
    </style>

    <script type="application/json" id="guides-data">{!! json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

    <div class="p-3 md:p-6" x-data="guideList()">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-gray-800">{{ __('guides.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('guides.intro') }}</p>
            </div>
            @if($boardUrl)
                <a href="{{ $boardUrl }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold" style="color: var(--color-primary-text)">{{ __('guides.board_link') }}</a>
            @endif
        </div>

        <div class="card gd-search">
            <input type="search" x-model="q" autocomplete="off"
                   placeholder="{{ __('guides.search_placeholder') }}">
            <span class="pill-count" x-text="countText()"></span>
        </div>

        <div class="gd-chips">
            <span class="gd-lbl">{{ __('guides.chip_work') }}</span>
            <button type="button" class="gd-chip" :class="{ on: grp === '' }" @click="grp = ''">{{ __('guides.chip_all') }}</button>
            <template x-for="(name, key) in groups" :key="key">
                <button type="button" class="gd-chip" :class="{ on: grp === key }" @click="grp = key" x-text="name"></button>
            </template>
            <span class="gd-sep"></span>
            <span class="gd-lbl">{{ __('guides.chip_role') }}</span>
            <template x-for="r in roles" :key="r">
                <button type="button" class="gd-chip" :class="{ on: role === r }" @click="role = role === r ? '' : r" x-text="t.roles[r] || r"></button>
            </template>
        </div>

        <div class="card gd-lap" x-show="!filtering() && lap().length" x-cloak>
            <div>
                <div class="text-sm font-bold text-gray-800" x-text="t.lap_title.replace(':n', lap().length)"></div>
                <div class="text-xs text-gray-500">{{ __('guides.lap_hint') }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-1">
                <template x-for="(s, i) in lap()" :key="s.slug">
                    <span class="flex items-center gap-1">
                        <span x-show="i > 0" style="color: #6d28d9">→</span>
                        <a :href="base + '/' + s.slug" x-text="(i + 1) + '. ' + s.title.split(' — ')[0]"></a>
                    </span>
                </template>
            </div>
        </div>

        <div class="card" style="padding: 0; overflow: hidden">
            <table class="gd-table">
                <thead><tr><th class="gd-c-no">#</th><th>{{ __('guides.col_feature') }}</th><th class="gd-c-who">{{ __('guides.col_who') }}</th><th>{{ __('guides.col_status') }}</th><th class="gd-c-date">{{ __('guides.col_updated') }}</th><th></th></tr></thead>
                <tbody>
                    <template x-for="r in rows()" :key="r.key">
                        <tr :class="r.head ? 'gd-grp' : ('gd-row ' + (r.s.status === 'wait' ? 'wait' : ''))"
                            @click="if (!r.head && r.s.status !== 'wait') location.href = base + '/' + r.s.slug">
                            <template x-if="r.head"><td colspan="6" x-text="r.head"></td></template>
                            <template x-if="!r.head">
                                <td class="gd-c-no" style="width: 34px; color: #6b7280" x-text="r.s.no"></td>
                            </template>
                            <template x-if="!r.head">
                                <td class="gd-c-ttl">
                                    <div class="gd-ttl" x-html="mark(r.s.title)"></div>
                                    <div class="gd-sum" x-html="mark(r.s.summary)"></div>
                                    <div class="gd-hit" x-show="hit(r.s)" x-html="t.body_hit + ': ' + hit(r.s)"></div>
                                    <span class="gd-who" x-show="r.s.koreanOnly" x-text="t.korean_only"></span>
                                </td>
                            </template>
                            <template x-if="!r.head">
                                <td class="gd-c-who"><template x-for="w in r.s.who" :key="w"><span class="gd-who" x-text="t.roles[w] || w"></span></template></td>
                            </template>
                            <template x-if="!r.head">
                                <td class="gd-c-st"><span class="gd-st" :class="r.s.status" x-text="stName[r.s.status]"></span></td>
                            </template>
                            <template x-if="!r.head">
                                <td class="gd-c-date" style="color: #6b7280; white-space: nowrap" x-text="r.s.updated || '—'"></td>
                            </template>
                            <template x-if="!r.head">
                                <td class="gd-c-go" style="text-align: right"><a x-show="r.s.status !== 'wait'" :href="base + '/' + r.s.slug" class="btn-primary" @click.stop>{{ __('guides.view') }}</a></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="gd-empty" x-show="matched().length === 0" x-cloak>{{ __('guides.empty') }}</div>
        </div>
    </div>

    <script>
        function guideList() {
            const data = JSON.parse(document.getElementById('guides-data').textContent);
            const norm = (s) => String(s).toLowerCase().replace(/\s+/g, '');
            const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            // 띄어쓰기를 무시하고 찾은 자리를 원문에 칠한다.
            const paint = (text, q) => {
                const nq = norm(q);
                if (!nq) return esc(text);
                const map = []; let n = '';
                for (let i = 0; i < text.length; i++) { if (!/\s/.test(text[i])) { map.push(i); n += text[i].toLowerCase(); } }
                const at = n.indexOf(nq);
                if (at < 0) return esc(text);
                const a = map[at], b = map[at + nq.length - 1] + 1;
                return esc(text.slice(0, a)) + '<mark>' + esc(text.slice(a, b)) + '</mark>' + esc(text.slice(b));
            };

            return {
                scenes: data.scenes, groups: data.groups, base: data.base, t: data.t,
                q: '', grp: '', role: '',
                roles: [...new Set(data.scenes.flatMap((s) => s.who))],
                stName: { ok: data.t.status_ok, new: data.t.status_new, wait: data.t.status_wait },
                filtering() { return norm(this.q) !== '' || this.grp !== '' || this.role !== ''; },
                matched() {
                    const nq = norm(this.q);
                    return this.scenes.filter((s) => (this.grp === '' || s.group === this.grp)
                        && (this.role === '' || s.who.includes(this.role))
                        && (nq === '' || norm(s.title + s.summary + s.who.join('') + s.who.map((w) => this.t.roles[w] || '').join('') + s.keywords + s.body).includes(nq)));
                },
                lap() { return this.scenes.filter((s) => s.lap !== null && s.status !== 'wait').sort((a, b) => a.lap - b.lap); },
                rows() {
                    const list = this.matched();
                    const open = list.filter((s) => s.status !== 'wait'), wait = list.filter((s) => s.status === 'wait');
                    const row = (s) => ({ key: s.slug, s });
                    if (norm(this.q) !== '') return [...open, ...wait].map(row);   // 검색 중엔 묶음 머리 없이
                    const out = [];
                    for (const [key, name] of Object.entries(this.groups)) {
                        const g = open.filter((s) => s.group === key);
                        if (g.length) out.push({ key: 'h-' + key, head: name }, ...g.map(row));
                    }
                    if (wait.length) out.push({ key: 'h-wait', head: this.t.waiting_head.replace(':n', wait.length) }, ...wait.map(row));
                    return out;
                },
                mark(text) { return paint(text, this.q); },
                // 제목·요약·역할에 없고 본문에서만 걸렸을 때 그 문장을 보여준다.
                hit(s) {
                    const nq = norm(this.q);
                    if (!nq || norm(s.title + s.summary + s.who.join('')).includes(nq)) return '';
                    const sent = s.body.split(/(?<=[.다])\s+/).find((x) => norm(x).includes(nq));
                    return sent ? paint(sent, this.q) : '';
                },
                countText() {
                    return this.filtering() ? this.t.found.replace(':n', this.matched().length)
                        : this.t.count.replace(':open', this.scenes.filter((s) => s.status !== 'wait').length).replace(':all', this.scenes.length);
                },
            };
        }
    </script>
</x-layouts.app>
