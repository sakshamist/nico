#!/usr/bin/env python3
"""
Compute-node preflight (Sprint 1, FR-9, AC-9, AC-10).

Runs before the worker accepts any job and answers one question: can this
machine actually run the pipeline? It exits non-zero with a specific message
naming the shortfall, rather than letting the worker start and crash later on a
download or an out-of-memory kill.

Usage:
    python3 preflight.py [--json] [--min-disk-gb N] [--require-binaries a,b]

The model tier is *selected* from measured RAM rather than hardcoded, so the same
script works on any machine the worker might run on (SPRINT-04 FR-2).
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import shutil
import subprocess
import sys

# Minimums validated during planning (see specs/README.md "Verified constraints").
MIN_FREE_DISK_GB = 15.0

# Required to transcribe media and to OCR images.
REQUIRED_BINARIES = ["ffmpeg", "tesseract", "python3"]

# RAM cost per whisper tier, int8, approximate peak resident memory.
# Each entry: (tier name, approx GB needed).
MODEL_TIERS = [
    ("large-v3-turbo", 2.9),
    ("medium", 1.5),
    ("small", 0.8),
    ("tiny", 0.4),
]

# Headroom reserved for the OS, the LLM, and the worker itself.
RAM_HEADROOM_GB = 2.0

# If swap is more than this fraction consumed, the machine is already thrashing
# and we should size the tier down. Total RAM alone is misleading: an 8 GB Mac
# with 6 GB of swap in use cannot really host a 2.9 GB model.
SWAP_PRESSURE_THRESHOLD = 0.5


def available_ram_bytes() -> int:
    """
    Memory actually available, not merely installed.

    Total RAM is the wrong input: on a machine already swapping, a model sized
    against the headline figure will be OOM-killed mid-transcription. This
    prefers the kernel's own estimate of available memory where one exists.
    """
    # Linux/macOS-free path: /proc gives MemAvailable directly.
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as fh:
            for line in fh:
                if line.startswith("MemAvailable:"):
                    return int(line.split()[1]) * 1024
    except (OSError, ValueError, IndexError):
        pass

    # vm_stat on macOS: pages free + inactive + speculative are reclaimable.
    if platform.system() == "Darwin":
        try:
            out = subprocess.run(
                ["vm_stat"], capture_output=True, text=True, check=True
            ).stdout
            page_size = 4096
            stats = {}
            for line in out.splitlines():
                if ":" in line:
                    key, _, value = line.partition(":")
                    stats[key.strip()] = value.strip().rstrip(".")

            def pages(*keys: str) -> int:
                total = 0
                for k in keys:
                    if k in stats:
                        total += int(stats[k])
                return total * page_size

            # Inactive and speculative pages can be reclaimed without swapping.
            return pages("Pages free", "Pages inactive", "Pages speculative")
        except Exception:
            pass

    return 0


def swap_used_fraction() -> float | None:
    """Fraction of swap in use, or None when it cannot be determined."""
    try:
        out = subprocess.run(
            ["sysctl", "vm.swapusage"], capture_output=True, text=True, check=True
        ).stdout
        used = int(out.split("used=")[1].split("MB")[0].strip())
        total = int(out.split("total=")[1].split("MB")[0].strip())
        return used / total if total else None
    except Exception:
        return None


def human_gb(value_bytes: int) -> str:
    return f"{value_bytes / (1024 ** 3):.1f} GB"


def free_disk_bytes(path: str) -> int:
    """Free bytes on the filesystem holding `path`, following symlinks."""
    return shutil.disk_usage(os.path.realpath(path)).free


def total_ram_bytes() -> int:
    """Total physical RAM. Uses sysctl on macOS, sysinfo elsewhere."""
    if platform.system() == "Darwin":
        try:
            out = subprocess.run(
                ["sysctl", "-n", "hw.memsize"],
                capture_output=True, text=True, check=True,
            ).stdout.strip()
            return int(out)
        except Exception:
            pass

    try:
        pages = os.sysconf("SC_PHYS_PAGES")
        page_size = os.sysconf("SC_PAGE_SIZE")
        return int(pages * page_size)
    except (ValueError, OSError, AttributeError):
        return 0


def check_binary(name: str) -> dict:
    """
    A binary is only 'ok' if it is both on PATH and actually executable. macOS
    Gatekeeper quarantine can leave a binary present but unlaunchable, which is
    why presence alone is not enough.
    """
    path = shutil.which(name)

    if path is None:
        return {"name": name, "ok": False, "path": None,
                "reason": "not found on PATH"}

    if not os.access(path, os.X_OK):
        return {"name": name, "ok": False, "path": path,
                "reason": "found but not executable (check file permissions)"}

    try:
        probe = subprocess.run([path, "-version"], capture_output=True, timeout=20)
        # Many tools exit non-zero on -version; existence of output is the signal.
        produced_output = bool(probe.stdout or probe.stderr)
    except (OSError, subprocess.SubprocessError):
        produced_output = False

    if not produced_output:
        return {
            "name": name, "ok": False, "path": path,
            "reason": "present but did not run (macOS quarantine? try: xattr -dr com.apple.quarantine <path>)",
        }

    return {"name": name, "ok": True, "path": path, "reason": None}


def select_tier(total_ram_gb: float) -> dict:
    """
    Largest tier that fits the *available* memory after headroom.

    Sized against available rather than total memory, and discounted further when
    the machine is already swapping. None if nothing fits.
    """
    swap_fraction = swap_used_fraction()
    under_pressure = swap_fraction is not None and swap_fraction > SWAP_PRESSURE_THRESHOLD

    # Headroom covers the OS, the LLM, and the worker itself.
    budget = total_ram_gb - RAM_HEADROOM_GB

    # A thrashing machine gets a smaller effective budget: reclaiming swap during
    # a transcription makes the job dramatically slower and risks an OOM kill.
    if under_pressure:
        budget *= 0.6

    for name, need in MODEL_TIERS:
        if need <= budget:
            return {
                "selected": name,
                "approx_ram_gb": need,
                "total_ram_gb": round(total_ram_gb, 2),
                "usable_budget_gb": round(budget, 2),
                "headroom_reserved_gb": RAM_HEADROOM_GB,
                "swap_used_fraction": round(swap_fraction, 2) if swap_fraction is not None else None,
                "swap_pressure": under_pressure,
                "note": "Selected from measured memory, not hardcoded.",
            }

    return {
        "selected": None,
        "approx_ram_gb": None,
        "total_ram_gb": round(total_ram_gb, 2),
        "usable_budget_gb": round(budget, 2),
        "headroom_reserved_gb": RAM_HEADROOM_GB,
        "swap_used_fraction": round(swap_fraction, 2) if swap_fraction is not None else None,
        "swap_pressure": under_pressure,
        "note": "No model tier fits; more RAM required.",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Nico compute-node preflight")
    parser.add_argument("--json", action="store_true", help="machine-readable output")
    parser.add_argument("--min-disk-gb", type=float, default=MIN_FREE_DISK_GB)
    parser.add_argument(
        "--require-binaries",
        default=",".join(REQUIRED_BINARIES),
        help="comma-separated binaries that must be executable",
    )
    args = parser.parse_args()

    required = [b.strip() for b in args.require_binaries.split(",") if b.strip()]

    failures: list[str] = []

    # --- disk ---
    free_bytes = free_disk_bytes(os.getcwd())
    free_gb = free_bytes / (1024 ** 3)
    disk_ok = free_gb >= args.min_disk_gb
    if not disk_ok:
        failures.append(
            f"insufficient disk: {free_gb:.1f} GB free, need {args.min_disk_gb:.1f} GB "
            f"(free up space or point the worker at a larger volume)"
        )

    # --- binaries ---
    binaries = [check_binary(name) for name in required]
    for b in binaries:
        if not b["ok"]:
            failures.append(f"binary '{b['name']}' unusable: {b['reason']}")

    # --- memory / tier ---
    total_ram = total_ram_bytes()
    avail_ram = available_ram_bytes()

    # Size against whichever is the more conservative signal.
    sizing_gb = (min(total_ram, avail_ram) if avail_ram else total_ram) / (1024 ** 3)
    tier = select_tier(sizing_gb) if total_ram else {
        "selected": None, "note": "could not read total RAM",
    }

    if tier.get("selected") is None:
        failures.append(
            f"insufficient RAM: {sizing_gb:.1f} GB usable of "
            f"{total_ram / (1024 ** 3):.1f} GB total, need more than "
            f"{RAM_HEADROOM_GB + MODEL_TIERS[-1][1]:.1f} GB to run any model tier"
        )

    report = {
        "ok": not failures,
        "platform": {
            "system": platform.system(),
            "release": platform.release(),
            "machine": platform.machine(),
            "python": platform.python_version(),
        },
        "disk": {
            "free_bytes": free_bytes,
            "free_gb": round(free_gb, 2),
            "required_gb": args.min_disk_gb,
            "ok": disk_ok,
        },
        "memory": {
            "total_bytes": total_ram,
            "total_gb": round(total_ram / (1024 ** 3), 2) if total_ram else None,
            "available_bytes": avail_ram,
            "available_gb": round(avail_ram / (1024 ** 3), 2) if avail_ram else None,
            "sized_against_gb": round(sizing_gb, 2) if total_ram else None,
            "swap_used_fraction": tier.get("swap_used_fraction"),
            "swap_pressure": tier.get("swap_pressure"),
            "ok": tier.get("selected") is not None,
        },
        "model_tier": tier,
        "binaries": binaries,
        "failures": failures,
    }

    if args.json:
        print(json.dumps(report, indent=2))
    else:
        print("Nico compute-node preflight")
        print(f"  platform : {report['platform']['system']} "
              f"{report['platform']['machine']} / python {report['platform']['python']}")
        print(f"  disk     : {report['disk']['free_gb']} GB free "
              f"(need {report['disk']['required_gb']} GB) "
              f"{'OK' if disk_ok else 'FAIL'}")

        mem = report["memory"]
        print(f"  memory   : {mem['available_gb']} GB available / "
              f"{mem['total_gb']} GB total "
              f"{'OK' if mem['ok'] else 'FAIL'}")
        if mem.get("swap_pressure"):
            print(f"             (swap {mem['swap_used_fraction']:.0%} used - "
                  f"sizing tier down)")
        print(f"  asr tier : {tier.get('selected') or 'NONE FITTING'} "
              f"(budget {tier.get('usable_budget_gb')} GB)")
        for b in binaries:
            print(f"  binary   : {b['name']:<12} {'OK' if b['ok'] else 'FAIL - ' + b['reason']}")

        if failures:
            print("\nPREFLIGHT FAILED")
            for f in failures:
                print(f"  - {f}")
        else:
            print("\nPREFLIGHT PASSED")

    return 0 if not failures else 1


if __name__ == "__main__":
    sys.exit(main())
