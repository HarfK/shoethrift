"""Rolling-origin backtest and error metrics for the revenue models."""
from __future__ import annotations

import copy
import time

import numpy as np
import pandas as pd


def _fold(model, series: pd.Series, origin, end) -> pd.DataFrame:
    """Fit on everything before `origin`, forecast `origin` .. `end`."""
    dates = pd.date_range(origin, end, freq="D")
    forecast = copy.deepcopy(model).fit(series[series.index < origin]).predict(dates)
    forecast["actual"] = series[dates].values
    forecast["origin"] = origin
    forecast["model"] = model.name
    return forecast


def backtest_window(models, series: pd.Series, start, end, step: int = 7, n_jobs: int = 1) -> pd.DataFrame:
    """Rolling origin inside [start, end]: every `step` days, fit each model on all data before that
    day and forecast the next `step` days (the last fold may be shorter). Data after `end` is never used.
    Returns one row per model and date: actual, yhat, lower, upper, origin."""
    start, end = pd.Timestamp(start), pd.Timestamp(end)
    origins = pd.date_range(start, end, freq=f"{step}D")
    folds = [(origin, min(origin + pd.Timedelta(days=step - 1), end)) for origin in origins]
    rows = []
    for model in models:
        started = time.perf_counter()
        if n_jobs == 1:
            parts = [_fold(model, series, o, e) for o, e in folds]
        else:
            from joblib import Parallel, delayed

            parts = Parallel(n_jobs=n_jobs)(delayed(_fold)(model, series, o, e) for o, e in folds)
        rows += parts
        print(f"{model.name:22} {len(folds)} folds in {time.perf_counter() - started:.0f} s")
    result = pd.concat(rows).rename_axis("date").reset_index()
    return result[["model", "origin", "date", "actual", "yhat", "lower", "upper"]]


def rolling_origin_backtest(models, series: pd.Series, test_days: int = 91, step: int = 7, n_jobs: int = 1) -> pd.DataFrame:
    """Backtest over the last `test_days` days of `series` (see backtest_window)."""
    return backtest_window(models, series, series.index[-test_days], series.index[-1], step, n_jobs)


def metrics(backtest: pd.DataFrame, step: int = 7) -> pd.DataFrame:
    """MAE, RMSE, WAPE, weekly-total error and bias, and 80% interval coverage per model.
    Weekly figures use complete folds only. If the backtest has `raw_lower` / `raw_upper`
    (the model's own interval before calibration), their coverage is reported too."""
    rows = []
    for name, group in backtest.groupby("model", sort=False):
        error = group["yhat"] - group["actual"]
        folds = group.groupby("origin")
        weekly = folds[["actual", "yhat"]].sum()[folds.size() == step]
        row = {
            "model": name,
            "MAE (RM)": error.abs().mean(),
            "RMSE (RM)": np.sqrt((error ** 2).mean()),
            "WAPE (%)": 100 * error.abs().sum() / group["actual"].abs().sum(),
            "weekly total error (%)": 100 * ((weekly["yhat"] - weekly["actual"]).abs() / weekly["actual"]).mean(),
            "weekly total bias (%)": 100 * ((weekly["yhat"] - weekly["actual"]) / weekly["actual"]).mean(),
            "80% interval coverage (%)": 100 * ((group["actual"] >= group["lower"]) & (group["actual"] <= group["upper"])).mean(),
        }
        if "raw_lower" in group:
            row["model's own interval coverage (%)"] = 100 * ((group["actual"] >= group["raw_lower"]) & (group["actual"] <= group["raw_upper"])).mean()
        rows.append(row)
    return pd.DataFrame(rows).sort_values("MAE (RM)").reset_index(drop=True)
