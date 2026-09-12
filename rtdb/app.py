import ast
import asyncio
import copy
import json
import os
import random
import re
import time
import traceback
from contextlib import asynccontextmanager
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, List, Optional

import requests
from fastapi import Body, FastAPI, File, Form, HTTPException, Request, Response, UploadFile, WebSocket, WebSocketDisconnect, status
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse, Response, StreamingResponse
from simpleeval import EvalWithCompoundTypes, FeatureNotAvailable

HF_TOKEN = os.getenv("HF_TOKEN", "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")
BUCKET_NAME = os.getenv("BUCKET_NAME", "plygram/backend")
PUBLIC_BASE_URL = os.getenv("PUBLIC_BASE_URL", "")

HF_BUCKET_URL = f"https://huggingface.co/api/buckets/{BUCKET_NAME}/raw"
HF_HEADERS = {"Authorization": f"Bearer {HF_TOKEN}"}

def hf_bucket_get(remote_path: str) -> Optional[bytes]:
    if not HF_TOKEN or HF_TOKEN.startswith("hf_YOUR"):
        return None
    url = f"{HF_BUCKET_URL}/{remote_path.lstrip('/')}"
    try:
        r = requests.get(url, headers=HF_HEADERS, timeout=20)
        if r.status_code == 200:
            return r.content
        return None
    except Exception as e:
        print(f"HF Bucket GET error ({remote_path}): {e}")
        return None

def hf_bucket_put(remote_path: str, data: bytes, content_type: str = "application/octet-stream") -> bool:
    if not HF_TOKEN or HF_TOKEN.startswith("hf_YOUR"):
        return False
    url = f"{HF_BUCKET_URL}/{remote_path.lstrip('/')}"
    headers = {**HF_HEADERS, "Content-Type": content_type}
    try:
        r = requests.put(url, headers=headers, data=data, timeout=60)
        if r.status_code in [200, 201, 204]:
            return True
        print(f"HF Bucket PUT failed ({remote_path}): {r.status_code} - {r.text}")
        return False
    except Exception as e:
        print(f"HF Bucket PUT error ({remote_path}): {e}")
        return False

def hf_bucket_delete(remote_path: str) -> bool:
    if not HF_TOKEN or HF_TOKEN.startswith("hf_YOUR"):
        return False
    url = f"{HF_BUCKET_URL}/{remote_path.lstrip('/')}"
    try:
        r = requests.delete(url, headers=HF_HEADERS, timeout=20)
        return r.status_code in [200, 204]
    except Exception as e:
        print(f"HF Bucket DELETE error ({remote_path}): {e}")
        return False

def check_nsfw_image_bytes(data: bytes, ext: str) -> bool:
    if ext.lower() not in [".jpg", ".jpeg", ".png", ".webp", ".bmp", ".jfif"]:
        return False
    try:
        import io
        from PIL import Image
        import opennsfw2 as n2
        img = Image.open(io.BytesIO(data))
        prob = n2.predict_image(img)
        return prob >= 0.75
    except Exception:
        return False

DATABASE: Dict[str, Any] = {}
RULES: Dict[str, Any] = {"rules": {".read": True, ".write": True}}
METRICS: Dict[str, Any] = {"days": {}, "hours": {}}
BACKUPS_METADATA: List[Dict[str, Any]] = []

db_lock = asyncio.Lock()
subscriptions: Dict[str, List[WebSocket]] = {}
active_websockets = set()
STORAGE_BYTES = 0
METRICS_DIRTY = False
PUSH_CHARS = "-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz"

def generate_push_id() -> str:
    now_ms = int(time.time() * 1000)
    time_chars = [""] * 8
    for i in range(7, -1, -1):
        time_chars[i] = PUSH_CHARS[now_ms % 64]
        now_ms //= 64
    rand_chars = "".join(random.choices(PUSH_CHARS, k=12))
    return "".join(time_chars) + rand_chars

class RuleDataSnapshot:
    def __init__(self, value: Any):
        self._value = value
    def val(self) -> Any:
        return self._value
    def exists(self) -> bool:
        return self._value is not None
    def child(self, path: str) -> "RuleDataSnapshot":
        if not isinstance(self._value, dict):
            return RuleDataSnapshot(None)
        parts = [p for p in str(path).strip("/").split("/") if p]
        curr = self._value
        for p in parts:
            if isinstance(curr, dict) and p in curr:
                curr = curr[p]
            else:
                return RuleDataSnapshot(None)
        return RuleDataSnapshot(curr)

class SafeRuleEval(EvalWithCompoundTypes):
    ALLOWED_METHODS = {"val", "child", "exists"}
    def _eval_call(self, node):
        if isinstance(node.func, ast.Attribute):
            if node.func.attr in self.ALLOWED_METHODS:
                obj = self._eval(node.func.value)
                if isinstance(obj, RuleDataSnapshot):
                    method = getattr(obj, node.func.attr)
                    args = [self._eval(a) for a in node.args]
                    return method(*args)
            raise FeatureNotAvailable(f"Method '{node.func.attr}' is not permitted")
        return super()._eval_call(node)

def load_rules():
    global RULES
    raw = hf_bucket_get("rules.json")
    if raw:
        try:
            RULES = json.loads(raw.decode("utf-8"))
            return
        except Exception:
            pass
    RULES = {"rules": {".read": True, ".write": True}}
    hf_bucket_put("rules.json", json.dumps(RULES, indent=2).encode("utf-8"), "application/json")

def load_database():
    global DATABASE, STORAGE_BYTES
    raw = hf_bucket_get("db.json")
    if raw:
        try:
            text = raw.decode("utf-8")
            DATABASE.clear()
            DATABASE.update(json.loads(text))
            STORAGE_BYTES = len(raw)
            return
        except Exception:
            pass
    DATABASE.clear()
    STORAGE_BYTES = 0

def load_metrics():
    global METRICS
    raw = hf_bucket_get("metrics.json")
    if raw:
        try:
            METRICS = json.loads(raw.decode("utf-8"))
            return
        except Exception:
            pass
    METRICS = {"days": {}, "hours": {}}

def load_backups_metadata():
    global BACKUPS_METADATA
    raw = hf_bucket_get("backups_index.json")
    if raw:
        try:
            BACKUPS_METADATA = json.loads(raw.decode("utf-8"))
            return
        except Exception:
            pass
    BACKUPS_METADATA = []

