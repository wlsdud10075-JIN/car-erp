{{-- dompdf 한글 폰트 등록.
     ⚠️ src 뒤에 format() 붙이면 dompdf v3가 "truetype" 외 모두 무시함 (Stylesheet.php _parse_font_face).
        format() 자체를 빼면 default가 truetype으로 처리되어 등록 가능.
     ⚠️ Windows 경로 백슬래시는 CSS url() 파서가 못 읽으므로 forward slash로 치환 필수.
     ⚠️ .subset.ttf는 Hangul Syllables U+AC00-D7A3 + Latin/한글기호만 포함된 사전 서브셋 폰트.
        풀 OTF 대비 1/3 사이즈 (1.55MB × 2). dompdf 내장 서브셋팅은 한글 글리프 누락 문제로 사용 불가.
--}}
@font-face {
    font-family: 'NotoSansKR';
    font-style: normal;
    font-weight: normal;
    src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSansKR-Regular.subset.ttf')) }}");
}
@font-face {
    font-family: 'NotoSansKR';
    font-style: normal;
    font-weight: bold;
    src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSansKR-Bold.subset.ttf')) }}");
}
