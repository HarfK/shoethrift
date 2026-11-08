"""Stock history and weekly snapshots for restock prediction.

Group = shoe model (products.name) x size band: S = UK 3.0-5.5, M = UK 6.0-8.5, L = UK 9.0-12.0.
Every product is one pair (stock 1). All days are Malaysia days (UTC+8).

A pair is in stock from products.created_at until it is sold (created_at of the first order containing it
that is not cancelled or failed) or hidden (products.updated_at when status = hidden). Pairs in cancelled or
failed orders stay in stock. "Stock at day d" means pairs in stock at the start of day d.

Snapshots (every Monday) hold what was known before that day plus the target: units sold in the next 14 days.
Sales are limited by stock (an empty group sells 0 whatever the demand), so models are trained on snapshots
with stock >= MIN_TRAIN_STOCK; see notebook 04 ("censored demand").
"""
from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import pandas as pd
from sqlalchemy import text
from sqlalchemy.engine import Engine

from forecasting.adjust import MAJOR_EVENTS, event_labels
from forecasting.calendar import calendar_features

MY_OFFSET = pd.Timedelta(hours=8)
HORIZON = 14
MIN_TRAIN_STOCK = 2
NOT_SOLD_STATUSES = ("cancelled", "failed")
NO_SALE_DAYS_CAP = 365  # "days since last sale" when there was none
BANDS = {"S": (3.0, 5.5), "M": (6.0, 8.5), "L": (9.0, 12.0)}

NUMERIC_FEATURES = [
    "stock", "sold_7", "sold_14", "sold_28", "sold_56", "days_since_last_sale", "trend_28",
    "avg_price", "avg_price_ratio", "views_14", "next14_major_event_days", "next14_payday_days",
]
CATEGORICAL_FEATURES = ["brand", "category", "size_band"]
FEATURES = NUMERIC_FEATURES + CATEGORICAL_FEATURES


def size_band(size: float) -> str:
    for band, (low, high) in BANDS.items():
        if low <= size <= high:
            return band
    raise ValueError(f"UK {size} is outside the size bands")


def format_size(size: float) -> str:
    return f"UK {size:g}"


@dataclass
class RestockData:
    products: pd.DataFrame  # one row per pair: group, size, price, listed_at, out_at, sold_at
    groups: pd.DataFrame  # one row per group: name, brand, category, size_band
    days: pd.DatetimeIndex  # every Malaysian day covered
    stock: pd.DataFrame  # days x groups: pairs in stock at the start of the day
    sales: pd.DataFrame  # days x groups: pairs sold that day
    views: pd.DataFrame  # days x groups: product page views that day
    last_day: pd.Timestamp  # last day with data


def load_restock_data(engine: Engine | None = None) -> RestockData:
    if engine is None:
        from db import get_engine

        engine = get_engine()

    q = lambda sql: pd.read_sql(text(sql), engine)  # noqa: E731
    products = q("""select p.id, p.name, p.brand, c.name as category, p.size, p.price, p.retail_price, p.status,
                           p.created_at, p.updated_at
                    from products p join categories c on c.id = p.category_id""")
    orders = q("""select oi.product_id, o.status, o.created_at
                  from order_items oi join orders o on o.id = oi.order_id""")
    views = q("select product_id, created_at from user_interactions where type = 'view'")

    for column in ["size", "price", "retail_price"]:
        products[column] = pd.to_numeric(products[column])
    products["size_band"] = products["size"].map(size_band)
    products["group"] = products["name"] + " · " + products["size_band"]
    products["listed_at"] = pd.to_datetime(products["created_at"]) + MY_OFFSET

    orders["ordered_at"] = pd.to_datetime(orders["created_at"]) + MY_OFFSET
    sold = orders[~orders["status"].isin(NOT_SOLD_STATUSES)].groupby("product_id")["ordered_at"].min()
    products["sold_at"] = products["id"].map(sold)
    hidden_at = (pd.to_datetime(products["updated_at"]) + MY_OFFSET).where(products["status"] == "hidden")
    products["out_at"] = products[["sold_at"]].assign(h=hidden_at).min(axis=1)

    last_day = max(products["listed_at"].max(), orders["ordered_at"].max()).normalize()
    days = pd.date_range(products["listed_at"].min().normalize(), last_day + pd.Timedelta(days=HORIZON + 1), freq="D")
    groups = (products.groupby("group").agg(name=("name", "first"), brand=("brand", "first"),
                                            category=("category", "first"), size_band=("size_band", "first"))
              .sort_index())

    def daily(day_series: pd.Series, group_series: pd.Series) -> pd.DataFrame:
        frame = pd.DataFrame({"day": day_series.dt.normalize(), "group": group_series.values}).dropna()
        return frame.groupby(["day", "group"]).size().unstack(fill_value=0).reindex(index=days, columns=groups.index, fill_value=0)

    # Stock at the start of day d: listed before d, and not out before d.
    # +1 the day after listing, -1 the day after it went (sold or hidden that day still counts that morning).
    plus = daily(products["listed_at"].dt.normalize() + pd.Timedelta(days=1), products["group"])
    minus = daily(products["out_at"].dt.normalize() + pd.Timedelta(days=1), products["group"])
    stock = (plus - minus).cumsum()

    sold_products = products.dropna(subset=["sold_at"])
    sales = daily(sold_products["sold_at"], sold_products["group"])
    views = views.merge(products[["id", "group"]], left_on="product_id", right_on="id")
    view_counts = daily(pd.to_datetime(views["created_at"]) + MY_OFFSET, views["group"])

    return RestockData(products=products, groups=groups, days=days, stock=stock, sales=sales, views=view_counts, last_day=last_day)