def save_db_now():
    global STORAGE_BYTES
    raw = json.dumps(DATABASE, indent=2).encode("utf-8")
    hf_bucket_put("db.json", raw, "application/json")
    STORAGE_BYTES = len(raw)

def sync_metrics(bandwidth_bytes: int = 0):
    global METRICS_DIRTY
    now = datetime.now(timezone.utc)
    day_str = now.strftime("%Y-%m-%d")
    hour_str = now.strftime("%Y-%m-%d %H:00")
    METRICS.setdefault("days", {})
    METRICS.setdefault("hours", {})
    curr_conns = len(active_websockets)

    for container, key in [(METRICS["days"], day_str), (METRICS["hours"], hour_str)]:
        if key not in container:
            container[key] = {"bandwidth": 0, "peak_connections": curr_conns, "storage": STORAGE_BYTES}
        if bandwidth_bytes > 0:
            container[key]["bandwidth"] += bandwidth_bytes
        container[key]["storage"] = STORAGE_BYTES
        if curr_conns > container[key].get("peak_connections", 0):
            container[key]["peak_connections"] = curr_conns

    METRICS_DIRTY = True

async def metrics_flusher():
    global METRICS_DIRTY
    while True:
        await asyncio.sleep(60)
        if METRICS_DIRTY:
            await asyncio.to_thread(
                hf_bucket_put,
                "metrics.json",
                json.dumps(METRICS).encode("utf-8"),
                "application/json",
            )
            METRICS_DIRTY = False

@asynccontextmanager
async def lifespan(app: FastAPI):
    await asyncio.to_thread(load_rules)
    await asyncio.to_thread(load_database)
    await asyncio.to_thread(load_metrics)
    await asyncio.to_thread(load_backups_metadata)
    flusher_task = asyncio.create_task(metrics_flusher())
    try:
        yield
    finally:
        flusher_task.cancel()
        if METRICS_DIRTY:
            hf_bucket_put("metrics.json", json.dumps(METRICS).encode("utf-8"), "application/json")
        save_db_now()

