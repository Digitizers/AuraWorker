# Aura Plugin Release v1.3.0 - Release Notes

## ✅ Completed Tasks

### 1. Merge v2-spec → main ✅
- Merged branch `v2-spec` into `main` successfully
- Commit: `4cbdec9` (merge commit)
- Pushed to GitHub

### 2. Code Verification ✅
**Plugin Details (after merge):**
- ✅ Plugin Name: "Digitizer Site Worker for Aura"
- ✅ Text Domain: "digitizer-site-worker"
- ✅ Directory: `digitizer-site-worker/`
- ✅ Main file: `digitizer-site-worker.php`
- ✅ Version: 1.3.0-beta.6 (in code)
- ✅ readme.txt: Updated with new name

### 3. Aura Dashboard Updates ✅
**Repository:** `~/Aura` (Digitizers/Aura)
**Commit:** `0e0cf39` - "rename: AuraWorker → Digitizer Site Worker for Aura"

**Files Updated (12 total):**
- `app/auraworker/page.tsx` - Main plugin landing page
- `app/page.tsx` - Homepage references
- `README.md` - Documentation
- `app/about/page.tsx`
- `app/privacy/page.tsx`
- `app/terms/page.tsx`
- `app/apps/AppsClient.tsx`
- `app/apps/[resourceId]/wordpress/WordPressClient.tsx`
- `app/api/.../aurawp/self-update/route.ts`
- `lib/wp/aurawp-client.ts`
- `components/PublicHeader.tsx`

**Changes Made:**
- All "AuraWorker" → "Digitizer Site Worker"
- Plugin download URL → `digitizer-site-worker.zip`
- API update log target → `digitizer-site-worker`
- Menu navigation text updated
- ✅ Kept `/auraworker` URL (backward compatibility)

### 4. GitHub Release ✅
**Tag:** v1.3.0
**Release URL:** https://github.com/Digitizers/AuraWorker/releases/tag/v1.3.0
**Download URL:** https://github.com/Digitizers/AuraWorker/releases/download/v1.3.0/digitizer-site-worker-v1.3.0.zip

**Release Assets:**
- ✅ ZIP file created: `digitizer-site-worker-v1.3.0.zip` (15KB)
- ✅ Uploaded to GitHub Release
- ✅ Release notes included

### 5. WordPress.org Upload Preparation ✅
**ZIP File Location:**
```
/home/gidon/AuraWorker/digitizer-site-worker-v1.3.0.zip
```

**ZIP Contents (verified):**
- ✅ Directory: `digitizer-site-worker/`
- ✅ Main file: `digitizer-site-worker.php`
- ✅ Includes: 4 class files
- ✅ readme.txt: Properly formatted with new name
- ✅ uninstall.php: Included
- ✅ No git files included

**WordPress.org Submission:**
🔴 **Manual Action Required by Ben:**
1. Go to: https://wordpress.org/plugins/developers/add/
2. Upload: `/home/gidon/AuraWorker/digitizer-site-worker-v1.3.0.zip`
3. Plugin slug will be: `digitizer-site-worker`

---

## 📊 Summary

| Task | Status | Notes |
|------|--------|-------|
| Merge v2-spec → main | ✅ Complete | Commit `4cbdec9` |
| Update Aura dashboard | ✅ Complete | 12 files, commit `0e0cf39` |
| Update /auraworker page | ✅ Complete | Full text update |
| Update homepage | ✅ Complete | All references updated |
| Create GitHub Release | ✅ Complete | v1.3.0 published |
| Prepare WP.org ZIP | ✅ Complete | Ready for manual upload |

---

## 🚀 Next Steps (Manual)

1. **Ben uploads ZIP to WordPress.org**
   - Upload `/home/gidon/AuraWorker/digitizer-site-worker-v1.3.0.zip`
   - Slug: `digitizer-site-worker`
   
2. **Test Installation**
   ```bash
   wp plugin install https://github.com/Digitizers/AuraWorker/releases/download/v1.3.0/digitizer-site-worker-v1.3.0.zip --activate
   ```

3. **Verify Aura Dashboard**
   - Check https://my-aura.app/auraworker
   - Verify download link works
   - Test plugin connection

---

## 🔗 Important Links

- **GitHub Repo:** https://github.com/Digitizers/AuraWorker
- **Release:** https://github.com/Digitizers/AuraWorker/releases/tag/v1.3.0
- **Download:** https://github.com/Digitizers/AuraWorker/releases/download/v1.3.0/digitizer-site-worker-v1.3.0.zip
- **Plugin Page:** https://my-aura.app/auraworker
- **Aura Dashboard:** https://my-aura.app

---

**Generated:** 2026-03-24 13:33 GMT+2
**Subagent:** aura-release
