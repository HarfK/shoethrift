"""
Step 3 - Validate the generated dataset (use the output in Chapter 4 as evidence).

Checks: data integrity, real-world patterns (trend, weekday, campaign days, Raya),
and that the price is learnable (quick Random Forest baseline).

Usage:  python validate_dataset.py seed-data
"""
import sys

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split

D = sys.argv[1].rstrip("/") + "/"
p = pd.read_csv(D + "products.csv")
o = pd.read_csv(D + "orders.csv")
it = pd.read_csv(D + "order_items.csv")

print("=== Integrity ===")
good = o[o.status.isin(["paid", "shipped", "delivered", "completed"])].copy()
gi = it[it.order_id.isin(o[~o.status.isin(["cancelled", "failed"])].id)]
print("each pair sold at most once :", not gi.product_id.duplicated().any())
m = gi.merge(p[["id", "created_at"]], left_on="product_id", right_on="id", suffixes=("", "_listed"))
print("listed before sold          :", (pd.to_datetime(m.created_at_listed) < pd.to_datetime(m.created_at)).all())
s = it.groupby("order_id").price.sum()
print("order total = items + RM10  :", np.allclose(o.set_index("id").total, s.reindex(o.id).values + 10))

print("\n=== Demand patterns (Malaysia time) ===")
good["local"] = pd.to_datetime(good.created_at) + pd.Timedelta(hours=8)
daily = good.groupby(good.local.dt.date).total.sum()
daily.index = pd.to_datetime(daily.index)
daily = daily.asfreq("D", fill_value=0)
print("monthly revenue (RM):")
print(daily.resample("ME").sum().round(0).to_string())
wd = good.groupby(good.local.dt.dayofweek).size()
print("weekday index Mon..Sun:", (wd / wd.mean()).round(2).tolist())
for day, label in [("2025-11-11", "11.11"), ("2025-12-12", "12.12"), ("2026-03-20", "Hari Raya 2026")]:
    d = pd.Timestamp(day)
    print(f"{label:15s} RM{daily.get(d, 0):6.0f}  vs previous 28-day average RM{daily[d - pd.Timedelta(days=28):d - pd.Timedelta(days=1)].mean():5.0f}")

print("\n=== Price learnability (quick baseline) ===")
s = it.merge(p, left_on="product_id", right_on="id", suffixes=("_sold", ""))
s = s[s.order_id.isin(good.id)]
X = pd.get_dummies(s[["brand", "name", "condition", "sole_condition", "material", "color"]]).assign(
    size=s["size"], box=s.box_included, retail=s.retail_price)
y = s.price_sold
Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.2, random_state=1)
pred = RandomForestRegressor(300, random_state=1, n_jobs=-1).fit(Xtr, ytr).predict(Xte)
print(f"Random Forest: R2 = {r2_score(yte, pred):.3f}, MAE = RM{mean_absolute_error(yte, pred):.1f}")
print("median sold price by condition:", s.groupby("condition").price_sold.median().to_dict())
