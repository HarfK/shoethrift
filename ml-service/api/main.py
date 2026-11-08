"""ShoeThrift ML API (FastAPI), called by the Laravel admin.

    run_api.bat   ->   uvicorn api.main:app --host 127.0.0.1 --port 8001   (from ml-service/)

Every request needs the header X-API-Key = ML_API_KEY from ml-service/.env (the Laravel .env holds the same value).

    GET  /health                       models (name, trained_until, key metric), cache ages, retrain status
    GET  /forecast/revenue?days=7      daily revenue forecast from tomorrow (1-30 days) + last 30 days of actuals
    GET  /restock/report?as_of=&risk=  restock report per group (+ brand roll-up), optional risk filter
    POST /price/recommend              suggested price for one pair (see PriceRequest)
    POST /admin/retrain                retrain all models in the background (retrain.py), then reload and clear the cache

Dates are Malaysia dates (UTC+8). The forecast and restock report are cached in memory for 1 hour.
Errors are JSON: {"error": ..., "details": [...]}: 401 bad/missing key, 422 bad input, 503 model missing or database down.
"""
from __future__ import annotations

import json
import math
import os
import re
import secrets
import sys
import threading
import time
from contextlib import asynccontextmanager
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Literal

import joblib
import pandas as pd
from dotenv import load_dotenv
from fastapi import BackgroundTasks, Depends, FastAPI, Header, Query, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field, field_validator
from sqlalchemy.exc import DBAPIError

ML_DIR = Path(__file__).resolve().parent.parent
if str(ML_DIR) not in sys.path:
    sys.path.insert(0, str(ML_DIR))
load_dotenv(ML_DIR / ".env")

from forecasting.adjust import MAJOR_EVENTS, event_labels  # noqa: E402
from forecasting.data import load_daily_revenue  # noqa: E402
from pricing.recommend import recommend_price  # noqa: E402
from restock.data import load_restock_data  # noqa: E402
from restock.report import RISK_ORDER, build_restock_report  # noqa: E402

MODEL_DIR = ML_DIR / "models"
MODEL_FILES = {"revenue": "revenue_model", "restock": "restock_model", "price": "price_model"}
CACHE_SECONDS = 3600
MY_OFFSET = timedelta(hours=8)
SHOP_CONFIG = ML_DIR.parent / "config" / "shop.php"
# Display names. 11.11 / 12.12 are national online shopping days (the shop itself runs no sale on them).
EVENT_NAMES = {"11.11": "11.11 shopping day", "12.12": "12.12 shopping day", "9.9": "9.9 shopping day", "10.10": "10.10 shopping day",
               "black_friday": "Black Friday", "pre_raya": "Pre-Raya", "raya_closure": "Hari Raya", "chinese_new_year": "Chinese New Year",
               "payday": "Payday"}


class ApiError(Exception):
    def __init__(self, status: int, error: str, details: list[str] | None = None):
        self.status, self.error, self.details = status, error, details or []


# --- state -----------------------------------------------------------------------------------------

class State:
    def __init__(self):
        self.models, self.meta, self.missing = {}, {}, {}
        self.cache: dict[tuple, tuple[float, object]] = {}
        self.valid: dict | None = None
        self.retrain = {"status": "idle", "started_at": None, "finished_at": None, "summary": None, "error": None}
        self.lock = threading.Lock()

    def load_models(self):
        self.models, self.meta, self.missing = {}, {}, {}
        for key, stem in MODEL_FILES.items():
            path, meta_path = MODEL_DIR / f"{stem}.joblib", MODEL_DIR / f"{stem}.json"
            if not path.exists():
                self.missing[key] = f"{path.name} not found"
                continue
            try:
                self.models[key] = joblib.load(path)
                self.meta[key] = json.loads(meta_path.read_text(encoding="utf-8")) if meta_path.exists() else {}
            except Exception as error:  # a corrupt file must not stop the other endpoints
                self.missing[key] = f"{path.name} could not be loaded: {error}"

    def model(self, key: str):
        if key not in self.models:
            raise ApiError(503, f"The {key} model is not available.", [self.missing.get(key, "not loaded")])
        return self.models[key]

    def cached(self, key: tuple, build):
        hit = self.cache.get(key)
        if hit and time.time() - hit[0] < CACHE_SECONDS:
            return hit[1]
        value = build()
        self.cache[key] = (time.time(), value)
        return value


