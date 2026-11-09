"""Re-create revenue forecasts for recent past days, and print them as JSON. Run by `php artisan ml:backfill-forecasts`.

    ml-service\\venv\\Scripts\\python ml-service\\backfill_forecasts.py --days=14 --today=2026-11-09

The days are split into 7-day blocks. For each block the final model (XGBoost + major-event uplift) is refitted on
the sales BEFORE that block only, then forecasts the block: the way the weekly retrain works for real, so a
backfilled forecast never saw the day it predicts. The saved models and the database are not changed.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import pandas as pd

ML_DIR = Path(__file__).resolve().parent
if str(ML_DIR) not in sys.path:
    sys.path.insert(0, str(ML_DIR))

BLOCK_DAYS = 7


def make_model():
    from forecasting.adjust import MAJOR_EVENTS, EventAdjusted
    from forecasting.models import XGBoostModel

    return EventAdjusted(XGBoostModel(), uplift=True, events=MAJOR_EVENTS)


def backfill(today, days: int = 14, history: pd.Series | None = None, new_model=make_model) -> dict:
    """Forecasts for the `days` complete days before `today` (a Malaysian date)."""
    end = pd.Timestamp(today).normalize() - pd.Timedelta(days=1)
    start = end - pd.Timedelta(days=days - 1)
    if history is None:
        from forecasting.data import load_daily_revenue

        history = load_daily_revenue(until=end)

    rows, name = [], None
    for origin in pd.date_range(start, end, freq=f"{BLOCK_DAYS}D"):
        train = history[history.index < origin]
        model = new_model().fit(train)
        name = model.name
        dates = pd.date_range(origin, min(origin + pd.Timedelta(days=BLOCK_DAYS - 1), end), freq="D")
        for day, forecast in zip(dates, model.predict(dates).itertuples()):
            rows.append({"date": f"{day:%Y-%m-%d}", "made_on": f"{origin - pd.Timedelta(days=1):%Y-%m-%d}",
                         "trained_until": f"{train.index.max():%Y-%m-%d}", "forecast": round(float(forecast.yhat), 2),
                         "lower": round(float(forecast.lower), 2), "upper": round(float(forecast.upper), 2)})
    return {"model": name, "rows": rows}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--days", type=int, default=14)
    parser.add_argument("--today", required=True, help="Today's Malaysian date, YYYY-MM-DD")
    arguments = parser.parse_args()
    print(json.dumps(backfill(arguments.today, arguments.days)))