def snapshot_dates(data: RestockData, with_target: bool = True, history_days: int = 56) -> pd.DatetimeIndex:
    """Mondays with `history_days` of sales history before them (and a complete 14-day target if asked)."""
    first = data.sales.index[data.sales.sum(axis=1).to_numpy().nonzero()[0][0]] + pd.Timedelta(days=history_days)
    last = data.last_day - pd.Timedelta(days=HORIZON - 1) if with_target else data.last_day + pd.Timedelta(days=1)
    return pd.date_range(first, last, freq="W-MON")


def _window(frame: pd.DataFrame, start, end) -> pd.Series:
    """Sum per group over days [start, end) (end exclusive)."""
    return frame.loc[start:end - pd.Timedelta(days=1)].sum()


def build_snapshots(data: RestockData, dates, with_target: bool = True) -> pd.DataFrame:
    """One row per (date, group) with the features known at the start of `date` and the 14-day target."""
    rows = []
    products = data.products
    for t in pd.DatetimeIndex(dates):
        day = pd.Timedelta(days=1)
        frame = data.groups.copy()
        frame["date"] = t
        frame["stock"] = data.stock.loc[t]
        for k in (7, 14, 28, 56):
            frame[f"sold_{k}"] = _window(data.sales, t - k * day, t)
        prev_28 = _window(data.sales, t - 56 * day, t - 28 * day)
        frame["trend_28"] = frame["sold_28"] - prev_28

        before = data.sales.loc[:t - day]
        last_sale = before.apply(lambda col: col[col > 0].index.max())
        frame["days_since_last_sale"] = ((t - last_sale).dt.days).fillna(NO_SALE_DAYS_CAP).clip(upper=NO_SALE_DAYS_CAP)

        in_stock = products[(products["listed_at"] < t) & (products["out_at"].isna() | (products["out_at"] >= t))]
        listed_before = products[products["listed_at"] < t]
        price = in_stock.groupby("group")["price"].mean()
        ratio = (in_stock["price"] / in_stock["retail_price"]).groupby(in_stock["group"]).mean()
        # Groups with no stock: use the average of all pairs of the group listed so far.
        fallback_price = listed_before.groupby("group")["price"].mean()
        fallback_ratio = (listed_before["price"] / listed_before["retail_price"]).groupby(listed_before["group"]).mean()
        frame["avg_price"] = price.reindex(frame.index).fillna(fallback_price.reindex(frame.index))
        frame["avg_price_ratio"] = ratio.reindex(frame.index).fillna(fallback_ratio.reindex(frame.index))
        frame["views_14"] = _window(data.views, t - 14 * day, t)

        next_days = pd.date_range(t, periods=HORIZON, freq="D")
        frame["next14_major_event_days"] = int(event_labels(next_days).isin(MAJOR_EVENTS).sum())
        frame["next14_payday_days"] = int(calendar_features(next_days)["payday"].sum())

        if with_target:
            frame["target"] = _window(data.sales, t, t + HORIZON * day)
        rows.append(frame.reset_index())

    snapshots = pd.concat(rows, ignore_index=True)
    snapshots[["avg_price", "avg_price_ratio"]] = snapshots[["avg_price", "avg_price_ratio"]].fillna(
        snapshots[["avg_price", "avg_price_ratio"]].median())
    return snapshots
