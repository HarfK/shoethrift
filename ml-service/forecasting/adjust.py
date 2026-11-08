"""Event uplift and calibrated intervals, on top of any forecasting model.

Both are learned from *out-of-sample* forecasts inside the training history ("inner folds"):
every 7 days, the base model is trained on the data before that day and forecasts the next 7 days,
the same way it is used for real. Then:

- Event uplift: per event type, ratio = actual revenue / model forecast on those days, divided by
  the same ratio on normal days (so a model that is a bit low *every* day isn't "corrected" only on
  event days). It is shrunk toward 1 when there are few occurrences (double days first toward their
  shared multiplier). Forecasts on event days are multiplied by it.
- Calibrated interval: the 10th and 90th percentile of the inner-fold errors (actual - forecast)
  over the most recent ~6 months are added to the forecast, so the 80% range is as wide as the
  model's real errors (split-conformal style).

`EventAdjusted(base)` wraps a model so the saved object does all of this when it predicts.
"""
from __future__ import annotations

import copy

import numpy as np
import pandas as pd

from .calendar import calendar_features
from .models import ForecastModel, Q_HIGH, Q_LOW

DOUBLE_DAYS = ["9.9", "10.10", "11.11", "12.12"]
EVENT_TYPES = [*DOUBLE_DAYS, "black_friday", "raya_closure", "pre_raya", "chinese_new_year", "payday"]
# Major campaign events: the ones the admin must see as big days (a business choice, see notebook 03).
# Smaller events (payday, 9.9, 10.10, CNY) are left to the model's own calendar features.
MAJOR_EVENTS = ["11.11", "12.12", "black_friday", "pre_raya", "raya_closure"]

SHRINK_K = 2.0  # occurrences: 1 occurrence -> 1/3 of the way from the prior, 2 -> 1/2, 10 -> 5/6
RATIO_LIMITS = (0.25, 4.0)
CALIBRATION_DAYS = 182


def event_labels(dates) -> pd.Series:
    """The event type of each date (None = normal day). When events overlap, the more specific
    one wins: a double day beats Black Friday, which beats Raya, pre-Raya, CNY and payday."""
    index = pd.DatetimeIndex(pd.to_datetime(dates)).normalize()
    cal = calendar_features(index)
    label = pd.Series(None, index=index, dtype=object)
    for column in ["payday", "chinese_new_year", "pre_raya", "raya_closure", "black_friday"]:  # low -> high priority
        label[cal[column].values == 1] = column
    double = cal["double_day"].values == 1
    label[double] = [f"{d.month}.{d.day}" for d in index[double]]
    return label


def _occurrences(days: pd.DatetimeIndex) -> int:
    """Number of separate events (runs of consecutive days)."""
    if len(days) == 0:
        return 0
    days = days.sort_values()
    return int(1 + (np.diff(days.values).astype("timedelta64[D]").astype(int) > 1).sum())


def _fold(model, history, origin, end):
    dates = pd.date_range(origin, end, freq="D")
    out = copy.deepcopy(model).fit(history[history.index < origin]).predict(dates)
    out["actual"] = history[dates].values
    out["origin"] = origin
    return out


def inner_forecasts(model, history: pd.Series, min_days: int = 84, step: int = 7, n_jobs: int = -1) -> pd.DataFrame:
    """Out-of-sample 7-day forecasts across the training history (each fold sees only earlier data)."""
    from joblib import Parallel, delayed

    origins = pd.date_range(history.index[0] + pd.Timedelta(days=min_days), history.index[-1], freq=f"{step}D")
    folds = [(o, min(o + pd.Timedelta(days=step - 1), history.index[-1])) for o in origins]
    parts = Parallel(n_jobs=n_jobs)(delayed(_fold)(model, history, o, e) for o, e in folds)
    return pd.concat(parts).rename_axis("date").reset_index()


def _shrink(raw: float, n: int, prior: float, k: float = SHRINK_K) -> float:
    return prior + n / (n + k) * (raw - prior)


def estimate_multipliers(inner: pd.DataFrame, k: float = SHRINK_K) -> pd.DataFrame:
    """One row per event type: occurrences, days, raw ratio and the shrunk multiplier.
    raw ratio = (sum actual / sum forecast on the event's days) / (the same on normal days).
    Double days are first pooled (shared multiplier, shrunk toward 1), then each one is shrunk
    toward that shared value."""
    labels = event_labels(inner["date"]).values

    def plain_ratio(mask):
        forecast, actual = inner.loc[mask, "yhat"].sum(), inner.loc[mask, "actual"].sum()
        return actual / forecast if forecast > 0 else 1.0

    normal = plain_ratio(pd.isna(labels))

    def ratio(mask):
        return float(np.clip(plain_ratio(mask) / normal, *RATIO_LIMITS))

    pooled_mask = np.isin(labels, DOUBLE_DAYS)
    pooled_n = _occurrences(pd.DatetimeIndex(inner.loc[pooled_mask, "date"]))
    double_prior = _shrink(ratio(pooled_mask), pooled_n, 1.0, k) if pooled_n else 1.0

    rows = []
    for event in EVENT_TYPES:
        mask = labels == event
        days = pd.DatetimeIndex(inner.loc[mask, "date"])
        n = _occurrences(days)
        prior = double_prior if event in DOUBLE_DAYS else 1.0
        raw = ratio(mask) if n else np.nan
        rows.append({"event": event, "occurrences": n, "days": int(mask.sum()), "raw ratio": raw,
                     "prior": prior, "multiplier": _shrink(raw, n, prior, k) if n else prior})
    table = pd.DataFrame(rows).set_index("event")
    table.attrs["normal_day_ratio"] = normal
    return table


