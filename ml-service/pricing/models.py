"""Price models with one shared interface.

    model.fit(X, y)      X: pricing.data.FEATURES, y: sold price (RM)
    model.predict(X)     -> pd.DataFrame(index=X.index, columns=["yhat", "lower", "upper"])  (RM)
    model.explain(X)     -> pd.DataFrame of RM contributions per feature + "base" (rows sum to yhat)

target="ratio" models price / retail_price and multiplies back; target="price" models RM directly.
The 80% range is calibrated: 5-fold out-of-fold predictions on the training data give the 10th and 90th
percentile of actual / predicted, which are applied to new predictions.
"""
from __future__ import annotations

import copy

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.model_selection import KFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler

from .data import CATEGORICAL_FEATURES, FEATURES, NUMERIC_FEATURES

Q_LOW, Q_HIGH = 0.10, 0.90


def preprocessor(scale: bool) -> ColumnTransformer:
    categorical = Pipeline([("fill", SimpleImputer(strategy="constant", fill_value="unknown")),
                            ("onehot", OneHotEncoder(handle_unknown="ignore", sparse_output=False))])
    numeric = Pipeline([("fill", SimpleImputer(strategy="median")), ("scale", StandardScaler() if scale else "passthrough")])
    return ColumnTransformer([("cat", categorical, CATEGORICAL_FEATURES), ("num", numeric, NUMERIC_FEATURES)])


class PriceModel:
    name = "base"
    target = "price"

    def fit(self, X: pd.DataFrame, y, calibrate: bool = True) -> "PriceModel":
        X, y = X[FEATURES].reset_index(drop=True), pd.Series(np.asarray(y, dtype=float))
        if calibrate:  # out-of-fold actual / predicted ratios -> calibrated 80% range
            oof = np.zeros(len(y))
            for train_idx, val_idx in KFold(5, shuffle=True, random_state=0).split(X):
                fold = copy.deepcopy(self)._fit_core(X.iloc[train_idx], y.iloc[train_idx])
                oof[val_idx] = fold._predict_core(X.iloc[val_idx])
            ratio = y / np.clip(oof, 1, None)
            self.range_ = (float(ratio.quantile(Q_LOW)), float(ratio.quantile(Q_HIGH)))
        self._fit_core(X, y)
        return self

    def predict(self, X: pd.DataFrame) -> pd.DataFrame:
        yhat = np.clip(self._predict_core(X[FEATURES]), 1, None)
        low, high = getattr(self, "range_", (np.nan, np.nan))
        return pd.DataFrame({"yhat": yhat, "lower": yhat * low, "upper": yhat * high}, index=X.index)

    def explain(self, X: pd.DataFrame) -> pd.DataFrame | None:
        return None

    def _fit_core(self, X, y):
        raise NotImplementedError

    def _predict_core(self, X):
        raise NotImplementedError


class RatioBaseline(PriceModel):
    """Median price / retail ratio of the same model + condition, times the retail price.
    Falls back to brand + condition, then condition, then all pairs, when a group has fewer than 3 sales."""

    name = "Baseline (median ratio)"
    target = "ratio"
    MIN_SALES = 3

    def _fit_core(self, X, y):
        data = X.assign(ratio=np.asarray(y) / X["retail_price"].to_numpy())
        self.levels_ = []
        for keys in (["name", "condition"], ["brand", "condition"], ["condition"]):
            g = data.groupby(keys)["ratio"].agg(["median", "size"])
            self.levels_.append((keys, g.loc[g["size"] >= self.MIN_SALES, "median"].to_dict()))
        self.overall_ = float(data["ratio"].median())
        return self

    def _ratio(self, X):
        ratios = []
        for _, row in X.iterrows():
            value = self.overall_
            for keys, table in self.levels_:
                key = tuple(row[k] for k in keys) if len(keys) > 1 else row[keys[0]]
                if key in table:
                    value = table[key]
                    break
            ratios.append(value)
        return np.array(ratios)

    def _predict_core(self, X):
        return self._ratio(X) * X["retail_price"].to_numpy(dtype=float)