app = FastAPI(title="Realtime Database", lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

def strip_json_suffix(path: str) -> str:
    return path[:-5] if path.endswith(".json") else path

def get_nested(data: dict, path_parts: List[str]) -> Any:
    curr = data
    for p in path_parts:
        if not isinstance(curr, dict) or p not in curr:
            return None
        curr = curr[p]
    return curr

def set_nested(data: dict, path_parts: List[str], value: Any):
    curr = data
    for p in path_parts[:-1]:
        if p not in curr or not isinstance(curr[p], dict):
            curr[p] = {}
        curr = curr[p]
    curr[path_parts[-1]] = value

def delete_nested(data: dict, path_parts: List[str]) -> bool:
    curr = data
    for p in path_parts[:-1]:
        if not isinstance(curr, dict) or p not in curr:
            return False
        curr = curr[p]
    if isinstance(curr, dict) and path_parts[-1] in curr:
        del curr[path_parts[-1]]
        return True
    return False

def check_permission(path_parts: List[str], perm_type: str = ".read") -> bool:
    if not RULES or "rules" not in RULES:
        return True
    return bool(RULES.get("rules", {}).get(perm_type, True))

async def broadcast(path: str, value: Any, event_type: str = "put"):
    clean_target = path.strip("/")
    raw_payload = json.dumps({"path": clean_target, "event": event_type, "data": value})
    payload_bytes = len(raw_payload.encode("utf-8"))
    for sub_path, sockets in list(subscriptions.items()):
        if clean_target == sub_path or clean_target.startswith(sub_path + "/") or sub_path == "":
            for ws in list(sockets):
                try:
                    await ws.send_text(raw_payload)
                except Exception:
                    sockets.remove(ws)
                    active_websockets.discard(ws)
    sync_metrics(bandwidth_bytes=payload_bytes)

async def extract_request_payload(request: Request) -> Any:
    try:
        return await request.json()
    except Exception:
        raw = await request.body()
        text = raw.decode("utf-8", errors="ignore").strip()
        if not text:
            return None
        try:
            return json.loads(text)
        except Exception:
            if text.lower() == "true":
                return True
            if text.lower() == "false":
                return False
            if text.lower() == "null":
                return None
            try:
                if "." in text:
                    return float(text)
                return int(text)
            except ValueError:
                return text

HTML_CONSOLE = """
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realtime Database</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #ffffff; color: #202124; }
        .mono { font-family: 'Roboto Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .node-row:hover .actions { display: inline-flex; }
        .actions { display: none; }
        .node-row { font-family: 'Roboto Mono', ui-monospace, monospace; font-size: 13px; color: #3c4043; }
        .node-toggle { display: inline-block; width: 14px; text-align: center; color: #80868b; font-size: 11px; transition: transform .15s ease; flex-shrink: 0; }
        .node-toggle.open { transform: rotate(90deg); }
        .node-dash { color: #9aa0a6; margin: 0 6px; flex-shrink: 0; }
        .tree-line { border-left: 1px dotted #cfd2d6; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden text-slate-800">
    <header class="bg-white border-b border-gray-200 px-6 pt-3.5 pb-0 flex flex-col gap-2 shadow-xs">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-normal tracking-tight text-gray-800">Realtime Database</h1>
        </div>
        <div class="flex gap-8 text-sm font-medium mt-2">
            <button id="tab-data-btn" onclick="switchTab('data')" class="pb-2 border-b-2 border-blue-600 text-blue-600">Data</button>
            <button id="tab-rules-btn" onclick="switchTab('rules')" class="pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-700">Rules</button>
            <button id="tab-backups-btn" onclick="switchTab('backups')" class="pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-700">Backups</button>
            <button id="tab-usage-btn" onclick="switchTab('usage')" class="pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-700">Usage</button>
        </div>
    </header>

    <div id="data-toolbar" class="bg-gray-50 border-b border-gray-200 px-6 py-2.5 flex items-center justify-between gap-3 text-xs">
        <div class="flex items-center gap-2 bg-white border border-gray-300 rounded-md px-3 py-1.5 shadow-xs text-gray-700 mono flex-1 min-w-0 max-w-2xl">
            <div class="overflow-x-auto whitespace-nowrap flex-1 min-w-0 no-scrollbar">
                <span id="base-endpoint" class="font-medium text-gray-800 select-all">https://.../</span>
            </div>
            <button id="copy-btn" onclick="copyUrl()" title="Copy URL" class="ml-2 text-gray-400 hover:text-gray-700 shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            </button>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button onclick="openModal('add', '')" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-3 py-1.5 rounded-md flex items-center gap-1.5 shadow-xs transition">
                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                <span>Add node</span>
            </button>
            <div class="relative">
                <button onclick="toggleMenu()" class="w-8 h-8 rounded-full hover:bg-gray-200 flex items-center justify-center text-gray-600">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                </button>
                <div id="dropdown-menu" class="hidden absolute right-0 mt-1 w-44 bg-white border border-gray-200 rounded-lg shadow-lg py-1 z-30 text-xs text-gray-700">
                    <button onclick="exportJSON(); toggleMenu();" class="w-full text-left px-4 py-2 hover:bg-gray-100 flex items-center gap-2">Export JSON</button>
                    <button onclick="document.getElementById('import-input').click(); toggleMenu();" class="w-full text-left px-4 py-2 hover:bg-gray-100 flex items-center gap-2">Import JSON</button>
                    <div class="border-t my-1"></div>
                    <button onclick="clearDatabase(); toggleMenu();" class="w-full text-left px-4 py-2 hover:bg-red-50 text-red-600 flex items-center gap-2">Clear database</button>
                </div>
            </div>
            <input type="file" id="import-input" accept=".json" class="hidden" onchange="importJSON(event)">
        </div>
    </div>

    <main class="flex-1 overflow-auto p-6 bg-white relative">
        <div id="tab-data" class="min-h-full">
            <div id="tree-root" class="mono text-xs space-y-1"></div>
        </div>

        <div id="tab-rules" class="hidden min-h-full flex flex-col">
            <div class="flex justify-between items-center mb-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-800">Security Rules (Hugging Face Bucket)</h2>
                    <p class="text-xs text-gray-500">Live rules stored directly in your bucket.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="loadRules()" class="bg-gray-100 hover:bg-gray-200 border border-gray-300 text-gray-700 px-3 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5">
                        Discard Changes
                    </button>
                    <button onclick="publishRules()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5 shadow-xs">
                        Publish Rules
                    </button>
                </div>
            </div>
            <textarea id="rules-editor" class="w-full flex-1 min-h-[500px] bg-gray-50 text-gray-800 font-mono text-xs p-4 rounded-md border border-gray-300 focus:outline-blue-500" spellcheck="false"></textarea>
        </div>

        <div id="tab-backups" class="hidden min-h-full space-y-4">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-sm font-semibold text-gray-800">Hugging Face Bucket Snapshots</h2>
                    <p class="text-xs text-gray-500">Snapshots saved in your cloud bucket.</p>
                </div>
                <button onclick="createBackup()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5 shadow-xs">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>Create</span>
                </button>
            </div>
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-medium">
                        <tr>
                            <th class="px-4 py-2.5">Backup File</th>
                            <th class="px-4 py-2.5">Created Date</th>
                            <th class="px-4 py-2.5">Size</th>
                            <th class="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="backups-list" class="divide-y divide-gray-200 text-gray-700"></tbody>
                </table>
            </div>
        </div>

        <div id="tab-usage" class="hidden min-h-full max-w-lg space-y-3">
            <div class="relative inline-block text-left">
                <button onclick="togglePeriodDropdown()" class="flex items-center gap-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded-md shadow-xs">
                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span id="selected-period-label">Daily (Last 24 hours)</span>
                    <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="period-dropdown" class="hidden absolute left-0 mt-1 w-52 bg-white border border-gray-200 rounded-lg shadow-lg py-1 z-30 text-xs text-gray-700">
                    <button onclick="selectPeriod('daily', 'Daily (Last 24 hours)')" class="w-full text-left px-4 py-2 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-between">
                        <span>Daily (Last 24 hours)</span>
                    </button>
                    <button onclick="selectPeriod('weekly', 'Weekly (Last 7 days)')" class="w-full text-left px-4 py-2 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-between">
                        <span>Weekly (Last 7 days)</span>
                    </button>
                    <button onclick="selectPeriod('monthly', 'Monthly (Last 30 days)')" class="w-full text-left px-4 py-2 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-between">
                        <span>Monthly (Last 30 days)</span>
                    </button>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-xs">
                <div id="row-conn" onclick="selectMetric('connections')" class="p-4 cursor-pointer hover:bg-gray-50 transition border-l-4 border-transparent border-b border-gray-100">
                    <div class="text-xs font-normal text-gray-600 flex items-center gap-1.5">
                        <span>Connections</span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1">
                        <span id="metric-connections" class="text-2xl font-normal text-gray-900">0</span>
                        <span class="text-xs text-gray-400">/ 100</span>
                    </div>
                </div>
                <div id="row-storage" onclick="selectMetric('storage')" class="p-4 cursor-pointer hover:bg-gray-50 transition border-l-4 border-transparent border-b border-gray-100">
                    <div class="text-xs font-normal text-gray-600 flex items-center gap-1.5">
                        <span>Storage</span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span id="metric-storage" class="text-2xl font-normal text-gray-900">0 KB</span>
                        <span class="text-xs text-gray-400">current</span>
                    </div>
                </div>
                <div id="row-downloads" onclick="selectMetric('downloads')" class="p-4 cursor-pointer hover:bg-gray-50 transition border-l-4 border-blue-600 bg-blue-50/40">
                    <div class="text-xs font-normal text-blue-600 flex items-center gap-1.5">
                        <span>Downloads</span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span id="metric-bandwidth" class="text-2xl font-normal text-blue-600">0 KB</span>
                        <span class="text-xs text-gray-400">total</span>
                    </div>
                </div>
                <div class="p-5 pt-3 border-t border-gray-100 bg-white">
                    <div class="flex justify-between items-center text-[10px] text-gray-400 mb-1">
                        <span id="chart-top-label">100</span>
                    </div>
                    <div class="w-full border-b border-dashed border-gray-200"></div>
                    <div class="flex justify-between items-center text-[10px] text-gray-400 my-1">
                        <span id="chart-mid-label">50</span>
                    </div>
                    <div class="w-full border-b border-gray-100"></div>
                    <div class="h-32 relative mt-1">
                        <svg id="usage-chart-svg" class="w-full h-full overflow-visible" viewBox="0 0 260 90">
                            <polyline id="chart-line" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points=""/>
                            <circle id="chart-point" cx="0" cy="0" r="3.5" fill="#2563eb" class="hidden"/>
                        </svg>
                    </div>
                    <div class="flex justify-between text-[11px] text-gray-400 mt-2 border-t border-gray-200 pt-1.5">
                        <span id="chart-date-start">-</span>
                        <span id="chart-date-end">-</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="node-modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 border border-gray-200 text-gray-800">
            <h3 id="modal-heading" class="text-sm font-semibold mb-3 text-gray-900">Add Child Node</h3>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block text-gray-600 font-medium mb-1">Path</label>
                    <input id="input-path" type="text" readonly class="w-full bg-gray-100 border border-gray-300 rounded px-3 py-2 font-mono text-gray-600 text-xs">
                </div>
                <div id="field-key-container">
                    <label class="block text-gray-600 font-medium mb-1">Key</label>
                    <input id="input-key" type="text" placeholder="Key (e.g. name, user_id, profile)" class="w-full bg-white border border-gray-300 rounded px-3 py-2 font-mono text-gray-800 text-xs focus:outline-blue-500">
                </div>
                <div>
                    <label class="block text-gray-600 font-medium mb-1">Value</label>
                    <textarea id="input-val" rows="4" placeholder='Value (e.g. "Hello", 123, true, or {"title": "Hello"})' class="w-full bg-white border border-gray-300 rounded px-3 py-2 font-mono text-gray-800 text-xs focus:outline-blue-500"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-5 text-xs font-medium">
                <button onclick="closeModal()" class="px-4 py-2 border border-gray-300 rounded hover:bg-gray-100 text-gray-700">Cancel</button>
                <button onclick="submitModal()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">Save</button>
            </div>
        </div>
    </div>

    <script>
        let db = {};
        let ws = null;
        let modalMode = 'add';
        let usageData = null;
        let activeMetric = 'downloads';
        let currentPeriod = 'daily';
        let expandedPaths = new Set();
        document.getElementById('base-endpoint').innerText = window.location.origin + '/';
        const SVG_PLUS = `<svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>`;
        const SVG_EDIT = `<svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>`;
        const SVG_TRASH = `<svg class="w-3 h-3 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>`;

        function toggleMenu() { document.getElementById('dropdown-menu').classList.toggle('hidden'); }
        function togglePeriodDropdown() { document.getElementById('period-dropdown').classList.toggle('hidden'); }
        window.onclick = function(e) {
            if (!e.target.closest('#dropdown-menu') && !e.target.closest('button[onclick*="toggleMenu"]')) {
                const m = document.getElementById('dropdown-menu');
                if (m) m.classList.add('hidden');
            }
            if (!e.target.closest('#period-dropdown') && !e.target.closest('button[onclick*="togglePeriodDropdown"]')) {
                const pd = document.getElementById('period-dropdown');
                if (pd) pd.classList.add('hidden');
            }
        };

        function selectPeriod(period, label) {
            currentPeriod = period;
            document.getElementById('selected-period-label').innerText = label;
            document.getElementById('period-dropdown').classList.add('hidden');
            updatePeriodView();
        }

        function copyUrl() {
            navigator.clipboard.writeText(window.location.origin + '/');
            const btn = document.getElementById('copy-btn');
            if (btn) {
                btn.innerHTML = `<svg class="w-3.5 h-3.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
                setTimeout(() => {
                    btn.innerHTML = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>`;
                }, 1500);
            }
        }

        function switchTab(tab) {
            ['data', 'rules', 'backups', 'usage'].forEach(t => {
                document.getElementById(`tab-${t}`).classList.add('hidden');
                document.getElementById(`tab-${t}-btn`).className = 'pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-700';
            });
            document.getElementById(`tab-${tab}`).classList.remove('hidden');
            document.getElementById(`tab-${tab}-btn`).className = 'pb-2 border-b-2 border-blue-600 text-blue-600 font-medium';
            const toolbar = document.getElementById('data-toolbar');
            if (tab === 'data') toolbar.classList.remove('hidden');
            else toolbar.classList.add('hidden');
            if (tab === 'rules') loadRules();
            if (tab === 'backups') loadBackups();
            if (tab === 'usage') loadUsage();
        }

        async function loadRules() {
            try {
                const r = await fetch('/_admin/rules');
                const rules = await r.json();
                document.getElementById('rules-editor').value = JSON.stringify(rules, null, 2);
            } catch (e) {}
        }

        async function publishRules() {
            try {
                const text = document.getElementById('rules-editor').value;
                const parsed = JSON.parse(text);
                const res = await fetch('/_admin/rules', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(parsed) });
                if (res.ok) alert('Rules saved to Hugging Face Bucket successfully!');
                else alert('Failed to publish rules');
            } catch (e) { alert('Invalid JSON syntax: ' + e.message); }
        }

        async function loadBackups() {
            const res = await fetch('/_admin/backups');
            const list = await res.json();
            const tbody = document.getElementById('backups-list');
            tbody.innerHTML = '';
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center text-gray-400 italic">No backups found.</td></tr>';
                return;
            }
            list.forEach(b => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="px-4 py-2.5 font-mono text-gray-900">${b.filename}</td>
                    <td class="px-4 py-2.5 text-gray-500">${b.date}</td>
                    <td class="px-4 py-2.5 text-gray-500">${(b.size / 1024).toFixed(2)} KB</td>
                    <td class="px-4 py-2.5 text-right space-x-2">
                        <button onclick="restoreBackup('${b.filename}')" class="text-blue-600 hover:underline">Restore</button>
                        <a href="/_admin/backups/download/${b.filename}" class="text-gray-600 hover:underline">Download</a>
                        <button onclick="deleteBackup('${b.filename}')" class="text-red-600 hover:underline">Delete</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function createBackup() {
            const res = await fetch('/_admin/backups', { method: 'POST' });
            if (res.ok) { loadBackups(); }
        }

        async function restoreBackup(filename) {
            if (confirm(`Restore ${filename}? This will overwrite current database.`)) {
                const res = await fetch(`/_admin/backups/restore/${filename}`, { method: 'POST' });
                if (res.ok) { refreshData(); }
            }
        }

        async function deleteBackup(filename) {
            if (confirm(`Delete backup ${filename}?`)) {
                await fetch(`/_admin/backups/${filename}`, { method: 'DELETE' });
                loadBackups();
            }
        }

        async function loadUsage() {
            try {
                const res = await fetch('/api/usage');
                usageData = await res.json();
                updatePeriodView();
            } catch (e) {}
        }

        function updatePeriodView() {
            if (!usageData) return;
            const periodData = usageData.periods[currentPeriod];
            document.getElementById('metric-connections').innerText = usageData.connections;
            document.getElementById('metric-storage').innerText = usageData.storage_formatted;
            document.getElementById('metric-bandwidth').innerText = periodData.bandwidth_formatted;
            renderMetricChart();
        }

        function selectMetric(metric) {
            activeMetric = metric;
            const rows = { connections: document.getElementById('row-conn'), storage: document.getElementById('row-storage'), downloads: document.getElementById('row-downloads') };
            Object.keys(rows).forEach(k => {
                rows[k].className = 'p-4 cursor-pointer hover:bg-gray-50 transition border-l-4 border-transparent border-b border-gray-100';
                const title = rows[k].querySelector('div:first-child');
                title.className = 'text-xs font-normal text-gray-600 flex items-center gap-1.5';
                const val = rows[k].querySelector('span:first-child');
                val.className = 'text-2xl font-normal text-gray-900';
            });
            rows[metric].className = 'p-4 cursor-pointer transition border-l-4 border-blue-600 bg-blue-50/40 border-b border-gray-100';
            const activeTitle = rows[metric].querySelector('div:first-child');
            activeTitle.className = 'text-xs font-normal text-blue-600 flex items-center gap-1.5';
            const activeVal = rows[metric].querySelector('span:first-child');
            activeVal.className = 'text-2xl font-normal text-blue-600';
            renderMetricChart();
        }

        function formatBytes(bytes) {
            if (bytes >= 1024 * 1024 * 1024) return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
            if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
            if (bytes >= 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return bytes + ' B';
        }

        function renderMetricChart() {
            if (!usageData) return;
            const history = (usageData.periods[currentPeriod] && usageData.periods[currentPeriod].history) || [];
            if (history.length === 0) return;
            document.getElementById('chart-date-start').innerText = history[0].date;
            document.getElementById('chart-date-end').innerText = history[history.length - 1].date;
            let values = [];
            if (activeMetric === 'connections') values = history.map(h => h.connections);
            else if (activeMetric === 'storage') values = history.map(h => h.storage);
            else if (activeMetric === 'downloads') values = history.map(h => h.bandwidth);
            let maxVal = Math.max(...values, 1);
            let topLabel = ''; let midLabel = '';
            if (activeMetric === 'connections') {
                maxVal = Math.max(maxVal, 100);
                topLabel = maxVal.toString();
                midLabel = Math.round(maxVal / 2).toString();
            } else {
                topLabel = formatBytes(maxVal);
                midLabel = formatBytes(maxVal / 2);
            }
            document.getElementById('chart-top-label').innerText = topLabel;
            document.getElementById('chart-mid-label').innerText = midLabel;
            let pts = [];
            values.forEach((v, idx) => {
                let x = (idx / (values.length - 1 || 1)) * 248 + 6;
                let y = 82 - (Math.min(v, maxVal) / maxVal) * 72;
                pts.push(`${x.toFixed(1)},${y.toFixed(1)}`);
            });
            document.getElementById('chart-line').setAttribute('points', pts.join(' '));
            const last = pts[pts.length - 1].split(',');
            const pt = document.getElementById('chart-point');
            pt.setAttribute('cx', last[0]); pt.setAttribute('cy', last[1]);
            pt.classList.remove('hidden');
        }

        function initWebSocket() {
            const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            ws = new WebSocket(`${proto}//${window.location.host}/ws/`);
            ws.onmessage = (event) => {
                const msg = JSON.parse(event.data);
                if (msg.event === 'connected') {
                    if (msg.data && Object.keys(msg.data).length > 0) { db = msg.data; renderTree(); }
                    else { refreshData(); }
                } else { refreshData(); }
            };
            ws.onclose = () => { setTimeout(initWebSocket, 2500); };
        }

        async function refreshData() {
            try {
                const r = await fetch('/api-data');
                if (r.ok) {
                    const data = await r.json();
                    db = data || {};
                    renderTree();
                }
            } catch (e) {}
        }

        function renderTree() {
            const root = document.getElementById('tree-root');
            root.innerHTML = '';
            if (!db || Object.keys(db).length === 0) {
                root.innerHTML = '<div class="text-gray-400 italic py-6 text-center text-xs">Database is empty. Click "+ Add node" to begin.</div>';
                return;
            }
            Object.keys(db).forEach(k => { root.appendChild(createBranch(k, k, db[k])); });
        }

        function createBranch(path, key, val) {
            const isObj = typeof val === 'object' && val !== null && !Array.isArray(val);
            const isArr = Array.isArray(val);
            const isContainer = isObj || isArr;
            const container = document.createElement('div');
            container.className = 'my-0.5';
            const row = document.createElement('div');
            row.className = 'node-row group flex items-center py-1 px-2 rounded hover:bg-gray-100 transition cursor-pointer';
            const toggleWrap = document.createElement('span');
            toggleWrap.className = 'node-toggle';
            toggleWrap.innerText = isContainer ? '▸' : '';
            row.appendChild(toggleWrap);
            if (isContainer) {
                const dash = document.createElement('span');
                dash.className = 'node-dash';
                dash.innerText = '—';
                row.appendChild(dash);
            }
            if (key !== null) {
                const keyEl = document.createElement('span');
                keyEl.className = 'text-gray-800 mr-1.5';
                keyEl.innerText = key;
                row.appendChild(keyEl);
                if (!isContainer) {
                    const colon = document.createElement('span');
                    colon.className = 'text-gray-400 mr-2';
                    colon.innerText = ':';
                    row.appendChild(colon);
                }
            }
            if (!isContainer) {
                const valEl = document.createElement('span');
                if (typeof val === 'string') { valEl.className = 'text-green-700'; valEl.innerText = `"${val}"`; }
                else if (typeof val === 'number') { valEl.className = 'text-blue-600'; valEl.innerText = val; }
                else if (typeof val === 'boolean') { valEl.className = 'text-purple-600'; valEl.innerText = val; }
                else { valEl.className = 'text-gray-400'; valEl.innerText = 'null'; }
                row.appendChild(valEl);
            }
            const actions = document.createElement('div');
            actions.className = 'actions ml-4 items-center gap-1.5';
            if (isContainer) {
                const addBtn = document.createElement('button');
                addBtn.className = 'w-5 h-5 rounded hover:bg-200 text-gray-600 flex items-center justify-center';
                addBtn.innerHTML = SVG_PLUS; addBtn.title = 'Add child node';
                addBtn.onclick = (e) => { e.stopPropagation(); openModal('add', path); };
                actions.appendChild(addBtn);
            } else {
                const editBtn = document.createElement('button');
                editBtn.className = 'w-5 h-5 rounded hover:bg-gray-200 text-gray-600 flex items-center justify-center';
                editBtn.innerHTML = SVG_EDIT; editBtn.title = 'Edit node';
                editBtn.onclick = (e) => { e.stopPropagation(); openModal('edit', path, val); };
                actions.appendChild(editBtn);
            }
            if (path !== '') {
                const delBtn = document.createElement('button');
                delBtn.className = 'w-5 h-5 rounded hover:bg-red-100 flex items-center justify-center';
                delBtn.innerHTML = SVG_TRASH; delBtn.title = 'Delete node';
                delBtn.onclick = (e) => { e.stopPropagation(); deleteNode(path); };
                actions.appendChild(delBtn);
            }
            row.appendChild(actions);
            container.appendChild(row);
            if (isContainer) {
                const childrenBox = document.createElement('div');
                childrenBox.className = 'pl-5 tree-line ml-2 space-y-0.5';
                const isOpen = expandedPaths.has(path);
                childrenBox.style.display = isOpen ? 'block' : 'none';
                toggleWrap.classList.toggle('open', isOpen);
                const keys = isObj ? Object.keys(val) : val.map((_, i) => i);
                keys.forEach(k => {
                    const subPath = path ? `${path}/${k}` : `${k}`;
                    childrenBox.appendChild(createBranch(subPath, k, val[k]));
                });
                container.appendChild(childrenBox);
                row.onclick = () => {
                    const willOpen = childrenBox.style.display === 'none';
                    childrenBox.style.display = willOpen ? 'block' : 'none';
                    toggleWrap.classList.toggle('open', willOpen);
                    if (willOpen) expandedPaths.add(path); else expandedPaths.delete(path);
                };
            }
            return container;
        }

        function openModal(mode, path, currentVal = '') {
            modalMode = mode;
            document.getElementById('input-path').value = path ? `/${path}` : '/';
            const keyGroup = document.getElementById('field-key-container');
            if (mode === 'add') {
                document.getElementById('modal-heading').innerText = 'Add Child Node';
                keyGroup.style.display = 'block';
                document.getElementById('input-key').value = '';
                document.getElementById('input-val').value = '';
            } else {
                document.getElementById('modal-heading').innerText = 'Edit Value';
                keyGroup.style.display = 'none';
                document.getElementById('input-val').value = typeof currentVal === 'string' ? currentVal : JSON.stringify(currentVal);
            }
            document.getElementById('node-modal').classList.remove('hidden');
            document.getElementById('node-modal').classList.add('flex');
        }

        function closeModal() {
            document.getElementById('node-modal').classList.add('hidden');
            document.getElementById('node-modal').classList.remove('flex');
        }

        async function submitModal() {
            const rawPath = document.getElementById('input-path').value.replace(/^\//, '');
            const rawVal = document.getElementById('input-val').value.trim();
            let parsed = rawVal;
            try { parsed = JSON.parse(rawVal); } catch (e) {
                if (!isNaN(rawVal) && rawVal !== '') parsed = Number(rawVal);
                else if (rawVal.toLowerCase() === 'true') parsed = true;
                else if (rawVal.toLowerCase() === 'false') parsed = false;
                else parsed = rawVal;
            }
            let target = rawPath;
            if (modalMode === 'add') {
                const key = document.getElementById('input-key').value.trim();
                if (!key) return alert('Key name is required');
                target = rawPath ? `${rawPath}/${key}` : key;
            }
            if (modalMode === 'add' && rawPath) expandedPaths.add(rawPath);
            const targetPath = target ? `/${target}.json` : '/.json';
            const res = await fetch(targetPath, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(parsed) });
            if (!res.ok) { const err = await res.json(); alert('Action Denied: ' + (err.detail || 'Error')); }
            else { closeModal(); }
        }

        async function deleteNode(path) {
            if (confirm(`Delete node "/${path}"?`)) {
                expandedPaths.delete(path);
                await fetch(`/${path}.json`, { method: 'DELETE' });
            }
        }

        function exportJSON() {
            const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(db, null, 2));
            const a = document.createElement('a'); a.href = dataStr; a.download = "database_export.json";
            document.body.appendChild(a); a.click(); a.remove();
        }

        function importJSON(event) {
            const file = event.target.files[0]; if (!file) return;
            const reader = new FileReader();
            reader.onload = async function(e) {
                try {
                    const parsed = JSON.parse(e.target.result);
                    if (confirm('Importing this JSON will overwrite the database. Continue?')) {
                        const res = await fetch('/.json', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(parsed) });
                        if (res.ok) { refreshData(); }
                    }
                } catch (err) { alert('Invalid JSON file: ' + err.message); }
            };
            reader.readAsText(file); event.target.value = '';
        }

        async function clearDatabase() {
            if (confirm('Clear entire database? This cannot be undone.')) { await fetch('/.json', { method: 'DELETE' }); }
        }
        refreshData(); initWebSocket();
    </script>
</body>
</html>
"""

@app.get("/", response_class=HTMLResponse)
async def serve_dashboard(request: Request):
    if "text/html" in request.headers.get("accept", ""):
        return HTML_CONSOLE
    return Response(content=json.dumps(DATABASE), media_type="application/json")

@app.get("/uploads/{filename:path}")
async def serve_upload_file(filename: str):
    clean_name = os.path.basename(filename)
    raw = await asyncio.to_thread(hf_bucket_get, f"uploads/{clean_name}")
    if raw:
        import mimetypes
        mime_type = mimetypes.guess_type(clean_name)[0] or "application/octet-stream"
        return Response(content=raw, media_type=mime_type)
    raise HTTPException(status_code=404, detail="File not found")

@app.delete("/uploads/{filename:path}")
async def delete_upload_file(filename: str):
    clean_name = os.path.basename(filename)
    await asyncio.to_thread(hf_bucket_delete, f"uploads/{clean_name}")

    def remove_db_refs(node):
        changed = False
        if isinstance(node, dict):
            keys_to_del = []
            for k, v in node.items():
                if isinstance(v, dict) and v.get("filename") == clean_name:
                    keys_to_del.append(k)
                else:
                    if remove_db_refs(v):
                        changed = True
            for k in keys_to_del:
                del node[k]
                changed = True
        elif isinstance(node, list):
            for item in node:
                if remove_db_refs(item):
                    changed = True
        return changed

    async with db_lock:
        db_changed = remove_db_refs(DATABASE)
        if db_changed:
            await asyncio.to_thread(save_db_now)
            await broadcast("", DATABASE, "put")

    return {"status": "deleted", "filename": clean_name}

@app.post("/api/upload")
async def upload_media_file(
    request: Request,
    file: UploadFile = File(...),
    target_path: str = Form("uploads"),
    is_public: str = Form("false"),
):
    push_id = generate_push_id()
    original_name = file.filename or "file"
    _, ext = os.path.splitext(original_name)
    saved_filename = f"{push_id}{ext}"
    content = await file.read()

    public_bool = is_public.lower() in ("true", "1", "yes")
    if public_bool and check_nsfw_image_bytes(content, ext):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Explicit content is not allowed."
        )

    remote_file_path = f"uploads/{saved_filename}"
    uploaded = await asyncio.to_thread(
        hf_bucket_put,
        remote_file_path,
        content,
        file.content_type or "application/octet-stream"
    )
    if not uploaded:
        raise HTTPException(status_code=500, detail="Failed to upload file to Hugging Face Bucket")

    base_url = PUBLIC_BASE_URL.rstrip("/") if PUBLIC_BASE_URL else str(request.base_url).rstrip("/")
    if base_url.startswith("http://") and ("hf.space" in base_url or "huggingface.co" in base_url):
        base_url = "https://" + base_url[7:]
    file_url = f"{base_url}/uploads/{saved_filename}"

    record = {
        "id": push_id,
        "name": original_name,
        "filename": saved_filename,
        "url": file_url,
        "content_type": file.content_type,
        "size": len(content),
        "is_public": public_bool,
        "timestamp": int(time.time() * 1000),
    }

    path_parts = [p for p in target_path.strip("/").split("/") if p] + [push_id]
    clean_path = "/".join(path_parts)

    async with db_lock:
        set_nested(DATABASE, path_parts, record)
        await asyncio.to_thread(save_db_now)
        await broadcast(clean_path, record, "put")

    return {"status": "success", "path": clean_path, "data": record}

