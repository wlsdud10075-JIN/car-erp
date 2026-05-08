{{-- dompdf 한글 폰트 등록.
     ⚠️ src 뒤에 format() 붙이면 dompdf v3가 "truetype" 외 모두 무시함 (Stylesheet.php _parse_font_face).
        format() 자체를 빼면 default가 truetype으로 처리되어 OTF/TTF 모두 등록 가능.
     ⚠️ Windows 경로 백슬래시는 CSS url() 파서가 못 읽으므로 forward slash로 치환 필수.
--}}
@font-face {
    font-family: 'NotoSansKR';
    font-style: normal;
    font-weight: normal;
    src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSansKR-Regular.otf')) }}");
}
@font-face {
    font-family: 'NotoSansKR';
    font-style: normal;
    font-weight: bold;
    src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSansKR-Bold.otf')) }}");
}
