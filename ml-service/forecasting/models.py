"""Daily revenue forecasting models with one shared interface.

    model.fit(history)    history: pd.Series of daily net revenue (RM), continuous DatetimeIndex
    model.predict(dates)  dates after the end of history ->
                          pd.DataFrame(index=dates, columns=["yhat", "lower", "upper"])

`lower` / `upper` form an 80% prediction interval. Revenue can't be negative, so everything is
clipped at 0. Each model computes its own calendar features from the dates, so a saved model only
needs the dates to forecast.
"""
from __future__ import annotations

import logging
import math
import warnings

import numpy as np
import pandas as pd

from .calendar import FEATURES as CALENDAR_FEATURES
from .calendar import calendar_features, prophet_holidays

INTERVAL = 0.80
Q_LOW, Q_HIGH = (1 - INTERVAL) / 2, 1 - (1 - INTERVAL) / 2  # 0.10, 0.90


class ForecastModel:
    """Base class: stores the history and checks the forecast dates."""

    name = "base"
    features: list[str] = []

    def fit(self, history: pd.Series) -> "ForecastModel":
        history = history.astype(float).asfreq("D")
        if history.isna().any():
            raise ValueError("history must be a continuous daily series (fill missing days with 0)")
        self.history_ = history
        self.trained_until_ = history.index.max()
        self._fit(history)
        return self

    def predict(self, dates) -> pd.DataFrame:
        dates = pd.DatetimeIndex(pd.to_datetime(dates)).normalize()
        if (dates <= self.trained_until_).any():
            raise ValueError(f"forecast dates must be after {self.trained_until_:%Y-%m-%d}")
        out = self._predict(dates)
        out = out.clip(lower=0)
        out["lower"] = np.minimum(out["lower"], out["yhat"])
        out["upper"] = np.maximum(out["upper"], out["yhat"])
        return out[["yhat", "lower", "upper"]]

    def _future_days(self, dates: pd.DatetimeIndex) -> pd.DatetimeIndex:
        """Every day from the end of history to the last requested date."""
        return pd.date_range(self.trained_until_ + pd.Timedelta(days=1), dates.max(), freq="D")

    def _fit(self, history: pd.Series) -> None:
        raise NotImplementedError

    def _predict(self, dates: pd.DatetimeIndex) -> pd.DataFrame:
        raise NotImplementedError


class SeasonalNaive(ForecastModel):
    """Baseline: each day = the same weekday one week earlier (k weeks for longer horizons).
    Interval: 10th/90th percentile of past one-week-ahead errors, widened by sqrt(weeks ahead)."""

    name = "Seasonal naive"
    features = ["revenue 7 days earlier"]

    def _fit(self, history):
        errors = (history - history.shift(7)).dropna()
        self.q_low_, self.q_high_ = errors.quantile(Q_LOW), errors.quantile(Q_HIGH)

    def _predict(self, dates):
        rows = []
        for d in dates:
            weeks = math.ceil((d - self.trained_until_).days / 7)
            yhat = self.history_[d - pd.Timedelta(days=7 * weeks)]
            spread = math.sqrt(weeks)
            rows.append((yhat, yhat + self.q_low_ * spread, yhat + self.q_high_ * spread))
        return pd.DataFrame(rows, index=dates, columns=["yhat", "lower", "upper"])


