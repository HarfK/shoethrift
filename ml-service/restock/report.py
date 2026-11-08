"""The admin's restock report: what to restock (and in which UK sizes), and what is overstocked.

    build_restock_report("2026-11-08")                  # per group (model x size band)
    build_restock_report("2026-11-08", level="brand")   # rolled up per brand (or "category")

Thresholds are in RESTOCK_CONFIG so the admin can change them later.
"""
from __future__ import annotations

import math
from pathlib import Path

import joblib
import numpy as np
import pandas as pd

from .data import BANDS, FEATURES, HORIZON, RestockData, build_snapshots, format_size, load_restock_data
from .models import poisson_upper, predict_demand

MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "restock_model.joblib"

RESTOCK_CONFIG = {
    "horizon_days": HORIZON,  # demand is predicted for the next 14 days
    "high_cover_days": 7,  # HIGH risk: stock lasts less than 7 days
    "medium_cover_days": 14,  # MEDIUM: 7-14 days; LOW: more than 14 days
    "overstock_cover_days": 90,  # OVERSTOCK: more than 90 days of cover ...
    "overstock_min_stock": 3,  # ... and at least 3 pairs in stock
    "size_history_days": 180,  # size shares come from the last 180 days of sales
    "size_smoothing": 0.5,  # pseudo-sales added to every size, so unsold sizes still get a small share
    # Censored-demand correction: for groups with less than 2 pairs, demand is also estimated from the days
    # they actually had stock (sales over the last 180 days / days with stock >= 1), if there were >= 14 such days.
    "censor_stock_below": 2,
    "in_stock_window_days": 180,
    "min_in_stock_days": 14,
}
RISK_ORDER = ["HIGH", "MEDIUM", "LOW", "OVERSTOCK"]


def risk_level(stock: float, cover_days: float, config: dict = RESTOCK_CONFIG) -> str:
    if cover_days > config["overstock_cover_days"] and stock >= config["overstock_min_stock"]:
        return "OVERSTOCK"
    if cover_days < config["high_cover_days"]:
        return "HIGH"
    if cover_days <= config["medium_cover_days"]:
        return "MEDIUM"
    return "LOW"


def _largest_remainder(weights: pd.Series, total: int) -> pd.Series:
    """Split `total` whole pairs in proportion to `weights`."""
    if total <= 0 or weights.sum() <= 0:
        return pd.Series(0, index=weights.index)
    exact = weights / weights.sum() * total
    counts = np.floor(exact).astype(int)
    for idx in (exact - counts).sort_values(ascending=False).index[: total - counts.sum()]:
        counts[idx] += 1
    return counts


def size_plan(data: RestockData, group: str, as_of: pd.Timestamp, upper_demand: float, qty: int,
              config: dict = RESTOCK_CONFIG) -> tuple[pd.DataFrame, str]:
    """Split a group's restock quantity into exact UK sizes.

    share per size = (sales of that size in the last 180 days + 0.5) / (all sales + 0.5 x number of sizes);
    target per size = 80% upper demand x share; gap = target - pairs in stock of that size.
    - fewer pairs to buy than sizes still short: one pair each for the most popular of those sizes;
    - otherwise the pairs are shared out in proportion to the gaps (or to the shares if no size has a gap).
    The text lists the sizes most popular first, with their share: "UK 7 x2 (28%), UK 8 x1 (20%)"."""
    products = data.products[data.products["group"] == group]
    band = products["size_band"].iloc[0]
    low, high = BANDS[band]
    sizes = np.sort(products["size"].unique()) if len(products) else np.arange(low, high + 0.25, 0.5)

    window_start = as_of - pd.Timedelta(days=config["size_history_days"])
    sold = products[(products["sold_at"] >= window_start) & (products["sold_at"] < as_of)].groupby("size").size()
    in_stock = products[(products["listed_at"] < as_of) & (products["out_at"].isna() | (products["out_at"] >= as_of))].groupby("size").size()

    plan = pd.DataFrame(index=pd.Index(sizes, name="size"))
    plan["sold_180d"] = sold.reindex(plan.index, fill_value=0)
    alpha = config["size_smoothing"]
    plan["share"] = (plan["sold_180d"] + alpha) / (plan["sold_180d"].sum() + alpha * len(plan))
    plan["in_stock"] = in_stock.reindex(plan.index, fill_value=0)
    plan["gap"] = (upper_demand * plan["share"] - plan["in_stock"]).clip(lower=0)

    # Most popular first; ties go to the size with the bigger gap, then the smaller size.
    by_popularity = plan.assign(_size=plan.index).sort_values(["share", "gap", "_size"], ascending=[False, False, True]).index
    candidates = [s for s in by_popularity if plan.loc[s, "gap"] > 0] or list(by_popularity)
    plan["restock"] = 0
    if 0 < qty < len(candidates):
        plan.loc[candidates[:qty], "restock"] = 1
    else:
        weights = plan["gap"] if plan["gap"].sum() > 0 else plan["share"]
        plan["restock"] = _largest_remainder(weights, qty)

    plan = plan.loc[by_popularity]
    text = ", ".join(f"{format_size(s)} x{n} ({plan.loc[s, 'share']:.0%})" for s, n in plan["restock"].items() if n > 0)
    return plan, text