@app.get("/api-data")
@app.get("/api-data/")
@app.get("/api-data/{full_path:path}")
async def get_raw_data(full_path: str = ""):
    path_parts = [p for p in full_path.strip("/").split("/") if p]
    if not path_parts:
        return DATABASE
    data = get_nested(DATABASE, path_parts)
    return data if data is not None else {}

@app.get("/_admin/rules")
async def get_rules():
    return RULES

@app.post("/_admin/rules")
async def update_rules(request: Request):
    global RULES
    new_rules = await extract_request_payload(request)
    if isinstance(new_rules, dict):
        RULES = new_rules
        await asyncio.to_thread(
            hf_bucket_put,
            "rules.json",
            json.dumps(RULES, indent=2).encode("utf-8"),
            "application/json",
        )
        return {"status": "success", "rules": RULES}
    raise HTTPException(status_code=400, detail="Invalid rules format")

@app.get("/_admin/backups")
async def list_backups():
    return BACKUPS_METADATA

@app.post("/_admin/backups")
async def make_backup():
    filename = f"backup_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}.json"
    raw = json.dumps(DATABASE, indent=2).encode("utf-8")
    await asyncio.to_thread(hf_bucket_put, f"backups/{filename}", raw, "application/json")

    backup_info = {
        "filename": filename,
        "date": datetime.now(timezone.utc).strftime("%b %d, %Y %I:%M %p"),
        "size": len(raw),
    }
    BACKUPS_METADATA.insert(0, backup_info)
    await asyncio.to_thread(
        hf_bucket_put,
        "backups_index.json",
        json.dumps(BACKUPS_METADATA).encode("utf-8"),
        "application/json",
    )
    return {"status": "created", "filename": filename}