state = State()


@asynccontextmanager
async def lifespan(app: FastAPI):
    state.load_models()
    yield


def require_api_key(x_api_key: str | None = Header(default=None)):
    expected = os.getenv("ML_API_KEY")
    if not expected:
        raise ApiError(500, "ML_API_KEY is not configured on the ML service.")
    if not x_api_key or not secrets.compare_digest(x_api_key, expected):
        raise ApiError(401, "Missing or invalid API key.", ["Send the X-API-Key header."])


app = FastAPI(title="ShoeThrift ML API", version="1.0", lifespan=lifespan, dependencies=[Depends(require_api_key)])


# --- errors ----------------------------------------------------------------------------------------

@app.exception_handler(ApiError)
async def api_error(request: Request, exc: ApiError):
    return JSONResponse(status_code=exc.status, content={"error": exc.error, "details": exc.details})


@app.exception_handler(RequestValidationError)
async def validation_error(request: Request, exc: RequestValidationError):
    details = []
    for err in exc.errors():
        field = ".".join(str(p) for p in err["loc"] if p not in ("body", "query"))
        details.append(f"{field}: {err['msg'].removeprefix('Value error, ')}" if field else err["msg"])
    return JSONResponse(status_code=422, content={"error": "Invalid input.", "details": details})


@app.exception_handler(DBAPIError)
async def database_error(request: Request, exc: DBAPIError):
    return JSONResponse(status_code=503, content={"error": "The database is not reachable.", "details": ["Check that MySQL is running."]})


# --- helpers ---------------------------------------------------------------------------------------

def today_my() -> date:
    return (datetime.now(timezone.utc) + MY_OFFSET).date()


def clean(value):
    """JSON-safe numbers: NaN/inf -> None, numpy -> Python."""
    if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
        return None
    if hasattr(value, "item"):
        return clean(value.item())
    return value


def records(frame: pd.DataFrame) -> list[dict]:
    return [{k: clean(v) for k, v in row.items()} for row in frame.to_dict(orient="records")]


def shop_materials() -> list[str]:
    """The material list from config/shop.php ('materials' => [...])."""
    text = SHOP_CONFIG.read_text(encoding="utf-8")
    block = re.search(r"'materials'\s*=>\s*\[(.*?)\]", text, re.S)
    return re.findall(r"'([^']+)'", block.group(1)) if block else []


def valid_values() -> dict:
    """Brands and categories from the database, materials from config/shop.php (loaded once)."""
    if state.valid is None:
        from sqlalchemy import text

        from db import get_engine

        with get_engine().connect() as conn:
            brands = [r[0] for r in conn.execute(text("select distinct brand from products order by brand"))]
            categories = [r[0] for r in conn.execute(text("select name from categories order by name"))]
        state.valid = {"brands": brands, "categories": categories, "materials": shop_materials()}
    return state.valid


# --- endpoints -------------------------------------------------------------------------------------

@app.get("/health")
def health():
    models = {}
    for key, stem in MODEL_FILES.items():
        meta = state.meta.get(key, {})
        models[key] = {"available": key in state.models, "file": f"{stem}.joblib", "error": state.missing.get(key),
                       "name": meta.get("model_name"), "trained_until": meta.get("trained_until"), "key_metric": _key_metric(key, meta)}
    now = time.time()
    cache = [{"key": "/".join(str(k) for k in key), "age_seconds": round(now - ts), "expires_in_seconds": max(0, round(CACHE_SECONDS - (now - ts)))}
             for key, (ts, _) in state.cache.items()]
    return {"status": "ok" if not state.missing else "degraded", "today_malaysia": str(today_my()), "models": models,
            "cache": cache, "retrain": state.retrain}


def _key_metric(key: str, meta: dict) -> dict | None:
    try:
        if key == "revenue":
            m = meta["evaluation"]["A"]["metrics"]
            return {"name": "MAE per day, test window A (RM)", "value": m["MAE (RM)"], "weekly_total_error_pct": m["weekly total error (%)"]}
        if key == "restock":
            m = meta["evaluation"]["metrics"]
            return {"name": "sell-out F1 (13-week backtest)", "value": m["sell-out F1"], "mae_units": m["MAE (units)"]}
        if key == "price":
            m = meta["evaluation"]["time_test"]["metrics"]
            return {"name": "MAE on later sales (RM)", "value": m["MAE (RM)"], "within_15pct": m["within 15% (%)"]}
    except (KeyError, TypeError):
        return None


