"""Check that the ML service can read the shop database.

Prints the number of orders and products, and the first and last order date in Malaysia time.
Run from the project root:  ml-service\\venv\\Scripts\\python ml-service\\test_connection.py
"""
from datetime import timedelta

from sqlalchemy import text

from db import get_engine

MALAYSIA_OFFSET = timedelta(hours=8)  # the database stores UTC


def main() -> None:
    with get_engine().connect() as conn:
        orders = conn.execute(text("SELECT COUNT(*) FROM orders")).scalar_one()
        products = conn.execute(text("SELECT COUNT(*) FROM products")).scalar_one()
        first, last = conn.execute(text("SELECT MIN(created_at), MAX(created_at) FROM orders")).one()

    print(f"Orders:   {orders}")
    print(f"Products: {products}")
    if first is None:
        print("No orders yet.")
        return

    fmt = "%d %b %Y, %I:%M %p"
    print(f"First order: {(first + MALAYSIA_OFFSET).strftime(fmt)} (Malaysia time)")
    print(f"Last order:  {(last + MALAYSIA_OFFSET).strftime(fmt)} (Malaysia time)")


if __name__ == "__main__":
    main()