@app.post("/_admin/backups/restore/{filename}")
async def restore_backup(filename: str):
    clean_name = os.path.basename(filename)
    raw = await asyncio.to_thread(hf_bucket_get, f"backups/{clean_name}")
    if not raw:
        raise HTTPException(status_code=404, detail="Backup file not found in bucket")
    data = json.loads(raw.decode("utf-8"))
    async with db_lock:
        DATABASE.clear()
        if isinstance(data, dict):
            DATABASE.update(data)
        await asyncio.to_thread(save_db_now)
        await broadcast("", DATABASE, "put")
    return {"status": "restored"}

@app.get("/_admin/backups/download/{filename}")
async def download_backup(filename: str):
    clean_name = os.path.basename(filename)
    raw = await asyncio.to_thread(hf_bucket_get, f"backups/{clean_name}")
    if not raw:
        raise HTTPException(status_code=404, detail="Backup file not found")
    return Response(content=raw, media_type="application/json")

@app.delete("/_admin/backups/{filename}")
async def delete_backup(filename: str):
    clean_name = os.path.basename(filename)
    await asyncio.to_thread(hf_bucket_delete, f"backups/{clean_name}")
    global BACKUPS_METADATA
    BACKUPS_METADATA = [b for b in BACKUPS_METADATA if b["filename"] != clean_name]
    await asyncio.to_thread(
        hf_bucket_put,
        "backups_index.json",
        json.dumps(BACKUPS_METADATA).encode("utf-8"),
        "application/json",
    )
    return {"status": "deleted"}

