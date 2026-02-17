# GSheet SFTP Sync

**Automatically export Google Sheets to your SFTP server daily or hourly.**

A free WordPress plugin that receives Google Sheets exports via API and uploads them to any SFTP server. Perfect for automated inventory feeds, data syncs, and backups.

![Version](https://img.shields.io/badge/version-1.3.0-blue)
![License](https://img.shields.io/badge/license-GPL--2.0-green)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)

## Features

- ✅ **Easy Setup** – Configure everything from WordPress admin
- ✅ **Secure API** – Auto-generated API keys protect your endpoint
- ✅ **Flexible Scheduling** – Daily or hourly exports
- ✅ **Filename Options** – Dated filenames or overwrite mode
- ✅ **Multiple Formats** – Export as CSV or XLSX
- ✅ **Activity Logs** – Track all uploads and errors
- ✅ **Pre-built Script** – Copy-paste Google Apps Script included
- ✅ **100% Free** – No paid services required

## How It Works

```
Google Sheet → Apps Script (scheduled) → HTTP POST → WordPress Plugin → SFTP Server
```

1. Google Apps Script exports your sheet on a schedule
2. Sends the file to your WordPress site via REST API
3. The plugin receives the file and uploads it to your SFTP server

---

## Installation

### Method 1: Upload ZIP
1. Download the latest release ZIP
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate

### Method 2: Manual
1. Download/clone this repository
2. Copy the `gsheet-sftp-sync` folder to `/wp-content/plugins/`
3. Activate via **Plugins** menu

---

## Step-by-Step Setup Guide

### Step 1: Activate & Configure the Plugin

1. Go to **Settings → GSheet SFTP Sync**
2. You'll see your auto-generated **API Key** and **Endpoint URL** at the top

### Step 2: Enter SFTP Credentials

Fill in your SFTP server details:

| Field | Description | Example |
|-------|-------------|---------|
| **SFTP Host** | Server IP or hostname | `sftp.example.com` or `123.45.67.89` |
| **SFTP Port** | Usually 22 or custom | `22` or `15492` |
| **Username** | SFTP username | `myuser` |
| **Password** | SFTP password | `mypassword` |
| **Remote Path** | Destination folder (must end with `/`) | `/uploads/` or `/www/feeds/` |

Click **Save Settings**.

### Step 3: Test the SFTP Connection

Click **Test SFTP Connection** to verify your credentials work.

**Common errors:**
- "Could not connect" → Check host and port
- "Authentication failed" → Check username and password
- "No SFTP library available" → See [SFTP Library Requirements](#sftp-library-requirements)

### Step 4: Configure Export Settings

| Setting | Options | Description |
|---------|---------|-------------|
| **Schedule** | Daily / Hourly | How often to export |
| **Daily Export Hour** | 00:00 - 23:00 | Time of day (your timezone) |
| **Filename Mode** | Dated / Overwrite | New file each time or replace |
| **Base Filename** | Any name | e.g., `inventory`, `products` |
| **Export Format** | CSV / XLSX | File format |

**Filename examples:**
- Dated: `inventory_2026-01-07_140000.csv`
- Overwrite: `inventory.csv`

Click **Save Settings**.

### Step 5: Set Up Google Apps Script

1. Open your Google Sheet
2. Go to **Extensions → Apps Script**
3. Delete any existing code
4. Go back to your WordPress plugin settings
5. Click **"Show Google Apps Script Code"**
6. **Copy the entire code** (it's pre-configured with your settings)
7. Paste into the Apps Script editor
8. Press **Ctrl+S** to save
9. Name your project (e.g., "SFTP Export")

### Step 6: Run Initial Test

1. In Apps Script, make sure `exportAndUpload` is selected in the function dropdown
2. Click **Run**
3. **Authorize the script** when prompted:
   - Click "Review Permissions"
   - Choose your Google account
   - Click "Advanced" → "Go to [project name] (unsafe)"
   - Click "Allow"
4. Check the execution log for success message
5. Verify the file appears on your SFTP server

### Step 7: Enable Automatic Schedule

1. In the function dropdown, select **`setupTrigger`**
2. Click **Run**
3. The trigger is now active!

**To verify:** Click the clock icon (⏰) in the left sidebar to see your trigger.

---

## Important: After Changing Plugin Settings

**⚠️ You must re-copy the Apps Script after changing export settings!**

The settings (schedule, filename mode, format, etc.) are embedded in the generated Apps Script. If you change settings in WordPress:

1. Save the new settings
2. Click "Show Google Apps Script Code"
3. Copy the new code
4. Paste into Google Apps Script (replacing the old code)
5. Save
6. Run `setupTrigger` again if you changed the schedule

---

## Troubleshooting

### Error: "SFTP configuration incomplete"

**Cause:** SFTP settings not saved in WordPress.

**Fix:** 
1. Go to Settings → GSheet SFTP Sync
2. Enter all SFTP credentials
3. Click Save Settings

### Error: "rest_forbidden" or "401 Unauthorized"

**Cause:** API key mismatch between Apps Script and WordPress.

**Fix:**
1. Go to Settings → GSheet SFTP Sync
2. Copy the API Key shown at the top
3. In Google Apps Script, update `CONFIG.API_KEY` with the new key
4. Or re-copy the entire Apps Script code (recommended)

### Error: "SFTP authentication failed"

**Cause:** Wrong SFTP credentials.

**Fix:**
1. Double-check username and password
2. Verify the host and port are correct
3. Test with an SFTP client (FileZilla, WinSCP) first
4. Check if your password has special characters that might need escaping

**Note:** Passwords with special characters like `+`, `@`, `!` should work, but verify they're entered correctly.

### Error: "No SFTP library available"

**Cause:** Neither ssh2 PHP extension nor phpseclib is installed.

**Fix (Option 1 - phpseclib):**
```bash
cd /path/to/wp-content/plugins/gsheet-sftp-sync
composer require phpseclib/phpseclib:~3.0
```

**Fix (Option 2):** Contact your host to enable the ssh2 PHP extension.

### Error: "Could not connect to SFTP server"

**Cause:** Network/firewall issue or wrong host/port.

**Fix:**
1. Verify the host IP/hostname is correct
2. Verify the port number
3. Check if your server's firewall allows outbound connections on that port
4. Try connecting from your server via command line: `ssh -p PORT user@host`

### Error: "Failed to write file to SFTP"

**Cause:** Remote path doesn't exist or no write permission.

**Fix:**
1. Verify the remote path exists on the SFTP server
2. Check write permissions on that directory
3. Make sure the path ends with `/`

### Trigger Not Running

**Symptoms:** Trigger is set but exports don't happen.

**Fix:**
1. Check Apps Script executions: Extensions → Apps Script → Executions
2. Look for failed executions and error messages
3. Re-run `setupTrigger` to recreate the trigger
4. Make sure you authorized all permissions

### Need to Regenerate API Key

If you suspect your API key is compromised:

1. Go to Settings → GSheet SFTP Sync
2. Click **Regenerate** next to the API Key
3. **Important:** Re-copy the Apps Script code to your Google Sheet
4. The old API key will stop working immediately

---

## SFTP Library Requirements

The plugin tries these methods in order:

1. **ssh2 PHP extension** (fastest, if available)
2. **phpseclib3** (recommended fallback)
3. **phpseclib 2.x** (legacy support)

Most managed WordPress hosts (including Kinsta) have phpseclib available or allow installing it via Composer.

---

## Google Apps Script Quotas (Free Tier)

| Resource | Daily Limit |
|----------|-------------|
| URL Fetch calls | 20,000 |
| Script runtime | 6 min per execution |
| Triggers | 20 per user |

These limits are very generous for typical use cases.

---

## Security Best Practices

1. **Keep your API key secret** – Don't share it publicly
2. **Use HTTPS** – Your WordPress site should use SSL
3. **Regenerate keys periodically** – If you suspect a leak
4. **Monitor logs** – Check the plugin logs for unauthorized attempts

---

## Multiple Sheets / Sites

### Multiple Sheets → One WordPress Site
Each Google Sheet can have its own Apps Script pointing to the same WordPress endpoint. Just use different base filenames.

### One Sheet → Multiple Sites
You can create multiple Apps Scripts in the same sheet, each pointing to a different WordPress endpoint.

---

## Changelog

### 1.3.0
- **Fixed:** CSV export now uses Google's native CSV export URL instead of manual CSV building
- **Fixed:** WP All Import not recognizing CSV column headers
- CSV output is now identical to Google Sheets "File > Download > CSV"
- Updated generated Google Apps Script code
- Updated WordPress compatibility to 6.9

### 1.2.0
- **Security:** Improved password encryption using AES-256-CBC with WordPress salts
- **Security:** Added rate limiting (60 requests/minute) to prevent API abuse
- Added `composer.json` for easier phpseclib dependency management
- Added `index.php` security files to prevent directory listing
- Updated WordPress compatibility to 6.7

### 1.1.0
- Added Export Settings section in admin
- New schedule options: Daily or Hourly
- New filename mode: Dated (unique files) or Overwrite (same file each time)
- Configurable base filename and export format (CSV/XLSX)
- Apps Script now auto-configured with plugin settings
- Improved SFTP error messages for debugging

### 1.0.0
- Initial release
- SFTP upload via ssh2 or phpseclib
- REST API endpoint for receiving files
- Admin settings page
- Activity logging
- Google Apps Script generator

---

## Author

**Olivier Bigras**  
Website: [https://olivierbigras.com](https://olivierbigras.com)

---

## License

GPL v2 or later - [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
