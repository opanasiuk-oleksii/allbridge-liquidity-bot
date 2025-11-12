# 🧠 Build a Telegram Bot That Talks to the Blockchain

This repository contains the complete source code and SQL structure used in the **five-part educational series** published on the [Allbridge Core Blog](https://allbridge.io/blog).

Over the course of these articles, you’ll learn how to build a **Telegram bot that interacts with blockchain data** — from first setup to full automation — using the [Allbridge Core REST API](https://docs-core.allbridge.io/sdk/allbridge-core-rest-api), PHP, MySQL, and cron jobs.

---

## 📚 Series Overview

### Part 1 — Setting Up Your Telegram Liquidity Bot
**Title:** *Step 1 – Building the Foundation*  
Learn how to install the Allbridge Core REST API locally, create a Telegram bot via @BotFather, and send your first blockchain query.  
👉 [Read Part 1](https://allbridge.io/blog/core/build-telegram-bot-allbridge-core-part-1/)

---

### Part 2 — Connecting Your Bot to a Database
**Title:** *Tracking Wallets in Real Time*  
Integrate MySQL, create tables for wallets, and let users subscribe to specific addresses for monitoring.  
👉 [Read Part 2](https://allbridge.io/blog/core/build-telegram-bot-allbridge-core-part-2/)

---
### Part 3 — Logging Rewards and Tracking Balances
**Title:** *Teaching Your Bot About Rewards*  
Add a rewards table, fetch real liquidity data from the Allbridge Core API, and store daily snapshots with precision control.  
👉 [Read Part 3](https://allbridge.io/blog/core/build-telegram-bot-allbridge-core-part-3/)

---

### Part 4 — Inline Management and Real-Time Updates
**Title:** *From Console to Interactive Dashboard*  
Turn Telegram into your DeFi control panel with inline buttons, callback actions, and live balance rendering — no typing required.  
👉 [Read Part 4](https://allbridge.io/blog/core/build-telegram-bot-allbridge-core-part-4/)

---

### Part 5 — From Local Bot to Live DeFi Assistant
**Title:** *When Telegram Meets Blockchain*  
Go live. Automate everything with cron, add market analytics, and expand into a full DeFi monitoring assistant.  
👉 [Read Part 5](https://allbridge.io/blog/core/build-telegram-bot-allbridge-core-part-5/)

---

## 🛠️ Tech Stack

- **Language:** PHP 8.1+
- **Framework:** [php-telegram-bot/core](https://github.com/php-telegram-bot/core)
- **Database:** MySQL 8
- **API:** [Allbridge Core REST API](https://docs-core.allbridge.io/sdk/allbridge-core-rest-api)
- **Automation:** cron jobs for data polling
- **Containerization:** Docker (optional)

---

## ⚙️ Quick Setup

1. Clone the repository
   ```bash
   git clone git@github.com:opanasiuk-oleksii/allbridge-liquidity-bot.git
   cd allbridge-liquidity-bot

2. Install dependencies
   ```bash
   composer install

3. Set your environment variables:
- Telegram Bot Token
- MySQL credentials
- Allbridge Core REST API URL

---

## 🔁 Automation

Use cron to periodically update wallet balances and rewards.

Example cron entry (every 10 minutes):
   ```bash
   */10 * * * * /usr/bin/php /var/www/bot/scripts/process_rewards.php >> /var/log/liquidity-bot.log 2>&1
   ```

---

## 🚀 Live Demo
Try the live version here:

👉 [@allbridge_lp_bot](https://t.me/allbridge_lp_bot)

Commands:
- **/start** - Welcome and quick guide
- **/subscribe** - Add a new wallet
- **/subscriptions** - Manage or refresh your portfolio

---

## 📈 Future Expansion Ideas
- Monitor all Allbridge Core pools and alert on APR spikes
- Detect arbitrage or flow imbalances between chains
- Build weekly performance reports from the rewards table
- Add web visualization or Telegram mini-app integration

---

## 🐘 Fun Fact

PHP isn’t dead - it’s just quietly running DeFi bots now.
Your liquidity speaks fluent PHP. 😎

---

## Thank you
If this bot saved you some rewards, spotted an APR spike, or just made you smile,
you can fuel its future upgrades with a any donation:

**EVM:** `0x14cf674baeec8ab17456af1a879f05982c6269be`  
**Solana:** `6C1epvXXXspw8hHkMEtwo9jwneiqkrrPRj7c9k4Tu3M`  
**Tron:** `TEPcXAzyRdnDnFAjKkcJaggoQDyc9dF7YJ`

It’s like tipping your bot for good service - and yes, it *does* run on PHP, so it appreciates the emotional support. 😎
