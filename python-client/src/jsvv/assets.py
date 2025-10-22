"""Asset discovery helpers for JSVV audio resources."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


@dataclass(frozen=True)
class AssetInfo:
    slot: int
    category: str
    voice: str | None
    path: Path
    size: int | None
    modified: float | None


def build_asset_list(index: dict[tuple[int, str], Path], category: str) -> list[AssetInfo]:
    assets: list[AssetInfo] = []
    for (slot, voice), path in sorted(index.items(), key=lambda item: (item[0][0], item[0][1])):
        assets.append(
            AssetInfo(
                slot=slot,
                category=category,
                voice=None if voice == "siren" else voice,
                path=path,
                size=path.stat().st_size if path.exists() else None,
                modified=path.stat().st_mtime if path.exists() else None,
            )
        )
    return assets
