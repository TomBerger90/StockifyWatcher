# StockifyWatcher: Real-Time Shopify Inventory Monitoring & Alert System

StockifyWatcher is an automated monitoring tool designed to track inventory changes on Shopify stores. It's perfect for those who need to keep an eye on store activities such as new product listings, price changes, restocking, and out-of-stock notifications, without requiring access to the store's API.

## Features

- **New Product Alerts**: Get notified when new products are added.
- **Price Change Alerts**: Receive updates when prices change.
- **Restock Alerts**: Be informed when products are restocked.
- **Out of Stock Alerts**: Know when products go out of stock.
- **WhatsApp Notifications**: Receive alerts directly on your WhatsApp, with SMS option available.

## Setup and Installation

### Prerequisites

- PHP environment.
- MySQL database.
- Composer for PHP dependency management.

### Installing Dependencies

StockifyWatcher uses Twilio for messaging services. To install Twilio PHP Client, run:

```bash
composer require twilio/sdk
```

### Configuration

#### Database and Twilio Setup

1. Rename `Config.php.sample` to `Config.php`.
2. Update the database connection parameters (`$host`, `$dbname`, `$username`, `$password`).
3. Configure Twilio parameters (`$sid`, `$token`, `$from`, and `$to` for your Twilio and WhatsApp settings).

#### Adding a Shopify Store

Use `add_shop.php` to add a new Shopify store to monitor:

```bash
php add_shop.php [Shop Name] [Shop URL]
```


## Usage
Run `check_shop.php` to start monitoring the Shopify stores:
```bash
php check_shop.php
```

### Automating with Cron
To automatically check for updates every hour, add the following line to your crontab (with `crontab -e` command):

```bash
0 * * * * /usr/bin/php /path/to/your/check_shop.php >> /var/log/stockifywatcher.log 2>&1
```

## Notes

1. Ensure proper permissions for the script to access the log file.
2. Modify the notification mechanism in Config.php if you prefer SMS alerts over WhatsApp.

## Contributing
Contributions, issues, and feature requests are welcome. Feel free to check [issues page](https://github.com/TomBerger90/StockifyWatcher/issues) if you want to contribute.


## License
Distributed under the MIT License. See `LICENSE` for more information.


Happy Monitoring with StockifyWatcher!
