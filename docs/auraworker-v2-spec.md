# AuraWorker v2 Spec

## מצב נוכחי (v1.3.0-beta.6)
- 6 קבצי PHP, 619 שורות updater
- Endpoints: status, updates, core/plugin/theme/translations update, database, self-update
- Security: auth layer בסיסי

## בעיות בגרסה הנוכחית
1. תוספי Pro ללא רישיון לא מתעדכנים (Elementor Pro, WP Rocket, ACF Pro)
2. אתרים עם 50+ תוספים - timeout ובעיות memory
3. אין rollback אם עדכון שובר משהו
4. אין health check אחרי עדכון

## Roadmap v2

### Phase 1: יציבות (P1)

#### 1.1 Chunked Updates
- עדכון תוספים בקבוצות של 5 (לא הכל בבת אחת)
- Queue-based processing
- Memory management (flush between chunks)
- Timeout handling per chunk

#### 1.2 Health Check Post-Update
- בדיקת HTTP status אחרי כל עדכון
- בדיקת PHP fatal errors (error log)
- בדיקת white screen of death
- אם נכשל → rollback אוטומטי

#### 1.3 Rollback Mechanism
- שמירת plugin/theme files לפני עדכון (zip)
- אם health check נכשל → שחזור אוטומטי
- לוג של כל rollback
- התראה ל-Aura dashboard

#### 1.4 Backup Before Update
- קריאה ל-backup provider (Cloudways/host) לפני עדכון
- אם backup נכשל → לא מעדכנים
- Configurable: skip backup for minor updates

### Phase 2: תוספי Pro (P2)

#### 2.1 License Management
- טבלת רישיונות: plugin_slug, license_key, provider, expires_at
- תמיכה ב:
  - Elementor Pro (EDD license)
  - WP Rocket (custom API)
  - ACF Pro (custom API)
  - Rank Math Pro (EDD)
  - WooCommerce extensions (WC API)
  - Gravity Forms (custom)

#### 2.2 Pro Plugin Update Detection
- בדיקת עדכונים דרך API של כל provider
- Fallback: בדיקת transients של WP
- Report: available vs installed vs latest

#### 2.3 Pro Plugin Auto-Update
- הורדת ZIP מה-provider API עם license key
- התקנת עדכון דרך WP upgrader
- Rollback אם נכשל
- License expiry alerts

### Phase 3: בטיחות (P3)

#### 3.1 Staging Environment
- יצירת staging copy של האתר
- בדיקת עדכונים ב-staging קודם
- אם staging עובר → apply ל-production
- Integration עם Cloudways staging API

#### 3.2 Plugin Compatibility Check
- בדיקה אם plugin X תואם ל-WP version, PHP version
- בדיקה אם יש conflicts ידועים בין תוספים
- Database: compatibility matrix (מתעדכן מ-WP.org API)

#### 3.3 Performance Baseline
- PageSpeed score לפני ועדכון
- TTFB measurement
- Memory usage comparison
- DB query count comparison

### Phase 4: אבטחה (P4)

#### 4.1 Vulnerability Scanning
- בדיקת CVEs עבור כל plugin/theme
- WPScan API integration
- Patchstack API integration
- Critical vulnerability = force update

#### 4.2 PHP Compatibility
- בדיקת PHP version compatibility לפני עדכון
- Report: plugins שלא תומכים ב-PHP 8.x
- Automated fix suggestions

#### 4.3 Security Hardening Audit
- File permissions check
- wp-config.php security constants
- Database prefix
- Admin username not "admin"
- XML-RPC disabled
- Directory listing disabled

## Endpoints חדשים (v2)

```
POST /aura/v2/update/batch          - עדכון קבוצת תוספים
GET  /aura/v2/health                - health check מורחב
POST /aura/v2/rollback/{plugin}     - rollback ידני
GET  /aura/v2/licenses              - רשימת רישיונות
POST /aura/v2/licenses              - הוספת רישיון
GET  /aura/v2/compatibility         - בדיקת תאימות
GET  /aura/v2/vulnerabilities       - סריקת פגיעויות
POST /aura/v2/staging/create        - יצירת staging
POST /aura/v2/staging/apply         - העברה ל-production
GET  /aura/v2/performance/baseline  - baseline ביצועים
```

## מבנה קבצים (v2)

```
aura-worker/
├── aura-worker.php
├── includes/
│   ├── class-aura-worker.php
│   ├── class-aura-worker-api.php (v2 routes)
│   ├── class-aura-worker-security.php
│   ├── class-aura-worker-updater.php (refactored)
│   ├── class-aura-worker-health.php (NEW)
│   ├── class-aura-worker-rollback.php (NEW)
│   ├── class-aura-worker-licenses.php (NEW)
│   ├── class-aura-worker-compatibility.php (NEW)
│   ├── class-aura-worker-vulnerability.php (NEW)
│   └── class-aura-worker-staging.php (NEW)
├── templates/ (admin UI if needed)
└── uninstall.php
```

## Priority Matrix

| Feature | Impact | Effort | Priority |
|---------|--------|--------|----------|
| Chunked Updates | HIGH | M | P1 |
| Health Check | HIGH | S | P1 |
| Rollback | HIGH | L | P1 |
| Backup Before Update | HIGH | S | P1 |
| License Management | HIGH | L | P2 |
| Pro Plugin Updates | HIGH | XL | P2 |
| Staging | MEDIUM | XL | P3 |
| Compatibility Check | MEDIUM | M | P3 |
| Vulnerability Scan | MEDIUM | M | P4 |
| Performance Baseline | LOW | M | P4 |