@app.get("/api/usage")
async def get_usage_metrics():
    storage_bytes = STORAGE_BYTES
    now = datetime.now(timezone.utc)
    def format_size(bytes_num):
        if bytes_num >= 1024 * 1024 * 1024:
            return f"{bytes_num / (1024 * 1024 * 1024):.2f} GB"
        if bytes_num >= 1024 * 1024:
            return f"{bytes_num / (1024 * 1024):.2f} MB"
        if bytes_num >= 1024:
            return f"{bytes_num / 1024:.2f} KB"
        return f"{bytes_num} B"

    daily_history = []
    daily_bandwidth = 0
    for i in range(23, -1, -1):
        dt = now - timedelta(hours=i)
        h_key = dt.strftime("%Y-%m-%d %H:00")
        h_label = dt.strftime("%I %p")
        rec = METRICS.get("hours", {}).get(h_key, {})
        b = rec.get("bandwidth", 0)
        c = rec.get("peak_connections", 0)
        s = rec.get("storage", storage_bytes)
        daily_bandwidth += b
        daily_history.append({"date": h_label, "bandwidth": b, "connections": c, "storage": s})

    weekly_history = []
    weekly_bandwidth = 0
    for i in range(6, -1, -1):
        dt = (now - timedelta(days=i)).date()
        d_key = dt.strftime("%Y-%m-%d")
        d_label = dt.strftime("%a")
        rec = METRICS.get("days", {}).get(d_key, {})
        b = rec.get("bandwidth", 0)
        c = rec.get("peak_connections", 0)
        s = rec.get("storage", storage_bytes)
        weekly_bandwidth += b
        weekly_history.append({"date": d_label, "bandwidth": b, "connections": c, "storage": s})

    monthly_history = []
    monthly_bandwidth = 0
    for i in range(29, -1, -1):
        dt = (now - timedelta(days=i)).date()
        d_key = dt.strftime("%Y-%m-%d")
        d_label = dt.strftime("%b %d")
        rec = METRICS.get("days", {}).get(d_key, {})
        b = rec.get("bandwidth", 0)
        c = rec.get("peak_connections", 0)
        s = rec.get("storage", storage_bytes)
        monthly_bandwidth += b
        monthly_history.append({"date": d_label, "bandwidth": b, "connections": c, "storage": s})

    return {
        "connections": len(active_websockets),
        "storage_bytes": storage_bytes,
        "storage_formatted": format_size(storage_bytes),
        "periods": {
            "daily": {
                "bandwidth_bytes": daily_bandwidth,
                "bandwidth_formatted": format_size(daily_bandwidth),
                "history": daily_history,
            },
            "weekly": {
                "bandwidth_bytes": weekly_bandwidth,
                "bandwidth_formatted": format_size(weekly_bandwidth),
                "history": weekly_history,
            },
            "monthly": {
                "bandwidth_bytes": monthly_bandwidth,
                "bandwidth_formatted": format_size(monthly_bandwidth),
                "history": monthly_history,
            },
        },
    }