@app.get("/forecast/revenue")
def forecast_revenue(days: int = Query(7, ge=1, le=30, description="Days to forecast from tomorrow (1-30)")):
    model = state.model("revenue")
    today = today_my()

    def build():
        history = load_daily_revenue(until=today)
        dates = pd.date_range(pd.Timestamp(today) + pd.Timedelta(days=1), periods=30, freq="D")
        forecast = model.predict(dates)
        labels = event_labels(dates)
        applied = getattr(model, "applied_multipliers_", {}) or {}

        def event(key):
            # event: display name; event_key: forecasting.adjust label; major: one of the major campaign events
            key = key if isinstance(key, str) else None
            return {"event": EVENT_NAMES.get(key) if key else None, "event_key": key, "major": key in MAJOR_EVENTS}

        rows = [{"date": str(d.date()), "weekday": d.day_name(), "forecast": round(float(r.yhat), 2), "lower": round(float(r.lower), 2),
                 "upper": round(float(r.upper), 2), **event(labels[d]),
                 "uplift": round(applied[labels[d]], 2) if labels[d] in applied else None}
                for d, r in zip(dates, forecast.itertuples())]
        last30 = history[history.index > pd.Timestamp(today) - pd.Timedelta(days=30)]
        past_labels = event_labels(last30.index)
        actual = [{"date": str(d.date()), "weekday": d.day_name(), "revenue": round(float(v), 2), "partial_day": d.date() == today,
                   **event(past_labels[d])} for d, v in last30.items()]
        return {"rows": rows, "actual": actual}

    built = state.cached(("forecast", str(today)), build)
    rows = built["rows"][:days]
    week = built["rows"][:7]
    week_total = sum(r["forecast"] for r in week)
    low, high = model.weekly_range(week_total) if hasattr(model, "weekly_range") else (None, None)
    return {
        "today": str(today), "model": state.meta["revenue"].get("model_name"), "model_trained_until": state.meta["revenue"].get("trained_until"),
        "days": rows,
        "total_forecast": round(sum(r["forecast"] for r in rows), 2),
        "next_7_days": {"from": week[0]["date"], "to": week[-1]["date"], "total": round(week_total, 2),
                        "lower": round(low, 2) if low is not None else None, "upper": round(high, 2) if high is not None else None},
        "last_30_days_actual": built["actual"],
        "note": "Revenue = paid orders, Malaysia time, net of refunds. Today's actual is a partial day.",
    }


@app.get("/restock/report")
def restock_report(as_of: date | None = Query(None, description="Report date (YYYY-MM-DD), default today in Malaysia"),
                   risk: str | None = Query(None, description="Comma-separated filter, e.g. HIGH,MEDIUM")):
    model = state.model("restock")
    as_of = as_of or today_my()
    wanted = None
    if risk:
        wanted = [r.strip().upper() for r in risk.split(",") if r.strip()]
        bad = [r for r in wanted if r not in RISK_ORDER]
        if bad:
            raise ApiError(422, "Invalid input.", [f"risk: unknown value(s) {', '.join(bad)}; use {', '.join(RISK_ORDER)}"])

    def build():
        data = load_restock_data()
        groups = build_restock_report(as_of, model=model, data=data).rename_axis("group").reset_index()
        brands = build_restock_report(as_of, level="brand", model=model, data=data).reset_index()
        groups["risk"], brands["risk"] = groups["risk"].astype(str), brands["risk"].astype(str)
        groups["as_of"], brands["as_of"] = groups["as_of"].astype(str), brands["as_of"].astype(str)
        return {"groups": groups, "brands": brands, "data_until": str(data.last_day.date())}

    built = state.cached(("restock", str(as_of)), build)
    groups = built["groups"]
    shown = groups[groups["risk"].isin(wanted)] if wanted else groups
    return {
        "as_of": str(as_of), "data_until": built["data_until"], "model": state.meta["restock"].get("model_name"),
        "summary": {"groups": int(len(groups)), "by_risk": {r: int((groups["risk"] == r).sum()) for r in RISK_ORDER},
                    "total_pairs_suggested": int(groups["suggested_restock_qty"].sum()),
                    "groups_to_restock": int((groups["suggested_restock_qty"] > 0).sum()),
                    "groups_using_in_stock_rate": int((groups["demand_source"] == "in-stock rate").sum())},
        "filter": wanted,
        "shown": {"groups": int(len(shown)), "pairs_suggested": int(shown["suggested_restock_qty"].sum())},
        "groups": records(shown),
        "brands": records(built["brands"]),
        "note": "days_of_cover = null means no predicted demand (cover is unlimited).",
    }


