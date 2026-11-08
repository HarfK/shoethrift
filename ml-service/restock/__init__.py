"""Restock prediction for the ShoeThrift admin (Stage 5).

- data:       stock history and weekly snapshots per group (model x size band)
- models:     MovingAverage, PoissonModel, RandomForestModel, XGBoostModel (fit(X, y) / predict(X))
- evaluation: rolling-origin backtest and metrics (MAE, RMSE, sell-out precision/recall/F1)
- report:     build_restock_report(as_of_date): what to restock, in which sizes
"""
