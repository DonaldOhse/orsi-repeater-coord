# ORSI Repeater Coordination System

A full-featured amateur radio repeater frequency coordination web application built for the Oklahoma Repeater Society, Inc. (ORSI) — W5DRO.

## Features

- 📡 **Repeater Database** — Full listing with search, filtering, and map view
- 🗺️ **Interactive Map** — Leaflet-based map with repeater locations
- 📋 **Coordination Requests** — Public form with automatic frequency conflict checking
- 🔄 **Update Requests** — Trustees can submit repeater info updates with verification
- 📧 **Ticket System** — Public contact form with email reply integration via POP3
- 🏛️ **FCC Integration** — 1.6M license records with weekly auto-update
- ⚖️ **License Enforcement** — Automated expired license detection and workflow
- 💀 **Silent Key Detection** — Weekly QRZ.com check for deceased trustees
- 📊 **Admin Dashboard** — Full coordinator workflow with district management
- 💬 **Coordinator Chat** — Internal chat with @mention email notifications
- 📜 **Letter Generator** — PDF and email letter generation for enforcement actions
- 🔍 **Frequency Check Tool** — Map-based frequency availability checker
- 📤 **Trustee Outreach** — Email outreach with QRZ lookup and history tracking

## Requirements

- Ubuntu 20.04+ / Debian 11+
- Apache 2.4+ with mod_rewrite
- PHP 8.1+ with extensions: pdo, pdo_mysql, simplexml, mbstring, mailparse
- MySQL 8.0+ or MariaDB 10.6+
- Python 3.10+ with mysql-connector-python
- Composer
- reportlab (pip3 install reportlab)

## Quick Install

```bash
# 1. Clone the repository
git clone https://github.com/YOUR_ORG/orsi-repeater-coord.git
cd orsi-repeater-coord

# 2. Install PHP dependencies
composer install

# 3. Install Python dependencies
pip3 install mysql-connector-python reportlab --break-system-packages

# 4. Install PHP mailparse extension
sudo apt-get install php-mailparse

# 5. Create database
mysql -u root -e "CREATE DATABASE repeater_coord CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -e "CREATE USER 'repeater_user'@'localhost' IDENTIFIED BY 'your_password';"
mysql -u root -e "GRANT ALL PRIVILEGES ON repeater_coord.* TO 'repeater_user'@'localhost';"

# 6. Import schema
mysql -u repeater_user -p repeater_coord < database/schema.sql
mysql -u repeater_user -p repeater_coord < database/default_data.sql

# 7. Configure
cp includes/config.example.php includes/config.php
nano includes/config.php  # Edit with your settings

# 8. Set permissions
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 uploads/ splat_cache/

# 9. Configure Apache
sudo cp .htaccess /etc/apache2/sites-available/repeater-coord.conf
sudo a2ensite repeater-coord
sudo a2enmod rewrite
sudo systemctl restart apache2

# 10. Run initial FCC import (~90 minutes)
python3 scripts/import_fcc_load.py
```

## Cron Jobs

```cron
# Daily renewal emails at 8am
0 8 * * * php /path/to/admin/send_renewals.php

# Weekly FCC database import - Monday 2am
0 2 * * 1 python3 /path/to/scripts/import_fcc_load.py >> /var/log/orsi_fcc_import.log

# Weekly license enforcement - Monday 3am  
0 3 * * 1 php /path/to/admin/license_enforcement.php >> /var/log/orsi_enforcement.log

# Weekly silent key check - Monday 4am
0 4 * * 1 php /path/to/scripts/check_silent_keys.php >> /var/log/orsi_sk_check.log

# Nightly backup - 2am
0 2 * * * /usr/local/bin/orsi-backup >> /var/log/orsi_backup.log

# Ticket email polling - every 5 minutes
*/5 * * * * php /path/to/scripts/poll_tickets_email.php >> /var/log/orsi_tickets_email.log
```

## Configuration

Copy `includes/config.example.php` to `includes/config.php` and update:

- Database credentials
- SMTP mail server settings
- Organization name and URL
- QRZ API credentials (for Silent Key detection)
- Base path

## Credits

Built for the Oklahoma Repeater Society, Inc. (ORSI) — W5DRO  
https://oklahomarepeatersociety.org  
coordination@oklahomarepeatersociety.org

73 de W5DRO
