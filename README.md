# رِلی API برای Arman VPN روی Railway

یک رِلی کوچک PHP که درخواست‌های API کلاینت (ورود، Guardian، لیست سرورها) را وقتی دسترسی مستقیم به Mozilla مسدود است، از مسیر Railway عبور می‌دهد. ترافیک خودِ VPN از این مسیر رد نمی‌شود.

فقط این دامنه‌ها (و زیردامنه‌هایشان) رِلی می‌شوند: `mozilla.org`، `mozilla.com`، `firefox.com`، `github.com`، `githubusercontent.com`، `jsdelivr.net`. ریدایرکت‌ها دنبال نمی‌شوند و به کلاینت برگردانده می‌شوند.

## راه‌اندازی

### ۱. ارسال به GitHub

```bash
git init
git add .
git commit -m "Arman VPN relay"
git branch -M main
git remote add origin https://github.com/USERNAME/arman-vpn-relay.git
git push -u origin main
```

### ۲. ساخت پروژه در Railway

**New Project ← Deploy from GitHub repo** و مخزن را انتخاب کنید. Railway خودکار از `Dockerfile` و `railway.json` استفاده می‌کند.

### ۳. متغیر SECRET

در تب **Variables** متغیر `SECRET` را با یک مقدار طولانی و تصادفی اضافه کنید. برای ساخت مقدار:

```bash
openssl rand -hex 24
```

بعد از ذخیره، سرویس دوباره دیپلوی می‌شود. بدون `SECRET` رِلی چیزی را عبور نمی‌دهد.

### ۴. ریجن

**Settings ← Region** و یک ریجن اروپایی انتخاب کنید (مثلاً Amsterdam).

### ۵. دامنه

**Settings ← Networking ← Generate Domain**

### ۶. تست در مرورگر گوشی

سلامت رِلی (به هیچ سروری وصل نمی‌شود):

```
https://NAME.up.railway.app/?secret=SECRET&health=1
```

پاسخ درست: `{"relay":"arman-vpn","version":1,"ready":true}`

عبور یک درخواست واقعی:

```
https://NAME.up.railway.app/?secret=SECRET&url=https://firefox.settings.services.mozilla.com/v1/buckets/main/collections/vpn-serverlist/records
```

باید JSON لیست سرورها را ببینید.

### ۷. تنظیم در اپ

این آدرس را در اپ VPN وارد کنید: **Settings ← Connection ← If Mozilla is blocked**

```
https://NAME.up.railway.app/?secret=SECRET
```

## عیب‌یابی

- **۴۵۱ (یا مسدود بودن از سمت مقصد):** IP ریجن فعلی Railway توسط سرور مقصد بلاک شده است. ریجن را عوض کنید (Settings ← Region) و دوباره دیپلوی کنید.
- **Timeout یا کندی:** مشکل معمولاً مسیر «کلاینت ← Railway» است. یک دامنهٔ اختصاصی اضافه کنید (Settings ← Networking ← Custom Domain) و رکورد `CNAME` آن را به مقدار داده‌شده در Railway وصل کنید، سپس آدرس جدید را در اپ بگذارید.
- **۴۰۳:** `secret` در آدرس نیست یا با `SECRET` در Variables یکی نیست. اگر ۵۰۳ گرفتید یعنی `SECRET` اصلاً تنظیم نشده است.

در هر خطا، دلیل در هدر و بدنهٔ `X-Relay-Error` نوشته می‌شود (برای ۵۰۲ شامل پیام خطای curl).

## چرا Healthcheck در railway.json نیست؟

مسیر healthcheck در Railway یک رشتهٔ ثابت است و متغیرها (مثل `${SECRET}`) در آن جایگزین نمی‌شوند، پس `/?health=1&secret=${SECRET}` ممکن نیست. اگر بدون secret بزنیم، رِلی عمداً ۴۰۳ یا ۵۰۳ برمی‌گرداند و Railway فقط پاسخ ۲۰۰ را سالم می‌داند، یعنی دیپلوی همیشه شکست می‌خورد. به جایش `restartPolicyType: ON_FAILURE` پروسه‌ای را که کرش کند دوباره بالا می‌آورد، و سلامت را با آدرس تست مرحلهٔ ۶ دستی بررسی کنید.

## نکتهٔ لاگ

سرور داخلی PHP آدرس کامل هر درخواست را، با `secret`، در stderr لاگ می‌کند. به همین دلیل در `Dockerfile` خروجی stderr حذف شده (`2>/dev/null`) و لاگ برنامه در Railway خالی می‌ماند.
