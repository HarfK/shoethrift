# Thrift Shoe Sales History: dataset for the ML features

Two years of sales history (**1 Nov 2024 – 7 Nov 2026**) for your `thriftstore` database. It's
used to train the revenue forecast, restock prediction and price recommendation models.

| Table | Rows |
|---|---|
| users (customers) | 389 + your 4 test accounts |
| products | 2,859 (2,559 sold · 217 available · 83 hidden) |
| orders | 2,414 (completed, cancelled, failed, refunded, shipped …) |
| order_items | 2,759 |
| user_interactions | 14,867 (view / cart / purchase, used by the recommendation module) |
| addresses / user_preferences | 389 / 155 |

The test customer `customer@thriftstore.test` also gets 26 past orders, so the demo account has a purchase history.

---

## How to install

1. **Back up your database first.** Your phpMyAdmin export `thriftstore.sql` works as the backup.
2. Copy the files into your Laravel project, keeping the same paths:
   ```
   laravel/database/migrations/2026_11_08_000028_add_retail_price_to_products_table.php
   laravel/database/seeders/ThriftHistorySeeder.php
   laravel/database/seeders/data/thrift/*.csv
   ```
3. Run:
   ```bash
   php artisan migrate
   php artisan db:seed --class=ThriftHistorySeeder
   ```
4. Add `'retail_price'` to `$fillable` in `app/Models/Product.php`, and add a "Retail price (RM)"
   input to the admin Add/Edit Product form. The price recommendation model needs it.

### What the seeder does
- **Keeps:** admin@, customer@, cod.blocked@ and tester@thriftstore.test, with their addresses and preferences.
- **Deletes:** all dummy products, orders, carts, reviews, returns, interactions, notifications and the dummy customers.
- **Photos:** reuses your existing photos by model name (every "Nike Dunk Low" gets Dunk Low photos).
  Available and hidden pairs get their **own copy** of the files. Sold pairs share files.
  It assumes photos are on the `public` disk. If yours are on a different disk, change `PHOTO_DISK` in the seeder.
- Generated customers can log in with the password `password`.
- All timestamps are stored in **UTC** (Laravel default). Convert to Asia/Kuala_Lumpur (+8h) when you group sales by day.

---

## How the data was built (for your methodology chapter)

The dataset is **semi-synthetic, calibrated from real resale market data**:

1. **Calibration from real data** (`scripts/calibrate_from_stockx.py`). From the StockX 2019 Data Contest
   dataset (99,956 real sneaker resale transactions), we extracted the weekday demand pattern, the
   day-to-day demand persistence (AR(1), φ = 0.55), the daily noise level, the shoe-size demand curve and the
   size price premium. Results are in `scripts/calibration.json`.
2. **Simulation of a Malaysian thrift shop** (`scripts/generate_thrift_dataset.py`), using:
   - shop growth from about 2 to about 4.6 orders a day
   - Malaysian calendar effects: payday (25th–2nd), 9.9 / 10.10 / 11.11 / 12.12, Black Friday,
     pre-Hari Raya shopping (formal shoes ×2), Raya and CNY dips, Deepavali, school reopening and monsoon season
     (boots up, sandals down)
   - **unique pairs** (stock = 1); weekly restocking from bales based on what was popular about 2 months earlier,
     so trending models and sizes run out (this is the restock signal)
   - pricing: `retail price × model resale ratio × condition × sole condition × box × size premium × hype trend`,
     plus the admin's pricing error. Over-priced pairs sell slower and are marked down 15% after 45 days
     (and again after 100 days)
   - customers who prefer their own size (±0.5), favourite brands and good-value pairs; 45% only want one specific model
   - real order lifecycles: online/COD, failed payments, cancellations, failed COD deliveries, refunds
3. **Validation** (`scripts/validate_dataset.py`) confirms the integrity and the patterns, for example:
   - 11.11: RM1,845 vs a 28-day average of RM783
   - Raya day: RM170 vs RM1,396 (shop closed / balik kampung)
   - Wednesday and Friday busiest, Sunday quietest (from StockX)
   - price is learnable: Random Forest baseline R² = 0.83, MAE = RM34.5

To regenerate (for example with a different seed):
`python generate_thrift_dataset.py calibration.json seed-data`

### Limitations (state these honestly in your report)
- Retail prices per model are approximate Malaysian retail prices; check them against brand websites if needed.
- School-reopening dates are approximate.
- Demand is simulated. The *patterns* come from real data, but individual transactions are not real.
  Once the system is live, real orders are added to the same tables and the models retrain on them.