class _PipelineModel(PriceModel):
    scale = False

    def __init__(self, target: str = "price"):
        self.target = target

    @property
    def name(self):
        return f"{self.label} ({self.target})"

    def _estimator(self):
        raise NotImplementedError

    def _fit_core(self, X, y):
        y = np.asarray(y, dtype=float)
        y_fit = y / X["retail_price"].to_numpy(dtype=float) if self.target == "ratio" else y
        self.pipeline_ = Pipeline([("prep", preprocessor(self.scale)), ("model", self._estimator())]).fit(X, y_fit)
        return self

    def _predict_core(self, X):
        out = self.pipeline_.predict(X[FEATURES])
        return out * X["retail_price"].to_numpy(dtype=float) if self.target == "ratio" else out

    def _feature_groups(self) -> list[str]:
        """The original feature behind every column after one-hot encoding."""
        prep = self.pipeline_.named_steps["prep"]
        encoder = prep.named_transformers_["cat"].named_steps["onehot"]
        groups = [f for f, cats in zip(CATEGORICAL_FEATURES, encoder.categories_) for _ in cats]
        return groups + NUMERIC_FEATURES

    def _contributions(self, Xt) -> tuple[np.ndarray, np.ndarray]:
        raise NotImplementedError

    def explain(self, X: pd.DataFrame) -> pd.DataFrame:
        """RM contribution of each original feature (one-hot columns added up) plus the base value."""
        Xt = self.pipeline_.named_steps["prep"].transform(X[FEATURES])
        values, base = self._contributions(Xt)
        frame = pd.DataFrame(values, columns=self._feature_groups(), index=X.index).T.groupby(level=0).sum().T[FEATURES]
        frame["base"] = base
        if self.target == "ratio":  # price = retail x f(x): scale every part by the retail price
            frame = frame.mul(X["retail_price"].to_numpy(dtype=float), axis=0)
        return frame


class RidgeModel(_PipelineModel):
    """Linear regression with a small penalty, on one-hot + scaled features."""

    label = "Ridge"
    scale = True

    def _estimator(self):
        from sklearn.linear_model import Ridge

        return Ridge(alpha=1.0)

    def _contributions(self, Xt):
        model = self.pipeline_.named_steps["model"]
        mean = self.mean_xt_ if hasattr(self, "mean_xt_") else Xt.mean(axis=0)
        values = (Xt - mean) * model.coef_
        return values, np.full(len(Xt), model.intercept_ + float(mean @ model.coef_))

    def _fit_core(self, X, y):
        super()._fit_core(X, y)
        self.mean_xt_ = self.pipeline_.named_steps["prep"].transform(X[FEATURES]).mean(axis=0)
        return self


class _TreeModel(_PipelineModel):
    def _contributions(self, Xt):
        import shap

        explainer = shap.TreeExplainer(self.pipeline_.named_steps["model"])
        values = explainer.shap_values(Xt)
        base = np.full(len(Xt), float(np.ravel(explainer.expected_value)[0]))
        return values, base


class RandomForestModel(_TreeModel):
    label = "Random forest"

    def _estimator(self):
        from sklearn.ensemble import RandomForestRegressor

        return RandomForestRegressor(n_estimators=400, min_samples_leaf=3, random_state=0, n_jobs=-1)


class XGBoostModel(_TreeModel):
    label = "XGBoost"

    def _estimator(self):
        from xgboost import XGBRegressor

        return XGBRegressor(n_estimators=600, learning_rate=0.03, max_depth=4, subsample=0.8, colsample_bytree=0.8, random_state=0)


class LightGBMModel(_TreeModel):
    label = "LightGBM"

    def _estimator(self):
        from lightgbm import LGBMRegressor

        return LGBMRegressor(n_estimators=600, learning_rate=0.03, num_leaves=15, min_child_samples=10,
                             subsample=0.8, subsample_freq=1, colsample_bytree=0.8, random_state=0, verbose=-1)


def metrics(actual, pred: pd.DataFrame) -> dict:
    actual = np.asarray(actual, dtype=float)
    yhat = pred["yhat"].to_numpy()
    error = yhat - actual
    ss_res, ss_tot = (error ** 2).sum(), ((actual - actual.mean()) ** 2).sum()
    return {
        "MAE (RM)": np.abs(error).mean(),
        "RMSE (RM)": np.sqrt((error ** 2).mean()),
        "R2": 1 - ss_res / ss_tot,
        "MAPE (%)": 100 * np.mean(np.abs(error) / actual),
        "within RM20 (%)": 100 * np.mean(np.abs(error) <= 20),
        "within 15% (%)": 100 * np.mean(np.abs(error) <= 0.15 * actual),
        "80% range coverage (%)": 100 * np.mean((actual >= pred["lower"].to_numpy()) & (actual <= pred["upper"].to_numpy())),
    }
