"""Training data for price recommendation.

Rows: pairs sold in orders with status paid / shipped / delivered / completed; target = order_items.price
(what the pair actually sold for). Markdown bias fix: only pairs sold within 45 days of listing are kept,
because the shop marks pairs down after 45 days, so later sale prices are not the price the pair was listed at.

Features describe the pair only; none is derived from its price.
"""
from __future__ import annotations

import pandas as pd
from sqlalchemy import bindparam, text
from sqlalchemy.engine import Engine

MY_OFFSET = pd.Timedelta(hours=8)
SOLD_STATUSES = ("paid", "shipped", "delivered", "completed")
MAX_DAYS_ON_SALE = 45
TIME_ORIGIN = pd.Timestamp("2024-11-01")

CATEGORICAL_FEATURES = ["brand", "name", "category", "condition", "sole_condition", "color", "material"]
NUMERIC_FEATURES = ["box_included", "size", "retail_price", "months_since_start"]
FEATURES = CATEGORICAL_FEATURES + NUMERIC_FEATURES

_SALES_SQL = text("""
    select oi.id as order_item_id, oi.price as sold_price, o.created_at as ordered_at,
           p.id as product_id, p.name, p.brand, c.name as category, p.`condition`, p.sole_condition, p.box_included,
           p.size, p.color, p.material, p.retail_price, p.created_at as listed_at
    from order_items oi
    join orders o on o.id = oi.order_id
    join products p on p.id = oi.product_id
    join categories c on c.id = p.category_id
    where o.status in :statuses
""").bindparams(bindparam("statuses", expanding=True))


def months_since_start(listed_at) -> pd.Series | float:
    """Months between 1 Nov 2024 and the listing date (captures demand for a model changing over time)."""
    return (pd.to_datetime(listed_at) - TIME_ORIGIN) / pd.Timedelta(days=30.4375)


def _engine(engine):
    if engine is not None:
        return engine
    from db import get_engine

    return get_engine()


def load_sales(engine: Engine | None = None) -> pd.DataFrame:
    """Every sold pair (all ages), with days_to_sell and the features. Times in Malaysia time."""
    sales = pd.read_sql(_SALES_SQL, _engine(engine), params={"statuses": list(SOLD_STATUSES)})
    for column in ["sold_price", "retail_price", "size"]:
        sales[column] = pd.to_numeric(sales[column])
    sales["box_included"] = sales["box_included"].astype(int)
    sales["listed_at"] = pd.to_datetime(sales["listed_at"]) + MY_OFFSET
    sales["ordered_at"] = pd.to_datetime(sales["ordered_at"]) + MY_OFFSET
    sales["days_to_sell"] = (sales["ordered_at"] - sales["listed_at"]).dt.total_seconds() / 86400
    sales["months_since_start"] = months_since_start(sales["listed_at"])
    return sales.sort_values("ordered_at").reset_index(drop=True)


def before_markdown(sales: pd.DataFrame, max_days: int = MAX_DAYS_ON_SALE) -> pd.DataFrame:
    """Keep pairs sold within `max_days` of listing (before the first markdown)."""
    return sales[sales["days_to_sell"] <= max_days].reset_index(drop=True)


def retail_reference(engine: Engine | None = None) -> dict:
    """Median retail price per model and per brand (all products), for when a new pair has no retail price."""
    products = pd.read_sql(text("select name, brand, retail_price from products where retail_price is not null"), _engine(engine))
    products["retail_price"] = pd.to_numeric(products["retail_price"])
    return {"name": products.groupby("name")["retail_price"].median().to_dict(),
            "brand": products.groupby("brand")["retail_price"].median().to_dict(),
            "all": float(products["retail_price"].median())}