class PriceRequest(BaseModel):
    brand: str = Field(min_length=1, max_length=100)
    name: str = Field(min_length=1, max_length=150, description="Shoe model, e.g. 'Nike Dunk Low' (new models allowed)")
    category: str = Field(min_length=1, max_length=100)
    condition: Literal["like_new", "good", "fair"]
    sole_condition: Literal["excellent", "good", "fair", "worn"] | None = None
    box_included: bool | None = Field(default=None, description="Original box; leave out if unknown (then a hint is returned)")
    size: float = Field(description="UK size, 3.0-12.0 in steps of 0.5")
    color: str | None = Field(default=None, max_length=50)
    material: str | None = None
    retail_price: float | None = Field(default=None, gt=0, le=20000)

    @field_validator("size")
    @classmethod
    def check_size(cls, v: float) -> float:
        if not 3.0 <= v <= 12.0 or not float(v * 2).is_integer():
            raise ValueError("must be between 3.0 and 12.0 in steps of 0.5 (UK size)")
        return v

    @field_validator("brand", "name", "category", "color", "material")
    @classmethod
    def strip(cls, v):
        return v.strip() if isinstance(v, str) else v


@app.post("/price/recommend")
def price_recommend(body: PriceRequest):
    model = state.model("price")
    valid = valid_values()
    errors, product = [], body.model_dump()
    for field, options in (("brand", valid["brands"]), ("category", valid["categories"]), ("material", valid["materials"])):
        value = product.get(field)
        if value is None:
            continue
        match = next((o for o in options if o.lower() == value.lower()), None)
        if match is None:
            errors.append(f"{field}: '{value}' is not a known {field}; use one of: {', '.join(options)}")
        else:
            product[field] = match
    if errors:
        raise ApiError(422, "Invalid input.", errors)

    result = recommend_price(product, model=model, listed_at=pd.Timestamp(today_my()))
    return {
        "suggested_price": result["suggested_price"],
        "range_80": {"lower": result["range_80"][0], "upper": result["range_80"][1]},
        "model_estimate": result["model_estimate"],
        "reasons": result["reasons"],
        "breakdown": [{"label": b["label"], "rm": round(b["rm"], 2)} for b in result["breakdown"]],
        "above_retail": result["above_retail"],
        "unseen_model": result["unseen_model"],
        "warnings": result["warnings"],
        "notes": result["notes"],
        "retail_price_used": result["retail_price_used"],
        "retail_price_estimated": result["retail_price_estimated"],
        "missing_details": result["missing_details"],
        "hint": result["hint"],
        "model": state.meta["price"].get("model_name"),
    }


def _run_retrain():
    from retrain import retrain_all

    with state.lock:
        state.retrain.update(status="running", started_at=datetime.now(timezone.utc).isoformat(timespec="seconds"), error=None)
        try:
            state.retrain["summary"] = retrain_all(log=lambda *_: None)
            state.load_models()
            state.cache.clear()
            state.valid = None
            state.retrain["status"] = "finished"
        except Exception as error:
            state.retrain.update(status="failed", error=str(error))
        finally:
            state.retrain["finished_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")


@app.post("/admin/retrain", status_code=202)
def admin_retrain(background: BackgroundTasks):
    if state.retrain["status"] == "running":
        return JSONResponse(status_code=409, content={"error": "A retrain is already running.", "details": [f"started {state.retrain['started_at']}"]})
    background.add_task(_run_retrain)
    return {"status": "started", "message": "Retraining all models in the background (about 1 minute). Check GET /health for the result."}
