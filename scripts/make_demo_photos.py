"""Draw one clearly-labelled DEMO image per shoe model + colour (no real photos exist yet).

Reads the models from database/seeders/data/thrift/products.csv and writes
storage/app/demo-photos/{model-slug}--{colour-slug}.jpg, which
`php artisan products:attach-photos` then links to the products.

Usage (from the project root, Pillow is in the ML venv):
    ml-service\\venv\\Scripts\\python scripts\\make_demo_photos.py

Replace these with real photos later: drop files with the same names (or just
{model-slug}.jpg) into a folder and run `php artisan products:attach-photos <folder> --replace-demo`.
"""
import csv
import re
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "database/seeders/data/thrift/products.csv"
OUT = ROOT / "storage/app/demo-photos"
SIZE = 800

# Category ids as seeded by CategorySeeder / ThriftHistorySeeder.
SHAPES = {1: "sneaker", 2: "runner", 3: "sneaker", 4: "boot", 5: "formal", 6: "sandal"}

COLOURS = {
    "black": (38, 38, 38), "white": (245, 245, 240), "beige": (214, 196, 160), "brown": (124, 82, 50),
    "grey": (150, 150, 150), "navy": (35, 50, 90), "red": (190, 40, 45), "green": (40, 120, 75),
}
BACKGROUND = (245, 245, 244)
OUTLINE = (68, 64, 60)


def slug(text: str) -> str:
    """Same rule as Laravel's Str::slug for these names (ASCII, lower, dashes)."""
    return re.sub(r"[^a-z0-9]+", "-", text.lower().replace("&", "and")).strip("-")


def font(size: int, bold: bool = False):
    for name in (["arialbd.ttf", "DejaVuSans-Bold.ttf"] if bold else ["arial.ttf", "DejaVuSans.ttf"]):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def palette(colour: str):
    parts = [COLOURS.get(p.strip().lower(), COLOURS["grey"]) for p in colour.split("/")]
    upper = parts[0]
    accent = parts[1] if len(parts) > 1 else tuple(max(0, min(255, c + (60 if sum(upper) < 300 else -60))) for c in upper)
    sole = parts[2] if len(parts) > 2 else ((225, 225, 220) if upper != COLOURS["white"] else (205, 180, 140))
    return upper, accent, sole


def draw_shoe(d: ImageDraw.ImageDraw, shape: str, upper, accent, sole):
    if shape == "sandal":
        d.rounded_rectangle((110, 500, 690, 560), 28, fill=sole, outline=OUTLINE, width=4)
        d.rounded_rectangle((120, 480, 680, 512), 14, fill=accent, outline=OUTLINE, width=3)
        for x in (300, 470):
            d.chord((x - 110, 330, x + 110, 640), 180, 360, fill=upper, outline=OUTLINE, width=4)
            d.ellipse((x - 10, 405, x + 10, 425), fill=(200, 190, 160), outline=OUTLINE, width=2)
        return

    if shape == "formal":
        d.rounded_rectangle((120, 515, 700, 545), 10, fill=(55, 40, 30), outline=OUTLINE, width=3)
        d.rectangle((140, 540, 230, 575), fill=(55, 40, 30), outline=OUTLINE, width=3)
        body = [(135, 520), (140, 420), (250, 400), (330, 420), (560, 440), (660, 470), (705, 505), (700, 520)]
        d.polygon(body, fill=upper, outline=OUTLINE, width=4)
        d.line([(330, 420), (400, 470), (520, 470), (560, 440)], fill=accent, width=5)
        d.line([(600, 452), (585, 518)], fill=accent, width=4)
        return

    top = 210 if shape == "boot" else 340
    d.rounded_rectangle((110, 515, 700, 575), 22, fill=sole, outline=OUTLINE, width=4)
    if shape == "runner":
        d.rounded_rectangle((110, 495, 700, 535), 18, fill=accent, outline=OUTLINE, width=3)
    body = [(130, 520), (138, top), (270, top - 10), (330, 380), (520, 410), (640, 450), (695, 495), (700, 520)]
    d.polygon(body, fill=upper, outline=OUTLINE, width=4)
    # heel tab, toe cap, side panel and laces in the accent colour
    d.polygon([(130, 520), (138, top + 40), (190, top + 50), (195, 520)], fill=accent, outline=OUTLINE, width=3)
    d.polygon([(585, 520), (600, 436), (640, 450), (695, 495), (700, 520)], fill=accent, outline=OUTLINE, width=3)
    d.polygon([(260, 500), (340, 430), (520, 440), (470, 500)], fill=accent, outline=OUTLINE, width=3)
    for i in range(5):
        x, y = 345 + i * 38, 388 + i * 6
        d.line([(x, y), (x + 22, y + 12)], fill=COLOURS["white"] if upper != COLOURS["white"] else OUTLINE, width=6)


def make(name: str, colour: str, category_id: int) -> Image.Image:
    img = Image.new("RGB", (SIZE, SIZE), BACKGROUND)
    d = ImageDraw.Draw(img)
    upper, accent, sole = palette(colour)
    draw_shoe(d, SHAPES.get(category_id, "sneaker"), upper, accent, sole)
    d.ellipse((140, 585, 680, 615), fill=(225, 224, 220))  # shadow

    title, sub = font(46, bold=True), font(30)
    d.text((SIZE / 2, 110), name, font=title, fill=(28, 25, 23), anchor="mm")
    d.text((SIZE / 2, 165), colour, font=sub, fill=(87, 83, 78), anchor="mm")
    d.rounded_rectangle((250, 690, 550, 740), 25, fill=(254, 243, 199), outline=(217, 119, 6), width=2)
    d.text((SIZE / 2, 715), "DEMO IMAGE", font=font(24, bold=True), fill=(146, 64, 14), anchor="mm")
    return img


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    seen = {}
    with SOURCE.open(encoding="utf-8") as f:
        for row in csv.DictReader(f):
            seen.setdefault((row["name"], row["color"] or ""), int(row["category_id"]))

    for (name, colour), category_id in sorted(seen.items()):
        file = OUT / (f"{slug(name)}--{slug(colour)}.jpg" if colour else f"{slug(name)}.jpg")
        make(name, colour or "", category_id).save(file, "JPEG", quality=85, optimize=True)
    print(f"{len(seen)} demo images written to {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
