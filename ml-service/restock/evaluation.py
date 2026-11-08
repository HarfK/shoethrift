"""Rolling-origin backtest and metrics for the restock models."""
from __future__ import annotations

import copy

import numpy as np
import pandas as pd

from .data import FEATURES, HORIZON, MIN_TRAIN_STOCK
from .models import predict_demand


def backtest(models, snapshots: pd.DataFrame, test_dates) -> pd.DataFrame:
    """For each test date t, train each model on snapshots whose 14-day target ended before t
    (date <= t - 14 days) with stock >= MIN_TRAIN_STOCK, then predict every group at t."""
    rows = []
    for t in pd.DatetimeIndex(test_dates):
        train = snapshots[(snapshots["date"] <= t - pd.Timedelta(days=HORIZON)) & (snapshots["stock"] >= MIN_TRAIN_STOCK)]
        test = snapshots[snapshots["date"] == t]
        for model in models:
            fitted = copy.deepcopy(model).fit(train[FEATURES], train["target"])
            pred = predict_demand(fitted, test[FEATURES])
            rows.append(test[["date", "group", "stock", "target"]].assign(model=model.name, yhat=pred["yhat"].values,
                                                                          upper=pred["upper"].values, train_rows=len(train)))
    return pd.concat(rows, ignore_index=True)


def with_censoring_correction(results: pd.DataFrame, data, model_name: str, config: dict | None = None) -> pd.DataFrame:
    """Add a "<model> + censored-demand correction" copy of one model's backtest rows: for groups with stock < 2
    at the test date, demand = max(model, in-stock rate x 14), using only data before that date."""
    from .models import poisson_upper
    from .report import RESTOCK_CONFIG, correct_censored, in_stock_demand

    config = {**RESTOCK_CONFIG, **(config or {})}
    parts = []
    for date, rows in results[results["model"] == model_name].groupby("date"):
        rows = rows.set_index("group")
        fixed = correct_censored(rows["yhat"], rows["stock"], in_stock_demand(data, date, config), config)
        rows = rows.assign(yhat=fixed["demand"], upper=poisson_upper(fixed["demand"]), demand_source=fixed["source"],
                           model=f"{model_name} + censored-demand correction")
        parts.append(rows.reset_index())
    return pd.concat([results, *parts], ignore_index=True)


def _prf(actual: pd.Series, predicted: pd.Series) -> tuple[float, float, float]:
    tp = int((actual & predicted).sum())
    precision = tp / predicted.sum() if predicted.sum() else 0.0
    recall = tp / actual.sum() if actual.sum() else 0.0
    f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
    return precision, recall, f1


def metrics(results: pd.DataFrame) -> pd.DataFrame:
    """MAE / RMSE / upper-bound coverage on snapshots with stock >= 2 (where sales ~ demand), and sell-out
    detection on snapshots with stock >= 1: actual sell-out = sales in the next 14 days >= stock,
    predicted sell-out = predicted demand >= stock (point forecast), also shown for the 80% upper bound."""
    rows = []
    for name, group in results.groupby("model", sort=False):
        demand = group[group["stock"] >= MIN_TRAIN_STOCK]
        error = demand["yhat"] - demand["target"]
        stocked = group[group["stock"] >= 1]
        actual = stocked["target"] >= stocked["stock"]
        precision, recall, f1 = _prf(actual, stocked["yhat"] >= stocked["stock"])
        _, recall_up, f1_up = _prf(actual, stocked["upper"] >= stocked["stock"])
        rows.append({
            "model": name,
            "MAE (units)": error.abs().mean(),
            "RMSE (units)": np.sqrt((error ** 2).mean()),
            "bias (units)": error.mean(),
            "80% upper coverage (%)": 100 * (demand["target"] <= demand["upper"]).mean(),
            "sell-out precision": precision,
            "sell-out recall": recall,
            "sell-out F1": f1,
            "F1 (upper-bound rule)": f1_up,
            "recall (upper-bound rule)": recall_up,
            "sell-outs (actual)": int(actual.sum()),
            "group-weeks scored": len(stocked),
        })
    table = pd.DataFrame(rows)
    return table.sort_values(["sell-out F1", "MAE (units)"], ascending=[False, True]).reset_index(drop=True)