class Sarima(ForecastModel):
    """SARIMAX with a weekly seasonal part (s = 7) and the calendar flags as exogenous variables.
    Choose the orders with `Sarima.select_order()` (small AIC grid) before backtesting.

    log=True models log1p(revenue): weekly and event effects become percentages. Turning a log
    forecast back gives a median, which is too low for skewed revenue with zero days, so the point
    forecast gets a level correction: it is scaled so the fitted total over the training data equals
    the real total (a ratio-of-sums version of Duan's smearing). The interval bounds are not scaled."""

    name = "SARIMA"

    def __init__(self, order=(1, 0, 1), seasonal_order=(1, 0, 1, 7), exog=CALENDAR_FEATURES, log=False):
        self.order = tuple(order)
        self.seasonal_order = tuple(seasonal_order)
        self.exog = list(exog)
        self.log = log

    @property
    def features(self):
        target = "log1p(revenue)" if getattr(self, "log", False) else "revenue"
        return [f"SARIMA{self.order}x{self.seasonal_order} on {target}", *self.exog]

    @staticmethod
    def _trend(d: int, D: int = 0) -> str:
        # No differencing: constant + linear trend. One difference: a drift term.
        # Two differences remove any linear trend, so no trend term.
        return {0: "ct", 1: "t"}.get(d + D, "n")

    def _exog(self, index, columns):
        return calendar_features(index)[columns].astype(float)

    def _fit(self, history):
        from statsmodels.tsa.statespace.sarimax import SARIMAX

        target = np.log1p(history) if getattr(self, "log", False) else history
        self.log_cap_ = float(np.log1p(3 * history.max()))  # guard: never forecast above 3x the best day
        exog = self._exog(history.index, self.exog)
        self.exog_used_ = [c for c in self.exog if exog[c].nunique() > 1]  # skip flags that never occur
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            self.result_ = SARIMAX(target, exog=exog[self.exog_used_], order=self.order, seasonal_order=self.seasonal_order,
                                   trend=self._trend(self.order[1], self.seasonal_order[1])).fit(disp=False, maxiter=200)
        if getattr(self, "log", False):
            burn_in = self.order[1] + 7 * self.seasonal_order[1] + 14  # first fitted values come from differencing start-up
            self.smear_ = _smearing(np.asarray(target)[burn_in:], np.asarray(self.result_.fittedvalues)[burn_in:])

    def _predict(self, dates):
        days = self._future_days(dates)
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            forecast = self.result_.get_forecast(len(days), exog=self._exog(days, self.exog_used_))
        ci = forecast.conf_int(alpha=1 - INTERVAL)
        out = pd.DataFrame({"yhat": forecast.predicted_mean.values, "lower": ci.iloc[:, 0].values,
                            "upper": ci.iloc[:, 1].values}, index=days)
        if getattr(self, "log", False):
            out = _from_log(out, self.log_cap_, self.smear_)
        return out.loc[dates]

    @classmethod
    def select_order(cls, history: pd.Series, p=(0, 1, 2), d=(0, 1), q=(0, 1, 2),
                     seasonal=((0, 0), (1, 0), (0, 1), (1, 1)), D=(0,), exog=CALENDAR_FEATURES, log=False,
                     n_jobs=-1) -> pd.DataFrame:
        """Fit every combination on `history` (in parallel) and return them sorted by AIC, best first."""
        from joblib import Parallel, delayed

        grid = [((pi, di, qi), (P, Di, Q, 7)) for pi in p for di in d for qi in q for P, Q in seasonal for Di in D]
        rows = Parallel(n_jobs=n_jobs)(delayed(_aic)(cls, history, order, seasonal_order, exog, log) for order, seasonal_order in grid)
        return pd.DataFrame(rows).sort_values("aic").reset_index(drop=True)


def _aic(cls, history, order, seasonal_order, exog, log=False) -> dict:
    """One grid point for Sarima.select_order (module level so joblib can run it in another process)."""
    row = {"order": order, "seasonal_order": seasonal_order}
    try:
        row["aic"] = cls(order, seasonal_order, exog, log=log).fit(history).result_.aic
    except Exception as error:  # some combinations don't converge
        row["aic"], row["error"] = np.nan, str(error)[:80]
    return row


class ProphetModel(ForecastModel):
    """Prophet: trend + weekly and yearly seasonality + Malaysian events as holidays
    (double days, Black Friday, Hari Raya, CNY, Deepavali). Payday, pre-Raya, back-to-school
    and month-end are extra regressors because they are windows, not single days.

    log=True models log1p(revenue) (effects become percentages). growth="flat" removes the trend;
    a smaller changepoint_prior_scale makes the trend stiffer (less likely to over-extend growth)."""

    name = "Prophet"
    REGRESSORS = ["payday", "pre_raya", "back_to_school", "month_end"]

    def __init__(self, log=False, growth="linear", changepoint_prior_scale=0.05):
        self.log = log
        self.growth = growth
        self.changepoint_prior_scale = changepoint_prior_scale

    @property
    def features(self):
        target = "log1p(revenue)" if getattr(self, "log", False) else "revenue"
        trend = f"{getattr(self, 'growth', 'linear')} trend (changepoint_prior_scale={getattr(self, 'changepoint_prior_scale', 0.05)})"
        return [f"target: {target}", trend, "weekly seasonality", "yearly seasonality",
                "holidays: double_day, black_friday, hari_raya, chinese_new_year, deepavali", *self.REGRESSORS]

    def _frame(self, index):
        return calendar_features(index)[self.REGRESSORS].reset_index(names="ds")

    def _fit(self, history):
        logging.getLogger("prophet.plot").disabled = True  # "plotly not installed" notice
        from prophet import Prophet

        logging.getLogger("cmdstanpy").disabled = True  # per-fit progress lines
        logging.getLogger("prophet").setLevel(logging.ERROR)
        # A yearly pattern can't be learned from less than a year of data (it overfits wildly).
        model = Prophet(holidays=prophet_holidays(), weekly_seasonality=True, yearly_seasonality=len(history) >= 365,
                        daily_seasonality=False, interval_width=INTERVAL, uncertainty_samples=500,
                        growth=getattr(self, "growth", "linear"),
                        changepoint_prior_scale=getattr(self, "changepoint_prior_scale", 0.05))
        for regressor in self.REGRESSORS:
            model.add_regressor(regressor)
        frame = self._frame(history.index)
        frame["y"] = np.log1p(history.values) if getattr(self, "log", False) else history.values
        self.log_cap_ = float(np.log1p(3 * history.max()))  # guard: never forecast above 3x the best day
        self.model_ = model.fit(frame)
        if getattr(self, "log", False):
            self.smear_ = _smearing(frame["y"].values, self.model_.predict(frame)["yhat"].values)

    def _predict(self, dates):
        np.random.seed(0)  # Prophet samples its intervals; keep them reproducible
        forecast = self.model_.predict(self._frame(dates))
        out = pd.DataFrame({"yhat": forecast["yhat"].values, "lower": forecast["yhat_lower"].values,
                            "upper": forecast["yhat_upper"].values}, index=dates)
        return _from_log(out, self.log_cap_, self.smear_) if getattr(self, "log", False) else out