def apply_uplift(forecast: pd.DataFrame, multipliers: dict) -> pd.DataFrame:
    """Multiply forecast, lower and upper on event days by that event's multiplier."""
    m = event_labels(forecast.index).map(lambda e: multipliers.get(e, 1.0) if e else 1.0).astype(float).values
    out = forecast.copy()
    for column in ["yhat", "lower", "upper"]:
        out[column] = out[column] * m
    return out


def interval_offsets(inner: pd.DataFrame, recent_days: int = CALIBRATION_DAYS) -> dict:
    """Error quantiles from the most recent inner folds: daily (actual - forecast) at 10% / 90%,
    and weekly (actual / forecast of complete 7-day folds) at 10% / 90%."""
    recent = inner[inner["date"] > inner["date"].max() - pd.Timedelta(days=recent_days)]
    errors = recent["actual"] - recent["yhat"]
    folds = recent.groupby("origin")
    weeks = folds[["actual", "yhat"]].sum()[folds.size() == 7]
    weekly_ratio = weeks["actual"] / weeks["yhat"]
    return {"daily_low": float(errors.quantile(Q_LOW)), "daily_high": float(errors.quantile(Q_HIGH)),
            "weekly_low": float(weekly_ratio.quantile(Q_LOW)), "weekly_high": float(weekly_ratio.quantile(Q_HIGH))}


def apply_interval(forecast: pd.DataFrame, offsets: dict) -> pd.DataFrame:
    out = forecast.copy()
    out["lower"] = (out["yhat"] + offsets["daily_low"]).clip(lower=0)
    out["upper"] = (out["yhat"] + offsets["daily_high"]).clip(lower=0)
    return out


def select_events(multipliers: dict, events) -> dict:
    """Keep only the multipliers of `events` (all other days get no uplift)."""
    return {event: m for event, m in multipliers.items() if event in set(events)}


class EventAdjusted(ForecastModel):
    """Wraps a base model: event uplift (optional) + calibrated 80% interval.
    fit(history) learns both from inner folds of `history`, then fits the base model on all of it.
    `events` limits which event types get their multiplier applied (default: all EVENT_TYPES;
    MAJOR_EVENTS for the major-campaign-only version). Multipliers are estimated the same way either way."""

    def __init__(self, base: ForecastModel, uplift: bool = True, calibrate: bool = True,
                 min_days: int = 84, n_jobs: int = -1, events=None):
        self.base = base
        self.uplift = uplift
        self.calibrate = calibrate
        self.min_days = min_days
        self.n_jobs = n_jobs
        self.events = list(events) if events is not None else list(EVENT_TYPES)

    @property
    def name(self):
        if not self.uplift:
            return self.base.name
        events = getattr(self, "events", EVENT_TYPES)
        return self.base.name + (" + uplift" if set(events) == set(EVENT_TYPES) else " + major-event uplift" if set(events) == set(MAJOR_EVENTS) else " + uplift (" + ", ".join(events) + ")")

    @property
    def features(self):
        extra = ["event uplift multipliers: " + ", ".join(getattr(self, "events", EVENT_TYPES))] if self.uplift else []
        return [*self.base.features, *extra, "calibrated 80% interval (inner-fold error quantiles)"]

    def _fit(self, history):
        inner = inner_forecasts(self.base, history, self.min_days, n_jobs=self.n_jobs)
        self.multipliers_ = estimate_multipliers(inner) if self.uplift else None
        self.applied_multipliers_ = select_events(self.multipliers_["multiplier"].to_dict(), self.events) if self.uplift else {}
        adjusted = apply_uplift(inner.set_index("date"), self.applied_multipliers_)
        self.offsets_ = interval_offsets(adjusted.reset_index())
        self.base_ = copy.deepcopy(self.base).fit(history)

    def _predict(self, dates):
        out = self.base_.predict(dates)
        applied = getattr(self, "applied_multipliers_", None)
        if applied is None and self.uplift:  # objects saved before `events` existed
            applied = self.multipliers_["multiplier"].to_dict()
        if applied:
            out = apply_uplift(out, applied)
        return apply_interval(out, self.offsets_) if self.calibrate else out

    def weekly_range(self, week_total: float) -> tuple[float, float]:
        """80% range for a 7-day total, from the inner folds' weekly errors."""
        return week_total * self.offsets_["weekly_low"], week_total * self.offsets_["weekly_high"]
