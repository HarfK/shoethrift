"""The forecast backfill must never let a model see the days it predicts. Uses fake data and a fake model."""
import sys
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from backfill_forecasts import backfill  # noqa: E402


class RecordingModel:
    name = "Fake model"
    fitted_until = []

    def fit(self, history):
        RecordingModel.fitted_until.append(history.index.max())
        return self

    def predict(self, dates):
        return pd.DataFrame({"yhat": 100.0, "lower": 50.0, "upper": 150.0}, index=dates)


def test_each_block_is_trained_only_on_earlier_days():
    RecordingModel.fitted_until = []
    days = pd.date_range("2026-01-01", "2026-11-08", freq="D")

    result = backfill("2026-11-09", 14, history=pd.Series(1.0, index=days), new_model=RecordingModel)

    assert result["model"] == "Fake model"
    assert [row["date"] for row in result["rows"]] == [f"{d:%Y-%m-%d}" for d in pd.date_range("2026-10-26", "2026-11-08")]
    assert RecordingModel.fitted_until == [pd.Timestamp("2026-10-25"), pd.Timestamp("2026-11-01")]
    assert {row["made_on"] for row in result["rows"][:7]} == {"2026-10-25"}
    assert {row["made_on"] for row in result["rows"][7:]} == {"2026-11-01"}
    for row in result["rows"]:
        assert row["trained_until"] < row["date"]
        assert row["made_on"] < row["date"]