def in_stock_demand(data: RestockData, as_of, config: dict = RESTOCK_CONFIG) -> pd.DataFrame:
    """Per group: sales in the last `in_stock_window_days` divided by the days the group had at least one pair
    in stock, x 14. NaN when it had stock on fewer than `min_in_stock_days` days (too little to judge)."""
    as_of = pd.Timestamp(as_of).normalize()
    start = as_of - pd.Timedelta(days=config["in_stock_window_days"])
    end = as_of - pd.Timedelta(days=1)
    days_in_stock = (data.stock.loc[start:end] >= 1).sum()
    sold = data.sales.loc[start:end].sum()
    rate = (sold / days_in_stock.where(days_in_stock >= config["min_in_stock_days"])) * config["horizon_days"]
    return pd.DataFrame({"sold": sold, "days_in_stock": days_in_stock, "in_stock_rate_14d": rate})


def correct_censored(demand: pd.Series, stock: pd.Series, rates: pd.DataFrame, config: dict = RESTOCK_CONFIG) -> pd.DataFrame:
    """For groups with stock below `censor_stock_below`: demand = max(model demand, in-stock rate x 14).
    Returns the corrected demand and where it came from ("model" or "in-stock rate")."""
    rate = rates["in_stock_rate_14d"].reindex(demand.index)
    use_rate = (stock < config["censor_stock_below"]) & rate.notna() & (rate > demand)
    return pd.DataFrame({"demand": demand.where(~use_rate, rate),
                         "source": np.where(use_rate, "in-stock rate", "model")}, index=demand.index)


def build_restock_report(as_of_date, level: str = "group", model=None, data: RestockData | None = None,
                         config: dict | None = None) -> pd.DataFrame:
    """Restock report at the start of `as_of_date` (Malaysia time), using data before that day.

    Per group: current stock, predicted demand for the next 14 days, 80% upper demand, days of cover
    (stock / daily demand), risk (HIGH / MEDIUM / LOW / OVERSTOCK), suggested restock quantity
    (max(0, ceil(80% upper demand) - stock)) and the UK sizes to look for.
    Groups with fewer than 2 pairs get the censored-demand correction (see correct_censored); the
    "demand_source" column says whether the model or the in-stock rate set their demand.
    level="brand" or "category" adds the groups up (risk from the totals; restock qty = the sum of the groups' suggestions)."""
    config = {**RESTOCK_CONFIG, **(config or {})}
    as_of = pd.Timestamp(as_of_date).normalize()
    data = data or load_restock_data()
    model = model or joblib.load(MODEL_PATH)

    snap = build_snapshots(data, [as_of], with_target=False).set_index("group")
    pred = predict_demand(model, snap[FEATURES])
    corrected = correct_censored(pred["yhat"], snap["stock"], in_stock_demand(data, as_of, config), config)
    report = snap[["name", "brand", "category", "size_band", "stock"]].copy()
    report["model_demand_14d"] = pred["yhat"].round(2)
    report["predicted_demand_14d"] = corrected["demand"].round(2)
    report["demand_source"] = corrected["source"]
    report["upper_demand_14d"] = poisson_upper(corrected["demand"])
    report["sold_last_28d"] = snap["sold_28"]
    # Restock is decided per group: spare pairs of one model or size band can't cover another's demand.
    report["suggested_restock_qty"] = [max(0, math.ceil(u) - int(s)) for u, s in zip(report["upper_demand_14d"], report["stock"])]

    if level in ("brand", "category"):
        report = report.groupby(level).agg(stock=("stock", "sum"), predicted_demand_14d=("predicted_demand_14d", "sum"),
                                           groups_corrected=("demand_source", lambda s: int((s == "in-stock rate").sum())),
                                           sold_last_28d=("sold_last_28d", "sum"), groups=("name", "size"),
                                           suggested_restock_qty=("suggested_restock_qty", "sum"))
        report["upper_demand_14d"] = poisson_upper(report["predicted_demand_14d"])

    daily = report["predicted_demand_14d"] / config["horizon_days"]
    report["days_of_cover"] = np.where(daily > 0, report["stock"] / daily.where(daily > 0, 1), np.inf)
    report["days_of_cover"] = report["days_of_cover"].round(1)
    report["risk"] = [risk_level(s, c, config) for s, c in zip(report["stock"], report["days_of_cover"])]

    if level == "group":
        report["sizes_to_look_for"] = [size_plan(data, g, as_of, u, q, config)[1]
                                       for g, u, q in zip(report.index, report["upper_demand_14d"], report["suggested_restock_qty"])]

    report["risk"] = pd.Categorical(report["risk"], categories=RISK_ORDER, ordered=True)
    report.insert(0, "as_of", as_of.date())
    return report.sort_values(["risk", "suggested_restock_qty", "predicted_demand_14d"], ascending=[True, False, False])