@app.get("/{full_path:path}")
async def read_endpoint(request: Request, full_path: str, shallow: bool = False):
    full_path = strip_json_suffix(full_path)
    parts = [p for p in full_path.strip("/").split("/") if p]
    if not check_permission(parts, ".read"):
        raise HTTPException(status_code=403, detail="Permission denied")
    data = DATABASE if not parts else get_nested(DATABASE, parts)
    if data is None:
        return None
    if shallow and isinstance(data, dict):
        return {k: True for k in data.keys()}
    return data

@app.put("/{full_path:path}")
@app.put("/")
async def write_endpoint(request: Request, full_path: str = ""):
    payload = await extract_request_payload(request)
    full_path = strip_json_suffix(full_path)
    parts = [p for p in full_path.strip("/").split("/") if p]
    if not check_permission(parts, ".write"):
        raise HTTPException(status_code=403, detail="Permission denied")
    clean_path = "/".join(parts)
    async with db_lock:
        if not parts:
            DATABASE.clear()
            if isinstance(payload, dict):
                DATABASE.update(payload)
            else:
                DATABASE["data"] = payload
        else:
            set_nested(DATABASE, parts, payload)
        await asyncio.to_thread(save_db_now)
        await broadcast(clean_path, payload, "put")
    return payload

@app.patch("/{full_path:path}")
@app.patch("/")
async def patch_endpoint(request: Request, full_path: str = ""):
    payload = await extract_request_payload(request)
    if not isinstance(payload, dict):
        raise HTTPException(status_code=400, detail="Patch payload must be an object")
    full_path = strip_json_suffix(full_path)
    parts = [p for p in full_path.strip("/").split("/") if p]
    if not check_permission(parts, ".write"):
        raise HTTPException(status_code=403, detail="Permission denied")
    clean_path = "/".join(parts)
    async with db_lock:
        if not parts:
            DATABASE.update(payload)
        else:
            target = get_nested(DATABASE, parts)
            if not isinstance(target, dict):
                target = {}
                set_nested(DATABASE, parts, target)
            target.update(payload)
        await asyncio.to_thread(save_db_now)
        await broadcast(clean_path, payload, "patch")
    return payload

