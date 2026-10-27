# ডিএন বাংলা নিউজরুম ড্যাশবোর্ড

এই repository-তে standalone `index.html`-এর পাশাপাশি একটি WordPress plugin implementation শুরু করা হয়েছে। পূর্ণ নির্দেশনার server-side অংশের জন্য plugin-টি ব্যবহার করতে হবে; GitHub Pages-এর static HTML দিয়ে database, login, cron বা API secrets নিরাপদে চালানো যায় না।

## ইনস্টলেশন

1. `wp-content/plugins/dnbangla-dashboard/` directory-টি WordPress site-এর `wp-content/plugins/` directory-তে কপি করুন, অথবা এই directory-র ZIP WordPress Admin → Plugins → Add New → Upload Plugin থেকে আপলোড করুন।
2. WordPress Admin → Plugins থেকে **DN Bangla Newsroom Dashboard** activate করুন। Activation-এ staff, card-history ও system-log table তৈরি হবে।
3. Admin menu-তে **ডিএন বাংলা** খুলুন। এটি কেবল `manage_options` permission-ধারী administrator-দের জন্য উপলভ্য।
4. WordPress REST API সক্রিয় আছে এবং administrator session-এ REST nonce request পাঠাতে পারছে তা নিশ্চিত করুন।
5. production scheduler-এর জন্য hosting control panel-এ প্রতি মিনিটে `wp-cron.php` চালানোর server cron যোগ করুন এবং `DISABLE_WP_CRON` ব্যবহারের সিদ্ধান্ত hosting provider-এর নির্দেশনা অনুযায়ী নিন।

## যা এই প্রাথমিক build-এ কাজ করে

- Admin-only WordPress REST routes; capability check, nonce, input sanitation এবং server-side settings storage।
- WordPress posts pagination, title, featured image, date, category ও source URL fetch; connection test প্রকৃত HTTP response যাচাই করে।
- Admin uploads WordPress Media Library-তে যায়; supported JPG/PNG/WebP MIME যাচাই হয়। Configured WordPress host থেকে feature image server-side import হয়।
- Card template settings WordPress options-এ থাকে; news-card records, staff ও logs WordPress database tables-এ থাকে। Staff ID unique।
- Ad metadata server-side রাখা হয়; uploaded বা HTTPS URL থেকে পাওয়া banner WordPress Media Library-তে import হয়।
- দৈনিক time-based schedules server-side option-এ থাকে; WP-Cron hook-এ একই schedule/day-এর duplicate trigger atomic option insert দিয়ে ঠেকানো হয়।
- News ও text canvas 1920×1920; main-card section Y/H: header 0/80, feature 80/1085, comment 1100/55, headline 1165/398, footer 1563/77, ad 1640/280। JPG export পূর্ণ-resolution canvas থেকে হয়।
- Social credentials/API calls নেই বলে social platforms-কে disconnected দেখানো হয়। কোনো fake test-post success নেই।

## এখনও বাস্তবায়িত নয়

এটি সম্পূর্ণ নির্দেশনার সমাপ্ত deliverable নয়। এই build-এ OAuth এবং প্রকৃত Facebook/X/Instagram/TikTok/WhatsApp/Telegram/Threads publishing, webhook/deduplicated post-ingestion queue, failed-post retries, post-result ledger, staff photo/ID-card front/back export, template full editing workflow, crop UI, log filtering/audit retention, backup/restore, login flows beyond WordPress admin, এবং scheduler-এর প্রকৃত card generation/publishing adapter অনুপস্থিত। WP-Cron hook `dnbangla_dashboard_schedule_due` নির্দিষ্ট সময়ে fire করে; এটি নিজে কোনো social post প্রকাশ করে না।

App password settings encrypted at rest using WordPress salts and OpenSSL AES-256-GCM. WordPress salt বদলালে সংরক্ষিত encrypted password আর decode নাও হতে পারে; সেক্ষেত্রে নতুন Application Password সংরক্ষণ করতে হবে। Social secrets এই plugin-এ এখনো নেওয়া বা সংরক্ষণ করা হয় না।

## যাচাই

Local checks:

```sh
php -l wp-content/plugins/dnbangla-dashboard/dnbangla-dashboard.php
node --check wp-content/plugins/dnbangla-dashboard/assets/admin.js
```

এই repository-তে WordPress core/database installation নেই, তাই plugin activation, REST permissions, database migrations, Media Library upload, cron execution, বা বাস্তব WordPress posts end-to-end যাচাই করা হয়নি। Production-এ install করার আগে staging site-এ পরীক্ষা করুন এবং backup নিন।
