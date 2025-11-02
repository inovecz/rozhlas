#!/usr/bin/env python3
import os
import sys
from pathlib import Path

target = Path(__file__).resolve().parents[1] / 'python-client' / 'daemons' / 'gsm_listener.py'
os.execv(sys.executable, [sys.executable, str(target), *sys.argv[1:]])
