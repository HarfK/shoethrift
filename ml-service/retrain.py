"""Retrain the 3 saved models from the current database, with the final settings of notebooks 03, 04 and 05.

    ml-service\\venv\\Scripts\\python ml-service\\retrain.py          (or POST /admin/retrain on the API)

- revenue_model: XGBoost + major-event uplift (11.11, 12.12, Black Friday, pre-Raya, Raya closure), calibrated intervals
- restock_model: Random forest on weekly snapshots with stock >= 2 (the report adds the censored-demand correction)
- price_model:   Random forest on price / retail ratio, pairs sold within 45 days of listing, calibrated 80% range

Only the models are refitted; the evaluation figures in the metadata are carried over from the notebooks' backtests
(re-run the notebooks to re-evaluate). Files are written to a temporary name first and then swapped in.
"""
from __future__ import annotations

import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import joblib

ML_DIR = Path(__file__).resolve().parent
if str(ML_DIR) not in sys.path:
    sys.path.insert(0, str(ML_DIR))

MODEL_DIR = ML_DIR / "models"


def _save(name: str, model, metadata_update: dict) -> None:
    """Write model + metadata atomically, keeping the previous metadata's evaluation sections."""
    path, meta_path = MODEL_DIR / f"{name}.joblib", MODEL_DIR / f"{name}.json"
    previous = json.loads(meta_path.read_text(encoding="utf-8")) if meta_path.exists() else {}
    metadata = {**previous, **metadata_update,
                "retrained_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                "retrained_by": "retrain.py (evaluation figures are from the notebook backtest)"}
    tmp_model, tmp_meta = path.with_suffix(".joblib.tmp"), meta_path.with_suffix(".json.tmp")
    joblib.dump(model, tmp_model)
    tmp_meta.write_text(json.dumps(metadata, indent=2, default=str), encoding="utf-8")
    tmp_model.replace(path)
    tmp_meta.replace(meta_path)


def retrain_revenue() -> dict:
    from forecasting.adjust import MAJOR_EVENTS, EventAdjusted
    from forecasting.data import load_daily_revenue
    from forecasting.models import XGBoostModel

    y = load_daily_revenue()
    model = EventAdjusted(XGBoostModel(), uplift=True, events=MAJOR_EVENTS).fit(y)
    _save("revenue_model", model, {
        "model_name": model.name, "trained_from": f"{y.index.min():%Y-%m-%d}", "trained_until": f"{model.trained_until_:%Y-%m-%d}",
        "training_days": int(len(y)), "event_multipliers_applied": {k: round(v, 3) for k, v in model.applied_multipliers_.items()},
        "interval_offsets": {k: round(v, 3) for k, v in model.offsets_.items()},
    })
    return {"model": model.name, "trained_until": f"{model.trained_until_:%Y-%m-%d}", "rows": int(len(y)),
            "11.11 multiplier": round(model.applied_multipliers_.get("11.11", 1.0), 2)}


def retrain_restock() -> dict:
    from restock.data import FEATURES, MIN_TRAIN_STOCK, build_snapshots, load_restock_data, snapshot_dates
    from restock.models import RandomForestModel

    data = load_restock_data()
    snapshots = build_snapshots(data, snapshot_dates(data))
    train = snapshots[snapshots["stock"] >= MIN_TRAIN_STOCK]
    model = RandomForestModel().fit(train[FEATURES], train["target"])
    _save("restock_model", model, {
        "model_name": model.name, "trained_until": f"{data.last_day:%Y-%m-%d}",
        "training": f"snapshots (Mondays) with stock >= {MIN_TRAIN_STOCK}, {snapshots['date'].min():%Y-%m-%d} to {snapshots['date'].max():%Y-%m-%d}, {len(train)} rows",
    })
    return {"model": model.name, "trained_until": f"{data.last_day:%Y-%m-%d}", "rows": int(len(train))}


def retrain_price() -> dict:
    from pricing.data import FEATURES, before_markdown, load_sales, retail_reference
    from pricing.models import RandomForestModel

    sales = load_sales()
    data = before_markdown(sales)
    model = RandomForestModel("ratio").fit(data[FEATURES], data["sold_price"])
    model.retail_reference_ = retail_reference()
    model.known_models_ = set(data["name"])
    _save("price_model", model, {
        "model_name": model.name, "trained_until": f"{data['ordered_at'].max():%Y-%m-%d}",
        "training_data": f"pairs sold (paid/shipped/delivered/completed) within 45 days of listing: {len(data):,} of {len(sales):,} sold pairs",
        "price_range": {"method": "10th/90th percentile of actual/predicted from 5-fold out-of-fold predictions", "multipliers": list(model.range_)},
    })
    return {"model": model.name, "trained_until": f"{data['ordered_at'].max():%Y-%m-%d}", "rows": int(len(data))}


def retrain_all(log=print) -> dict:
    summary = {}
    for name, job in [("revenue_model", retrain_revenue), ("restock_model", retrain_restock), ("price_model", retrain_price)]:
        started = time.perf_counter()
        summary[name] = {**job(), "seconds": round(time.perf_counter() - started, 1)}
        log(f"{name:14} {summary[name]}")
    return summary


if __name__ == "__main__":
    print("Retraining from the current database ...")
    retrain_all()
    print("Done. Restart the API (or POST /admin/retrain) to use the new models.")
