"""Restock demand models with one shared interface.

    model.fit(X, y)   X: snapshot rows (restock.data.FEATURES), y: units sold in the next 14 days
    model.predict(X)  -> pd.DataFrame(index=X.index, columns=["yhat", "upper"])

`yhat` is the expected demand over the next 14 days; `upper` is the 80% upper bound
(the 80th percentile of a Poisson distribution with mean yhat: demand should be at or below it 8 times in 10).
"""
from __future__ import annotations

import numpy as np
import pandas as pd
from scipy.stats import poisson
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler

from .data import CATEGORICAL_FEATURES, MIN_TRAIN_STOCK, NUMERIC_FEATURES

UPPER_QUANTILE = 0.80


def poisson_upper(mean, q: float = UPPER_QUANTILE) -> np.ndarray:
    mean = np.clip(np.asarray(mean, dtype=float), 0, None)
    return np.where(mean > 0, poisson.ppf(q, np.maximum(mean, 1e-9)), 0.0)


def _preprocessor(scale: bool) -> ColumnTransformer:
    numeric = StandardScaler() if scale else "passthrough"
    return ColumnTransformer([
        ("num", numeric, NUMERIC_FEATURES),
        ("cat", OneHotEncoder(handle_unknown="ignore", sparse_output=False), CATEGORICAL_FEATURES),
    ])


class RestockModel:
    name = "base"

    def fit(self, X: pd.DataFrame, y) -> "RestockModel":
        self._fit(X, np.asarray(y, dtype=float))
        return self

    def predict(self, X: pd.DataFrame) -> pd.DataFrame:
        yhat = np.clip(self._predict(X), 0, None)
        return pd.DataFrame({"yhat": yhat, "upper": poisson_upper(yhat)}, index=X.index)

    def feature_importance(self) -> pd.Series | None:
        return None

    def _fit(self, X, y):
        raise NotImplementedError

    def _predict(self, X):
        raise NotImplementedError


class MovingAverage(RestockModel):
    """Baseline: the next 14 days sell as many pairs as half of the last 28 days."""

    name = "Moving average"

    def _fit(self, X, y):
        pass

    def _predict(self, X):
        return X["sold_28"].to_numpy(dtype=float) / 2


class _PipelineModel(RestockModel):
    scale = False

    def _estimator(self):
        raise NotImplementedError

    def _fit(self, X, y):
        self.pipeline_ = Pipeline([("prep", _preprocessor(self.scale)), ("model", self._estimator())]).fit(X, y)

    def _predict(self, X):
        return self.pipeline_.predict(X)

    def _feature_names(self):
        return [n.split("__", 1)[1] for n in self.pipeline_.named_steps["prep"].get_feature_names_out()]


class PoissonModel(_PipelineModel):
    """Poisson regression: log(demand) = a weighted sum of the (scaled, one-hot) features."""

    name = "Poisson regression"
    scale = True

    def __init__(self, alpha: float = 0.1):
        self.alpha = alpha

    def _estimator(self):
        from sklearn.linear_model import PoissonRegressor

        return PoissonRegressor(alpha=self.alpha, max_iter=2000)

    def feature_importance(self):
        coef = self.pipeline_.named_steps["model"].coef_
        return pd.Series(np.abs(coef), index=self._feature_names()).sort_values(ascending=False)


class RandomForestModel(_PipelineModel):
    """Random forest: the average of 300 decision trees, each trained on a random sample."""

    name = "Random forest"

    def __init__(self, n_estimators: int = 300, min_samples_leaf: int = 5, random_state: int = 0):
        self.n_estimators, self.min_samples_leaf, self.random_state = n_estimators, min_samples_leaf, random_state

    def _estimator(self):
        from sklearn.ensemble import RandomForestRegressor

        return RandomForestRegressor(n_estimators=self.n_estimators, min_samples_leaf=self.min_samples_leaf,
                                     random_state=self.random_state, n_jobs=-1)

    def feature_importance(self):
        return pd.Series(self.pipeline_.named_steps["model"].feature_importances_, index=self._feature_names()).sort_values(ascending=False)


class XGBoostModel(_PipelineModel):
    """Gradient-boosted trees with a Poisson (count) objective."""

    name = "XGBoost (Poisson)"

    def __init__(self, n_estimators: int = 400, learning_rate: float = 0.03, max_depth: int = 3, random_state: int = 0):
        self.params = dict(n_estimators=n_estimators, learning_rate=learning_rate, max_depth=max_depth,
                           subsample=0.8, colsample_bytree=0.8, random_state=random_state)

    def _estimator(self):
        from xgboost import XGBRegressor

        return XGBRegressor(objective="count:poisson", **self.params)

    def feature_importance(self):
        return pd.Series(self.pipeline_.named_steps["model"].feature_importances_, index=self._feature_names()).sort_values(ascending=False)


def predict_demand(model: RestockModel, X: pd.DataFrame) -> pd.DataFrame:
    """Demand *if pairs were available*: models are trained on snapshots with stock >= 2 (sales of
    emptier groups are capped by stock), so the stock feature is clipped to at least 2 when predicting."""
    X = X.copy()
    X["stock"] = X["stock"].clip(lower=MIN_TRAIN_STOCK)
    return model.predict(X)
