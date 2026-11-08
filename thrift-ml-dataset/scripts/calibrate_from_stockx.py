"""
Step 1 - Calibrate real market patterns from the StockX 2019 Data Contest dataset.

The StockX file is REAL resale transaction data (~100k sales, Sep 2017 - Feb 2019).
We do not copy its rows (US hype resale != Malaysian thrift). Instead we extract the
*shape* of real resale demand and pricing, and the thrift generator re-uses it:

  - weekday demand pattern          (which days people buy more)
  - day-to-day persistence (AR(1))  (busy days are followed by busy days)
  - size demand curve               (which sizes sell most)
  - size price premium              (rare sizes sell for more)
  - noise level of daily demand

Usage:  python calibrate_from_stockx.py StockX-Data-Contest-2019-3.csv calibration.json
"""
import json
import sys

import numpy as np
import pandas as pd

src, out = sys.argv[1], sys.argv[2]
s = pd.read_csv(src)
s["date"] = pd.to_datetime(s["Order Date"], format="%m/%d/%y")
s["sale"] = s["Sale Price"].str.replace(r"[$,]", "", regex=True).astype(float)
s["retail"] = s["Retail Price"].str.replace(r"[$,]", "", regex=True).astype(float)

# ---- 1. Weekday pattern (normalised so mean = 1) -------------------------------
wd = s.groupby(s.date.dt.dayofweek).size()
weekday_factor = (wd / wd.mean()).round(4).tolist()  # Mon..Sun

# ---- 2. Day-to-day persistence of demand ----------------------------------------
# Remove the long-term growth trend (28-day centred rolling mean) and measure the
# lag-1 autocorrelation of what is left, on the log scale.
daily = s.groupby("date").size().asfreq("D", fill_value=0)
log_d = np.log1p(daily)
trend = log_d.rolling(28, center=True, min_periods=7).mean()
resid = (log_d - trend).dropna()
# also remove the weekday effect from the residual
resid = resid - resid.groupby(resid.index.dayofweek).transform("mean")
ar1_phi = float(resid.autocorr(1))
resid_sd = float(resid.std())

# ---- 3. Size demand and size price premium --------------------------------------
# StockX sizes are US men's. UK ~= US - 1 (men's). We keep UK 3.0 - 12.0.
s["uk"] = s["Shoe Size"] - 1.0
s = s[(s.uk >= 3.0) & (s.uk <= 12.0)]
size_share = (s.groupby("uk").size() / len(s)).round(5)
# premium = median(sale/retail) of the size / overall median
ratio = s.sale / s.retail
size_premium = (ratio.groupby(s.uk).median() / ratio.median()).round(4)

cal = {
    "source": "StockX Data Contest 2019 (99,956 real resale transactions, US, 2017-09 to 2019-02)",
    "weekday_factor_mon_to_sun": weekday_factor,
    "ar1_phi_daily_log_demand": round(ar1_phi, 4),
    "residual_sd_daily_log_demand": round(resid_sd, 4),
    "size_demand_share_uk": {str(k): float(v) for k, v in size_share.items()},
    "size_price_premium_uk": {str(k): float(v) for k, v in size_premium.items()},
}
with open(out, "w") as f:
    json.dump(cal, f, indent=2)
print(json.dumps(cal, indent=2))
