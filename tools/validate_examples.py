"""Validate synthetic fixtures and Markdown links; does not query infrastructure."""
import json
import re
from pathlib import Path


def main():
    root = Path(__file__).resolve().parents[1]
    data = json.loads((root / "examples/status.json").read_text())
    assert data["collection_ok"] == 1
    assert isinstance(data["collected_at"], int)
    ids = [row["{#IFNAME}"] for row in data["discovery"]]
    assert len(ids) == len(set(ids))
    assert set(ids) == set(data["tunnels"])
    assert {v["session_up"] for v in data["tunnels"].values()} == {0, 1, 2}
    for row in data["discovery"]:
        tunnel = data["tunnels"][row["{#IFNAME}"]]
        assert row["{#UNIT}"] == tunnel["unit"]
        assert row["{#PATH}"] == tunnel["path"]
        if tunnel["session_up"] == 1:
            assert tunnel["ike_established"] == tunnel["child_installed"] == 1
        if tunnel["session_up"] == 2:
            assert 0 < tunnel["children_installed"] < tunnel["children_expected"]
    for doc in root.rglob("*.md"):
        for target in re.findall(r"\]\(([^)]+)\)", doc.read_text()):
            if not target.startswith(("https://", "http://", "#")):
                assert (doc.parent / target.split("#")[0]).exists(), (doc, target)
    print("OK: synthetic inventory, state examples and local documentation links")


if __name__ == "__main__":
    main()