def _smearing(log_actual, log_fitted) -> float:
    """Level correction for a log1p model: sum(actual + 1) / sum(exp(fitted)) over the training data.
    (Duan's mean(exp(residual)) is unstable here: zero-sales days make the log residuals very wide.)"""
    actual, fitted = np.asarray(log_actual, dtype=float), np.asarray(log_fitted, dtype=float)
    ok = np.isfinite(actual) & np.isfinite(fitted)
    return float(np.exp(actual[ok]).sum() / np.exp(fitted[ok]).sum()) if ok.any() else 1.0


def _from_log(out: pd.DataFrame, cap: float, smear: float) -> pd.DataFrame:
    """Back from log1p space: mean-corrected point forecast, plain bounds."""
    out = out.clip(upper=cap)
    return pd.DataFrame({"yhat": np.exp(out["yhat"]) * smear - 1, "lower": np.expm1(out["lower"]),
                         "upper": np.expm1(out["upper"])}, index=out.index)


class XGBoostModel(ForecastModel):
    """Gradient-boosted trees on calendar + lag features, trained with quantile loss for the
    10th, 50th (point forecast) and 90th percentile. Every lag is at least 7 days old, so a
    7-day-ahead forecast never uses unknown data; longer horizons are forecast week by week,
    feeding the forecasts back in as lags."""

    name = "XGBoost"
    LAGS = (7, 14, 28)
    features = ["day_of_week", "day_of_month", "month", *CALENDAR_FEATURES,
                "lag_7", "lag_14", "lag_28", "rolling_mean_7 (shifted 7)", "rolling_mean_28 (shifted 7)"]

    def __init__(self, n_estimators=400, learning_rate=0.03, max_depth=3, random_state=0):
        self.params = dict(n_estimators=n_estimators, learning_rate=learning_rate, max_depth=max_depth,
                           subsample=0.8, colsample_bytree=0.8, random_state=random_state)

    def _features(self, series: pd.Series, dates: pd.DatetimeIndex) -> pd.DataFrame:
        X = calendar_features(dates).astype(float)
        X.insert(0, "month", dates.month)
        X.insert(0, "day_of_month", dates.day)
        X.insert(0, "day_of_week", dates.day_of_week)
        for lag in self.LAGS:
            X[f"lag_{lag}"] = series.shift(lag).reindex(dates).values
        shifted = series.shift(7)
        X["rolling_mean_7"] = shifted.rolling(7).mean().reindex(dates).values
        X["rolling_mean_28"] = shifted.rolling(28).mean().reindex(dates).values
        return X

    def _fit(self, history):
        from xgboost import XGBRegressor

        X = self._features(history, history.index)
        known = X.notna().all(axis=1)
        self.model_ = XGBRegressor(objective="reg:quantileerror", quantile_alpha=np.array([Q_LOW, 0.5, Q_HIGH]), **self.params)
        self.model_.fit(X[known], history[known])
        self.columns_ = list(X.columns)

    def _predict(self, dates):
        days = self._future_days(dates)
        series = self.history_.reindex(self.history_.index.union(days))
        rows = []
        for start in range(0, len(days), 7):  # one week at a time: lags >= 7 are always known
            block = days[start:start + 7]
            pred = np.sort(self.model_.predict(self._features(series, block)[self.columns_]), axis=1)
            series[block] = pred[:, 1]
            rows.append(pd.DataFrame(pred, index=block, columns=["lower", "yhat", "upper"]))
        return pd.concat(rows).loc[dates]

    def feature_importance(self) -> pd.Series:
        return pd.Series(self.model_.feature_importances_, index=self.columns_).sort_values(ascending=False)