@app.post("/{full_path:path}")
@app.post("/")
async def post_push_endpoint(request: Request, full_path: str = ""):
    payload = await extract_request_payload(request)
    full_path = strip_json_suffix(full_path)
    parts = [p for p in full_path.strip("/").split("/") if p]
    push_id = generate_push_id()
    target_parts = parts + [push_id]
    if not check_permission(target_parts, ".write"):
        raise HTTPException(status_code=403, detail="Permission denied")
    clean_path = "/".join(target_parts)
    async with db_lock:
        set_nested(DATABASE, target_parts, payload)
        await asyncio.to_thread(save_db_now)
        await broadcast(clean_path, payload, "put")
    return {"name": push_id}

@app.delete("/{full_path:path}")
@app.delete("/")
async def delete_endpoint(full_path: str = ""):
    full_path = strip_json_suffix(full_path)
    parts = [p for p in full_path.strip("/").split("/") if p]
    if not check_permission(parts, ".write"):
        raise HTTPException(status_code=403, detail="Permission denied")
    clean_path = "/".join(parts)
    async with db_lock:
        if not parts:
            DATABASE.clear()
        else:
            delete_nested(DATABASE, parts)
        await asyncio.to_thread(save_db_now)
        await broadcast(clean_path, None, "delete")
    return {"status": "deleted", "path": clean_path}

@app.websocket("/ws/{full_path:path}")
@app.websocket("/ws/")
async def ws_endpoint(websocket: WebSocket, full_path: str = ""):
    parts = [p for p in full_path.strip("/").split("/") if p]
    await websocket.accept()
    active_websockets.add(websocket)
    clean_path = "/".join(parts)
    subscriptions.setdefault(clean_path, []).append(websocket)
    data = get_nested(DATABASE, parts) if parts else DATABASE
    conn_msg = json.dumps({"event": "connected", "path": clean_path, "data": data})
    await websocket.send_text(conn_msg)
    sync_metrics(bandwidth_bytes=len(conn_msg.encode("utf-8")))
    try:
        while True:
            await websocket.receive_text()
    except (WebSocketDisconnect, Exception):
        active_websockets.discard(websocket)
        if clean_path in subscriptions and websocket in subscriptions[clean_path]:
            subscriptions[clean_path].remove(websocket)
            if not subscriptions[clean_path]:
                del subscriptions[clean_path]
        sync_metrics()
