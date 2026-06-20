# TutooPay

Payment reconciliation and crypto payout admin dashboard for [admin.tutoopay.com](http://admin.tutoopay.com).

## Features

- **Statement Upload** — Import PayPal, Payoneer, Stripe, Wise, and bank transfer CSVs
- **Fuzzy Matching** — Match user payment claims against uploaded statements (5% amount tolerance)
- **Approve & Pay** — Two-step workflow: approve matches, then batch-pay via CoinEx API (USDT/TRC20)
- **CoinEx CSV Export** — Generate whitelist import files for CoinEx
- **Role-Based Access** — Admin and Supervisor roles with different permissions
- **Payment Logs** — Full audit trail of all payouts with timestamps
- **Email Notifications** — Automatic approval and payment confirmation emails
- **Live Exchange Rates** — Multi-currency conversion to USD via ExchangeRate API

## Tech Stack

- **Backend:** PHP (deployed on cPanel/shared hosting)
- **Frontend:** Vite + React (compiled, static files)
- **Database:** MySQL
- **Payments:** CoinEx API (USDT withdrawals via TRC20)

## Setup

1. Copy `api/config.example.php` to `api/config.php`
2. Fill in your database credentials, CoinEx API keys, and ExchangeRate API key
3. Upload files to your web server document root
4. Ensure the `admin_sessions/` directory exists and is writable
5. Set up MySQL database with the required tables

## Project Structure

```
api/                    # PHP backend endpoints
  config.php            # Configuration (not committed — use config.example.php)
  auth.php              # Admin authentication
  match.php             # Fuzzy matching engine
  payout.php            # CoinEx withdrawal
  multi_payout.php      # Batch payouts
  statements.php        # Statement management
  users.php             # User management
  payments.php          # Payment records
  payment_logs.php      # Audit logs
  settings.php          # Admin settings
  ...
assets/                 # Compiled frontend JS/CSS bundles
index.html              # SPA entry point
.htaccess               # Apache URL rewriting
```
