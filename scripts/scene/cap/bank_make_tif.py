"""가이드용 연습 전신문 — 실제 신한 팩스 모양(3쪽, 1728x2333, 흑백 Group4 TIF), 이름·번호는 전부 가짜."""
import sys
from PIL import Image, ImageDraw, ImageFont

out = sys.argv[1]
W, H = 1728, 2333
F = 'C:/Windows/Fonts/malgun.ttf'
f_big = ImageFont.truetype(F, 46)
f = ImageFont.truetype(F, 30)
f_s = ImageFont.truetype(F, 24)


def page(n):
    im = Image.new('L', (W, H), 255)
    d = ImageDraw.Draw(im)
    d.text((60, 10), '24-Nov-2026 10:12    From : [120]-0000000000    To : 0000000000', font=f_s, fill=0)
    d.text((W - 140, 10), f'p.{n}', font=f_s, fill=0)
    d.rectangle((100, 120, W - 100, 230), outline=0, width=4)
    d.text((130, 140), 'SHINHAN BANK  (연습용 예시)', font=f_big, fill=0)
    d.text((130, 195), 'Shinhan Fax Service', font=f_s, fill=0)
    return im, d


pages = []
# 1쪽 — 요약표
im, d = page(1)
d.text((520, 330), '타발해외송금/국내자금이체 내도통지', font=f_big, fill=0)
y = 470
cols = [160, 400, 640, 870, 1090, 1330, 1580]
heads = ['구분', 'REF-NO', 'VALUE DATE', '통화', '금액', '송금의뢰인']
vals = ['해외송금', '811-000000000', '20261121', 'USD', '1,000.00', 'GOLDEN BR**']
for i in range(6):
    d.rectangle((cols[i], y, cols[i + 1], y + 60), outline=0, width=2)
    d.rectangle((cols[i], y + 60, cols[i + 1], y + 120), outline=0, width=2)
    d.text((cols[i] + 12, y + 12), heads[i], font=f_s, fill=0)
    d.text((cols[i] + 12, y + 72), vals[i], font=f_s, fill=0)
yy = 700
for line in ['이하 SWIFT 전문내용 첨부', '*** INCOMING 수신전문 ***', 'MSG TYPE : 송금(300) (NORMAL) (pacs.008.001.08)',
             'SENDER : (000000 : EXAMPLEXXXX) EXAMPLE BANK, N.A.', 'NEW YORK', 'UNITED STATES OF AMERICA']:
    d.rectangle((170, yy, W - 200, yy + 56), outline=0, width=2)
    d.text((185, yy + 10), line, font=f, fill=0)
    yy += 70
pages.append(im)

# 2쪽 — 은행 정보
im, d = page(2)
yy = 320
for line in ['< Instructing Agent >', 'BIC : EXAMPLEXXXX', '< Instructed Agent >', 'BIC : SHBKKRSEXXX',
             'Interbank Settlement Amount : USD 1,000.00', 'Charge Bearer : SHAR']:
    d.text((185, yy), line, font=f, fill=0)
    yy += 70
pages.append(im)

# 3쪽 — Debtor(송금인) · Creditor(우리 회사)
im, d = page(3)
yy = 320
for line in ['< Debtor >', 'Name : GOLDEN BRIDGE TRADING LLC', 'Town Name : DUBAI', 'Country : AE', '',
             '< Debtor Account >', 'IBAN : AE00 0000 0000 0000 0000 000', '',
             '< Creditor >', 'Name : EXAMPLE CO., LTD', 'Country : KR', '',
             'Remittance Information : VEHICLE PAYMENT']:
    d.text((185, yy), line, font=f, fill=0)
    yy += 66
pages.append(im)

bw = [p.convert('1') for p in pages]
bw[0].save(out, save_all=True, append_images=bw[1:], compression='group4', dpi=(204, 196))
print('ok', out)
