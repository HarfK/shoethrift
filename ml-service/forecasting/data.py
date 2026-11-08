"""Daily revenue, defined exactly as the admin dashboard and notebook 01:

- orders with paid_at set and status paid / shipped / completed / refunded
  (online orders count once paid, Cash on Delivery once the cash is collected);
- dated by paid_at converted from UTC to Malaysia time (UTC+8);
- net = total - refunded_amount.
"""
import pandas as pd
from sqlalchemy import bindparam, text
from sqlalchemy.engine import Engine

MY_OFFSET = pd.Timedelta(hours=8)
REVENUE_STATUSES = ("paid", "shipped", "completed", "refunded")

_SQL = text("""
    select paid_at, total, refunded_amount
    from orders
    where paid_at is not null and status in :statuses
""").bindparams(bindparam("statuses", expanding=True))


def load_daily_revenue(engine: Engine | None = None, until=None) -> pd.Series:
    """Net revenue (RM) per Malaysian day, continuous (days without sales = 0).

    `until` (optional, a date) cuts the series to end on that day.
    """
    if engine is None:
        from db import get_engine

        engine = get_engine()

    orders = pd.read_sql(_SQL, engine, params={"statuses": list(REVENUE_STATUSES)})
    orders["net"] = pd.to_numeric(orders["total"]) - pd.to_numeric(orders["refunded_amount"])
    orders["day"] = (pd.to_datetime(orders["paid_at"]) + MY_OFFSET).dt.normalize()

    daily = orders.groupby("day")["net"].sum()
    end = pd.Timestamp(until).normalize() if until is not None else daily.index.max()
    days = pd.date_range(daily.index.min(), end, freq="D", name="date")
    return daily.reindex(days, fill_value=0.0).astype(float).rename("revenue")
