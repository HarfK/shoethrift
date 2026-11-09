"""A retrain refits a model but must keep its test figures and the date they were calculated."""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import retrain  # noqa: E402


def test_retrain_keeps_the_test_figures_and_their_date(tmp_path, monkeypatch):
    monkeypatch.setattr(retrain, "MODEL_DIR", tmp_path)
    (tmp_path / "x.json").write_text(json.dumps({
        "trained_until": "2026-11-01", "evaluated_at": "2026-11-02T01:00:00+00:00", "evaluation": {"A": {"metrics": {"MAE (RM)": 500.0}}},
    }), encoding="utf-8")

    retrain._save("x", {"model": 1}, {"trained_until": "2026-11-08"})

    saved = json.loads((tmp_path / "x.json").read_text(encoding="utf-8"))
    assert saved["trained_until"] == "2026-11-08"
    assert saved["evaluated_at"] == "2026-11-02T01:00:00+00:00"
    assert saved["evaluation"]["A"]["metrics"]["MAE (RM)"] == 500.0
    assert saved["retrained_at"] > saved["evaluated_at"]
